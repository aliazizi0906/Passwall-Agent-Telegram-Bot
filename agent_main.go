package main

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const agentVersion = "1.4.0-alpha.1"

type Config struct {
	ServerURL           string `json:"server_url"`
	DeviceID            string `json:"device_id"`
	DeviceSecret        string `json:"device_secret"`
	PollIntervalSeconds int    `json:"poll_interval_seconds"`
	RequestTimeoutSec   int    `json:"request_timeout_seconds"`
	UpdatePublicKey     string `json:"update_public_key"`
}

type CommandParams map[string]interface{}

func (p *CommandParams) UnmarshalJSON(data []byte) error {
	data = bytes.TrimSpace(data)
	if bytes.Equal(data, []byte("null")) || bytes.Equal(data, []byte("[]")) || len(data) == 0 {
		*p = CommandParams{}
		return nil
	}
	var m map[string]interface{}
	if err := json.Unmarshal(data, &m); err != nil {
		return fmt.Errorf("params must be a JSON object: %w", err)
	}
	if m == nil {
		m = map[string]interface{}{}
	}
	*p = CommandParams(m)
	return nil
}

type Command struct {
	ID             int64         `json:"id"`
	Action         string        `json:"action"`
	Params         CommandParams `json:"params"`
	LeaseToken     string        `json:"lease_token,omitempty"`
	IdempotencyKey string        `json:"idempotency_key,omitempty"`
}

type PollResponse struct {
	OK      bool     `json:"ok"`
	Command *Command `json:"command"`
	Error   string   `json:"error,omitempty"`
}

type ResultPayload struct {
	CommandID      int64       `json:"command_id"`
	LeaseToken     string      `json:"lease_token,omitempty"`
	IdempotencyKey string      `json:"idempotency_key,omitempty"`
	Success        bool        `json:"success"`
	Result         interface{} `json:"result,omitempty"`
	Error          string      `json:"error,omitempty"`
}

type UCIConfig struct {
	SectionType map[string]string
	Options     map[string]map[string]string
}

type Agent struct {
	cfg    Config
	client *http.Client
}

var safeID = regexp.MustCompile(`^[A-Za-z0-9_\-]+$`)
var linkScheme = regexp.MustCompile(`(?i)^(vless|vmess|trojan|ss|ssr|socks|socks5|hysteria2|hy2|tuic|naive|http|https)://`)

func main() {
	if runCLIIfRequestedV4() {
		return
	}
	cfgPath := "/etc/pw-agent/config.json"
	if v := os.Getenv("PW_AGENT_CONFIG"); v != "" {
		cfgPath = v
	}
	raw, err := os.ReadFile(cfgPath)
	if err != nil {
		fatalf("read config: %v", err)
	}
	var cfg Config
	if err := json.Unmarshal(raw, &cfg); err != nil {
		fatalf("parse config: %v", err)
	}
	cfg.ServerURL = strings.TrimRight(cfg.ServerURL, "/")
	if cfg.ServerURL == "" || cfg.DeviceID == "" || cfg.DeviceSecret == "" {
		fatalf("server_url, device_id and device_secret are required")
	}
	if cfg.PollIntervalSeconds < 2 {
		cfg.PollIntervalSeconds = 3
	}
	if cfg.RequestTimeoutSec < 5 {
		cfg.RequestTimeoutSec = 25
	}

	a := &Agent{
		cfg:    cfg,
		client: &http.Client{Timeout: time.Duration(cfg.RequestTimeoutSec) * time.Second},
	}
	fmt.Printf("pw-agent %s started for %s\n", agentVersion, cfg.DeviceID)
	go a.failoverLoopV4()
	go healthTelemetryLoopV6()
	a.loop()
}

func (a *Agent) loop() {
	backoff := time.Duration(a.cfg.PollIntervalSeconds) * time.Second
	for {
		cmd, err := a.poll()
		if err != nil {
			fmt.Fprintf(os.Stderr, "poll: %v\n", err)
			time.Sleep(backoff)
			if backoff < 60*time.Second {
				backoff *= 2
			}
			continue
		}
		backoff = time.Duration(a.cfg.PollIntervalSeconds) * time.Second
		if cmd == nil {
			time.Sleep(time.Duration(a.cfg.PollIntervalSeconds) * time.Second)
			continue
		}
		if cached, ok := lookupIdempotentResultV5(cmd); ok {
			if err := a.sendResult(*cached); err != nil {
				fmt.Fprintf(os.Stderr, "result replay command=%d: %v\n", cmd.ID, err)
			}
			continue
		}
		result, execErr := a.executeWithHistoryV6(cmd)
		payload := ResultPayload{CommandID: cmd.ID, LeaseToken: cmd.LeaseToken, IdempotencyKey: cmd.IdempotencyKey, Success: execErr == nil, Result: result}
		if execErr != nil {
			payload.Error = execErr.Error()
		}
		saveIdempotentResultV5(cmd, payload)
		if err := a.sendResult(payload); err != nil {
			fmt.Fprintf(os.Stderr, "result command=%d: %v\n", cmd.ID, err)
		}
	}
}

func (a *Agent) poll() (*Command, error) {
	hb := map[string]interface{}{
		"agent_version":    agentVersion,
		"hostname":         hostname(),
		"uptime_seconds":   uptimeSeconds(),
		"load1":            load1(),
		"cpu_percent":      cpuPercent(120 * time.Millisecond),
		"memory":           memoryInfo(),
		"passwall_running": passwallRunning(),
		"wan":              heartbeatNetworkV6(),
		"health":           cachedHealthTelemetryV6(),
	}
	raw, _, err := a.signedPOST(a.cfg.ServerURL+"/poll.php", hb)
	if err != nil {
		return nil, err
	}
	var pr PollResponse
	if err := json.Unmarshal(raw, &pr); err != nil {
		return nil, fmt.Errorf("invalid poll JSON: %w", err)
	}
	if !pr.OK {
		if pr.Error == "" {
			pr.Error = "server returned ok=false"
		}
		return nil, errors.New(pr.Error)
	}
	return pr.Command, nil
}

func (a *Agent) sendResult(p ResultPayload) error {
	raw, _, err := a.signedPOST(a.cfg.ServerURL+"/result.php", p)
	if err != nil {
		return err
	}
	var v map[string]interface{}
	if err := json.Unmarshal(raw, &v); err != nil {
		return err
	}
	if ok, _ := v["ok"].(bool); !ok {
		return fmt.Errorf("server rejected result: %s", string(raw))
	}
	return nil
}

