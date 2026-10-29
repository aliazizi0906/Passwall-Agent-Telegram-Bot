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
    armv5*|armv5tel) ARCH=armv5 ;;
    armv6*|armv6l) ARCH=armv6 ;;
    armv8l|armv7l|armv7*) ARCH=armv7 ;;
    aarch64|arm64) ARCH=arm64 ;;
    x86_64|amd64) ARCH=amd64 ;;
    i386|i486|i586|i686) ARCH=386 ;;
    mips) ARCH=mips ;;
    mipsel|mipsle) ARCH=mipsle ;;
    mips64el|mips64le) ARCH=mips64le ;;
    mips64) ARCH=mips64 ;;
    riscv64) ARCH=riscv64 ;;
    loongarch64|loong64) ARCH=loong64 ;;
    ppc64le) ARCH=ppc64le ;;
    *) echo "ERROR: unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac

TMP="/tmp/pw-agent-install.$$"
mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT INT TERM

download() {
    url="$1"
    out="$2"
    rm -f "$out"
    if command -v curl >/dev/null 2>&1; then
        if curl -4 -fLsS --connect-timeout 15 --max-time 180 "$url" -o "$out"; then
            [ -s "$out" ] && return 0
        fi
        rm -f "$out"
        if curl -fLsS --connect-timeout 15 --max-time 180 "$url" -o "$out"; then
            [ -s "$out" ] && return 0
        fi
    fi
    rm -f "$out"
    if command -v wget >/dev/null 2>&1; then
        if wget -O "$out" "$url"; then
            [ -s "$out" ] && return 0
        fi
    fi
    echo "ERROR: release download failed or was empty; verify DNS, WAN and router TLS." >&2
    return 1
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
# The cPanel server may cache signed bundles; this allows OpenWrt routers
# without access to GitHub to install directly through their backend domain.
# No provisioning token is consumed until local SHA256+Ed25519 verification.
backend_asset() {
    part="$1"
    out="$2"
    endpoint="$SERVER_BASE/install/bundle.php"
    form="token=$TOKEN&arch=$ARCH&version=$VERSION&part=$part"
    rm -f "$out"
    if command -v curl >/dev/null 2>&1; then
        if curl -4 -fsS --connect-timeout 15 --max-time 180 \
             -H 'Content-Type: application/x-www-form-urlencoded' \
             --data "$form" "$endpoint" -o "$out" && [ -s "$out" ]; then
            return 0
        fi
    fi
    rm -f "$out"
    if command -v wget >/dev/null 2>&1; then
        if wget -qO "$out" --header='Content-Type: application/x-www-form-urlencoded' \
            --post-data="$form" "$endpoint" && [ -s "$out" ]; then
            return 0
        fi
    fi
    rm -f "$out"
    return 1
}

if backend_asset bundle "$TMP/bundle.tar.gz" && \
   backend_asset sha256 "$TMP/bundle.sha256" && \
   backend_asset signature "$TMP/bundle.sig"; then
    echo "Downloaded signed Agent assets from the cPanel backend."
else
    echo "Backend cache unavailable; downloading the signed GitHub release."
    download "$BASE_URL/$ASSET" "$TMP/bundle.tar.gz"
    download "$BASE_URL/$ASSET.sha256" "$TMP/bundle.sha256"
    download "$BASE_URL/$ASSET.sig" "$TMP/bundle.sig"
fi

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

# Independently verify Ed25519 over the actual archive before touching
# provisioning credentials or running any downloaded installer.
# The raw 32-byte public key is pinned in this release bootstrap.
PUBLIC_KEY_B64='fDiTaZG26qn3BCI3c8cFiO0KdqMW561w6JSaMwXCecw='
if ! command -v openssl >/dev/null 2>&1; then
    echo "ERROR: OpenSSL CLI is required to verify the signed initial install." >&2
    echo "Install the OpenWrt openssl-util package (opkg or apk) and retry; no credentials have been consumed." >&2
    exit 1
fi
if ! command -v base64 >/dev/null 2>&1; then
    echo "ERROR: base64 is required to decode the pinned Ed25519 key." >&2
    exit 1
fi
# Ed25519 DER SubjectPublicKeyInfo prefix: 30 2a 30 05 06 03 2b 65 70 03 21 00
# Octal escapes work with BusyBox ash printf without needing xxd.
printf '\060\052\060\005\006\003\053\145\160\003\041\000' > "$TMP/key.der"
printf '%s' "$PUBLIC_KEY_B64" | base64 -d >> "$TMP/key.der" || {
    echo "ERROR: invalid embedded Ed25519 verification key." >&2
    exit 1
}
[ "$(wc -c < "$TMP/key.der" | tr -d ' ')" = 44 ] || {
    echo "ERROR: invalid verifier key length." >&2
    exit 1
}
if ! openssl pkeyutl -verify -rawin -pubin -keyform DER \
    -inkey "$TMP/key.der" -in "$TMP/bundle.tar.gz" \
    -sigfile "$TMP/bundle.sig" >/dev/null 2>&1; then
    echo "ERROR: Ed25519 signature verification FAILED; refusing installation." >&2
    exit 1
fi
echo "SHA256 and Ed25519 signature verified."
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
