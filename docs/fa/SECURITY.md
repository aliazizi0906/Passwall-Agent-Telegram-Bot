# امنیت

> **آخرین نسخه عمومی آزمایشی: v1.3.0-rc.2.** این سند بخشی از رفتار امنیتی و Migration نسخه v0.6 را شرح می‌دهد. برای محدوده پنل جدید و موارد تکمیل‌نشده، [جدول وضعیت](CONTROL_CENTER_PROGRESS.md) را ببین. هنگام ارتقا Instance Key قبلی را حفظ کن.

[صفحه مستندات](README.md) · [English](../en/SECURITY.md)

PW Agent بر مبنای ارتباط خروجی روتر و Actionهای مشخص طراحی شده است. نیازی به IP Public روتر یا باز کردن SSH روی WAN ندارد.

## احراز هویت Device

هر روتر دارای:

- Device ID
- Device Secret

است.

Requestهای Agent با HMAC-SHA256 احراز هویت می‌شوند و Responseهای Backend نیز Sign می‌شوند.

فایل‌های `server/config.php` و `/etc/pw-agent/config.json` باید خصوصی بمانند.

## دسترسی Telegram

دسترسی Telegram با Numeric User ID Allowlist محدود می‌شود.

Mini App علاوه بر آن Telegram WebApp `initData` و سن احراز هویت را بررسی می‌کند.

## رمزنگاری Queue

پارامترهای حساس Command به‌صورت Plain JSON در MySQL باقی نمی‌مانند.

برای Actionهای حساس، Backend یک Queue Key از Device Secret مشتق می‌کند و Payload را با AES-256-GCM محافظت می‌کند.

داده‌های Terminal پس از اتمام عملیات در نقاط مربوطه از Queue پاک می‌شوند.

## Provisioning Token

Installer عمومی از Token یک‌بارمصرف استفاده می‌کند:

- Random
- ذخیره Hash با SHA256 در MySQL
- اعتبار پیش‌فرض ۳۰ دقیقه
- فقط یک بار قابل مصرف
- فقط برای تحویل کانفیگ دائمی روتر

به این ترتیب Device Secret دائمی مستقیماً داخل دستور نصب قرار نمی‌گیرد.

## Idempotency و Replay Safety

Mutationها Idempotency Key دارند و Agent یک Journal محلی نگه می‌دارد. اگر عملیات انجام شود اما Result Delivery قطع شود، Retry نباید باعث اجرای مجدد ناخواسته همان Mutation شود.

Leaseها Expire می‌شوند و Retry محدود است.

## اعتماد Update

Release رسمی دو مرحله Verify می‌شود:

1. SHA256 برای Integrity
2. Ed25519 Signature برای Authenticity

Public Key رسمی در:

```text
UPDATE_PUBLIC_KEY.b64
```

وجود دارد و داخل Agent نیز Embed شده است.

Private Signing Key نباید Commit شود و فقط به‌صورت GitHub Actions Secret نزد Maintainer نگه‌داری می‌شود:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

Forkهایی که Trust مستقل می‌خواهند باید Key Pair خودشان را داشته باشند.

## SSH Console

برای SSH Console نیازی به باز کردن SSH روی WAN نیست.

Agent به Dropbear محلی روتر متصل می‌شود. این قابلیت یک Command Console محدود است و PTY تعاملی دائمی نیست.

## توصیه‌های عملیاتی

- فقط HTTPS معتبر استفاده کنید.
- Backend را در Subdomain/Directory مستقل Deploy کنید.
- `config.php` را وارد Git نکنید.
- Password قوی برای MySQL انتخاب کنید.
- دسترسی cPanel را محدود نگه دارید.
- Telegram Allowlist را کوچک نگه دارید.
- در صورت افشای Bot Token آن را Rotate کنید.
- Device Secret را در Issue یا Chat عمومی ارسال نکنید.
- Mutationها را ابتدا روی محیط کنترل‌شده تست کنید.


## Fleet Secret و RBAC در v0.6

Deviceهای جدید در Fleet Registry مبتنی بر Database ذخیره می‌شوند. Device Secret آن‌ها در حالت At-Rest با AES-256-GCM و Instance Key تولیدشده هنگام Setup رمزنگاری می‌شود.

برای Upgrade از v0.5 که هنوز `security.instance_key` ندارد، v0.6 یک Migration Key پایدار از Configuration موجود مشتق می‌کند. بعد از ایجاد Device Secretهای Database-backed، Effective Instance Key را بدون Migration اختصاصی تغییر ندهید.

Roleهای RBAC:

- Owner
- Admin
- Operator
- Viewer

Permission قبل از ورود Command به Queue بررسی می‌شود. برای Operator و Viewer می‌توان Device Scope محدود تعریف کرد.

آخرین Owner فعال از داخل Dashboard قابل Disable یا Demote نیست.

## محرمانگی Config History

Rollback Snapshot توسط Agent با AES-GCM و یک History Key تصادفی ۳۲‌بایتی مخصوص Router در مسیر `/etc/pw-agent/history.key` رمزنگاری می‌شود.

Backend در عملیات عادی Config History این Key را دریافت نمی‌کند و فقط Snapshot رمزنگاری‌شده را نگه می‌دارد. Diffهای Audit/Config History فقط نام UCI Option و نوع تغییر را نمایش می‌دهند، نه Value آن را. Full Backup روتر می‌تواند فایل‌های محلی PW Agent را شامل شود، بنابراین Backup باید Sensitive تلقی شود.

Rollback Payload در Queue نیز Sensitive محسوب می‌شود و بعد از اتمام Command پاک می‌شود.

## احراز هویت Dashboard

Fleet Dashboard یک Telegram Mini App است. هر API Request، Telegram `initData`، Signature و Auth Age را Verify می‌کند و بعد RBAC و Device Scope را اعمال می‌کند.

باز کردن URL Dashboard در Browser عادی به‌تنهایی دسترسی API ایجاد نمی‌کند.
