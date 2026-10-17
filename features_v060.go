package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

type configSnapshotV6 struct {
	Packages []string          `json:"packages"`
	Files    map[string]string `json:"files"`
}

func configPackagesForActionV6(action string) []string {
	switch {
	case strings.HasPrefix(action, "wifi."):
		if action == "wifi.reload" {
			return nil
		}
		return []string{"wireless"}
	case strings.HasPrefix(action, "firewall."):
		return []string{"firewall"}
	case strings.HasPrefix(action, "passwall2."):
		switch action {
		case "passwall2.restart", "passwall2.failover.configure":
			return nil
		default:
			return []string{"passwall2"}
		}
	default:
		return nil
	}
}

func captureConfigSnapshotV6(packages []string) (configSnapshotV6, map[string]map[string]string, error) {
	snap := configSnapshotV6{Packages: append([]string{}, packages...), Files: map[string]string{}}
	hashes := map[string]map[string]string{}
	for _, pkg := range packages {
		if !regexp.MustCompile(`^[A-Za-z0-9_-]+$`).MatchString(pkg) {
			return snap, nil, errors.New("invalid config package")
		}
		raw, err := os.ReadFile("/etc/config/" + pkg)
		if err != nil {
			return snap, nil, fmt.Errorf("snapshot %s: %w", pkg, err)
		}
		snap.Files[pkg] = base64.StdEncoding.EncodeToString(raw)
		hashes[pkg] = uciOptionHashesV6(pkg)
	}
	return snap, hashes, nil
}

func uciOptionHashesV6(pkg string) map[string]string {
	out := map[string]string{}
	uci := commandPath("uci")
	if uci == "" {
		return out
	}
	raw, err := runOutput(12*time.Second, uci, "show", pkg)
	if err != nil {
		return out
	}
	for _, line := range strings.Split(raw, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		eq := strings.IndexByte(line, '=')
		if eq < 1 {
			continue
		}
		key := line[:eq]
		value := line[eq+1:]
		sum := sha256.Sum256([]byte(value))
		out[key] = hex.EncodeToString(sum[:])
	}
	return out
}

func diffOptionHashesV6(before, after map[string]map[string]string) []string {
	pkgs := map[string]bool{}
	for p := range before {
		pkgs[p] = true
	}
	for p := range after {
		pkgs[p] = true
	}
	diff := []string{}
	for pkg := range pkgs {
		keys := map[string]bool{}
		for k := range before[pkg] {
			keys[k] = true
		}
		for k := range after[pkg] {
			keys[k] = true
		}
		names := make([]string, 0, len(keys))
		for k := range keys {
			names = append(names, k)
		}
		sort.Strings(names)
		for _, k := range names {
			b, bok := before[pkg][k]
			a, aok := after[pkg][k]
			switch {
			case !bok && aok:
				diff = append(diff, "added: "+k)
			case bok && !aok:
				diff = append(diff, "removed: "+k)
			case bok && aok && b != a:
				diff = append(diff, "changed: "+k)
			}
		}
	}
	if len(diff) > 128 {
		diff = append(diff[:128], "… additional changes omitted")
	}
	return diff
}

func configHistoryKeyPathV6() string {
	if p := os.Getenv("PW_AGENT_HISTORY_KEY_PATH"); p != "" {
		return p
	}
	return "/etc/pw-agent/history.key"
}

func configHistoryKeyV6() ([32]byte, error) {
	var key [32]byte
	path := configHistoryKeyPathV6()
	raw, err := os.ReadFile(path)
	if err == nil {
		if len(raw) != len(key) {
			return key, errors.New("invalid config-history key length")
		}
		copy(key[:], raw)
		return key, nil
	}
	if !os.IsNotExist(err) {
		return key, err
	}
	if _, err := io.ReadFull(rand.Reader, key[:]); err != nil {
		return key, err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return key, err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, key[:], 0600); err != nil {
		return key, err
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return key, err
	}
	return key, nil
}

func (a *Agent) sealConfigSnapshotV6(s configSnapshotV6) (string, error) {
	plain, err := json.Marshal(s)
	if err != nil {
		return "", err
	}
	key, err := configHistoryKeyV6()
	if err != nil {
		return "", err
	}
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return "", err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return "", err
	}
	nonce := make([]byte, gcm.NonceSize())
	if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
		return "", err
	}
	ciphertext := gcm.Seal(nil, nonce, plain, []byte("pw-config-history-v1"))
	return base64.StdEncoding.EncodeToString(append(nonce, ciphertext...)), nil
}

func (a *Agent) openConfigSnapshotV6(sealed string) (configSnapshotV6, error) {
	var snap configSnapshotV6
	raw, err := base64.StdEncoding.DecodeString(sealed)
	if err != nil {
		return snap, errors.New("invalid rollback snapshot encoding")
	}
	key, err := configHistoryKeyV6()
	if err != nil {
		return snap, err
	}
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return snap, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return snap, err
	}
	if len(raw) <= gcm.NonceSize() {
		return snap, errors.New("rollback snapshot is truncated")
	}
	plain, err := gcm.Open(nil, raw[:gcm.NonceSize()], raw[gcm.NonceSize():], []byte("pw-config-history-v1"))
	if err != nil {
		return snap, errors.New("rollback snapshot authentication failed")
	}
	if err := json.Unmarshal(plain, &snap); err != nil {
		return snap, errors.New("rollback snapshot JSON is invalid")
	}
	if len(snap.Packages) == 0 || len(snap.Files) == 0 {
		return snap, errors.New("rollback snapshot is empty")
	}
	return snap, nil
}

