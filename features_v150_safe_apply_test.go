package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestWifiSafeApplyV15RestoresOriginalAndClearsLease(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "safety")
	if err := os.Mkdir(dir, 0700); err != nil {
		t.Fatal(err)
	}
	conf := filepath.Join(root, "wireless")
	original := []byte("config wifi-iface 'default_radio0'\n option ssid 'Before'\n")
	if err := os.WriteFile(conf, original, 0600); err != nil {
		t.Fatal(err)
	}
	id := "0123456789abcdef0123456789abcdef"
	tx, err := beginWiFiSafeAtV15(dir, conf, id, "default_radio0", 90*time.Second)
	if err != nil || tx.ID != id {
		t.Fatalf("begin: %v %v", tx, err)
	}
	marker, e := pendingWifiV15(dir)
	if e != nil || marker.ExpiresAt <= time.Now().Unix() {
		t.Fatalf("corrupt marker: %v %v", marker, e)
	}
	// Simulate UCI applying changes followed by loss of remote connectivity.
	if err := os.WriteFile(conf, []byte("changed"), 0600); err != nil {
		t.Fatal(err)
	}
	reloaded := false
	if err := restoreWifiSafeAtV15(dir, conf, func() error { reloaded = true; return nil }); err != nil {
		t.Fatal(err)
	}
	restored, _ := os.ReadFile(conf)
	if string(restored) != string(original) || !reloaded {
		t.Fatalf("rollback failed: %s", restored)
	}
	if _, e := os.Stat(wifiSafeDirV15(dir)); !os.IsNotExist(e) {
		t.Fatalf("unconfirmed state retained: %v", e)
	}
}
func TestWifiSafeApplyV15RefusesParallelTransactions(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "safety")
	if err := os.Mkdir(dir, 0700); err != nil {
		t.Fatal(err)
	}
	conf := filepath.Join(root, "wireless")
	if err := os.WriteFile(conf, []byte("before"), 0600); err != nil {
		t.Fatal(err)
	}
	id := "0123456789abcdef0123456789abcdef"
	if _, err := beginWiFiSafeAtV15(dir, conf, id, "default_radio0", time.Minute); err != nil {
		t.Fatal(err)
	}
	if _, err := beginWiFiSafeAtV15(dir, conf, id, "default_radio0", time.Minute); err == nil {
		t.Fatal("parallel mutation permitted")
	}
	if _, err := beginWiFiSafeAtV15(dir, conf, "../BAD", "default_radio0", time.Minute); err == nil {
		t.Fatal("unsafe transaction ID accepted")
	}
}
func TestWifiSafeApplyV15LocksAcrossCalls(t *testing.T) {
	root := t.TempDir()
	calls := 0
	if err := safeLockV15(root, func() error { calls++; return nil }); err != nil {
		t.Fatal(err)
	}
	if err := safeLockV15(root, func() error { calls++; return nil }); err != nil {
		t.Fatal(err)
	}
	if calls != 2 {
		t.Fatal("lock callbacks did not complete")
	}
}
