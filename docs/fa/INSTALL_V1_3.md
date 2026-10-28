# نصب PW Agent v1.3.0-rc.2 (نسخه آزمایشی)

[صفحه اصلی](../../README.md) · [English](../en/INSTALL_V1_3.md) · [ارتقا از v0.6](UPGRADE_V1_3.md) · [وضعیت قابلیت‌ها](CONTROL_CENTER_PROGRESS.md)

> **آخرین Release گیت‌هاب، با نام RC — ابتدا برای Staging:** بسته‌های امضاشده `v1.3.0-rc.2` از [GitHub Latest](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/latest) قابل دریافت‌اند. این وضعیت به‌معنی تأیید Production نیست و نصب روی روتر حیاتی توصیه نمی‌شود.

## ۱. پیش‌نیازها

**هاست cPanel:** PHP 8.1 یا بالاتر، MySQL/MariaDB، PDO MySQL، cURL، OpenSSL، HTTPS، File Manager، MySQL Databases و Cron Jobs. Git و SSH روی هاست الزامی نیست.

**روتر:** OpenWrt، نصب بودن Passwall2، دسترسی SSH روی LAN، دانلود HTTPS از GitHub، `wget` یا `curl`، `sha256sum` و `tar`. برای صفحه محلی LuCI باید بسته LuCI/uhttpd نصب باشد. قابلیت مودم سلولی و RGB به سخت‌افزار و Driver مخصوص وابسته‌اند.

**محیط تست:** یک ربات تلگرام **جداگانه**، دیتابیس جدا و زیرپوشه یا ساب‌دامین Staging انتخاب کن تا ثبت Webhook روی Bot تولیدی تأثیر نگذارد.

## ۲. دریافت ZIP نسخه درست

از [Release نسخه v1.3.0-rc.2](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/tag/v1.3.0-rc.2) فایل زیر را دریافت کن:

```text
pw-agent-cpanel-v1.3.0-rc.2.zip
pw-agent-cpanel-v1.3.0-rc.2.zip.sha256
```

روی کامپیوتر شخصی فایل ZIP را با SHA256 اعلام‌شده تطبیق بده؛ برای اصالت‌سنجی از فایل امضای `.sig` و کلید معتبر از منبع مستقل استفاده می‌شود. اگر یکی از فایل‌های مورد انتظار موجود نبود، نصب را متوقف کن و از Artifact نامرتبط استفاده نکن.

ZIP شامل **محتویات** `server/` در ریشه آرشیو است؛ **کل Repository یا پوشه server را داخل یک پوشه server دیگر استخراج نکن.**

## ۳. نصب Backend در cPanel

۱. در `cPanel → MySQL Databases` یک Database و User مستقل بساز؛ User را اضافه و مجوز لازم را اختصاص بده.

۲. در `File Manager` مسیر دلخواه را ایجاد کن، مثل:

```text
/home/CPANEL_USERNAME/public_html/pw-test
https://test.example.com/       ← اگر Document Root روی همین پوشه باشد
https://example.com/pw-test/    ← اگر زیرپوشه دامنه اصلی باشد
```

۳. ZIP را در همین مسیر آپلود و Extract کن. ساختار باید این‌طور باشد:

```text
pw-test/
  setup.php
  index.php
  health.php
  api/
  control/
  dashboard/
  cron/
  .htaccess
```

۴. در تلگرام با `@BotFather` یک Bot آزمایشی جدید بساز و `/start` بفرست. توکن Bot را در عکس یا چت منتشر نکن.

۵. در HTTPS، فایل `setup.php` را باز کن؛ MySQL، Bot Token، Owner Telegram Numeric ID، نام/شناسه روتر و Timezone را پر کن. بعد از Install شدن `config.php` ساخته می‌شود و Wizard قفل می‌شود.

۶. `health.php` را باز کن. **در صورت نصب RC انتظار نسخه `1.3.0-rc.2` داری.** خطای 500 در این مرحله نیازمند خواندن PHP `error_log` و بررسی افزونه‌ها/اتصال MySQL است؛ `config.php` را برای رفع آن حذف نکن.

## ۴. Cron در cPanel

