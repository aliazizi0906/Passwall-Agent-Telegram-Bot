# رفع اشکال

[صفحه مستندات](README.md) · [English](../en/TROUBLESHOOTING.md)

## Web Setup باز نمی‌شود

URL دقیق همان فولدری را باز کن که محتویات `server/` را داخلش آپلود کرده‌ای.

مثال:

```text
https://example.com/pw/
https://example.com/tools/router/
https://router.example.com/
```

اگر `config.php` وجود نداشته باشد، `index.php` خودکار به `setup.php` Redirect می‌کند.

Backend هیچ مسیر ثابت cPanel نمی‌خواهد.

اگر قبل از Setup خطای HTTP 500 می‌گیری، در cPanel بررسی کن:

- PHP حداقل 8.1 باشد.
- `pdo_mysql`، `curl` و `openssl` فعال باشند.
- فایل `.htaccess` آپلود شده باشد.
- PHP Version انتخابی روی همین فولدر اعمال شده باشد.

## Web Setup می‌گوید Directory Writable نیست

از cPanel File Manager Permission/Ownership فولدری که `setup.php` داخلش است را بررسی کن.

PW Agent باید بتواند `config.php` و Runtime Fileهای محافظت‌شده را بسازد.

کل Web Directory را World-writable نکن.

## Base URL اشتباه تشخیص داده شده

Web Setup از Request فعلی یک URL پیشنهادی می‌سازد، ولی باید Public HTTPS URL نهایی را خودت تأیید کنی.

مثلاً اگر Backend اینجاست:

```text
public_html/network/control
```

Base URL باید باشد:

```text
https://example.com/network/control
```

به URL مسیرهای `/api`، `/bot` یا `/setup.php` را اضافه نکن؛ مگر اینکه عمداً خود فولدر `server/` را زیر چنین مسیری Deploy کرده باشی.

## Detect my Telegram ID چیزی پیدا نمی‌کند

قبل از زدن دکمه:

1. Bot جدید را در Telegram باز کن.
2. برایش `/start` بفرست.
3. به Web Setup برگرد و دوباره Detect را بزن.

اگر Bot قبلاً Webhook دیگری داشته باشد، ممکن است `getUpdates` پیام جدیدی نداشته باشد. برای نصب تازه بهتر است Bot تازه استفاده شود یا Webhook قبلی حذف شود.

## Setup قفل شده

بعد از ساخته‌شدن `config.php`، صفحه `setup.php` عمداً Lock می‌شود تا شخص دیگری از اینترنت نتواند تنظیمات Backend را Overwrite کند.

برای اضافه کردن Router دوباره Setup را اجرا نکن.

از:

```text
Telegram → Fleet / Devices → Add Device
```

یا:

```text
Dashboard → Devices → Add device
```

استفاده کن.

## Provisioning Token منقضی شده

Router فعلی را در:

```text
Dashboard → Devices
```

انتخاب کن و:

```text
Provision / Reinstall
```

را بزن.

PW Agent Tokenهای استفاده‌نشده قبلی را Revoke می‌کند و Command جدید ۳۰ دقیقه‌ای می‌سازد.

نیازی به cPanel Terminal نیست.

## Release در Backend Cache وجود ندارد

وارد:

```text
Dashboard → Release Cache
```

شو و Architecture لازم یا **Sync all architectures** را اجرا کن.

اگر Sync شکست خورد، بررسی کن GitHub Release همان نسخه واقعاً این فایل‌ها را داشته باشد:

```text
pw-agent-openwrt-ARCH.tar.gz
pw-agent-openwrt-ARCH.tar.gz.sha256
pw-agent-openwrt-ARCH.tar.gz.sig
```

## Health Endpoint خطای 500 می‌دهد

باز کن:

```text
https://YOUR-BACKEND-URL/health.php
```

خطای 500 معمولاً مربوط به Database/Schema است.

در Web Setup یا cPanel بررسی کن:

- MySQL Host و Port
- Database Name
- Username/Password
- ALL PRIVILEGES
- Permissionهای CREATE و ALTER هنگام Migration

## Cron / Alert / Schedule اجرا نمی‌شود

در **cPanel → Cron Jobs** بررسی کن.

برای نصب تازه، دقیقاً Command تولیدشده توسط Web Setup را استفاده کن؛ چون همان Command مسیر واقعی Backend و PHP CLI را دارد.

Cron باید هر یک دقیقه اجرا شود.

Maintenance Mode عمداً Alertها را Mute و Scheduleهای Due را Pause می‌کند.

## Dashboard خطای Unauthorized می‌دهد

Dashboard باید از دکمه Telegram Mini App باز شود تا `initData` معتبر Telegram وجود داشته باشد.

همچنین بررسی کن:

- User در RBAC فعال باشد.
- Role Permission لازم را داشته باشد.
- Device Scope شامل Router فعال باشد.

## Device در Fleet دیده نمی‌شود

یک بار `health.php` را باز کن تا Migration/Seed اجرا شود.

بعد دوباره `/start` بفرست.

Deviceهای v0.5 که در `config.php` وجود دارند خودکار وارد Fleet Registry می‌شوند.

## Agent Router Offline است

روی OpenWrt:

```sh
pgrep -af pw-agent
logread -e pw-agent
/usr/bin/pw-agent diagnose-json
/etc/init.d/pw-agent restart
```

این موارد را بررسی کن:

- Server URL
- Device ID
- HMAC Authentication
- Signed Response
- HTTPS Reachability
- Clock روتر و سرور

## Architecture Router اشتباه است

روی OpenWrt:

```sh
uname -m
```

Mapping:

```text
armv7l  -> armv7
aarch64 -> arm64
x86_64  -> amd64
i686    -> 386
mips    -> mips
mipsel  -> mipsle
```

Remote Installer معمولاً Architecture را خودکار تشخیص می‌دهد.

## Passwall2 پیدا نمی‌شود

```sh
test -f /etc/config/passwall2 && echo OK || echo NOT_FOUND
```

قابلیت‌های Passwall به نصب بودن Passwall2 نیاز دارند.

## Rollback در دسترس نیست

Config History فقط بعد از اجرای Agent v0.6 ثبت می‌شود. تغییرات v0.5 Snapshot قابل Rollback ندارند.

برای تغییر v0.6 بررسی کن Command اصلی Success شده و در Config History دیده شود.

Rollback Snapshot با History Key مخصوص Router رمزنگاری می‌شود:

```text
/etc/pw-agent/history.key
```

Snapshot روی Router دیگر قابل Decrypt نیست.

## CLI Diagnostic اختیاری

اگر هاست Terminal داشت، می‌توانی علاوه بر موارد بالا اجرا کنی:

```bash
php bin/doctor.php
```

این دستور اختیاری است و برای نصب عادی cPanel لازم نیست.

## چه اطلاعاتی برای Bug Report امن است؟

معمولاً قابل اشتراک:

- Agent Version
- OpenWrt Version
- Architecture
- Error Message پاک‌سازی‌شده
- `diagnose-json` بدون Secret
- Logهای مرتبط بدون Credential

هرگز منتشر نکن:

- Telegram Bot Token
- Device Secret
- Database Password
- Private Ed25519 Signing Key
- کل `config.php`
- کل `/etc/pw-agent/config.json`
- `/etc/pw-agent/history.key`
