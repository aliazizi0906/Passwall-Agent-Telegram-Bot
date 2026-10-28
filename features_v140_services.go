package main

import (
	"os"
	"sort"
	"strconv"
	"strings"
)

// Discover optional OpenWrt services without disclosing keys, DDNS credentials,
// network scripts or traffic rules. Hardware-independent and strictly read-only.
func networkServiceInventoryV14() (map[string]interface{}, error) {
	exists := func(path string) bool {
		_, err := os.Stat(path)
		return err == nil
	}
	services := map[string]bool{
		"dnsmasq":         exists("/etc/config/dhcp"),
		"https_dns_proxy": exists("/etc/config/https-dns-proxy"),
		"stubby_dot":      exists("/etc/config/stubby"),
		"smartdns":        exists("/etc/config/smartdns"),
		"adblock":         exists("/etc/config/adblock"),
		"adblock_fast":    exists("/etc/config/adblock-fast"),
		"ddns":            exists("/etc/config/ddns"),
		"mwan3":           exists("/etc/config/mwan3"),
		"firewall4":       exists("/sbin/fw4") || exists("/usr/sbin/fw4"),
		"firewall3":       exists("/sbin/fw3") || exists("/usr/sbin/fw3"),
	}
	vlans := []map[string]interface{}{}
	if conf, err := loadUCIConfig("network"); err == nil {
		names := []string{}
		for id, kind := range conf.SectionType {
			if kind == "bridge-vlan" {
				names = append(names, id)
			}
		}
		sort.Strings(names)
		for _, section := range names {
			if len(vlans) >= 48 {
				break
			}
			options := conf.Options[section]
			vid := parseVLANIDV14(options["vlan"])
			if vid == 0 {
				continue
			}
			vlans = append(vlans, map[string]interface{}{
				"vlan_id": vid,
				"device":  boundSafeV14(options["device"], 48),
			})
		}
	}
	ddnsCount := 0
	if conf, err := loadUCIConfig("ddns"); err == nil {
		for _, kind := range conf.SectionType {
			if kind == "service" {
				ddnsCount++
			}
		}
	}
	mwanInterfaces := 0
	if conf, err := loadUCIConfig("mwan3"); err == nil {
		for _, kind := range conf.SectionType {
			if kind == "interface" {
				mwanInterfaces++
			}
		}
	}
	return map[string]interface{}{
		"read_only":        true,
		"services":         services,
		"bridge_vlans":     vlans,
		"ddns_service_count": ddnsCount,
		"multiwan_interfaces": mwanInterfaces,
		"warning":          "Installed configuration is not proof the service is running; mutation and failover need verified adapters.",
	}, nil
}

func parseVLANIDV14(raw string) int {
	if raw == "" || strings.ContainsAny(raw, " \t\n\r") {
		return 0
	}
	vid, err := strconv.Atoi(raw)
	if err != nil || vid < 1 || vid > 4094 {
		return 0
	}
	return vid
}
