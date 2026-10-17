# v0.5.0 hardening migration

v0.5 introduces resilient command leasing/idempotency, encrypted sensitive queue parameters, Ed25519-signed agent updates, scored failover, metrics/events, CI/CD and automated tests.

## Database
The backend calls `ensure_v050_schema()` and adds the required columns/tables automatically when the configured DB user has ALTER/CREATE privileges. The same definitions are also present in `server/schema.sql`.

## Monitoring cron
Run once per minute from cPanel:

```sh
/usr/local/bin/php /home/USER/public_html/routerbot/cron/monitor.php
```

The default offline threshold is 300 seconds and can be overridden with:

```php
'monitoring' => ['offline_after_seconds' => 300],
```

## Ed25519 update signing
Generate a dedicated Ed25519 key and keep the private key offline / in the GitHub Actions secret `UPDATE_SIGNING_PRIVATE_KEY_PEM_B64`.

Example:

```sh
openssl genpkey -algorithm ED25519 -out update-ed25519.pem
openssl pkey -in update-ed25519.pem -pubout -outform DER | tail -c 32 | base64
base64 -w0 update-ed25519.pem
```

Put the raw public key base64 in OpenWrt `update_public_key`. Put the base64-encoded PEM private key in the GitHub Actions secret.

The cPanel release directory must contain both the release tarball and its matching `.sig` file. The Agent refuses an update if the Ed25519 signature is missing or invalid.
