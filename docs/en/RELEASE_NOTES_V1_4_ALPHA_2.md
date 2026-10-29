# PW Agent v1.4.0-alpha.2 — GitHub Latest (experimental, **NOT stable**)

This release extends the signed alpha.1 development preview with **real, permission-checked commands** and a redesigned cPanel web interface. The GitHub **Latest** designation does not indicate production certification, full support across OpenWrt models, or completion of all planned features.

## Newly actionable

- **Manual sysfs LED brightness:** list detected LEDs, change numeric brightness within the hardware's reported maximum, and identify LED temporarily. The Agent refuses driver-managed triggers and returns the previous brightness for manual recovery. RGB colors, LED scheduling and model-specific GoogleWifi LED drivers are **not** implemented.
- **Passwall2:** explicit authenticated enable/disable buttons alongside the existing restart and node/subscription operations. Service changes require confirmation and may temporarily disrupt VPN paths.
- **OpenVPN:** list safe UCI profile metadata and live service status; run explicit **start, stop, restart** operations using the fixed OpenWrt OpenVPN init script, only for users with `network.modify` and after explicit confirmation. These operations may affect routing and connectivity; keep an independent recovery channel.
- **VPN policy builder (dry run only):** validate an OpenVPN profile identifier, route policy and destination CIDR, rejecting default routes and unsafe networks. **No policy routing or firewall rule is installed** because independent rollback/health verification is not yet available.
- **Integrated Web SSH terminal:** accessible directly from PW Control for users with `terminal.execute`, authenticated via the independent web session plus CSRF. Commands still require the router's actual Dropbear credentials, are encrypted for the Agent and run non-interactively. Do not use a production router unless you trust the hosting account and access controls.
- **Readability and UI:** redesigned operational navigation with SVG icons, structured key/value and expandable results instead of long JSON blobs, better OpenVPN/LED control cards, readable mobile layouts and a fix to persist results across successive command executions.
- Updated bilingual documentation and two clearly labelled **illustrative SVG UI previews**. These are not live screenshots or proofs of real-hardware validation.

## Important limits

Other modules such as per-client block/limits, WAN/firewall safe-apply, Wi-Fi password/SSID apply, VPN split-tunnel rule installation, IPv4/IPv6 kill switch, RGB LED scheduling, modem controls and SQM policy changes remain **read-only, planning-only or disabled** until independent router-local rollback and hardware acceptance tests exist. Never interpret a capability-detection result as safe write support.

OpenVPN connection management requires `/etc/init.d/openvpn` and a working existing OpenVPN profile. The panel **does not create VPN credentials or upload certificates**.

## Deployment safety

1. **Staging / noncritical OpenWrt routers only.** Back up cPanel `config.php`, database, and OpenWrt state; keep SSH/LuCI accessible independently of the VPN being modified.
2. Download the exact v1.4.0-alpha.2 cPanel ZIP, OpenWrt architecture bundle and SHA256/Ed25519 signatures from this tagged release. Verify signatures before deploying.
3. For new installations, use cPanel `setup.php` to choose the web credentials and generate a one-time router provisioning command. For an existing alpha.1 installation, **do not rerun setup or overwrite live `config.php`**: follow versioned migration/upgrade documentation and verify database/Agent compatibility before rollout.
4. Open the web control center at `/control/`. Browser SSH is at `/terminal/`. Use a supported router that has OpenVPN installed if testing VPN controls.
5. Reconnect to the router locally after any OpenVPN service change; no independent tunnel rollback is yet guaranteed.

See [implementation matrix](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/blob/feature/v1.4-secure-browser-control/docs/en/V1_4_EXECUTION.md), [Persian notes](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/blob/feature/v1.4-secure-browser-control/docs/fa/V1_4_EXECUTION.md), and [main project docs](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot).