func (a *Agent) signedPOST(url string, payload interface{}) ([]byte, http.Header, error) {
	body, err := json.Marshal(payload)
	if err != nil {
		return nil, nil, err
	}
	ts := strconv.FormatInt(time.Now().Unix(), 10)
	msg := a.cfg.DeviceID + "\n" + ts + "\n" + string(body)
	mac := hmac.New(sha256.New, []byte(a.cfg.DeviceSecret))
	_, _ = mac.Write([]byte(msg))
	sig := hex.EncodeToString(mac.Sum(nil))

	req, err := http.NewRequest(http.MethodPost, url, bytes.NewReader(body))
	if err != nil {
		return nil, nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "pw-agent/"+agentVersion)
	req.Header.Set("X-PW-Device", a.cfg.DeviceID)
	req.Header.Set("X-PW-Timestamp", ts)
	req.Header.Set("X-PW-Signature", sig)

	resp, err := a.client.Do(req)
	if err != nil {
		return nil, nil, err
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if err != nil {
		return nil, resp.Header, err
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, resp.Header, fmt.Errorf("HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(raw)))
	}
	rsig := resp.Header.Get("X-PW-Response-Signature")
	if rsig == "" {
		return nil, resp.Header, errors.New("missing response signature")
	}
	rmac := hmac.New(sha256.New, []byte(a.cfg.DeviceSecret))
	_, _ = rmac.Write(raw)
	expected := hex.EncodeToString(rmac.Sum(nil))
	if !hmac.Equal([]byte(strings.ToLower(rsig)), []byte(expected)) {
		return nil, resp.Header, errors.New("invalid response signature")
	}
	return raw, resp.Header, nil
}

func (a *Agent) execute(c *Command) (interface{}, error) {
	params := map[string]interface{}(c.Params)
	switch c.Action {
	case "system.status":
		return systemStatus()
	case "agent.capabilities":
		return capabilityReportV13(), nil
	case "led.status":
		return ledStatusV13()
	case "network.doctor":
		return networkDoctorV13()
	case "system.interfaces":
		return networkInterfaces()
	case "system.clients":
		return connectedClients()
	case "system.backup.create":
		return createSystemBackup()
	case "system.backup.restore":
		return restoreSystemBackup(params)
	case "system.reboot":
		return scheduleReboot()
	case "wifi.list":
		return listWiFi()
	case "wifi.set_ssid":
		section, e1 := stringParam(params, "section")
		ssid, e2 := stringParam(params, "ssid")
		if e1 != nil {
			return nil, e1
		}
		if e2 != nil {
			return nil, e2
		}
		return updateWiFi(section, "ssid", ssid)
	case "wifi.set_password":
		section, e1 := stringParam(params, "section")
		password, e2 := stringParam(params, "password")
		if e1 != nil {
			return nil, e1
		}
		if e2 != nil {
			return nil, e2
		}
		return updateWiFi(section, "key", password)
	case "wifi.reload":
		return reloadWiFi()
	case "passwall2.status":
		return passwallStatus()
	case "passwall2.enable":
		return mutateService(true)
	case "passwall2.disable":
		return mutateService(false)
	case "passwall2.restart":
		if err := runService("restart"); err != nil {
			return nil, err
		}
		return passwallStatus()
	case "passwall2.log":
		return passwallLog()
	case "passwall2.nodes.list":
		return listNodes()
	case "passwall2.node.select":
		id, err := stringParam(params, "id")
		if err != nil {
			return nil, err
		}
		return selectNode(id)
	case "passwall2.node.test":
		id, err := stringParam(params, "id")
		if err != nil {
			return nil, err
		}
		return testNode(id)
	case "passwall2.nodes.test_all":
		return testAllNodes()
	case "passwall2.node.add_manual":
		return addManualNode(params)
	case "passwall2.node.add_link":
		link, err := stringParam(params, "link")
		if err != nil {
			return nil, err
		}
		return addLinks(link)
	case "passwall2.node.delete":
		id, err := stringParam(params, "id")
		if err != nil {
			return nil, err
		}
		return deleteNode(id)
	case "passwall2.shunt.list":
		return listShuntsV3()
	case "passwall2.shunt.options":
		shuntID, e1 := stringParam(params, "shunt_id")
		ruleID, e2 := stringParam(params, "rule_id")
		if e1 != nil {
			return nil, e1
		}
		if e2 != nil {
			return nil, e2
		}
		return shuntOptionsV3(shuntID, ruleID)
	case "passwall2.shunt.set":
		shuntID, e1 := stringParam(params, "shunt_id")
		ruleID, e2 := stringParam(params, "rule_id")
		target, e3 := stringParam(params, "target")
		if e1 != nil {
			return nil, e1
		}
		if e2 != nil {
			return nil, e2
		}
		if e3 != nil {
			return nil, e3
		}
		return setShuntV3(shuntID, ruleID, target)
	case "passwall2.shunt.set_default":
		shuntID, e1 := stringParam(params, "shunt_id")
		target, e2 := stringParam(params, "target")
		if e1 != nil {
			return nil, e1
		}
		if e2 != nil {
			return nil, e2
		}
		return setShuntDefaultV3(shuntID, target)
	case "system.time":
		return systemTimeInfo()
	case "system.log":
		return systemLogV3()
	case "system.klog":
		return kernelLogV3()
	case "system.processes":
		return processListV3()
	case "system.services":
		return servicesV3()
	case "system.service.restart":
		name, err := stringParam(params, "name")
		if err != nil {
			return nil, err
		}
		return restartServiceV3(name)
	case "system.packages":
		return packagesV3(false)
	case "system.package_updates":
		return packagesV3(true)
	case "network.wan":
		return wanStatusV3()
	case "network.wan.reconnect":
		return wanReconnectV3()
	case "network.internet_test":
		return internetTestV3()
	case "network.routes":
		return routesV3()
	case "network.dns":
		return dnsInfoV3()
	case "network.dhcp_leases":
		return dhcpLeasesV3()
	case "network.wireguard":
		return wireGuardV3()
	case "wifi.radios":
		return wifiRadiosV3()
	case "wifi.radio.toggle":
		section, e1 := stringParam(params, "section")
		if e1 != nil {
			return nil, e1
		}
		enable, _ := params["enable"].(bool)
		return wifiRadioToggleV3(section, enable)
	case "wifi.radio.channel":
		section, e1 := stringParam(params, "section")
		channel, e2 := stringParam(params, "channel")
		if e1 != nil {
			return nil, e1
		}
		if e2 != nil {
			return nil, e2
		}
		return wifiSetChannelV3(section, channel)
	case "wifi.stations":
		return wifiStationsV3()
	case "firewall.zones":
		return firewallZonesV3()
	case "firewall.redirects":
		return firewallRedirectsV3()
	case "firewall.redirect.toggle":
		section, e1 := stringParam(params, "section")
		if e1 != nil {
			return nil, e1
		}
		enable, _ := params["enable"].(bool)
		return firewallRedirectToggleV3(section, enable)
	case "diag.ping":
		target, err := stringParam(params, "target")
		if err != nil {
			return nil, err
		}
		return diagPingV3(target)
	case "diag.traceroute":
		target, err := stringParam(params, "target")
		if err != nil {
			return nil, err
		}
		return diagTracerouteV3(target)
	case "passwall2.failover.list":
		return failoverSummaryV4()
	case "passwall2.failover.configure":
		return failoverConfigureV4(params)
	case "passwall2.failover.run":
		shuntID, err := stringParam(params, "shunt_id")
		if err != nil {
			return nil, err
		}
		return failoverRunOneV4(a, shuntID, true)
	case "passwall2.subscriptions.list":
		return listSubscriptionsV4()
	case "passwall2.subscription.add":
		url, e1 := stringParam(params, "url")
		remark, e2 := stringParam(params, "remark")
		if e1 != nil {
			return nil, e1
		}
		if e2 != nil {
			return nil, e2
		}
		return addSubscriptionV4(url, remark)
	case "passwall2.subscription.update":
		id, err := stringParam(params, "id")
		if err != nil {
			return nil, err
		}
		return updateSubscriptionV4(id)
	case "passwall2.subscription.delete":
		id, err := stringParam(params, "id")
		if err != nil {
			return nil, err
		}
		return deleteSubscriptionV4(id)
	case "passwall2.nodes.relay_ranking":
		return relayRankingV4()
	case "passwall2.nodes.dead_candidates":
		return deadNodeCandidatesV4()
	case "passwall2.nodes.prune_dead":
		return pruneDeadNodesV4()
	case "passwall2.nodes.delete_many":
		rawIDs, _ := params["ids"].([]interface{})
		ids := []string{}
		for _, v := range rawIDs {
			if x, ok := v.(string); ok {
				ids = append(ids, x)
			}
		}
		return deleteNodesManyV4(ids)
	case "passwall2.nodes.delete_all":
		return deleteAllNodesV4()
	case "agent.update.check":
		return a.agentUpdateCheckV4()
	case "agent.update.apply":
		return a.agentUpdateApplyV4()
	case "agent.diagnose":
		return a.diagnoseV4(), nil
	case "terminal.exec":
		return a.terminalExecV4(params)
	default:
		return nil, fmt.Errorf("unsupported action: %s", c.Action)
	}
}

func systemStatus() (map[string]interface{}, error) {
	mem := memoryInfo()
	storage := storageInfo()
	cpu := cpuPercent(250 * time.Millisecond)
	board := boardInfo()
	wan := map[string]interface{}{}
	if ubus := commandPath("ubus"); ubus != "" {
		if out, err := runOutput(8*time.Second, ubus, "call", "network.interface.wan", "status"); err == nil {
			_ = json.Unmarshal([]byte(out), &wan)
		}
	}
	return map[string]interface{}{
		"hostname":       hostname(),
		"agent_version":  agentVersion,
		"uptime_seconds": uptimeSeconds(),
		"load1":          load1(),
		"cpu_percent":    cpu,
		"memory":         mem,
		"storage":        storage,
		"board":          board,
		"wan":            wan,
	}, nil
}

func boardInfo() map[string]interface{} {
	board := map[string]interface{}{}
	if ubus := commandPath("ubus"); ubus != "" {
		if out, err := runOutput(8*time.Second, ubus, "call", "system", "board"); err == nil {
			_ = json.Unmarshal([]byte(out), &board)
		}
	}
	if _, ok := board["kernel"]; !ok {
		if raw, err := os.ReadFile("/proc/sys/kernel/osrelease"); err == nil {
			board["kernel"] = strings.TrimSpace(string(raw))
		}
	}
	if model, _ := board["model"].(string); strings.TrimSpace(model) == "" {
		for _, path := range []string{"/tmp/sysinfo/model", "/proc/device-tree/model"} {
			if raw, err := os.ReadFile(path); err == nil {
				v := strings.Trim(strings.TrimSpace(string(raw)), "\x00")
				if v != "" {
					board["model"] = v
					break
				}
			}
		}
	}
	release, _ := board["release"].(map[string]interface{})
	if release == nil {
		release = map[string]interface{}{}
	}
	if v, _ := release["version"].(string); strings.TrimSpace(v) == "" {
		if raw, err := os.ReadFile("/etc/os-release"); err == nil {
			for _, line := range strings.Split(string(raw), "\n") {
				if strings.HasPrefix(line, "VERSION_ID=") {
					release["version"] = strings.Trim(strings.TrimPrefix(line, "VERSION_ID="), "\"")
				}
				if strings.HasPrefix(line, "PRETTY_NAME=") {
					release["description"] = strings.Trim(strings.TrimPrefix(line, "PRETTY_NAME="), "\"")
				}
			}
		}
	}
	board["release"] = release
	return board
}

func passwallStatus() (map[string]interface{}, error) {
	enabled, _ := uciGet("passwall2.@global[0].enabled")
	selected, _ := uciGet("passwall2.@global[0].node")
	conf, _ := loadUCI()
	selectedRemarks := ""
	if conf != nil && conf.Options[selected] != nil {
		selectedRemarks = conf.Options[selected]["remarks"]
	}
	return map[string]interface{}{
		"enabled":          enabled == "1",
		"running":          passwallRunning(),
		"selected_node":    selected,
		"selected_remarks": selectedRemarks,
	}, nil
}

func mutateService(enable bool) (map[string]interface{}, error) {
	if _, err := backupPasswall(); err != nil {
		return nil, err
	}
	val := "0"
	action := "stop"
	if enable {
		val = "1"
		action = "restart"
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "set", "passwall2.@global[0].enabled="+val); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	if err := runService(action); err != nil {
		return nil, err
	}
	return passwallStatus()
}

func listNodes() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	selected, _ := uciGet("passwall2.@global[0].node")
	nodes := make([]map[string]interface{}, 0)
	ids := make([]string, 0)
	for id, typ := range conf.SectionType {
		if typ == "nodes" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	for _, id := range ids {
		o := conf.Options[id]
		nodes = append(nodes, map[string]interface{}{
			"id": id, "remarks": o["remarks"], "type": o["type"], "protocol": o["protocol"],
			"address": o["address"], "port": o["port"], "selected": id == selected,
			"is_shunt": o["protocol"] == "_shunt",
		})
	}
	return map[string]interface{}{"selected": selected, "nodes": nodes}, nil
}

func selectNode(id string) (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if !safeID.MatchString(id) || conf.SectionType[id] != "nodes" {
		return nil, errors.New("node not found")
	}
	if _, err := backupPasswall(); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "set", "passwall2.@global[0].node="+id); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	if err := runService("restart"); err != nil {
		return nil, err
	}
	return passwallStatus()
}

func testNode(id string) (map[string]interface{}, error) {
	if !safeID.MatchString(id) {
		return nil, errors.New("invalid node id")
	}
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if conf.SectionType[id] != "nodes" {
		return nil, errors.New("node not found")
	}
	o := conf.Options[id]
	if o["protocol"] == "_shunt" {
		return nil, errors.New("shunt nodes are routing containers and cannot be tested directly")
	}
	address := strings.TrimSpace(o["address"])
	port := strings.TrimSpace(o["port"])
	if address == "" || port == "" {
		return nil, errors.New("node has no address or port")
	}

	result := map[string]interface{}{
		"id":      id,
		"remarks": displayNodeName(id, o),
		"ping":    testICMP(address),
		"tcp":     testTCP(address, port),
		"relay":   testRelay(id),
	}
	return result, nil
}

func testAllNodes() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && conf.Options[id]["protocol"] != "_shunt" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	if len(ids) > 25 {
		ids = ids[:25]
	}
	results := []map[string]interface{}{}
	for _, id := range ids {
		r, err := testNode(id)
		if err != nil {
			results = append(results, map[string]interface{}{"id": id, "remarks": displayNodeName(id, conf.Options[id]), "error": err.Error()})
			continue
		}
		results = append(results, r)
	}
	return map[string]interface{}{"count": len(results), "limited": len(ids) == 25, "results": results}, nil
}

