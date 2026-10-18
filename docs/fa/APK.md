# نصب PW Agent با APK v3 روی OpenWrt 25.12.2

[مستندات](README.md) · [English](../en/APK.md) · [راهنمای OPKG نسخه‌های 24.10 و قدیمی‌تر](OPKG.md) · [Releaseهای GitHub](https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases)

**مخصوص OpenWrt 25.12.2 و نسخه‌های دارای APK v3.** در نسخه‌های جدید OpenWrt مدیریت بسته‌ها از طریق `apk` انجام می‌شود. دستورهای `opkg` و فایل‌های `.ipk` مخصوص OpenWrt 24.10 و قدیمی‌تر هستند و روی OpenWrt 25.12.2 قابل استفاده نیستند.

هر Release رسمی شامل شش Package امضاشده APK v3 و شش فایل Index امضاشده مخصوص معماری است. فایل Index با آدرس مستقیم `.adb` خوانده می‌شود و اسم Package در Index امضاشده با `--pkgname-spec` ثبت شده است؛ به این ترتیب GitHub Release به‌صورت مستقیم Feed را ارائه می‌کند و به سرور جداگانه نیاز نداریم. فایل‌ها توسط ابزار اصلی `apk` داخل SDK رسمی OpenWrt 25.12.2 ساخته و اعتبارسنجی می‌شوند.

## ۱. تشخیص معماری مناسب

روی روتر اجرا کن:

```sh
uname -m
apk --version
cat /etc/apk/arch
```

| معماری CPU | Alias سازگار در APK | نام فایل Index امضاشده |
|---|---|---|
| `armv7l` / `armv7` | `pw_agent_armv7` | `pw-agent-packages-armv7.adb` |
| `aarch64` / `arm64` | `pw_agent_arm64` | `pw-agent-packages-arm64.adb` |
| `x86_64` / `amd64` | `pw_agent_amd64` | `pw-agent-packages-amd64.adb` |
| `i386` / `i686` | `pw_agent_386` | `pw-agent-packages-386.adb` |
| `mips` (Big-endian) | `pw_agent_mips` | `pw-agent-packages-mips.adb` |
| `mipsel` (Little-endian) | `pw_agent_mipsle` | `pw-agent-packages-mipsle.adb` |

این Aliasها اختصاصی هستند؛ چون نام Architecture اصلی OpenWrt به Target/Subtarget روتر وابسته است. قبل از نصب روی روتر عملیاتی، از سازگاری Binary همان Release با CPU روتر خودت مطمئن شو.

**فقط Alias مناسب روتر** را به آخر فایل `/etc/apk/arch` اضافه کن. مثلاً روی ARM64:

```sh
grep -qxF 'pw_agent_arm64' /etc/apk/arch || printf '\npw_agent_arm64\n' >> /etc/apk/arch
```

**هیچ خط قبلی، به‌خصوص خط اول فایل را حذف یا جایگزین نکن.** در APK خط اول معماری اصلی دستگاه است و خط‌های بعدی معماری‌های سازگار اضافی هستند. Alias معماری‌های دیگر را روی روتر وارد نکن.

## ۲. اعتماد به کلید امضای APK

Feed APK v3 با کلید ECDSA P-256 امضا می‌شود. این کلید به‌شکل ثابت و با HKDF و جداسازی دامنه از Secret امضای Ed25519 فعلی پروژه مشتق می‌شود. کلید عمومی جدید در Release با نام `pw-agent-apkv3-p256.pem` قرار می‌گیرد و امضای آن با **همان کلید Ed25519 قدیمی** در فایل `pw-agent-apkv3-p256.pem.sig` منتشر می‌شود. فایل ریشه اعتماد قبلی پروژه `UPDATE_PUBLIC_KEY.b64` است.

برای اطمینان از اصالت کلید جدید، دستورهای زیر را روی کامپیوتر لینوکسی قابل‌اعتماد اجرا کن؛ برای نمونه نسخه `v0.7.0`:

