# PW Agent v1.4.0-alpha.5 — LuCI capability center and control UI refinement

**Status:** Experimental GitHub Latest, not Stable. This is NOT a complete clone of all LuCI pages, plugins or hardware adapters.

## Working additions
- **LuCI capability hub:** inspect 28 OpenWrt System, Status, Network, Wireless, VPN and Services modules from the router. Classifies each as actionable, read-only, package unavailable or adapter not implemented. Includes search, status filters and links to the corresponding PW Control section.
- **Safe hostname change:** edit a named system UCI hostname, snapshot `/etc/config/system`, launch independent 120-second recovery timer and require explicit confirmation.
- **LAN static DHCP reservations:** show existing reservations without secrets, add a new MAC/IP/name host reservation only within the configured LAN subnet and remove only a matching PW-managed reservation. Uses `dnsmasq` reload, local backup and rollback. Never overwrites another reservation.
- **Wi-Fi interface toggles:** enable/disable a named `wifi-iface` with an independently recovering, confirmation-gated UCI transaction. A disconnected Wi-Fi client may need to reconnect before confirming.
- **SSH terminal UI rewrite:** coherent visual style, fixed icon dimensions, responsive fields, readable LTR terminal output, connection status, and explicit selection of the dashboard's active device. The SSH backend independently checks device-scoped permissions; credentials are not saved to browser storage.
- **OpenVPN UI corrections:** normal-sized SVG header icons, consistent panel spacing, two-column PBR/network action cards on desktop and single-column mobile, clear distinction between route dry-run validation and real PBR safe apply.
- **Shared design system:** normalized SVG icon dimensions (including toolbar), safe input widths and overflow, RTL/LTR responsiveness, mobile forms, accessible focus and reduced-motion support.
- **Regression checks:** fast CI verifies Go tests/vet/formatting, PHP syntax/validation, JavaScript syntax including terminal, and static UI/CSS/permission contracts.

## Existing operational functionality retained
OpenVPN service/profile control, PBR managed destination policies, per-MAC LAN→WAN block/unblock, existing SQM queue rates, Wi-Fi SSID/channel timed apply, limited firewall forwarding and classic DNS port-53 egress guards, native sysfs RGB on supported LED hardware and the signed CLI backend update tool.

## Important exclusions
Full LuCI parity would require separate, audited adapters for firewall zone editing, VLAN/bridge/switch, WAN protocol credentials, safe guest Wi-Fi, process management, init scripts, arbitrary cron, disk mounts, storage, DDNS/UPnP/AdBlock extensions, all installed third-party `luci-app-*` packages and each router's hardware-dependent services.

These are **not represented as working**, and the hub intentionally distinguishes them. A generic remote LuCI proxy or arbitrary UCI shell editor is intentionally excluded due to security and router-bricking risk.

## Deployment
Use matched signed `v1.4.0-alpha.5` cPanel and OpenWrt Agent bundles. Back up the cPanel database and `config.php` and router UCI; never rerun Setup to upgrade an existing system. Keep independent physical/LuCI/SSH access. Test the new controls on noncritical hardware before relying on rollback under real disconnect/power loss.

The live website `openwrt.s14.telviprobot.top` is **not automatically updated** by publishing GitHub; upgrade backend and Agent separately. The installer retains six independently copyable shell lines.
