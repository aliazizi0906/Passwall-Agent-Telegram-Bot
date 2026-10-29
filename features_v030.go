package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// v0.3 feature set. All mutations are intentionally narrow and whitelisted.

func normalizeShuntTarget(target string) string {
	if target == "_close" {
		return ""
	}
	return target
}

func shuntTargetName(id string, names map[string]string) string {
	if id == "" {
		return "Close"
	}
	if v := names[id]; v != "" {
		return v
	}
	return id
}

func listShuntsV3() (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}

	allRules := map[string]map[string]string{}
	for id, typ := range conf.SectionType {
		if typ == "shunt_rules" {
			allRules[id] = conf.Options[id]
		}
	}
	nodeNames := map[string]string{
		"": "Close", "_close": "Close", "_direct": "Direct", "_default": "Use Default", "_blackhole": "Block",
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
	rulesForUI := map[string]map[string]string{}
	for _, id := range ids {
		opts := conf.Options[id]
		group := strings.ToLower(strings.TrimSpace(opts["shunt_group"]))
		ruleIDs := []string{}
		for rid, ro := range allRules {
			if strings.ToLower(strings.TrimSpace(ro["group"])) == group {
				ruleIDs = append(ruleIDs, rid)
			}
		}
		sort.Slice(ruleIDs, func(i, j int) bool {
			ai := strings.ToLower(strings.TrimSpace(allRules[ruleIDs[i]]["remarks"]))
			aj := strings.ToLower(strings.TrimSpace(allRules[ruleIDs[j]]["remarks"]))
			if ai == aj {
				return ruleIDs[i] < ruleIDs[j]
			}
			return ai < aj
		})
		routes := []map[string]interface{}{}
		for _, rid := range ruleIDs {
			ro := allRules[rid]
			name := strings.TrimSpace(ro["remarks"])
			if name == "" {
				name = rid
			}
			target := opts[rid] // empty means Close, exactly like Passwall2 CBI.
			routes = append(routes, map[string]interface{}{
				"rule_id": rid, "rule_name": name, "target": target, "target_name": shuntTargetName(target, nodeNames),
			})
			rulesForUI[rid] = ro
		}
		def := opts["default_node"]
		if def == "" {
			def = "_direct"
		}
		shunts = append(shunts, map[string]interface{}{
			"id": id, "remarks": displayNodeName(id, opts), "shunt_group": opts["shunt_group"],
			"default_node": def, "default_name": shuntTargetName(def, nodeNames), "routes": routes,
		})
	}
	return map[string]interface{}{"rules": rulesForUI, "shunts": shunts, "node_names": nodeNames}, nil
}

func shuntOptionsV3(shuntID, ruleID string) (map[string]interface{}, error) {
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
		sg := strings.ToLower(strings.TrimSpace(conf.Options[shuntID]["shunt_group"]))
		rg := strings.ToLower(strings.TrimSpace(conf.Options[ruleID]["group"]))
		if sg != rg {
			return nil, errors.New("rule does not belong to this shunt group")
		}
	}
	current := conf.Options[shuntID]["default_node"]
	ruleName := "Default"
	if isDefault {
		if current == "" {
			current = "_direct"
		}
	} else {
		current = conf.Options[shuntID][ruleID]
		ruleName = strings.TrimSpace(conf.Options[ruleID]["remarks"])
		if ruleName == "" {
			ruleName = ruleID
		}
	}
	options := []map[string]interface{}{}
	if !isDefault {
		options = append(options, map[string]interface{}{"id": "_close", "name": "Close"}, map[string]interface{}{"id": "_default", "name": "Use Default"})
	}
	options = append(options, map[string]interface{}{"id": "_direct", "name": "Direct"}, map[string]interface{}{"id": "_blackhole", "name": "Block"})
	ids := []string{}
	for id, typ := range conf.SectionType {
		if typ == "nodes" && conf.Options[id]["protocol"] != "_shunt" {
			ids = append(ids, id)
		}
	}
	sort.Slice(ids, func(i, j int) bool {
		return strings.ToLower(displayNodeName(ids[i], conf.Options[ids[i]])) < strings.ToLower(displayNodeName(ids[j], conf.Options[ids[j]]))
	})
	for _, id := range ids {
		options = append(options, map[string]interface{}{"id": id, "name": displayNodeName(id, conf.Options[id])})
	}
	currentUI := current
	if currentUI == "" {
		currentUI = "_close"
	}
	currentName := "Close"
	if currentUI != "_close" {
		if currentUI == "_direct" {
			currentName = "Direct"
		} else if currentUI == "_default" {
			currentName = "Use Default"
		} else if currentUI == "_blackhole" {
			currentName = "Block"
		} else if conf.Options[currentUI] != nil {
			currentName = displayNodeName(currentUI, conf.Options[currentUI])
		} else {
			currentName = currentUI
		}
	}
	return map[string]interface{}{"shunt_id": shuntID, "shunt_name": displayNodeName(shuntID, conf.Options[shuntID]), "rule_id": ruleID, "rule_name": ruleName, "is_default": isDefault, "current": currentUI, "current_name": currentName, "options": options}, nil
}

