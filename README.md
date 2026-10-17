# PW Agent

**OpenWrt + Passwall2 Remote Management via Telegram**

[English Documentation](docs/en/README.md) · [مستندات فارسی](docs/fa/README.md) · [Releases](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases) · [Issues](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/issues)

---

## English

PW Agent is a self-hosted remote-management stack for OpenWrt routers running Passwall2. The router communicates with a PHP/MySQL backend over outbound HTTPS, so it does not require a public IP or an exposed management port.

### Documentation

| Section | Description |
|---|---|
| [Install](docs/en/INSTALL.md) | Complete cPanel + OpenWrt installation |
| [Features](docs/en/FEATURES.md) | Router, Passwall2, Failover, Monitoring and Terminal capabilities |
| [Architecture](docs/en/ARCHITECTURE.md) | Agent, backend, queue and request flow |
| [Security](docs/en/SECURITY.md) | HMAC, AES-GCM, Ed25519, allowlists and provisioning |
| [Updates](docs/en/UPDATES.md) | Agent update and GitHub Release workflow |
| [Migration](docs/en/MIGRATION.md) | Upgrade from earlier versions |
| [Development](docs/en/DEVELOPMENT.md) | Build, test and CI information |
| [Troubleshooting](docs/en/TROUBLESHOOTING.md) | Diagnostics and common problems |

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

PW Agent یک سامانه‌ی Self-hosted برای مدیریت از راه دور **OpenWrt + Passwall2 از طریق Telegram** است. روتر فقط با اتصال خروجی HTTPS به Backend متصل می‌شود؛ بنابراین برای استفاده از پروژه نیازی به IP Public یا باز کردن پورت مدیریتی روی WAN نیست.

### مستندات

| بخش | توضیح |
|---|---|
| [نصب](docs/fa/INSTALL.md) | آموزش کامل نصب روی cPanel و OpenWrt |
| [قابلیت‌ها](docs/fa/FEATURES.md) | امکانات روتر، Passwall2، Failover، Monitoring و Terminal |
| [معماری](docs/fa/ARCHITECTURE.md) | ساختار Agent، Backend، Queue و جریان درخواست |
| [امنیت](docs/fa/SECURITY.md) | HMAC، AES-GCM، Ed25519، Allowlist و Provisioning |
| [به‌روزرسانی](docs/fa/UPDATES.md) | آپدیت Agent و GitHub Release |
| [مهاجرت](docs/fa/MIGRATION.md) | ارتقا از نسخه‌های قبلی |
| [توسعه](docs/fa/DEVELOPMENT.md) | Build، Test و CI |
| [رفع اشکال](docs/fa/TROUBLESHOOTING.md) | Diagnostics و خطاهای متداول |

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

Repository / مخزن: https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot
