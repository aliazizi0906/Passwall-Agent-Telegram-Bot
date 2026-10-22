# PW Control Center v1.3 — Functional specification (FA)

Status: **partially implemented in public pre-release v1.3.0-rc.2**; this specification also contains planned items that are not yet implemented or hardware-certified.  
Source branch: `feature/v1.3-control-center`; public pre-release: `v1.3.0-rc.2`. Existing production v0.6 deployments must remain untouched until staged migration and rollback checks pass.  
Reference: user-supplied WRTPro screenshots and product-description text, used solely as a functional reference. PW Control Center must have its own code, styles, identity, components and permissions. **No WRTPro binaries, source or license system.**

## Product goal

Turn PW Agent from Telegram-first Passwall2 remote management into a dual-mode OpenWrt control center:
1. **Local mode:** on-device web UI via LuCI/ubus; usable while the internet or PHP backend is unavailable.
2. **Remote mode:** Telegram Mini App + optional independently authenticated browser portal via outbound HTTPS polling (no open inbound management port).
3. One typed capability/action layer, so the same feature can be operated locally or remotely subject to RBAC and safe apply/rollback.

The PHP 8.2+/MySQL+cPanel backend stays supported without Composer, SSH or a resident Node server. A static Vite build is permitted; router agent remains Go. Feature-detect hardware and installed packages instead of presenting unsupported controls.

## Functional map from provided reference

| Module | Required capabilities | v0.6 current basis | v1.3 delivery |
|---|---|---|---|
| Home | Link status, upload/download, Iranian/international connectivity, gateway, selected proxy, CPU, uptime, clients, shortcuts | Metrics + summary | New home UI + per-interface/live client telemetry |
| Internet | WAN/LAN health, local/foreign loss & latency, 24h/7d history, router-based bounded speed test, first-failure diagnosis | Heartbeats, WAN/DNS checks | Historical series, speed-test jobs and diagnosis report |
| Proxy | Passwall2 status, profiles/nodes, bulk link import, subscriptions, health/ranking, Xray/WireGuard/OpenVPN **when installed** | Passwall2 actions only | Provider adapters + compatibility checks, no claim that Passwall2 already supports WG/OVPN |
| Protection | Iran direct-routing policy, DNS leak controls, IPv6 policy, kill switch, protected guest access | Partial UCI/Shunt | Explicit policies, before/after diff and packet-path integration tests |
| Watchdog | Real HTTP/TCP application probes, score/rank, cooldown/hysteresis, preferred-node recovery, safe-mode/restart policy | Smart failover | Independent checks, bounded recovery and detailed audit |
| Clients | Named devices; online/offline; IP, MAC, Wi-Fi band/wired; rx/tx, block/unblock, speed limits, timed access, proxy bypass, gaming priority | Existing agent system.clients; dashboard API does NOT expose it | Persistent client identity, nftables/tc integration, controlled policies |
| Wi-Fi | 2.4/5 GHz SSID/key/security/channel, guest SSID isolation, QR, channel scan/recommendation, repeater when supported | UCI Wi-Fi | Local and remote safe apply with connectivity rollback |
| DNS & security | Upstream picker, encrypted DNS, Iran/direct split, resolver tests, ad-block lists, access hardening, short-lived optional logs | Basic network/DNS | Split-DNS correctness tests, integration with installed resolver, opt-in volatile logging |
| Gaming | NAT indication, bufferbloat test, SQM/QoS shaping, per-client priority, UPnP optional and security warning | Diagnostics partial | Capability-gated SQM, explicit opt-in UPnP |
| Modem/cell | Signal, tower/cell scan, 4G/5G band preferences, cell lock, aggregation stats, bounded preference-based tests, SMS, USSD | Not present | Hardware driver adapters ONLY; no fake capability on Google WiFi |
| LED | Device status colors, custom RGB, latency status, speed-test animation, night-time off | Not generic | Optional per-model provider (e.g. Google WiFi LED package); unsupported hardware hides tab |
| System | Process, resource, logs, reboot, backup/restore, signed agent updates | Present in part | Guided diagnostics, staged config apply & recovery |
| Fleet | Multi-router switch, groups, alerts, maintenance, schedules, RBAC, audit | Present | Consistent UX + bulk safe rollout |
| Updates | Backend staged update with rollback, signed agent release, DB migrations, compatibility gates | Agent signature path; backend manual copy | Transaction-safe migration + operator confirmation |

**Not included:** license key generation or activation workflows. **Not assumed:** generic cellular modem support, universal Xray/WireGuard/OpenVPN install, unrestricted SSH or arbitrary host-side command execution.

## Screenshot-based information architecture

