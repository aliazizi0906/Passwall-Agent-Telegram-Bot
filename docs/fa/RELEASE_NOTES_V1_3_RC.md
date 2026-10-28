# PW Agent v1.3.0-rc.2 — یادداشت انتشار

> **آخرین Release گیت‌هاب (با نام RC)**؛ این نسخه در GitHub به‌عنوان Release و Latest معرفی شده، اما هنوز نسخه پایدار/Production-certified یا جایگزین کامل WRTPro نیست. ابتدا فقط در محیط Staging نصب شود.

## وضعیت نسخه

- آخرین Release در GitHub: `v1.3.0-rc.2`
- نسخه پایه قدیمی برای Production: `v0.6.0`
- [دانلود Release و همه بسته‌ها](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v1.3.0-rc.2)

## تغییرات

- هماهنگ‌سازی Version در Go Agent، PHP Setup و Backend روی `1.3.0-rc.2`.
- اصلاح README اصلی برای نمایش صحیح Latest Release و نسخه پایه قدیمی و لینک واقعی ZIP نصب cPanel.
- اصلاح راهنماهای فارسی و انگلیسی برای نصب تازه، ارتقا، به‌روزرسانی، قابلیت‌ها و وضعیت پیاده‌سازی؛ راهنماهای تاریخی v0.6 به‌صورت Legacy مشخص شده‌اند.
- انتشار ZIP مخصوص cPanel و بسته‌های OpenWrt برای شش معماری: `armv7`، `arm64`، `amd64`، `386`، `mips` و `mipsle`.
- انتشار فایل‌های SHA256 و امضای Ed25519 برای بسته‌ها.
- CI و Release workflow شامل تست Go، PHP، JavaScript، Shell، اعتبارسنجی پارامتر فرمان‌ها و ساخت ZIP بدون Runtime Secret است.

## قابلیت‌های موجود در RC

- پنل Telegram Mini App با فارسی/انگلیسی، RTL/LTR و تم روشن/تاریک.
- نمایش Fleet و Metrics واقعی، تاریخچه Monitoring و وضعیت روتر.
- مشاهده/تست/انتخاب Nodeهای Passwall2، ورود لینک و مدیریت Subscription با اعتبارسنجی سمت سرور.
- پنل محلی LuCI با وضعیت سیستم، Wi-Fi، دستگاه‌های LAN، Network Doctor و شناسایی قابلیت سخت‌افزار؛ این بخش برای مشاهده وضعیت به Backend وابسته نیست.
- Agent Diagnostics شامل `agent.capabilities`، `network.doctor` و `led.status`.

## موارد تکمیل‌نشده

محدودسازی سرعت/قطع اینترنت کلاینت، Rollback امن تغییرات WAN/Wi-Fi/Firewall، Kill Switch تضمین‌شده برای DNS/IPv6، کنترل باند/دکل مودم و SMS/USSD، تغییر رنگ RGB LED، SQM/UPnP پیشرفته، Adapterهای VPN و ورود مستقل مرورگر هنوز کامل یا روی سخت‌افزار واقعی تأیید نشده‌اند. جزئیات در [جدول وضعیت پیاده‌سازی](CONTROL_CENTER_PROGRESS.md) آمده است.

## نصب و ارتقا

- [راهنمای نصب RC2 روی cPanel و OpenWrt](INSTALL_V1_3.md)
- [راهنمای ارتقا از v0.6 به RC2](UPGRADE_V1_3.md)
- [README اصلی](../../README.md)
- [Changelog](../../CHANGELOG.md)

از Bot، دیتابیس و روتر آزمایشی جداگانه استفاده کن. برای ارتقا، `config.php`، Instance Key یا دیتابیس را پاک نکن. قبل از هر تغییر، Backup و روش Rollback را آماده کن.
