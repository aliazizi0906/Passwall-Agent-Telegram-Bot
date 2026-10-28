# مستندات PW Agent — فارسی

> **آخرین Release در GitHub: v1.3.0-rc.2.** این نسخه با برچسب Latest منتشر شده است، اما نام RC و محدودیت‌های آمادگی برای Production همچنان برقرار است. برای نصب و ارتقا از [راهنمای نصب](INSTALL_V1_3.md)، [ارتقا](UPGRADE_V1_3.md) و [وضعیت قابلیت‌ها](CONTROL_CENTER_PROGRESS.md) استفاده کن. راهنماهای v0.6 مربوط به نسخه پایه قدیمی‌اند.

[← صفحه اصلی پروژه](../../README.md) · [English](../en/README.md)

## منو

| بخش | توضیح |
|---|---|
| [نصب v1.3 RC](INSTALL_V1_3.md) | راهنمای آخرین Release در GitHub (نسخه RC) |
| [نصب Legacy](INSTALL.md) | راهنمای نسخه پایدار v0.6.0 |
| [یادداشت انتشار RC2](RELEASE_NOTES_V1_3_RC.md) | تغییرات و محدودیت‌های v1.3.0-rc.2 |
| [قابلیت‌ها](FEATURES.md) | معرفی کامل امکانات |
| [Fleet و Automation](FLEET.md) | قابلیت‌های پایه v0.6؛ پیش‌نمایش پنل جدید در راهنمای v1.3 RC |
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

**مرحله بعد:** [نصب آخرین Release یعنی v1.3.0-rc.2](INSTALL_V1_3.md) · [راهنمای قدیمی v0.6.0](INSTALL.md)


## مدل نصب روی cPanel

در نسخه پایدار v0.6 و نسخه آزمایشی v1.3، Backend به cPanel Terminal یا مسیر ثابت فایل‌سیستم نیاز ندارد.

محتویات `server/` را در هر فولدر Web-accessible آپلود کن و `setup.php` را باز کن. Web Setup آدرس واقعی Deployment را تشخیص می‌دهد، Schema و Config را می‌سازد، Telegram Webhook را ثبت می‌کند و Cron Command و Provisioning Command دقیق همان نصب را تحویل می‌دهد.
