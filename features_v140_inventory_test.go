package main

import (
	"reflect"
	"testing"
)

func TestModemSignalSanitizerV14(t *testing.T) {
	raw := "imei: 352099000000000\nimsi: 202000000000000\nlte rsrp: -104.25 dBm\nrsrq: -11.5 dB\nsnr: 8.2 dB\napn: private.carrier"
	got := signalValuesV14(raw)
	want := map[string]float64{"rsrp": -104.25, "rsrq": -11.5, "snr": 8.2}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("signal whitelist mismatch: got=%v", got)
	}
	if _, ok := got["imei"]; ok {
		t.Fatal("subscriber identity leaked")
	}
}
func TestModemSignalRejectsOutliersV14(t *testing.T) {
	got := signalValuesV14("rsrp: -200\nrssi: 1000\nsnr: NaN\nrsrq: -12")
	if len(got) != 1 || got["rsrq"] != -12 {
		t.Fatalf("out-of-range signals were accepted: %v", got)
	}
}
func TestModemIndexSanitizerV14(t *testing.T) {
	raw := "/org/freedesktop/ModemManager1/Modem/0 [Qualcomm]\n/org/freedesktop/ModemManager1/Modem/9"
	got := modemIndexV14.FindAllStringSubmatch(raw, 4)
	if len(got) != 2 || got[0][1] != "0" || got[1][1] != "9" {
		t.Fatalf("modem ID parser: %#v", got)
	}
}
func TestNetworkInventoryBoundsV14(t *testing.T) {
	if boundPositiveV14("109", 100) != 0 || boundPositiveV14("-1", 100) != 0 || boundPositiveV14("80", 100) != 80 {
		t.Fatal("numeric policy bounds")
	}
	if boundSafeV14("eth0", 32) != "eth0" || boundSafeV14("a\nb", 32) != "" || boundSafeV14("abcdefgh", 4) != "" {
		t.Fatal("unsafe interface or qdisc values")
	}
}
