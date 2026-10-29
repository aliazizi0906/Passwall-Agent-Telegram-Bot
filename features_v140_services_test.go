package main

import "testing"

// Only numeric VLAN IDs 1..4094 are included in read-only inventory.
func TestParseVLANIDV14(t *testing.T) {
	allowed := map[string]int{"1": 1, "4094": 4094, "123": 123}
	for text, want := range allowed {
		if got := parseVLANIDV14(text); got != want {
			t.Fatalf("valid VLAN %q: got %d", text, got)
		}
	}
	for _, input := range []string{"", "0", "4095", "-1", "abc", "1;reboot", "12\n3", "3 4"} {
		if got := parseVLANIDV14(input); got != 0 {
			t.Errorf("invalid VLAN %q accepted as %d", input, got)
		}
	}
}
