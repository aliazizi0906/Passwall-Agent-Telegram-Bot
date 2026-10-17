# ماتریس قابلیت‌های v0.4.0 — آرشیو

[مستندات فارسی](../README.md) · [English](../../en/legacy/FEATURES_0.4.0.md)

این فایل فقط برای تاریخچه نسخه v0.4.0 نگه‌داری می‌شود. برای قابلیت‌های فعلی [قابلیت‌ها](../FEATURES.md) را ببینید.

## قابلیت‌های درخواست‌شده

1. Smart Failover مستقل برای هر Shunt و مسیر `Default` بر اساس سلامت HTTP Relay و بهترین Latency سالم.
2. فعال/غیرفعال‌سازی Failover برای هر Node از نوع `_shunt` از جمله صفحه Shunt Rules.
3. Failure Threshold، Cooldown، Preferred Node و Auto Return اختیاری.
4. Self-update احرازهویت‌شده Agent از Backend روی cPanel.
5. Diagnostics در LuCI → **Services → PW Agent** برای HMAC، Server، Key و Latency.
6. SSH Command Console در Telegram Mini App با Credential واقعی OpenWrt و Dropbear محلی.
7. مدیریت V2Ray/Passwall2 Subscription URL.
8. Multi-link Import، هر URL در یک خط.
9. اسکن Nodeهای دارای HTTP Relay ناموفق و پاک‌سازی امن.
10. Node Select & Delete.
11. Safe Delete All برای Normal Proxy Nodeها.

## بهبودهای عملیاتی

- صفحه HTTP Relay Ranking.
- Alert در Telegram هنگام Failover Switch، Recovery و نبود Candidate.
- حذف Special Nodeهای Passwall2 از Failover و Bulk Cleanup.
- Subscription Delete بدون حذف Nodeهایی که جای دیگری Reference شده‌اند.
- جلوگیری از Direct HTTP Access به Release Storage.
- محافظت از Terminal Queue Payload با AES-256-GCM.
- پاک‌شدن Terminal Command/Result از Queue پس از پایان.
- محدودیت Concurrency در Relay Scan برای کاهش بار روتر.
