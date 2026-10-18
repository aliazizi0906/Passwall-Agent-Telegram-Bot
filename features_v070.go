package main

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// v0.7 deliberately supports a small declarative profile allowlist. Arbitrary
// UCI keys and secret-bearing options are never accepted from the backend.
type profileRuleV7 struct {
	Package string `json:"package"`
	Key     string `json:"key"`
	Value   string `json:"value"`
}

var profileKeyV7 = regexp.MustCompile(`^(wireless|network|firewall)\.[A-Za-z0-9_@.\[\]-]+\.(channel|disabled|country|hwmode|htmode|txpower|metric|peerdns|delegate|ipv6|ip6assign|masq|mtu_fix|input|output|forward)$`)
var secretValueV7 = regexp.MustCompile(`(?i)(password|passwd|secret|token|private.?key|uuid|vless://|vmess://|trojan://|ss://)`)

func validateProfileRulesV7(raw interface{}) ([]profileRuleV7, error) {
	items, ok := raw.([]interface{})
	if !ok || len(items) == 0 || len(items) > 100 {
		return nil, errors.New("profile rules must contain 1 to 100 entries")
	}
	rules := make([]profileRuleV7, 0, len(items))
	seen := map[string]bool{}
	for _, item := range items {
		m, ok := item.(map[string]interface{})
		if !ok {
			return nil, errors.New("invalid profile rule")
		}
		pkg, pkgOK := m["package"].(string)
		key, keyOK := m["key"].(string)
		value, valueOK := m["value"].(string)
		if !pkgOK || !keyOK || !valueOK {
			return nil, errors.New("profile package, key and value must be strings")
		}
		r := profileRuleV7{Package: strings.TrimSpace(pkg), Key: strings.TrimSpace(key), Value: value}
		if !profileKeyV7.MatchString(r.Key) || !strings.HasPrefix(r.Key, r.Package+".") {
			return nil, fmt.Errorf("profile key is outside the safe allowlist: %s", r.Key)
		}
		if len(r.Value) > 128 || strings.ContainsAny(r.Value, "\x00\r\n") || secretValueV7.MatchString(r.Value) {
			return nil, fmt.Errorf("unsafe profile value for %s", r.Key)
		}
		if seen[r.Key] {
			return nil, fmt.Errorf("duplicate profile key: %s", r.Key)
		}
		seen[r.Key] = true
		rules = append(rules, r)
	}
	return rules, nil
}

func profileCheckV7(params map[string]interface{}) (map[string]interface{}, error) {
	if commandPath("uci") == "" {
		return nil, errors.New("uci is unavailable")
	}
	rules, err := validateProfileRulesV7(params["rules"])
	if err != nil {
		return nil, err
	}
	results := make([]map[string]interface{}, 0, len(rules))
	drift := 0
	for _, rule := range rules {
		actual, getErr := uciGet(rule.Key)
		if getErr != nil {
			actual = "__unset__"
		}
		matches := actual == rule.Value
		if !matches {
			drift++
		}
		results = append(results, map[string]interface{}{"package": rule.Package, "key": rule.Key, "expected": rule.Value, "actual": actual, "matches": matches})
	}
	return map[string]interface{}{"ok": true, "rules": results, "drift_count": drift, "checked_at": time.Now().UTC().Format(time.RFC3339)}, nil
}

func profilePackagesV7(raw interface{}) ([]string, error) {
	rules, err := validateProfileRulesV7(raw)
	if err != nil {
		return nil, err
	}
	packages := map[string]bool{}
	for _, rule := range rules {
		packages[rule.Package] = true
	}
	return sortedKeysV7(packages), nil
}

