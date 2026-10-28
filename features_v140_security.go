package main

import (
	"net"
	"os"
	"strings"
	"time"
)

// Static security diagnostics cannot prove packet-path safety. This audit is
// explicitly NOT a DNS leak test or a kill-switch compliance certificate.
func routeSecurityAuditV14() (map[string]interface{}, error) {
	ipv6Disabled := readSmallFileV13("/proc/sys/net/ipv6/conf/all/disable_ipv6") == "1"
	publicIPv6 := 0
	interfaces, err := net.Interfaces()
	if err == nil {
		for _, iface := range interfaces {
			if iface.Flags&net.FlagLoopback != 0 || iface.Flags&net.FlagUp == 0 {
				continue
			}
			addrs, err := iface.Addrs()
			if err != nil {
				continue
			}
			for _, addr := range addrs {
				cidr, ok := addr.(*net.IPNet)
				if !ok {
					continue
				}
				ip := cidr.IP
				if ip.To4() == nil && ip.IsGlobalUnicast() && !ip.IsPrivate() {
					publicIPv6++
				}
			}
		}
	}
	ipv6Default := false
	routeTool := commandPath("ip")
	if routeTool != "" {
		out, err := runOutput(4*time.Second, routeTool, "-6", "route", "show", "default")
		if err == nil {
			for _, line := range strings.Split(out, "\n") {
				if strings.HasPrefix(strings.TrimSpace(line), "default ") {
					ipv6Default = true
					break
				}
			}
		}
	}
	resolvers := 0
	loopbackOnly := false
	resolverData, err := os.ReadFile("/etc/resolv.conf")
	if err == nil && len(resolverData) <= 32768 {
		resolvers, loopbackOnly = parseResolvV14(string(resolverData))
	}
	fw := "unknown"
	if _, err := os.Stat("/sbin/fw4"); err == nil {
		fw = "firewall4"
	} else if _, err := os.Stat("/usr/sbin/fw4"); err == nil {
		fw = "firewall4"
	} else if _, err := os.Stat("/sbin/fw3"); err == nil {
		fw = "firewall3"
	}
	indicators := []string{}
	if !ipv6Disabled && publicIPv6 > 0 && ipv6Default {
		indicators = append(indicators, "A public IPv6 address and default route exist; verify the policy applies to IPv6 as well as IPv4.")
	}
	if resolvers == 0 {
		indicators = append(indicators, "No resolver entry was found at /etc/resolv.conf; OpenWrt may manage resolvers elsewhere.")
	}
	indicators = append(indicators, "Actual DNS/IPv6 leak prevention and kill-switch behavior require external path tests during VPN failure.")
	return map[string]interface{}{
		"read_only":                   true,
		"verified_no_leak":            false,
		"kill_switch_verified":        false,
		"dns_leak_verified":           false,
		"ipv6_disabled_globally":      ipv6Disabled,
		"public_ipv6_address_count":   publicIPv6,
		"ipv6_default_route_present":  ipv6Default,
		"resolver_entries":            resolvers,
		"local_dns_forwarder_only":    loopbackOnly,
		"firewall_backend":            fw,
		"configuration_indicators":    indicators,
		"note":                        "Static configuration indicators only; no outbound leak-check requests were made.",
	}, nil
}

// Count nameserver entries while avoiding exposure of resolver IPs to the Web UI.
func parseResolvV14(raw string) (count int, loopbackOnly bool) {
	loopbackOnly = true
	for _, line := range strings.Split(raw, "\n") {
		fields := strings.Fields(strings.SplitN(line, "#", 2)[0])
		if len(fields)!=2 || fields[0]!="nameserver" {
			continue
		}
		ip := net.ParseIP(fields[1])
		if ip == nil {
			continue
		}
		count++
		if !ip.IsLoopback() {
			loopbackOnly=false
		}
		if count==16 {
			break
		}
	}
	if count==0 {
		loopbackOnly=false
	}
	return count,loopbackOnly
}
