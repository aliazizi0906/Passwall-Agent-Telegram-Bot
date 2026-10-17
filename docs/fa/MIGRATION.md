# مهاجرت و ارتقا

[صفحه مستندات](README.md) · [English](../en/MIGRATION.md)

## ارتقا از v0.5.x به v0.6.x

نسخه v0.6 پروتکل Outbound Agent نسخه v0.5 را حفظ می‌کند و Fleet Management، RBAC، Audit، Config History/Rollback، Monitoring پیشرفته، Maintenance و Scheduler را اضافه می‌کند.

Migration روی cPanel **بدون SSH و بدون Terminal** قابل انجام است.

## ۱. Runtime Fileها را نگه دار

این موارد را حفظ کن:

```text
config.php
releases/
```

`config.php` را با `config.example.php` جایگزین نکن.

محل فیزیکی نصب مهم نیست؛ v0.6 از همان فولدری که Backend فعلی Deploy شده اجرا می‌شود.

## ۲. Deploy نسخه v0.6 با File Manager

ZIP آخر Repository را از GitHub دانلود کن، داخل یک فولدر موقت Extract کن و محتویات `server/` را روی فولدر فعلی Backend کپی کن.

فولدر مقصد را قبل از Copy پاک نکن.

نمونه مسیرهای معتبر:

```text
public_html/pw
public_html/tools/openwrt
public_html/network/control
Document Root یک Subdomain
```

هیچ مسیر اجباری مثل `/home/USER/public_html/pwagent` وجود ندارد.

## ۳. Migration دیتابیس

در Browser باز کن:

```text
https://YOUR-BACKEND-URL/health.php
```

اولین Request نسخه v0.6 تابع `ensure_v060_schema()` را اجرا می‌کند.

MySQL User هنگام Migration باید Permissionهای CREATE و ALTER داشته باشد.

v0.6 مواردی مثل این‌ها را به Database اضافه می‌کند:

- Fleet Device Registry
- Device Groupها
- RBAC Userها و Active Device State
- Audit Log
- Metadata مربوط به Config History رمزنگاری‌شده
- Alert Ruleها
- Maintenance State
- Scheduled Actionها
- Metricهای Monitoring جدید

Deviceهای موجود در `config.php` وارد Fleet Registry می‌شوند.

Telegram Userهای قبلی نیز وارد RBAC می‌شوند؛ اولین Allowed User نقش Owner و بقیه نقش Admin می‌گیرند.

## ۴. رفتار Instance Key

نصب تازه با Web Setup در v0.6 مقدار `security.instance_key` را خودکار می‌سازد.

اگر نصب v0.5 این فیلد را نداشته باشد، v0.6 برای Migration از Key Derivation پایدار و سازگار با نسخه قبل استفاده می‌کند و نیازی به ویرایش دستی فوری `config.php` نیست.

بعد از رمزنگاری Device Secretهای Database-backed، Effective Instance Key را خودسرانه تغییر نده.

## ۵. Sync کردن Agent v0.6 بدون Terminal

بعد از Migration Backend وارد:

```text
Telegram → Dashboard → Release Cache
```

شو و Architecture Router را Sync کن یا همه Architectureها را یکجا Sync کن.

Backend قبل از ذخیره Package، SHA256 را Verify می‌کند.

## ۶. Upgrade کردن Agent Router

بعد از آماده‌شدن Release Cache، از Update Flow عادی Telegram/Dashboard استفاده کن.

اگر Router نیاز به Reinstall داشت:

```text
Dashboard → Devices → Provision / Reinstall
```

را بزن تا Command جدید با Token یک‌بارمصرف ۳۰ دقیقه‌ای ساخته شود؛ بدون نیاز به cPanel Terminal.

## ۷. Cron

Cron یک‌دقیقه‌ای فعلی در v0.6 این موارد را اجرا می‌کند:

- Offline/Recovery Monitoring
- Alert Ruleها
- Scheduled Actionها

اگر قبلاً Backend را جابه‌جا کرده‌ای و Cron Path قدیمی شده، در **cPanel → Cron Jobs** مسیر فعلی را اصلاح کن.

در نصب تازه، Web Setup مسیر Absolute واقعی را خودش تشخیص می‌دهد و Cron Command دقیق را چاپ می‌کند.

## ۸. اعتبارسنجی

Browser:

```text
https://YOUR-BACKEND-URL/health.php
```

Telegram:

```text
/start
```

در Dashboard این بخش‌ها را بررسی کن:

```text
Devices
Monitoring
Audit & Rollback
Release Cache
```

روی Router:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

## سازگاری Config History

Config History فقط از زمانی ثبت می‌شود که Agent v0.6 روی Router اجرا شود. Mutationهای قدیمی v0.5 Snapshot قابل Rollback ندارند.

Rollback Snapshot با History Key مخصوص همان Router در مسیر:

```text
/etc/pw-agent/history.key
```

رمزنگاری می‌شود و Backend در عملیات عادی این Key را دریافت نمی‌کند.

## روش CLI اختیاری

اگر SSH/Terminal در دسترس بود، Scriptهای CLI همچنان قابل استفاده‌اند ولی اجباری نیستند:

```bash
php bin/doctor.php
php bin/sync-release.php --all
```
