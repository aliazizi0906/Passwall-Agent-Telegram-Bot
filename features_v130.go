package main

import (
	"context"
	"crypto/tls"
	"errors"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// CapabilityReportV13 advertises only capabilities discovered on this router.
// In particular, RGB LED, tower/band lock and SMS/USSD remain false until a
// model-specific, tested adapter has been installed. Never infer them from name.
func capabilityReportV13() map[string]interface{} {
	model := readSmallFileV13("/tmp/sysinfo/model")
	board := readSmallFileV13("/tmp/sysinfo/board_name")
	version := ""
	for _, line := range strings.Split(readSmallFileV13("/etc/openwrt_release"), "\n") {
		if strings.HasPrefix(line, "DISTRIB_RELEASE=") {
			version = strings.Trim(strings.TrimPrefix(line, "DISTRIB_RELEASE="), "'\"")
		}
	}

	radios := []string{}
	if w, err := loadUCIConfig("wireless"); err == nil {
		for id, typ := range w.SectionType {
			if typ != "wifi-device" {
				continue
			}
			band := strings.ToLower(w.Options[id]["band"])
			switch band {
			case "2g", "5g", "6g":
				radios = append(radios, band)
			default:
				if mode := strings.ToLower(w.Options[id]["hwmode"]); strings.Contains(mode, "11a") {
					radios = append(radios, "5g")
				} else if strings.Contains(mode, "11g") {
					radios = append(radios, "2g")
				}
			}
		}
	}
	sort.Strings(radios)
	radios = uniqueRadiosV13(radios)

	exists := func(path string) bool {
		_, err := os.Stat(path)
		return err == nil
	}
	return map[string]interface{}{
		"agent_version":   agentVersion,
		"openwrt_version": version,
		"hardware": map[string]string{
			"model": model,
			"board": board,
		},
		"capabilities": map[string]interface{}{
			"passwall2":     exists("/etc/config/passwall2"),
			"wifi_radios":   radios,
			"guest_network": false,
			"cellular":      false,
			"cell_lock":     false,
			"sms":           false,
			"led_rgb":       hasGoogleWifiLEDProviderV13(),
			"sqm":           exists("/etc/config/sqm") && commandPath("tc") != "",
			"upnp":          exists("/etc/init.d/miniupnpd"),
			"wireguard":     commandPath("wg") != "",
			"openvpn":       exists("/etc/init.d/openvpn"),
		},
		"limits": map[string]interface{}{
			"can_control_led": false,
			"can_lock_tower":  false,
			"can_send_ussd":   false,
		},
	}
}

func uniqueRadiosV13(s []string) []string {
	out := make([]string, 0, len(s))
	for _, v := range s {
		if len(out) == 0 || v != out[len(out)-1] {
			out = append(out, v)
		}
	}
	return out
}

func readSmallFileV13(path string) string {
	data, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	if len(data) > 4096 {
		data = data[:4096]
	}
	return strings.TrimSpace(string(data))
}

type doctorCheckV13 struct {
	ID      string `json:"id"`
	Status  string `json:"status"`
	Detail  string `json:"detail,omitempty"`
	Latency int64  `json:"latency_ms,omitempty"`
}

func doctorStatusV13(ok bool) string {
	if ok {
		return "pass"
	}
	return "fail"
}

func networkDoctorV13() (map[string]interface{}, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 22*time.Second)
	defer cancel()

	checks := []doctorCheckV13{}
	add := func(id string, ok bool, detail string, elapsed time.Duration) {
		checks = append(checks, doctorCheckV13{
			ID: id, Status: doctorStatusV13(ok), Detail: detail, Latency: elapsed.Milliseconds(),
		})
	}
	wan := heartbeatNetworkV6()
	wanUp, _ := wan["up"].(bool)
	add("wan_link", wanUp, "Route interface: "+safeIfaceV13(wan["interface"]), 0)

	// DNS and TCP checks use independent bounded contexts; an ICMP-only success
	// must not be reported as an application-level internet connection.
	begin := time.Now()
	dnsCtx, dnsCancel := context.WithTimeout(ctx, 5*time.Second)
	_, dnsErr := net.DefaultResolver.LookupHost(dnsCtx, "example.com")
	dnsCancel()
	add("dns_resolution", dnsErr == nil, sanitizedProbeErrorV13(dnsErr), time.Since(begin))

	begin = time.Now()
	dialCtx, dialCancel := context.WithTimeout(ctx, 5*time.Second)
	conn, tcpErr := (&net.Dialer{Timeout: 5 * time.Second}).DialContext(dialCtx, "tcp", "1.1.1.1:443")
	if conn != nil {
		_ = conn.Close()
	}
	dialCancel()
	add("tcp_443", tcpErr == nil, sanitizedProbeErrorV13(tcpErr), time.Since(begin))

	begin = time.Now()
	httpCtx, httpCancel := context.WithTimeout(ctx, 7*time.Second)
	req, reqErr := http.NewRequestWithContext(httpCtx, http.MethodGet, "https://example.com/", nil)
	httpOK := false
	httpDetail := ""
	if reqErr == nil {
		client := &http.Client{
			Timeout: 7 * time.Second,
			Transport: &http.Transport{
				Proxy:           nil,
				TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12},
				DialContext:     (&net.Dialer{Timeout: 5 * time.Second}).DialContext,
			},
			CheckRedirect: func(req *http.Request, via []*http.Request) error {
				if len(via) >= 2 {
					return errors.New("redirect limit reached")
				}
				return nil
			},
		}
		resp, err := client.Do(req)
		if err == nil {
			httpOK = resp.StatusCode >= 200 && resp.StatusCode < 400
			httpDetail = "HTTP " + http.StatusText(resp.StatusCode)
			_ = resp.Body.Close()
		} else {
			httpDetail = sanitizedProbeErrorV13(err)
		}
	} else {
		httpDetail = sanitizedProbeErrorV13(reqErr)
	}
	httpCancel()
	add("https_application", httpOK, httpDetail, time.Since(begin))

	pw := passwallRunning()
	if _, err := os.Stat("/etc/config/passwall2"); err == nil {
		add("passwall_service", pw, "Passwall2 service state", 0)
	} else {
		checks = append(checks, doctorCheckV13{ID: "passwall_service", Status: "skipped", Detail: "Passwall2 not installed"})
	}

	rootCause := ""
	status := "healthy"
	for _, check := range checks {
		if check.Status == "fail" {
			if rootCause == "" {
				rootCause = check.ID
			}
			status = "degraded"
		}
	}
	return map[string]interface{}{
		"status":       status,
		"first_failed": rootCause,
		"checks":       checks,
		"tested_at":    time.Now().Unix(),
		"note":         "Probes are point-in-time diagnostics; regional reachability and leak safety require separate path-specific tests.",
	}, nil
}

