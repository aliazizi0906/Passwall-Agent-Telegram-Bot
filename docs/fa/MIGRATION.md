# مهاجرت و ارتقا

[صفحه مستندات](README.md) · [English](../en/MIGRATION.md)

## ارتقا به v0.5.x از نصب قبلی

نسخه v0.5 پروژه را از Deployment وابسته به یک Device مشخص به مدل نصب عمومی تبدیل می‌کند و Queue Hardening، رمزنگاری پارامترهای حساس، Signed Update، Monitoring و Multi-architecture Release را اضافه می‌کند.

### ۱. حفظ Runtime Configuration

فایل زیر را Overwrite نکنید:

```text
server/config.php
```

اگر Releaseهای Runtime را قبلاً Sync کرده‌اید، آن‌ها را هم حفظ کنید.

### ۲. آپدیت Backend

Source را Pull کرده و فایل‌های جدید `server/` را Deploy کنید، ولی `config.php` و Runtime Release Assets را Exclude کنید.

### ۳. تغییرات Database

MySQL User در اولین اجرای v0.5 باید مجوزهای `ALTER` و `CREATE` داشته باشد تا `ensure_v050_schema()` بتواند Schema جدید را اعمال کند.

یا می‌توانید:

```text
server/schema.sql
```

را دستی Import کنید.

v0.5 فیلدهای Lease/Idempotency و Tableهای Metrics، Events، Alert State و Provisioning Token را اضافه می‌کند.

### ۴. Sync کردن Signed Release

مثال برای ARMv7:

```bash
php bin/sync-release.php --arch=armv7
```

معماری‌های پشتیبانی‌شده:

```text
armv7
arm64
amd64
386
mips
mipsle
```

### ۵. ارتقای Agent روتر

از Release v0.5 استفاده کنید یا Provisioning Token جدید بسازید:

```bash
php bin/new-provision-token.php
```

سپس دستور تولیدشده را روی OpenWrt اجرا کنید.

Public Key رسمی Ed25519 داخل Agent نسخه v0.5 Embed شده است و کاربر عادی نیازی به ساخت Signing Key ندارد.

### ۶. اضافه کردن Cron مانیتورینگ

```cron
* * * * * /usr/bin/php /absolute/path/to/cron/monitor.php >/dev/null 2>&1
```

### ۷. اعتبارسنجی

Backend:

```bash
php bin/doctor.php
```

Router:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

Telegram:

```text
/start
```

## مهاجرت Fork با Update Trust مستقل

Forkی که Release امضاشده مستقل می‌خواهد باید:

1. Ed25519 Key Pair جدید بسازد.
2. `UPDATE_PUBLIC_KEY.b64` را جایگزین کند.
3. Public Key Embed‌شده در Source Agent را تغییر دهد.
4. Private Key متناظر را فقط در GitHub Actions Secret نگه دارد.
5. Releaseهای خودش را Build و منتشر کند.

Private Signing Key را هیچ‌وقت Commit نکنید.
