# Legacy v0.4.0 upgrade checklist (archived)

> Do not use this document for a new installation. Use the root README for v0.5+ public installation. This file is retained only for historical reference.

1. Keep the existing cPanel `server/config.php`.
2. Upload/overwrite all other v0.4.0 `server/` files, including `server/releases/` and hidden `.htaccess` files.
3. Verify `health.php` reports `0.4.0`.
4. Copy or clone the complete repository to the OpenWrt router.
5. From the repository root, run:

   ```sh
   cd openwrt
   chmod +x install.sh
   ./install.sh
   ```

   If cloning directly from GitHub:

   ```sh
   cd /tmp
   git clone https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot.git
   cd Passwall-Agent-Telegram-Bot/openwrt
   chmod +x install.sh
   ./install.sh
   ```

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