func validateShuntTargetV3(conf *UCIConfig, target string) error {
	if target == "_close" || target == "_direct" || target == "_default" || target == "_blackhole" {
		return nil
	}
	if !safeID.MatchString(target) || conf.SectionType[target] != "nodes" || conf.Options[target]["protocol"] == "_shunt" {
		return errors.New("invalid shunt target")
	}
	return nil
}

func setShuntV3(shuntID, ruleID, target string) (map[string]interface{}, error) {
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
	sg := strings.ToLower(strings.TrimSpace(conf.Options[shuntID]["shunt_group"]))
	rg := strings.ToLower(strings.TrimSpace(conf.Options[ruleID]["group"]))
	if sg != rg {
		return nil, errors.New("rule does not belong to this shunt group")
	}
	if err := validateShuntTargetV3(conf, target); err != nil {
		return nil, err
	}
	backup, err := backupPasswall()
	if err != nil {
		return nil, err
	}
	uci := commandPathRequired("uci")
	if target == "_close" {
		_, err = runOutput(10*time.Second, uci, "-q", "delete", fmt.Sprintf("passwall2.%s.%s", shuntID, ruleID))
		if err != nil && !strings.Contains(err.Error(), "Entry not found") {
			return nil, err
		}
	} else {
		_, err = runOutput(10*time.Second, uci, "set", fmt.Sprintf("passwall2.%s.%s=%s", shuntID, ruleID, target))
		if err != nil {
			return nil, err
		}
	}
	if _, err = runOutput(10*time.Second, uci, "commit", "passwall2"); err != nil {
		return nil, err
	}
	if err = runService("restart"); err != nil {
		return nil, err
	}
	fresh, e := listShuntsV3()
	if e != nil {
		return nil, e
	}
	fresh["updated"] = map[string]interface{}{"shunt_id": shuntID, "rule_id": ruleID, "target": normalizeShuntTarget(target), "backup": backup}
	return fresh, nil
}

func setShuntDefaultV3(shuntID, target string) (map[string]interface{}, error) {
	conf, err := loadUCI()
	if err != nil {
		return nil, err
	}
	if !safeID.MatchString(shuntID) || conf.SectionType[shuntID] != "nodes" || conf.Options[shuntID]["protocol"] != "_shunt" {
		return nil, errors.New("shunt node not found")
	}
	if err := validateShuntTargetV3(conf, target); err != nil {
		return nil, err
	}
	if target == "_default" {
		return nil, errors.New("default route cannot point to Use Default")
	}
	backup, err := backupPasswall()
	if err != nil {
		return nil, err
	}
	uci := commandPathRequired("uci")
	if target == "_close" {
		_, err = runOutput(10*time.Second, uci, "-q", "delete", fmt.Sprintf("passwall2.%s.default_node", shuntID))
		if err != nil && !strings.Contains(err.Error(), "Entry not found") {
			return nil, err
		}
	} else {
		_, err = runOutput(10*time.Second, uci, "set", fmt.Sprintf("passwall2.%s.default_node=%s", shuntID, target))
		if err != nil {
			return nil, err
		}
	}
	if _, err = runOutput(10*time.Second, uci, "commit", "passwall2"); err != nil {
		return nil, err
	}
	if err = runService("restart"); err != nil {
		return nil, err
	}
	fresh, e := listShuntsV3()
	if e != nil {
		return nil, e
	}
	fresh["updated"] = map[string]interface{}{"shunt_id": shuntID, "rule_id": "__default__", "target": normalizeShuntTarget(target), "backup": backup}
	return fresh, nil
}

