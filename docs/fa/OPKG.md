# نصب PW Agent با opkg

[صفحه مستندات](README.md) · [English](../en/OPKG.md) · [راهنمای APK v3 برای OpenWrt 25.12+](APK.md) · [Releaseهای GitHub](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases)

**این راهنما فقط برای OpenWrt 24.10 و نسخه‌های قدیمی‌تر دارای opkg است.** در OpenWrt **25.12.2 مدیریت پکیج با APK v3** انجام می‌شود و معمولاً `opkg` وجود ندارد. برای نسخه 25.12.2 از [راهنمای نصب APK v3](APK.md) استفاده کن. فایل‌های `.ipk` با APK قابل جایگزینی نیستند.

در کنار هر Release رسمی، بسته‌های `.ipk` مخصوص معماری و فایل‌های `Packages` و `Packages.gz` منتشر می‌شوند. امضای Feed از همان کلید Ed25519 استفاده می‌کند که Bundleهای رسمی Agent را امضا می‌کند. Workflow پیش از ساخت Package، صحت SHA256 و امضای Bundleهای منبع را بررسی می‌کند.

## سازگاری و نام معماری‌ها

نام معماری Package در OpenWrt به Target و Subtarget وابسته است. به همین دلیل Feed از Aliasهای اختصاصی استفاده می‌کند و `arm64` یا `mipsle` را به‌اشتباه نام رسمی معماری OpenWrt معرفی نمی‌کند:

| هدف Release | Alias در opkg | مقدار معمول `uname -m` |
|---|---|---|
| `armv7` | `pw_agent_armv7` | `armv7l` |
| `arm64` | `pw_agent_arm64` | `aarch64` |
| `amd64` | `pw_agent_amd64` | `x86_64` |
| `386` | `pw_agent_386` | `i386` / `i686` |
| `mips` | `pw_agent_mips` | `mips`، Big-endian |
| `mipsle` | `pw_agent_mipsle` | `mipsel`، Little-endian |

اسکریپت پیش از نصب، معماری ELF فایل و معماری گزارش‌شده روتر را بررسی می‌کند. برای MIPS، خروجی `opkg print-architecture` هم بررسی می‌شود تا بسته با Endianness اشتباه نصب نشود. با این حال، معماری Go از Target/Subtargetهای OpenWrt کلی‌تر است؛ پیش از استفاده روی روتر عملیاتی مطمئن شو که دستگاه واقعاً قادر به اجرای Binary همان Release است.

## ۱. افزودن کلید عمومی opkg

فایل `Packages.sig` با قالب بومی usign در OpenWrt ساخته می‌شود و از `UPDATE_PUBLIC_KEY.b64` فعلی مشتق شده است. شناسه کلید فعلی `1e2f57b76d1074e1` است. قبل از اجرای `opkg update` کلید را نصب کن:

```sh
mkdir -p /etc/opkg/keys
wget -O /etc/opkg/keys/1e2f57b76d1074e1 \
  https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0/pw-agent-opkg-key-1e2f57b76d1074e1.pub
chmod 0644 /etc/opkg/keys/1e2f57b76d1074e1
```

کلید عمومی محرمانه نیست. اگر کلید امضای پروژه عوض شود، شناسه و فایل کلید هم تغییر می‌کنند؛ کلید Release موردنظر را از منبع مورداعتماد دریافت کن و تغییر کلید را از مسیر معتبر پروژه بررسی کن.

## ۲. ثبت Alias معماری درست

ابتدا معماری روتر را بررسی کن:

```sh
uname -m
opkg print-architecture
```

یک خط متناسب با روتر به `/etc/opkg.conf` اضافه کن. برای نمونه، روی روتر ARM64:

```text
arch pw_agent_arm64 100
```

برای اهداف دیگر از `pw_agent_armv7`، `pw_agent_amd64`، `pw_agent_386`، `pw_agent_mips` یا `pw_agent_mipsle` استفاده کن. همه Aliasها را روی یک روتر ثبت نکن.

## ۳. افزودن Feed نسخه مشخص

`v0.7.0` را با نسخه موردنظر جایگزین کن. URL مستقیماً به Assetهای همان Release اشاره می‌کند؛ GitHub فایل‌های `Packages.gz`، `Packages.sig` و IPKها را با نام فایل در اختیار opkg می‌گذارد.

```sh
cat >> /etc/opkg/customfeeds.conf <<'EOF'
src/gz pw-agent https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0
EOF

opkg update
opkg info pw-agent
opkg install pw-agent
```

