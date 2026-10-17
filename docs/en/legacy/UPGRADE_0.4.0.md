# Upgrade to v0.4.0 — Archived

[English Documentation](../README.md) · [فارسی](../../fa/legacy/UPGRADE_0.4.0.md)

> Historical document only. New installations must use the current [Install Guide](../INSTALL.md). Current upgrades should use [Migration](../MIGRATION.md).

Legacy v0.4 flow:

1. Keep the existing cPanel `server/config.php`.
2. Upload/overwrite the remaining v0.4 `server/` files.
3. Verify `health.php` reports `0.4.0`.
4. Copy or clone the repository to OpenWrt.
5. Run `openwrt/install.sh`.
6. Verify `/etc/pw-agent/config.json` still contains the expected API URL, Device ID and Device Secret.
7. Verify `/usr/bin/pw-agent --version`.
8. Open `/start` in Telegram.
9. Test Agent Connection diagnostics.
10. Test the LuCI PW Agent connection page.
11. Test Shunt Smart Failover.
12. Test subscriptions, link import and Relay Ranking.
13. Test SSH Console.

From v0.4 onward, Agent/init/LuCI updates were designed to be delivered remotely while backend PHP changes remained server-side deployments.
