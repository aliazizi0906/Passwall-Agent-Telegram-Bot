# مهاجرت و ارتقا

[صفحه مستندات](README.md) · [English](../en/MIGRATION.md)

## ارتقا از v0.5.x به v0.6.x

نسخه v0.6 پروتکل Outbound Agent در v0.5 را حفظ می‌کند و لایه Fleet را روی آن اضافه می‌کند. Device ID و Device Secret موجود در نصب v0.5 به‌صورت خودکار وارد Device Registry جدید می‌شوند.

### ۱. حفظ Runtime Files

فایل config.php و پوشه Runtime مربوط به Releaseها را حفظ کنید.

config.php را با config.example.php جایگزین نکنید.

### ۲. Deploy کردن Backend نسخه v0.6

Source را Pull کنید و فایل‌های جدید server را با حفظ Runtime Files Deploy کنید.

~~~bash
cd ~/Passwall-Agent-Telegram-Bot
git pull --ff-only

rsync -a \
  --exclude='config.php' \
  --exclude='releases/*.tar.gz' \
  --exclude='releases/*.sig' \
  --exclude='releases/*.sha256' \
  ~/Passwall-Agent-Telegram-Bot/server/ \
  ~/public_html/pwagent/
~~~

### ۳. اعمال Schema نسخه v0.6

اولین Request در v0.6 تابع ensure_v060_schema() را اجرا می‌کند.

MySQL User هنگام Migration باید مجوز CREATE و ALTER داشته باشد. در صورت نیاز می‌توان server/schema.sql را دستی Import کرد.

Tableهای جدید:

- pw_device_registry
- pw_device_groups
- pw_users
- pw_user_state
- pw_audit_log
- pw_config_changes
- pw_alert_rules
- pw_maintenance
- pw_schedules

Telegram Userهای قبلی وارد RBAC می‌شوند. اولین User موجود در allowed_user_ids نقش Owner و سایر Userها نقش Admin می‌گیرند.

Deviceهای قبلی نیز به Fleet Registry منتقل می‌شوند.

### ۴. Instance Key

نصب جدید security.instance_key را خودکار تولید می‌کند.

برای نصب‌های v0.5 که این مقدار را ندارند، v0.6 از Key Derivation سازگار با نسخه قبلی استفاده می‌کند و Migration بدون ویرایش فوری config.php انجام می‌شود.

پس از ساخته‌شدن Device Secretهای Database-backed، Key را بدون Migration تغییر ندهید چون Secretهای رمزنگاری‌شده دیگر قابل باز شدن نخواهند بود.

### ۵. آپدیت Agent

Signed Package نسخه v0.6 را برای معماری روتر Sync کنید و سپس از مسیر Update عادی Agent یا Provisioning مجدد استفاده کنید.

~~~bash
php bin/sync-release.php --arch=armv7
~~~

Agent v0.6 Telemetry پیشرفته و Snapshot رمزنگاری‌شده Config History برای Rollback را اضافه می‌کند.

### ۶. Cron الزامی است

Cron یک‌دقیقه‌ای علاوه بر Monitoring، Alert Rule و Scheduled Action را هم اجرا می‌کند:

~~~cron
* * * * * /usr/bin/php /absolute/path/to/cron/monitor.php >/dev/null 2>&1
~~~

Maintenance Mode برای Device مربوطه Alertها را Mute و Scheduleهای Due را Pause می‌کند.

### ۷. اعتبارسنجی

Backend:

~~~bash
php bin/doctor.php
~~~

Router:

~~~sh
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
~~~

Telegram:

~~~text
/start
~~~

بررسی کنید Router قبلی در Fleet دیده شود و Dashboard Monitoring داده دریافت کند.

## اضافه کردن Router جدید

برای Routerهای جدید config.php را دستی ویرایش نکنید.

در Telegram:

~~~text
/start → Fleet / Devices → Add Device
~~~

یا از Mini App Dashboard استفاده کنید.

دستور تولیدشده فقط Provisioning Token یک‌بارمصرف دارد و Device Secret دائمی را نمایش نمی‌دهد.

## نکته Rollback

Config History فقط از زمانی ثبت می‌شود که Router Agent نسخه v0.6 را اجرا کند. Mutationهای v0.5 را نمی‌توان به‌صورت Retroactive بازسازی کرد.

Rollback Snapshot روی Agent با History Key مخصوص Router در `/etc/pw-agent/history.key` رمزنگاری می‌شود و Backend در عملیات عادی Config History این Key را دریافت نمی‌کند.
