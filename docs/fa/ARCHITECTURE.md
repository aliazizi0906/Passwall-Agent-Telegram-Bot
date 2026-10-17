# معماری

[صفحه مستندات](README.md) · [English](../en/ARCHITECTURE.md)

## نمای کلی

```text
Telegram User
     |
     v
Telegram Bot / Mini App
     |
     v
PHP Backend
     |
     +---- MySQL: Commands / Metrics / Events
     |
     ^ HTTPS + HMAC
     |
OpenWrt Agent (Go)
     |
     +---- UCI / ubus / procd
     +---- Passwall2
     +---- Dropbear محلی
```

ارتباط مدیریتی عادی همیشه از سمت روتر آغاز می‌شود و Backend نیازی به اتصال ورودی مستقیم به روتر ندارد.

## Backend

Backend مبتنی بر PHP مسئول موارد زیر است:

- دریافت Telegram Webhook
- بررسی Telegram User Allowlist
- ایجاد Command
- Lease کردن Command
- دریافت Result
- احراز هویت Device
- ذخیره Event و Metric
- تحویل Update امضاشده
- Provisioning
- Alertهای Monitoring

MySQL وضعیت Commandها، Deviceها، Sessionها، Metricها، Eventها، Alert State و Provisioning Tokenهای یک‌بارمصرف را نگه می‌دارد.

## Agent

Agent نوشته‌شده با Go زیر Init System روتر اجرا می‌شود و:

1. Poll احرازهویت‌شده به Backend می‌فرستد.
2. Command Lease‌شده را دریافت می‌کند.
3. Action پشتیبانی‌شده را روی روتر اجرا می‌کند.
4. Result را به Backend می‌فرستد.
5. پس از Poll Interval چرخه را تکرار می‌کند.

Smart Failover نیز مستقل از Commandهای Telegram در Agent اجرا می‌شود.

## چرخه Command

```text
queued
  |
  v
leased
  |
  +---- Result received ----> done / failed
  |
  +---- Lease expired ------> queued (در صورت باقی بودن Retry)
  |
  +---- Retry exhausted ----> failed
```

هر Command یک Idempotency Key دارد. برای Mutationها، Agent نتیجه اجراشده را در Journal محلی ذخیره می‌کند. اگر عملیات روی روتر انجام شده باشد ولی Result به Backend نرسیده باشد، Retry بعدی می‌تواند Result قبلی را Replay کند و Mutation دوباره اجرا نشود.

## احراز هویت Device

هر نصب یک Device ID و Device Secret دارد.

Requestها با HMAC-SHA256 احراز هویت می‌شوند. Responseهای Backend نیز Sign می‌شوند تا Agent بتواند مطمئن شود پاسخ از Backend دارای Secret مشترک آمده است.

## Provisioning

Setup سرور دو مورد می‌سازد:

- Device Secret دائمی
- Provisioning Token تصادفی یک‌بارمصرف که فقط Hash آن در MySQL ذخیره می‌شود

دستور نصب فقط Token موقت را دارد، نه Device Secret دائمی.

Installer روی OpenWrt Token را یک بار به `api/provision.php` می‌دهد، کانفیگ دائمی را دریافت می‌کند و در `/etc/pw-agent/config.json` ذخیره می‌کند.

## پارامترهای حساس Queue

پارامترهای حساس پیش از ذخیره در MySQL Encrypt می‌شوند. Key از Device Secret مشتق می‌شود و AES-256-GCM برای Authenticated Encryption استفاده می‌شود.

## مسیر Smart Failover

برای Shunt تنظیم‌شده:

1. Default Node فعال بررسی می‌شود.
2. چند Relay Probe انجام می‌شود.
3. در صورت وجود Address/Port، TCP Reachability بررسی می‌شود.
4. Score بر اساس Latency، Jitter، Loss و TCP ساخته می‌شود.
5. Failure Threshold و Cooldown اعمال می‌شوند.
6. Nodeهای سالم Normal Proxy Rank می‌شوند.
7. در صورت نیاز Default تغییر می‌کند.
8. در صورت فعال بودن Auto Return، پس از Recovery به Preferred Node برمی‌گردد.

## معماری Update

برای هر معماری یک Archive رسمی منتشر می‌شود.

مسیر Update:

```text
Downloaded bytes
   |
   +--> SHA256 == Manifest SHA256
   |
   +--> Ed25519 Signature معتبر
   |
   v
Extract -> install-update.sh -> Restart Agent
```

جزئیات بیشتر در [امنیت](SECURITY.md).
