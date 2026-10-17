# به‌روزرسانی و Release

[صفحه مستندات](README.md) · [English](../en/UPDATES.md)

## مدل Update برای کاربران

دو بخش باید به‌روز نگه داشته شوند:

1. فایل‌های Backend روی هاست
2. Agent/LuCI روی OpenWrt

## آپدیت Backend

Source را Pull کنید:

```bash
cd ~/Passwall-Agent-Telegram-Bot
git pull --ff-only
```

Backend را با حفظ Runtime Files Deploy کنید:

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

## Sync کردن Agent Release

برای یک معماری:

```bash
php bin/sync-release.php --arch=armv7
```

برای همه معماری‌ها:

```bash
php bin/sync-release.php --all
```

Backend Bundle و Signature متناظر را در `server/releases/` نگه می‌دارد.

## Self-update Agent

Agent می‌تواند از Backend وجود نسخه جدید را بررسی کند.

Backend Package مخصوص معماری را ارائه می‌دهد و Agent پیش از نصب SHA256 و Ed25519 Signature را Verify می‌کند.

Public Key رسمی داخل Agent Embed شده است.

## Release رسمی برای Maintainer

GitHub Actions برای معماری‌های زیر Build می‌سازد:

- armv7
- arm64
- amd64
- 386
- mips
- mipsle

برای هر معماری سه فایل منتشر می‌شود:

```text
pw-agent-openwrt-ARCH.tar.gz
pw-agent-openwrt-ARCH.tar.gz.sha256
pw-agent-openwrt-ARCH.tar.gz.sig
```

Maintainer فقط یک Repository Secret نیاز دارد:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

سپس:

```text
GitHub → Actions → Release → Run workflow
Branch: main
version: vX.Y.Z
```

Workflow تطبیق Version با `app_version()` و تطبیق Private Key با `UPDATE_PUBLIC_KEY.b64` را بررسی می‌کند، تمام معماری‌ها را Build و Sign می‌کند و GitHub Release را می‌سازد.

## Forkها

Forkهایی که Signed Release مستقل می‌خواهند باید Public Key رسمی را با Key خودشان جایگزین کنند و Private Key جدید را فقط در GitHub Actions Secret خود نگه دارند.
