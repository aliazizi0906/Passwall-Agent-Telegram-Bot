#!/bin/sh
set -eu

if [ "$#" -ne 4 ]; then
    echo "Usage: $0 FEED_ARCHIVE SHA256_FILE ED25519_SIGNATURE UPDATE_PUBLIC_KEY.b64" >&2
    exit 2
fi

ARCHIVE=$1
CHECKSUM_FILE=$2
SIGNATURE=$3
PUBLIC_KEY_B64=$4
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT INT TERM

[ -s "$ARCHIVE" ] && [ -s "$CHECKSUM_FILE" ] && [ -s "$SIGNATURE" ] || {
    echo "Archive, checksum, or signature file is missing or empty." >&2
    exit 1
}

expected=$(awk 'NF { print $1; exit }' "$CHECKSUM_FILE")
case "$expected" in
    *[!0123456789abcdefABCDEF]*|'') echo "Invalid SHA256 in $CHECKSUM_FILE" >&2; exit 1 ;;
esac
[ "${#expected}" -eq 64 ] || { echo "Invalid SHA256 length in $CHECKSUM_FILE" >&2; exit 1; }
actual=$(sha256sum "$ARCHIVE" | awk '{ print $1 }')
[ "$actual" = "$expected" ] || { echo "SHA256 mismatch for $ARCHIVE" >&2; exit 1; }

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
openssl pkeyutl -verify -rawin -pubin -inkey "$TEMP_DIR/public.pem" \
    -in "$ARCHIVE" -sigfile "$SIGNATURE"
echo "Verified signed PW Agent opkg feed archive: $ARCHIVE"
