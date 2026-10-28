# PW Agent v1.4 — execution and hardware compatibility matrix

**Status:** Development branch `feature/v1.4-secure-browser-control`. This is **not** a completed v1.4 release, and no existing deployment should replace its released binaries with this branch without staging.

## Implemented in this branch (pending CI and hardware verification)

- **No. 1 — setup/recovery (partial):** safer, version-tag-pinned router bootstrap, nonempty download verification, curl/wget fallback. Complete backend staged upgrade/migration rollback not yet implemented.
- **No. 8 — local/web UI (partial):** updated PW Control Center design tokens, typography, responsive surfaces; LuCI remains read-only for risky mutations.
- **No. 9 — browser login (implemented for staging):** independent owner username/password entered during fresh cPanel Web Setup; password hashed with Argon2id or bcrypt in MySQL; server-side sessions, SameSite/HttpOnly/Secure cookie, 30-minute idle timeout, CSRF, per-IP persistent lockout and existing RBAC/device scopes. Existing installs bootstrap via Telegram Owner (System page), never anonymous web signup.
- **No. 7 — bounded speed probe (partial):** explicit opt-in 2 MiB download from the fixed Cloudflare endpoint with 10-minute Agent cooldown, strict network quota and a privacy notice. Does **not** measure actual ISP peak bandwidth, domestic routing, upload or bufferbloat.
- **No. 14 — CI (partial):** PHP credential/session/lockout integration tests against MySQL, existing multi-architecture Go CI, version-consistency checks.
- **Cross-router portability (partial):** Passwall2 made optional for OpenWrt-only controls; CPU build targets expanded to armv5, armv6, armv7, arm64, x86/386, amd64, mips/le, mips64/le, riscv64, loong64, ppc64le. Target support and actual compatibility differ: the target kernel, flash, library and package dependencies must be checked per router.
- **Vazirmatn reliability:** pinned v33.0.3 stylesheet, runtime CDN fallback, build-time locally bundled verified WOFF2, font license bundled; system-font fallback if both unexpectedly fail.

## Requested workstreams (1–14)

| # | Module | Implementation level / missing gate |
|---|---|---|
| 1 | Installation, backend updater, migration and rollback | Bootstrap hardened; schema version ledger, staged backend release updater and live rollback remain |
| 2 | Clients: block, quotas, speed, schedules | Existing read-only discovery; nftables + tc with safe persistence, per-device identity and verified rollback remain |
| 3 | Wi-Fi SSID/pass/channel/guest QR | Read-only enumeration exists; prepare/diff/commit/timed rollback and guest isolation remain |
| 4 | Iran/direct split, VPN kill switch, DNS/IPv6 leak | Not enabled; require audited nftables path policies, IPv4+IPv6 leak tests and connectivity rollback |
| 5 | DoH/DoT, split DNS and ad-block | DNS view/tests exist; resolver-specific adapters and split-path proof remain |
| 6 | SQM/QoS, NAT, UPnP, gaming | Installed-package detection exists; controlled SQM tuning, bufferbloat measurements, UPnP permissions remain |
| 7 | Router speed test, history and client traffic | Real 2 MiB capped download probe added; existing metrics/graphs kept. Full throughput/load testing, uploads and trustworthy per-client counters remain |
| 8 | Full local LuCI and remote UI | Read-only local panel and redesigned remote UI; safe writes not released |
| 9 | Independent browser username/password | Implemented for staging in v1.4 development, production hardening and live review pending |
| 10 | RGB LED and time-of-day schedules | Verified provider detection/read only; RGB mutation and nightly schedule require actual GoogleWifiLED device tests |
| 11 | VPN, Passwall2 health and failover | Existing node/subscription and failover; native WG/OpenVPN provider adapters and deeper probe/routing tests remain |
| 12 | LTE/5G modem, tower/band lock, SMS/USSD | No generic provider; hardware-specific, verified modem adapters needed |
| 13 | Fleet safe bulk rollouts | Existing groups/RBAC/alert/schedules; staged rollout/canary/suspend/rollback remain |
| 14 | Security, real hardware tests, stable GA | CI expanded; OpenWrt 24/25 real router tests, staging upgrade drills and penetration review remain |

## Cross-model policy

**Universal OpenWrt compatibility means graceful capability discovery and safe nonoperation**, not guaranteed availability of cellular modems, LED drivers, Wi-Fi radios, SQM, or Passwall2. Do not show controls as operational without a real verified capability provider. Router CPU families can be added only when the Go compiler, release packaging and target firmware support them.

Every mutation touching WAN/Wi-Fi/firewall/DNS/routing must implement **snapshot → diff → explicit confirm → prepare → apply → connectivity/health check → commit or timed local rollback**, with independent local recovery when Backend is unreachable. If any step is not implemented, the mutation must remain disabled.

## Deployment notes

- Fresh cPanel setup asks for `web_username`, `web_password`, `web_password_confirm`; use unique long credentials and HTTPS.
- Existing installations: only a verified Telegram **Owner** in the Mini App System page may set/reset web login. No reinstall or secret-key rotation.
- Browser login URL: `<base_url>/auth/login.php`; same authorized Fleet/Queue, no bypass of RBAC.
- New installer command pins the bootstrap script to `v<backend_version>`; it will not work until a matching signed release tag exists. **No v1.4 release has been published.**
- cPanel ZIP production builds vendor the Vazirmatn binary; the source repository tracks the stylesheet, not bundled font binaries. No extra Node.js runtime is needed on cPanel.
- Existing release `v1.3.0-rc.2` and production `main` stay unchanged during this review.

## Definition of done for full request

Stable release only after all 14 modules are genuinely implemented and tested with capability-dependent functionality verified on supported hardware families, including offline WAN cut recovery, fresh install, migration, downgrade, and at least one Google WiFi AC1304. Cross-compilation and screenshots alone are not acceptance evidence.
