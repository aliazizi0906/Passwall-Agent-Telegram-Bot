package main

// Hardware support is reported per capability rather than per router model.
// Installation of an optional package is never treated as certification of
// a dangerous configuration write. These states are UI hints, not privileges.
type featureSupportEntryV14 struct {
	Status string `json:"status"`
	Detail string `json:"detail"`
}

func featureSupportV14(radios []string, passwall, led, modemAdapter, sqm, upnp, wg, ovpn bool) map[string]featureSupportEntryV14 {
	supported := func(detail string) featureSupportEntryV14 {
		return featureSupportEntryV14{Status: "available", Detail: detail}
	}
	readonly := func(detail string) featureSupportEntryV14 {
		return featureSupportEntryV14{Status: "read_only", Detail: detail}
	}
	unavailable := func(detail string) featureSupportEntryV14 {
		return featureSupportEntryV14{Status: "not_available", Detail: detail}
	}
	planned := func(detail string) featureSupportEntryV14 {
		return featureSupportEntryV14{Status: "planned", Detail: detail}
	}
	wifi := unavailable("No OpenWrt UCI wireless radio was detected.")
	if len(radios) > 0 {
		wifi = supported("Wi-Fi SSID safe apply and local 90-second rollback are available. Password, channels and WAN/firewall mutations require separate certification.")
	}
	proxy := unavailable("Passwall2 is not installed; generic OpenWrt monitoring remains available.")
	if passwall {
		proxy = supported("Passwall2 adapter detected; only allowlisted legacy operations are enabled.")
	}
	ledStatus := unavailable("No standard sysfs LED interface was detected.")
	if led {
		ledStatus = readonly("LED inventory and identification; manual-brightness writes only on LEDs whose kernel trigger is none.")
	}
	cellular := unavailable("No recognized cellular command adapter was found.")
	if modemAdapter {
		cellular = readonly("Cellular adapter detected, but a connected modem and signal must be confirmed separately.")
	}
	sqmStatus := unavailable("SQM/tc configuration not detected.")
	if sqm {
		sqmStatus = readonly("SQM/tc detected; tuning is disabled until hardware-safe apply exists.")
	}
	upnpStatus := unavailable("miniupnpd is not installed.")
	if upnp {
		upnpStatus = readonly("miniupnpd detected; no automatic NAT mutation is enabled.")
	}
	wireguard := unavailable("WireGuard CLI not detected.")
	if wg {
		wireguard = readonly("WireGuard detected; native provider management not yet enabled.")
	}
	openvpn := unavailable("OpenVPN service not detected.")
	if ovpn {
		openvpn = supported("OpenVPN service start/stop/restart supported, with confirmation. CIDR routing rules are preview-only.")
	}
	return map[string]featureSupportEntryV14{
		"system":             supported("Generic OpenWrt network and Agent diagnostics."),
		"fleet":              supported("Router management lives in the cPanel backend; hardware-only modules are optional."),
		"clients":            readonly("Client discovery is available; blocking/limits require local rollback and verified nftables/tc."),
		"wifi":               wifi,
		"passwall2":          proxy,
		"dns":                readonly("Current DNS and route state only; leak-proof policy writes are not certified."),
		"sqm":                sqmStatus,
		"upnp":               upnpStatus,
		"led":                ledStatus,
		"led_rgb":            unavailable("RGB write driver is not implemented or hardware-certified."),
		"cellular":           cellular,
		"wireguard":          wireguard,
		"openvpn":            openvpn,
		"openvpn_route_apply": planned("Routing rules are preview-only until independent path validation and rollback are certified."),
		"wifi_ssid_safe_apply": func() featureSupportEntryV14 { if len(radios)>0 { return supported("Confirmed 90-second router-local SSID rollback.") };return unavailable("No wireless radio") }(),
		"network_safe_apply": planned("WAN, firewall, DNS and Wi-Fi changes require a router-local timed rollback watchdog."),
		"kill_switch":        planned("No IPv4/IPv6 path-failure certification. Static diagnostics are not a leak test."),
	}
}
