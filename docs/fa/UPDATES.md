# به‌روزرسانی و Release

> **نسخه ۱.۳ آزمایشی:** برای پنل PW Control Center، نصب تازه و ارتقا از v0.6 به راهنمای [نصب v1.3 RC](INSTALL_V1_3.md)، [ارتقا](UPGRADE_V1_3.md) و [وضعیت قابلیت‌ها](CONTROL_CENTER_PROGRESS.md) مراجعه کن. متن‌های پایین این صفحه درباره سری پایدار v0.6 هستند؛ پکیج ۱.۳ هنوز نسخه پایدار نیست.

[صفحه مستندات](README.md) · [English](../en/UPDATES.md)

این صفحه روند به‌روزرسانی نسخه پایدار قدیمی v0.6 را توضیح می‌دهد. آخرین نسخه عمومی آزمایشی v1.3.0-rc.2 است؛ برای نصب و ارتقا از راهنمای [نصب v1.3 RC](INSTALL_V1_3.md) و [ارتقا](UPGRADE_V1_3.md) استفاده کن. SSH، Git و cPanel Terminal همچنان اختیاری‌اند.

## چه چیزهایی Update می‌شوند؟

دو لایه مستقل داریم:

1. فایل‌های PHP/MySQL Backend روی هاست
2. Agent/LuCI روی Routerهای OpenWrt

## آپدیت Backend بدون Terminal

از **cPanel File Manager** استفاده کن:

1. ZIP نسخه جدید Repository را از GitHub دانلود کن.
2. داخل یک فولدر موقت Extract کن.
3. وارد `server/` نسخه جدید شو.
4. محتویات آن را روی فولدر فعلی PW Agent کپی کن.
5. فولدر مقصد را قبل از Copy پاک نکن.
6. `config.php` Runtime فعلی را نگه دار.
7. بعد از Copy یک بار `health.php` را باز کن.

Backend می‌تواند داخل هر Document Root یا Subdirectory نصب شده باشد و هیچ مسیر اجباری مثل `public_html/pwagent` وجود ندارد.

مثال:

```text
https://example.com/pw
https://example.com/tools/router
https://router.example.com/
```

اولین Request بعد از Upgrade، Migrationهای سازگار Database را به‌صورت Lazy اعمال می‌کند.

## Sync کردن Agent Release بدون Terminal

از Telegram Mini App وارد:

```text
Dashboard → Release Cache
```

شو.

User دارای Permission **Agent Update** می‌تواند:

- وضعیت Cache هر Architecture را ببیند.
- یک Architecture را Sync کند.
- همه Architectureهای پشتیبانی‌شده را Sync کند.

Backend فایل‌های Release رسمی GitHub را دانلود می‌کند و قبل از ذخیره در Cache محافظت‌شده، SHA256 را Verify می‌کند.

Architectureها:

```text
armv7
arm64
amd64
386
mips
mipsle
```

## Provision مجدد Router بدون Terminal

اگر Provisioning Token منقضی شد، Backend را از اول نصب نکن و نیازی به Terminal نیست.

در:

```text
Dashboard → Devices
```

Router را انتخاب کن و بزن:

```text
Provision / Reinstall
```

PW Agent Tokenهای استفاده‌نشده قبلی همان Device را Revoke می‌کند و یک Command جدید با اعتبار ۳۰ دقیقه می‌سازد.

## Self-update Agent

Router می‌تواند از Backend وجود Package جدید مخصوص Architecture خودش را بررسی کند.

قبل از نصب:

1. SHA256 برای Integrity
2. Ed25519 Signature برای Authenticity

Verify می‌شوند.

Public Key رسمی داخل Agent Embed شده است.

## روش CLI اختیاری

اگر هاست SSH/Terminal داشت، Scriptهای CLI هنوز قابل استفاده‌اند:

```bash
php bin/doctor.php
php bin/sync-release.php --arch=armv7
php bin/sync-release.php --all
```

ولی برای استفاده عادی روی cPanel لازم نیستند.

## Release رسمی برای Maintainer

GitHub Actions برای این Architectureها Build می‌سازد:

- armv7
- arm64
- amd64
- 386
- mips
- mipsle

برای هر Architecture این فایل‌ها منتشر می‌شوند:

```text
pw-agent-openwrt-ARCH.tar.gz
pw-agent-openwrt-ARCH.tar.gz.sha256
pw-agent-openwrt-ARCH.tar.gz.sig
```

Maintainer Secret زیر را در Repository تنظیم می‌کند:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

سپس:

```text
GitHub → Actions → Release → Run workflow
Branch: main
version: vX.Y.Z
```

Workflow نسخه Source را بررسی می‌کند، Private Key را با `UPDATE_PUBLIC_KEY.b64` تطبیق می‌دهد، تمام Architectureها را Build و Sign می‌کند و GitHub Release را می‌سازد.

## Forkها

Forkی که Signed Release مستقل می‌خواهد باید Update Public Key خودش را قرار دهد و Private Key متناظر را فقط در GitHub Actions Secret نگه دارد.