در `cPanel → Cron Jobs` زمان‌بندی را روی **Once Per Minute** تنظیم کن و داخل فیلد **Command** تنها قسمت فرمان PHP تولیدشده توسط Setup را بگذار؛ مثلاً:

```sh
'/opt/cpanel/ea-php82/root/usr/bin/php' '/home/CPANEL_USERNAME/public_html/pw-test/cron/monitor.php' >/dev/null 2>&1
```

نباید `* * * * *` را داخل Command تکرار کنی. مسیر PHP و مسیر نصب باید همان‌هایی باشند که Web Setup واقعاً تشخیص می‌دهد.

## ۵. نصب Agent در OpenWrt

بعد از انتشار **بسته امضاشده Agent در همان Release** و تکمیل Setup، **فرمان دقیقی را که Setup می‌دهد** روی روتر آزمایش اجرا کن. شکل عمومی فرمان:

```sh
PW_SERVER_BASE='https://example.com/pw-test' \
PW_PROVISION_TOKEN='ONE_TIME_PRIVATE_TOKEN' \
PW_AGENT_VERSION='v1.3.0-rc.2' \
sh -c "$(wget -qO- 'https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh')"
```

توکن فقط **۳۰ دقیقه** و یک بار معتبر است. اگر مرحله Install شکست خورد، به جای انتشار مجدد توکن قبلی از Dashboard گزینه **Provision / Reinstall** همان روتر را بزن. Package مناسب `armv7`، `arm64`، `amd64`، `386`، `mips` یا `mipsle` انتخاب می‌شود.

## ۶. باز کردن پنل‌ها

**تلگرام:** `/start` → Dashboard → **PW Control Preview**. این نسخه برای ورود به پنل وب از **Telegram Mini App initData** استفاده می‌کند؛ ورود مستقیم مرورگر مستقل هنوز پیاده‌سازی نشده است.

**OpenWrt:** `LuCI → Services → PW Control Center`. این پنل اطلاعات System، Wi-Fi، LAN Clients، Diagnostics، Capabilities و در صورت سازگاری GoogleWifiLED را از خود روتر می‌خواند؛ برای خواندن آن نیاز به اتصال Backend نیست.

## ۷. تست بعد از نصب

روی OpenWrt:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
/usr/bin/pw-agent status-json
/usr/bin/pw-agent capabilities-json
/usr/bin/pw-agent network-doctor-json
/usr/bin/pw-agent clients-json
/usr/bin/pw-agent wifi-json
/usr/bin/pw-agent led-json
/etc/init.d/pw-agent status
logread -e pw-agent
```

در پنل تلگرام: روتر Online، Metrics واقعی، لیست LAN و Wi-Fi، Passwall2 Nodes و Network Doctor را بررسی کن. عملیات تغییردهنده را فقط روی روتر آزمایش انجام بده. **تست CI به معنی تأیید عملی روی مودم 4G/5G یا Google Wifi نیست.**

## ۸. رفع اشکال

- `403` در Provision: توکن اشتباه، مصرف‌شده، منقضی یا مسدودسازی در هاست. توکن تازه بساز.
- `500` در Provision: PHP/MySQL Error Log، Migration و نسخه Backend را بررسی کن.
- `404` هنگام دانلود: Release/Asset نسخه انتخاب‌شده هنوز منتشر نشده یا نام نسخه اشتباه است.
- Mini App باز نمی‌شود: Bot/Webhook، HTTPS، دسترسی کاربر و باز شدن **داخل تلگرام** را بررسی کن.
- دستور جدید `unsupported action`: نسخه Agent روتر هنوز v0.6 است؛ API جدید با Agent قدیمی سازگار نیست.
- پنل LuCI ظاهر نمی‌شود: نصب LuCI، وجود فایل‌های Resource/Menu و RPCD ACL، سپس کش LuCI را بررسی کن.
- LED یا Cellular غیرفعال: نبود سخت‌افزار یا Adapter تأییدشده الزاماً خطا نیست.

**امنیت:** هیچ‌وقت توکن، Password دیتابیس، `config.php` یا `/etc/pw-agent/config.json` را در گزارش عمومی قرار نده. هنگام نصب واقعی ابتدا Backup و روش بازگشت به نسخه قبلی را آماده کن.
