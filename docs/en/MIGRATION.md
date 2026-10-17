# Migration

[Documentation Home](README.md) · [فارسی](../fa/MIGRATION.md)

## Upgrade to v0.5.x from an existing installation

v0.5 changes the project from a device-specific deployment into a reusable public installation model and adds queue hardening, encrypted sensitive parameters, signed updates, monitoring and multi-architecture releases.

### 1. Preserve runtime configuration

Do not overwrite your existing:

```text
server/config.php
```

Also preserve runtime release files if you already synchronized them.

### 2. Update backend files

Update the source checkout and deploy the new `server/` files while excluding `config.php` and release runtime assets.

### 3. Apply schema changes

The configured MySQL account should have `ALTER` and `CREATE` privileges for the first v0.5 request so `ensure_v050_schema()` can apply required changes.

Alternatively, import:

```text
server/schema.sql
```

manually.

v0.5 adds command lease/idempotency fields and tables for metrics, events, alert state and provisioning tokens.

### 4. Synchronize the signed release

Example for ARMv7:

```bash
php bin/sync-release.php --arch=armv7
```

Supported values:

```text
armv7
arm64
amd64
386
mips
mipsle
```

### 5. Upgrade/reinstall the router Agent

Use the v0.5 release or generate a new provisioning token:

```bash
php bin/new-provision-token.php
```

Then execute the generated install command on OpenWrt.

The official Ed25519 update public key is embedded in the v0.5 Agent. Normal users do not need to generate signing keys.

### 6. Add monitoring cron

```cron
* * * * * /usr/bin/php /absolute/path/to/cron/monitor.php >/dev/null 2>&1
```

### 7. Validate

Backend:

```bash
php bin/doctor.php
```

Router:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

Telegram:

```text
/start
```

## Migrating a fork with independent update trust

A fork that wants to publish independently signed Agent releases should:

1. generate a new Ed25519 key pair;
2. replace `UPDATE_PUBLIC_KEY.b64`;
3. update the embedded official public key in the Agent source;
4. store the matching private key only in its GitHub Actions secret;
5. rebuild and publish its own releases.

Never commit the private signing key.
