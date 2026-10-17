# ارتقا به v0.4.0 — آرشیو

[مستندات فارسی](../README.md) · [English](../../en/legacy/UPGRADE_0.4.0.md)

> این فایل فقط تاریخی است. نصب جدید باید از [راهنمای نصب فعلی](../INSTALL.md) و ارتقا از [مهاجرت](../MIGRATION.md) استفاده کند.

روند قدیمی v0.4:

1. فایل `server/config.php` موجود روی cPanel حفظ می‌شد.
2. سایر فایل‌های `server/` نسخه v0.4 جایگزین می‌شدند.
3. `health.php` باید نسخه `0.4.0` را نشان می‌داد.
4. Repository روی OpenWrt Copy/Clone می‌شد.
5. `openwrt/install.sh` اجرا می‌شد.
6. API URL، Device ID و Device Secret در `/etc/pw-agent/config.json` بررسی می‌شد.
7. نسخه Agent بررسی می‌شد.
8. `/start` در Telegram تست می‌شد.
9. Agent Connection Diagnostics تست می‌شد.
10. صفحه LuCI مربوط به PW Agent تست می‌شد.
11. Smart Failover روی Shunt تست می‌شد.
12. Subscription، Link Import و Relay Ranking تست می‌شد.
13. SSH Console تست می‌شد.

از v0.4 به بعد، Update بخش Agent/init/LuCI به‌صورت Remote طراحی شده بود و تغییرات PHP همچنان نیازمند Deploy سمت Server بودند.
