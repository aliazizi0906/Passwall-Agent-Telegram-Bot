# Install PW Agent

> **v1.3 release candidate:** For the PW Control Center, use the [v1.3 RC install guide](INSTALL_V1_3.md), [migration guide](UPGRADE_V1_3.md) and [implementation status](CONTROL_CENTER_PROGRESS.md). Historical sections below document the v0.6 stable series; RC is not production certified.

[Documentation Home](README.md) · [فارسی](../fa/INSTALL.md)

PW Agent v0.6 is designed to install on ordinary cPanel hosting **without SSH or cPanel Terminal**.

The backend directory is path-independent. You may upload the contents of `server/` to any web-accessible directory, for example:

```text
public_html/pw
public_html/tools/router
public_html/network/pw-agent
a subdomain document root
```

No `/home/USER/public_html/pwagent` path is required or hardcoded.

## Requirements

### cPanel / web hosting

- PHP 8.1 or newer
- MySQL or MariaDB
- PHP extensions: `pdo_mysql`, `curl`, `openssl`
- HTTPS
- cPanel File Manager
- cPanel MySQL Databases
- cPanel Cron Jobs

**cPanel Terminal, SSH and Git are optional.**

### OpenWrt router

- OpenWrt
- Passwall2 installed
- Internet access
- `wget` or `curl`
- `sha256sum`
- `tar`

The router does not need a public/static IP.

## 1. Create the MySQL database from cPanel

Open **cPanel → MySQL Databases**:

1. Create a database.
2. Create a database user with a strong password.
3. Add the user to the database.
4. Grant **ALL PRIVILEGES**.

Keep these values:

```text
MySQL host
MySQL port
Database name
Database username
Database password
```

On most cPanel hosts the MySQL host is `localhost` and the port is `3306`.

## 2. Create the Telegram bot

Open `@BotFather` in Telegram and run:

```text
/newbot
```

Save the Bot Token.

Then open the new bot and send:

```text
/start
```

You do **not** need curl or Terminal to discover your Telegram numeric ID. The Web Setup page can read recent Bot updates and show the available user IDs.

## 3. Upload the backend using cPanel File Manager

The easiest method is to download this Release asset:

```text
pw-agent-cpanel-v0.6.0.zip
```

from the project's **v0.6.0 GitHub Release**.

Create any directory you want under the domain document root, upload this ZIP there, and use **cPanel File Manager → Extract**. The ZIP contains the backend files at its root, including `setup.php` and the required `.htaccess` files.

Alternatively, download the full repository ZIP, extract it locally, open `server/`, and upload the **contents of `server/`**.

Upload/extract the backend into any directory you want under your domain document root.

Examples:

```text
public_html/pw
public_html/apps/openwrt
public_html/network/control
```

You can also use a subdomain document root.

The selected directory itself becomes the backend base URL.

Examples:

```text
public_html/pw
→ https://example.com/pw

public_html/network/control
→ https://example.com/network/control
```

Make sure hidden files such as `.htaccess` are uploaded too.

## 4. Open Web Setup

Open the directory in your browser:

```text
https://example.com/your/chosen/path/
```

If `config.php` does not exist, `index.php` automatically redirects to:

```text
setup.php
```

You may also open it directly:

```text
https://example.com/your/chosen/path/setup.php
```

The installer automatically detects the directory URL as a suggestion. It does not assume a fixed cPanel username or filesystem path.

## 5. Complete the Web Setup form

The browser wizard checks:

- PHP version
- required PHP extensions
- directory write access
- MySQL connectivity
- Telegram Bot validity

Enter:

- Public HTTPS Base URL
- MySQL host/port/database/user/password
- Telegram Bot Token
- Owner Telegram numeric ID
- Initial Router Device ID
- Router Display Name
- Router architecture
- Scheduler timezone

### Detect Telegram ID without Terminal

After entering the Bot Token, click:

**Detect my Telegram ID**

The setup page calls Telegram from the server and shows users that recently messaged the bot. Click your ID to populate the Owner field.

## 6. What Web Setup performs