func systemTimeInfo() (map[string]interface{}, error) {
	conf, _ := loadUCIConfig("system")
	zonename := ""
	timezone := ""
	ntpEnabled := true
	servers := []string{}
	if conf != nil {
		for id, typ := range conf.SectionType {
			if typ == "system" {
				zonename = conf.Options[id]["zonename"]
				timezone = conf.Options[id]["timezone"]
			}
			if typ == "timeserver" {
				if conf.Options[id]["enabled"] == "0" {
					ntpEnabled = false
				}
				if s := strings.TrimSpace(conf.Options[id]["server"]); s != "" {
					servers = strings.Fields(s)
				}
			}
		}
	}
	return map[string]interface{}{"unix": time.Now().Unix(), "local": time.Now().Format(time.RFC3339), "zonename": zonename, "timezone": timezone, "ntp_enabled": ntpEnabled, "ntp_servers": servers}, nil
}

func tailCommand(name string, args []string, maxLines int) ([]string, error) {
	path := commandPath(name)
	if path == "" {
		return nil, fmt.Errorf("%s unavailable", name)
	}
	out, err := runOutput(12*time.Second, path, args...)
	if err != nil {
		return nil, err
	}
	lines := strings.Split(strings.TrimSpace(out), "\n")
	if len(lines) > maxLines {
		lines = lines[len(lines)-maxLines:]
	}
	return lines, nil
}
func systemLogV3() (map[string]interface{}, error) {
	lines, err := tailCommand("logread", []string{}, 60)
	if err != nil {
		return nil, err
	}
	return map[string]interface{}{"lines": lines}, nil
}
func kernelLogV3() (map[string]interface{}, error) {
	lines, err := tailCommand("dmesg", []string{}, 60)
	if err != nil {
		return nil, err
	}
	return map[string]interface{}{"lines": lines}, nil
}

type procRow struct {
	PID       int
	Name, Cmd string
	RSS       uint64
}

func processListV3() (map[string]interface{}, error) {
	matches, _ := filepath.Glob("/proc/[0-9]*")
	rows := []procRow{}
	for _, p := range matches {
		pid, _ := strconv.Atoi(filepath.Base(p))
		if pid <= 0 {
			continue
		}
		status, err := os.ReadFile(filepath.Join(p, "status"))
		if err != nil {
			continue
		}
		name := ""
		rss := uint64(0)
		sc := bufio.NewScanner(bytes.NewReader(status))
		for sc.Scan() {
			line := sc.Text()
			if strings.HasPrefix(line, "Name:") {
				name = strings.TrimSpace(strings.TrimPrefix(line, "Name:"))
			}
			if strings.HasPrefix(line, "VmRSS:") {
				f := strings.Fields(line)
				if len(f) >= 2 {
					n, _ := strconv.ParseUint(f[1], 10, 64)
					rss = n * 1024
				}
			}
		}
		cmdRaw, _ := os.ReadFile(filepath.Join(p, "cmdline"))
		cmd := strings.TrimSpace(strings.ReplaceAll(string(cmdRaw), "\x00", " "))
		if cmd == "" {
			cmd = name
		}
		rows = append(rows, procRow{pid, name, cmd, rss})
	}
	sort.Slice(rows, func(i, j int) bool { return rows[i].RSS > rows[j].RSS })
	if len(rows) > 20 {
		rows = rows[:20]
	}
	items := []map[string]interface{}{}
	for _, r := range rows {
		items = append(items, map[string]interface{}{"pid": r.PID, "name": r.Name, "cmd": r.Cmd, "rss_bytes": r.RSS})
	}
	return map[string]interface{}{"processes": items}, nil
}

