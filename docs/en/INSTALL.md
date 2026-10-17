# Install PW Agent

[Documentation Home](README.md) · [فارسی](../fa/INSTALL.md)

## Menu

- [Requirements](#requirements)
- [1. Check OpenWrt](#1-check-openwrt)
- [2. Prepare cPanel](#2-prepare-cpanel)
- [3. Create the MySQL database](#3-create-the-mysql-database)
- [4. Create the Telegram bot](#4-create-the-telegram-bot)
- [5. Clone the repository](#5-clone-the-repository)
- [6. Deploy the backend](#6-deploy-the-backend)
- [7. Run setup](#7-run-setup)
- [8. Validate the backend](#8-validate-the-backend)
- [9. Install the OpenWrt Agent](#9-install-the-openwrt-agent)
- [10. Verify the Agent](#10-verify-the-agent)
- [11. Enable monitoring cron](#11-enable-monitoring-cron)
- [12. Test Telegram](#12-test-telegram)
- [Updating later](#updating-later)

## Requirements

### cPanel server

- PHP 8.1 or newer
- MySQL or MariaDB
- PHP extensions: `pdo_mysql`, `curl`, `openssl`
- HTTPS
- cPanel Terminal or SSH access
- Cron Jobs access recommended
- Git available in the shell

### Router

- OpenWrt
- Passwall2 already installed
- Internet access
- `wget` or `curl`
- `sha256sum`
- `tar`

No public IP is required.

## 1. Check OpenWrt

SSH into the router and run:

```sh
uname -m
test -f /etc/config/passwall2 && echo "Passwall2: OK" || echo "Passwall2: NOT FOUND"
command -v wget || command -v curl
command -v sha256sum
command -v tar
```

Architecture mapping:

| `uname -m` | PW Agent architecture |
|---|---|
| `armv7l` | `armv7` |
| `aarch64` | `arm64` |
| `x86_64` | `amd64` |
| `i386` / `i686` | `386` |
| `mips` | `mips` |
| `mipsel` | `mipsle` |

## 2. Prepare cPanel

Create a domain or subdomain, for example:

```text
pw.example.com
```

Point its document root to a dedicated directory such as:

```text
/home/CPANEL_USER/public_html/pwagent
```

Enable SSL and confirm the site is reachable over HTTPS.

Check the CLI PHP version:

```bash
php -v
php -m | grep -Ei 'pdo_mysql|curl|openssl'
```

All three required extensions must be present.

On some cPanel servers, a specific PHP binary may be required, for example:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -v
```

Use that binary in later commands if the default CLI PHP is too old.

## 3. Create the MySQL database

In cPanel → **MySQL Databases**:

1. Create a database.
2. Create a database user with a strong password.
3. Add the user to the database.
4. Grant **ALL PRIVILEGES**.

Example:

```text
Database: cpuser_pwagent
User:     cpuser_pwagent
Host:     localhost
Port:     3306
```

## 4. Create the Telegram bot

Open `@BotFather` and create a bot:

```text
/newbot
```

Save the bot token and send at least one message such as `/start` to the new bot.

Before setup registers the webhook, retrieve your numeric Telegram user ID:

```bash
BOT_TOKEN='YOUR_BOT_TOKEN'
curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getUpdates"
```

Find:

```json
"from": {
  "id": 123456789
}
```

Use that number as the administrator ID.

## 5. Clone the repository

```bash
cd ~
git clone https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot.git
cd ~/Passwall-Agent-Telegram-Bot
git status
```

## 6. Deploy the backend

```bash
mkdir -p ~/public_html/pwagent
cp -a ~/Passwall-Agent-Telegram-Bot/server/. ~/public_html/pwagent/
```

The trailing `.` copies hidden files such as `.htaccess`.

Use your real document-root path if it differs.

## 7. Run setup

```bash
cd ~/public_html/pwagent
php bin/setup.php
```

Setup asks for:

- Public HTTPS base URL
- MySQL host and port
- Database name
- Database username and password
- Telegram Bot token
- Numeric Telegram administrator ID
- Router Device ID
- Router architecture

Example base URL:

```text
https://pw.example.com
```

Do not add `/server` or `/api` if the contents of `server/` were copied directly into the document root.

Setup automatically creates/upgrades the schema, generates secrets, writes `config.php`, registers the Telegram webhook, downloads the signed update bundle, creates a single-use 30-minute provisioning token and prints the OpenWrt install command.

Never commit or publish `config.php`.

## 8. Validate the backend

```bash
php bin/doctor.php
```

Then open:

```text
https://pw.example.com/health.php
```

Expected:

```json
{
  "ok": true,
  "version": "0.6.0"
}
```

## 9. Install the OpenWrt Agent

Run the exact command printed by setup. It looks like:

```sh
PW_SERVER_BASE='https://pw.example.com' \
PW_PROVISION_TOKEN='ONE_TIME_TOKEN' \
sh -c "$(wget -qO- 'https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh')"
```

The installer detects the CPU, downloads the matching GitHub Release, verifies SHA256, consumes the one-time provisioning token, receives the Device ID and Device Secret, writes `/etc/pw-agent/config.json`, installs the Agent/LuCI integration and starts the service.

The permanent Device Secret is not included in the installation command.

If the token expires or was used:

```bash
php bin/new-provision-token.php
```

Do not rerun setup with `--force` only to get another token.

## 10. Verify the Agent

On OpenWrt:

```sh
/usr/bin/pw-agent --version
pgrep -af pw-agent
logread -e pw-agent
/usr/bin/pw-agent diagnose-json
```

A healthy diagnostic response contains:

```json
"ok": true
```

The Agent config is:

```text
/etc/pw-agent/config.json
```

It contains a secret. Do not publish the full file.

With LuCI installed, open **Services → PW Agent** and run the connection test.

## 11. Enable monitoring cron

Find your PHP CLI path:

```bash
which php
```

Add a cPanel Cron Job once per minute:

```cron
* * * * * /usr/bin/php /home/CPANEL_USER/public_html/pwagent/cron/monitor.php >/dev/null 2>&1
```

Replace both paths with the real values from your server.

## 12. Test Telegram

Send:

```text
/start
```

Start with read-only checks such as Status, Diagnostics, Monitoring, Passwall2 status and Node List. Test mutation operations only after the Agent connection is healthy.

## Updating later

```bash
cd ~/Passwall-Agent-Telegram-Bot
git pull --ff-only
```

Deploy backend updates while preserving runtime data:

```bash
rsync -a \
  --exclude='config.php' \
  --exclude='releases/*.tar.gz' \
  --exclude='releases/*.sig' \
  --exclude='releases/*.sha256' \
  ~/Passwall-Agent-Telegram-Bot/server/ \
  ~/public_html/pwagent/
```

Validate:

```bash
cd ~/public_html/pwagent
php bin/doctor.php
```

Sync an official signed Agent release:

```bash
php bin/sync-release.php --arch=armv7
```

or all architectures:

```bash
php bin/sync-release.php --all
```

See [Updates](UPDATES.md) for details.


## Adding more routers in v0.6

After the initial backend installation, do not edit `config.php` for every additional router.

From Telegram:

```text
/start
→ Fleet / Devices
→ Add Device
```

or open the **Dashboard** Mini App and use **Add device**.

Enter a display name. PW Agent creates a Device ID, encrypted Device Secret and a single-use provisioning token, then returns an installation command valid for 30 minutes.

Run that command on the new OpenWrt router. After its first poll, it appears Online in Fleet.

You can then assign:

- Group
- Location
- Tags

from the Dashboard.

### RBAC after installation

The Telegram ID used during initial setup is seeded as **Owner**. Additional users can be added from:

```text
Dashboard → Access
```

Roles are Owner, Admin, Operator and Viewer. Operator/Viewer accounts can be limited to selected Device IDs.

### v0.6 cron responsibilities

The one-minute cron is now required for three functions:

- Offline/Recovery monitoring
- Alert Rule evaluation
- Scheduled Action execution

Maintenance Mode suppresses alerts and pauses scheduled actions for the selected device.