func displayNodeName(id string, o map[string]string) string {
	if v := strings.TrimSpace(o["remarks"]); v != "" {
		return v
	}
	return id
}

func testICMP(address string) map[string]interface{} {
	ping := commandPath("ping")
	if ping == "" {
		return map[string]interface{}{"ok": false, "detail": "ping unavailable"}
	}
	out, err := runOutput(5*time.Second, ping, "-c", "1", "-W", "2", address)
	if err != nil {
		return map[string]interface{}{"ok": false, "detail": "timeout"}
	}
	re := regexp.MustCompile(`time[=<]([0-9.]+)\s*ms`)
	m := re.FindStringSubmatch(out)
	if len(m) == 2 {
		ms, _ := strconv.ParseFloat(m[1], 64)
		return map[string]interface{}{"ok": true, "ms": ms}
	}
	return map[string]interface{}{"ok": true, "detail": "reachable"}
}

func testTCP(address, port string) map[string]interface{} {
	p, err := strconv.Atoi(port)
	if err != nil || p < 1 || p > 65535 {
		return map[string]interface{}{"ok": false, "detail": "invalid port"}
	}
	start := time.Now()
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(address, port), 4*time.Second)
	elapsed := float64(time.Since(start).Microseconds()) / 1000.0
	if err != nil {
		return map[string]interface{}{"ok": false, "detail": "unreachable"}
	}
	_ = conn.Close()
	return map[string]interface{}{"ok": true, "ms": elapsed}
}

