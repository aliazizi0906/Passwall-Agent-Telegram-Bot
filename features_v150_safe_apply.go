package main

// Router-local, fail-closed OpenWrt Wi-Fi safe apply.
//
// The signed Agent writes a durable, root-only backup before touching UCI,
// starts a detached local timer, and requires an explicit authenticated remote
// confirmation within 90 seconds. A reboot or pw-agent restart invokes
// --pw-safe-recover and restores any unconfirmed change, even if the backend
// is unavailable. The timer does not depend on PHP cron or Telegram.
//
// Only SSID of a named wifi-iface is covered by this implementation. WAN,
// firewall, VPN routes and client traffic controls are NOT safe-apply certified.

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"
)

const wifiSafeApplySecondsV15 = 90
const wifiSafeApplyRootV15 = "/etc/pw-agent/safety"
const wifiSafeApplyConfigV15 = "/etc/config/wireless"

type wifiSafeTransactionV15 struct {
	ID        string `json:"id"`
	ExpiresAt int64  `json:"expires_at"`
	Section   string `json:"section"`
}

func wifiSafeDirV15(root string) string { return filepath.Join(root, "wifi-pending") }

func safeLockV15(root string, action func() error) error {
	if err := os.MkdirAll(root, 0700); err != nil {
		return err
	}
	file, err := os.OpenFile(filepath.Join(root, "wifi.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return err
	}
	defer file.Close()
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX); err != nil {
		return err
	}
	defer syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
	return action()
}
func durableWriteV15(path string, content []byte) error {
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return err
	}
	if _, err = f.Write(content); err != nil {
		f.Close()
		return err
	}
	if err = f.Sync(); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}
