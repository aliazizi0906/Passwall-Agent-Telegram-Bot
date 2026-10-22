# Updates and Releases

> **Latest public pre-release: v1.3.0-rc.2.** This page documents the legacy v0.6 update model; for the new control center use [Install v1.3 RC](INSTALL_V1_3.md), [Upgrade v0.6 → v1.3 RC](UPGRADE_V1_3.md) and [Implementation status](CONTROL_CENTER_PROGRESS.md). RC is not production-certified.

[Documentation Home](README.md) · [فارسی](../fa/UPDATES.md)

This page documents the legacy v0.6 stable update workflow. The latest public pre-release is v1.3.0-rc.2; use [its install guide](INSTALL_V1_3.md) and [upgrade guide](UPGRADE_V1_3.md) for the new control center. SSH, Git and cPanel Terminal remain optional.

## What is updated

There are two independent update layers:

1. PHP/MySQL backend files on the hosting account
2. Agent/LuCI packages on OpenWrt routers

## Update the backend without Terminal

Use **cPanel File Manager**:

1. Download the newer repository ZIP from GitHub.
2. Extract it into a temporary directory.
3. Open the extracted `server/` directory.
4. Copy its contents over the existing PW Agent backend directory.
5. Do not delete the destination directory first.
6. Keep the runtime `config.php`.
7. Open `health.php` once after the copy.

The backend may be installed in any document-root directory or subdirectory. There is no required `public_html/pwagent` path.

Examples:

```text
https://example.com/pw
https://example.com/tools/router
https://router.example.com/
```

The first request after an upgrade applies compatible lazy schema migrations.

## Sync Agent packages without Terminal

Open the Telegram Mini App:

```text
Dashboard → Release Cache
```

Users with the **Agent Update** permission can:

- view cache state for each architecture;
- sync one architecture;
- sync all supported architectures.

The backend downloads the official GitHub Release assets and verifies SHA256 before storing them in the protected release cache.

Supported architectures:

```text
armv7
arm64
amd64
386
mips
mipsle
```

## Re-provision a router without Terminal

If a provisioning token expires, do not reinstall the backend and do not use cPanel Terminal.

Open:

```text
Dashboard → Devices
```

select the router and use:

```text
Provision / Reinstall
```

PW Agent revokes previous unused tokens for that device and generates a new single-use 30-minute installation command.

## Agent self-update

The router can check the backend for a newer architecture-specific Agent package.

Before installation the Agent verifies:

1. SHA256 integrity
2. Ed25519 signature authenticity

The official update public key is embedded in the Agent.

## Optional CLI workflow

If the host provides SSH/Terminal, the CLI scripts remain available:

```bash
php bin/doctor.php
php bin/sync-release.php --arch=armv7
php bin/sync-release.php --all
```

They are not required for normal cPanel operation.

## Official release process for maintainers

The GitHub Actions Release workflow builds:

- armv7
- arm64
- amd64
- 386
- mips
- mipsle

For each architecture it publishes:

```text
pw-agent-openwrt-ARCH.tar.gz
pw-agent-openwrt-ARCH.tar.gz.sha256
pw-agent-openwrt-ARCH.tar.gz.sig
```

The maintainer configures:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

Then runs:

```text
GitHub → Actions → Release → Run workflow
Branch: main
version: vX.Y.Z
```

The workflow verifies that the requested version matches the source version, checks the private signing key against `UPDATE_PUBLIC_KEY.b64`, builds all architectures, signs the bundles and creates the GitHub Release.

## Forks

A fork that wants independent signed releases should replace the official update public key and store its own matching private key only in its GitHub Actions secret.
