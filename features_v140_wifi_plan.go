package main

import (
	"fmt"
	"strings"
	"unicode"
	"unicode/utf8"
)

func validateSSIDPlanV14(section, ssid string) error {
	if !safeID.MatchString(section) || len(section) > 64 {
		return fmt.Errorf("use a named UCI wifi-iface section")
	}
	if !utf8.ValidString(ssid) || len([]byte(ssid)) < 1 || len([]byte(ssid)) > 32 || strings.TrimSpace(ssid) == "" {
		return fmt.Errorf("SSID must be valid UTF-8 and 1-32 bytes")
	}
	for _, r := range ssid {
		if unicode.IsControl(r) {
			return fmt.Errorf("SSID contains a control character")
		}
	}
	return nil
}

// Strictly read-only preview. Actual UCI write/reload stays disabled until a
// crash/reboot-safe local watchdog and timed connectivity rollback are tested.
func planSSIDV14(params map[string]interface{}) (map[string]interface{}, error) {
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
		return nil, fmt.Errorf("wireless configuration is unavailable: %w", err)
	}
	if cfg.SectionType[section] != "wifi-iface" {
		return nil, fmt.Errorf("the selected section is not a Wi-Fi network")
	}
	current := cfg.Options[section]["ssid"]
	return map[string]interface{}{
		"dry_run": true,
		"package": "wireless",
		"section": section,
		"changes": []map[string]string{
			{"option": "ssid", "before": current, "after": ssid},
		},
		"changed": current != ssid,
		"apply_available": false,
		"warning": "Preview only; no changes applied. Network writes need a timed local rollback and connectivity verification.",
	}, nil
}
