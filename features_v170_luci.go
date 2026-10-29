package main

// LuCI parity is capability-driven: report only installed packages and
// actual Agent operations. Never expose UCI secrets, wireless keys or raw
// configuration blobs in a read-only browser catalog.
import (
	"errors"
	"fmt"
	"net"
	"os"
	"regexp"
	"sort"
	"strings"
)

var hostnameLuciV17 = regexp.MustCompile(`^[A-Za-z][A-Za-z0-9-]{0,62}$`)
var leaseNameLuciV17 = regexp.MustCompile(`^[a-zA-Z][a-zA-Z0-9_-]{0,62}$`)

type luciModuleV17 struct {
	ID      string   `json:"id"`
	Title   string   `json:"title"`
	Group   string   `json:"group"`
	Package string   `json:"package"`
	State   string   `json:"state"`
	Detail  string   `json:"detail"`
	Page    string   `json:"page"`
	Actions []string `json:"actions"`
}
type luciModuleSpecV17 struct {
	id, title, group, pkg, page string
	actions                     []string
	detail                      string
}

var luciModulesV17 = []luciModuleSpecV17{
	{"overview", "Router overview", "status", "system", "home", []string{"system.status"}, "CPU, RAM, WAN and Agent telemetry"},
	{"interfaces", "Interfaces", "network", "network", "internet", []string{"system.interfaces", "network.wan"}, "Existing WAN/LAN interfaces and links"},
	{"wifi", "Wireless", "network", "wireless", "wifi", []string{"wifi.list", "wifi.radios", "wifi.ssid.safe_apply", "wifi.channel.safe_apply", "luci.wifi.iface.toggle"}, "Wi-Fi radios, SSID and per-interface enable/disable"},
	{"dhcp", "DHCP and DNS", "network", "dhcp", "dns", []string{"network.dhcp_leases", "luci.dhcp.static.list", "luci.dhcp.static.add", "luci.dhcp.static.delete"}, "Client leases and validated static reservations"},
	{"firewall", "Firewall4", "network", "firewall", "dns", []string{"firewall.wan_guard.set", "firewall.dns53_guard.set", "clients.internet.block"}, "Scoped rules with timed rollback; not general firewall editing"},
	{"routes", "Routes", "network", "network", "internet", []string{"network.routes"}, "IPv4 and IPv6 routes, read only"},
	{"diagnostics", "Diagnostics", "network", "network", "internet", []string{"network.doctor", "network.internet_test", "network.speed_probe"}, "Path, DNS and connectivity checks"},
	{"system", "System settings", "system", "system", "luci", []string{"luci.system.status", "luci.system.hostname.set"}, "Hostname change via timed safe-apply"},
	{"packages", "Package management", "system", "system", "system", []string{"system.packages", "system.package_updates"}, "Installed apk/opkg packages and available updates; read only"},
	{"logs", "System and kernel logs", "system", "system", "system", []string{"agent.diagnose"}, "Diagnostics only; full log viewer not implemented"},
	{"services", "Startup services", "system", "system", "system", []string{"system.services", "system.service.restart"}, "Explicit allowlisted service restarts"},
	{"led", "LEDs", "system", "system", "led", []string{"system.leds", "led.brightness.set", "led.rgb.set"}, "Hardware-gated LED operations"},
	{"processes", "Processes", "status", "system", "system", []string{"system.status"}, "Process management not available in PW Control"},
	{"backup", "Backup and recovery", "system", "system", "system", []string{"system.backup.create"}, "Backup creation and manual restore workflows"},
	{"terminal", "Terminal", "system", "system", "terminal", []string{"terminal.exec"}, "Non-interactive authenticated SSH console"},
	{"openvpn", "OpenVPN", "vpn", "openvpn", "openvpn", []string{"openvpn.status", "openvpn.profile.toggle", "openvpn.service.control"}, "Existing profiles and service controls"},
	{"passwall", "Passwall2", "vpn", "passwall2", "proxy", []string{"passwall2.status", "passwall2.restart"}, "Installed Passwall2 nodes and service"},
	{"wireguard", "WireGuard", "vpn", "network", "internet", []string{"network.wireguard"}, "Tunnel inspection only"},
	{"pbr", "Policy routing", "vpn", "pbr", "openvpn", []string{"pbr.policy.list", "pbr.policy.add", "pbr.policy.delete"}, "Named PBR policy add/remove with local rollback"},
	{"sqm", "SQM QoS", "services", "sqm", "gaming", []string{"sqm.queue.set"}, "Existing interface queue rate controls"},
	{"upnp", "UPnP", "services", "upnpd", "gaming", []string{}, "Status and safe mutation adapter not implemented"},
	{"ddns", "Dynamic DNS", "services", "ddns", "luci", []string{}, "LuCI DDNS extension adapter not implemented"},
	{"adblock", "Ad-blocking", "services", "adblock", "dns", []string{}, "Adapter depends on installed DNS filtering package"},
	{"statistics", "Real-time statistics", "status", "system", "home", []string{"system.status"}, "Agent telemetry; LuCI statistics plugin not replicated"},
	{"switch", "Switch/VLAN", "network", "network", "luci", []string{}, "Hardware-specific VLAN/switch changes not certified"},
	{"mounts", "Mount points", "system", "fstab", "luci", []string{}, "Storage/mount changes not implemented"},
	{"cron", "Scheduled tasks", "system", "system", "automation", []string{}, "PW scheduling exists; arbitrary system crontab editing is disabled"},
	{"cellular", "Mobile modem", "services", "modemmanager", "cellular", []string{"cellular.status"}, "Modem-specific bands/SMS adapters not certified"},
	{"guest", "Guest Wi-Fi", "network", "wireless", "wifi", []string{}, "Guest VLAN/firewall isolation provisioning is not implemented"},
}

