# Legacy migration guide — v0.5.x to v0.6.x

> **Latest public pre-release: v1.3.0-rc.2.** This page documents only the historical v0.5→v0.6 migration. For the current v0.6→v1.3 RC path, use [Upgrade v1.3 RC](UPGRADE_V1_3.md), [Install v1.3 RC](INSTALL_V1_3.md) and [implementation status](CONTROL_CENTER_PROGRESS.md). RC is not production-certified.

[Documentation Home](README.md) · [فارسی](../fa/MIGRATION.md)

## Historical upgrade: v0.5.x → v0.6.x

v0.6 keeps the outbound Agent protocol from v0.5 and adds Fleet Management, RBAC, Audit, Config History/Rollback, advanced Monitoring, Maintenance and Scheduling.

The migration can be completed from cPanel **without SSH or Terminal**.

## 1. Preserve runtime files

Keep the existing:

```text
config.php
releases/
```

Do not replace `config.php` with `config.example.php`.

The physical installation directory does not matter. v0.6 continues to run from the directory in which the backend is already deployed.

## 2. Deploy v0.6 using File Manager

For this historical v0.5→v0.6 migration, download the repository source archive pinned to tag `v0.6.0` (not `main` or the latest release): [v0.6.0 source ZIP](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/archive/refs/tags/v0.6.0.zip). Extract it into a temporary directory and copy the contents of its `server/` directory over the existing backend directory.

Do not delete the destination directory first.

Examples of valid existing installations:

```text
public_html/pw
public_html/tools/openwrt
public_html/network/control
a subdomain document root
```

No fixed `/home/USER/public_html/pwagent` path is required.

## 3. Apply schema migration

Open:

```text
https://YOUR-BACKEND-URL/health.php
```

The first v0.6 request runs `ensure_v060_schema()`.

The configured MySQL user needs CREATE and ALTER privileges while the migration runs.

v0.6 adds platform data such as:

- Fleet Device Registry
- Device Groups
- RBAC Users and active-device state
- Audit Log
- encrypted Config History metadata
- Alert Rules
- Maintenance state
- Scheduled Actions
- additional Monitoring metrics

Existing devices in `config.php` are seeded into the Fleet Registry.

Existing configured Telegram users are seeded into RBAC. The first configured allowed user becomes Owner and additional configured users become Admin.

## 4. Instance-key behavior

Fresh v0.6 Web Setup installations generate `security.instance_key`.

For an existing v0.5 installation that does not have that field, v0.6 uses a stable backward-compatible derivation so migration can happen without manually editing `config.php`.

Once database-backed Device Secrets have been encrypted, do not arbitrarily change the effective instance key.

## 5. Sync v0.6 Agent packages without Terminal

After the backend migration, open:

```text
Telegram → Dashboard → Release Cache
```

Sync the architecture used by the router, or sync all architectures.

The backend verifies SHA256 before storing each package.

## 6. Upgrade the router Agent

After the v0.6 package is available in the Release Cache, use the normal Agent update flow from Telegram/Dashboard.

If a router must be reinstalled instead, select it in:

```text
Dashboard → Devices → Provision / Reinstall
```

A new single-use 30-minute provisioning command is generated without cPanel Terminal.

## 7. Cron

The existing one-minute Cron Job now drives:

- Offline/Recovery monitoring
- Alert Rules
- Scheduled Actions

If the old Cron path is wrong because the backend directory moved, open `setup.php` only on a clean install or use cPanel File Manager to determine the current directory and update **cPanel → Cron Jobs** accordingly.

New Web Setup installations print the exact absolute Cron command automatically.

## 8. Validate

Browser:

```text
https://YOUR-BACKEND-URL/health.php
```

Telegram:

```text
/start
```

Dashboard checks:

```text
Devices
Monitoring
Audit & Rollback
Release Cache
```

Router:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

## Config History compatibility

Config History begins only after the router runs Agent v0.6. Older v0.5 mutations do not have rollback snapshots.

Rollback snapshots are encrypted by the Agent with the router-only key at:

```text
/etc/pw-agent/history.key
```

The backend stores ciphertext and does not receive this key during normal Config History operation.

## Optional CLI migration

If SSH/Terminal exists, the CLI workflow remains available, but it is optional:

```bash
php bin/doctor.php
php bin/sync-release.php --all
```
