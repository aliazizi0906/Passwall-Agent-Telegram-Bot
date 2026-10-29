# PW Agent v1.4.0-alpha.4 — Extended operational controls (GitHub Latest, NOT Stable)

**Scope:** Adds real, bounded on-router actions. It does NOT complete every roadmap item or certify all hardware. Do not enable router-wide rules on a remote-only device.

## Real actions added

- **Policy-based routing (PBR):** create and delete PW-managed UCI `pbr` destination CIDR policies via an EXISTING named OpenWrt `network` interface, list current policies. Requires installed `pbr`, an existing logical interface, explicit role permission and timed local rollback. Does not automatically create tun interfaces, import a VPN, or guarantee encrypted paths.
- **OpenVPN profiles:** enable/disable existing named OpenVPN UCI profiles with service restart and local rollback; initial OpenVPN service controls remain available. No secret/credential upload.
- **Client internet restriction:** add/remove a named UCI Firewall4 rule matching a single MAC forwarded from `lan` to `wan`. Not a complete block on other VLANs/zones, not per-client rate limiting.
- **SQM:** change download/upload kbit/s on an EXISTING named `sqm` queue through the installed SQM service. Applies at interface queue level, not per-device.
- **Wi-Fi:** update channel of an existing named `wifi-device` using local 120-second rollback. SSID changes keep their 90-second local recovery flow. Password changes and guest network provisioning are not yet enabled through this protected path.
- **VPN forwarding guard:** optionally block direct forwarded `lan`→`wan` traffic using a PW-managed Firewall4 UCI rule with rollback.
- **Classic DNS egress guard:** optionally block forwarded LAN→WAN TCP/UDP port 53 with rollback. This does NOT stop DoH/DoT, DNS generated on the router, traffic through arbitrary zones, or prove no IPv6 leak.
- **RGB LEDs:** color picker writes to Linux native sysfs multicolor `multi_index` / `multi_intensity` only for a 3-channel RGB LED with trigger `none`; the Agent discovers exact channel ordering and refuses unknown/vendor-managed LED hardware.
- **cPanel backend upgrades:** new local **CLI-only** signed upgrade utility `cli/backend-upgrade.php`: verifies pinned Ed25519 signature + SHA256, rejects traversal/symlinks/untrusted files, stages backups, preserves `config.php`, and supports manual `--rollback` / `--accept`. Browser upgrade remains intentionally disabled.

## Safety and compatibility
- Mutating actions use strict PHP and Go validation, RBAC, explicit confirmation, and a router-local 120-second UCI transaction watchdog. Pending changes are recovered after Agent restart/reboot. Confirm ONLY after verifying both internet and router-management connectivity.
- Only the target UCI package is restored on timeout. This does not restore arbitrary external scripts, other applications' rule mutations, or changes by other processes.
- The installer still uses 6 short independent commands copied one line at a time. Existing installations must NOT rerun Setup or replace their old `config.php`.
- Automated Go/PHP/JS tests run in GitHub Actions. **No evidence of live-router/power-cut acceptance or cPanel hosting account permissions testing is claimed.**

## Still not fully implemented or certified
- A generic leak-proof, interface-failure-tested IPv4/IPv6 Kill Switch with DNS/DoH/DoT coverage.
- Per-client rate limiting, priority scheduling and comprehensive VLAN/guest/parental controls.
- General WAN/firewall/network safe-apply and safe recovery under a real power cut.
- RGB vendor adapters, LED scheduling and modem-specific SMS, band lock and Cell Lock.
- One-click browser/backend self-update with transactional MySQL migrations and schema downgrade.
- Device-specific OpenVPN credential import, end-to-end DNS/IPv6 leak measurements and heterogeneous hardware certification.

## CLI-only signed backend upgrade

Download the SAME alpha.4 `pw-agent-cpanel-v1.4.0-alpha.4.zip`, its `.sig` and `.sha256`. Confirm `ext-zip` and `ext-sodium` are enabled. Back up the MySQL database separately.

```sh
php cli/backend-upgrade.php --archive /private/pw-agent-cpanel-v1.4.0-alpha.4.zip --sig /private/pw-agent-cpanel-v1.4.0-alpha.4.zip.sig --sha256 /private/pw-agent-cpanel-v1.4.0-alpha.4.zip.sha256
php cli/backend-upgrade.php --archive /private/pw-agent-cpanel-v1.4.0-alpha.4.zip --sig /private/pw-agent-cpanel-v1.4.0-alpha.4.zip.sig --sha256 /private/pw-agent-cpanel-v1.4.0-alpha.4.zip.sha256 --apply
php cli/backend-upgrade.php --rollback
# Or ONLY after successful health checks:
php cli/backend-upgrade.php --accept
```

The signed zip updater only replaces application files, not MySQL. Use a staging cPanel site and manually review migration state first.
