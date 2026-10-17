# PW Agent Documentation — English

[← Project README](../../README.md) · [فارسی](../fa/README.md)

## Menu

| Section | Description |
|---|---|
| [Install](INSTALL.md) | Full cPanel + OpenWrt installation |
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
