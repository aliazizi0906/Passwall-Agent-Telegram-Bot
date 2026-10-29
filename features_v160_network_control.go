package main

// Controlled UCI transactions for OpenWrt packages. The on-router lease and
// rollback watchdog operate independently of the hosted cPanel backend.
// This is intentionally a small, fixed action vocabulary, not an arbitrary
// shell or generic UCI command exposed to a remote operator.
import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const configSafeRootV16 = "/etc/pw-agent/safety"
const configSafeTTLSecondsV16 = 120

var configSectionV16 = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9_]{0,47}$`)
var safeIfNameV16 = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,32}$`)

type configTransactionV16 struct {
	ID        string `json:"id"`
	Package   string `json:"package"`
	ExpiresAt int64  `json:"expires_at"`
}

func configSafeDirV16(root string) string { return filepath.Join(root, "config-pending") }
func configPackageSupportedV16(pkg string) bool {
	switch pkg {
	case "pbr", "firewall", "sqm", "wireless", "openvpn":
		return true
	}
	return false
}
func configServiceV16(pkg string) string {
	switch pkg {
	case "pbr":
		return "pbr"
	case "firewall":
		return "firewall"
	case "sqm":
		return "sqm"
	case "wireless":
		return "wifi"
	case "openvpn":
		return "openvpn"
	}
	return ""
}
func configReloadV16(pkg string) error {
	var cmd string
	if pkg == "wireless" {
		cmd = commandPath("wifi")
	} else {
		cmd = "/etc/init.d/" + configServiceV16(pkg)
	}
	if cmd == "" {
		return errors.New("required config reload utility unavailable")
	}
	if pkg != "wireless" {
		if _, err := os.Stat(cmd); err != nil {
			return fmt.Errorf("service for %s is unavailable", pkg)
		}
	}
	op := "restart"
	if pkg == "wireless" {
		op = "reload"
	}
	_, err := runOutput(55*time.Second, cmd, op)
	return err
}
func configPendingV16(root string) (configTransactionV16, error) {
	var t configTransactionV16
	raw, e := os.ReadFile(filepath.Join(configSafeDirV16(root), "transaction.json"))
	if e != nil {
		return t, e
	}
	if e = json.Unmarshal(raw, &t); e != nil {
		return t, e
	}
	if len(t.ID) != 32 || !validSafeHexV15(t.ID) || !configPackageSupportedV16(t.Package) {
		return t, errors.New("corrupt UCI transaction")
	}
	return t, nil
}
func restoreConfigV16(root string, reload func(string) error) error {
	return restoreConfigAtV16(root, "/etc/config", reload)
}
func restoreConfigAtV16(root, configRoot string, reload func(string) error) error {
	t, e := configPendingV16(root)
	if e != nil {
		return e
	}
	target := filepath.Join(configRoot, t.Package)
	backup, e := os.ReadFile(filepath.Join(configSafeDirV16(root), "original"))
	if e != nil || len(backup) < 1 || len(backup) > 2<<20 {
		return errors.New("missing original UCI file; manual recovery needed")
	}
	if configRoot == "/etc/config" {
		if uci := commandPath("uci"); uci != "" {
			_, _ = runOutput(8*time.Second, uci, "revert", t.Package)
		}
	}
	tmp := target + ".pw-restore"
	if e = os.WriteFile(tmp, backup, 0600); e != nil {
		return e
	}
	if f, x := os.OpenFile(tmp, os.O_RDONLY, 0); x == nil {
		_ = f.Sync()
		_ = f.Close()
	}
	if e = os.Rename(tmp, target); e != nil {
		_ = os.Remove(tmp)
		return e
	}
	if e = reload(t.Package); e != nil {
		return fmt.Errorf("UCI restored; service reload failed: %w", e)
	}
	return os.RemoveAll(configSafeDirV16(root))
}
func configRecoverV16() error {
	return safeLockV15(configSafeRootV16, func() error {
		if _, e := os.Stat(configSafeDirV16(configSafeRootV16)); os.IsNotExist(e) {
			return nil
		}
		return restoreConfigV16(configSafeRootV16, configReloadV16)
	})
}
func configWatchdogV16(id string) error {
	if len(id) != 32 || !validSafeHexV15(id) {
		return errors.New("invalid config transaction ID")
	}
	for {
		delay := time.Duration(0)
		err := safeLockV15(configSafeRootV16, func() error {
			t, e := configPendingV16(configSafeRootV16)
			if os.IsNotExist(e) {
				return nil
			}
			if e != nil {
				return e
			}
			if t.ID != id {
				return nil
			}
			if now := time.Now().Unix(); now < t.ExpiresAt {
				delay = time.Duration(t.ExpiresAt-now) * time.Second
				if delay > 5*time.Second {
					delay = 5 * time.Second
				}
				return nil
			}
			return restoreConfigV16(configSafeRootV16, configReloadV16)
		})
		if err != nil {
			return err
		}
		if delay == 0 {
			return nil
		}
		time.Sleep(delay)
	}
}
func launchConfigWatchdogV16(id string) error {
	path, e := os.Executable()
	if e != nil {
		return e
	}
	cmd := exec.Command(path, "--pw-config-watchdog", id)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	cmd.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin"}
	null, e := os.OpenFile("/dev/null", os.O_WRONLY, 0)
	if e != nil {
		return e
	}
	defer null.Close()
	cmd.Stdin = null
	cmd.Stdout = null
	cmd.Stderr = null
	if e = cmd.Start(); e != nil {
		return e
	}
	go func() { _ = cmd.Wait() }()
	return nil
}
func configSafeCLIIfRequestedV16() bool {
	if len(os.Args) < 2 {
		return false
	}
	switch os.Args[1] {
	case "--pw-config-recover":
		if e := configRecoverV16(); e != nil {
			fmt.Fprintln(os.Stderr, e)
			os.Exit(1)
		}
		return true
	case "--pw-config-watchdog":
		if len(os.Args) != 3 {
			fmt.Fprintln(os.Stderr, "missing transaction ID")
			os.Exit(1)
		}
		if e := configWatchdogV16(os.Args[2]); e != nil {
			fmt.Fprintln(os.Stderr, e)
			os.Exit(1)
		}
		return true
	}
	return false
}
func configSafeApplyV16(pkg string, ops [][]string) (map[string]interface{}, error) {
	if !configPackageSupportedV16(pkg) || len(ops) < 1 || len(ops) > 20 {
		return nil, errors.New("invalid package transaction")
	}
	if commandPath("uci") == "" {
		return nil, errors.New("UCI unavailable")
	}
	if _, e := os.Stat("/etc/config/" + pkg); e != nil {
		return nil, fmt.Errorf("%s config not installed", pkg)
	}
	if pkg == "pbr" || pkg == "sqm" || pkg == "openvpn" {
		if _, e := os.Stat("/etc/init.d/" + pkg); e != nil {
			return nil, fmt.Errorf("%s package not installed", pkg)
		}
	}
	nonce := make([]byte, 16)
	if _, e := rand.Read(nonce); e != nil {
		return nil, e
	}
	id := hex.EncodeToString(nonce)
	var result map[string]interface{}
	err := safeLockV15(configSafeRootV16, func() error {
		dir := configSafeDirV16(configSafeRootV16)
		if _, e := os.Stat(dir); e == nil {
			return errors.New("another UCI operation awaits confirmation or rollback")
		}
		// Mutations are globally exclusive with the existing Wi-Fi SSID safe lease.
		if _, e := os.Stat(wifiSafeDirV15(configSafeRootV16)); e == nil {
			return errors.New("Wi-Fi SSID change awaits confirmation")
		}
		source, e := os.ReadFile("/etc/config/" + pkg)
		if e != nil || len(source) == 0 || len(source) > 2<<20 {
			return errors.New("original UCI config unavailable")
		}
		if e = os.Mkdir(dir, 0700); e != nil {
			return e
		}
		if e = durableWriteV15(filepath.Join(dir, "original"), source); e != nil {
			_ = os.RemoveAll(dir)
			return e
		}
		tx := configTransactionV16{ID: id, Package: pkg, ExpiresAt: time.Now().Add(configSafeTTLSecondsV16 * time.Second).Unix()}
		raw, _ := json.Marshal(tx)
		if e = durableWriteV15(filepath.Join(dir, "transaction.json"), raw); e != nil {
			_ = os.RemoveAll(dir)
			return e
		}
		if f, x := os.Open(dir); x == nil {
			_ = f.Sync()
			_ = f.Close()
		}
		if f, x := os.Open(configSafeRootV16); x == nil {
			_ = f.Sync()
			_ = f.Close()
		}
		if e = launchConfigWatchdogV16(id); e != nil {
			_ = os.RemoveAll(dir)
			return e
		}
		uci := commandPath("uci")
		for _, op := range ops {
			// Only hardcoded ops from validated action builders are permitted.
			if len(op) < 2 || len(op) > 3 || (op[0] != "set" && op[0] != "delete") {
				e = errors.New("invalid controlled UCI operation")
				break
			}
			if _, e = runOutput(12*time.Second, uci, op...); e != nil {
				break
			}
		}
		if e == nil {
			_, e = runOutput(15*time.Second, uci, "commit", pkg)
		}
		if e == nil {
			e = configReloadV16(pkg)
		}
		if e != nil {
			if r := restoreConfigV16(configSafeRootV16, configReloadV16); r != nil {
				return fmt.Errorf("failed update: %v; rollback failed: %w", e, r)
			}
			return fmt.Errorf("%s configuration failed; previous settings restored: %w", pkg, e)
		}
		result = map[string]interface{}{"pending": true, "package": pkg, "transaction_id": id, "expires_at": tx.ExpiresAt, "confirm_within_seconds": configSafeTTLSecondsV16, "message": "Verify service and management connectivity, then confirm; otherwise router-local rollback occurs."}
		return nil
	})
	return result, err
}
func configSafeConfirmV16(params map[string]interface{}) (map[string]interface{}, error) {
	id, e := stringParam(params, "transaction_id")
	if e != nil || len(id) != 32 || !validSafeHexV15(id) {
		return nil, errors.New("invalid UCI confirmation ID")
	}
	var pkg string
	e = safeLockV15(configSafeRootV16, func() error {
		t, x := configPendingV16(configSafeRootV16)
		if x != nil {
			return x
		}
		if t.ID != id {
			return errors.New("UCI confirmation ID mismatch")
		}
		if time.Now().Unix() >= t.ExpiresAt-5 {
			return errors.New("too late to confirm; local rollback is pending")
		}
		pkg = t.Package
		return os.RemoveAll(configSafeDirV16(configSafeRootV16))
	})
	if e != nil {
		return nil, e
	}
	return map[string]interface{}{"confirmed": true, "package": pkg, "transaction_id": id}, nil
}
func validPolicyCIDRV16(s string) (string, error) {
	ip, netw, e := net.ParseCIDR(s)
	if e != nil || ip == nil || netw == nil || len(s) > 64 {
		return "", errors.New("invalid CIDR")
	}
	if !ip.Equal(netw.IP) || ip.IsMulticast() || ip.IsUnspecified() || ip.IsLoopback() {
		return "", errors.New("unsafe CIDR")
	}
	ones, bits := netw.Mask.Size()
	if ones == 0 || bits != 32 && bits != 128 {
		return "", errors.New("default route and invalid CIDR refused")
	}
	return netw.String(), nil
}