func profileApplyV7(params map[string]interface{}) (map[string]interface{}, error) {
	if commandPath("uci") == "" {
		return nil, errors.New("uci is unavailable")
	}
	rules, err := validateProfileRulesV7(params["rules"])
	if err != nil {
		return nil, err
	}
	packages := map[string]bool{}
	for _, rule := range rules {
		packages[rule.Package] = true
	}
	snapshot, _, err := captureConfigSnapshotV6(sortedKeysV7(packages))
	if err != nil {
		return nil, fmt.Errorf("could not create safety snapshot: %w", err)
	}
	committed := false
	defer func() {
		if !committed {
			_ = restoreProfileSnapshotV7(snapshot)
		}
	}()
	uci := commandPathRequired("uci")
	for _, rule := range rules {
		if rule.Value == "__unset__" {
			if _, err := runOutput(8*time.Second, uci, "-q", "delete", rule.Key); err != nil {
				if _, getErr := uciGet(rule.Key); getErr == nil {
					return nil, fmt.Errorf("could not remove %s: %w", rule.Key, err)
				}
			}
		} else if _, err := runOutput(8*time.Second, uci, "set", rule.Key+"="+rule.Value); err != nil {
			return nil, fmt.Errorf("could not set %s: %w", rule.Key, err)
		}
	}
	for _, pkg := range sortedKeysV7(packages) {
		if _, err := runOutput(12*time.Second, uci, "commit", pkg); err != nil {
			return nil, fmt.Errorf("could not commit %s: %w", pkg, err)
		}
	}
	if packages["wireless"] {
		if wifi := commandPath("wifi"); wifi != "" {
			if _, err := runOutput(30*time.Second, wifi, "reload"); err != nil {
				return nil, fmt.Errorf("wireless reload failed: %w", err)
			}
		}
	}
	if packages["firewall"] {
		if _, err := runOutput(30*time.Second, "/etc/init.d/firewall", "reload"); err != nil {
			return nil, fmt.Errorf("firewall reload failed: %w", err)
		}
	}
	if packages["network"] {
		if _, err := runOutput(60*time.Second, "/etc/init.d/network", "reload"); err != nil {
			return nil, fmt.Errorf("network reload failed: %w", err)
		}
	}
	committed = true
	return map[string]interface{}{"ok": true, "applied_rules": len(rules), "packages": sortedKeysV7(packages), "warning": "Review connectivity after applying a profile."}, nil
}

func restoreProfileSnapshotV7(snapshot configSnapshotV6) error {
	var first error
	uci := commandPathRequired("uci")
	for _, pkg := range snapshot.Packages {
		encoded := snapshot.Files[pkg]
		raw, err := decodeSnapshotFileV7(encoded)
		if err != nil {
			if first == nil {
				first = err
			}
			continue
		}
		path := "/etc/config/" + pkg
		tmp := path + ".pw-agent-restore.tmp"
		if err := os.WriteFile(tmp, raw, 0600); err != nil {
			if first == nil {
				first = err
			}
			continue
		}
		if err := os.Rename(tmp, path); err != nil {
			_ = os.Remove(tmp)
			if first == nil {
				first = err
			}
			continue
		}
		_, _ = runOutput(8*time.Second, uci, "revert", pkg)
	}
	for _, pkg := range snapshot.Packages {
		_, err := runOutput(12*time.Second, uci, "commit", pkg)
		if err != nil && first == nil {
			first = err
		}
	}
	if containsStringV7(snapshot.Packages, "wireless") {
		if wifi := commandPath("wifi"); wifi != "" {
			_, err := runOutput(30*time.Second, wifi, "reload")
			if err != nil && first == nil {
				first = err
			}
		}
	}
	if containsStringV7(snapshot.Packages, "firewall") {
		_, err := runOutput(30*time.Second, "/etc/init.d/firewall", "reload")
		if err != nil && first == nil {
			first = err
		}
	}
	if containsStringV7(snapshot.Packages, "network") {
		_, err := runOutput(60*time.Second, "/etc/init.d/network", "reload")
		if err != nil && first == nil {
			first = err
		}
	}
	return first
}

func decodeSnapshotFileV7(encoded string) ([]byte, error) {
	return base64.StdEncoding.DecodeString(encoded)
}

func containsStringV7(items []string, target string) bool {
	for _, item := range items {
		if item == target {
			return true
		}
	}
	return false
}

func sortedKeysV7(m map[string]bool) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	// The profile allowlist is small; deterministic ordering makes audit output stable.
	for i := 0; i < len(out); i++ {
		for j := i + 1; j < len(out); j++ {
			if out[j] < out[i] {
				out[i], out[j] = out[j], out[i]
			}
		}
	}
	return out
}

func diagnosticsBundleV7(a *Agent) map[string]interface{} {
	out := map[string]interface{}{
		"generated_at": time.Now().UTC().Format(time.RFC3339),
		"agent_version": agentVersion,
		"device_id": a.cfg.DeviceID,
		"hostname": hostname(),
	}
	if status, err := systemStatus(); err == nil {
		out["system"] = status
	} else {
		out["system_error"] = safeDiagnosticTextV7(err.Error())
	}
	if status, err := passwallStatus(); err == nil {
		out["passwall2"] = status
	} else {
		out["passwall_error"] = safeDiagnosticTextV7(err.Error())
	}
	if ifaces, err := networkInterfaces(); err == nil {
		out["interfaces"] = ifaces
	} else {
		out["interfaces_error"] = safeDiagnosticTextV7(err.Error())
	}
	if diag := a.diagnoseV4(); len(diag) > 0 {
		delete(diag, "server_url")
		out["backend_connectivity"] = diag
	}
	checks := map[string]bool{}
	for _, name := range []string{"ubus", "uci", "tar", "wget", "openssl"} {
		checks[name] = commandPath(name) != ""
	}
	out["tools"] = checks
	raw, _ := json.Marshal(out)
	sum := sha256.Sum256(raw)
	out["bundle_sha256"] = hex.EncodeToString(sum[:])
	return out
}