func safeIfaceV13(v interface{}) string {
	iface, _ := v.(string)
	if len(iface) > 32 {
		iface = iface[:32]
	}
	for _, r := range iface {
		if !(r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || r == '.' || r == '_' || r == '-') {
			return "unavailable"
		}
	}
	if iface == "" {
		return "unavailable"
	}
	return iface
}

func sanitizedProbeErrorV13(err error) string {
	if err == nil {
		return "Reachable"
	}
	if errors.Is(err, context.DeadlineExceeded) || os.IsTimeout(err) {
		return "Timed out"
	}
	return "Connection check failed"
}

// Keep package path helpers testable without assuming an OpenWrt filesystem.
func hasRuntimeFileV13(dir, file string) bool {
	_, err := os.Stat(filepath.Join(dir, file))
	return err == nil
}


func hasGoogleWifiLEDProviderV13() bool {
	if _, err := os.Stat("/usr/libexec/googlewifi-led/googlewifi-led.sh"); err != nil {
		return false
	}
	return len(ledChannelsV13()) == 3
}

func ledChannelsV13() map[string]string {
	channels := map[string]string{}
	entries, err := os.ReadDir("/sys/class/leds")
	if err != nil {
		return channels
	}
	for _, entry := range entries {
		n := strings.ToLower(entry.Name())
		for _, color := range []string{"red", "green", "blue"} {
			if _, already := channels[color]; !already && strings.Contains(n, color) {
				if _, err := os.Stat(filepath.Join("/sys/class/leds", entry.Name(), "brightness")); err == nil {
					channels[color] = entry.Name()
				}
			}
		}
	}
	return channels
}

func ledStatusV13() (map[string]interface{}, error) {
	channels := ledChannelsV13()
	provider := hasGoogleWifiLEDProviderV13()
	status := map[string]interface{}{
		"supported": provider,
		"provider":  "googlewifi-led",
		"channels":  channels,
		"active":    false,
	}
	if !provider {
		status["reason"] = "RGB provider not installed or required sysfs channels missing"
		return status, nil
	}
	status["active"] = serviceState("googlewifi-led")["status"] == "running"
	values := map[string]string{}
	for color, name := range channels {
		values[color] = readSmallFileV13(filepath.Join("/sys/class/leds", name, "brightness"))
	}
	status["brightness"] = values
	return status, nil
}
