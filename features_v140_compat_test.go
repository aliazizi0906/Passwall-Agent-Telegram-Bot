package main

import "testing"

func TestFeatureSupportGenericOpenWrtV14(t *testing.T) {
	m := featureSupportV14(nil, false, false, false, false, false, false, false)
	for _, key := range []string{"wifi", "passwall2", "cellular", "led", "led_rgb", "upnp", "sqm", "wireguard", "openvpn"} {
		if m[key].Status != "not_available" {
			t.Fatalf("unsupported %s advertised as %s", key, m[key].Status)
		}
	}
	if m["system"].Status != "available" || m["fleet"].Status != "available" {
		t.Fatal("Generic OpenWrt core must work without vendor peripherals")
	}
	if m["kill_switch"].Status == "available" || m["network_safe_apply"].Status == "available" {
		t.Fatal("Dangerous unverified writes must not be advertised")
	}
}

func TestFeatureSupportHardwareV14(t *testing.T) {
	m := featureSupportV14([]string{"2g", "5g"}, true, true, true, true, true, true, true)
	if m["wifi"].Status != "available" {
		t.Fatal("WiFi SSID safe apply should be discoverable")
	}
	if m["passwall2"].Status != "available" {
		t.Fatal("Passwall2 discovered but not reported")
	}
	for _, key := range []string{"cellular", "upnp", "wireguard", "led"} {
		if m[key].Status != "read_only" {
			t.Fatalf("Uncertified write for %s reported %s", key, m[key].Status)
		}
	}
	if m["sqm"].Status != "available" { t.Fatal("installed SQM queue controls are available with scoped rollback") }
	if m["openvpn"].Status != "available" || m["openvpn_route_apply"].Status == "available" {
		t.Fatal("OpenVPN service and rule support must be distinct")
	}
	if m["led_rgb"].Status != "not_available" {
		t.Fatal("RGB provider is not evidence of a working RGB controller")
	}
}
