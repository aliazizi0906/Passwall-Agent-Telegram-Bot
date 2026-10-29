# PW Agent v1.4.0-alpha.3 — experimental GitHub Latest (NOT Stable)

This is an incremental engineering Alpha, **not** completion of the v1.4/v1.5 roadmap or certification for production routers. The release is designed for staging and physical-hardware acceptance testing.

## New since alpha.2

### Reliable short OpenWrt installation
- Initial cPanel Setup and the legacy Fleet Provision/Reinstall screen now present **six separately copyable shell lines** instead of one complex, truncated `sh -c` command.
- Each line is short enough for typical BusyBox SSH paste buffers, with no nested quotes; the installer still verifies downloaded signed packages. Token is not placed in a URL.
- The standalone `wget` step can be replaced by a short `curl` download if needed. Provisioning credentials remain single-use and expire after 30 minutes.

### Wi-Fi SSID safe apply — first supported local rollback path
- Apply SSID changes only for a named `wifi-iface` section with `wifi.modify` permission and explicit confirmation.
- Before UCI changes, persist a root-only original wireless config and transaction marker on the router and launch a detached 90-second watchdog.
- A second, validated confirmation action marks the change final. If no confirmation is received, the **router itself** restores the original wireless config.
- The Agent's OpenWrt procd init script attempts recovery of unconfirmed changes after reboot/restart. No PHP cron or network connectivity is required to trigger recovery.
- Hardware-independent automated tests cover backup, rollback, concurrency lock and validation. **Real router reboot/power-loss and wireless-chipset tests remain required.** WAN/firewall and VPN route writes are not enabled by this feature.

### Improved operational controls and observability
- Add safe, allowlisted DNS/DHCP/NTP service restart buttons (dnsmasq, odhcpd, sysntpd). No arbitrary services, shell arguments or remote network/firewall restart.
- Add permission-scoped alert creation/toggle/delete and interval-based backup/Passwall2 restart schedules in the new control center. Alerts require the cPanel cron to be configured.
- Refresh overview and Internet metrics periodically only when the page is visible and the user isn't editing or running a command.
- Detect `apk` or `opkg` for installed-package and available-update inventory on different OpenWrt versions.
- Correct dashboard allowlists for existing read-only client-policy previews, DHCP leases, DNS/service inventories and package reports.
- Keep clear distinction between real OpenVPN service actions (alpha.2), **dry-run-only** VPN route planning and model-dependent LEDs.

### Validation
- GitHub fast-validation workflow checks Go format/tests/vet, PHP syntax and access/command validation tests, browser script syntax, and installer shell syntax.
- Signed release workflow additionally compiles 13 OpenWrt architectures, verifies the Ed25519 signing key and publishes a cPanel archive, hashes and signatures.

## Still incomplete / deliberately disabled
- **OpenVPN split tunnelling rules**: safe routing/firewall policy apply, importing credentials, automatic route recovery, DNS/IPv6 leak enforcement and kill switch.
- Full client blocking/rate limits, SQM or UPnP mutations; generic Wi-Fi password/channel/guest and WAN/firewall safe apply.
- Model-specific RGB color controls, LED time schedules, verified cellular tower/band/SMS adapters, all-architecture physical certification.
- One-click transactional cPanel backend upgrade/rollback, full audit of terminal behaviour and independent on-device power-cut test.

The UI must not advertise these functions as working. Use the matrix in `docs/en/V1_4_EXECUTION.md` to track further acceptance.

## Installation safety
1. Keep an independent SSH/LuCI path. Do not apply SSID changes on a remote router without physical recovery access.
2. Back up `config.php`, database and OpenWrt settings. **Do not rerun cPanel Setup when upgrading an existing installation.**
3. Upgrade PHP backend and router Agent to the same signed Alpha tag; avoid mixing alpha.2 Backend with alpha.3 Agent commands.
4. Issue a **new** provisioning token in Setup/Provision. Execute each short installer line separately.
5. Confirm SSID from a verified working connection before the 90-second deadline or the router will attempt automatic rollback.
6. Alpha does not imply GA stability or real-hardware certification.

See [repository](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot), [feature matrix](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/blob/feature/v1.4-secure-browser-control/docs/en/V1_4_EXECUTION.md) and [Persian roadmap](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/blob/feature/v1.4-secure-browser-control/docs/fa/V1_4_EXECUTION.md).
