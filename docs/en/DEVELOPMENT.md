# Development

[Documentation Home](README.md) · [فارسی](../fa/DEVELOPMENT.md)

## Source layout

Main areas:

```text
agent_main.go
features_v040.go
features_v050.go
openwrt/
server/
.github/workflows/
docs/
```

- Go files implement the OpenWrt Agent.
- `openwrt/` contains init/install/LuCI integration.
- `server/` contains the PHP/MySQL backend, Telegram bot and Mini App.
- `.github/workflows/` contains CI and release automation.

## Go requirements

The project uses Go modules.

Run:

```bash
gofmt -w *.go
go test ./...
go vet ./...
```

## Cross-compilation

Examples:

```bash
GOOS=linux GOARCH=arm GOARM=7 CGO_ENABLED=0 go build -trimpath -o pw-agent-armv7 .
GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -trimpath -o pw-agent-arm64 .
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -o pw-agent-amd64 .
```

CI also validates `386`, `mips` and `mipsle`.

## PHP checks

```bash
find server -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Shell checks

```bash
sh -n openwrt/install.sh
sh -n openwrt/install-update.sh
sh -n openwrt/install-remote.sh
```

## JSON checks

```bash
find openwrt -name '*.json' -print0 | xargs -0 -n1 jq -e . >/dev/null
```

## CI

The CI workflow validates:

- gofmt
- Go tests
- go vet
- static builds for all supported architectures
- PHP syntax
- shell syntax
- JSON files

## Release workflow

The Release workflow is intended to run from `main`.

Maintainers configure:

```text
UPDATE_SIGNING_PRIVATE_KEY_PEM_B64
```

and publish a semantic version such as:

```text
v0.5.0
```

The workflow validates the version and signing key before publishing signed assets.

## Documentation

Documentation is bilingual:

```text
docs/en/
docs/fa/
```

When changing behavior, update both languages in the same pull request.

## Contribution notes

Before submitting changes:

1. keep device secrets and Bot tokens out of commits;
2. keep user-facing code/UI strings in English unless the project explicitly adds localization;
3. add tests for reliability/security-sensitive logic where practical;
4. make sure CI passes;
5. update both documentation languages when behavior changes.