func testRelay(id string) map[string]interface{} {
	path := "/usr/share/passwall2/test.sh"
	if _, err := os.Stat(path); err != nil {
		return map[string]interface{}{"ok": false, "detail": "Passwall2 test helper unavailable"}
	}
	out, err := runOutput(45*time.Second, path, "url_test_node", id, "pw-agent")
	if err != nil {
		return map[string]interface{}{"ok": false, "detail": "timeout"}
	}
	raw := strings.TrimSpace(out)
	parts := strings.SplitN(raw, ":", 2)
	if len(parts) != 2 || parts[0] == "000" || parts[0] == "0" {
		return map[string]interface{}{"ok": false, "detail": "failed", "raw": raw}
	}
	seconds, err := strconv.ParseFloat(strings.TrimSpace(parts[1]), 64)
	if err != nil {
		return map[string]interface{}{"ok": true, "detail": raw}
	}
	return map[string]interface{}{"ok": true, "ms": seconds * 1000.0, "code": parts[0]}
}

func addManualNode(params map[string]interface{}) (map[string]interface{}, error) {
	v, ok := params["options"]
	if !ok {
		return nil, errors.New("missing parameter: options")
	}
	obj, ok := v.(map[string]interface{})
	if !ok {
		return nil, errors.New("options must be an object")
	}
	allowed := map[string]bool{
		"remarks": true, "type": true, "protocol": true, "address": true, "port": true, "username": true, "password": true, "uuid": true,
		"flow": true, "encryption": true, "security": true, "tls": true, "tls_serverName": true, "alpn": true, "utls": true, "fingerprint": true,
		"reality": true, "reality_publicKey": true, "reality_shortId": true, "transport": true, "tcp_guise": true,
		"tcp_guise_http_host": true, "tcp_guise_http_path": true, "http_host": true, "http_path": true, "ws_host": true, "ws_path": true,
		"grpc_serviceName": true, "grpc_mode": true, "httpupgrade_host": true, "httpupgrade_path": true, "xhttp_host": true, "xhttp_path": true,
		"mux": true, "mux_type": true, "mux_concurrency": true, "packet_encoding": true, "method": true, "plugin": true, "plugin_opts": true,
	}
	opts := map[string]string{}
	for k, val := range obj {
		if !allowed[k] {
			return nil, fmt.Errorf("manual option not allowed: %s", k)
		}
		switch x := val.(type) {
		case string:
			opts[k] = x
		case float64:
			opts[k] = strconv.FormatFloat(x, 'f', -1, 64)
		case bool:
			if x {
				opts[k] = "1"
			} else {
				opts[k] = "0"
			}
		default:
			return nil, fmt.Errorf("manual option %s must be scalar", k)
		}
	}
	for _, req := range []string{"remarks", "protocol", "address", "port"} {
		if strings.TrimSpace(opts[req]) == "" {
			return nil, fmt.Errorf("manual option required: %s", req)
		}
	}
	if opts["type"] == "" {
		opts["type"] = "Xray"
	}
	if _, err := strconv.Atoi(opts["port"]); err != nil {
		return nil, errors.New("port must be numeric")
	}
	if _, err := backupPasswall(); err != nil {
		return nil, err
	}
	b := make([]byte, 6)
	if _, err := rand.Read(b); err != nil {
		return nil, err
	}
	id := "tg_" + hex.EncodeToString(b)
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "set", "passwall2."+id+"=nodes"); err != nil {
		return nil, err
	}
	for k, val := range opts {
		if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "set", fmt.Sprintf("passwall2.%s.%s=%s", id, k, val)); err != nil {
			_, _ = runOutput(10*time.Second, commandPathRequired("uci"), "delete", "passwall2."+id)
			return nil, err
		}
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	return map[string]interface{}{"id": id, "remarks": opts["remarks"], "protocol": opts["protocol"], "address": opts["address"], "port": opts["port"]}, nil
}

func addLinks(input string) (map[string]interface{}, error) {
	if len(input) > 65536 {
		return nil, errors.New("link payload too large")
	}
	lines := []string{}
	for _, line := range strings.Split(strings.ReplaceAll(input, "\r", ""), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		if !linkScheme.MatchString(line) {
			return nil, fmt.Errorf("unsupported or invalid link scheme")
		}
		lines = append(lines, line)
	}
	if len(lines) == 0 {
		return nil, errors.New("no links supplied")
	}
	before, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if _, err := backupPasswall(); err != nil {
		return nil, err
	}
	tmp := "/tmp/links.conf"
	if err := os.WriteFile(tmp, []byte(strings.Join(lines, "\n")+"\n"), 0600); err != nil {
		return nil, err
	}
	defer os.Remove(tmp)
	if _, err := runOutput(120*time.Second, commandPathRequired("lua"), "/usr/share/passwall2/subscribe.lua", "add", "default"); err != nil {
		return nil, fmt.Errorf("Passwall2 parser failed: %w", err)
	}
	after, err := loadUCI()
	if err != nil {
		return nil, err
	}
	added := []map[string]interface{}{}
	for id, typ := range after.SectionType {
		if typ != "nodes" {
			continue
		}
		if before.SectionType[id] == "" {
			o := after.Options[id]
			added = append(added, map[string]interface{}{"id": id, "remarks": o["remarks"], "protocol": o["protocol"], "address": o["address"], "port": o["port"]})
		}
	}
	return map[string]interface{}{"input_count": len(lines), "added_count": len(added), "added": added}, nil
}

