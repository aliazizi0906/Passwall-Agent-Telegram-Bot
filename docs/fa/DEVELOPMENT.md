# توسعه پروژه

[صفحه مستندات](README.md) · [English](../en/DEVELOPMENT.md)

## ساختار Source

بخش‌های اصلی:

```text
agent_main.go
features_v040.go
features_v050.go
openwrt/
server/
.github/workflows/
docs/
```

- فایل‌های Go مربوط به Agent روی OpenWrt هستند.
- `openwrt/` شامل Install، Init و LuCI Integration است.
- `server/` شامل Backend PHP/MySQL، Telegram Bot و Mini App است.
- `.github/workflows/` مربوط به CI و Release Automation است.

## نیازمندی Go

پروژه از Go Modules استفاده می‌کند.

```bash
gofmt -w *.go
go test ./...
go vet ./...
```

## Cross Compile

نمونه:

```bash
GOOS=linux GOARCH=arm GOARM=7 CGO_ENABLED=0 go build -trimpath -o pw-agent-armv7 .
GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -trimpath -o pw-agent-arm64 .
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -o pw-agent-amd64 .
```

CI معماری‌های `386`، `mips` و `mipsle` را هم Build می‌کند.

## بررسی PHP

```bash
find server -name '*.php' -print0 | xargs -0 -n1 php -l
```

## بررسی Shell

```bash
sh -n openwrt/install.sh
sh -n openwrt/install-update.sh
sh -n openwrt/install-remote.sh
```

## بررسی JSON

```bash
find openwrt -name '*.json' -print0 | xargs -0 -n1 jq -e . >/dev/null
```

## CI

CI موارد زیر را بررسی می‌کند:

- gofmt
- Go Test
- go vet
- Build استاتیک همه معماری‌های پشتیبانی‌شده
- PHP Syntax
- Shell Syntax
- JSON

## Release Workflow

Release رسمی باید از `main` اجرا شود.

Maintainer Secret زیر را تنظیم می‌کند:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

و Version مانند:

```text
v0.5.0
```

را منتشر می‌کند.

Workflow قبل از انتشار Version و Signing Key را Validate می‌کند.

## مستندات

مستندات دو‌زبانه هستند:

```text
docs/en/
docs/fa/
```

هر تغییر رفتاری باید در همان Pull Request در هر دو زبان مستند شود.

## نکات Contribution

قبل از ارسال تغییر:

1. Device Secret و Bot Token را Commit نکنید.
2. متن‌های UI/Source انگلیسی بمانند مگر پروژه Localization رسمی اضافه کند.
3. برای منطق حساس Reliability/Security تا حد امکان Test اضافه کنید.
4. CI باید Pass شود.
5. هر دو زبان مستندات را به‌روز کنید.
