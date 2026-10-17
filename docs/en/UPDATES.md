# Updates and Releases

[Documentation Home](README.md) · [فارسی](../fa/UPDATES.md)

## User-side update model

There are two parts to keep current:

1. PHP/MySQL backend files on the hosting server
2. OpenWrt Agent/LuCI package on the router

## Update the backend source

Refresh the Git checkout:

```bash
cd ~/Passwall-Agent-Telegram-Bot
git pull --ff-only
```

Deploy while preserving local runtime files:

```bash
rsync -a \
  --exclude='config.php' \
  --exclude='releases/*.tar.gz' \
  --exclude='releases/*.sig' \
  --exclude='releases/*.sha256' \
  ~/Passwall-Agent-Telegram-Bot/server/ \
  ~/public_html/pwagent/
```

Then run:

```bash
cd ~/public_html/pwagent
php bin/doctor.php
```

## Synchronize Agent release packages

For one architecture:

```bash
php bin/sync-release.php --arch=armv7
```

For all supported architectures:

```bash
php bin/sync-release.php --all
```

The backend stores the bundle and matching signature under `server/releases/`.

## Agent self-update

The Agent can check the backend for a newer release.

The backend returns architecture-specific package metadata. The Agent verifies the SHA256 digest and Ed25519 signature before installation.

The trusted official public key is embedded in the Agent.

## Official release process for maintainers

The official GitHub Actions Release workflow builds:

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

The maintainer configures one repository secret:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

Then run:

```text
GitHub → Actions → Release → Run workflow
Branch: main
version: vX.Y.Z
```

The workflow checks that the version matches `app_version()`, verifies that the private key matches `UPDATE_PUBLIC_KEY.b64`, builds all architectures, signs the bundles and creates the GitHub Release.

## Forks

A fork that wants independent signed releases should replace the official public key and use a new Ed25519 private key in its own GitHub Actions secret.
