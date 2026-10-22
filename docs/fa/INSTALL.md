# نصب PW Agent

> **نسخه ۱.۳ آزمایشی:** برای پنل PW Control Center، نصب تازه و ارتقا از v0.6 به راهنمای [نصب v1.3 RC](INSTALL_V1_3.md)، [ارتقا](UPGRADE_V1_3.md) و [وضعیت قابلیت‌ها](CONTROL_CENTER_PROGRESS.md) مراجعه کن. متن‌های پایین این صفحه درباره سری پایدار v0.6 هستند؛ پکیج ۱.۳ هنوز نسخه پایدار نیست.

[صفحه مستندات](README.md) · [English](../en/INSTALL.md)

نسخه v0.6 طوری طراحی شده که روی هاست cPanel **بدون SSH و بدون cPanel Terminal** نصب شود.

مسیر Backend کاملاً Path-independent است. محتویات فولدر `server/` را می‌توانی در هر مسیر Web-accessible آپلود کنی؛ مثلاً:

```text
public_html/pw
public_html/tools/router
public_html/network/pw-agent
Document Root یک Subdomain
```

هیچ مسیر ثابتی مثل `/home/USER/public_html/pwagent` در پروژه اجباری یا Hardcode نشده است.

## پیش‌نیازها

### هاست cPanel

- PHP 8.1 یا جدیدتر
- MySQL یا MariaDB
- Extensionهای PHP: `pdo_mysql`، `curl` و `openssl`
- HTTPS
- cPanel File Manager
- cPanel MySQL Databases
- cPanel Cron Jobs

**Terminal، SSH و Git روی cPanel اختیاری هستند.**

### Router

- OpenWrt
- نصب بودن Passwall2
- اینترنت
- `wget` یا `curl`
- `sha256sum`
- `tar`

Router نیازی به IP Public یا Static ندارد.

## ۱. ساخت Database از داخل cPanel

وارد:

**cPanel → MySQL Databases**

شو و:

1. Database بساز.
2. Database User با Password قوی بساز.
3. User را به Database اضافه کن.
4. **ALL PRIVILEGES** را فعال کن.

این اطلاعات را نگه دار:

```text
MySQL Host
MySQL Port
Database Name
Database Username
Database Password
```

در اغلب cPanelها Host برابر `localhost` و Port برابر `3306` است.

## ۲. ساخت Telegram Bot

در Telegram وارد `@BotFather` شو و اجرا کن:

```text
/newbot
```

Bot Token را نگه دار.

سپس Bot جدیدت را باز کن و برایش بفرست:

```text
/start
```

برای پیدا کردن Telegram Numeric ID دیگر نیازی به curl یا Terminal نیست؛ خود Web Setup می‌تواند Updateهای اخیر Bot را بخواند و IDها را نشان دهد.

## ۳. آپلود Backend با cPanel File Manager

ساده‌ترین روش این است که از Release رسمی **v0.6.0** فایل زیر را دانلود کنی:

```text
pw-agent-cpanel-v0.6.0.zip
```

در cPanel هر فولدر دلخواهی زیر Document Root بساز، ZIP را همان‌جا Upload کن و با **File Manager → Extract** بازش کن. محتویات ZIP مستقیماً فایل‌های Backend هستند و `setup.php` و فایل‌های لازم `.htaccess` را هم شامل می‌شوند.

روش جایگزین این است که ZIP کامل Repository را دانلود کنی، آن را Extract کنی، وارد فولدر `server/` شوی و **محتویات داخل `server/`** را Upload کنی.

Backend را می‌توانی در هر مسیر دلخواه زیر Document Root قرار بدهی.

مثال:

```text
public_html/pw
public_html/apps/openwrt
public_html/network/control
```

یا می‌توانی از Document Root یک Subdomain استفاده کنی.

همان فولدر انتخابی، Base URL پروژه می‌شود.

مثلاً:

```text
public_html/pw
→ https://example.com/pw

public_html/network/control
→ https://example.com/network/control
```

حتماً فایل‌های مخفی مثل `.htaccess` هم آپلود شوند.

## ۴. باز کردن Web Setup

URL همان فولدر را در Browser باز کن:

```text
https://example.com/your/chosen/path/
```

اگر `config.php` وجود نداشته باشد، `index.php` به‌صورت خودکار به:

```text
setup.php
```

Redirect می‌کند.

می‌توانی مستقیم هم باز کنی:

```text
https://example.com/your/chosen/path/setup.php
```

Setup مسیر URL را بر اساس محل واقعی فایل تشخیص می‌دهد و هیچ Username یا مسیر ثابتی برای cPanel فرض نمی‌کند.

## ۵. تکمیل فرم Web Setup

Wizard در Browser این موارد را بررسی می‌کند:

- نسخه PHP
- Extensionهای موردنیاز
- Writable بودن فولدر
- اتصال MySQL
- معتبر بودن Telegram Bot

موارد زیر را وارد می‌کنی:

- Public HTTPS Base URL
- MySQL Host / Port / Database / User / Password
- Telegram Bot Token
- Telegram Numeric Owner ID
- Device ID اولیه
- Display Name روتر
- معماری Router
- Timezone مربوط به Scheduler

### پیدا کردن Telegram ID بدون Terminal

بعد از وارد کردن Bot Token روی:

**Detect my Telegram ID**

بزن.

Setup از سمت Server به Telegram وصل می‌شود و Userهایی که اخیراً به Bot پیام داده‌اند را نشان می‌دهد. روی ID خودت کلیک کن تا فیلد Owner پر شود.

## ۶. Web Setup چه کارهایی انجام می‌دهد؟

با زدن **Install PW Agent**:

