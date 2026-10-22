# ارتقا از PW Agent v0.6.0 به v1.3.0-rc.1

[نصب جدید](INSTALL_V1_3.md) · [English](../en/UPGRADE_V1_3.md) · [وضعیت قابلیت‌ها](CONTROL_CENTER_PROGRESS.md)

**این یک ارتقای آزمایشی است؛ روی سرور و روتر اصلی انجام نده مگر اینکه دوره تست و بازگشت امن را خودت تکمیل کرده باشی.** نسخه 0.6 فعلی روی روترت کار می‌کند و نباید بدون بکاپ جایگزین شود.

## نکته مهم درباره پایداری اطلاعات
- فایل `config.php` و Instance Key را هرگز حذف یا بازتولید نکن. Secretهای `pw_device_registry` در دیتابیس به این کلید وابسته‌اند.
- با نصب مجدد Setup روی Bot فعلی، Telegram Webhook می‌تواند به Backend دیگری منتقل شود. برای Staging یک Bot جداگانه بساز.
- Backend RC و Agent RC هر دو نسخه `1.3.0-rc.1` دارند؛ اگر Agent قبلی را نگه داری، بعضی فرمان‌های تازه `unsupported action` برمی‌گردانند.
- تنظیمات `/etc/pw-agent/config.json`، `/etc/config/passwall2`، `/etc/config/network`، `/etc/config/wireless` و `/etc/config/firewall` را ذخیره کن.

## ۱. تهیه بکاپ

**cPanel:** از File Manager کل فولدر فعلی Backend و مخصوصاً `config.php` و `releases/` را به مکانی غیرعمومی یا فایل رمزگذاری‌شده بکاپ بگیر. از phpMyAdmin → Export یک خروجی SQL از دیتابیس موجود بگیر. بکاپ دیتابیس را به‌صورت عمومی روی Document Root نگذار.

**OpenWrt (روی LAN با SSH):**

```sh
sysupgrade -b /tmp/pw-before-v130.tar.gz
cp -p /usr/bin/pw-agent /tmp/pw-agent-v060.bin
cp -p /etc/pw-agent/config.json /tmp/pw-agent-v060-config.json
/usr/bin/pw-agent --version
```

این فایل‌ها معمولاً با ریبوت از `/tmp` پاک می‌شوند؛ نسخه مهم را از روتر به محل امن منتقل کن. فایل `config.json` دارای Secret است؛ از انتشار آن خودداری کن.

## ۲. آزمایش روی Staging

اول نسخه جدید را در یک زیرپوشه/ساب‌دامین **مجزا** با Database و Bot آزمایشی مطابق [نصب جدید](INSTALL_V1_3.md) راه‌اندازی کن. تست `health.php`، Provisioning، LuCI، Command Queue، Metrics، Passwall2 و Cron را انجام بده.

## ۳. ارتقای Backend اصلی (فقط پس از تایید عملی)

1. یک بازه نگهداری انتخاب کن؛ عملیات زمان‌بندی‌شده و تغییرات حساس را در این بازه متوقف کن.
2. ZIP رسمی `pw-agent-cpanel-v1.3.0-rc.1.zip` را دریافت و SHA256 آن را تطبیق بده. اگر فایل هنوز در Release نیست، این مرحله امکان‌پذیر نیست.
3. در cPanel یک فولدر **Staging برای Extract** ایجاد کن؛ ZIP را همان‌جا Extract کن. نسخه جدید `config.php` تولیدی ندارد.
4. در File Manager محتویات جدید را روی Backend موجود **بدون حذف فولدر مقصد** کپی کن. فایل قدیمی `config.php`، `releases/` و داده Runtime را حفظ کن؛ فایل‌های جدید `control/`، API و Dashboard باید وارد شوند. از همان مسیر قبلی استفاده کن تا Base URL و Webhook تغییر نکنند.
5. `health.php` را باز کن؛ باید JSON `ok:true` و `version:1.3.0-rc.1` نشان دهد. اگر 500 شد، از `error_log` با جزئیات خطا بررسی کن. **به جای حذف دیتابیس/کانفیگ، بکاپ را بازگردان.**
6. Cron قدیمی را چک کن؛ اگر فعال است، **Cron دوم** نساز. در cPanel زمان‌بندی Once Per Minute را نگه دار و مسیر واقعی `cron/monitor.php` را بررسی کن.
7. Mini App تلگرام را باز کن؛ کاربر Owner، Device Scope، Fleet و Command Queue باید درست باشند.

## ۴. ارتقای Agent

اول روی روتر آزمایش، نسخه امضاشده RC را نصب/آپدیت و سلامت اتصال را بررسی کن؛ روی Production تنها پس از تأیید مراحل قبل انجام بده. **از فرمان Provision با توکن جدید برای ارتقای عادی استفاده نکن**؛ آن برای نصب اولیه/بازصدور مشخصات اتصال است.

در روتر:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
/usr/bin/pw-agent capabilities-json
/usr/bin/pw-agent network-doctor-json
logread -e pw-agent
```

اگر آپدیت Agent با خطا متوقف شد یا سرویس آنلاین نشد، باینری و سرویس نسخه 0.6 را طبق بکاپ قبلی برگردان؛ تنظیمات Passwall2 را بی‌دلیل Reset نکن.

## ۵. بررسی و Rollback

**Acceptance checklist:** `health.php`، احراز هویت Telegram، Online/Heartbeat، اجرای و تکمیل فرمان بدون Replay، فهرست نودها، Snapshot/Backup، روتر LuCI محلی و Cron/Monitoring.

**Rollback Backend:** Snapshot فولدر قبل از ارتقا + بکاپ MySQL را بازگردان. بازگرداندن صرف فایل PHP به نسخه قدیمی بدون هماهنگی Schema جدید ممکن است خطرناک باشد؛ بازگشت پایگاه‌داده را در Staging آزمایش کن. Bot Webhook باید همچنان به URL اصلی درست اشاره کند.

**Rollback Agent:** باینری قبلی `/usr/bin/pw-agent` و فایل init قبلی را از بکاپ بازگردان، مجوز اجرایی را حفظ کن و `/etc/init.d/pw-agent restart` بزن. فایل JSON Secret را تنها در صورت نیاز به نسخه قبلی بازگردان.

## نتیجه

این نسخه **با انتشار v1.3.0 Stable برابر نیست**؛ برای قابلیت‌هایی مثل تغییر Kill Switch، باند مودم، Wi-Fi یا محدودسازی کلاینت‌ها به تست روی سخت‌افزار واقعی و Rollback نیاز داریم. [چک‌لیست کامل](CONTROL_CENTER_PROGRESS.md).