func luciModulesListV17() map[string]interface{} {
	modules := make([]luciModuleV17, 0, len(luciModulesV17))
	for _, m := range luciModulesV17 {
		present := true
		if m.pkg != "" {
			if _, err := os.Stat("/etc/config/" + m.pkg); err != nil {
				// Not all LuCI extensions use a UCI file called after the module.
				present = false
			}
		}
		state := "unavailable"
		if present {
			if len(m.actions) == 0 {
				state = "not_implemented"
			} else {
				state = "read_only"
				for _, action := range m.actions {
					switch action {
					case "luci.system.hostname.set", "luci.dhcp.static.add", "luci.dhcp.static.delete", "luci.wifi.iface.toggle",
						"wifi.ssid.safe_apply", "wifi.channel.safe_apply", "firewall.wan_guard.set", "firewall.dns53_guard.set",
						"clients.internet.block", "led.brightness.set", "led.rgb.set", "openvpn.profile.toggle", "openvpn.service.control",
						"passwall2.restart", "pbr.policy.add", "pbr.policy.delete", "sqm.queue.set",
						"system.service.restart", "system.backup.create", "terminal.exec":
						state = "partial"
					}
				}
			}
		}
		modules = append(modules, luciModuleV17{m.id, m.title, m.group, m.pkg, state, m.detail, m.page, append([]string{}, m.actions...)})
	}
	return map[string]interface{}{"modules": modules, "source": "local OpenWrt Agent + UCI package presence", "complete_luci_parity": false}
}
func luciSystemInfoV17() (map[string]interface{}, error) {
	cfg, e := loadUCIConfig("system")
	if e != nil {
		return nil, e
	}
	info := map[string]interface{}{"hostname": "", "timezone": "", "zonename": ""}
	for section, typ := range cfg.SectionType {
		if typ != "system" {
			continue
		}
		options := cfg.Options[section]
		info["hostname"] = options["hostname"]
		info["timezone"] = options["timezone"]
		info["zonename"] = options["zonename"]
		break
	}
	return info, nil
}
func luciHostnameSetV17(params map[string]interface{}) (map[string]interface{}, error) {
	value, e := stringParam(params, "hostname")
	if e != nil || !hostnameLuciV17.MatchString(value) || strings.HasSuffix(value, "-") {
		return nil, errors.New("invalid hostname: one DNS label required")
	}
	cfg, e := loadUCIConfig("system")
	if e != nil {
		return nil, e
	}
	section := ""
	for k, typ := range cfg.SectionType {
		if typ == "system" && leaseNameLuciV17.MatchString(k) {
			section = k
			break
		}
	}
	if section == "" {
		return nil, errors.New("named system UCI section not found; refusing anonymous section editing")
	}
	if cfg.Options[section]["hostname"] == value {
		return map[string]interface{}{"changed": false}, nil
	}
	return configSafeApplyV16("system", [][]string{{"set", "system." + section + ".hostname=" + value}})
}
func luciStaticDHCPListV17() (map[string]interface{}, error) {
	cfg, e := loadUCIConfig("dhcp")
	if e != nil {
		return nil, e
	}
	rows := []map[string]interface{}{}
	for name, typ := range cfg.SectionType {
		if typ != "host" {
			continue
		}
		entry := cfg.Options[name]
		// Only display fields needed for the static lease UI (never client ID/key).
		rows = append(rows, map[string]interface{}{"section": name, "name": entry["name"], "mac": entry["mac"], "ip": entry["ip"], "managed_by_pw": strings.HasPrefix(name, "pw_")})
	}
	sort.Slice(rows, func(i, j int) bool { return fmt.Sprint(rows[i]["name"]) < fmt.Sprint(rows[j]["name"]) })
	return map[string]interface{}{"leases": rows, "count": len(rows)}, nil
}
func luciLeaseIPIsLANV17(ip net.IP) bool {
	if ip == nil || ip.To4() == nil || !ip.IsPrivate() {
		return false
	}
	cfg, e := loadUCIConfig("network")
	if e != nil {
		return false
	}
	lan := cfg.Options["lan"]
	if cfg.SectionType["lan"] != "interface" {
		return false
	}
	lanIP := net.ParseIP(lan["ipaddr"]).To4()
	maskIP := net.ParseIP(lan["netmask"]).To4()
	if lanIP == nil || maskIP == nil {
		return false
	}
	mask := net.IPv4Mask(maskIP[0], maskIP[1], maskIP[2], maskIP[3])
	netw := net.IPNet{IP: lanIP.Mask(mask), Mask: mask}
	return netw.Contains(ip) && !lanIP.Equal(ip) && !ip.Equal(netw.IP)
}
func luciMACValidV17(raw string) (string, error) {
	mac, e := net.ParseMAC(raw)
	if e != nil || len(mac) != 6 || mac[0]&1 != 0 || mac.String() == "00:00:00:00:00:00" {
		return "", errors.New("invalid unicast MAC")
	}
	return strings.ToLower(mac.String()), nil
}
func luciDHCPAddV17(params map[string]interface{}) (map[string]interface{}, error) {
	macInput, e := stringParam(params, "mac")
	if e != nil {
		return nil, e
	}
	mac, e := luciMACValidV17(macInput)
	if e != nil {
		return nil, e
	}
	ipInput, e := stringParam(params, "ip")
	if e != nil {
		return nil, e
	}
	ip := net.ParseIP(ipInput)
	if !luciLeaseIPIsLANV17(ip) {
		return nil, errors.New("static lease IPv4 must belong to existing LAN subnet and not the router IP")
	}
	name, e := stringParam(params, "name")
	if e != nil || !hostnameLuciV17.MatchString(name) || strings.HasSuffix(name, "-") {
		return nil, errors.New("invalid lease hostname")
	}
	id := "pw_" + strings.ReplaceAll(mac, ":", "")
	cfg, e := loadUCIConfig("dhcp")
	if e != nil {
		return nil, e
	}
	if cfg.SectionType[id] != "" {
		return nil, errors.New("PW static lease for MAC already exists")
	}
	for _, entry := range cfg.Options {
		if strings.EqualFold(entry["mac"], mac) || entry["ip"] == ip.String() {
			return nil, errors.New("MAC or IP already reserved")
		}
	}
	return configSafeApplyV16("dhcp", [][]string{
		{"set", "dhcp." + id + "=host"},
		{"set", "dhcp." + id + ".name=" + name},
		{"set", "dhcp." + id + ".mac=" + mac},
		{"set", "dhcp." + id + ".ip=" + ip.String()},
	})
}
func luciDHCPDeleteV17(params map[string]interface{}) (map[string]interface{}, error) {
	macInput, e := stringParam(params, "mac")
	if e != nil {
		return nil, e
	}
	mac, e := luciMACValidV17(macInput)
	if e != nil {
		return nil, e
	}
	id := "pw_" + strings.ReplaceAll(mac, ":", "")
	cfg, e := loadUCIConfig("dhcp")
	if e != nil {
		return nil, e
	}
	if cfg.SectionType[id] != "host" || cfg.Options[id]["mac"] != mac {
		return nil, errors.New("PW-managed lease not found; external reservations are protected")
	}
	return configSafeApplyV16("dhcp", [][]string{{"delete", "dhcp." + id}})
}
func luciWifiInterfaceToggleV17(params map[string]interface{}) (map[string]interface{}, error) {
	section, e := stringParam(params, "section")
	if e != nil || !configSectionV16.MatchString(section) {
		return nil, errors.New("invalid Wi-Fi interface section")
	}
	enable, ok := params["enabled"].(bool)
	if !ok {
		return nil, errors.New("enabled must be boolean")
	}
	cfg, e := loadUCIConfig("wireless")
	if e != nil {
		return nil, e
	}
	if cfg.SectionType[section] != "wifi-iface" {
		return nil, errors.New("named wifi-iface was not found")
	}
	disabled := "1"
	if enable {
		disabled = "0"
	}
	return configSafeApplyV16("wireless", [][]string{{"set", "wireless." + section + ".disabled=" + disabled}})
}