func (a *Agent) executeWithHistoryV6(c *Command) (interface{}, error) {
	if c.Action == "config.rollback_snapshot" {
		return a.rollbackConfigSnapshotV6(map[string]interface{}(c.Params))
	}
	packages := configPackagesForActionV6(c.Action)
	if len(packages) == 0 || !isMutationActionV5(c.Action) {
		return a.execute(c)
	}
	before, beforeHashes, err := captureConfigSnapshotV6(packages)
	if err != nil {
		return nil, err
	}
	result, execErr := a.execute(c)
	if execErr != nil {
		return result, execErr
	}
	_, afterHashes, err := captureConfigSnapshotV6(packages)
	if err != nil {
		return result, err
	}
	sealed, err := a.sealConfigSnapshotV6(before)
	if err != nil {
		return result, err
	}
	change := map[string]interface{}{
		"packages":      packages,
		"before_sealed": sealed,
		"before_hashes": beforeHashes,
		"after_hashes":  afterHashes,
		"diff":          diffOptionHashesV6(beforeHashes, afterHashes),
	}
	if m, ok := result.(map[string]interface{}); ok {
		m["_v6_change"] = change
		return m, nil
	}
	return map[string]interface{}{"result": result, "_v6_change": change}, nil
}

func (a *Agent) rollbackConfigSnapshotV6(params map[string]interface{}) (map[string]interface{}, error) {
	sealed, err := stringParam(params, "snapshot")
	if err != nil {
		return nil, err
	}
	snap, err := a.openConfigSnapshotV6(sealed)
	if err != nil {
		return nil, err
	}
	restored := []string{}
	for _, pkg := range snap.Packages {
		if !regexp.MustCompile(`^[A-Za-z0-9_-]+$`).MatchString(pkg) {
			return nil, errors.New("invalid rollback package")
		}
		enc, ok := snap.Files[pkg]
		if !ok {
			return nil, fmt.Errorf("rollback snapshot missing %s", pkg)
		}
		raw, err := base64.StdEncoding.DecodeString(enc)
		if err != nil || len(raw) == 0 || len(raw) > 4<<20 {
			return nil, fmt.Errorf("invalid rollback content for %s", pkg)
		}
		_, _ = backupConfigFile(pkg)
		path := "/etc/config/" + pkg
		tmp := path + ".pw-agent-new"
		if err := os.WriteFile(tmp, raw, 0600); err != nil {
			return nil, err
		}
		if err := os.Rename(tmp, path); err != nil {
			_ = os.Remove(tmp)
			return nil, err
		}
		restored = append(restored, pkg)
	}
	for _, pkg := range restored {
		switch pkg {
		case "wireless":
			_, _ = reloadWiFi()
		case "firewall":
			_, _ = runOutput(60*time.Second, "/etc/init.d/firewall", "restart")
		case "network":
			_, _ = runOutput(60*time.Second, "/etc/init.d/network", "reload")
		case "passwall2":
			_ = runService("restart")
		}
	}
	return map[string]interface{}{"rolled_back": true, "packages": restored}, nil
}

func heartbeatNetworkV6() map[string]interface{} {
	iface := defaultRouteInterfaceV6()
	out := map[string]interface{}{"interface": iface, "up": false, "ip": "", "rx_bytes": uint64(0), "tx_bytes": uint64(0)}
	if iface == "" {
		return out
	}
	if raw, err := os.ReadFile(filepath.Join("/sys/class/net", iface, "operstate")); err == nil {
		out["up"] = strings.TrimSpace(string(raw)) == "up"
	}
	out["rx_bytes"] = readUintFile(filepath.Join("/sys/class/net", iface, "statistics/rx_bytes"))
	out["tx_bytes"] = readUintFile(filepath.Join("/sys/class/net", iface, "statistics/tx_bytes"))
	if ni, err := net.InterfaceByName(iface); err == nil {
		if addrs, err := ni.Addrs(); err == nil {
			for _, addr := range addrs {
				host, _, err := net.ParseCIDR(addr.String())
				if err == nil && host != nil && !host.IsLoopback() {
					out["ip"] = host.String()
					if host.To4() != nil {
						break
					}
				}
			}
		}
	}
	return out
}

func defaultRouteInterfaceV6() string {
	raw, err := os.ReadFile("/proc/net/route")
	if err != nil {
		return ""
	}
	lines := strings.Split(string(raw), "\n")
	for _, line := range lines[1:] {
		f := strings.Fields(line)
		if len(f) < 4 || f[1] != "00000000" {
			continue
		}
		flags, _ := strconv.ParseUint(f[3], 16, 32)
		if flags&0x1 != 0 {
			return f[0]
		}
	}
	return ""
}