func deleteNode(id string) (map[string]interface{}, error) {
	if !safeID.MatchString(id) {
		return nil, errors.New("invalid node id")
	}
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if conf.SectionType[id] != "nodes" {
		return nil, errors.New("node not found")
	}
	if conf.Options[id]["protocol"] == "_shunt" {
		return nil, errors.New("refusing to delete shunt node")
	}
	refs := []string{}
	for sec, opts := range conf.Options {
		for k, v := range opts {
			if sec == id {
				continue
			}
			for _, token := range strings.Fields(v) {
				if token == id {
					refs = append(refs, sec+"."+k)
				}
			}
		}
	}
	if len(refs) > 0 {
		return map[string]interface{}{"id": id, "referenced_by": refs}, errors.New("node is referenced by configuration; deletion refused")
	}
	backup, err := backupPasswall()
	if err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "delete", "passwall2."+id); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	return map[string]interface{}{"deleted": id, "backup": backup}, nil
}

func listShunts() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	rules := map[string]map[string]string{}
	for id, typ := range conf.SectionType {
		if typ == "shunt_rules" {
			rules[id] = conf.Options[id]
		}
	}
	nodeNames := map[string]string{
		"_direct":    "Direct",
		"_default":   "Default",
		"_blackhole": "Block",
	}
	for id, typ := range conf.SectionType {
		if typ == "nodes" {
			nodeNames[id] = displayNodeName(id, conf.Options[id])
		}
	}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && conf.Options[id]["protocol"] == "_shunt" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	shunts := []map[string]interface{}{}
	for _, id := range ids {
		opts := conf.Options[id]
		routes := map[string]string{}
		for ruleID := range rules {
			v := opts[ruleID]
			if v == "" {
				v = "_default"
			}
			routes[ruleID] = v
		}
		shunts = append(shunts, map[string]interface{}{
			"id":           id,
			"remarks":      displayNodeName(id, opts),
			"default_node": opts["default_node"],
			"routes":       routes,
		})
	}
	return map[string]interface{}{"rules": rules, "shunts": shunts, "node_names": nodeNames}, nil
}

func shuntOptions(shuntID, ruleID string) (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if !safeID.MatchString(shuntID) || conf.SectionType[shuntID] != "nodes" || conf.Options[shuntID]["protocol"] != "_shunt" {
		return nil, errors.New("shunt node not found")
	}
	isDefault := ruleID == "__default__"
	if !isDefault {
		if !safeID.MatchString(ruleID) || conf.SectionType[ruleID] != "shunt_rules" {
			return nil, errors.New("shunt rule not found")
		}
	}
	current := conf.Options[shuntID]["default_node"]
	ruleName := "Default"
	if !isDefault {
		current = conf.Options[shuntID][ruleID]
		if current == "" {
			current = "_default"
		}
		ruleName = strings.TrimSpace(conf.Options[ruleID]["remarks"])
		if ruleName == "" {
			ruleName = ruleID
		}
	}
	options := []map[string]interface{}{
		{"id": "_direct", "name": "Direct"},
		{"id": "_default", "name": "Default"},
		{"id": "_blackhole", "name": "Block"},
	}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && conf.Options[id]["protocol"] != "_shunt" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	for _, id := range ids {
		options = append(options, map[string]interface{}{"id": id, "name": displayNodeName(id, conf.Options[id])})
	}
	return map[string]interface{}{
		"shunt_id":   shuntID,
		"shunt_name": displayNodeName(shuntID, conf.Options[shuntID]),
		"rule_id":    ruleID,
		"rule_name":  ruleName,
		"is_default": isDefault,
		"current":    current,
		"options":    options,
	}, nil
}

func setShunt(shuntID, ruleID, target string) (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if !safeID.MatchString(shuntID) || !safeID.MatchString(ruleID) {
		return nil, errors.New("invalid shunt/rule id")
	}
	if conf.SectionType[shuntID] != "nodes" || conf.Options[shuntID]["protocol"] != "_shunt" {
		return nil, errors.New("shunt node not found")
	}
	if conf.SectionType[ruleID] != "shunt_rules" {
		return nil, errors.New("shunt rule not found")
	}
	if err := validateTarget(conf, target); err != nil {
		return nil, err
	}
	backup, err := backupPasswall()
	if err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "set", fmt.Sprintf("passwall2.%s.%s=%s", shuntID, ruleID, target)); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	if err := runService("restart"); err != nil {
		return nil, err
	}
	fresh, e := listShunts()
	if e != nil {
		return nil, e
	}
	fresh["updated"] = map[string]interface{}{"shunt_id": shuntID, "rule_id": ruleID, "target": target, "backup": backup}
	return fresh, nil
}

func setShuntDefault(shuntID, target string) (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if !safeID.MatchString(shuntID) {
		return nil, errors.New("invalid shunt id")
	}
	if conf.SectionType[shuntID] != "nodes" || conf.Options[shuntID]["protocol"] != "_shunt" {
		return nil, errors.New("shunt node not found")
	}
	if err := validateTarget(conf, target); err != nil {
		return nil, err
	}
	backup, err := backupPasswall()
	if err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "set", fmt.Sprintf("passwall2.%s.default_node=%s", shuntID, target)); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	if err := runService("restart"); err != nil {
		return nil, err
	}
	fresh, e := listShunts()
	if e != nil {
		return nil, e
	}
	fresh["updated"] = map[string]interface{}{"shunt_id": shuntID, "rule_id": "__default__", "target": target, "backup": backup}
	return fresh, nil
}

func validateTarget(conf *UCIConfig, target string) error {
	switch target {
	case "_direct", "_default", "_blackhole":
		return nil
	}
	if !safeID.MatchString(target) || conf.SectionType[target] != "nodes" {
		return errors.New("target node not found")
	}
	return nil
}

func backupPasswall() (string, error) {
	raw, err := os.ReadFile("/etc/config/passwall2")
	if err != nil {
		return "", err
	}
	dir := "/etc/pw-agent/backups"
	if err := os.MkdirAll(dir, 0700); err != nil {
		return "", err
	}
	name := "passwall2-" + time.Now().Format("20060102-150405") + ".conf"
	path := filepath.Join(dir, name)
	if err := os.WriteFile(path, raw, 0600); err != nil {
		return "", err
	}
	entries, _ := filepath.Glob(filepath.Join(dir, "passwall2-*.conf"))
	sort.Strings(entries)
	if len(entries) > 20 {
		for _, old := range entries[:len(entries)-20] {
			_ = os.Remove(old)
		}
	}
	return name, nil
}

