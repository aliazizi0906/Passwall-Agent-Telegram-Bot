# v0.5.0 migration

v0.5 changes the project from a device-specific deployment into a reusable public installation flow.

## Existing installations

1. Preserve your current `server/config.php`.
2. Deploy the new `server/` files.
3. Ensure the MySQL account has `ALTER` and `CREATE` privileges for the first v0.5 request, or import `server/schema.sql` manually.
4. Sync the signed update bundle for your router architecture:

```bash
php server/bin/sync-release.php --arch=armv7
```

Supported values are `armv7`, `arm64`, `amd64`, `386`, `mips` and `mipsle`.

5. Upgrade the router once using the v0.5 release bundle or reinstall through the public provisioning flow.

The Agent contains the official Ed25519 update public key, so existing users do not need to add `update_public_key` to their config.

## New installations

Use:

```bash
php server/bin/setup.php
```

The setup utility creates the database schema, instance configuration, Telegram webhook, device credentials and a one-time provisioning token.

Then run the printed command on the OpenWrt router.

## Monitoring cron

Run once per minute:

```cron
* * * * * /usr/bin/php /absolute/path/to/server/cron/monitor.php >/dev/null 2>&1
```

## Official Ed25519 signing

Normal users do **not** create signing keys.

The official update public key is committed as `UPDATE_PUBLIC_KEY.b64` and embedded in the Agent. Official GitHub Releases are signed by the repository maintainer.

The maintainer stores the matching private key only as the GitHub Actions secret:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

The private key must never be committed.

## Forks with independent update trust

A fork that wants to publish independently signed updates should:

1. generate its own Ed25519 key pair;
2. replace `UPDATE_PUBLIC_KEY.b64`;
3. replace `officialUpdatePublicKeyV5` with the matching raw public key;
4. configure its own `UPDATE_SIGNING_PRIVATE_KEY_PEM_B64` Actions secret;
5. optionally set `update_public_key` in existing router configs during migration.

The Release workflow verifies that the configured private key matches the published public key before creating assets.
