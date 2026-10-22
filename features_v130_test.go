// Hardware capabilities must never be reported unless verified at runtime.
package main

import (
	"context"
	"reflect"
	"testing"
)

func TestCapabilityReportNoUnverifiedHardware(t *testing.T) {
	report := capabilityReportV13()
	caps, ok := report["capabilities"].(map[string]interface{})
	if !ok {
		t.Fatal("missing capabilities object")
	}
	for _, name := range []string{"cellular", "cell_lock", "sms", "led_rgb"} {
		if value, exists := caps[name]; !exists || value != false {
			t.Fatalf("%s must remain unsupported without a verified adapter; got %v", name, value)
		}
	}
	if _, ok := caps["wifi_radios"].([]string); !ok {
		t.Fatal("wifi_radios must be a string array")
	}
}

func TestDoctorStatus(t *testing.T) {
	if got := doctorStatusV13(true); got != "pass" {
		t.Fatalf("pass: %s", got)
	}
	if got := doctorStatusV13(false); got != "fail" {
		t.Fatalf("fail: %s", got)
	}
}

func TestUniqueRadios(t *testing.T) {
	got := uniqueRadiosV13([]string{"2g", "2g", "5g", "6g", "6g"})
	if !reflect.DeepEqual(got, []string{"2g", "5g", "6g"}) {
		t.Fatalf("bad radios: %#v", got)
	}
}

func TestSanitizeNetworkErrors(t *testing.T) {
	if got := sanitizedProbeErrorV13(context.DeadlineExceeded); got != "Timed out" {
		t.Fatalf("got %s", got)
	}
	if got := sanitizedProbeErrorV13(nil); got != "Reachable" {
		t.Fatalf("got %s", got)
	}
}

func TestInterfaceNameSanitization(t *testing.T) {
	for _, bad := range []string{"eth0\nsecrets", "wlan0/test", "rm -rf /", ""} {
		if got := safeIfaceV13(bad); got != "unavailable" {
			t.Errorf("unexpected: %s", got)
		}
	}
	if got := safeIfaceV13("eth0.2"); got != "eth0.2" {
		t.Fatalf("got %s", got)
	}
}
