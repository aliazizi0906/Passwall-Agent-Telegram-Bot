package main

import (
	"testing"
)

func TestConfigPackagesForActionV6(t *testing.T) {
	cases := map[string][]string{
		"wifi.set_ssid":               {"wireless"},
		"firewall.redirect.toggle":    {"firewall"},
		"passwall2.shunt.set_default": {"passwall2"},
		"passwall2.restart":           nil,
		"system.status":               nil,
	}
	for action, want := range cases {
		got := configPackagesForActionV6(action)
		if len(got) != len(want) {
			t.Fatalf("%s: got %#v want %#v", action, got, want)
		}
		for i := range want {
			if got[i] != want[i] {
				t.Fatalf("%s: got %#v want %#v", action, got, want)
			}
		}
	}
}

func TestConfigSnapshotSealRoundTripV6(t *testing.T) {
	a := &Agent{cfg: Config{DeviceSecret: "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"}}
	in := configSnapshotV6{
		Packages: []string{"wireless", "passwall2"},
		Files: map[string]string{
			"wireless":  "Y29uZmlnIHdpcmVsZXNz",
			"passwall2": "Y29uZmlnIHBhc3N3YWxsMg==",
		},
	}
	sealed, err := a.sealConfigSnapshotV6(in)
	if err != nil {
		t.Fatal(err)
	}
	out, err := a.openConfigSnapshotV6(sealed)
	if err != nil {
		t.Fatal(err)
	}
	if len(out.Packages) != 2 || out.Packages[0] != "wireless" || out.Files["passwall2"] != in.Files["passwall2"] {
		t.Fatalf("round-trip mismatch: %#v", out)
	}
}

func TestConfigSnapshotRejectsWrongDeviceSecretV6(t *testing.T) {
	a := &Agent{cfg: Config{DeviceSecret: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}
	b := &Agent{cfg: Config{DeviceSecret: "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}}
	sealed, err := a.sealConfigSnapshotV6(configSnapshotV6{Packages: []string{"wireless"}, Files: map[string]string{"wireless": "YWJj"}})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := b.openConfigSnapshotV6(sealed); err == nil {
		t.Fatal("snapshot encrypted for another device secret was accepted")
	}
}

func TestDiffOptionHashesV6DoesNotExposeValues(t *testing.T) {
	before := map[string]map[string]string{
		"wireless": {
			"wireless.radio0.channel": "oldhash",
			"wireless.default_radio0.key": "secret-old-hash",
		},
	}
	after := map[string]map[string]string{
		"wireless": {
			"wireless.radio0.channel": "newhash",
			"wireless.default_radio0.key": "secret-new-hash",
			"wireless.default_radio0.ssid": "addedhash",
		},
	}
	diff := diffOptionHashesV6(before, after)
	joined := ""
	for _, x := range diff {
		joined += x + "
"
	}
	for _, want := range []string{"changed: wireless.radio0.channel", "changed: wireless.default_radio0.key", "added: wireless.default_radio0.ssid"} {
		if !containsV6(joined, want) {
			t.Fatalf("missing %q in %q", want, joined)
		}
	}
	if containsV6(joined, "secret-old-hash") || containsV6(joined, "secret-new-hash") {
		t.Fatal("config diff exposed hashed values instead of key names only")
	}
}

func containsV6(s, sub string) bool {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return true
		}
	}
	return false
}
