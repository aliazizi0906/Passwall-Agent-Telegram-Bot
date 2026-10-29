package main

import "testing"

// This is an offline validation test: no UCI state or router configuration is touched.
func TestSSIDPlanValidationV14(t *testing.T) {
	good := []string{"OpenWrt", "شبکه", "Cafe-WiFi", "a"}
	for _, ssid := range good {
		if err := validateSSIDPlanV14("default_radio0", ssid); err != nil {
			t.Errorf("%q rejected: %v", ssid, err)
		}
	}
	bad := []struct {
		section string
		ssid    string
	}{
		{"@wifi-iface[0]", "MyWiFi"},
		{"", "MyWiFi"},
		{"radio0;reboot", "MyWiFi"},
		{"default_radio0", ""},
		{"default_radio0", "   "},
		{"default_radio0", "123456789012345678901234567890123"},
		{"default_radio0", "bad\nname"},
		{"default_radio0", string([]byte{0xff, 0xfe})},
	}
	for _, x := range bad {
		if err := validateSSIDPlanV14(x.section, x.ssid); err == nil {
			t.Errorf("unsafe section or SSID accepted: %#v", x)
		}
	}
}
