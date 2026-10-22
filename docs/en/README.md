# PW Agent Documentation — English

> **v1.3 release candidate:** For the PW Control Center, use the [v1.3 RC install guide](INSTALL_V1_3.md), [migration guide](UPGRADE_V1_3.md) and [implementation status](CONTROL_CENTER_PROGRESS.md). Historical sections below document the v0.6 stable series; RC is not production certified.

[← Project README](../../README.md) · [فارسی](../fa/README.md)

## Menu

| Section | Description |
|---|---|
| [Install](INSTALL.md) | Terminal-free cPanel Web Setup + OpenWrt installation |
| [Features](FEATURES.md) | Complete feature overview |
| [Fleet & Automation](FLEET.md) | v0.6 multi-device, RBAC, audit, monitoring and schedules |
| [Architecture](ARCHITECTURE.md) | Components and request flow |
| [Security](SECURITY.md) | Authentication, encryption and update trust |
| [Updates](UPDATES.md) | User updates and maintainer releases |
| [Migration](MIGRATION.md) | Upgrade from older versions |
| [Development](DEVELOPMENT.md) | Build, test and CI |
| [Troubleshooting](TROUBLESHOOTING.md) | Diagnostics and common failures |

## What is PW Agent?

PW Agent is a self-hosted Telegram remote-management system for OpenWrt and Passwall2. An Agent written in Go runs on the router and polls a PHP/MySQL backend over HTTPS. Telegram commands are translated into whitelisted router actions and results are returned to the user.

The design is suitable for routers behind CGNAT or without a public/static IP because no inbound management port is required.

## Supported architectures

| CPU / OpenWrt architecture | Release name |
|---|---|
| ARMv7 / armv7l | `armv7` |
| ARM64 / aarch64 | `arm64` |
| x86_64 | `amd64` |
| x86 / i386-i686 | `386` |
| MIPS big-endian | `mips` |
| MIPS little-endian | `mipsle` |

Passwall2 must already be installed on the router.

**Next:** [Install PW Agent](INSTALL.md)


## cPanel installation model

PW Agent v0.6 does not require cPanel Terminal or a fixed filesystem path.

Upload the contents of `server/` to any web-accessible directory and open `setup.php`. The browser installer detects the deployment URL, creates the database schema/configuration, registers the Telegram webhook and generates the exact Cron and OpenWrt provisioning commands for that installation.
