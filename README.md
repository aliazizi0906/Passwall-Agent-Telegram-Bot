# PW Agent

**OpenWrt + Passwall2 Remote Management via Telegram**

[English Documentation](docs/en/README.md) · [مستندات فارسی](docs/fa/README.md) · [Releases](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases) · [Issues](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/issues)

---

## English

PW Agent is a self-hosted remote-management stack for OpenWrt routers running Passwall2. The router communicates with a PHP/MySQL backend over outbound HTTPS, so it does not require a public IP or an exposed management port. On cPanel, the backend can be installed through a browser with no SSH/Terminal: upload the contents of `server/` to any web-accessible directory and open `setup.php`.

### Documentation

| Section | Description |
|---|---|
| [Install](docs/en/INSTALL.md) | Terminal-free cPanel Web Setup + OpenWrt installation |
| [Features](docs/en/FEATURES.md) | Router, Passwall2, Failover, Monitoring and Terminal capabilities |
| [Fleet & Automation](docs/en/FLEET.md) | Multi-device, RBAC, audit, Dashboard, alerts and schedules |
| [Architecture](docs/en/ARCHITECTURE.md) | Agent, backend, queue and request flow |
| [Security](docs/en/SECURITY.md) | HMAC, AES-GCM, Ed25519, allowlists and provisioning |
| [Updates](docs/en/UPDATES.md) | Agent update and GitHub Release workflow |
| [Migration](docs/en/MIGRATION.md) | Upgrade from earlier versions |
| [Development](docs/en/DEVELOPMENT.md) | Build, test and CI information |
| [Troubleshooting](docs/en/TROUBLESHOOTING.md) | Diagnostics and common problems |

### Quick cPanel install

```text
1. Create MySQL database/user in cPanel
2. Upload server/ contents anywhere under the domain document root
3. Open https://YOUR-DOMAIN/YOUR-PATH/setup.php
4. Complete the Web Setup form
5. Copy the generated Cron command into cPanel → Cron Jobs
6. Run the generated provisioning command on OpenWrt
```

No fixed `/home/USER/public_html/...` path is required.

### Quick architecture

```text
Telegram
   |
   v
PHP Backend + MySQL
   ^
   | HTTPS + HMAC
   |
OpenWrt PW Agent
   |
   +-- UCI / ubus / procd
   +-- Passwall2
   +-- Dropbear on localhost
```

Official builds are available for `armv7`, `arm64`, `amd64`, `386`, `mips` and `mipsle`.

Start here: **[Installation Guide](docs/en/INSTALL.md)**

---

## فارسی

PW Agent یک سامانه‌ی Self-hosted برای مدیریت از راه دور **OpenWrt + Passwall2 از طریق Telegram** است. روتر فقط با اتصال خروجی HTTPS به Backend متصل می‌شود؛ بنابراین نیازی به IP Public یا باز کردن پورت مدیریتی روی WAN نیست. روی cPanel هم بدون SSH/Terminal قابل نصب است: محتویات `server/` را در هر مسیر Web-accessible آپلود کن و `setup.php` را باز کن.

### مستندات

| بخش | توضیح |
|---|---|
| [نصب](docs/fa/INSTALL.md) | Web Setup روی cPanel بدون Terminal + نصب OpenWrt |
| [قابلیت‌ها](docs/fa/FEATURES.md) | امکانات روتر، Passwall2، Failover، Monitoring و Terminal |
| [Fleet و Automation](docs/fa/FLEET.md) | Multi-Device، RBAC، Audit، Dashboard، Alert و Scheduler |
| [معماری](docs/fa/ARCHITECTURE.md) | ساختار Agent، Backend، Queue و جریان درخواست |
| [امنیت](docs/fa/SECURITY.md) | HMAC، AES-GCM، Ed25519، Allowlist و Provisioning |
| [به‌روزرسانی](docs/fa/UPDATES.md) | آپدیت Agent و GitHub Release |
| [مهاجرت](docs/fa/MIGRATION.md) | ارتقا از نسخه‌های قبلی |
| [توسعه](docs/fa/DEVELOPMENT.md) | Build، Test و CI |
| [رفع اشکال](docs/fa/TROUBLESHOOTING.md) | Diagnostics و خطاهای متداول |

### نصب سریع cPanel

```text
1. Database و User را در cPanel بساز
2. محتویات server/ را در هر مسیر دلخواه زیر Document Root آپلود کن
3. https://YOUR-DOMAIN/YOUR-PATH/setup.php را باز کن
4. فرم Web Setup را کامل کن
5. Cron تولیدشده را در cPanel → Cron Jobs قرار بده
6. Provisioning Command تولیدشده را روی OpenWrt اجرا کن
```

هیچ مسیر ثابت `/home/USER/public_html/...` لازم نیست.

### معماری خلاصه

```text
Telegram
   |
   v
PHP Backend + MySQL
   ^
   | HTTPS + HMAC
   |
OpenWrt PW Agent
   |
   +-- UCI / ubus / procd
   +-- Passwall2
   +-- Dropbear روی localhost
```

Build رسمی برای معماری‌های `armv7`، `arm64`، `amd64`، `386`، `mips` و `mipsle` منتشر می‌شود.

از اینجا شروع کنید: **[راهنمای نصب](docs/fa/INSTALL.md)**

---

## Project status / وضعیت پروژه

Current public release / نسخه عمومی فعلی: **v0.5.0**

Development version / نسخه در حال توسعه: **v0.6.0**

Repository / مخزن: https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot
