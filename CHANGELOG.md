# Changelog

All notable PW Agent changes are documented here. Release candidates are pre-releases and are not claims of feature-complete, production-certified stable support.

## [1.3.0-rc.2] — Public pre-release (22 October 2026)

**Status:** Published GitHub pre-release with signed assets. Not stable/GA `v1.3.0`.

### Changed
- Aligned Go Agent, PHP Setup and Backend version identifiers to `1.3.0-rc.2` so provisioning, health checks and generated package names agree.
- Reworked the root README to distinguish the latest public pre-release from the latest stable release and link to the real cPanel ZIP.
- Updated bilingual documentation landing pages, install/update guides, migration guidance, release notes and feature-status pages. The v0.6 docs are explicitly marked legacy where appropriate.
- Updated CI artifact names and versioned release documentation.
- Published cPanel ZIP and six OpenWrt architecture bundles, with SHA256 files and Ed25519 signatures.

### Release boundaries
- Use RC2 only in staging with a separate Telegram Bot/database and a noncritical OpenWrt router.
- Existing deployed v0.6 installations remain unchanged until explicitly upgraded; keep `config.php`, instance keys, credentials and database backups.
- Hardware-dependent and advanced networking items listed in the implementation matrix are not complete or hardware-certified.

## [1.3.0-rc.1] — Initial public pre-release (22 October 2026)

- Introduced the PW Control Center Telegram Mini App preview with Persian/English, RTL/LTR and light/dark themes.
- Added the local read-only LuCI panel for system status, LAN clients, Wi-Fi, diagnostics and capability discovery.
- Added Go Agent diagnostics for `agent.capabilities`, `network.doctor` and `led.status`.
- Added Passwall2 dashboard operations for node list/test/select/import, subscription management and Shunt read-only views.
- Added version-pinned cPanel packaging, signed six-architecture OpenWrt bundles, PHP/Go/JavaScript CI and initial installation/migration guides.
- Added a safer Agent update installer that preserves the last-known-good binary and attempts rollback if service restart fails.

## [0.6.0] — Stable release (17 October 2026)

- Multi-device Fleet management, groups, RBAC, audit trail and configuration history/rollback.
- Monitoring, alert rules, maintenance mode, scheduled actions and Telegram Mini App dashboard.
- Browser-first cPanel Web Setup, one-time provisioning and version-pinned multi-architecture Agent assets.

## [0.5.0] — Stable release (17 October 2026)

- Public installation, one-time router provisioning and signed multi-architecture release bundles.
- Idempotent command handling, encrypted queued secrets, monitoring and update reliability hardening.

[Latest public pre-release](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v1.3.0-rc.2) · [Latest stable release](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v0.6.0)
