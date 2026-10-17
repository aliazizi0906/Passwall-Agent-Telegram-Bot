# v0.4.0 feature matrix

## Requested features
1. Per-Shunt Smart Failover for `Default` based on Passwall2 HTTP Relay health and best healthy Relay latency.
2. Toggle Failover on/off for each `_shunt` node, including from the Shunt Rules screen.
3. Failure threshold, cooldown, Preferred node tracking and optional Auto-return.
4. Authenticated OpenWrt Agent self-update from the cPanel bot server.
5. LuCI `Services -> PW Agent` HMAC/server/key/latency diagnostics.
6. Telegram Mini App SSH command console using real OpenWrt credentials via local Dropbear.
7. V2Ray/Passwall2 subscription URL management.
8. Multi-link node import, one URL per line.
9. HTTP Relay-failed node scanning and safe cleanup.
10. Node Select & Delete.
11. Safe Delete All normal proxy nodes.

## Added operational improvements
- HTTP Relay ranking page.
- Telegram alerts on automatic Failover switch, Preferred-node recovery, and no-candidate conditions.
- Special Passwall2 nodes never participate in Failover selection or bulk node cleanup.
- Subscription deletion preserves nodes still referenced elsewhere.
- Update release storage is denied to direct HTTP access; Agent receives releases via HMAC-authenticated API only.
- Terminal queue payloads are encrypted with AES-256-GCM using a key derived from the device secret.
- Terminal command/result payloads are scrubbed from the queue after completion.
- Relay scan concurrency is limited to three tests to reduce load on Gale.