func listBackups() ([]map[string]interface{}, error) {
	entries, err := filepath.Glob("/etc/pw-agent/backups/passwall2-*.conf")
	if err != nil {
		return nil, err
	}
	sort.Sort(sort.Reverse(sort.StringSlice(entries)))
	out := []map[string]interface{}{}
	for _, p := range entries {
		st, err := os.Stat(p)
		if err != nil {
			continue
		}
		out = append(out, map[string]interface{}{"name": filepath.Base(p), "size": st.Size(), "modified": st.ModTime().Unix()})
	}
	return out, nil
}

func restoreBackup(name string) (map[string]interface{}, error) {
	if !regexp.MustCompile(`^passwall2-[0-9]{8}-[0-9]{6}\.conf$`).MatchString(name) {
		return nil, errors.New("invalid backup name")
	}
	src := filepath.Join("/etc/pw-agent/backups", name)
	raw, err := os.ReadFile(src)
	if err != nil {
		return nil, err
	}
	safety, err := backupPasswall()
	if err != nil {
		return nil, err
	}
	if err := os.WriteFile("/etc/config/passwall2", raw, 0644); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, commandPathRequired("uci"), "commit", "passwall2"); err != nil {
		return nil, err
	}
	if err := runService("restart"); err != nil {
		return nil, err
	}
	return map[string]interface{}{"restored": name, "pre_restore_backup": safety}, nil
}

func commandPath(name string) string {
	if p, err := exec.LookPath(name); err == nil {
		return p
	}
	for _, dir := range []string{"/bin", "/sbin", "/usr/bin", "/usr/sbin"} {
		p := filepath.Join(dir, name)
		if st, err := os.Stat(p); err == nil && !st.IsDir() {
			return p
		}
	}
	return ""
}

func commandPathRequired(name string) string {
	if p := commandPath(name); p != "" {
		return p
	}
	return name
}

func networkInterfaces() (map[string]interface{}, error) {
	ubus := commandPath("ubus")
	if ubus == "" {
		return nil, errors.New("ubus is unavailable")
	}
	out, err := runOutput(10*time.Second, ubus, "call", "network.interface", "dump")
	if err != nil {
		return nil, err
	}
	var root map[string]interface{}
	if err := json.Unmarshal([]byte(out), &root); err != nil {
		return nil, err
	}
	rawList, _ := root["interface"].([]interface{})
	items := []map[string]interface{}{}
	for _, rv := range rawList {
		m, _ := rv.(map[string]interface{})
		if m == nil {
			continue
		}
		name, _ := m["interface"].(string)
		proto, _ := m["proto"].(string)
		device, _ := m["l3_device"].(string)
		if device == "" {
			device, _ = m["device"].(string)
		}
		ips := []string{}
		for _, key := range []string{"ipv4-address", "ipv6-address"} {
			if arr, ok := m[key].([]interface{}); ok {
				for _, x := range arr {
					if im, ok := x.(map[string]interface{}); ok {
						if a, ok := im["address"].(string); ok && a != "" {
							ips = append(ips, a)
						}
					}
				}
			}
		}
		rx, tx := uint64(0), uint64(0)
		if device != "" {
			rx = readUintFile(filepath.Join("/sys/class/net", device, "statistics/rx_bytes"))
			tx = readUintFile(filepath.Join("/sys/class/net", device, "statistics/tx_bytes"))
		}
		items = append(items, map[string]interface{}{
			"name":      name,
			"proto":     proto,
			"device":    device,
			"up":        boolValue(m["up"]),
			"uptime":    int64Value(m["uptime"]),
			"addresses": ips,
			"rx_bytes":  rx,
			"tx_bytes":  tx,
		})
	}
	sort.Slice(items, func(i, j int) bool { return fmt.Sprint(items[i]["name"]) < fmt.Sprint(items[j]["name"]) })
	return map[string]interface{}{"interfaces": items}, nil
}

func connectedClients() (map[string]interface{}, error) {
	type client struct {
		IP, MAC, Hostname, Interface string
		Expires                      int64
	}
	byKey := map[string]*client{}
	if raw, err := os.ReadFile("/tmp/dhcp.leases"); err == nil {
		for _, line := range strings.Split(string(raw), "\n") {
			f := strings.Fields(line)
			if len(f) < 4 {
				continue
			}
			exp, _ := strconv.ParseInt(f[0], 10, 64)
			host := f[3]
			if host == "*" {
				host = ""
			}
			c := &client{IP: f[2], MAC: strings.ToUpper(f[1]), Hostname: host, Expires: exp}
			byKey[c.IP+"|"+c.MAC] = c
		}
	}
	ipcmd := commandPath("ip")
	if ipcmd != "" {
		if out, err := runOutput(5*time.Second, ipcmd, "neigh", "show"); err == nil {
			for _, line := range strings.Split(out, "\n") {
				f := strings.Fields(line)
				if len(f) < 3 {
					continue
				}
				ip := f[0]
				iface := ""
				mac := ""
				for i := 1; i < len(f)-1; i++ {
					if f[i] == "dev" {
						iface = f[i+1]
					}
					if f[i] == "lladdr" {
						mac = strings.ToUpper(f[i+1])
					}
				}
				if mac == "" {
					continue
				}
				key := ip + "|" + mac
				if c, ok := byKey[key]; ok {
					c.Interface = iface
				} else {
					byKey[key] = &client{IP: ip, MAC: mac, Interface: iface}
				}
			}
		}
	}
	items := []map[string]interface{}{}
	for _, c := range byKey {
		items = append(items, map[string]interface{}{"ip": c.IP, "mac": c.MAC, "hostname": c.Hostname, "interface": c.Interface, "expires": c.Expires})
	}
	sort.Slice(items, func(i, j int) bool { return fmt.Sprint(items[i]["ip"]) < fmt.Sprint(items[j]["ip"]) })
	return map[string]interface{}{"clients": items, "count": len(items)}, nil
}

func listWiFi() (map[string]interface{}, error) {
	conf, err := loadUCIConfig("wireless")
	if err != nil {
		return nil, err
	}
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "wifi-iface" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	items := []map[string]interface{}{}
	for _, id := range ids {
		o := conf.Options[id]
		items = append(items, map[string]interface{}{
			"section":    id,
			"ssid":       o["ssid"],
			"mode":       o["mode"],
			"network":    o["network"],
			"encryption": o["encryption"],
			"disabled":   o["disabled"] == "1",
		})
	}
	return map[string]interface{}{"wifi": items}, nil
}

var safeUCISection = regexp.MustCompile(`^(?:[A-Za-z0-9_-]+|@[A-Za-z0-9_-]+\[[0-9]+\])$`)

