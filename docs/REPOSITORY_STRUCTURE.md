# Repository structure and cleanup / ساختار مخزن و پاکسازی

**Current public pre-release:** [v1.3.0-rc.2](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v1.3.0-rc.2). This document explains source ownership, safe deletion rules, and how to keep the repository clean. It does not claim full hardware certification.

## Source map

| Path | Purpose | Keep? |
|---|---|---|
| `agent_main.go` | Go Agent entrypoint, signed outbound transport and command routing | Required |
| `features_v030.go` / `features_v040.go` | Passwall2, shunt, failover, diagnostic and command features | Required — same Go `main` package |
| `features_v050.go` | Agent signing, idempotency and update safeguards | Required |
| `features_v060.go` | Configuration snapshots, rollback and v0.6 Fleet-related functionality | Required |
| `features_v130.go` | New v1.3 capability/diagnostic APIs | Required |
| `*_test.go` | Regression and backward compatibility tests | Required for CI |
| `server/lib.php` | PHP API/backend shared library | Required |
| `server/hardening_v050.php` | Still required by `server/lib.php`; secure queue parameters and hardening | Required |
| `server/platform_v060.php` | Still required by `server/lib.php`; Fleet, RBAC, schedules, metrics and schema | Required |
| `server/setup.php`, `server/setup_core.php`, `server/schema.sql` | cPanel installation and database setup | Required |
| `server/dashboard/`, `server/control/` | Existing Telegram Dashboard and PW Control Mini App | Required |
| `server/api/`, `server/bot/`, `server/cron/` | Agent requests, Telegram webhook and scheduling | Required |
| `server/terminal/`, `server/bin/` | Optional secured terminal/UI and CLI maintenance functions | Keep |
| `openwrt/` | Provisioning, signed update, LuCI menus/views and RPCD ACL | Required |
| `docs/en/`, `docs/fa/` | Active bilingual documentation | Keep |
| `docs/assets/previews/` | Static screenshots-style visual previews with sample data | Keep |
| `scripts/` and `.github/workflows/` | CI, releases, documentation and packaging validation | Required |

> **Do not remove files just because their names contain v030/v040/v050/v060.** These are compiled or imported by the current v1.3 source; deleting them would break Go builds or PHP runtime. Older releases remain available as immutable GitHub tags, not duplicated documents in the current tree.

## Removed from the active tree

- Root version placeholders: `FEATURES_0.4.0.md`, `UPGRADE_0.4.0.md`, `MIGRATION_0.5.0.md`.
- Duplicated bilingual v0.4 archive pages under `docs/en/legacy/` and `docs/fa/legacy/`.
- `docs/en/RELEASE_CANDIDATE_BUILD.md` (obsolete one-off release marker).

Archived information is still recoverable from [v0.4 source history](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/commits/main/) and older Git tags/releases. Historical v0.5→v0.6 migration docs deliberately remain because some users still run stable v0.6.0. Deleting documents from a branch **does not erase Git commit history**.

## Installable cPanel bundle

The release packager `scripts/package_cpanel.sh` copies only appropriate backend files and excludes runtime credentials, DB dumps, logs, backups, test fixtures, the developer config template and release caches. The app's source tests remain in Git for CI but are excluded from public cPanel ZIP assets. `.htaccess` protection and required `setup.php`, `control/`, `health.php` are retained.

## Screenshots / تصاویر

The images in [README](../README.md) are **source-aligned illustrative static previews**, **not screenshots of an authenticated deployment**. They use clearly marked sample information and follow the current `server/control/index.php` and `openwrt/luci/.../control.js` design. Because Telegram `initData` is needed for actual data, screenshots from a real installation require a test router and user-supplied authenticated session; never publish credentials, live IP addresses or provisioning tokens.

## Checks

Run:

```sh
go test ./...
go vet ./...
find server -name '*.php' -print0 | xargs -0 -n1 php -l
python3 scripts/check_markdown_links.py
python3 scripts/check_repository_hygiene.py
bash scripts/package_cpanel.sh
```

CI also performs Go cross-compilation, JS/Shell checks and validates the cPanel asset. A successful CI **does not** replace actual OpenWrt modem, network rollback or hardware tests.

## فارسی — تصمیم پاکسازی

فایل‌های مستندات تکراری و فایل موقت انتشار حذف شدند، اما کدهای دارای نام نسخه قدیمی همچنان توسط نسخه ۱.۳ استفاده می‌شوند و نباید حذف شوند. تصاویر README بر اساس ظاهر فعلی پروژه و با داده نمونه ساخته شده‌اند و نباید با اسکرین‌شات واقعی روتر اشتباه گرفته شوند. راهنماهای مهاجرت تاریخی برای کاربران نسخه پایدار قدیمی محفوظ‌اند.