func beginWiFiSafeAtV15(root, wireless, id, section string, timeout time.Duration) (wifiSafeTransactionV15, error) {
	m := wifiSafeTransactionV15{ID: id, Section: section, ExpiresAt: time.Now().Add(timeout).Unix()}
	if len(id) != 32 || !validSafeHexV15(id) || !safeID.MatchString(section) || len(section) > 64 {
		return m, errors.New("invalid Wi-Fi safe apply transaction")
	}
	orig, err := os.ReadFile(wireless)
	if err != nil || len(orig) == 0 || len(orig) > 2<<20 {
		return m, errors.New("Wi-Fi UCI backup is unavailable or oversized")
	}
	path := wifiSafeDirV15(root)
	if err = os.Mkdir(path, 0700); err != nil {
		return m, fmt.Errorf("unconfirmed Wi-Fi transaction already exists: %w", err)
	}
	defer func() {
		if err != nil {
			_ = os.RemoveAll(path)
		}
	}()
	if err = durableWriteV15(filepath.Join(path, "wireless.original"), orig); err != nil {
		return m, err
	}
	raw, _ := json.Marshal(m)
	if err = durableWriteV15(filepath.Join(path, "transaction.json"), raw); err != nil {
		return m, err
	}
	if folder, e := os.Open(path); e == nil {
		_ = folder.Sync()
		_ = folder.Close()
	}
	if folder, e := os.Open(root); e == nil {
		_ = folder.Sync()
		_ = folder.Close()
	}
	return m, nil
}
func validSafeHexV15(id string) bool {
	for _, r := range id {
		if !((r >= '0' && r <= '9') || (r >= 'a' && r <= 'f')) {
			return false
		}
	}
	return true
}
func pendingWifiV15(root string) (wifiSafeTransactionV15, error) {
	var tx wifiSafeTransactionV15
	raw, err := os.ReadFile(filepath.Join(wifiSafeDirV15(root), "transaction.json"))
	if err != nil {
		return tx, err
	}
	if err = json.Unmarshal(raw, &tx); err != nil {
		return tx, err
	}
	if len(tx.ID) != 32 || !validSafeHexV15(tx.ID) {
		return tx, errors.New("corrupt Wi-Fi transaction marker")
	}
	return tx, nil
}
func restoreWifiSafeAtV15(root, wireless string, reload func() error) error {
	path := wifiSafeDirV15(root)
	data, err := os.ReadFile(filepath.Join(path, "wireless.original"))
	if err != nil || len(data) == 0 || len(data) > 2<<20 {
		return errors.New("Wi-Fi backup unavailable; manual recovery required")
	}
	// Discard any uncommitted UCI delta left by a failed uci set/commit.
	// Without this, a later wifi reload could reapply the unsafe SSID.
	if uci := commandPath("uci"); uci != "" {
		_, _ = runOutput(8*time.Second, uci, "revert", "wireless")
	}
	temp := wireless + ".pw-safe-restore"
	if err := os.WriteFile(temp, data, 0600); err != nil {
		return err
	}
	if file, e := os.OpenFile(temp, os.O_RDONLY, 0); e == nil {
		_ = file.Sync()
		_ = file.Close()
	}
	if err = os.Rename(temp, wireless); err != nil {
		_ = os.Remove(temp)
		return err
	}
	if err = reload(); err != nil {
		return fmt.Errorf("Wi-Fi config restored but service reload failed: %w", err)
	}
	return os.RemoveAll(path)
}
func runWifiReloadV15() error {
	path := commandPath("wifi")
	if path == "" {
		return errors.New("OpenWrt wifi command is unavailable")
	}
	_, err := runOutput(40*time.Second, path, "reload")
	return err
}
func rollbackWiFiOnBootV15() error {
	return safeLockV15(wifiSafeApplyRootV15, func() error {
		if _, err := os.Stat(wifiSafeDirV15(wifiSafeApplyRootV15)); os.IsNotExist(err) {
			return nil
		}
		fmt.Fprintln(os.Stderr, "PW Agent: recovering unconfirmed Wi-Fi changes after restart/reboot")
		return restoreWifiSafeAtV15(wifiSafeApplyRootV15, wifiSafeApplyConfigV15, runWifiReloadV15)
	})
}
func wifiSafeWatchdogV15(id string) error {
	if len(id) != 32 || !validSafeHexV15(id) {
		return errors.New("invalid Wi-Fi transaction ID")
	}
	for {
		var delay time.Duration
		err := safeLockV15(wifiSafeApplyRootV15, func() error {
			tx, e := pendingWifiV15(wifiSafeApplyRootV15)
			if os.IsNotExist(e) {
				return nil
			}
			if e != nil {
				return e
			}
			if tx.ID != id {
				return nil
			}
			if now := time.Now().Unix(); now < tx.ExpiresAt {
				delay = time.Duration(tx.ExpiresAt-now) * time.Second
				if delay > 5*time.Second {
					delay = 5 * time.Second
				}
				return nil
			}
			fmt.Fprintln(os.Stderr, "PW Agent: Wi-Fi confirmation deadline exceeded; restoring previous settings")
			return restoreWifiSafeAtV15(wifiSafeApplyRootV15, wifiSafeApplyConfigV15, runWifiReloadV15)
		})
		if err != nil {
			return err
		}
		if delay == 0 {
			return nil
		}
		time.Sleep(delay)
	}
}
func launchWifiWatchdogV15(id string) error {
	path, err := os.Executable()
	if err != nil {
		return err
	}
	cmd := exec.Command(path, "--pw-safe-watchdog", id)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	// Never expose agent secrets or the cPanel provisioning token in child args.
	cmd.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin"}
	null, err := os.OpenFile("/dev/null", os.O_WRONLY, 0)
	if err != nil {
		return err
	}
	defer null.Close()
	cmd.Stdin = null
	cmd.Stdout = null
	cmd.Stderr = null
	if err := cmd.Start(); err != nil {
		return err
	}
	// Reap the detached watchdog without waiting for it to finish here.
	go func() { _ = cmd.Wait() }()
	return nil
}
func safeApplySSIDV15(params map[string]interface{}) (map[string]interface{}, error) {
	section, e1 := stringParam(params, "section")
	ssid, e2 := stringParam(params, "ssid")
	if e1 != nil {
		return nil, e1
	}
	if e2 != nil {
		return nil, e2
	}
	if err := validateSSIDPlanV14(section, ssid); err != nil {
		return nil, err
	}
	cfg, err := loadUCIConfig("wireless")
	if err != nil {
		return nil, err
	}
	if cfg.SectionType[section] != "wifi-iface" {
		return nil, errors.New("named UCI section is not wifi-iface")
	}
	if cfg.Options[section]["ssid"] == ssid {
		return map[string]interface{}{"changed": false, "pending": false, "message": "SSID already matches desired value"}, nil
	}
	nonce := make([]byte, 16)
	if _, err := rand.Read(nonce); err != nil {
		return nil, err
	}
	id := hex.EncodeToString(nonce)
	var result map[string]interface{}
	err = safeLockV15(wifiSafeApplyRootV15, func() error {
		tx, e := beginWiFiSafeAtV15(wifiSafeApplyRootV15, wifiSafeApplyConfigV15, id, section, wifiSafeApplySecondsV15*time.Second)
		if e != nil {
			return e
		}
		// The independent watchdog must start BEFORE any UCI write.
		if e = launchWifiWatchdogV15(tx.ID); e != nil {
			_ = os.RemoveAll(wifiSafeDirV15(wifiSafeApplyRootV15))
			return e
		}
		uci := commandPath("uci")
		if uci == "" {
			_ = os.RemoveAll(wifiSafeDirV15(wifiSafeApplyRootV15))
			return errors.New("uci unavailable")
		}
		if _, e = runOutput(10*time.Second, uci, "set", "wireless."+section+".ssid="+ssid); e == nil {
			_, e = runOutput(10*time.Second, uci, "commit", "wireless")
		}
		if e == nil {
			e = runWifiReloadV15()
		}
		if e != nil {
			if restoreErr := restoreWifiSafeAtV15(wifiSafeApplyRootV15, wifiSafeApplyConfigV15, runWifiReloadV15); restoreErr != nil {
				return fmt.Errorf("Wi-Fi apply error: %v; rollback failed: %w", e, restoreErr)
			}
			return fmt.Errorf("Wi-Fi apply failed, original settings restored: %w", e)
		}
		result = map[string]interface{}{
			"changed": true, "pending": true, "transaction_id": tx.ID,
			"expires_at": tx.ExpiresAt, "confirm_within_seconds": wifiSafeApplySecondsV15,
			"message": "Verify connectivity, then click Confirm. Otherwise this router independently restores the original SSID.",
		}
		return nil
	})
	return result, err
}
func confirmSSIDV15(params map[string]interface{}) (map[string]interface{}, error) {
	id, err := stringParam(params, "transaction_id")
	if err != nil || len(id) != 32 || !validSafeHexV15(id) {
		return nil, errors.New("invalid confirmation ID")
	}
	err = safeLockV15(wifiSafeApplyRootV15, func() error {
		tx, e := pendingWifiV15(wifiSafeApplyRootV15)
		if e != nil {
			return fmt.Errorf("no pending Wi-Fi transaction: %w", e)
		}
		if tx.ID != id {
			return errors.New("incorrect Wi-Fi transaction ID")
		}
		if time.Now().Unix() >= tx.ExpiresAt-5 {
			return errors.New("confirmation deadline too close; allow rollback and retry")
		}
		return os.RemoveAll(wifiSafeDirV15(wifiSafeApplyRootV15))
	})
	if err != nil {
		return nil, err
	}
	return map[string]interface{}{"confirmed": true, "transaction_id": id, "rollback_cancelled": true}, nil
}

// Called before normal Agent startup, including on boot, via procd init script.
func safeApplyCLIIfRequestedV15() bool {
	if len(os.Args) < 2 {
		return false
	}
	switch os.Args[1] {
	case "--pw-safe-recover":
		if err := rollbackWiFiOnBootV15(); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return true
	case "--pw-safe-watchdog":
		if len(os.Args) != 3 {
			fmt.Fprintln(os.Stderr, "missing transaction ID")
			os.Exit(1)
		}
		if err := wifiSafeWatchdogV15(os.Args[2]); err != nil {
			fmt.Fprintln(os.Stderr, err)
			os.Exit(1)
		}
		return true
	}
	return false
}