func updateWiFi(section, field, value string) (map[string]interface{}, error) {
	if !safeUCISection.MatchString(section) {
		return nil, errors.New("invalid Wi-Fi section")
	}
	conf, err := loadUCIConfig("wireless")
	if err != nil {
		return nil, err
	}
	if conf.SectionType[section] != "wifi-iface" {
		return nil, errors.New("Wi-Fi interface not found")
	}
	if field == "ssid" {
		if n := len([]byte(value)); n < 1 || n > 32 {
			return nil, errors.New("SSID must be 1-32 bytes")
		}
	} else if field == "key" {
		valid := (len(value) >= 8 && len(value) <= 63) || (len(value) == 64 && regexp.MustCompile(`^[0-9A-Fa-f]{64}$`).MatchString(value))
		if !valid {
			return nil, errors.New("Wi-Fi password must be 8-63 characters or a 64-character hex PSK")
		}
	} else {
		return nil, errors.New("unsupported Wi-Fi field")
	}
	if _, err := backupConfigFile("wireless"); err != nil {
		return nil, err
	}
	uci := commandPathRequired("uci")
	if _, err := runOutput(10*time.Second, uci, "set", fmt.Sprintf("wireless.%s.%s=%s", section, field, value)); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, uci, "commit", "wireless"); err != nil {
		return nil, err
	}
	if _, err := reloadWiFi(); err != nil {
		return nil, err
	}
	return listWiFi()
}

func reloadWiFi() (map[string]interface{}, error) {
	if wifi := commandPath("wifi"); wifi != "" {
		if _, err := runOutput(45*time.Second, wifi, "reload"); err != nil {
			return nil, err
		}
	} else if ubus := commandPath("ubus"); ubus != "" {
		if _, err := runOutput(45*time.Second, ubus, "call", "network", "reload"); err != nil {
			return nil, err
		}
	} else {
		return nil, errors.New("Wi-Fi reload command unavailable")
	}
	return listWiFi()
}

func backupConfigFile(name string) (string, error) {
	if !regexp.MustCompile(`^[A-Za-z0-9_-]+$`).MatchString(name) {
		return "", errors.New("invalid config name")
	}
	raw, err := os.ReadFile("/etc/config/" + name)
	if err != nil {
		return "", err
	}
	dir := "/etc/pw-agent/safety"
	if err := os.MkdirAll(dir, 0700); err != nil {
		return "", err
	}
	path := filepath.Join(dir, name+"-"+time.Now().Format("20060102-150405")+".conf")
	if err := os.WriteFile(path, raw, 0600); err != nil {
		return "", err
	}
	matches, _ := filepath.Glob(filepath.Join(dir, name+"-*.conf"))
	sort.Strings(matches)
	if len(matches) > 10 {
		for _, old := range matches[:len(matches)-10] {
			_ = os.Remove(old)
		}
	}
	return filepath.Base(path), nil
}

func createSystemBackup() (map[string]interface{}, error) {
	sysupgrade := commandPath("sysupgrade")
	if sysupgrade == "" {
		return nil, errors.New("sysupgrade is unavailable")
	}
	name := "openwrt-" + sanitizeFilename(hostname()) + "-" + time.Now().Format("20060102-150405") + ".tar.gz"
	path := filepath.Join("/tmp", name)
	defer os.Remove(path)
	if _, err := runOutput(120*time.Second, sysupgrade, "-b", path); err != nil {
		return nil, err
	}
	st, err := os.Stat(path)
	if err != nil {
		return nil, err
	}
	if st.Size() > 5<<20 {
		return nil, errors.New("backup is larger than the 5 MiB transfer limit")
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	sum := sha256.Sum256(raw)
	return map[string]interface{}{
		"filename": name,
		"size":     len(raw),
		"sha256":   hex.EncodeToString(sum[:]),
		"data_b64": base64.StdEncoding.EncodeToString(raw),
	}, nil
}

func restoreSystemBackup(params map[string]interface{}) (map[string]interface{}, error) {
	data, err := stringParam(params, "data_b64")
	if err != nil {
		return nil, err
	}
	expected, _ := params["sha256"].(string)
	raw, err := base64.StdEncoding.DecodeString(data)
	if err != nil {
		return nil, errors.New("invalid backup encoding")
	}
	if len(raw) == 0 || len(raw) > 8<<20 {
		return nil, errors.New("backup size is invalid")
	}
	sum := sha256.Sum256(raw)
	actual := hex.EncodeToString(sum[:])
	if expected != "" && !strings.EqualFold(expected, actual) {
		return nil, errors.New("backup checksum mismatch")
	}
	path := "/tmp/pw-agent-restore-" + strconv.FormatInt(time.Now().Unix(), 10) + ".tar.gz"
	defer os.Remove(path)
	if err := os.WriteFile(path, raw, 0600); err != nil {
		return nil, err
	}
	sysupgrade := commandPath("sysupgrade")
	if sysupgrade == "" {
		return nil, errors.New("sysupgrade is unavailable")
	}
	if _, err := runOutput(120*time.Second, sysupgrade, "-T", path); err != nil {
		return nil, fmt.Errorf("backup validation failed: %w", err)
	}
	if _, err := runOutput(180*time.Second, sysupgrade, "-r", path); err != nil {
		return nil, fmt.Errorf("backup restore failed: %w", err)
	}
	return map[string]interface{}{"restored": true, "sha256": actual, "reboot_recommended": true}, nil
}

func scheduleReboot() (map[string]interface{}, error) {
	reboot := commandPath("reboot")
	if reboot == "" {
		return nil, errors.New("reboot command unavailable")
	}
	cmd := exec.Command("/bin/sh", "-c", "sleep 10; "+reboot)
	cmd.Stdin = nil
	cmd.Stdout = nil
	cmd.Stderr = nil
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	return map[string]interface{}{"scheduled": true}, nil
}

func passwallLog() (map[string]interface{}, error) {
	paths := []string{"/tmp/log/passwall2.log", "/var/log/passwall2.log"}
	for _, p := range paths {
		raw, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		lines := strings.Split(strings.TrimSpace(string(raw)), "\n")
		if len(lines) > 40 {
			lines = lines[len(lines)-40:]
		}
		return map[string]interface{}{"lines": lines}, nil
	}
	return map[string]interface{}{"lines": []string{}}, nil
}

func sanitizeFilename(s string) string {
	s = regexp.MustCompile(`[^A-Za-z0-9._-]+`).ReplaceAllString(s, "-")
	s = strings.Trim(s, "-.")
	if s == "" {
		return "router"
	}
	return s
}

func readUintFile(path string) uint64 {
	raw, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	v, _ := strconv.ParseUint(strings.TrimSpace(string(raw)), 10, 64)
	return v
}
func boolValue(v interface{}) bool { b, _ := v.(bool); return b }
func int64Value(v interface{}) int64 {
	switch x := v.(type) {
	case float64:
		return int64(x)
	case int64:
		return x
	case int:
		return int64(x)
	case json.Number:
		n, _ := x.Int64()
		return n
	}
	return 0
}

func loadUCI() (*UCIConfig, error) { return loadUCIConfig("passwall2") }

func loadUCIConfig(pkg string) (*UCIConfig, error) {
	if !regexp.MustCompile(`^[A-Za-z0-9_-]+$`).MatchString(pkg) {
		return nil, errors.New("invalid UCI package")
	}
	out, err := runOutput(15*time.Second, commandPathRequired("uci"), "show", pkg)
	if err != nil {
		return nil, err
	}
	c := &UCIConfig{SectionType: map[string]string{}, Options: map[string]map[string]string{}}
	prefix := pkg + "."
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, prefix) {
			continue
		}
		eq := strings.IndexByte(line, '=')
		if eq < 0 {
			continue
		}
		key := strings.TrimPrefix(line[:eq], prefix)
		val := unquoteUCI(line[eq+1:])
		parts := strings.SplitN(key, ".", 2)
		sec := parts[0]
		if len(parts) == 1 {
			c.SectionType[sec] = val
			if c.Options[sec] == nil {
				c.Options[sec] = map[string]string{}
			}
		} else {
			if c.Options[sec] == nil {
				c.Options[sec] = map[string]string{}
			}
			if old := c.Options[sec][parts[1]]; old != "" {
				c.Options[sec][parts[1]] = old + " " + val
			} else {
				c.Options[sec][parts[1]] = val
			}
		}
	}
	return c, nil
}

