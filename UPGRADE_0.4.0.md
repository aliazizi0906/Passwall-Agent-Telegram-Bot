# v0.4.0 upgrade checklist

1. Keep the existing cPanel `server/config.php`.
2. Upload/overwrite all other v0.4.0 `server/` files, including `server/releases/` and hidden `.htaccess` files.
3. Verify `health.php` reports `0.4.0`.
4. Copy the complete `pwbot_v4/` folder to Gale.
5. Run `openwrt/install.sh`.
6. Verify `/etc/pw-agent/config.json` still has the corrected API path, Device ID and Device Secret.
7. Verify `/usr/bin/pw-agent --version` prints `0.4.0`.
8. Open `/start` in Telegram.
9. Test `Diagnostics -> Agent Connection`.
10. Open OpenWrt LuCI -> Services -> PW Agent and run Connection Test.
11. Test Shunt Rules -> Smart Failover on `rulenode` while its Default points to a normal VLESS node.
12. Test Nodes -> Subscriptions / Add Link(s) / Relay Ranking before doing any bulk deletion.
13. Open SSH Console and authenticate with the OpenWrt username/password.

No database schema migration is required from v0.3.0. The session table is created automatically if needed.

From this version onward, the OpenWrt Agent + init script + LuCI integration can be updated through Telegram. Server-side PHP changes still require uploading the newer server backend to cPanel.
