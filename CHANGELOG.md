# Changelog

All notable changes to PW Agent are documented here. Versions follow SemVer where production compatibility has been verified. Release candidates are **previews**, not declarations of feature-complete stable support.

## [1.3.0-rc.2] — Source candidate (22 October 2026)

**Status:** work-in-progress release candidate on `feature/v1.3-control-center`, not stable/production-certified. Official GitHub prerelease exists only when a signed release workflow has completed successfully.

### Added
- PW Control Center Telegram Mini App UI: bilingual FA/EN, RTL/LTR, light/dark, fleet switch, real metric history and chart display.
- Local LuCI Control Center with read-only system status, DHCP/neighbor clients, Wi-Fi networks, Network Doctor, capabilities and optional GoogleWifiLED provider status. Local views do not require Telegram/PHP connectivity.
- Go actions `agent.capabilities`, `network.doctor`, `led.status`, plus allowlisted CLI JSON modes for local LuCI.
- Passwall2 dashboard interactions for node list/test/select/import, subscriptions CRUD and Shunt read-only, backed by device-scoped RBAC and server-side input validation.
- RC-aware version pins for Go Agent and PHP Backend; Go tests, PHP action-validation tests, CI JavaScript validation, cPanel ZIP staging with SHA256 and signed release workflow.
- Dedicated FA/EN installation and v0.6 migration guides; status matrix and architecture plan.

### Maintained compatibility
- Outbound HMAC transport, command leasing, fleet/roles/audit, encrypted sensitive queued parameters, monitoring, schedules and existing v0.6 database schema.
- The original Telegram dashboard remains available alongside the new UI.
- The backend still targets cPanel PHP+MySQL without server-side Node.js.

### Security / caveats
- Agent actions respect allowlists; LuCI operations added here are read-only.
- Per-router client policy enforcement, cellular-band/tower lock/SMS/USSD adapters, LED RGB writes, DNS split/kill switch and full safe-apply/rollback are **not implemented or hardware-verified** in RC. Do not advertise these as complete.
- `1.3.0-rc.2` is **not** a stable upgrade path for critical routers; prefer isolated staging and explicit rollback checks.
- No WRTPro code, branding, licensing or proprietary assets are included.

### Testing
- CI: Go format/test/vet, six target GOARCH builds, PHP lint, setup/security validations, shell/JSON validation, mini-app and LuCI JS checks, Markdown link checks, cPanel package creation.
- Requires additional actual OpenWrt 24/25 and cellular hardware integration testing before GA.

## [0.6.0] — 17 October 2026
- Multi-device fleet management and groups; role-based access and per-device scope.
- Signed update/cache support, audit trail, config snapshots and rollback.
- Monitoring, alert rules, maintenance mode, scheduled actions and dashboard.
- cPanel Web Setup + one-time provisioning without hosting-side SSH.

## [0.5.0] — 17 October 2026
- Idempotency protections, command retry control and signed update support.
- Earlier Smart Failover and device/security improvements.

[Source](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot) · [PR #5](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/pull/5)