// PBR uses the actual pbr OpenWrt service and UCI policies. The requested
// interface must already be defined in OpenWrt network UCI.
func pbrPolicyAddV16(params map[string]interface{}) (map[string]interface{}, error) {
	name, e := stringParam(params, "name")
	if e != nil || !configSectionV16.MatchString(name) || !strings.HasPrefix(name, "pw_") {
		return nil, errors.New("policy name must start pw_")
	}
	destination, e := stringParam(params, "cidr")
	if e != nil {
		return nil, e
	}
	cidr, e := validPolicyCIDRV16(destination)
	if e != nil {
		return nil, e
	}
	iface, e := stringParam(params, "interface")
	if e != nil || !configSectionV16.MatchString(iface) {
		return nil, errors.New("invalid PBR interface")
	}
	netconf, e := loadUCIConfig("network")
	if e != nil {
		return nil, e
	}
	if netconf.SectionType[iface] != "interface" {
		return nil, errors.New("PBR interface is not a configured OpenWrt network interface")
	}
	cfg, e := loadUCIConfig("pbr")
	if e != nil {
		return nil, errors.New("pbr is not installed/configured")
	}
	if cfg.SectionType[name] != "" {
		return nil, errors.New("policy already exists")
	}
	return configSafeApplyV16("pbr", [][]string{{"set", "pbr." + name + "=policy"}, {"set", "pbr." + name + ".name=" + name}, {"set", "pbr." + name + ".dest_addr=" + cidr}, {"set", "pbr." + name + ".interface=" + iface}})
}
func pbrPolicyDeleteV16(params map[string]interface{}) (map[string]interface{}, error) {
	name, e := stringParam(params, "name")
	if e != nil || !configSectionV16.MatchString(name) || !strings.HasPrefix(name, "pw_") {
		return nil, errors.New("only PW-managed policies may be deleted")
	}
	cfg, e := loadUCIConfig("pbr")
	if e != nil {
		return nil, e
	}
	if cfg.SectionType[name] != "policy" {
		return nil, errors.New("PW-managed PBR policy not found")
	}
	return configSafeApplyV16("pbr", [][]string{{"delete", "pbr." + name}})
}
func pbrPolicyListV16() (map[string]interface{}, error) {
	cfg, e := loadUCIConfig("pbr")
	if e != nil {
		return map[string]interface{}{"installed": false, "policies": []map[string]string{}}, nil
	}
	entries := []map[string]string{}
	for name, typ := range cfg.SectionType {
		if typ == "policy" && configSectionV16.MatchString(name) {
			o := cfg.Options[name]
			entries = append(entries, map[string]string{"name": name, "interface": o["interface"], "destination": o["dest_addr"], "managed_by_pw": strconv.FormatBool(strings.HasPrefix(name, "pw_"))})
		}
	}
	return map[string]interface{}{"installed": true, "policies": entries}, nil
}