```sh
BASE='https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0'
curl -fL "$BASE/pw-agent-apkv3-p256.pem" -o pw-agent-apkv3-p256.pem
curl -fL "$BASE/pw-agent-apkv3-p256.pem.sig" -o pw-agent-apkv3-p256.pem.sig

# UPDATE_PUBLIC_KEY.b64 باید همان کلید معتبر قبلی پروژه باشد.
{ printf '302a300506032b6570032100' | xxd -r -p; base64 -d < UPDATE_PUBLIC_KEY.b64; } > update-public.der
openssl pkey -pubin -inform DER -in update-public.der -out update-public.pem
openssl pkeyutl -verify -rawin -pubin -inkey update-public.pem \
  -in pw-agent-apkv3-p256.pem -sigfile pw-agent-apkv3-p256.pem.sig
```

باید پیام معتبر بودن امضا نمایش داده شود. سپس PEM تأییدشده را به مسیر `/etc/apk/keys/pw-agent-apkv3-p256.pem` روتر منتقل کن. مثلاً:

```sh
scp pw-agent-apkv3-p256.pem root@ROUTER-LAN-IP:/etc/apk/keys/pw-agent-apkv3-p256.pem
```

می‌توان فایل PEM را از طریق HTTPS هم روی روتر دانلود کرد، اما باید مطمئن شوی دقیقاً با کلید تأییدشده مطابقت دارد. برای دور زدن خطاها گزینه `--allow-untrusted` را به نصب اضافه نکن و بررسی امضا را غیرفعال نکن.

## ۳. افزودن Repository با لینک مستقیم Index

نام Index را از جدول بالا انتخاب کن. برای مثال، روی ARM64 و Release `v0.7.0`:

```sh
mkdir -p /etc/apk/repositories.d
cat > /etc/apk/repositories.d/pw-agent.list <<'EOF'
ndx https://github.com/aliazizi0906/Passwall-Agent-Telegram-Bot/releases/download/v0.7.0/pw-agent-packages-arm64.adb
EOF

apk update
apk search pw-agent
apk add pw-agent
```

Repository روی همان نسخه مشخص ثابت می‌ماند. APK از فایل Index امضاشده نام Package را استخراج می‌کند؛ مثلاً `pw-agent-0.7.0-r0-pw_agent_arm64.apk` و همان فایل را از GitHub Release دریافت می‌کند. فایل `.adb` و هر فایل `.apk` با کلید P-256 امضا می‌شوند. اگر بررسی امضا خطا داد، کلید، نسخه و Alias معماری را بررسی کن.

## ۴. تنظیم و اجرای Agent

ابتدا Passwall2 باید نصب شده باشد. APK فایل اجرایی Agent، اسکریپت procd و نمونه تنظیمات را نصب می‌کند. فایل اصلی `/etc/pw-agent/config.json` **توسط Package مدیریت نمی‌شود** و هنگام ارتقا تغییر نمی‌کند. در نصب اول فایل نمونه ساخته می‌شود و تا وقتی عبارت `CHANGE_ME` در آن وجود دارد، سرویس اجرا نمی‌شود.

```sh
vi /etc/pw-agent/config.json
chmod 600 /etc/pw-agent/config.json
/etc/init.d/pw-agent enable
/etc/init.d/pw-agent restart
/usr/bin/pw-agent --version
/usr/bin/pw-agent diagnose-json
logread -e pw-agent
```

مقادیر واقعی `server_url`، Device ID و Device Secret را از Backend خودت وارد کن. برای به‌روزرسانی، URL مخزن را به Index نسخه جدید تغییر بده و `apk update && apk upgrade pw-agent` را اجرا کن.

## فرایند انتشار برای Maintainer

Workflow مستقل `.github/workflows/apk-feed.yml` شش Bundle رسمی دارای امضای Ed25519 را بررسی می‌کند، SDK رسمی OpenWrt 25.12.2 را با SHA256 از قبل مشخص‌شده دریافت می‌کند، کلید P-256 جدید را فقط در فضای موقت Runner تولید می‌کند و با ابزار SDK یعنی `apk mkpkg` و `apk mkndx`، Packageها و Indexهای امضاشده می‌سازد. سپس `apk verify` را روی هر دو نوع فایل و `apk adbdump` را روی Index اجرا می‌کند. Private Key منتشر، لاگ یا داخل Git Commit نمی‌شود؛ فقط Public Key دارای امضای اصالت منتشر می‌شود.

Aliasهای معماری فقط برای این Binaryهای مستقل Go در نظر گرفته شده‌اند و به معنای پشتیبانی همه Target/Subtargetهای OpenWrt نیستند. اسکریپت پیش از نصب، معماری CPU را دوباره بررسی می‌کند.
