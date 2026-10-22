# Upgrade PW Agent v0.6 to v1.3.0-rc.1

[Fresh install](INSTALL_V1_3.md) · [فارسی](../fa/UPGRADE_V1_3.md) · [Feature status](CONTROL_CENTER_PROGRESS.md)

**Release candidate / staging first:** Do not overwrite a healthy v0.6 production router or backend before independent backups, staging checks and a rehearsed rollback.

## Safety prerequisites

- Preserve `config.php` and the instance encryption key; database-stored device secrets rely on that key.
- Never rerun Web Setup as an upgrade shortcut.
- Use a **different Telegram Bot** for a separate staging backend; sharing the production Bot token may re-register the webhook to your staging URL.
- New commands (`agent.capabilities`, `network.doctor`, `led.status`) require the matching v1.3 RC Go agent; legacy v0.6 agents report unsupported action.
- Do not paste secrets, router credentials, config JSON or database dumps into public issues.

## Step 1: Back up your entire installation

From cPanel File Manager, save the complete backend folder, including `config.php` and `releases/`, to an encrypted/offline location. Export a full SQL backup using phpMyAdmin. Never leave exported SQL/ZIP/config in public_html.

From the router's **LAN SSH** terminal:

```sh
sysupgrade -b /tmp/pw-before-v130.tar.gz
cp -p /usr/bin/pw-agent /tmp/pw-agent-v060.bin
cp -p /etc/pw-agent/config.json /tmp/pw-agent-v060-config.json
/usr/bin/pw-agent --version
```

Copy critical `/tmp` backups off-device; `/tmp` is not persistent after reboot. Keep `/etc/config/passwall2`, `network`, `wireless` and `firewall` snapshots separately if needed.

## Step 2: Staging rehearsal

Deploy the matching published RC cPanel package in an **isolated** subdomain or subdirectory with its own DB/Bot, then provision a noncritical router. Verify `health.php`, signed commands, router metrics, scheduled monitoring, LuCI, and Passwall2 operations.

## Step 3: Backend in-place upgrade (only after acceptance)

1. Schedule maintenance; pause sensitive automation and snapshot DB and files.
2. Download `pw-agent-cpanel-v1.3.0-rc.1.zip` from a genuine matching prerelease and compare its SHA256. If no published asset exists, stop.
3. Extract to a *temporary directory* in cPanel File Manager. The ZIP has all backend files at root and intentionally excludes `config.php`.
4. Copy the new files over the current backend folder **without deleting the destination**. Preserve `config.php`, the existing releases cache, and all runtime state. Keep your existing base URL.
5. Open `health.php`: expect `{"ok":true,"version":"1.3.0-rc.1",...}`. For a failure inspect PHP error_log, not by deleting configuration/database.
6. Verify the existing Once Per Minute cron continues to call your real `cron/monitor.php`. Do not add a duplicate job or paste five schedule stars into the Command field.
7. Verify the authorized Telegram Mini App Dashboard and device list.

## Step 4: Agent upgrade

Use the signed Agent Update path **only after** the matching RC release assets are published and your staging router successfully tests. Routine upgrades do not need a new provisioning token.

After upgrade:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
/usr/bin/pw-agent capabilities-json
/usr/bin/pw-agent network-doctor-json
logread -e pw-agent
```

Check a completed command, telemetry, CPU/memory metrics, local LuCI and Passwall2 state. Avoid dangerous Wi-Fi/WAN/Firewall mutations without a proven recovery path.

## Step 5: Recovery

**Backend rollback:** Restore the saved file snapshot *and compatible database snapshot*, taking schema changes into account; blindly downgrading PHP while retaining newer DB schema is not a certified migration path. Confirm webhook points to the active backend.

**Router rollback:** Restore the saved v0.6 executable/init service and restart with `/etc/init.d/pw-agent restart`; restore `config.json` only if your credentials changed. Do not factory-reset or reconfigure Passwall2 unnecessarily.

**Acceptance gate:** Health, authentication/RBAC, queue completion and deduplication, Fleet, LuCI local read-only status, monitoring cron and rollback verified.

The prerelease is not production certification; inspect [remaining blockers](CONTROL_CENTER_PROGRESS.md).
