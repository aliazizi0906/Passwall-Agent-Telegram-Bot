# مدیریت Fleet و Automation

[صفحه مستندات](README.md) · [English](../en/FLEET.md)

نسخه v0.6 یک لایه Fleet مبتنی بر Database روی معماری تک‌روتری v0.5 اضافه می‌کند.

## Device Registry

برای هر روتر Device ID، Display Name، Group، Location، Tag، وضعیت فعال بودن، Last Seen، Agent Version و Device Secret رمزنگاری‌شده نگه‌داری می‌شود.

Device Secret دائمی قبل از ذخیره در Registry با Instance Key تولیدشده توسط Setup رمزنگاری می‌شود.

## Device Picker

در Telegram، /start روتر فعال را نشان می‌دهد و از Fleet / Devices می‌توان Router دیگری را به‌عنوان Target فعال انتخاب کرد.

Mini App Dashboard نیز Device Selection و مدیریت Metadata دارد.

## Zero-Touch Provisioning

Owner و Admin می‌توانند از Telegram یا Dashboard یک Router جدید اضافه کنند.

سیستم به‌صورت خودکار Device ID، Device Secret تصادفی، Registry Entry رمزنگاری‌شده، Provisioning Token یک‌بارمصرف ۳۰ دقیقه‌ای و دستور نصب OpenWrt را می‌سازد.

Device Secret دائمی داخل دستور نصب قرار نمی‌گیرد.

## Group، Tag و Location

Routerها را می‌توان با Group، Tag و Location مرتب کرد؛ مثلاً Home، Office، Lab یا Customers. این اطلاعات فقط Metadata هستند و کانفیگ OpenWrt را تغییر نمی‌دهند.

## RBAC

| Role | سطح کلی دسترسی |
|---|---|
| Owner | دسترسی کامل |
| Admin | مدیریت عملیاتی گسترده در Device Scope؛ RBAC فقط Read-only |
| Operator | عملیات روزمره بدون قابلیت‌های حساس Admin |
| Viewer | دسترسی Read-only |

برای Operator و Viewer می‌توان Device Scope مشخص کرد.

Permissionهای حساس شامل Terminal، Backup Restore، Agent Update، Rollback، Alert و Scheduler هستند. تغییر Role و Scope فقط توسط Owner انجام می‌شود تا Admin محدود نتواند سطح دسترسی خودش را افزایش دهد.

## Audit Log

برای هر Command، User، Source، Device، Action، پارامترهای Redacted، Status، خلاصه Result و Timestamp ثبت می‌شود.

Password، Node Link، Subscription URL، Backup Payload و Terminal Payload در Audit نمایش داده نمی‌شوند.

## Config History و Rollback

برای Mutationهای UCI پشتیبانی‌شده، Agent قبل از تغییر فایل مربوط در /etc/config را Snapshot می‌گیرد.

Snapshot روی خود Router با یک Key تصادفی ۳۲‌بایتی مخصوص همان Router در مسیر `/etc/pw-agent/history.key` رمزنگاری می‌شود. Backend در حالت عادی این History Key را دریافت نمی‌کند و فقط Ciphertext را نگه می‌دارد. Diff فقط نام UCI Keyهای تغییرکرده را نشان می‌دهد و Valueها را نمایش نمی‌دهد.

Owner و Admin می‌توانند از Telegram یا Dashboard Rollback را Queue کنند.

## Monitoring پیشرفته

Heartbeat نسخه v0.6 شامل CPU، Memory، وضعیت WAN، WAN IP، RX/TX، وضعیت Passwall2، Uptime و Load است.

Dashboard برای ۲۴ ساعت، ۷ روز و ۳۰ روز Heartbeat Coverage را نمایش می‌دهد و Traffic Delta، تغییر WAN IP و Eventهای Failover را هم خلاصه می‌کند.

## Alert Rules

Metricهای فعلی شامل CPU، Memory، Load، WAN Down و Passwall2 Down هستند. هر Rule دارای Operator، Threshold، Duration، Cooldown و Enabled State است.

## Maintenance Mode

Maintenance Mode Alertهای عملیاتی را موقتاً Mute می‌کند و Scheduled Actionها را Pause می‌کند.

## Scheduled Actions

Scheduleهای One-time، Interval، Daily و Weekly پشتیبانی می‌شوند.

Scheduler از همان Cron یک‌دقیقه‌ای Monitoring استفاده می‌کند و فقط Actionهای Allowlist‌شده قابل Schedule هستند؛ از جمله Restart Passwall2، Update Subscription، Backup، Reboot، Agent Update و اجرای Failover.

Scheduled Commandها همچنان از RBAC، Queue Lease، Idempotency و Audit عبور می‌کنند.

## Mini App Dashboard

Dashboard شامل Fleet Selection، Device Metadata و Group، Monitoring، Router Operations، Command History، Audit و Rollback، Alert Rules، Maintenance، Scheduled Actions، RBAC و دسترسی به SSH Console است.

تمام درخواست‌های Dashboard با Telegram Mini App initData اعتبارسنجی می‌شوند.