func safeDiagnosticTextV7(s string) string {
	s = secretValueV7.ReplaceAllString(s, "[REDACTED]")
	if len(s) > 1200 {
		s = s[:1200]
	}
	return s
}

func pendingSecretPathV7() string {
	if p := os.Getenv("PW_AGENT_PENDING_SECRET_PATH"); p != "" {
		return p
	}
	return "/etc/pw-agent/secret.pending"
}

func agentConfigPathV7() string {
	if p := os.Getenv("PW_AGENT_CONFIG"); p != "" {
		return p
	}
	return "/etc/pw-agent/config.json"
}

func stageSecretRotationV7(newSecret string) (map[string]interface{}, error) {
	newSecret = strings.ToLower(strings.TrimSpace(newSecret))
	decoded, err := hex.DecodeString(newSecret)
	if err != nil || len(decoded) != 32 {
		return nil, errors.New("new device secret must be 32 random bytes encoded as 64 hex characters")
	}
	path := pendingSecretPathV7()
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return nil, err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, []byte(newSecret+"\n"), 0600); err != nil {
		return nil, err
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return nil, err
	}
	return map[string]interface{}{"staged": true, "message": "secret staged; server acknowledgement is required before activation"}, nil
}

func (a *Agent) commitPendingSecretRotationV7() error {
	path := pendingSecretPathV7()
	rawSecret, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return errors.New("server requested secret rotation but no pending secret exists on this router")
	}
	if err != nil {
		return err
	}
	newSecret := strings.ToLower(strings.TrimSpace(string(rawSecret)))
	decoded, err := hex.DecodeString(newSecret)
	if err != nil || len(decoded) != 32 {
		return errors.New("pending device secret is invalid")
	}
	configPath := agentConfigPathV7()
	rawConfig, err := os.ReadFile(configPath)
	if err != nil {
		return err
	}
	var config map[string]interface{}
	if err := json.Unmarshal(rawConfig, &config); err != nil {
		return errors.New("agent config JSON is invalid; pending secret retained")
	}
	config["device_secret"] = newSecret
	updated, err := json.MarshalIndent(config, "", "  ")
	if err != nil {
		return err
	}
	tmp := configPath + ".rotation.tmp"
	if err := os.WriteFile(tmp, append(updated, '\n'), 0600); err != nil {
		return err
	}
	if err := os.Rename(tmp, configPath); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	a.cfg.DeviceSecret = newSecret
	_ = os.Remove(path)
	return nil
}

func (a *Agent) cleanupCommittedPendingSecretRotationV7() error {
	path := pendingSecretPathV7()
	raw, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return err
	}
	if strings.TrimSpace(string(raw)) != a.cfg.DeviceSecret {
		return nil
	}
	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return err
	}
	return nil
}

func agentUpdateRollbackV7() (map[string]interface{}, error) {
	previous := "/usr/bin/pw-agent.previous"
	info, err := os.Stat(previous)
	if err != nil || !info.Mode().IsRegular() {
		return nil, errors.New("previous Agent binary is not available")
	}
	previousVersion := "unknown"
	if raw, err := os.ReadFile("/etc/pw-agent/previous-version"); err == nil {
		if v := strings.TrimSpace(string(raw)); v != "" && len(v) <= 64 {
			previousVersion = v
		}
	}
	script := `#!/bin/sh
set -eu
PREV=/usr/bin/pw-agent.previous
[ -f "$PREV" ] || exit 1
cp "$PREV" /usr/bin/pw-agent.rollback
chmod 755 /usr/bin/pw-agent.rollback
mv -f /usr/bin/pw-agent.rollback /usr/bin/pw-agent
if [ -f /etc/init.d/pw-agent.previous ]; then
  cp /etc/init.d/pw-agent.previous /etc/init.d/pw-agent
  chmod 755 /etc/init.d/pw-agent
fi
/etc/init.d/pw-agent restart >/dev/null 2>&1 || true
`
	path := "/tmp/pw-agent-rollback-v7.sh"
	if err := os.WriteFile(path, []byte(script), 0700); err != nil {
		return nil, err
	}
	cmd := exec.Command("/bin/sh", "-c", "sleep 4; "+path+" >/tmp/pw-agent-rollback.log 2>&1; rm -f "+path)
	cmd.Stdin = nil
	cmd.Stdout = nil
	cmd.Stderr = nil
	if err := cmd.Start(); err != nil {
		_ = os.Remove(path)
		return nil, err
	}
	return map[string]interface{}{
		"scheduled":        true,
		"previous_version": previousVersion,
		"message":          "Agent rollback scheduled; service will restart automatically",
	}, nil
}
