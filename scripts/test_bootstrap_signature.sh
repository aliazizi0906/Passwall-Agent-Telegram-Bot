#!/bin/sh
# Offline verifier coverage: public-key pin consistency, DER format,
# accepted signed payload and rejected tampered payload.
set -eu
ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT INT TERM
command -v openssl >/dev/null 2>&1
command -v base64 >/dev/null 2>&1

PIN="$(sed -n "s/^PUBLIC_KEY_B64='\([^']*\)'.*$/\1/p" "$ROOT/openwrt/install-remote.sh")"
EXPECTED="$(tr -d '\r\n' < "$ROOT/UPDATE_PUBLIC_KEY.b64")"
[ -n "$PIN" ] && [ "$PIN" = "$EXPECTED" ] || {
  echo "Pinned key diverges from published UPDATE_PUBLIC_KEY.b64" >&2; exit 1;
}
printf '\060\052\060\005\006\003\053\145\160\003\041\000' > "$TMP/official.der"
printf '%s' "$PIN" | base64 -d >> "$TMP/official.der"
[ "$(wc -c < "$TMP/official.der" | tr -d ' ')" = 44 ]
openssl pkey -pubin -inform DER -in "$TMP/official.der" -noout

openssl genpkey -algorithm ED25519 -out "$TMP/test-private.pem" >/dev/null 2>&1
openssl pkey -in "$TMP/test-private.pem" -pubout -outform DER -out "$TMP/test-public.der"
printf 'release payload example\n' > "$TMP/archive"
openssl pkeyutl -sign -rawin -inkey "$TMP/test-private.pem" -in "$TMP/archive" -out "$TMP/archive.sig"
openssl pkeyutl -verify -rawin -pubin -keyform DER -inkey "$TMP/test-public.der" \
    -in "$TMP/archive" -sigfile "$TMP/archive.sig" >/dev/null
printf 'malicious payload\n' > "$TMP/archive"
if openssl pkeyutl -verify -rawin -pubin -keyform DER -inkey "$TMP/test-public.der" \
    -in "$TMP/archive" -sigfile "$TMP/archive.sig" >/dev/null 2>&1; then
  echo "Tampered archive was accepted" >&2; exit 1
fi
echo "Signed bootstrap Ed25519 pin and verification: OK"
