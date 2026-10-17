# رفع اشکال

[صفحه مستندات](README.md) · [English](../en/TROUBLESHOOTING.md)

## Doctor در Backend

اول این دستور را اجرا کنید:

```bash
cd /path/to/deployed/backend
php bin/doctor.php
```

Doctor موارد PHP، Extensionها، HTTPS، Database/Schema، Device، Telegram API/Webhook و Signed Update Bundle را بررسی می‌کند.

## Health Endpoint

باز کنید:

```text
https://YOUR-DOMAIN/health.php
```

خروجی سالم:

```json
{"ok":true,"version":"0.6.0",...}
```

HTTP 500 معمولاً یعنی Backend نتوانسته Database/Schema را Initialize کند.

## PHP CLI قدیمی است

```bash
php -v
```

در cPanel می‌توانید EA-PHP را بررسی کنید:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -v
```

در صورت نیاز همین مسیر کامل را برای Setup، Doctor و Cron استفاده کنید.

## PHP Extension وجود ندارد

```bash
php -m | grep -Ei 'pdo_mysql|curl|openssl'
```

هر سه مورد لازم هستند.

## مشکل Telegram Webhook

اول Doctor را اجرا کنید.

اگر Bot Token عوض شده، `config.php` را اصلاح کرده و Webhook را دوباره ثبت کنید.

Webhook باید روی مسیر زیر باشد:

```text
https://YOUR-DOMAIN/bot/webhook.php
```

## Provisioning Token منقضی شده

کل نصب را از اول نسازید.

```bash
php bin/new-provision-token.php
```

Token یک‌بارمصرف است و ۳۰ دقیقه اعتبار دارد.

## دانلود Release در Setup شکست خورده

ابتدا بررسی کنید معماری موردنظر در GitHub Release فعلی وجود دارد.

بعد:

```bash
php bin/sync-release.php --arch=armv7
```

را با معماری صحیح اجرا کنید.

## مشکل Service Agent

روی OpenWrt:

```sh
pgrep -af pw-agent
logread -e pw-agent
/etc/init.d/pw-agent restart
```

## Diagnostics اتصال Agent

```sh
/usr/bin/pw-agent diagnose-json
```

موارد مهم:

- Server URL
- Device ID
- HMAC Authentication
- Response Signature
- Round-trip Latency

در LuCI هم از **Services → PW Agent** استفاده کنید.

## معماری اشتباه

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

Remote Installer معمولاً این مورد را خودکار تشخیص می‌دهد.

## Passwall2 پیدا نمی‌شود

```sh
test -f /etc/config/passwall2 && echo OK || echo NOT_FOUND
```

برای قابلیت‌های Passwall، نصب Passwall2 لازم است.

## Telegram پاسخ می‌دهد ولی اطلاعات Router قدیمی است

Logهای Agent و `diagnose-json` را بررسی کنید. سپس دسترسی HTTPS روتر به Backend و منطقی بودن Clock روتر/سرور را بررسی کنید.

## Monitoring هشدار نمی‌دهد

Cron را دستی اجرا کنید:

```bash
php cron/monitor.php
```

بعد بررسی کنید Cron cPanel از مسیر Absolute صحیح PHP و Backend استفاده می‌کند.

## اطلاعات مناسب برای Bug Report

معمولاً قابل اشتراک:

- Agent Version
- OpenWrt Version
- Architecture
- Error Message پاک‌سازی‌شده
- `diagnose-json` بدون Secret
- Logهای مرتبط بدون Token/Credential

هرگز منتشر نکنید:

- Telegram Bot Token
- Device Secret
- Database Password
- Private Ed25519 Signing Key
- کل `config.php`
- کل `/etc/pw-agent/config.json`


## Device در Fleet دیده نمی‌شود

اجرا کنید:

```bash
php bin/doctor.php
```

بعد بررسی کنید `pw_device_registry` برای Device ID موردنظر Row فعال داشته باشد و Router در حال Poll کردن Backend باشد.

Deviceهای v0.5 باید هنگام Initialize شدن Schema نسخه v0.6 به‌صورت خودکار وارد Registry شوند.

## Dashboard خطای Unauthorized می‌دهد

Dashboard باید از دکمه Telegram Mini App باز شود. Browser عادی `initData` معتبر Telegram ندارد.

همچنین بررسی کنید:

- Telegram User در `pw_users` وجود داشته باشد.
- User فعال باشد.
- Role او Permission لازم را داشته باشد.
- Device Scope شامل Router فعال باشد.

## Scheduled Action اجرا نمی‌شود

Cron یک‌دقیقه‌ای را بررسی کنید:

```bash
php cron/monitor.php
```

اگر Maintenance Mode برای Device فعال باشد، Scheduleهای Due عمداً Pause می‌شوند.

همچنین User سازنده Schedule باید هنوز Permission اجرای Action را داشته باشد.

## Alert Rule Trigger نمی‌شود

بررسی کنید:

- Cron هر دقیقه اجرا شود.
- Metric جدید در `pw_metrics` وجود داشته باشد.
- Rule فعال باشد.
- Threshold و Duration برقرار باشند.
- Cooldown تمام شده باشد.
- Maintenance Mode فعال نباشد.

## Rollback در دسترس نیست

Config History فقط بعد از اجرای Agent v0.6 ثبت می‌شود. تغییرات قدیمی Snapshot قابل Rollback ندارند.

برای تغییرات v0.6 بررسی کنید Command اصلی Success شده باشد و Record مربوطه در `pw_config_changes` وجود داشته باشد.

Rollback Snapshot به Device Secret همان Router وابسته است و روی Router دیگر Authentication آن Fail می‌شود.
