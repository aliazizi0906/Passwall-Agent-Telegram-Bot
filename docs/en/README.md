# PW Agent Documentation — English

> **Latest GitHub Release: v1.3.0-rc.2.** This release is marked Latest, while retaining the RC tag and its not-production-certified status. Use the [installation guide](INSTALL_V1_3.md), [upgrade guide](UPGRADE_V1_3.md) and [implementation status](CONTROL_CENTER_PROGRESS.md). Historical sections document the v0.6 baseline.

[← Project README](../../README.md) · [فارسی](../fa/README.md)

## Menu

| Section | Description |
|---|---|
| [Install v1.3 RC](INSTALL_V1_3.md) | Current GitHub Latest release (RC-tagged) installation |
| [Legacy install](INSTALL.md) | Stable v0.6.0 installation guide |
| [Release notes](RELEASE_NOTES_V1_3_RC.md) | v1.3.0-rc.2 changes and limitations |
| [Features](FEATURES.md) | Complete feature overview |
| [Fleet & Automation](FLEET.md) | v0.6 baseline: fleet, RBAC, audit, monitoring and schedules; v1.3 RC preview linked above |
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

**Next:** [Install current GitHub Latest v1.3.0-rc.2](INSTALL_V1_3.md) · [Legacy v0.6.0 installer](INSTALL.md)


## cPanel installation model

The cPanel backend is browser-first: neither the v0.6 stable release nor the v1.3 RC requires cPanel Terminal or a fixed filesystem path.

Upload the contents of `server/` to any web-accessible directory and open `setup.php`. The browser installer detects the deployment URL, creates the database schema/configuration, registers the Telegram webhook and generates the exact Cron and OpenWrt provisioning commands for that installation.