When you click **Install PW Agent**, the browser installer:

1. creates/upgrades the database schema;
2. generates Device Secret, Webhook Secret and Instance Key;
3. writes `config.php` atomically;
4. creates the Fleet Device Registry;
5. creates the initial Owner RBAC account;
6. registers the Telegram Webhook;
7. creates a single-use 30-minute provisioning token;
8. optionally downloads and SHA256-verifies the selected signed Agent release bundle;
9. generates the exact OpenWrt installation command;
10. generates the exact cPanel Cron command using the **real detected filesystem path**.

After `config.php` exists, the Web Setup page locks itself and cannot overwrite the installation remotely.

## 7. Add the Cron Job without Terminal

The success page shows an exact Cron command based on the real directory in which PW Agent was uploaded.

Open:

**cPanel → Cron Jobs**

Choose **Once Per Minute** and paste the command shown by Web Setup.

Example shape only:

```cron
* * * * * '/usr/local/bin/php' '/actual/path/chosen/by/you/cron/monitor.php' >/dev/null 2>&1
```

Do not copy a fixed `/home/USER/...` path from documentation. Use the command generated by your own Setup page.

The cron drives:

- offline/recovery monitoring;
- Alert Rules;
- Scheduled Actions.

## 8. Install the Agent on OpenWrt

Copy the exact Router command shown by Web Setup and run it on the OpenWrt router.

Example shape:

```sh
PW_SERVER_BASE='https://example.com/your/chosen/path' \
PW_PROVISION_TOKEN='ONE_TIME_TOKEN' \
PW_AGENT_VERSION='v0.6.0' \
sh -c "$(wget -qO- 'https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh')"
```

The token expires after 30 minutes and can only be used once. The generated command is pinned to the backend's Agent version, so it will never silently install an older GitHub `latest` release.

The installer:

- detects router CPU architecture;
- downloads the matching official GitHub Release;
- verifies SHA256;
- consumes the provisioning token;
- receives Device ID and Device Secret;
- writes `/etc/pw-agent/config.json`;
- installs Agent and LuCI files;
- starts the service.

The permanent Device Secret is not placed in the installation command.

## 9. Validate

Open:

```text
https://example.com/your/chosen/path/health.php
```

Expected:

```json
{
  "ok": true,
  "version": "0.6.0"
}
```

On OpenWrt:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
pgrep -af pw-agent
logread -e pw-agent
```

Then send:

```text
/start
```

to the Telegram Bot.

## 10. Release Cache without cPanel Terminal

Open:

```text
Telegram → Dashboard → Release Cache
```

Owner/Admin users with Agent Update permission can:

- see which architecture bundles are cached;
- sync one architecture;
- sync all six architectures.

The Dashboard downloads official GitHub Release assets and verifies SHA256 before writing them to the protected backend release cache.

This replaces the old requirement to run `php bin/sync-release.php` from cPanel Terminal.

## 11. Add more routers

From Telegram:

```text
/start
→ Fleet / Devices
→ Add Device
```

or:

```text
Dashboard → Devices → Add device
```

PW Agent creates a new encrypted Device Secret and a new one-time provisioning command.

No manual edit of `config.php` is required.

## 12. Updating the backend without Terminal

Use cPanel File Manager:

1. Download the newer repository ZIP.
2. Extract it into a temporary directory.
3. Copy the contents of its `server/` directory over your existing PW Agent backend directory.
4. Do **not** delete the destination directory first.
5. Keep the existing runtime `config.php`.
6. Open `health.php` once to apply lazy schema migrations.
7. Open **Dashboard → Release Cache** and sync the required Agent bundle(s).

The repository does not contain your runtime `config.php`, so a normal source-file copy does not replace it.

For upgrade details see [Updates](UPDATES.md) and [Migration](MIGRATION.md).

## Optional CLI workflow

If a host does provide SSH/Terminal, the old scripts remain supported:

```bash
php bin/setup.php
php bin/doctor.php
php bin/sync-release.php --all
```

They are optional; the cPanel web installation does not depend on them.
