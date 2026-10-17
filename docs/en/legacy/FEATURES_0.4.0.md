# v0.4.0 Feature Matrix — Archived

[English Documentation](../README.md) · [فارسی](../../fa/legacy/FEATURES_0.4.0.md)

This document is preserved as historical documentation for v0.4.0. For current capabilities, use [Features](../FEATURES.md).

## Requested features

1. Per-Shunt Smart Failover for `Default` based on Passwall2 HTTP Relay health and best healthy Relay latency.
2. Toggle Failover on/off for each `_shunt` node, including from the Shunt Rules screen.
3. Failure threshold, cooldown, Preferred Node tracking and optional Auto Return.
4. Authenticated OpenWrt Agent self-update from the cPanel bot server.
5. LuCI **Services → PW Agent** HMAC/server/key/latency diagnostics.
6. Telegram Mini App SSH command console using real OpenWrt credentials through local Dropbear.
7. V2Ray/Passwall2 subscription URL management.
8. Multi-link node import, one URL per line.
9. HTTP Relay-failed node scanning and safe cleanup.
10. Node Select & Delete.
11. Safe Delete All for normal proxy nodes.

## Operational improvements

- HTTP Relay ranking page.
- Telegram alerts on automatic Failover switch, Preferred-node recovery and no-candidate conditions.
- Special Passwall2 nodes excluded from Failover selection and bulk cleanup.
- Subscription deletion preserves nodes still referenced elsewhere.
- Release storage denied to direct HTTP access; Agent receives releases through authenticated API.
- Terminal queue payloads protected with AES-256-GCM derived from the device secret.
- Terminal command/result payloads scrubbed from the queue after completion.
- Relay scan concurrency limited to reduce router load.
