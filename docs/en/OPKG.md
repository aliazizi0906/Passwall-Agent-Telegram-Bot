# Install PW Agent with opkg

[Documentation home](README.md) · [فارسی](../fa/OPKG.md) · [APK v3 for OpenWrt 25.12+](APK.md) · [GitHub Releases](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases)

**OpenWrt 24.10 and older (legacy opkg) only.** OpenWrt **25.12.2 uses APK v3**, and `opkg` is not available. For OpenWrt 25.12.2 follow the [APK v3 installation guide](APK.md) instead. The `.ipk` and APK packages are different formats and must never be interchanged.

The project publishes architecture-specific `.ipk` packages and a signed `Packages` index alongside each official GitHub Release. The feed reuses the same Ed25519 private key and public key as the existing Agent release bundles. The workflow verifies the source bundle checksums and signatures before building packages.

## Compatibility and architecture names

These packages target the Go/Linux architectures already used by the official release bundles. OpenWrt's package architecture names are target-specific, so the feed uses explicit custom aliases to avoid pretending that `arm64` or `mipsle` is an official OpenWrt target name:

| Release target | opkg architecture alias | Typical `uname -m` |
|---|---|---|
| `armv7` | `pw_agent_armv7` | `armv7l` |
| `arm64` | `pw_agent_arm64` | `aarch64` |
| `amd64` | `pw_agent_amd64` | `x86_64` |
| `386` | `pw_agent_386` | `i386` / `i686` |
| `mips` | `pw_agent_mips` | `mips` (big-endian) |
| `mipsle` | `pw_agent_mipsle` | `mipsel` (little-endian) |

The package's pre-install script checks the ELF architecture and the router's reported architecture. MIPS packages additionally check `opkg print-architecture` to avoid installing the wrong endianness. A Go architecture is still broader than an OpenWrt target/subtarget; confirm that your device can run the corresponding release binary before using this feed on a production router.

## 1. Add the official opkg public key

The feed's `Packages.sig` uses OpenWrt's native usign envelope, derived from the existing `UPDATE_PUBLIC_KEY.b64`. The public key ID is `1e2f57b76d1074e1` for the currently published key. Install it before running `opkg update`:

```sh
mkdir -p /etc/opkg/keys
wget -O /etc/opkg/keys/1e2f57b76d1074e1 \
  https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0/pw-agent-opkg-key-1e2f57b76d1074e1.pub
chmod 0644 /etc/opkg/keys/1e2f57b76d1074e1
```

The public key is not secret. If the project's update key is rotated, the key ID and public key file will change; use the key published with the release you intend to install and verify the key transition through a trusted project channel.

## 2. Register the matching architecture alias

Check the router first:

```sh
uname -m
opkg print-architecture
```

Add exactly one line for the matching package target to `/etc/opkg.conf`. For example, for an ARM64 router:

```text
arch pw_agent_arm64 100
```

Use `pw_agent_armv7`, `pw_agent_amd64`, `pw_agent_386`, `pw_agent_mips`, or `pw_agent_mipsle` for the corresponding target in the table above. Do not add every alias to every router.

## 3. Add the version-pinned feed

Replace `v0.7.0` with the release you want to install. The feed URL points directly at the release asset directory; GitHub serves `Packages.gz`, `Packages.sig`, and the `.ipk` files by their filenames.

```sh
cat >> /etc/opkg/customfeeds.conf <<'EOF'
src/gz pw-agent https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0
EOF

opkg update
opkg info pw-agent
opkg install pw-agent
```

Keep OpenWrt's package signature checking enabled. The workflow creates `Packages.sig` in native usign format and validates it with upstream `usign` before uploading the feed. If `opkg update` reports a signature or architecture error, stop and fix the key/alias configuration; do not disable signature checking to work around it.

## 4. Configure and start the agent

If `/etc/pw-agent/config.json` does not already exist, package installation creates it from the example and leaves the service stopped. Edit it with the real values from your PW Agent backend:

```sh
vi /etc/pw-agent/config.json
chmod 0600 /etc/pw-agent/config.json
```

Make sure Passwall2 is installed and `/etc/config/passwall2` exists. Then enable and start the service:

```sh
/etc/init.d/pw-agent enable
/etc/init.d/pw-agent restart
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

The package does not overwrite an existing `/etc/pw-agent/config.json` during upgrades. Removing the package stops and disables the service but preserves that configuration and its credentials.

This IPK installs the core service, init script, and example configuration. It does not add a LuCI page; the current release bundle's menu entry references a view that is not present in the repository.

## Offline installation

Each release also includes `pw-agent-opkg-feed-vX.Y.Z.tar.gz`, its SHA256 file, and an Ed25519 signature made with the existing release key. This archive contains the IPKs, `Packages`, `Packages.gz`, the usign `Packages.sig`, and the public key file. Verify the archive's SHA256 and Ed25519 signature using the same release verification process used for the official Agent bundles before extracting it and installing the matching local `.ipk` with `opkg install /path/to/package.ipk`.

On a trusted Linux workstation, download the archive, its `.sha256` and `.sig` assets, and the matching `UPDATE_PUBLIC_KEY.b64` from the source tag. Run the verifier from a trusted checkout that contains `packaging/verify-feed-archive.sh` (the tag itself contains it once the packaging change is included):

```sh
sh packaging/verify-feed-archive.sh \
  pw-agent-opkg-feed-v0.7.0.tar.gz \
  pw-agent-opkg-feed-v0.7.0.tar.gz.sha256 \
  pw-agent-opkg-feed-v0.7.0.tar.gz.sig \
  UPDATE_PUBLIC_KEY.b64
```

Extract the archive only after the verifier succeeds. The signed archive is the recommended offline route when a router cannot reach the GitHub Release feed.

## Maintainer workflow

After the official `Release` workflow succeeds for a pushed version tag, GitHub runs `.github/workflows/package-feed.yml`. It downloads all six official bundles, verifies SHA256 and Ed25519 signatures, builds reproducible IPKs using the release tag commit timestamp, generates `Packages` and `Packages.gz`, signs `Packages` with the existing key in native usign format, verifies the signature with upstream `usign`, and attaches the feed assets to the same release. For an older release, or one created through the manual Release workflow, use **Actions → OpenWrt OPKG Feed → Run workflow** with its tag. If the signing secret is missing or does not match `UPDATE_PUBLIC_KEY.b64`, publication fails rather than producing an unsigned feed.

The packaging tests run with Python's standard library and do not need an OpenWrt router, OpenWrt SDK, or network access.