var serviceAllow = []string{"network", "dnsmasq", "odhcpd", "firewall", "uhttpd", "cron", "log", "sysntpd", "passwall2"}

func serviceAllowed(name string) bool {
	for _, v := range serviceAllow {
		if name == v {
			return true
		}
	}
	return false
}
func serviceState(name string) map[string]interface{} {
	path := "/etc/init.d/" + name
	if _, err := os.Stat(path); err != nil {
		return map[string]interface{}{"name": name, "installed": false}
	}
	status := "unknown"
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, path, "status")
	out, err := cmd.CombinedOutput()
	if err == nil {
		status = "running"
	} else if strings.Contains(strings.ToLower(string(out)), "not running") || strings.Contains(strings.ToLower(string(out)), "inactive") {
		status = "stopped"
	}
	enabled := false
	cmd2 := exec.CommandContext(ctx, path, "enabled")
	if cmd2.Run() == nil {
		enabled = true
	}
	return map[string]interface{}{"name": name, "installed": true, "status": status, "enabled": enabled}
}
func servicesV3() (map[string]interface{}, error) {
	items := []map[string]interface{}{}
	for _, n := range serviceAllow {
		items = append(items, serviceState(n))
	}
	return map[string]interface{}{"services": items}, nil
}
func restartServiceV3(name string) (map[string]interface{}, error) {
	if !serviceAllowed(name) {
		return nil, errors.New("service is not in the allowed list")
	}
	path := "/etc/init.d/" + name
	if _, err := os.Stat(path); err != nil {
		return nil, errors.New("service is not installed")
	}
	if name == "passwall2" {
		if err := runService("restart"); err != nil {
			return nil, err
		}
	} else {
		if _, err := runOutput(90*time.Second, path, "restart"); err != nil {
			return nil, err
		}
	}
	return servicesV3()
}

func packagesV3(updatesOnly bool) (map[string]interface{}, error) {
	manager := "apk"
	path := commandPath("apk")
	args := []string{"list", "--installed"}
	if path == "" {
		manager = "opkg"
		path = commandPath("opkg")
		args = []string{"list-installed"}
		if updatesOnly {
			args = []string{"list-upgradable"}
		}
	} else if updatesOnly {
		args = []string{"list", "-u"}
	}
	if path == "" {
		return map[string]interface{}{"available": false, "manager": "none", "packages": []string{}, "count": 0}, nil
	}
	out, err := runOutput(45*time.Second, path, args...)
	if err != nil {
		return nil, err
	}
	lines := []string{}
	for _, l := range strings.Split(strings.TrimSpace(out), "\n") {
		l = strings.TrimSpace(l)
		if l != "" {
			lines = append(lines, l)
		}
	}
	sort.Strings(lines)
	total := len(lines)
	limited := false
	if len(lines) > 120 {
		lines = lines[:120]
		limited = true
	}
	return map[string]interface{}{"available": true, "manager": manager, "packages": lines, "count": total, "limited": limited, "updates_only": updatesOnly}, nil
}