// Scope is specifically LAN -> WAN, not a total client block.
func clientInternetBlockV16(params map[string]interface{}) (map[string]interface{}, error) {
	macStr, e := stringParam(params, "mac")
	if e != nil {
		return nil, e
	}
	mac, e := net.ParseMAC(macStr)
	if e != nil || len(mac) != 6 || mac[0]&1 != 0 || mac.String() == "00:00:00:00:00:00" {
		return nil, errors.New("invalid unicast MAC")
	}
	block, ok := params["blocked"].(bool)
	if !ok {
		return nil, errors.New("blocked boolean required")
	}
	key := "pw_mac_" + strings.ReplaceAll(mac.String(), ":", "")
	cfg, e := loadUCIConfig("firewall")
	if e != nil {
		return nil, e
	}
	if !block {
		if cfg.SectionType[key] != "rule" {
			return map[string]interface{}{"changed": false, "already_unblocked": true}, nil
		}
		return configSafeApplyV16("firewall", [][]string{{"delete", "firewall." + key}})
	}
	if cfg.SectionType[key] != "" {
		return nil, errors.New("firewall section already exists; refusing to overwrite")
	}
	return configSafeApplyV16("firewall", [][]string{
		{"set", "firewall." + key + "=rule"}, {"set", "firewall." + key + ".name=PW internet block " + mac.String()},
		{"set", "firewall." + key + ".src=lan"}, {"set", "firewall." + key + ".dest=wan"},
		{"set", "firewall." + key + ".src_mac=" + mac.String()}, {"set", "firewall." + key + ".target=REJECT"},
		{"set", "firewall." + key + ".proto=all"}, {"set", "firewall." + key + ".family=any"},
	})
}
func sqmQueueSetV16(params map[string]interface{}) (map[string]interface{}, error) {
	section, e := stringParam(params, "section")
	if e != nil || !configSectionV16.MatchString(section) {
		return nil, errors.New("invalid SQM section")
	}
	cfg, e := loadUCIConfig("sqm")
	if e != nil {
		return nil, e
	}
	if cfg.SectionType[section] != "queue" {
		return nil, errors.New("SQM queue not found")
	}
	in, e := stringParam(params, "download_kbit")
	if e != nil {
		return nil, e
	}
	out, e := stringParam(params, "upload_kbit")
	if e != nil {
		return nil, e
	}
	d, e := strconv.Atoi(in)
	if e != nil || d < 64 || d > 10000000 {
		return nil, errors.New("invalid download speed")
	}
	u, e := strconv.Atoi(out)
	if e != nil || u < 64 || u > 10000000 {
		return nil, errors.New("invalid upload speed")
	}
	return configSafeApplyV16("sqm", [][]string{{"set", "sqm." + section + ".download=" + strconv.Itoa(d)}, {"set", "sqm." + section + ".upload=" + strconv.Itoa(u)}, {"set", "sqm." + section + ".enabled=1"}})
}
func openvpnProfileToggleV16(params map[string]interface{}) (map[string]interface{}, error) {
	name, e := stringParam(params, "profile")
	if e != nil || !configSectionV16.MatchString(name) {
		return nil, errors.New("invalid OpenVPN profile")
	}
	on, ok := params["enabled"].(bool)
	if !ok {
		return nil, errors.New("enabled boolean required")
	}
	cfg, e := loadUCIConfig("openvpn")
	if e != nil {
		return nil, e
	}
	if cfg.SectionType[name] != "openvpn" {
		return nil, errors.New("OpenVPN profile not found")
	}
	flag := "0"
	if on {
		flag = "1"
	}
	return configSafeApplyV16("openvpn", [][]string{{"set", "openvpn." + name + ".enabled=" + flag}})
}
func wifiChannelSafeV16(params map[string]interface{}) (map[string]interface{}, error) {
	radio, e := stringParam(params, "radio")
	if e != nil || !configSectionV16.MatchString(radio) {
		return nil, errors.New("invalid radio section")
	}
	chanStr, e := stringParam(params, "channel")
	if e != nil {
		return nil, e
	}
	if chanStr != "auto" {
		n, x := strconv.Atoi(chanStr)
		if x != nil || n < 1 || n > 233 {
			return nil, errors.New("invalid Wi-Fi channel")
		}
		chanStr = strconv.Itoa(n)
	}
	cfg, e := loadUCIConfig("wireless")
	if e != nil {
		return nil, e
	}
	if cfg.SectionType[radio] != "wifi-device" {
		return nil, errors.New("radio is not wifi-device")
	}
	return configSafeApplyV16("wireless", [][]string{{"set", "wireless." + radio + ".channel=" + chanStr}})
}
func vpnWANGuardV16(params map[string]interface{}) (map[string]interface{}, error) {
	enable, ok := params["enabled"].(bool)
	if !ok {
		return nil, errors.New("enabled boolean required")
	}
	cfg, e := loadUCIConfig("firewall")
	if e != nil {
		return nil, e
	}
	name := "pw_vpn_wan_guard"
	if !enable {
		if cfg.SectionType[name] != "rule" {
			return map[string]interface{}{"changed": false, "already_disabled": true}, nil
		}
		return configSafeApplyV16("firewall", [][]string{{"delete", "firewall." + name}})
	}
	if cfg.SectionType[name] != "" {
		return nil, errors.New("VPN guard section already exists; refusing to overwrite")
	}
	// This is an explicit LAN->WAN forwarded traffic guard. Router output and
	// interface-based WAN tunnel control are not covered; no leak-proof promise.
	return configSafeApplyV16("firewall", [][]string{
		{"set", "firewall." + name + "=rule"}, {"set", "firewall." + name + ".name=PW block direct LAN-to-WAN forwarding"},
		{"set", "firewall." + name + ".src=lan"}, {"set", "firewall." + name + ".dest=wan"},
		{"set", "firewall." + name + ".target=REJECT"}, {"set", "firewall." + name + ".proto=all"}, {"set", "firewall." + name + ".family=any"},
	})
}


 // dnsPortGuardV16 is limited to client-originated classic DNS (TCP/UDP 53)
 // forwarded from the LAN to WAN zone. Router-generated DNS, DoH and DoT are
 // explicitly not covered. It cannot be described as full DNS leak protection.