func unquoteUCI(v string) string {
	v = strings.TrimSpace(v)
	if len(v) >= 2 && v[0] == '\'' && v[len(v)-1] == '\'' {
		v = v[1 : len(v)-1]
		v = strings.ReplaceAll(v, "'\\''", "'")
	}
	return v
}

func uciGet(key string) (string, error) {
	out, err := runOutput(8*time.Second, commandPathRequired("uci"), "-q", "get", key)
	return strings.TrimSpace(out), err
}

func runService(action string) error {
	if action != "start" && action != "stop" && action != "restart" {
		return errors.New("invalid service action")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 180*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "/etc/init.d/passwall2", action)
	devnull, err := os.Open("/dev/null")
	if err == nil {
		defer devnull.Close()
		cmd.Stdin = devnull
	}
	var buf bytes.Buffer
	cmd.Stdout, cmd.Stderr = &buf, &buf
	err = cmd.Run()
	if ctx.Err() == context.DeadlineExceeded {
		return errors.New("Passwall2 service action timed out")
	}
	if err != nil {
		return fmt.Errorf("passwall2 %s failed: %v: %s", action, err, strings.TrimSpace(buf.String()))
	}
	return nil
}

func runOutput(timeout time.Duration, name string, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, name, args...)
	devnull, err := os.Open("/dev/null")
	if err == nil {
		defer devnull.Close()
		cmd.Stdin = devnull
	}
	out, err := cmd.CombinedOutput()
	if ctx.Err() == context.DeadlineExceeded {
		return string(out), fmt.Errorf("command timeout: %s", name)
	}
	if err != nil {
		return string(out), fmt.Errorf("%s: %v: %s", name, err, strings.TrimSpace(string(out)))
	}
	return string(out), nil
}

func stringParam(m map[string]interface{}, key string) (string, error) {
	v, ok := m[key]
	if !ok {
		return "", fmt.Errorf("missing parameter: %s", key)
	}
	s, ok := v.(string)
	if !ok || strings.TrimSpace(s) == "" {
		return "", fmt.Errorf("invalid parameter: %s", key)
	}
	return strings.TrimSpace(s), nil
}

func hostname() string { h, _ := os.Hostname(); return h }
func uptimeSeconds() int64 {
	raw, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0
	}
	f := strings.Fields(string(raw))
	if len(f) == 0 {
		return 0
	}
	x, _ := strconv.ParseFloat(f[0], 64)
	return int64(x)
}
func load1() float64 {
	raw, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return 0
	}
	f := strings.Fields(string(raw))
	if len(f) == 0 {
		return 0
	}
	x, _ := strconv.ParseFloat(f[0], 64)
	return x
}
func memoryInfo() map[string]uint64 {
	raw, _ := os.ReadFile("/proc/meminfo")
	vals := map[string]uint64{}
	for _, line := range strings.Split(string(raw), "\n") {
		p := strings.Fields(line)
		if len(p) < 2 {
			continue
		}
		n, _ := strconv.ParseUint(p[1], 10, 64)
		vals[strings.TrimSuffix(p[0], ":")] = n * 1024
	}
	total := vals["MemTotal"]
	avail := vals["MemAvailable"]
	if avail == 0 {
		avail = vals["MemFree"] + vals["Buffers"] + vals["Cached"]
	}
	used := uint64(0)
	if total > avail {
		used = total - avail
	}
	return map[string]uint64{"total_bytes": total, "used_bytes": used, "available_bytes": avail}
}
func storageInfo() map[string]uint64 {
	path := "/overlay"
	if _, err := os.Stat(path); err != nil {
		path = "/"
	}
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return map[string]uint64{}
	}
	total := uint64(st.Blocks) * uint64(st.Bsize)
	free := uint64(st.Bavail) * uint64(st.Bsize)
	used := uint64(0)
	if total > free {
		used = total - free
	}
	return map[string]uint64{"total_bytes": total, "used_bytes": used, "free_bytes": free}
}
func temperatures() []float64 {
	paths, _ := filepath.Glob("/sys/class/thermal/thermal_zone*/temp")
	out := []float64{}
	for _, p := range paths {
		raw, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		x, err := strconv.ParseFloat(strings.TrimSpace(string(raw)), 64)
		if err != nil {
			continue
		}
		if x > 1000 {
			x /= 1000
		}
		if x > -50 && x < 200 {
			out = append(out, x)
		}
	}
	return out
}
func cpuPercent(delay time.Duration) float64 {
	idle1, total1 := readCPU()
	time.Sleep(delay)
	idle2, total2 := readCPU()
	dt := total2 - total1
	di := idle2 - idle1
	if dt == 0 {
		return 0
	}
	v := 100 * (1 - float64(di)/float64(dt))
	if v < 0 {
		v = 0
	}
	if v > 100 {
		v = 100
	}
	return float64(int(v*10+0.5)) / 10
}
func readCPU() (idle, total uint64) {
	raw, _ := os.ReadFile("/proc/stat")
	line := strings.SplitN(string(raw), "\n", 2)[0]
	f := strings.Fields(line)
	if len(f) < 5 {
		return
	}
	nums := []uint64{}
	for _, s := range f[1:] {
		n, _ := strconv.ParseUint(s, 10, 64)
		nums = append(nums, n)
		total += n
	}
	if len(nums) > 3 {
		idle = nums[3]
	}
	if len(nums) > 4 {
		idle += nums[4]
	}
	return
}
func passwallRunning() bool {
	procs, _ := filepath.Glob("/proc/[0-9]*/cmdline")
	for _, p := range procs {
		raw, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		s := strings.ReplaceAll(string(raw), "\x00", " ")
		if strings.Contains(s, "/tmp/etc/passwall2/") || strings.Contains(s, "/usr/share/passwall2/") {
			return true
		}
	}
	return false
}

func fatalf(f string, a ...interface{}) { fmt.Fprintf(os.Stderr, f+"\n", a...); os.Exit(1) }
