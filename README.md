# Gale OpenWrt Telegram Remote v0.4.0

Target: Google WiFi (Gale), ARMv7, OpenWrt 25.12.x, Passwall2.

PW Agent lets a private Telegram bot manage and monitor OpenWrt without a public/static IP. The router creates only outbound HTTPS connections to the cPanel backend.

## v0.4.0 highlights

### Smart Shunt Failover
- Per-`_shunt` opt-in failover policy.
- The current Shunt `Default` node becomes the Preferred node when Failover is enabled.
- Tests the active Default node by Passwall2 HTTP Relay / URL test on a schedule.
- Uses a failure threshold (default 2 checks) to avoid switching on a single transient failure.
- When unhealthy, tests normal proxy nodes concurrently and switches `default_node` to the healthy node with the lowest HTTP Relay latency.
- Cooldown (default 180s) prevents rapid route flapping.
- Optional Auto-return moves Default back to the Preferred node after it recovers.
- Special Passwall2 virtual nodes (`_shunt`, `_balancing`, `_urltest`, etc.) are excluded from candidate selection.
- Autonomous switch/recovery alerts are sent to Telegram.
- Configuration is stored at `/etc/pw-agent/failover.json` and included in standard OpenWrt backups.

### Agent self-update
- Telegram: System -> PW Agent Update -> Check / Install.
- The Agent downloads the release only from the configured bot server API.
- Server requests use the existing device HMAC authentication and signed responses.
- The release tarball is SHA256-verified before installation.
- Updates `/usr/bin/pw-agent`, the procd init script, and the LuCI integration, then restarts the agent.
- `/etc/pw-agent/config.json` and Failover configuration are preserved.
- Static release files are blocked from direct web access and are served only through authenticated API endpoints.

The initial upgrade to v0.4.0 is manual. From v0.4.0 onward, the OpenWrt component can be updated from Telegram after a newer release package is uploaded with the cPanel backend. cPanel/PHP backend files still have to be updated on the web host when a future release changes the server side.

### LuCI integration
A new `Services -> PW Agent` page checks:
- Agent version
- Device ID
- Configured server URL
- HTTPS/API reachability
- HMAC device authentication
- Signed server response validation
- Round-trip time
- Server version

No Telegram command is leased for this test.

### Telegram SSH Console
- `⌨️ SSH Console` opens as a Telegram Mini App.
- Access is restricted to configured numeric Telegram user IDs and validated Telegram Mini App `initData`.
- Requires the real OpenWrt username and password.
- Authentication/execution goes through local Dropbear (`127.0.0.1`); no WAN SSH port is opened.
- Credentials + command are encrypted with AES-256-GCM before entering the command queue and are scrubbed after completion.
- This is a command console, not a persistent PTY: normal shell commands work; full-screen interactive programs such as `vi` and `top` are not interactive.

### Passwall2 subscriptions and import
- Add V2Ray/Passwall2 HTTP(S) subscription URLs from Telegram.
- Uses Passwall2's own `subscribe_list` configuration and native `subscribe.lua` parser/update path.
- Update one subscription or all subscriptions.
- Delete a subscription; referenced nodes are preserved instead of breaking configuration.
- Multi-link import: paste multiple node URLs, one per line.

### Node lifecycle tools
- HTTP Relay ranking.
- Scan for nodes that fail HTTP Relay.
- Delete Relay-failed nodes with safe reference checks.
- Select multiple nodes and delete them.
- Safe Delete All for normal proxy nodes.
- Shunt and Passwall2 special virtual nodes are excluded from bulk cleanup.

## Existing capabilities retained
- System CPU/RAM/storage/uptime/OpenWrt/kernel/model status.
- Passwall2 enable/disable/restart/log and Main Node selection.
- Inline Shunt Default/Iran/other rule editing.
- Ping + TCP + HTTP Relay tests.
- Interfaces, WAN, DNS, routes, DHCP leases, connected clients, WireGuard.
- Wi-Fi SSID/password/radio/channel/station management.
- Firewall zones and port-forward enable/disable.
- Logs, processes, services, APK inventory/update check.
- Ping/Traceroute diagnostics.
- OpenWrt backup to Telegram and restore from Telegram.
- Router reboot with confirmation.

## Security model
- Router initiates outbound HTTPS polling; no public IP or inbound router port is required.
- Device requests and server responses use HMAC-SHA256.
- Telegram access is numeric-user-ID restricted.
- Mini App identity is validated server-side before SSH console access.
- Terminal secrets are encrypted in the queue; sensitive command parameters are scrubbed after completion.
- Mutating OpenWrt operations remain explicit actions with validation.
- Passwall2 node deletion checks live references.
- Agent update packages require authenticated download plus SHA256 validation.

## cPanel upgrade

Preserve your current `server/config.php` and overwrite the other backend files with the contents of `server/`.

Optional config keys (v0.4 defaults Terminal to enabled when the key is absent):

```php
'telegram' => [
    // existing settings ...
    'terminal_enabled' => true,
    'alert_chat_id' => 123456789,
],
```

Verify:

```text
https://YOUR-DOMAIN/routerbot/health.php
```

Expected version: `0.4.0`.

## Router upgrade to v0.4.0

Copy the complete `pwbot_v4` directory to the router, then:

```sh
cd /tmp/pwbot/pwbot_v4/openwrt
chmod +x install.sh
./install.sh
```

The installer preserves `/etc/pw-agent/config.json`.

Verify:

```sh
/usr/bin/pw-agent --version
pgrep -af pw-agent
logread -e pw-agent
```

Expected version: `0.4.0`.

After this one manual upgrade, future OpenWrt-agent releases can be installed from Telegram through `System -> PW Agent Update`.

## Telegram

The only slash command is:

```text
/start
```

All other operations are inline buttons, Telegram Mini App UI, or short prompted input.