func wanStatusV3() (map[string]interface{}, error) {
	ubus := commandPath("ubus")
	if ubus == "" {
		return nil, errors.New("ubus unavailable")
	}
	out, err := runOutput(10*time.Second, ubus, "call", "network.interface.wan", "status")
	if err != nil {
		return nil, err
	}
	var v map[string]interface{}
	if json.Unmarshal([]byte(out), &v) != nil {
		return nil, errors.New("invalid WAN status")
	}
	return v, nil
}
func wanReconnectV3() (map[string]interface{}, error) {
	ubus := commandPath("ubus")
	if ubus == "" {
		return nil, errors.New("ubus unavailable")
	}
	_, _ = runOutput(15*time.Second, ubus, "call", "network.interface.wan", "down")
	time.Sleep(1 * time.Second)
	if _, err := runOutput(15*time.Second, ubus, "call", "network.interface.wan", "up"); err != nil {
		return nil, err
	}
	time.Sleep(2 * time.Second)
	return wanStatusV3()
}
func internetTestV3() (map[string]interface{}, error) {
	ping := testICMP("1.1.1.1")
	tcp := map[string]interface{}{"ok": false}
	start := time.Now()
	c, err := net.DialTimeout("tcp", "1.1.1.1:443", 4*time.Second)
	if err == nil {
		c.Close()
		tcp = map[string]interface{}{"ok": true, "ms": float64(time.Since(start).Microseconds()) / 1000}
	}
	dns := map[string]interface{}{"ok": false, "detail": "nslookup unavailable"}
	if ns := commandPath("nslookup"); ns != "" {
		out, e := runOutput(8*time.Second, ns, "openwrt.org")
		if e == nil {
			dns = map[string]interface{}{"ok": true, "detail": strings.TrimSpace(out)}
		} else {
			dns = map[string]interface{}{"ok": false, "detail": e.Error()}
		}
	}
	return map[string]interface{}{"ping": ping, "tcp443": tcp, "dns": dns}, nil
}
func routesV3() (map[string]interface{}, error) {
	ip := commandPath("ip")
	if ip == "" {
		return nil, errors.New("ip command unavailable")
	}
	v4, _ := runOutput(10*time.Second, ip, "route", "show")
	v6, _ := runOutput(10*time.Second, ip, "-6", "route", "show")
	return map[string]interface{}{"ipv4": strings.Split(strings.TrimSpace(v4), "\n"), "ipv6": strings.Split(strings.TrimSpace(v6), "\n")}, nil
}
func dnsInfoV3() (map[string]interface{}, error) {
	paths := []string{"/tmp/resolv.conf.d/resolv.conf.auto", "/tmp/resolv.conf.auto", "/etc/resolv.conf"}
	files := map[string][]string{}
	for _, p := range paths {
		raw, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		ls := []string{}
		for _, l := range strings.Split(strings.TrimSpace(string(raw)), "\n") {
			l = strings.TrimSpace(l)
			if l != "" {
				ls = append(ls, l)
			}
		}
		files[p] = ls
	}
	return map[string]interface{}{"files": files}, nil
}
func dhcpLeasesV3() (map[string]interface{}, error) {
	raw, err := os.ReadFile("/tmp/dhcp.leases")
	if err != nil {
		return map[string]interface{}{"leases": []map[string]interface{}{}}, nil
	}
	items := []map[string]interface{}{}
	now := time.Now().Unix()
	for _, l := range strings.Split(string(raw), "\n") {
		f := strings.Fields(l)
		if len(f) < 4 {
			continue
		}
		exp, _ := strconv.ParseInt(f[0], 10, 64)
		items = append(items, map[string]interface{}{"expires": exp, "remaining_seconds": max64(0, exp-now), "mac": strings.ToUpper(f[1]), "ip": f[2], "hostname": f[3]})
	}
	return map[string]interface{}{"leases": items, "count": len(items)}, nil
}
func max64(a, b int64) int64 {
	if a > b {
		return a
	}
	return b
}
func wireGuardV3() (map[string]interface{}, error) {
	wg := commandPath("wg")
	if wg == "" {
		return map[string]interface{}{"available": false, "lines": []string{}}, nil
	}
	out, err := runOutput(12*time.Second, wg, "show")
	if err != nil {
		return nil, err
	}
	lines := strings.Split(strings.TrimSpace(out), "\n")
	return map[string]interface{}{"available": true, "lines": lines}, nil
}