Primary navigation (right for RTL; left for LTR): Home, Internet, Cellular*, Wi-Fi, Devices, Proxy, Gaming, DNS & Security, System, Automation, Fleet. Optional LED tab (*) shown only if agent advertises a controllable LED; Cellular only if modem + supported driver detected.

Home components: three-state connectivity (healthy/degraded/offline), active policy and route, gateway, IPv4/IPv6 visibility, upload/download units, CPU/memory/uptime, device count, frequent actions, top talkers, diagnostic summary.

Pages use section tabs, search/filter, safe empty/error/loading states, and explicit warnings for actions which can cut off local/remote access. Responsive mobile-first RTL/LTR; independent design tokens; Persian and English; theme light/dark.

## Required agent capability contract

New read-only `agent.capabilities` result:
```json
{
  "agent_version": "1.3.0",
  "openwrt_version": "...",
  "hardware": {"model": "...", "board": "..."},
  "capabilities": {
    "passwall2": true,
    "wifi_radios": ["2g", "5g"],
    "guest_network": true,
    "cellular": false,
    "cell_lock": false,
    "sms": false,
    "led_rgb": false,
    "sqm": false,
    "upnp": false,
    "wireguard": false,
    "openvpn": false
  }
}
```
All booleans are discovered by **agent runtime probes**, never hardcoded from a model name. For dangerous features, a per-driver list of operations and parameter ranges is required. Frontend hides unsupported features and backend also rejects unsupported commands.

## Safety and trust requirements

- Remote control still uses Telegram `initData` with age/signature validation and existing RBAC; plain browser portal needs its own real authentication, never a bypass or token in URL.
- All mutations via a server-side action allowlist, permission check, device scope, CSRF (for cookie sessions), per-device locks and audit.
- Parameter validation and OpenWrt adapters; no shell concatenation of untrusted arguments.
- Wi-Fi/WAN/firewall/DNS/cell-lock changes use **prepare → diff → confirm → apply → health-check → commit / timed rollback**. For internet-breaking changes, fallback must be local and work without server.
- DNS and IPv6 leak checks include both IPv4 and IPv6, no "safe" label without passing tests. Kill switch modes must clearly state whether LAN/internal access remains.
- Cell lock has an automatic reconnect rollback deadline with hardware-specific confirmation. Background optimization respects daily data budgets and must be explicitly opted into.
- Client blocking/rate limits must remain effective through reboot and service restarts, with time-zone-aware schedules.
- No secrets, identifiable browsing history, or full client activity logs in default telemetry. DNS request history off by default and volatile if enabled.
- Signed downloads (SHA256 + Ed25519) for both initial installation and updates; no unsigned `main` installer execution as an unquestioned trust boundary.
- SQL migrations outside device-command transactions; backward-compatible schema changes, backup before migration, forward-only migration ledger.
- All API surfaces have JSON versioning, explicit errors and rate limits. No fake live metrics.

## Implementation phases & acceptance gates

**P0 — v0.7 Reliability:** transaction-safe schema migrations; provisioning failure recovery; cron diagnostics; DB integration tests; installer signature checks; backend staging/rollback. Gate: setup, reinstall and update pass repeated tests including partial failures.

**P1 — v0.8 Control Center UI:** new original RTL/LTR theme-aware dashboard, router picker, status & metric charts, job results, safe confirmation; v0.6 legacy dashboard remains intact. Gate: Mini App authorization and mobile/responsive smoke tests.

**P2 — v0.9 Connectivity:** Passwall2 node operations and subscription UX, actual Iran/direct route and DNS split verification, firewall leak & kill-switch tests, enhanced watchdog. Gate: integration tests under disconnect/reconnect scenarios.

**P3 — v1.0 Clients & Wi-Fi:** per-client rate/block/schedule/priorities and guest Wi-Fi; safe apply/rollback; QR. Gate: field tests on physical OpenWrt.

**P4 — v1.1 Internet/Gaming:** external/local probes, history, per-router speed tests with quota; SQM/UPnP only with opt-in. Gate: controlled metered tests; privacy and CPU resource budgets.

**P5 — v1.2 Hardware Adapters:** Google WiFi LED provider; cellular modem API adapter capability discovery, band/cell/USSD/SMS on supported hardware only. Gate: hardware matrix and revert/restore paths tested.

**P6 — v1.3 GA:** combined local panel + Telegram/cloud panel, user docs, stable versioned API, safe fleet rollouts, recovery drills and complete release CI. Gate: no known critical security issues; successful upgrade from v0.6 with backup.

## Honest current implementation boundary

The initial `server/control/` UI can only consume **already allowlisted v0.6 dashboard endpoints**: `summary`, `device_detail`, `select_device`, `commands`, `command` and existing actions. It is not by itself an OpenWrt client/device manager or a cellular controller. Do not label pending functions "working" before agent actions, PHP API permission checks, end-to-end tests, and supported hardware providers are implemented.
