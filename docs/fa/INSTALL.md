# نصب PW Agent

[صفحه مستندات](README.md) · [English](../en/INSTALL.md)

## منو

- [پیش‌نیازها](#پیشنیازها)
- [۱. بررسی OpenWrt](#۱-بررسی-openwrt)
- [۲. آماده‌سازی cPanel](#۲-آمادهسازی-cpanel)
- [۳. ساخت دیتابیس MySQL](#۳-ساخت-دیتابیس-mysql)
- [۴. ساخت Telegram Bot](#۴-ساخت-telegram-bot)
- [۵. دریافت پروژه از GitHub](#۵-دریافت-پروژه-از-github)
- [۶. استقرار Backend](#۶-استقرار-backend)
- [۷. اجرای Setup](#۷-اجرای-setup)
- [۸. بررسی Backend](#۸-بررسی-backend)
- [۹. نصب Agent روی OpenWrt](#۹-نصب-agent-روی-openwrt)
- [۱۰. بررسی Agent](#۱۰-بررسی-agent)
- [۱۱. فعال‌سازی Cron مانیتورینگ](#۱۱-فعالسازی-cron-مانیتورینگ)
- [۱۲. تست Telegram](#۱۲-تست-telegram)
- [آپدیت‌های بعدی](#آپدیتهای-بعدی)

## پیش‌نیازها

### هاست cPanel

- PHP 8.1 یا جدیدتر
- MySQL یا MariaDB
- افزونه‌های PHP: `pdo_mysql`، `curl` و `openssl`
- HTTPS
- دسترسی cPanel Terminal یا SSH
- دسترسی Cron Jobs برای Monitoring
- Git در محیط Shell

### روتر

- OpenWrt
- نصب بودن Passwall2
- دسترسی اینترنت
- `wget` یا `curl`
- `sha256sum`
- `tar`

برای روتر نیازی به IP Public نیست.

## ۱. بررسی OpenWrt

با SSH وارد روتر شوید:

```sh
uname -m
test -f /etc/config/passwall2 && echo "Passwall2: OK" || echo "Passwall2: NOT FOUND"
command -v wget || command -v curl
command -v sha256sum
command -v tar
```

| خروجی `uname -m` | معماری PW Agent |
|---|---|
| `armv7l` | `armv7` |
| `aarch64` | `arm64` |
| `x86_64` | `amd64` |
| `i386` / `i686` | `386` |
| `mips` | `mips` |
| `mipsel` | `mipsle` |

## ۲. آماده‌سازی cPanel

یک Domain یا Subdomain اختصاصی بسازید:

```text
pw.example.com
```

Document Root نمونه:

```text
/home/CPANEL_USER/public_html/pwagent
```

SSL را فعال کنید.

نسخه PHP و Extensionها را بررسی کنید:

```bash
php -v
php -m | grep -Ei 'pdo_mysql|curl|openssl'
```

در بعضی سرورهای cPanel باید از PHP مشخص استفاده شود:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php -v
```

اگر PHP پیش‌فرض CLI قدیمی است، در مراحل بعد همان Binary را جایگزین `php` کنید.

## ۳. ساخت دیتابیس MySQL

در cPanel → **MySQL Databases**:

1. Database بسازید.
2. Database User با Password قوی بسازید.
3. User را به Database متصل کنید.
4. **ALL PRIVILEGES** را فعال کنید.

نمونه:

```text
Database: cpuser_pwagent
User:     cpuser_pwagent
Host:     localhost
Port:     3306
```

## ۴. ساخت Telegram Bot

در Telegram وارد `@BotFather` شوید:

```text
/newbot
```

Bot را بسازید و Token را نگه دارید. قبل از Setup حداقل یک پیام مثل `/start` برای Bot ارسال کنید.

برای به‌دست آوردن Telegram Numeric User ID، قبل از ثبت Webhook اجرا کنید:

```bash
BOT_TOKEN='YOUR_BOT_TOKEN'
curl -s "https://api.telegram.org/bot${BOT_TOKEN}/getUpdates"
```

در خروجی به دنبال:

```json
"from": {
  "id": 123456789
}
```

باشید. همین عدد Admin ID است.

## ۵. دریافت پروژه از GitHub

```bash
cd ~
git clone https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot.git
cd ~/Passwall-Agent-Telegram-Bot
git status
```

## ۶. استقرار Backend

```bash
mkdir -p ~/public_html/pwagent
cp -a ~/Passwall-Agent-Telegram-Bot/server/. ~/public_html/pwagent/
```

وجود `.` انتهای Source باعث می‌شود فایل‌های مخفی مثل `.htaccess` هم کپی شوند.

اگر Document Root شما مسیر دیگری است، مقصد را تغییر دهید.

## ۷. اجرای Setup

```bash
cd ~/public_html/pwagent
php bin/setup.php
```

Setup موارد زیر را درخواست می‌کند:

- Public HTTPS Base URL
- MySQL Host و Port
- Database Name
- Database Username و Password
- Telegram Bot Token
- Telegram Numeric Admin ID
- Router Device ID
- معماری روتر

نمونه:

```text
https://pw.example.com
```

اگر محتوای `server/` مستقیماً داخل Document Root قرار گرفته، `/server` یا `/api` را به URL اضافه نکنید.

Setup به‌صورت خودکار Schema را می‌سازد/به‌روزرسانی می‌کند، Secretها را تولید می‌کند، `config.php` را می‌سازد، Telegram Webhook را ثبت می‌کند، Signed Update Bundle را دانلود می‌کند، Provisioning Token یک‌بارمصرف ۳۰ دقیقه‌ای می‌سازد و دستور نصب OpenWrt را چاپ می‌کند.

`config.php` را Commit یا Public نکنید.

## ۸. بررسی Backend

```bash
php bin/doctor.php
```

در مرورگر نیز باز کنید:

```text
https://pw.example.com/health.php
```

خروجی مورد انتظار:

```json
{
  "ok": true,
  "version": "0.6.0"
}
```

## ۹. نصب Agent روی OpenWrt

دستور دقیق تولیدشده توسط Setup را روی OpenWrt اجرا کنید. نمونه:

```sh
PW_SERVER_BASE='https://pw.example.com' \
PW_PROVISION_TOKEN='ONE_TIME_TOKEN' \
sh -c "$(wget -qO- 'https://raw.githubusercontent.com/aliazizi0906/Passwall-Agent-Telegram-Bot/main/openwrt/install-remote.sh')"
```

Installer معماری CPU را تشخیص می‌دهد، Release مناسب را دانلود می‌کند، SHA256 را بررسی می‌کند، Token را مصرف می‌کند، Device ID/Secret را می‌گیرد، `/etc/pw-agent/config.json` را می‌سازد، Agent و LuCI Integration را نصب می‌کند و Service را Start می‌کند.

Device Secret دائمی داخل دستور نصب قرار نمی‌گیرد.

اگر Token منقضی یا مصرف شد:

```bash
php bin/new-provision-token.php
```

برای گرفتن Token جدید Setup را با `--force` اجرا نکنید.

## ۱۰. بررسی Agent

```sh
/usr/bin/pw-agent --version
pgrep -af pw-agent
logread -e pw-agent
/usr/bin/pw-agent diagnose-json
```

در حالت سالم باید:

```json
"ok": true
```

دیده شود.

کانفیگ Agent:

```text
/etc/pw-agent/config.json
```

این فایل Secret دارد؛ آن را کامل در محیط عمومی منتشر نکنید.

در LuCI نیز می‌توانید **Services → PW Agent** را باز کنید.

## ۱۱. فعال‌سازی Cron مانیتورینگ

مسیر PHP:

```bash
which php
```

Cron نمونه:

```cron
* * * * * /usr/bin/php /home/CPANEL_USER/public_html/pwagent/cron/monitor.php >/dev/null 2>&1
```

مسیرها را مطابق هاست خودتان تغییر دهید.

## ۱۲. تست Telegram

برای Bot ارسال کنید:

```text
/start
```

ابتدا Status، Diagnostics، Monitoring، وضعیت Passwall2 و Node List را تست کنید. بعد از سالم بودن ارتباط Agent، عملیات تغییردهنده را امتحان کنید.

## آپدیت‌های بعدی

```bash
cd ~/Passwall-Agent-Telegram-Bot
git pull --ff-only
```

Backend را بدون overwrite کردن Runtime Config و Releaseها Deploy کنید:

```bash
rsync -a \
  --exclude='config.php' \
  --exclude='releases/*.tar.gz' \
  --exclude='releases/*.sig' \
  --exclude='releases/*.sha256' \
  ~/Passwall-Agent-Telegram-Bot/server/ \
  ~/public_html/pwagent/
```

سپس:

```bash
cd ~/public_html/pwagent
php bin/doctor.php
```

Sync یک معماری:

```bash
php bin/sync-release.php --arch=armv7
```

یا همه معماری‌ها:

```bash
php bin/sync-release.php --all
```

جزئیات بیشتر: [به‌روزرسانی](UPDATES.md)


## اضافه کردن Routerهای بیشتر در v0.6

بعد از نصب اولیه Backend، برای هر Router جدید `config.php` را دستی تغییر ندهید.

در Telegram:

```text
/start
→ Fleet / Devices
→ Add Device
```

یا Mini App **Dashboard** را باز کرده و **Add device** را انتخاب کنید.

یک Display Name وارد کنید. PW Agent به‌صورت خودکار Device ID، Device Secret رمزنگاری‌شده و Provisioning Token یک‌بارمصرف می‌سازد و دستور نصب با اعتبار ۳۰ دقیقه تحویل می‌دهد.

همان دستور را روی OpenWrt جدید اجرا کنید. بعد از اولین Poll، Router در Fleet به‌صورت Online دیده می‌شود.

سپس از Dashboard می‌توانید برای Router موارد زیر را تنظیم کنید:

- Group
- Location
- Tag

### RBAC بعد از نصب

Telegram ID که در Setup اولیه وارد شده به‌عنوان **Owner** ثبت می‌شود. Userهای جدید از مسیر زیر قابل اضافه‌شدن هستند:

```text
Dashboard → Access
```

Roleها شامل Owner، Admin، Operator و Viewer هستند. برای Operator و Viewer می‌توان Device Scope محدود تعریف کرد.

### وظایف Cron در v0.6

Cron یک‌دقیقه‌ای در v0.6 برای سه بخش استفاده می‌شود:

- Monitoring و Offline/Recovery Alert
- ارزیابی Alert Ruleها
- اجرای Scheduled Actionها

Maintenance Mode برای Device انتخاب‌شده Alertها را Mute و Scheduleها را Pause می‌کند.
