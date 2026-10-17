# Features

[Documentation Home](README.md) · [فارسی](../fa/FEATURES.md)

## Core router management

PW Agent exposes supported OpenWrt operations through Telegram without exposing a WAN management port.

Current areas include:

- system status, uptime, CPU/load, memory and storage
- network interfaces, WAN, DNS, routing and DHCP information
- Wi-Fi SSID/password/radio/channel/station operations
- firewall zones and redirect/port-forward operations
- system and kernel logs
- process and service management
- ping and traceroute
- backup and restore

## Passwall2

Passwall2 integration includes:

- Main Node selection
- Shunt Rule inspection and mutation
- Default route selection for Shunt nodes
- node list and node deletion safety
- VLESS / VMess / Trojan / Shadowsocks link import where supported by the parser
- multi-link import
- subscription URL management
- subscription update
- relay testing and ranking
- dead Relay node scanning and cleanup
- bulk delete operations with reference protection

Special/virtual Passwall2 nodes are excluded from unsafe bulk operations and Smart Failover candidate selection.

## Smart Failover

Smart Failover is configured per Shunt node.

It supports:

- enable/disable per Shunt
- Preferred Node
- optional Auto Return
- repeated Relay probes
- TCP reachability
- latency scoring
- jitter penalty
- loss penalty
- failure threshold
- cooldown
- automatic switching to the best healthy candidate
- Telegram events for switch/recovery/no-candidate conditions

## Telegram SSH Console

The Telegram Mini App provides a command console using real OpenWrt credentials.

Important properties:

- local Dropbear/dbclient path on the router
- no WAN SSH requirement
- Telegram Mini App `initData` validation
- command/cwd/credential payload protection
- command timeout and output limits
- non-persistent, non-interactive command execution rather than a full PTY

## Monitoring

The backend stores device metrics/events and provides:

- last seen
- online/offline state
- heartbeat coverage
- load history summary
- uptime summary
- Failover event count
- latest event
- offline and recovery alerts through cron

## LuCI diagnostics

The LuCI page under **Services → PW Agent** can test:

- Agent version
- Device ID
- Backend URL
- HMAC authentication
- signed server response
- round-trip latency

## Self-update

Agent updates use architecture-specific GitHub Release bundles and verify:

1. SHA256 integrity
2. Ed25519 signature authenticity

The official update public key is embedded in the Agent and published in `UPDATE_PUBLIC_KEY.b64`.

## Reliability

v0.5 adds:

- expiring command leases
- retry budgets
- per-command idempotency keys
- Agent-side idempotency journal
- result replay after delivery failure
- encrypted storage for sensitive queued parameters

See [Architecture](ARCHITECTURE.md) and [Security](SECURITY.md) for details.
