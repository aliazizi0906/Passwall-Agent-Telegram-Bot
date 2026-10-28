package main

import (
	"fmt"
	"net"
	"strings"
)

// clientPolicyPlanV14 intentionally does not install firewall rules.
// The future apply path must enforce nftables-compatible atomically committed
// rules and a router-local timeout/rollback that works without the backend.
func clientPolicyPlanV14(params map[string]interface{}) (map[string]interface{}, error) {
	macValue, ok := params["mac"].(string)
	if !ok {
		return nil, fmt.Errorf("a client MAC address is required")
	}
	mac, err := net.ParseMAC(strings.TrimSpace(macValue))
	if err != nil || len(mac) != 6 {
		return nil, fmt.Errorf("invalid 48-bit client MAC address")
	}
	if mac[0]&1 != 0 {
		return nil, fmt.Errorf("multicast or broadcast MAC addresses cannot be targeted")
	}
	if mac == nil || mac.String() == "00:00:00:00:00:00" {
		return nil, fmt.Errorf("zero client MAC address cannot be targeted")
	}
	mode, ok := params["mode"].(string)
	if !ok || (mode != "block" && mode != "unblock" && mode != "limit") {
		return nil, fmt.Errorf("mode must be block, unblock or limit")
	}
	target := strings.ToLower(mac.String())
	limit := int64(0)
	if mode == "limit" {
		number, ok := params["limit_kbit"].(float64)
		if !ok || number < 64 || number > 1000000 || number != float64(int64(number)) {
			return nil, fmt.Errorf("limit_kbit must be an integer between 64 and 1000000")
		}
		limit = int64(number)
	}
	return map[string]interface{}{
		"dry_run":          true,
		"client_mac":       target,
		"requested_policy": mode,
		"limit_kbit":       limit,
		"apply_available":  false,
		"warning":          "Preview only. No nftables/tc or UCI mutations. Applying client policies requires verified per-client identity, hardware capability and independent timed rollback.",
	}, nil
}
