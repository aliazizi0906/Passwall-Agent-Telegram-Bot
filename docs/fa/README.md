# مستندات PW Agent — فارسی

> **نسخه ۱.۳ آزمایشی:** برای پنل PW Control Center، نصب تازه و ارتقا از v0.6 به راهنمای [نصب v1.3 RC](INSTALL_V1_3.md)، [ارتقا](UPGRADE_V1_3.md) و [وضعیت قابلیت‌ها](CONTROL_CENTER_PROGRESS.md) مراجعه کن. متن‌های پایین این صفحه درباره سری پایدار v0.6 هستند؛ پکیج ۱.۳ هنوز نسخه پایدار نیست.

[← صفحه اصلی پروژه](../../README.md) · [English](../en/README.md)

## منو

| بخش | توضیح |
|---|---|
| [نصب](INSTALL.md) | Web Setup بدون Terminal روی cPanel + نصب OpenWrt |
| [قابلیت‌ها](FEATURES.md) | معرفی کامل امکانات |
| [Fleet و Automation](FLEET.md) | Multi-Device، RBAC، Audit، Monitoring و Scheduler در v0.6 |
| [معماری](ARCHITECTURE.md) | اجزا و جریان درخواست‌ها |
| [امنیت](SECURITY.md) | احراز هویت، رمزنگاری و اعتماد Update |
| [به‌روزرسانی](UPDATES.md) | آپدیت کاربران و Release برای Maintainer |
| [مهاجرت](MIGRATION.md) | ارتقا از نسخه‌های قدیمی |
| [توسعه](DEVELOPMENT.md) | Build، Test و CI |
| [رفع اشکال](TROUBLESHOOTING.md) | Diagnostics و خطاهای رایج |

## PW Agent چیست؟

PW Agent یک سیستم Self-hosted برای مدیریت OpenWrt و Passwall2 از طریق Telegram است. یک Agent نوشته‌شده با Go روی روتر اجرا می‌شود و از طریق HTTPS به Backend مبتنی بر PHP/MySQL متصل می‌شود. درخواست‌های Telegram به Actionهای محدود و مشخص تبدیل می‌شوند و نتیجه دوباره به Telegram برمی‌گردد.

این معماری برای روترهایی که پشت CGNAT هستند یا IP Public/Static ندارند مناسب است، چون نیازی به باز کردن پورت مدیریتی ورودی روی اینترنت ندارد.

## معماری‌های پشتیبانی‌شده

| معماری CPU / OpenWrt | نام Release |
|---|---|
| ARMv7 / armv7l | `armv7` |
| ARM64 / aarch64 | `arm64` |
| x86_64 | `amd64` |
| x86 / i386-i686 | `386` |
| MIPS big-endian | `mips` |
| MIPS little-endian | `mipsle` |

Passwall2 باید از قبل روی روتر نصب باشد.

**مرحله بعد:** [نصب PW Agent](INSTALL.md)


## مدل نصب روی cPanel

در v0.6 هیچ نیازی به cPanel Terminal یا مسیر ثابت فایل‌سیستم نیست.

محتویات `server/` را در هر فولدر Web-accessible آپلود کن و `setup.php` را باز کن. Web Setup آدرس واقعی Deployment را تشخیص می‌دهد، Schema و Config را می‌سازد، Telegram Webhook را ثبت می‌کند و Cron Command و Provisioning Command دقیق همان نصب را تحویل می‌دهد.