func dnsPortGuardV16(params map[string]interface{}) (map[string]interface{},error) {
 enabled,ok:=params["enabled"].(bool);if !ok{return nil,errors.New("enabled boolean required")}
 cfg,e:=loadUCIConfig("firewall");if e!=nil{return nil,e}
 name:="pw_dns53_guard"
 if !enabled {
  if cfg.SectionType[name]!="rule"{return map[string]interface{}{"changed":false,"already_disabled":true},nil}
  if cfg.Options[name]["name"]!="PW block LAN WAN DNS port 53"{return nil,errors.New("DNS rule is not owned by PW Agent")}
  return configSafeApplyV16("firewall",[][]string{{"delete","firewall."+name}})
 }
 if cfg.SectionType[name]!=""{return nil,errors.New("reserved DNS rule name already exists")}
 return configSafeApplyV16("firewall",[][]string{
  {"set","firewall."+name+"=rule"},
  {"set","firewall."+name+".name=PW block LAN WAN DNS port 53"},
  {"set","firewall."+name+".src=lan"},{"set","firewall."+name+".dest=wan"},
  {"set","firewall."+name+".proto=tcp udp"},{"set","firewall."+name+".dest_port=53"},
  {"set","firewall."+name+".target=REJECT"},{"set","firewall."+name+".family=any"},
 })
}
