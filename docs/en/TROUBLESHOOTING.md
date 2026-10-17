# Troubleshooting

[Documentation Home](README.md) · [فارسی](../fa/TROUBLESHOOTING.md)

## Backend doctor

Start with:

```bash
cd /path/to/deployed/backend
php bin/doctor.php
```

This checks PHP, required extensions, HTTPS configuration, database connectivity/schema, default device configuration, Telegram API/webhook and signed update bundle availability.

## Health endpoint

Open:

```text
https://YOUR-DOMAIN/health.php
```

Expected:

```json
{"ok":true,"version":"0.6.0",...}
```

A 500 response normally means the backend could not initialize the database/schema.

## PHP CLI is too old

Check:

```bash
php -v
```

On cPanel, try an EA-PHP binary such as:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -v
```

Use that full path for setup, doctor and cron if required.

## Missing PHP extension

Check:

```bash
php -m | grep -Ei 'pdo_mysql|curl|openssl'
```

All three are required.

## Telegram webhook problems

Run doctor first.

If the Bot token was rotated, update `config.php` and register the webhook again through setup/reconfiguration.

The webhook URL must match:

```text
https://YOUR-DOMAIN/bot/webhook.php
```

## Provisioning token expired

Do not recreate the whole installation.

Generate a new one:

```bash
php bin/new-provision-token.php
```

Tokens are single-use and expire after 30 minutes.

## Release download failed during setup

Confirm that the requested architecture exists in the current GitHub Release.

Then retry:

```bash
php bin/sync-release.php --arch=armv7
```

Use the architecture that matches the router.

## Agent service problems

On OpenWrt:

```sh
pgrep -af pw-agent
logread -e pw-agent
/etc/init.d/pw-agent restart
```

## Agent connection diagnostics

```sh
/usr/bin/pw-agent diagnose-json
```

Check:

- server URL
- Device ID
- HMAC authentication
- response signature
- round-trip latency

With LuCI installed, use **Services → PW Agent**.

## Wrong architecture

Check:

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

Confirm:

```sh
test -f /etc/config/passwall2 && echo OK || echo NOT_FOUND
```

PW Agent requires Passwall2 for Passwall-specific features.

## Telegram responds but router data is stale

Check Agent logs and `diagnose-json`. Then confirm the router can reach the backend over HTTPS and that the server clock/router clock are reasonable.

## Monitoring does not alert

Verify cron manually:

```bash
php cron/monitor.php
```

Then confirm the cPanel Cron Job uses the correct absolute PHP and backend paths.

## Safe information to share in bug reports

Usually safe:

- Agent version
- OpenWrt version
- architecture
- sanitized error messages
- `diagnose-json` with secrets removed
- relevant log lines with tokens/credentials removed

Never share:

- Telegram Bot Token
- Device Secret
- database password
- private Ed25519 signing key
- full `config.php`
- full `/etc/pw-agent/config.json`


## Device is missing from Fleet

Run:

```bash
php bin/doctor.php
```

Then verify that `pw_device_registry` contains an enabled row for the Device ID and that the router is polling the backend.

Existing v0.5 devices should be seeded automatically when the v0.6 schema initializes.

## Dashboard says Unauthorized

The Dashboard must be opened from the Telegram Mini App button. A normal browser does not provide valid Telegram `initData`.

Also verify:

- the Telegram user exists in `pw_users`;
- the user is enabled;
- the user's role grants the requested permission;
- the Device Scope includes the active router.

## Scheduled actions do not run

Verify the one-minute cron is active:

```bash
php cron/monitor.php
```

Check whether Maintenance Mode is enabled for the device. Due schedules are intentionally paused during maintenance.

Also verify the schedule creator still has permission to execute the scheduled action.

## Alert rule does not trigger

Check that:

- cron runs every minute;
- recent metrics exist in `pw_metrics`;
- the alert is enabled;
- the threshold/duration are satisfied;
- cooldown has expired;
- Maintenance Mode is not active.

## Rollback is unavailable

Config History starts only after the router runs Agent v0.6. Older changes do not have rollback snapshots.

For a v0.6 change, verify that the original command finished successfully and that a record exists in `pw_config_changes`.

A rollback snapshot is tied cryptographically to the same Device Secret. Moving the snapshot to another router will fail authentication.
