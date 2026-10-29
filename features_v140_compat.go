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
		wifi = supported("Wi-Fi SSID and named radio channel changes use router-local timed rollback. Password/guest provisioning remains disabled pending secret handling.")
	}
	proxy := unavailable("Passwall2 is not installed; generic OpenWrt monitoring remains available.")
	if passwall {
		proxy = supported("Passwall2 adapter detected; only allowlisted legacy operations are enabled.")
	}
	ledStatus := unavailable("No standard sysfs LED interface was detected.")
	if led {
		ledStatus = readonly("Manual sysfs brightness supported; native 3-channel RGB controls require LED inventory rgb_control=true.")
	}
	cellular := unavailable("No recognized cellular command adapter was found.")
	if modemAdapter {
		cellular = readonly("Cellular adapter detected, but a connected modem and signal must be confirmed separately.")
	}
	sqmStatus := unavailable("SQM/tc configuration not detected.")
	if sqm {
		sqmStatus = supported("Existing named SQM queue rate adjustments are available with 120-second local rollback; per-client shaping is not supported.")
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
		"system":              supported("Generic OpenWrt network and Agent diagnostics."),
		"fleet":               supported("Router management lives in the cPanel backend; hardware-only modules are optional."),
		"clients":             readonly("Clients can be discovered. On fw4 routers with lan/wan zones, bounded MAC LAN-to-WAN blocking is available with rollback; per-client speed caps remain unavailable."),
		"wifi":                wifi,
		"passwall2":           proxy,
		"dns":                 readonly("Scoped classic DNS port 53 LAN-to-WAN guard is available on supported Firewall4 routers; router-originated DNS, DoH and DoT are not covered."),
		"sqm":                 sqmStatus,
		"upnp":                upnpStatus,
		"led":                 ledStatus,
		"led_rgb":             unavailable("Native sysfs three-channel RGB writes are supported only when LED inventory reports rgb_control; vendor adapters are not available."),
		"cellular":            cellular,
		"wireguard":           wireguard,
		"openvpn":             openvpn,
		"openvpn_route_apply": planned("Named PBR UCI policies can be created/removed on installed pbr via a real network interface with rollback; leak-proof split tunneling is not certified."),
		"wifi_ssid_safe_apply": func() featureSupportEntryV14 {
			if len(radios) > 0 {
				return supported("Confirmed 90-second router-local SSID rollback.")
			}
			return unavailable("No wireless radio")
		}(),
		"network_safe_apply": planned("Selected pbr, firewall, sqm, wifi and openvpn package changes have local timed rollback; arbitrary WAN and network edits are not supported."),
		"kill_switch":        planned("Optional LAN-to-WAN forwarding guard exists; complete IPv4/IPv6, DNS, DoH/DoT and VPN-failure kill-switch testing remains unavailable."),
	}
}
