# Security

[Documentation Home](README.md) · [فارسی](../fa/SECURITY.md)

PW Agent is designed around outbound router communication and explicit command handling. It does not require a public router IP or a WAN SSH exposure.

## Device authentication

Each router has:

- Device ID
- Device Secret

Agent requests use HMAC-SHA256. Backend responses are signed as well, so both request authentication and response authenticity are checked.

Keep `server/config.php` and `/etc/pw-agent/config.json` private.

## Telegram access

Telegram access is limited using numeric user IDs configured in the backend allowlist.

The Mini App additionally validates Telegram WebApp `initData` and its authentication age.

## Queue encryption

Sensitive command parameters are not kept as plain JSON in MySQL.

For sensitive actions, the backend derives a queue key from the Device Secret and protects the payload with AES-256-GCM.

Terminal command/result data is scrubbed from the queue after completion where applicable.

## Provisioning token

The public installer uses a one-time provisioning token:

- random token
- stored as SHA256 hash in MySQL
- default 30-minute lifetime
- consumed once
- used only to deliver the permanent router configuration

This avoids placing the permanent Device Secret directly in the installation command.

## Idempotency and replay safety

Mutation commands have idempotency keys and a local Agent journal. This protects against accidental repeated mutation when execution succeeds but the result delivery fails.

Leases expire and retries are limited.

## Update trust

Official releases are protected by:

1. SHA256 integrity verification
2. Ed25519 release signature verification

The official Ed25519 public key is committed as:

```text
UPDATE_PUBLIC_KEY.b64
```

and is also embedded in the Agent.

The private signing key must never be committed. It is held only by the project maintainer as the GitHub Actions secret:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

Fork maintainers that want independent release trust should use their own key pair.

## SSH Console

The Telegram SSH Console does not require opening SSH on WAN.

The Agent connects to local Dropbear on the router. The feature is a bounded command console, not a persistent interactive PTY.

## Operational recommendations

- use HTTPS with a valid certificate;
- use a dedicated backend subdomain/directory;
- keep `config.php` outside version control;
- use strong MySQL credentials;
- restrict cPanel account access;
- keep the Telegram allowlist minimal;
- rotate Bot tokens if exposed;
- do not paste Device Secrets into issues or public chats;
- review mutation operations before using them on production routers.


## v0.6 Fleet secrets and RBAC

New devices are stored in the database-backed Fleet Registry. Their Device Secrets are encrypted at rest with AES-256-GCM using an instance key generated during a fresh setup.

For upgrades from v0.5 where `security.instance_key` does not yet exist, v0.6 derives a stable migration key from existing instance configuration. Do not later change the effective instance key without a dedicated migration because database-encrypted Device Secrets depend on it.

RBAC roles are:

- Owner
- Admin
- Operator
- Viewer

Permissions are evaluated before commands enter the queue. Operator and Viewer accounts may also be restricted by Device ID scope.

The last enabled Owner cannot be demoted or disabled through the Dashboard.

## Config History confidentiality

Rollback snapshots are encrypted by the Agent with AES-GCM using a key derived from the router Device Secret.

The backend stores only the encrypted snapshot. Audit/config diffs expose UCI option names and change type, not their values.

Rollback payloads are additionally treated as sensitive queue parameters and are scrubbed after command completion.

## Dashboard authentication

The Fleet Dashboard is a Telegram Mini App. Each API request validates Telegram `initData`, its signature and authentication age, then applies RBAC and Device Scope checks.

Opening the Dashboard URL directly in a normal browser does not grant API access.
