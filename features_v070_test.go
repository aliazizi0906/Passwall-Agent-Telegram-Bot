package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestValidateProfileRulesV7AllowsSafeRules(t *testing.T) {
	rules, err := validateProfileRulesV7([]interface{}{
		map[string]interface{}{"package": "wireless", "key": "wireless.radio0.channel", "value": "36"},
		map[string]interface{}{"package": "firewall", "key": "firewall.@zone[0].masq", "value": "1"},
	})
	if err != nil || len(rules) != 2 {
		t.Fatalf("expected safe rules to pass, got %#v, %v", rules, err)
	}
}

func TestValidateProfileRulesV7RejectsSecretsAndUnsafeKeys(t *testing.T) {
	cases := []map[string]interface{}{
		{"package": "wireless", "key": "wireless.default_radio0.key", "value": "not-a-real-password"},
		{"package": "network", "key": "network.lan.ipaddr", "value": "192.0.2.1"},
		{"package": "wireless", "key": "wireless.radio0.channel", "value": "vless://example.invalid"},
	}
	for _, rule := range cases {
		if _, err := validateProfileRulesV7([]interface{}{rule}); err == nil {
			t.Fatalf("unsafe rule accepted: %#v", rule)
		}
	}
}

func TestValidateProfileRulesV7RejectsDuplicateKeys(t *testing.T) {
	rule := map[string]interface{}{"package": "wireless", "key": "wireless.radio0.channel", "value": "36"}
	if _, err := validateProfileRulesV7([]interface{}{rule, rule}); err == nil {
		t.Fatal("duplicate profile key accepted")
	}
}

func TestSecretRotationStagingAndCommitV7(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.json")
	pendingPath := filepath.Join(dir, "secret.pending")
	t.Setenv("PW_AGENT_CONFIG", configPath)
	t.Setenv("PW_AGENT_PENDING_SECRET_PATH", pendingPath)
	oldSecret := "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	newSecret := "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
	config := map[string]interface{}{"server_url": "https://example.invalid", "device_id": "test-router", "device_secret": oldSecret, "future_field": "preserved"}
	raw, err := json.Marshal(config)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(configPath, raw, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := stageSecretRotationV7(newSecret); err != nil {
		t.Fatal(err)
	}
	a := &Agent{cfg: Config{DeviceSecret: oldSecret}}
	if err := a.commitPendingSecretRotationV7(); err != nil {
		t.Fatal(err)
	}
	if a.cfg.DeviceSecret != newSecret {
		t.Fatalf("in-memory secret not updated")
	}
	updatedRaw, err := os.ReadFile(configPath)
	if err != nil {
		t.Fatal(err)
	}
	var updated map[string]interface{}
	if err := json.Unmarshal(updatedRaw, &updated); err != nil {
		t.Fatal(err)
	}
	if updated["device_secret"] != newSecret || updated["future_field"] != "preserved" {
		t.Fatalf("config update failed or removed unknown fields: %#v", updated)
	}
	if _, err := os.Stat(pendingPath); !os.IsNotExist(err) {
		t.Fatalf("pending secret should be removed after commit: %v", err)
	}
}

func TestSecretRotationStagingV7RejectsInvalidLength(t *testing.T) {
	t.Setenv("PW_AGENT_PENDING_SECRET_PATH", filepath.Join(t.TempDir(), "pending"))
	if _, err := stageSecretRotationV7("abcd"); err == nil {
		t.Fatal("short device secret accepted")
	}
}
