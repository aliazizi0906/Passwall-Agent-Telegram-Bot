# Changelog

All notable PW Agent changes are documented here. GitHub may classify an RC-tagged build as a regular Release for discoverability; this is not a claim of feature-complete, production-certified support.

## [1.4.0-alpha.5] — LuCI capability catalog and responsive UX (29 October 2026)

- New live 29-module OpenWrt LuCI capability hub; action/read-only/unavailable/adapter-needed statuses, search, filter and module routing.
- Added locally rollback-protected UCI hostname change, LAN static DHCP lease add/delete, and named wireless interface enable/disable.
- Fixed distorted SVG icons in OpenVPN, SSH and toolbar, rebuilt the embedded terminal with selected-device scoping, readable LTR output and responsive fields.
- Reorganized real OpenVPN/PBR and firewall operations into responsive cards; clarified route dry-run vs real PBR apply.
- Expanded Go/PHP contract tests and front-end JavaScript/CSS/terminal checks. **No claim of all LuCI plugin parity or live router visual certification.**

[English notes](docs/en/RELEASE_NOTES_V1_4_ALPHA_5.md) · [فارسی](docs/fa/RELEASE_NOTES_V1_4_ALPHA_5.md)

## [1.4.0-alpha.4] — Scoped network and hardware actions (experimental, 29 October 2026)

- Real named UCI PBR create/delete, OpenVPN profile enable/disable, SQM rate configuration, MAC-based LAN→WAN rule block/unblock, Wi-Fi radio channel changes, LAN→WAN forwarding guard and client port-53 DNS guard.
- Independent router-local 120-second config snapshot/watchdog/confirm/rollback workflow, including startup recovery; existing SSID 90-second safe apply retained.
- Linux native 3-channel RGB sysfs LED colors with channel-order validation, only when the hardware exposes multi_intensity/multi_index and trigger none.
- cPanel CLI-only signed backend update utility: Ed25519/SHA256 verified ZIP, restricted paths, pre-upgrade copies, rollback and acceptance; no browser-triggered self-upgrade.
- Operation forms, backend allowlists, strict role permissions and Go/PHP tests.
- **Still unsupported:** full kill-switch/DNS leak guarantees, client-specific rate limits, guest/password setup, modem-specific SMS/band lock, vendor LED schedules and live power-loss certification.

[English release notes](docs/en/RELEASE_NOTES_V1_4_ALPHA_4.md) · [فارسی](docs/fa/RELEASE_NOTES_V1_4_ALPHA_4.md)

## [1.4.0-alpha.3] — experimental GitHub Latest (29 October 2026)

**Not Stable/GA.** Existing v1.4 limitations and real-hardware acceptance requirements still apply.

- Replaced lengthy nested-shell OpenWrt install and reprovision commands with **six copyable, individually executable lines** and private temporary bootstrap downloads.
- New router-local 90-second **Wi-Fi SSID Safe Apply**, with durable backup, independent watchdog and unconfirmed-change recovery after reboot or service restart; explicit confirmation via web UI.
- Allowlisted and confirmed DNS/DHCP/NTP service restart controls; improved apk/opkg package inventory.
- Added scoped alert/schedule creation and management controls, bounded live monitoring refresh, and fixed read-only command allowlists.
- Expanded Go/PHP security regression tests and a fast CI gate.
- OpenVPN route rules, general firewall/WAN and all-client policies, hardware RGB, advanced modem adapter and automatic backend update **remain incomplete**.

See [English alpha.3 release notes](docs/en/RELEASE_NOTES_V1_4_ALPHA_3.md) and [Persian notes](docs/fa/RELEASE_NOTES_V1_4_ALPHA_3.md).

## [1.4.0-alpha.2] — Experimental GitHub Latest (29 October 2026)

**Status:** Public Alpha / Latest, **not** production-certified or stable. Hardware-specific and network-critical controls remain restricted.

### Added or fixed
- Permission-scoped browser SSH terminal embedded inside the PW Control interface, using web-session CSRF validation and encrypted per-command Dropbear credentials.
- Confirmed, hardware-guarded brightness writes for manually controlled sysfs LEDs; refuses active kernel triggers and validates the brightness range.
- OpenVPN profile inventory, user-confirmed start/stop/restart service operations, and strict CIDR rule **dry-run** (not an installed route).
- Passwall2 enable/disable service buttons gated by permission and explicit confirmation.
- Structured, escaped, expandable result tables replacing generic raw JSON and fixing loss of the result after one command.
- Responsive operational control cards, SVG navigation icons and labelled static illustrative screenshots for OpenVPN, LED and reports.
- Signed multi-architecture cPanel/OpenWrt release publishing, new unit/API validation.

### Not complete
- Real OpenVPN split-route/firewall policy installation; safe Wi-Fi/WAN changes; automatic rollback after connectivity loss; general client block/limits; hardware RGB/LED patterns, modem management and all-device certification.

**Upgrade caution:** Do not rerun cPanel setup or overwrite an existing `config.php`. Follow the tested upgrade/migration path and back up the database/router first.

## [1.4.0-alpha.1] — GitHub Latest public Alpha (29 October 2026)

**Status:** Public alpha published as GitHub Latest by maintainer request, from the open v1.4 development branch. **Not** deployed or production-certified; not a stable / GA release.

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

[Latest public Alpha](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v1.4.0-alpha.2) · [Latest stable release](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v0.6.0)
