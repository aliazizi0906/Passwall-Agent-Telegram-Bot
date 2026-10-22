# Troubleshooting

> **Current public pre-release: v1.3.0-rc.2.** Some troubleshooting sections below refer to the legacy v0.6 Agent/backend. For the current RC install and upgrade path, use [Install](INSTALL_V1_3.md) and [Upgrade](UPGRADE_V1_3.md).

[Documentation Home](README.md) · [فارسی](../fa/TROUBLESHOOTING.md)

## Web Setup does not open

Open the exact directory where the contents of `server/` were uploaded.

Examples:

```text
https://example.com/pw/
https://example.com/tools/router/
https://router.example.com/
```

If `config.php` does not exist, `index.php` redirects to `setup.php`.

The backend does not require a fixed cPanel path.

If the page returns HTTP 500 before setup, check from cPanel that:

- PHP is 8.1 or newer;
- `pdo_mysql`, `curl` and `openssl` are enabled;
- `.htaccess` was uploaded;
- the selected PHP version applies to this directory.

## Web Setup says the directory is not writable

Use cPanel File Manager permissions/ownership for the directory containing `setup.php`.

PW Agent needs to create `config.php` and runtime files in protected subdirectories.

Do not make the entire web directory world-writable.

## Base URL is wrong

Web Setup detects a URL suggestion from the current request, but you must confirm the public HTTPS address.

For a nested installation:

```text
public_html/network/control
```

the Base URL should be:

```text
https://example.com/network/control
```

Do not append `/api`, `/server`, `/bot` or `/setup.php` unless `server/` itself was intentionally uploaded under that URL path.

## Telegram ID detection returns no users

Before clicking **Detect my Telegram ID**:

1. open the newly-created Bot;
2. send `/start`;
3. return to Web Setup and retry.

If an old webhook is already registered for the Bot, `getUpdates` may not contain new messages. For a fresh installation, use a fresh Bot or remove the old webhook first.

## Setup is locked

Once `config.php` exists, `setup.php` intentionally becomes read-only/locked so a remote visitor cannot overwrite your installation.

Do not rerun setup to add routers.

Use:

```text
Telegram → Fleet / Devices → Add Device
```

or:

```text
Dashboard → Devices → Add device
```

## Provisioning token expired

Select the existing device in:

```text
Dashboard → Devices
```

and use:

```text
Provision / Reinstall
```

PW Agent revokes previous unused tokens and generates a new one-time 30-minute command.

No cPanel Terminal is required.

## Release is missing from the backend

Open:

```text
Dashboard → Release Cache
```

and sync the required architecture, or use **Sync all architectures**.

If sync fails, verify that the matching `vX.Y.Z` GitHub Release actually contains:

```text
pw-agent-openwrt-ARCH.tar.gz
pw-agent-openwrt-ARCH.tar.gz.sha256
pw-agent-openwrt-ARCH.tar.gz.sig
```

## Health endpoint returns 500

Open:

```text
https://YOUR-BACKEND-URL/health.php
```

A 500 normally means database/schema initialization failed.

Check in Web Setup/cPanel:

- MySQL hostname and port;
- database name;
- database username/password;
- ALL PRIVILEGES for the database user;
- CREATE and ALTER privileges during migration.

## Cron/Alerts/Schedules do not run

Use **cPanel → Cron Jobs**.

For fresh installs, copy the exact command shown by Web Setup. It uses the actual filesystem path and detected PHP CLI path.

The task must run once per minute.

Maintenance Mode intentionally suppresses alerts and pauses due schedules for that device.

## Dashboard says Unauthorized

The Dashboard must be opened through the Telegram Mini App button so valid Telegram `initData` is present.

Also verify:

- the Telegram user is enabled in RBAC;
- the role grants the requested permission;
- Device Scope includes the active router.

## Device is missing from Fleet

Open `health.php` once so schema migration/seed logic runs.

Then send `/start` again.

Existing v0.5 devices configured in `config.php` are seeded into the v0.6 Fleet Registry automatically.

## Router Agent is offline

On OpenWrt:

```sh
pgrep -af pw-agent
logread -e pw-agent
/usr/bin/pw-agent diagnose-json
/etc/init.d/pw-agent restart
```

Check:

- Server URL
- Device ID
- HMAC authentication
- signed response validation
- HTTPS reachability
- router/server clocks

## Wrong router architecture

On OpenWrt:

```sh
uname -m
```

Mapping:

```text
armv7l  -> armv7
aarch64 -> arm64
x86_64  -> amd64
i686    -> 386
mips    -> mips
mipsel  -> mipsle
```

The remote installer normally detects this automatically.

## Passwall2 not found

On OpenWrt:

```sh
test -f /etc/config/passwall2 && echo OK || echo NOT_FOUND
```

Passwall-specific operations require Passwall2 to be installed.

## Rollback is unavailable

Config History starts only after the router runs Agent v0.6. Older v0.5 changes do not have rollback snapshots.

For a v0.6 change, confirm that the original command completed successfully and appears in Config History.

Rollback snapshots are encrypted with the router-only key:

```text
/etc/pw-agent/history.key
```

A snapshot copied to another router cannot be decrypted there.

## Optional CLI diagnostics

If the hosting account has Terminal, you may additionally run:

```bash
php bin/doctor.php
```

It is optional and not required for normal cPanel installation.

## Safe information to share in bug reports

Usually safe:

- Agent version
- OpenWrt version
- architecture
- sanitized errors
- `diagnose-json` with secrets removed
- relevant logs with credentials removed

Never share:

- Telegram Bot Token
- Device Secret
- database password
- private Ed25519 signing key
- full `config.php`
- full `/etc/pw-agent/config.json`
- `/etc/pw-agent/history.key`
