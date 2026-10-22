# Install PW Agent using APK v3 (OpenWrt 25.12.2)

[Documentation](README.md) · [فارسی](../fa/APK.md) · [Legacy OPKG instructions (24.10 and older)](OPKG.md) · [Releases](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases)

**For OpenWrt 25.12.2 and other APK v3-based builds.** The newer OpenWrt releases use the `apk` package manager. `opkg`/`.ipk` instructions are for OpenWrt 24.10 and older and cannot install APK packages.

Official release assets include six separately signed APK v3 packages and six signed **direct-index** files, one per release architecture. An index is downloaded using its full `.adb` URL. The actual APK filename is recorded in the signed index using `--pkgname-spec`, so GitHub Release assets can be used without an independent directory-based feed server. All artifacts are built and checked by the OpenWrt 25.12.2 SDK's host `apk` executable.

## 1. Select the correct architecture

On your router check:

```sh
uname -m
apk --version
cat /etc/apk/arch
```

| CPU / release target | Additional compatible APK architecture | Signed index filename |
|---|---|---|
| `armv7l` / `armv7` | `pw_agent_armv7` | `pw-agent-packages-armv7.adb` |
| `aarch64` / `arm64` | `pw_agent_arm64` | `pw-agent-packages-arm64.adb` |
| `x86_64` / `amd64` | `pw_agent_amd64` | `pw-agent-packages-amd64.adb` |
| `i386` / `i686` | `pw_agent_386` | `pw-agent-packages-386.adb` |
| `mips` (big-endian) | `pw_agent_mips` | `pw-agent-packages-mips.adb` |
| `mipsel` (little-endian) | `pw_agent_mipsle` | `pw-agent-packages-mipsle.adb` |

These are custom compatible-architecture aliases because OpenWrt's native architecture names are specific to the device's target and subtarget. They are intentionally distinct from native OpenWrt package architecture names. Check that the corresponding official release binary works on your particular CPU before using a production router.

Add **only the matching alias** as a new line in `/etc/apk/arch`. For example, on ARM64:

```sh
grep -qxF 'pw_agent_arm64' /etc/apk/arch || printf '\npw_agent_arm64\n' >> /etc/apk/arch
```

**Keep all existing lines in `/etc/apk/arch`**, especially the first line with your native architecture. APK treats the first line as the primary architecture; subsequent lines specify additional compatible architectures. Do not replace the file or add aliases for unrelated CPUs.

## 2. Trust the APK signing key

The APK v3 feed is signed with a stable ECDSA P-256 key, derived by HKDF with domain separation from the project's existing Ed25519 release signing secret. The ECDSA public key is published as the official release asset `pw-agent-apkv3-p256.pem` and its **Ed25519 attestation** as `pw-agent-apkv3-p256.pem.sig`. This attestation is signed with the original release key (published separately as `UPDATE_PUBLIC_KEY.b64`).

The following commands verify the new public key on a trusted Linux PC using the previously authenticated project release public key, not on the router. Replace `v0.7.0` with the target official release:

```sh
BASE='https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0'
curl -fL "$BASE/pw-agent-apkv3-p256.pem" -o pw-agent-apkv3-p256.pem
curl -fL "$BASE/pw-agent-apkv3-p256.pem.sig" -o pw-agent-apkv3-p256.pem.sig

# UPDATE_PUBLIC_KEY.b64 must be the authentic key already trusted for Agent releases.
{ printf '302a300506032b6570032100' | xxd -r -p; base64 -d < UPDATE_PUBLIC_KEY.b64; } > update-public.der
openssl pkey -pubin -inform DER -in update-public.der -out update-public.pem
openssl pkeyutl -verify -rawin -pubin -inkey update-public.pem \
  -in pw-agent-apkv3-p256.pem -sigfile pw-agent-apkv3-p256.pem.sig
```

The last command must report a valid signature. Then transfer the verified PEM to `/etc/apk/keys/pw-agent-apkv3-p256.pem` on the router, for example using SCP:

```sh
scp pw-agent-apkv3-p256.pem root@ROUTER-LAN-IP:/etc/apk/keys/pw-agent-apkv3-p256.pem
```

Alternatively, you may download the exact PEM through HTTPS on the router, but you still need to verify that it matches the previously authenticated PEM. Never use `--allow-untrusted` or disable signature checking to make an invalid key or feed work.

## 3. Add the signed direct-index repository

Use the index filename for your own CPU from the table above. For example, on ARM64 with release `v0.7.0`:

```sh
mkdir -p /etc/apk/repositories.d
cat > /etc/apk/repositories.d/pw-agent.list <<'EOF'
ndx https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0/pw-agent-packages-arm64.adb
EOF

apk update
apk search pw-agent
apk add pw-agent
```

The repository is pinned to the selected release. APK downloads the package filename bound to the index (`pw-agent-0.7.0-r0-pw_agent_arm64.apk` in this example) from the **same GitHub Release asset directory**. The index and each APK file are signed by the P-256 signing key. If the index fails verification, stop and recheck the key, tag, and architecture alias.

## 4. Configure PW Agent

Install Passwall2 first. The APK package installs the agent binary, the OpenWrt procd init script, and the public example JSON. The runtime configuration at `/etc/pw-agent/config.json` is **never included as an owned package file**. A missing config is created from the example in the post-install script. It remains stopped while `CHANGE_ME` remains in the file.

```sh
vi /etc/pw-agent/config.json
chmod 600 /etc/pw-agent/config.json
/etc/init.d/pw-agent enable
/etc/init.d/pw-agent restart
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

Use your own backend `server_url`, device ID and secret from PW Agent provisioning. A new package version preserves existing runtime credentials. To upgrade, change the repository URL to the newer signed release index, then run `apk update && apk upgrade pw-agent`.

## Release and verification for maintainers

The separate `.github/workflows/apk-feed.yml` workflow checks the six **existing Ed25519-signed** release bundles, downloads the official OpenWrt 25.12.2 x86/64 SDK and validates its pinned SHA256, derives the isolated P-256 key in runner temporary storage, and calls the SDK's own `apk mkpkg` and `apk mkndx` with signing enabled. It then runs `apk verify` on each `.apk` and `.adb` plus `apk adbdump` on each index. The private P-256 key is never published, logged, or committed; only the attested public key leaves the runner.

The package architecture aliases are compatibility labels for these standalone Go binaries; they do not imply compatibility with every OpenWrt target or package ABI. The pre-install script checks the CPU type again before modifying the router.
