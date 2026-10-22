# PW Control Center — v1.3 development status

> **v1.3.0-rc.2 is published as a public pre-release, not final/stable v1.3.0.** The code is available in the repository and the release assets are downloadable; existing deployed v0.6 installations remain unchanged until explicitly upgraded.

## Implemented for staging verification
- Independent `server/control/` Mini App preview with FA/EN, RTL/LTR and light/dark modes.
- Existing Telegram `initData` authentication, device-scoped RBAC, command queue, real metrics, charts and fleet switching.
- Read-only LAN client discovery, Wi-Fi, DNS, routes, connectivity checks, capability discovery and bounded Network Doctor.
- Existing Passwall2 read/manage operations wired to validated dashboard commands (nodes, import, selection, test, subscriptions).
- Local LuCI read-only Control Center and explicit RPCD ACL for offline status, Wi-Fi, clients, diagnostics and capabilities.
- Optional GoogleWifiLED provider/status discovery. RGB mutation intentionally absent until tested.
- CI: Go format, test, vet, six target architectures, PHP lint, validation tests, LuCI and Mini App JS checks, shell and JSON checks.

## GA release blockers
- Enforced client traffic policies and safe UCI/iptables/nftables rollback.
- Verified DNS/IPv6 kill switch and Iran split-routing policies.
- Modem-specific cellular APIs, band/cell lock, SMS/USSD and recovery tests on physical supported devices.
- RGB control and night automation, SQM/QoS, WireGuard/OpenVPN adapters and real router speed tests.
- Independent secure browser sessions, staged backend updater, migration and rollback.
- Hardware tests on OpenWrt 24/25, credential/authorization review, v0.6 production migration tests.
- A signed official `v1.3.0` release and end-to-end provisioning verification.

**Testing:** Use an isolated staging backend and noncritical OpenWrt router. The preview currently requires a Telegram Mini App context. Older v0.6 agents do not recognize new command names. Do not replace production binaries or backend without the documented staging, backup and rollback process.

The project must not claim unavailable hardware controls are operational. CI success validates only covered tests, not production rollout.
