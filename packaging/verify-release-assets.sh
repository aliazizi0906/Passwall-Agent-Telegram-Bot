#!/bin/sh
set -eu

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 RELEASE_ASSET_DIRECTORY UPDATE_PUBLIC_KEY.b64" >&2
    exit 2
fi

ASSET_DIR=$1
PUBLIC_KEY_B64=$2
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT INT TERM

base64 -d "$PUBLIC_KEY_B64" > "$TEMP_DIR/public.raw"
RAW_SIZE=$(wc -c < "$TEMP_DIR/public.raw" | tr -d '[:space:]')
[ "$RAW_SIZE" = "32" ] || {
    echo "UPDATE_PUBLIC_KEY.b64 must decode to a 32-byte Ed25519 key." >&2
    exit 1
}
{
    printf '\060\052\060\005\006\003\053\145\160\003\041\000'
    cat "$TEMP_DIR/public.raw"
} > "$TEMP_DIR/public.der"
openssl pkey -pubin -inform DER -in "$TEMP_DIR/public.der" -out "$TEMP_DIR/public.pem"

for arch in armv7 arm64 amd64 386 mips mipsle; do
    asset="pw-agent-openwrt-$arch.tar.gz"
    bundle="$ASSET_DIR/$asset"
    checksum="$ASSET_DIR/$asset.sha256"
    signature="$ASSET_DIR/$asset.sig"
    [ -s "$bundle" ] && [ -s "$checksum" ] && [ -s "$signature" ] || {
        echo "Missing release bundle, checksum, or signature for $arch." >&2
        exit 1
    }

    expected=$(awk 'NF { print $1; exit }' "$checksum")
    case "$expected" in
        *[!0123456789abcdefABCDEF]*|'') echo "Invalid SHA256 in $checksum" >&2; exit 1 ;;
    esac
    [ "${#expected}" -eq 64 ] || { echo "Invalid SHA256 length in $checksum" >&2; exit 1; }
    actual=$(sha256sum "$bundle" | awk '{ print $1 }')
    [ "$actual" = "$expected" ] || { echo "SHA256 mismatch for $asset" >&2; exit 1; }
    openssl pkeyutl -verify -rawin -pubin -inkey "$TEMP_DIR/public.pem" \
        -in "$bundle" -sigfile "$signature" >/dev/null
    echo "Verified $asset"
done
