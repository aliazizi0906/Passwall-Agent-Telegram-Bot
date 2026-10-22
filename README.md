# PW Agent — OpenWrt Network Control Center

**Self-hosted management for OpenWrt + Passwall2, Telegram and a local LuCI panel.**

[راهنمای فارسی](docs/fa/INSTALL_V1_3.md) · [English installation](docs/en/INSTALL_V1_3.md) · [Upgrade from v0.6 / ارتقا](docs/fa/UPGRADE_V1_3.md) · [Features / قابلیت‌ها](docs/fa/CONTROL_CENTER_V1_3.md) · [Changelog](CHANGELOG.md) · [GitHub Releases](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases)

> **Release status:** Current stable series: **v0.6.0**. The `feature/v1.3-control-center` branch is **v1.3.0-rc.1 (release candidate source)**, not a final production-certified v1.3.0 release. Do not treat all WRTPro-reference features as implemented. Development progress and gaps: [FA](docs/fa/CONTROL_CENTER_PROGRESS.md) / [EN](docs/en/CONTROL_CENTER_PROGRESS.md).

## English

PW Agent controls your OpenWrt routers through a PHP 8.1+/MySQL backend that can run on shared cPanel hosting. The router **polls the server over outbound HTTPS** with authenticated HMAC requests—no WAN management port or public IP is necessary.

