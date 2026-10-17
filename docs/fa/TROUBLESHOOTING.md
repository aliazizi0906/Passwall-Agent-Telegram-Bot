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
{"ok":true,"version":"0.5.0",...}
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