بررسی امضای Packageها در OpenWrt را روشن نگه دار. Workflow فایل `Packages.sig` را با قالب بومی usign می‌سازد و پیش از انتشار آن را با ابزار اصلی `usign` اعتبارسنجی می‌کند. اگر `opkg update` خطای امضا یا معماری داد، تنظیم کلید و Alias را اصلاح کن؛ برای عبور از خطا، بررسی امضا را غیرفعال نکن.

## ۴. تنظیم و اجرای Agent

اگر `/etc/pw-agent/config.json` از قبل وجود نداشته باشد، نصب Package آن را از روی فایل نمونه می‌سازد و سرویس را متوقف نگه می‌دارد. مقادیر واقعی Backend را در فایل وارد کن:

```sh
vi /etc/pw-agent/config.json
chmod 0600 /etc/pw-agent/config.json
```

مطمئن شو Passwall2 نصب است و `/etc/config/passwall2` وجود دارد. سپس سرویس را فعال و اجرا کن:

```sh
/etc/init.d/pw-agent enable
/etc/init.d/pw-agent restart
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

هنگام Upgrade، فایل فعلی `/etc/pw-agent/config.json` بازنویسی نمی‌شود. هنگام حذف Package، سرویس متوقف و غیرفعال می‌شود اما فایل تنظیمات و Credentialها باقی می‌مانند.

این IPK سرویس اصلی، Init Script و فایل نمونه تنظیمات را نصب می‌کند و صفحه LuCI اضافه نمی‌کند؛ Menu فعلی در Bundle به Viewی اشاره می‌کند که در Repository وجود ندارد.

## نصب آفلاین

هر Release شامل `pw-agent-opkg-feed-vX.Y.Z.tar.gz` به‌همراه فایل SHA256 و امضای Ed25519 آن با کلید رسمی نیز هست. این آرشیو IPKها، فایل‌های `Packages` و `Packages.gz`، امضای usign به نام `Packages.sig` و کلید عمومی را دارد. پیش از Extract، SHA256 و امضای آرشیو را با همان روش اعتبارسنجی Bundle رسمی Agent بررسی کن؛ سپس فایل IPK مناسب را با `opkg install /path/to/package.ipk` نصب کن.

روی یک سیستم Linux مورداعتماد، آرشیو و فایل‌های `.sha256` و `.sig` آن را به‌همراه `UPDATE_PUBLIC_KEY.b64` متناظر با Source Tag دانلود کن. اسکریپت Verify را از یک Checkout مورداعتماد که `packaging/verify-feed-archive.sh` را دارد اجرا کن؛ بعد از واردشدن تغییرات بسته‌بندی، این اسکریپت داخل Tag هم موجود خواهد بود:

```sh
sh packaging/verify-feed-archive.sh \
  pw-agent-opkg-feed-v0.7.0.tar.gz \
  pw-agent-opkg-feed-v0.7.0.tar.gz.sha256 \
  pw-agent-opkg-feed-v0.7.0.tar.gz.sig \
  UPDATE_PUBLIC_KEY.b64
```

فقط پس از موفقیت Verify، آرشیو را Extract کن. اگر روتر به Feed داخل GitHub Release دسترسی ندارد، روش آفلاین امضاشده گزینه پیشنهادی است.

## Workflow انتشار برای Maintainer

بعد از موفقیت Workflow رسمی `Release` برای Tag نسخه‌ای که Push شده، GitHub فایل `.github/workflows/package-feed.yml` را اجرا می‌کند. Workflow هر شش Bundle رسمی را دریافت می‌کند، SHA256 و امضاهای Ed25519 آن‌ها را بررسی می‌کند، با Timestamp کامیت Tag بسته‌های قابل‌بازتولید می‌سازد، `Packages` و `Packages.gz` را تولید می‌کند، `Packages` را با کلید موجود و قالب native usign امضا می‌کند، امضا را با `usign` اصلی بررسی می‌کند و فایل‌ها را به همان Release اضافه می‌کند. برای Release قدیمی یا Release ساخته‌شده از اجرای دستی Workflow، از **Actions → OpenWrt OPKG Feed → Run workflow** و Tag همان Release استفاده کن. اگر Secret امضا وجود نداشته باشد یا با `UPDATE_PUBLIC_KEY.b64` تطابق نداشته باشد، انتشار متوقف می‌شود و Feed بدون امضا ساخته نمی‌شود.

تست‌های بسته‌بندی با کتابخانه استاندارد Python اجرا می‌شوند و به روتر OpenWrt، OpenWrt SDK یا اینترنت نیاز ندارند.