v1.3 RC introduces **PW Control Center**:
- **Telegram Mini App /control/**: Persian/English, RTL/LTR, light/dark UI; router selection, real monitoring history, health checks and read-only views for LAN clients, Wi-Fi, DNS and routes.
- **Passwall2 tools**: node list and tests, select node, import configuration links, add/update/remove subscriptions, relay ranking, shunt overview and basic failover controls. Mutations require RBAC and explicit confirmation.
- **LuCI local panel** at *Services → PW Control Center*: offline read-only system, Wi-Fi, clients, diagnostic, capability and optional GoogleWifiLED status.
- **Agent diagnostics**: `agent.capabilities`, `network.doctor`, `led.status`; safely report unsupported hardware instead of showing nonfunctional cellular/LED controls.
- **Existing v0.6 services remain**: monitoring/alerts, fleet, roles, audit, backups, schedules and signed-agent-update support.

**Important limitations:** This is a staged development preview. Per-client blocking/rate limits, WAN/Wi-Fi safe-apply rollback, cellular tower/band lock and SMS/USSD, RGB LED mutations, DNS/IPv6 kill-switch guarantees, real SQM/bufferbloat, standalone browser authentication and tested backend rollback are **not complete**. The UI labels them as planned. Read the [implementation matrix](docs/en/CONTROL_CENTER_PROGRESS.md).

### Installation (when an RC build has been published)

1. Create a MySQL database/user and a Telegram bot; send `/start` to the bot.
2. Download the matching `pw-agent-cpanel-v1.3.0-rc.1.zip` from the **corresponding GitHub prerelease**. If no prerelease asset appears, the build has **not been published** yet; use CI preview artifacts only on staging.
3. In **cPanel → File Manager**, create a test directory (such as `public_html/pw-test`), upload/extract the ZIP **directly there** (it contains `setup.php`, `api/`, `control/` at the ZIP root). Preserve hidden `.htaccess` files.
4. Visit `https://YOUR-DOMAIN/pw-test/setup.php`; complete Web Setup. Copy the **generated** cron command; set its schedule separately to **Once Per Minute** in cPanel (do not paste the five cron stars into the Command field).
5. On an **unimportant test router**, run the one-time provisioning command displayed by Setup. It is pinned to `v1.3.0-rc.1` and needs the corresponding signed agent release asset. Keep tokens out of tickets and screenshots.
6. Open the Telegram bot → Mini App Dashboard → **PW Control Preview**. In LuCI open *Services → PW Control Center*. Verify `health.php`, agent version, CLI `/usr/bin/pw-agent diagnose-json` and Network Doctor.

For full prerequisites, verification and troubleshooting read the [English installation guide](docs/en/INSTALL_V1_3.md). For existing deployments **do not rerun Setup or delete config.php**; follow [migration guidance](docs/en/UPGRADE_V1_3.md).

### Architecture

```text
Telegram Bot → HTTPS PHP API + MySQL + Cron
                     ↑
             signed HTTPS HMAC
                     ↑
                 Go Agent
                  OpenWrt
                /       \
      Passwall2/UCI      Local LuCI Control (read-only)
```

**Architecture compatibility:** release workflow builds `armv7`, `arm64`, `amd64`, `386`, `mips`, `mipsle`; actual OpenWrt hardware testing is still needed. Backend remains compatible with cPanel and PHP without a Node.js runtime.

### Build & verify

```sh
gofmt -l *.go
go test ./...
go vet ./...
find server -name '*.php' -print0 | xargs -0 -n1 php -l
php server/tests/setup_core_test.php
php server/tests/v130_command_validation_test.php
python3 scripts/check_dashboard_js.py
node --check openwrt/luci/www/luci-static/resources/view/pw-agent/control.js
bash scripts/package_cpanel.sh
```

Only push signed release assets after the matching version, CI, staging upgrade, authorization checks and rollback verification pass.

---

## فارسی

**PW Agent** سامانه مدیریت شخصی روترهای OpenWrt و Passwall2 است که با PHP/MySQL روی هاست اشتراکی cPanel نصب می‌شود و از طریق تلگرام به روتر متصل است. ارتباط روتر به سرور خروجی و مبتنی بر HTTPS/HMAC است؛ نیازی به باز کردن پورت مدیریتی روی اینترنت نیست.

### امکانات نسخه توسعه ۱.۳

- **پنل PW Control:** طراحی واکنش‌گرا، فارسی و انگلیسی، RTL/LTR، تم روشن و تاریک، مدیریت چند روتر و نمودارهایی با اطلاعات واقعی.
- **Passwall2:** نمایش/تست/انتخاب نود، ورود لینک، افزودن/به‌روزرسانی/حذف Subscription، رتبه‌بندی Relay و مشاهده Shunt.
- **شبکه:** نمایش Wi-Fi، دستگاه‌های LAN، وضعیت DNS و Route، مانیتورینگ و Network Doctor.
- **پنل محلی LuCI:** مشاهده وضعیت Router، Wi-Fi، Clients، Diagnostics، قابلیت‌های سخت‌افزاری و وضعیت اختیاری GoogleWifiLED حتی بدون اتصال به سرور.
- **قابلیت‌های قبلی:** مدیریت Fleet، RBAC، Alert، Backup، Schedule و Audit.

**موارد هنوز تکمیل‌نشده:** کنترل سرعت و قطع اینترنت کلاینت‌ها، تنظیمات WAN/Wi-Fi با Rollback، Kill Switch و جلوگیری قطعی از Leak، تنظیم باند و دکل مودم و SMS/USSD، تغییر رنگ LED، SQM/QoS پیشرفته و نسخه نهایی مستقل مرورگر. [گزارش دقیق](docs/fa/CONTROL_CENTER_PROGRESS.md)

### نصب و ارتقا

**نصب آزمایشی جدید:** از [راهنمای قدم‌به‌قدم نصب نسخه ۱.۳](docs/fa/INSTALL_V1_3.md) استفاده کن. ابتدا بسته Backend مخصوص cPanel را از GitHub Release Candidate دریافت کن؛ اگر بسته هنوز منتشر نشده، از Artifact تست CI فقط برای محیط Staging استفاده کن. نصب روتر به همان نسخه بسته امضاشده وابسته است.

**ارتقا از v0.6:** از [راهنمای مهاجرت v0.6 به v1.3 RC](docs/fa/UPGRADE_V1_3.md) پیروی کن. **`config.php`، کلیدهای Runtime، دیتابیس یا تنظیمات Agent فعلی را پاک نکن.** قبل از ارتقا Backup مستقل داشته باش.

**نکته Cron:** در cPanel گزینه `Once Per Minute` را جداگانه انتخاب کن. در فیلد Command فقط فرمان PHP تولیدشده توسط Setup را قرار بده، نه `* * * * *` ابتدا را.

### وضعیت انتشار

`v1.3.0-rc.1` برچسب برنامه‌ریزی‌شده برای نخستین Release Candidate است؛ وجود سورس در GitHub **به معنای وجود Release رسمی و قابل نصب نیست**. وقتی بسته‌ها امضا و منتشر شدند، فایل‌های cPanel ZIP و شش معماری OpenWrt در [صفحه Releases](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases) دیده می‌شوند. تا آن زمان `main` و نصب `v0.6.0` را تغییر نده.

**Maintainer source:** [GitHub repository](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot) · [Feature branch](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/tree/feature/v1.3-control-center) · [PR #5](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/pull/5)
