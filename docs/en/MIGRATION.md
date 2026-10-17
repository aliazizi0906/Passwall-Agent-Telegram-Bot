# Migration

[Documentation Home](README.md) · [فارسی](../fa/MIGRATION.md)

## Upgrade from v0.5.x to v0.6.x

v0.6 keeps the v0.5 outbound Agent protocol and adds the Fleet platform around it. Existing v0.5 Device IDs and Device Secrets are migrated into the new database-backed Device Registry automatically.

### 1. Preserve runtime files

Keep the deployed config.php and the runtime release directory.

Do not replace config.php with config.example.php.

### 2. Deploy the v0.6 backend

Update the source checkout and copy the new server files while excluding local runtime files.

Example:

~~~bash
cd ~/Passwall-Agent-Telegram-Bot
git pull --ff-only

rsync -a \
  --exclude='config.php' \
  --exclude='releases/*.tar.gz' \
  --exclude='releases/*.sig' \
  --exclude='releases/*.sha256' \
  ~/Passwall-Agent-Telegram-Bot/server/ \
  ~/public_html/pwagent/
~~~

### 3. Apply the v0.6 schema

The first v0.6 backend request runs ensure_v060_schema().

The MySQL user must have CREATE and ALTER privileges during migration. You may alternatively import server/schema.sql manually.

New v0.6 tables include:

- pw_device_registry
- pw_device_groups
- pw_users
- pw_user_state
- pw_audit_log
- pw_config_changes
- pw_alert_rules
- pw_maintenance
- pw_schedules

Existing configured Telegram users are seeded into RBAC. The first configured allowed user becomes Owner; additional configured users become Admin.

Existing configured devices are seeded into the Fleet Registry.

### 4. Instance-key behavior

New installations generate security.instance_key automatically.

Existing v0.5 installations can migrate without immediately editing config.php; v0.6 uses a stable backward-compatible key derivation for Fleet secret storage when no explicit instance key exists.

Do not later replace the key arbitrarily after database-backed Device Secrets have been created, or those encrypted secrets will no longer decrypt.

### 5. Update the Agent

Sync the v0.6 signed package for the router architecture, then use the normal Agent update path or reinstall using a new provisioning token.

Example:

~~~bash
php bin/sync-release.php --arch=armv7
~~~

The v0.6 Agent adds advanced heartbeat telemetry and encrypted configuration-history snapshots used by Rollback.

### 6. Cron is required

The existing one-minute monitoring cron now also runs Alert Rules and Scheduled Actions:

~~~cron
* * * * * /usr/bin/php /absolute/path/to/cron/monitor.php >/dev/null 2>&1
~~~

Maintenance Mode suppresses alerts and pauses due schedules for the affected device.

### 7. Validate

Backend:

~~~bash
php bin/doctor.php
~~~

Router:

~~~sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
~~~

Telegram:

~~~text
/start
~~~

Confirm that the Fleet menu shows the existing router, then open the Dashboard and verify Monitoring.

## Additional routers after migration

Do not edit config.php to add each new router.

Use Telegram:

~~~text
/start → Fleet / Devices → Add Device
~~~

or use the Mini App Dashboard.

The generated provisioning command contains a one-time token, not the permanent Device Secret.

## Rollback considerations

Config History begins only after the router is running the v0.6 Agent. Older v0.5 mutations cannot be reconstructed retroactively.

Rollback snapshots are encrypted by the Agent using a router-only key in `/etc/pw-agent/history.key`. The backend does not receive this key during normal config-history operation.
