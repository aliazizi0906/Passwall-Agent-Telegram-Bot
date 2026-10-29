package main

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var ledNameV14 = regexp.MustCompile("^[A-Za-z0-9:_+.-]{1,96}$")

// identifyLEDV14 is an opt-in one-second physical LED test, not a persistent
// setting or vendor-specific RGB controller.
func identifyLEDV14(params map[string]interface{}) (map[string]interface{}, error) {
	name, err := stringParam(params, "name")
	if err != nil {
		return nil, err
	}
	if err := identifyLEDAtV14("/sys/class/leds", name, time.Sleep); err != nil {
		return nil, err
	}
	return map[string]interface{}{
		"identified": true, "led": name, "duration_ms": 1200,
		"restored": true, "persistent_change": false,
	}, nil
}

func identifyLEDAtV14(root, name string, pause func(time.Duration)) (err error) {
	if !ledNameV14.MatchString(name) || name == "." || name == ".." {
		return fmt.Errorf("invalid LED name")
	}
	base := filepath.Clean(root)
	directory := filepath.Join(base, name)
	if filepath.Dir(directory) != base {
		return fmt.Errorf("LED path traversal refused")
	}
	brightnessFile := filepath.Join(directory, "brightness")
	triggerFile := filepath.Join(directory, "trigger")
	// Refuse active netdev, phy, heartbeat, or other OS-managed LED triggers.
	trigger, err := os.ReadFile(triggerFile)
	if err != nil || !strings.Contains(string(trigger), "[none]") {
		return fmt.Errorf("LED has an active trigger or cannot safely be identified")
	}
	maxBytes, err := os.ReadFile(filepath.Join(directory, "max_brightness"))
	if err != nil {
		return fmt.Errorf("LED brightness capability unavailable")
	}
	max, err := strconv.Atoi(strings.TrimSpace(string(maxBytes)))
	if err != nil || max < 1 || max > 65535 {
		return fmt.Errorf("LED max brightness invalid")
	}
	beforeBytes, err := os.ReadFile(brightnessFile)
	if err != nil {
		return fmt.Errorf("LED brightness unreadable")
	}
	before, err := strconv.Atoi(strings.TrimSpace(string(beforeBytes)))
	if err != nil || before < 0 || before > max {
		return fmt.Errorf("LED brightness invalid")
	}
	target := max
	if before > 0 {
		target = 0
	}
	if err := os.WriteFile(brightnessFile, []byte(strconv.Itoa(target)), 0600); err != nil {
		return fmt.Errorf("LED does not permit brightness changes")
	}
	defer func() {
		restoreErr := os.WriteFile(brightnessFile, []byte(strconv.Itoa(before)), 0600)
		if restoreErr != nil && err == nil {
			err = fmt.Errorf("LED identification ended but original brightness was not restored")
		}
	}()
	pause(1200 * time.Millisecond)
	return nil
}

// setLEDBrightnessV14 supports only a manually controlled sysfs LED whose
// kernel trigger is already "none". It refuses vendor/OS-managed LEDs.
// It does not disable kernel triggers, change network state or claim RGB support.
func setLEDBrightnessV14(params map[string]interface{}) (map[string]interface{}, error) {
	name, err := stringParam(params, "name")
	if err != nil {
		return nil, err
	}
	raw, ok := params["brightness"].(float64)
	if !ok || raw < 0 || raw != float64(int(raw)) || raw > 65535 {
		return nil, fmt.Errorf("brightness must be a nonnegative integer")
	}
	before, max, err := setLEDBrightnessAtV14("/sys/class/leds", name, int(raw))
	if err != nil {
		return nil, err
	}
	return map[string]interface{}{
		"led": name, "brightness": int(raw), "previous_brightness": before,
		"max_brightness": max, "changed": before != int(raw),
		"note": "Manual sysfs brightness only. No RGB or kernel-trigger control.",
	}, nil
}

func setLEDBrightnessAtV14(root, name string, level int) (before, max int, err error) {
	if !ledNameV14.MatchString(name) || name == "." || name == ".." {
		return 0, 0, fmt.Errorf("invalid LED name")
	}
	base := filepath.Clean(root)
	directory := filepath.Join(base, name)
	if filepath.Dir(directory) != base {
		return 0, 0, fmt.Errorf("LED path traversal refused")
	}
	trigger, err := os.ReadFile(filepath.Join(directory, "trigger"))
	if err != nil || !strings.Contains(string(trigger), "[none]") {
		return 0, 0, fmt.Errorf("LED uses an active driver trigger: manual brightness refused")
	}
	maxRaw, err := os.ReadFile(filepath.Join(directory, "max_brightness"))
	if err != nil {
		return 0, 0, fmt.Errorf("LED max brightness unavailable")
	}
	max, err = strconv.Atoi(strings.TrimSpace(string(maxRaw)))
	if err != nil || max < 1 || max > 65535 || level < 0 || level > max {
		return 0, 0, fmt.Errorf("brightness outside LED supported range")
	}
	current, err := os.ReadFile(filepath.Join(directory, "brightness"))
	if err != nil {
		return 0, 0, fmt.Errorf("LED brightness unreadable")
	}
	before, err = strconv.Atoi(strings.TrimSpace(string(current)))
	if err != nil || before < 0 || before > max {
		return 0, 0, fmt.Errorf("current LED brightness invalid")
	}
	if before == level {
		return before, max, nil
	}
	if err := os.WriteFile(filepath.Join(directory, "brightness"), []byte(strconv.Itoa(level)), 0600); err != nil {
		return before, max, fmt.Errorf("LED brightness update failed")
	}
	return before, max, nil
}
