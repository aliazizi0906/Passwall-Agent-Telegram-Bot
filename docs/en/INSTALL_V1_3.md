# PW Agent v1.3.0-rc.2 — staging installation

[Home](../../README.md) · [فارسی](../fa/INSTALL_V1_3.md) · [Upgrade](UPGRADE_V1_3.md) · [Implementation matrix](CONTROL_CENTER_PROGRESS.md)

> **GitHub Latest Release, RC-tagged; staging recommended.** Matching signed assets for `v1.3.0-rc.2` are available from [GitHub Latest](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/latest). The regular GitHub Release designation does not imply production certification; do not install onto a critical production router.

## 1. Prerequisites

**cPanel:** PHP ≥8.1, MySQL/MariaDB, PHP PDO MySQL/cURL/OpenSSL, HTTPS, File Manager, MySQL Databases, Cron Jobs. No host SSH or Node runtime required.

**Router:** OpenWrt + Passwall2, SSH access on LAN, HTTPS access to GitHub, curl or wget, sha256sum and tar. LuCI/uhttpd required for local panel. Cellular modem and RGB controls require explicit hardware support.

Use a **separate staging domain/subdirectory, database and Telegram Bot**, because configuring a second backend with your production Bot token changes the Bot webhook and can interrupt production.

## 2. Download and verify cPanel package

Download `pw-agent-cpanel-v1.3.0-rc.2.zip` and its `.sha256` from the [v1.3.0-rc.2 pre-release](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v1.3.0-rc.2). Compare SHA256 before uploading; verify the `.sig` against a previously trusted signing public key. If an expected asset is missing, stop and do not substitute a random artifact.

The ZIP contains the contents of `server/` at the archive root, **not an enclosing server directory**.

## 3. Install backend without SSH

1. Create MySQL database/user and grant required privileges.
2. Create a staging folder in cPanel File Manager, e.g. `public_html/pw-test`.
3. Upload/extract the ZIP directly there. Verify `setup.php`, `api/`, `control/`, `dashboard/`, `cron/` and hidden `.htaccess` exist at that level.
4. Create a new test bot with BotFather; message it `/start`.
5. Open `https://example.com/pw-test/setup.php`, enter MySQL and Bot details, owner Telegram numeric ID, initial router ID/name and timezone; complete installation.
6. Open `https://example.com/pw-test/health.php`. The installed RC should report `"version":"1.3.0-rc.2"`. If HTTP 500 occurs, inspect PHP error_log and the DB connection. **Do not delete config.php as a troubleshooting shortcut.**

## 4. Configure cron correctly

Choose **Once Per Minute** in cPanel → Cron Jobs, then place **only the shell command** supplied by Setup in the Command box:

```sh
'/opt/cpanel/ea-php82/root/usr/bin/php' '/home/CPANEL_USERNAME/public_html/pw-test/cron/monitor.php' >/dev/null 2>&1
```

Do not paste `* * * * *` into the Command field if cPanel already collects the schedule separately. Use actual detected paths, not example values.

## 5. Install on a noncritical OpenWrt test device

Only after the matching signed agent asset is published, run the exact one-time command displayed by Setup:

```sh
PW_SERVER_BASE='https://example.com/pw-test' \
PW_PROVISION_TOKEN='PRIVATE_SINGLE_USE_TOKEN' \
PW_AGENT_VERSION='v1.3.0-rc.2' \
sh -c "$(wget -qO- 'https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh')"
```

Provision tokens expire in 30 minutes and are single-use. Reissue from Dashboard → Router → Provision/Reinstall if needed. Never share tokens in tickets/screenshots.

## 6. Open and verify both panels

**Telegram:** Bot `/start` → Dashboard → PW Control Preview. The preview uses verified Telegram Mini App `initData`; standalone browser login is not yet offered.

**Local LuCI:** Services → PW Control Center. This read-only panel operates while the backend is offline.

CLI verification:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
/usr/bin/pw-agent capabilities-json
/usr/bin/pw-agent network-doctor-json
/usr/bin/pw-agent clients-json
/usr/bin/pw-agent wifi-json
/usr/bin/pw-agent led-json
/etc/init.d/pw-agent status
logread -e pw-agent
```

Check live metrics, LAN clients, Wi-Fi and Passwall2. Do not mistake a successful CI cross-compile for actual OpenWrt 24/25 hardware validation.

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| Provision 403 | Expired/consumed token or host-side security rule; issue a fresh token |
| Provision 500 | PHP error log, migration/DB access, matching backend revision |
| GitHub asset 404 | Matching release/tag/architecture asset has not been published |
| New command unsupported | Router still runs legacy v0.6 Agent |
| Mini App unauthorized | Open inside Telegram, check owner/RBAC, HTTPS and Bot webhook |
| LuCI menu absent | Verify bundled resource/menu/rpcd ACL, LuCI installed, refresh cache |
| Cellular/LED unavailable | Hardware capability/verified provider missing; not a generic software failure |

Existing production installations must use the [upgrade procedure](UPGRADE_V1_3.md), not rerun Setup. Never expose `config.php`, router credentials or provisioning tokens.
