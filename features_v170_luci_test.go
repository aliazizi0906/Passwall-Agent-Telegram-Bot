package main

import "testing"

func TestLuCIModuleCatalogV17(t *testing.T) {
	out := luciModulesListV17()
	if out["complete_luci_parity"] != false {
		t.Fatal("Never claim complete LuCI parity")
	}
	modules, ok := out["modules"].([]luciModuleV17)
	if !ok || len(modules) < 24 {
		t.Fatal("LuCI inventory missing primary modules")
	}
	seen := map[string]bool{}
	for _, m := range modules {
		if m.ID == "" || m.Title == "" || seen[m.ID] {
			t.Fatalf("duplicate or invalid module: %v", m.ID)
		}
		seen[m.ID] = true
		switch m.State {
		case "partial", "read_only", "not_implemented", "unavailable":
		default:
			t.Fatalf("invalid state %s", m.State)
		}
	}
	for _, id := range []string{"wifi", "dhcp", "firewall", "system", "terminal", "openvpn", "pbr", "sqm", "mounts", "guest", "ddns", "statistics"} {
		if !seen[id] {
			t.Errorf("missing module %s", id)
		}
	}
}
func TestLuCIInputGuardsV17(t *testing.T) {
	for _, h := range []string{"../etc/passwd", "bad;reboot", "a.b", "bad-name-", ""} {
		if _, err := luciHostnameSetV17(map[string]interface{}{"hostname": h}); err == nil {
			t.Errorf("accepted unsafe hostname %q", h)
		}
	}
	for _, mac := range []string{"01:00:5e:00:00:fb", "ff:ff:ff:ff:ff:ff", "00:00:00:00:00:00", "something;reboot"} {
		if _, e := luciMACValidV17(mac); e == nil {
			t.Errorf("accepted unsafe MAC %q", mac)
		}
	}
	if _, e := luciDHCPAddV17(map[string]interface{}{"mac": "bad", "ip": "192.168.1.2", "name": "printer"}); e == nil {
		t.Fatal("accepted invalid DHCP MAC")
	}
	if _, e := luciDHCPDeleteV17(map[string]interface{}{"mac": "01:00:5e:00:00:fb"}); e == nil {
		t.Fatal("accepted multicast static lease deletion")
	}
	if _, e := luciWifiInterfaceToggleV17(map[string]interface{}{"section": "radio0;reboot", "enabled": true}); e == nil {
		t.Fatal("accepted injection wifi section")
	}
	if _, e := luciWifiInterfaceToggleV17(map[string]interface{}{"section": "default_radio0", "enabled": "true"}); e == nil {
		t.Fatal("accepted untyped wifi state")
	}
}
