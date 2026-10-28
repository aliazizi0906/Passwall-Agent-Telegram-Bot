# Changelog

All notable PW Agent changes are documented here. GitHub may classify an RC-tagged build as a regular Release for discoverability; this is not a claim of feature-complete, production-certified support.

## [1.4.0-alpha.1] — Unreleased development branch

**Status:** Work in progress in Pull Request #8. Not deployed, not production-certified, and not a published GitHub Release.

### Added
- Independent cPanel-installed browser username/password login, Argon2id/bcrypt hashing, secure cookie sessions, CSRF and rate-limit lockout; authorized Telegram Owner resets.
- Persian control center styling, inline SVG navigation and pinned Vazirmatn with verified, bundled WOFF2 in the cPanel ZIP.
- More OpenWrt Go CPU targets, optional Passwall2 support, hardware/service discovery, QMI/ModemManager status, SQM/UPnP/DNS/DDNS/MultiWAN inventory.
- Signed bootstrap download with fallback and verification; bounded router download probes; read-only SSID change previews.
- Owner-approved Fleet canary rollout engine with first-router-only wave, health verification, explicit continuation, pause/cancel and MySQL tests.
- Temporary sysfs LED identification with restoration and managed-trigger refusal; static IPv6/DNS path risk inventory with no false no-leak guarantee.
- Additive versioned MySQL migration ledger, CLI and Telegram Owner upgrade workflow (no data-destructive down migrations).
- CI coverage for MySQL authentication, rollout and migrations, Go hardware-independent probes and additional CPU builds.

### Not yet complete
- Per-client firewall/traffic policies; safe network writes with router-independent reboot recovery; verified Kill Switch and split DNS; full SQM/Bufferbloat; vendor-specific modem/LED control; signed staged backend replacement and automatic rollback; real-device/production acceptance testing.
- All above are documented in [v1.4 execution matrix](docs/en/V1_4_EXECUTION.md). No stable tag should be produced until required tests pass.

## [1.3.0-rc.2] — GitHub Latest Release (originally published 22 October 2026)

**Status:** Promoted to GitHub Latest / regular Release on 28 October 2026, using existing signed assets. Version remains `v1.3.0-rc.2` and is not stable/GA `v1.3.0`.

### Changed
- Aligned Go Agent, PHP Setup and Backend version identifiers to `1.3.0-rc.2` so provisioning, health checks and generated package names agree.
- Reworked the root README to highlight GitHub Latest and distinguish it from the previous production baseline, with a direct link to the real cPanel ZIP.
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
