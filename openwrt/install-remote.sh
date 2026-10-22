#!/bin/sh
set -eu

REPO="${PW_GITHUB_REPO:-aliazizi0906/Passwall-Agent-Telegram-Bot}"
SERVER_BASE="${PW_SERVER_BASE:-}"
TOKEN="${PW_PROVISION_TOKEN:-}"
VERSION="${PW_AGENT_VERSION:-latest}"

[ -n "$SERVER_BASE" ] || { echo "ERROR: PW_SERVER_BASE is required." >&2; exit 1; }
[ -n "$TOKEN" ] || { echo "ERROR: PW_PROVISION_TOKEN is required." >&2; exit 1; }
echo "$VERSION" | grep -Eq '^(latest|v[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?)$' || {
    echo "ERROR: invalid PW_AGENT_VERSION." >&2
    exit 1
}
echo "$SERVER_BASE" | grep -Eq '^https://[^[:space:]"]+$' || {
    echo "ERROR: PW_SERVER_BASE must use HTTPS." >&2
    exit 1
}
echo "$TOKEN" | grep -Eq '^[A-Za-z0-9_-]{32,128}$' || {
    echo "ERROR: invalid provisioning token format." >&2
    exit 1
}

case "$(uname -m)" in
    armv7l|armv7*) ARCH=armv7 ;;
    aarch64|arm64) ARCH=arm64 ;;
    x86_64|amd64) ARCH=amd64 ;;
    i386|i486|i586|i686) ARCH=386 ;;
    mips) ARCH=mips ;;
    mipsel|mipsle) ARCH=mipsle ;;
    *) echo "ERROR: unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac

TMP="/tmp/pw-agent-install.$$"
mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT INT TERM

download() {
    url="$1"
    out="$2"
    if command -v curl >/dev/null 2>&1; then
        curl -fL --connect-timeout 15 --max-time 180 "$url" -o "$out"
    elif command -v wget >/dev/null 2>&1; then
        wget -O "$out" "$url"
    else
        echo "ERROR: curl or wget is required." >&2
        exit 1
    fi
}

post_json() {
    url="$1"
    data="$2"
    if command -v curl >/dev/null 2>&1; then
        curl -fsS --connect-timeout 15 --max-time 45 \
            -H 'Content-Type: application/json' \
            --data "$data" "$url"
    elif command -v wget >/dev/null 2>&1; then
        wget -qO- \
            --header='Content-Type: application/json' \
            --post-data="$data" "$url"
    else
        return 1
    fi
}

if [ "$VERSION" = "latest" ]; then
    BASE_URL="https://github.com/$REPO/releases/latest/download"
else
    BASE_URL="https://github.com/$REPO/releases/download/$VERSION"
fi
ASSET="pw-agent-openwrt-$ARCH.tar.gz"

echo "Detected architecture: $ARCH"
echo "Downloading PW Agent release: $VERSION"
download "$BASE_URL/$ASSET" "$TMP/bundle.tar.gz"
download "$BASE_URL/$ASSET.sha256" "$TMP/bundle.sha256"

command -v sha256sum >/dev/null 2>&1 || {
    echo "ERROR: sha256sum is required." >&2
    exit 1
}
EXPECTED="$(awk '{print $1}' "$TMP/bundle.sha256" | head -n1 | tr 'A-F' 'a-f')"
ACTUAL="$(sha256sum "$TMP/bundle.tar.gz" | awk '{print $1}' | tr 'A-F' 'a-f')"
[ -n "$EXPECTED" ] && [ "$EXPECTED" = "$ACTUAL" ] || {
    echo "ERROR: release SHA256 mismatch." >&2
    exit 1
}

tar -xzf "$TMP/bundle.tar.gz" -C "$TMP"
[ -f "$TMP/install.sh" ] || {
    echo "ERROR: release installer missing." >&2
    exit 1
}

echo "Requesting one-time device credentials..."
JSON_PAYLOAD='{"token":"'"$TOKEN"'"}'
RESP="$(post_json "$SERVER_BASE/api/provision.php" "$JSON_PAYLOAD")"
printf '%s' "$RESP" | grep -q '"ok":true' || {
    echo "ERROR: provisioning failed: $RESP" >&2
    exit 1
}

SERVER_URL="$(printf '%s' "$RESP" | sed -n 's/.*"server_url":"\([^"]*\)".*/\1/p')"
DEVICE_ID="$(printf '%s' "$RESP" | sed -n 's/.*"device_id":"\([^"]*\)".*/\1/p')"
DEVICE_SECRET="$(printf '%s' "$RESP" | sed -n 's/.*"device_secret":"\([^"]*\)".*/\1/p')"

[ -n "$SERVER_URL" ] && [ -n "$DEVICE_ID" ] && [ -n "$DEVICE_SECRET" ] || {
    echo "ERROR: invalid provisioning response." >&2
    exit 1
}

PW_SERVER_URL="$SERVER_URL" \
PW_DEVICE_ID="$DEVICE_ID" \
PW_DEVICE_SECRET="$DEVICE_SECRET" \
sh "$TMP/install.sh"

echo
echo "Installation completed. Open Telegram and send /start to your bot."