1. Schema دیتابیس ساخته/Upgrade می‌شود.
2. Device Secret، Webhook Secret و Instance Key ساخته می‌شوند.
3. `config.php` به‌شکل Atomic ایجاد می‌شود.
4. Fleet Device Registry ساخته می‌شود.
5. Owner اولیه در RBAC ثبت می‌شود.
6. Telegram Webhook ثبت می‌شود.
7. Provisioning Token یک‌بارمصرف ۳۰ دقیقه‌ای ساخته می‌شود.
8. در صورت انتخاب، Signed Agent Release برای معماری Router دانلود و SHA256 آن Verify می‌شود.
9. دستور دقیق نصب روی OpenWrt تولید می‌شود.
10. دستور Cron بر اساس **مسیر واقعی همان فولدر روی هاست** تولید می‌شود.

بعد از ساخته‌شدن `config.php`، Web Setup قفل می‌شود و از راه دور نمی‌توان تنظیمات نصب را Overwrite کرد.

## ۷. ساخت Cron بدون Terminal

صفحه موفقیت Setup یک Cron Command دقیق بر اساس مسیر واقعی فایل‌ها نمایش می‌دهد.

در cPanel وارد:

**cPanel → Cron Jobs**

شو، **Once Per Minute** را انتخاب کن و همان Command تولیدشده را Paste کن.

شکل کلی آن مثلاً این است:

```cron
* * * * * '/usr/local/bin/php' '/actual/path/chosen/by/you/cron/monitor.php' >/dev/null 2>&1
```

مسیر نمونه‌ای مثل `/home/USER/...` را از مستندات کپی نکن؛ Command تولیدشده توسط Setup خودت را استفاده کن.

Cron این بخش‌ها را اجرا می‌کند:

- Offline/Recovery Monitoring
- Alert Ruleها
- Scheduled Actionها

## ۸. نصب Agent روی OpenWrt

دستور Router که Web Setup تولید کرده را Copy کن و روی OpenWrt اجرا کن.

شکل کلی:

```sh
PW_SERVER_BASE='https://example.com/your/chosen/path' \
PW_PROVISION_TOKEN='ONE_TIME_TOKEN' \
PW_AGENT_VERSION='v0.6.0' \
sh -c "$(wget -qO- 'https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh')"
```

Token فقط یک بار قابل استفاده است و بعد از ۳۰ دقیقه Expire می‌شود. Command تولیدشده به نسخه Agent همان Backend Pin می‌شود؛ بنابراین اگر GitHub `latest` قدیمی‌تر باشد، نسخه اشتباه به‌صورت Silent نصب نخواهد شد.

Installer:

- معماری CPU را تشخیص می‌دهد.
- Release رسمی مناسب را از GitHub می‌گیرد.
- SHA256 را Verify می‌کند.
- Provisioning Token را مصرف می‌کند.
- Device ID و Device Secret را دریافت می‌کند.
- `/etc/pw-agent/config.json` را می‌سازد.
- Agent و LuCI را نصب می‌کند.
- Service را Start می‌کند.

Device Secret دائمی داخل دستور نصب قرار نمی‌گیرد.

## ۹. اعتبارسنجی

در Browser باز کن:

```text
https://example.com/your/chosen/path/health.php
```

انتظار:

```json
{
  "ok": true,
  "version": "0.6.0"
}
```

روی OpenWrt:

```sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
pgrep -af pw-agent
logread -e pw-agent
```

بعد در Telegram برای Bot بفرست:

```text
/start
```

## ۱۰. Release Cache بدون Terminal cPanel

از مسیر:

```text
Telegram → Dashboard → Release Cache
```

Owner/Admin دارای Permission مربوط به Agent Update می‌تواند:

- Cache معماری‌ها را ببیند.
- یک معماری را Sync کند.
- هر شش معماری را یکجا Sync کند.

Dashboard فایل‌های Release رسمی GitHub را Download می‌کند و قبل از ذخیره در Backend Cache، SHA256 را Verify می‌کند.

بنابراین دیگر برای این کار نیازی به:

```text
php bin/sync-release.php
```

در cPanel Terminal نیست.

## ۱۱. اضافه کردن Routerهای بیشتر

در Telegram:

```text
/start
→ Fleet / Devices
→ Add Device
```

یا:

```text
Dashboard → Devices → Add device
```

PW Agent یک Device Secret جدید و رمزنگاری‌شده و یک Provisioning Command یک‌بارمصرف می‌سازد.

نیازی به ویرایش دستی `config.php` نیست.

## ۱۲. آپدیت Backend بدون Terminal

با cPanel File Manager:

1. ZIP نسخه جدید Repository را دانلود کن.
2. آن را در یک فولدر موقت Extract کن.
3. محتویات `server/` نسخه جدید را روی فولدر فعلی Backend کپی کن.
4. فولدر مقصد را قبل از Copy پاک نکن.
5. `config.php` Runtime فعلی را نگه دار.
6. یک بار `health.php` را باز کن تا Migrationهای Lazy اجرا شوند.
7. از **Dashboard → Release Cache** Bundleهای Agent موردنیاز را Sync کن.

Repository فایل Runtime `config.php` تو را ندارد، بنابراین Copy عادی Source آن را جایگزین نمی‌کند.

برای جزئیات بیشتر: [آپدیت](UPDATES.md) و [Migration](MIGRATION.md).

## روش CLI اختیاری

اگر هاست SSH/Terminal داشت، Scriptهای قبلی همچنان قابل استفاده‌اند:

```bash
php bin/setup.php
php bin/doctor.php
php bin/sync-release.php --all
```

ولی این‌ها اختیاری‌اند و نصب cPanel از طریق Web Setup هیچ وابستگی‌ای به Terminal ندارد.
