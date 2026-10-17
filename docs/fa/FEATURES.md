# قابلیت‌ها

[صفحه مستندات](README.md) · [English](../en/FEATURES.md)

## مدیریت OpenWrt

PW Agent عملیات پشتیبانی‌شده‌ی OpenWrt را از طریق Telegram ارائه می‌کند، بدون اینکه پورت مدیریتی روی WAN باز شود.

بخش‌های اصلی:

- وضعیت سیستم، Uptime، CPU/Load، RAM و Storage
- Interfaceها، WAN، DNS، Route و DHCP
- مدیریت SSID، Password، Radio، Channel و Stationهای Wi-Fi
- Firewall Zone و Redirect/Port Forward
- System Log و Kernel Log
- Process و Service
- Ping و Traceroute
- Backup و Restore

## Passwall2

امکانات Passwall2 شامل:

- انتخاب Main Node
- مشاهده و تغییر Shunt Ruleها
- تغییر Default Route در Shunt
- لیست و حذف امن Nodeها
- Import لینک‌های VLESS / VMess / Trojan / Shadowsocks در محدوده Parser
- Multi-Link Import
- مدیریت Subscription URL
- Update Subscription
- Relay Test و Ranking
- اسکن و حذف Nodeهای دارای Relay ناموفق
- Bulk Delete همراه Reference Protection

Nodeهای Special/Virtual در عملیات خطرناک Bulk و Candidateهای Smart Failover وارد نمی‌شوند.

## Smart Failover

Failover برای هر Shunt به‌صورت مستقل تنظیم می‌شود.

قابلیت‌ها:

- فعال/غیرفعال‌سازی برای هر Shunt
- Preferred Node
- Auto Return اختیاری
- چند Relay Probe
- بررسی TCP Reachability
- امتیازدهی Latency
- جریمه Jitter
- جریمه Loss
- Failure Threshold
- Cooldown
- سوییچ خودکار به بهترین Node سالم
- Event در Telegram هنگام Switch، Recovery یا نبود Candidate

## SSH Console در Telegram

Telegram Mini App امکان اجرای Command با Credential واقعی OpenWrt را فراهم می‌کند.

ویژگی‌ها:

- استفاده از Dropbear/dbclient محلی روی روتر
- عدم نیاز به SSH روی WAN
- اعتبارسنجی Telegram Mini App `initData`
- محافظت از Credential، Command و cwd
- Timeout و محدودیت خروجی
- اجرای Command به‌صورت Non-interactive و بدون PTY دائمی

## Monitoring

Backend Metrics و Eventها را ذخیره می‌کند و موارد زیر را ارائه می‌دهد:

- Last Seen
- وضعیت Online/Offline
- Heartbeat Coverage
- خلاصه Load
- خلاصه Uptime
- تعداد Eventهای Failover
- آخرین Event
- هشدار Offline و Recovery با Cron

## Diagnostics در LuCI

در **Services → PW Agent** می‌توان موارد زیر را تست کرد:

- نسخه Agent
- Device ID
- Backend URL
- HMAC Authentication
- Signed Server Response
- Round-trip Latency

## Self-update

Agent برای Update از Bundle مخصوص معماری استفاده می‌کند و دو بررسی انجام می‌دهد:

1. SHA256 برای Integrity
2. Ed25519 برای Authenticity

Public Key رسمی داخل Agent Embed شده و در `UPDATE_PUBLIC_KEY.b64` نیز منتشر شده است.

## Reliability

نسخه v0.5 موارد زیر را اضافه می‌کند:

- Expiring Command Lease
- Retry Budget
- Idempotency Key
- Agent-side Idempotency Journal
- Result Replay در صورت قطع ارتباط
- رمزنگاری پارامترهای حساس Queue در Database

برای جزئیات [معماری](ARCHITECTURE.md) و [امنیت](SECURITY.md) را ببینید.
