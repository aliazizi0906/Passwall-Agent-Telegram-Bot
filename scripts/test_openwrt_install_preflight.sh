#!/bin/sh
# CI-safe preflight tests. All accepted inputs are stopped by a shadowed mkdir
# before any live /usr/bin, /etc/init.d or /etc/pw-agent path can be touched.
set -eu
ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' 0
mkdir -p "$TMP/bundle" "$TMP/shadow"
cp "$ROOT/openwrt/install.sh" "$TMP/bundle/install.sh"
AUDIT="$TMP/mkdir-invocations"
printf '#!/bin/sh\nprintf "attempted mkdir\\n" >> "%s"\nexit 88\n' "$AUDIT" > "$TMP/shadow/mkdir"
chmod 755 "$TMP/shadow/mkdir"
TESTPATH="$TMP/shadow:$PATH"

rejected_before_mutation() {
    : > "$AUDIT"
    if env PATH="$TESTPATH" "$@" sh "$TMP/bundle/install.sh" >"$TMP/result" 2>&1; then
        echo "ERROR: invalid installer inputs were accepted" >&2
        exit 1
    fi
    if [ -s "$AUDIT" ]; then
        echo "ERROR: installer modified state before rejecting invalid inputs" >&2
        cat "$TMP/result" >&2
        exit 1
    fi
}

# Neither a missing executable nor missing init may reach the service stop.
rejected_before_mutation PW_SERVER_URL=https://example.test/api \
    PW_DEVICE_ID=router-1 PW_DEVICE_SECRET=0123456789abcdef0123456789abcdef

printf '#!/bin/sh\nprintf "1.4.0-alpha.1\\n"\n' > "$TMP/bundle/pw-agent"
chmod 755 "$TMP/bundle/pw-agent"
rejected_before_mutation PW_SERVER_URL=https://example.test/api \
    PW_DEVICE_ID=router-1 PW_DEVICE_SECRET=0123456789abcdef0123456789abcdef

printf '#!/bin/sh\nexit 0\n' > "$TMP/bundle/pw-agent.init"
chmod 755 "$TMP/bundle/pw-agent.init"
rejected_before_mutation PW_SERVER_URL=https://example.test/api \
    PW_DEVICE_ID=router-1 PW_DEVICE_SECRET=
rejected_before_mutation PW_SERVER_URL='https://example.test/";bad/api' \
    PW_DEVICE_ID=router-1 PW_DEVICE_SECRET=0123456789abcdef0123456789abcdef
rejected_before_mutation PW_SERVER_URL=https://example.test/api \
    PW_DEVICE_ID='router/"unsafe' PW_DEVICE_SECRET=0123456789abcdef0123456789abcdef
rejected_before_mutation PW_SERVER_URL=https://example.test/api \
    PW_DEVICE_ID=router-1 PW_DEVICE_SECRET=0123456789abcdef0123456789abcdef \
    PW_UPDATE_PUBLIC_KEY='";bad'

# A fully valid input must get past preflight and reach the guarded staging
# step, but the shadowed mkdir must stop execution before any mutation.
: > "$AUDIT"
if env PATH="$TESTPATH" PW_SERVER_URL=https://example.test/nested/api \
    PW_DEVICE_ID=router-1 PW_DEVICE_SECRET=0123456789abcdef0123456789abcdef \
    sh "$TMP/bundle/install.sh" > "$TMP/result" 2>&1; then
    echo "ERROR: staging guard was unexpectedly bypassed" >&2
    exit 1
fi
[ -s "$AUDIT" ] || { echo "ERROR: valid inputs did not pass preflight" >&2; exit 1; }
echo "OpenWrt initial installer preflight and mutation guard: OK"