func wifiRadiosV3() (map[string]interface{}, error) {
	conf, err := loadUCIConfig("wireless")
	if err != nil {
		return nil, err
	}
	ids := []string{}
	for id, t := range conf.SectionType {
		if t == "wifi-device" {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	items := []map[string]interface{}{}
	for _, id := range ids {
		o := conf.Options[id]
		items = append(items, map[string]interface{}{"section": id, "type": o["type"], "band": o["band"], "channel": o["channel"], "htmode": o["htmode"], "country": o["country"], "disabled": o["disabled"] == "1"})
	}
	return map[string]interface{}{"radios": items}, nil
}
func wifiRadioToggleV3(section string, enable bool) (map[string]interface{}, error) {
	if !safeUCISection.MatchString(section) {
		return nil, errors.New("invalid radio section")
	}
	conf, err := loadUCIConfig("wireless")
	if err != nil {
		return nil, err
	}
	if conf.SectionType[section] != "wifi-device" {
		return nil, errors.New("Wi-Fi radio not found")
	}
	if _, err := backupConfigFile("wireless"); err != nil {
		return nil, err
	}
	val := "1"
	if enable {
		val = "0"
	}
	uci := commandPathRequired("uci")
	if _, err := runOutput(10*time.Second, uci, "set", fmt.Sprintf("wireless.%s.disabled=%s", section, val)); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, uci, "commit", "wireless"); err != nil {
		return nil, err
	}
	if _, err := reloadWiFi(); err != nil {
		return nil, err
	}
	return wifiRadiosV3()
}
func wifiSetChannelV3(section, channel string) (map[string]interface{}, error) {
	if !safeUCISection.MatchString(section) {
		return nil, errors.New("invalid radio section")
	}
	if channel != "auto" {
		n, err := strconv.Atoi(channel)
		if err != nil || n < 1 || n > 233 {
			return nil, errors.New("invalid Wi-Fi channel")
		}
	}
	conf, err := loadUCIConfig("wireless")
	if err != nil {
		return nil, err
	}
	if conf.SectionType[section] != "wifi-device" {
		return nil, errors.New("Wi-Fi radio not found")
	}
	if _, err := backupConfigFile("wireless"); err != nil {
		return nil, err
	}
	uci := commandPathRequired("uci")
	if _, err := runOutput(10*time.Second, uci, "set", fmt.Sprintf("wireless.%s.channel=%s", section, channel)); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, uci, "commit", "wireless"); err != nil {
		return nil, err
	}
	if _, err := reloadWiFi(); err != nil {
		return nil, err
	}
	return wifiRadiosV3()
}
func wifiStationsV3() (map[string]interface{}, error) {
	ubus := commandPath("ubus")
	if ubus == "" {
		return nil, errors.New("ubus unavailable")
	}
	list, err := runOutput(8*time.Second, ubus, "list", "hostapd.*")
	if err != nil {
		return map[string]interface{}{"stations": []map[string]interface{}{}, "count": 0}, nil
	}
	stations := []map[string]interface{}{}
	for _, obj := range strings.Split(strings.TrimSpace(list), "\n") {
		obj = strings.TrimSpace(obj)
		if obj == "" {
			continue
		}
		out, e := runOutput(8*time.Second, ubus, "call", obj, "get_clients")
		if e != nil {
			continue
		}
		var v map[string]interface{}
		if json.Unmarshal([]byte(out), &v) != nil {
			continue
		}
		clients, _ := v["clients"].(map[string]interface{})
		for mac, raw := range clients {
			m, _ := raw.(map[string]interface{})
			item := map[string]interface{}{"interface": strings.TrimPrefix(obj, "hostapd."), "mac": strings.ToUpper(mac)}
			for _, k := range []string{"signal", "noise", "inactive", "connected_time", "authorized"} {
				if x, ok := m[k]; ok {
					item[k] = x
				}
			}
			if b, ok := m["bytes"].(map[string]interface{}); ok {
				item["bytes"] = b
			}
			stations = append(stations, item)
		}
	}
	sort.Slice(stations, func(i, j int) bool { return fmt.Sprint(stations[i]["mac"]) < fmt.Sprint(stations[j]["mac"]) })
	return map[string]interface{}{"stations": stations, "count": len(stations)}, nil
}

