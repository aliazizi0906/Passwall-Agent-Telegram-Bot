# مستندات PW Agent — فارسی

> **Latest GitHub Release: v1.4.0-alpha.2.** این نسخه بنا به درخواست مالک به‌عنوان Latest منتشر می‌شود، اما **Alpha و غیرپایدار** است؛ تضمین عملکرد روی تمام روترها ندارد. [یادداشت انتشار](../en/RELEASE_NOTES_V1_4_ALPHA_LATEST.md) و [ماتریس ۱۴ قابلیت](V1_4_EXECUTION.md) را بخوانید. نسخه ۱.۳ RC قدیمی‌تر است و v0.6 خط مبنای پایدار قبلی است.

[← صفحه اصلی پروژه](../../README.md) · [English](../en/README.md)

## نسخه عمومی Alpha v1.4 (Latest، نه Stable)
- [یادداشت انتشار ۱.۴ آلفا ۲ و محدودیت‌های عملیاتی](../en/RELEASE_NOTES_V1_4_ALPHA_2.md)
- [تصویر جدید داشبورد](../assets/previews/control-center-v140-operations.svg)
- [پیش‌نمایش OpenVPN و LED](../assets/previews/openvpn-led-v140-controls.svg)
- [وضعیت و مراحل اجرایی تمام ۱۴ قابلیت](V1_4_EXECUTION.md)

## منو

| بخش | توضیح |
|---|---|
| [نصب v1.3 RC](INSTALL_V1_3.md) | راهنمای نسخه قدیمی‌تر RC2؛ برای v1.4 راهنمای انتشار Alpha را بخوانید |
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
