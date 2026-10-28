package main

import (
	"encoding/json"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

var modemIndexV14 = regexp.MustCompile("/Modem/([0-9]{1,5})\\b")
var modemSignalV14 = regexp.MustCompile("(?im)\\b(rsrp|rsrq|rssi|sinr|snr)\\s*:\\s*(-?[0-9]+(?:\\.[0-9]+)?)")

// Whitelist signal strength only: never expose IMEI, IMSI, subscriber or location data.
func signalValuesV14(raw string) map[string]float64 {
	out := map[string]float64{}
	for _, m := range modemSignalV14.FindAllStringSubmatch(raw, 20) {
		value, err := strconv.ParseFloat(m[2], 64)
		if err != nil || math.IsNaN(value) || math.IsInf(value, 0) || value < -180 || value > 100 {
			continue
		}
		out[strings.ToLower(m[1])] = value
	}
	return out
}

func modemProviderV14() string {
	if commandPath("mmcli") != "" {
		return "modemmanager"
	}
	if commandPath("uqmi") != "" {
		if list, _ := filepath.Glob("/dev/cdc-wdm[0-9]*"); len(list) > 0 {
			return "qmi"
		}
	}
	return "none"
}

// Model-independent, read-only modem inventory. Bands, SMS, USSD and tower
// mutations must use dedicated verified hardware-specific providers.
func cellularStatusV14() (map[string]interface{}, error) {
	provider := modemProviderV14()
	status := map[string]interface{}{
		"provider": provider, "available": false, "modem_count": 0,
		"signal": map[string]float64{}, "read_only": true,
		"limitations": "Cell/band locks and SMS/USSD require a verified modem adapter",
	}
	switch provider {
	case "modemmanager":
		raw, err := runOutput(8*time.Second, commandPath("mmcli"), "-L")
		if err != nil {
			status["reason"] = "ModemManager discovery unavailable"
			return status, nil
		}
		matches := modemIndexV14.FindAllStringSubmatch(raw, 4)
		if len(matches) == 0 {
			status["reason"] = "No ModemManager modem detected"
			return status, nil
		}
		status["modem_count"] = len(matches)
		status["available"] = true
		signal, err := runOutput(8*time.Second, commandPath("mmcli"), "-m", matches[0][1], "--signal-get")
		if err == nil {
			status["signal"] = signalValuesV14(signal)
		}
	case "qmi":
		devs, _ := filepath.Glob("/dev/cdc-wdm[0-9]*")
		if len(devs) == 0 {
			status["reason"] = "No QMI device detected"
			return status, nil
		}
		sort.Strings(devs)
		status["modem_count"] = len(devs)
		status["available"] = true
		raw, err := runOutput(8*time.Second, commandPath("uqmi"), "-d", devs[0], "--get-signal-info")
		if err == nil {
			values := map[string]interface{}{}
			if json.Unmarshal([]byte(raw), &values) == nil {
				signal := map[string]float64{}
				for _, key := range []string{"rsrp", "rsrq", "rssi", "snr", "sinr"} {
					if v, ok := values[key].(float64); ok && !math.IsNaN(v) && !math.IsInf(v, 0) && v >= -180 && v <= 100 {
						signal[key] = v
					}
				}
				status["signal"] = signal
			}
		}
	default:
		status["reason"] = "No supported modem manager installed"
	}
	return status, nil
}

func boundPositiveV14(raw string, limit int64) int64 {
	v, err := strconv.ParseInt(raw, 10, 64)
	if err != nil || v < 0 || v > limit {
		return 0
	}
	return v
}
func boundSafeV14(raw string, max int) string {
	if len(raw) > max {
		return ""
	}
	for _, c := range raw {
		if c < 32 || c > 126 {
			return ""
		}
	}
	return raw
}

// SQM and UPnP installation / settings inventory without mutating firewall.
func networkPoliciesV14() (map[string]interface{}, error) {
	result := map[string]interface{}{
		"read_only": true, "sqm_installed": false, "sqm_interfaces": []map[string]interface{}{},
		"upnp_installed": false, "upnp_enabled": false, "upnp_secure_mode": false,
		"note": "Changing SQM/UPnP requires supported firmware and safe rollback",
	}
	if cfg, err := loadUCIConfig("sqm"); err == nil {
		result["sqm_installed"] = true
		interfaces := []map[string]interface{}{}
		ids := []string{}
		for id, kind := range cfg.SectionType {
			if kind == "queue" {
				ids = append(ids, id)
			}
		}
		sort.Strings(ids)
		for _, id := range ids {
			if len(interfaces) >= 24 {
				break
			}
			opt := cfg.Options[id]
			interfaces = append(interfaces, map[string]interface{}{
				"enabled": opt["enabled"] == "1", "interface": boundSafeV14(opt["interface"], 32),
				"download_kbit": boundPositiveV14(opt["download"], 100000000),
				"upload_kbit": boundPositiveV14(opt["upload"], 100000000),
				"qdisc": boundSafeV14(opt["qdisc"], 40),
			})
		}
		result["sqm_interfaces"] = interfaces
	}
	if cfg, err := loadUCIConfig("upnpd"); err == nil {
		result["upnp_installed"] = true
		for id, kind := range cfg.SectionType {
			if kind == "upnpd" {
				opt := cfg.Options[id]
				result["upnp_enabled"] = opt["enabled"] == "1"
				result["upnp_secure_mode"] = opt["secure_mode"] == "1"
				break
			}
		}
	}
	return result, nil
}

// Read-only LEDs from Linux sysfs; makes no hardware brand assumption.
func ledInventoryV14() (map[string]interface{}, error) {
	result := map[string]interface{}{"read_only": true, "leds": []map[string]interface{}{}, "can_set_rgb": false}
	dirs, err := os.ReadDir("/sys/class/leds")
	if err != nil {
		result["reason"] = "No sysfs LED interfaces"
		return result, nil
	}
	list := []map[string]interface{}{}
	for _, dir := range dirs {
		if len(list) >= 24 {
			break
		}
		name := dir.Name()
		if name == "" || len(name) > 96 || strings.Contains(name, "/") {
			continue
		}
		folder := filepath.Join("/sys/class/leds", name)
		list = append(list, map[string]interface{}{
			"name": name,
			"brightness": boundPositiveV14(readSmallFileV13(filepath.Join(folder, "brightness")), 65535),
			"max_brightness": boundPositiveV14(readSmallFileV13(filepath.Join(folder, "max_brightness")), 65535),
		})
	}
	result["leds"] = list
	return result, nil
}