func firewallZonesV3() (map[string]interface{}, error) {
	conf, err := loadUCIConfig("firewall")
	if err != nil {
		return nil, err
	}
	items := []map[string]interface{}{}
	for id, t := range conf.SectionType {
		if t != "zone" {
			continue
		}
		o := conf.Options[id]
		items = append(items, map[string]interface{}{"section": id, "name": o["name"], "input": o["input"], "output": o["output"], "forward": o["forward"], "network": o["network"], "masq": o["masq"] == "1"})
	}
	sort.Slice(items, func(i, j int) bool { return fmt.Sprint(items[i]["name"]) < fmt.Sprint(items[j]["name"]) })
	return map[string]interface{}{"zones": items}, nil
}
func firewallRedirectsV3() (map[string]interface{}, error) {
	conf, err := loadUCIConfig("firewall")
	if err != nil {
		return nil, err
	}
	items := []map[string]interface{}{}
	for id, t := range conf.SectionType {
		if t != "redirect" {
			continue
		}
		o := conf.Options[id]
		enabled := o["enabled"] != "0"
		items = append(items, map[string]interface{}{"section": id, "name": o["name"], "src": o["src"], "src_dport": o["src_dport"], "dest": o["dest"], "dest_ip": o["dest_ip"], "dest_port": o["dest_port"], "proto": o["proto"], "enabled": enabled})
	}
	sort.Slice(items, func(i, j int) bool { return fmt.Sprint(items[i]["name"]) < fmt.Sprint(items[j]["name"]) })
	return map[string]interface{}{"redirects": items}, nil
}
func firewallRedirectToggleV3(section string, enable bool) (map[string]interface{}, error) {
	if !safeUCISection.MatchString(section) {
		return nil, errors.New("invalid firewall section")
	}
	conf, err := loadUCIConfig("firewall")
	if err != nil {
		return nil, err
	}
	if conf.SectionType[section] != "redirect" {
		return nil, errors.New("port forward not found")
	}
	if _, err := backupConfigFile("firewall"); err != nil {
		return nil, err
	}
	val := "0"
	if enable {
		val = "1"
	}
	uci := commandPathRequired("uci")
	if _, err := runOutput(10*time.Second, uci, "set", fmt.Sprintf("firewall.%s.enabled=%s", section, val)); err != nil {
		return nil, err
	}
	if _, err := runOutput(10*time.Second, uci, "commit", "firewall"); err != nil {
		return nil, err
	}
	if fw := "/etc/init.d/firewall"; fileExistsV3(fw) {
		if _, err := runOutput(60*time.Second, fw, "restart"); err != nil {
			return nil, err
		}
	}
	return firewallRedirectsV3()
}
func fileExistsV3(p string) bool { _, err := os.Stat(p); return err == nil }

var diagTargetRE = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._:-]{0,252}$`)

func validateDiagTarget(s string) (string, error) {
	s = strings.TrimSpace(s)
	if s == "" || !diagTargetRE.MatchString(s) || strings.Contains(s, "..") {
		return "", errors.New("invalid host or IP")
	}
	return s, nil
}
func diagPingV3(target string) (map[string]interface{}, error) {
	t, err := validateDiagTarget(target)
	if err != nil {
		return nil, err
	}
	p := commandPath("ping")
	if p == "" {
		return nil, errors.New("ping unavailable")
	}
	out, err := runOutput(15*time.Second, p, "-c", "4", "-W", "2", t)
	if err != nil {
		return map[string]interface{}{"target": t, "ok": false, "output": out}, nil
	}
	return map[string]interface{}{"target": t, "ok": true, "output": out}, nil
}
func diagTracerouteV3(target string) (map[string]interface{}, error) {
	t, err := validateDiagTarget(target)
	if err != nil {
		return nil, err
	}
	tr := commandPath("traceroute")
	if tr == "" {
		tr = commandPath("traceroute6")
	}
	if tr == "" {
		return map[string]interface{}{"target": t, "available": false, "output": "traceroute is not installed"}, nil
	}
	out, err := runOutput(35*time.Second, tr, "-m", "12", "-w", "2", t)
	return map[string]interface{}{"target": t, "available": true, "ok": err == nil, "output": out}, nil
}
