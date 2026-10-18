#!/bin/sh
set -eu
BASE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BIN="$BASE/pw-agent"
[ -f "$BIN" ] || BIN="$BASE/pw-agent-armv7"
[ -f "$BIN" ] || { echo "missing pw-agent binary" >&2; exit 1; }
mkdir -p /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety
chmod 700 /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety 2>/dev/null || true

PREV_BIN=/usr/bin/pw-agent.previous
PREV_INIT=/etc/init.d/pw-agent.previous
PREV_VERSION=/etc/pw-agent/previous-version

# Validate the staged binary before replacing the running copy. Testing the
# /usr/bin staging path also works on routers where /tmp is mounted noexec.
cp "$BIN" /usr/bin/pw-agent.new
chmod 755 /usr/bin/pw-agent.new
/usr/bin/pw-agent.new --version >/tmp/pw-agent-new-version 2>/dev/null || {
    rm -f /usr/bin/pw-agent.new
    echo "new pw-agent binary failed the local version check" >&2
    exit 1
}

if [ -f /usr/bin/pw-agent ]; then
    /usr/bin/pw-agent --version > "$PREV_VERSION.tmp" 2>/dev/null || printf '%s\n' unknown > "$PREV_VERSION.tmp"
    chmod 600 "$PREV_VERSION.tmp" 2>/dev/null || true
    mv -f "$PREV_VERSION.tmp" "$PREV_VERSION"
    cp /usr/bin/pw-agent "$PREV_BIN.new"
    chmod 755 "$PREV_BIN.new"
    mv -f "$PREV_BIN.new" "$PREV_BIN"
fi
if [ -f /etc/init.d/pw-agent ]; then
    cp /etc/init.d/pw-agent "$PREV_INIT.new"
    chmod 755 "$PREV_INIT.new"
    mv -f "$PREV_INIT.new" "$PREV_INIT"
fi

mv -f /usr/bin/pw-agent.new /usr/bin/pw-agent
if [ -f "$BASE/pw-agent.init" ]; then
    cp "$BASE/pw-agent.init" /etc/init.d/pw-agent
    chmod 755 /etc/init.d/pw-agent
fi
if [ -d "$BASE/luci" ]; then
    cp -a "$BASE/luci/." /
    rm -rf /tmp/luci-* 2>/dev/null || true
    /etc/init.d/rpcd restart >/dev/null 2>&1 || true
    /etc/init.d/uhttpd reload >/dev/null 2>&1 || true
fi
/etc/init.d/pw-agent restart >/dev/null 2>&1 || true

# Startup watchdog: if the new executable cannot stay running under procd,
# restore the previous Agent locally before the fleet canary health gate fires.
sleep 6
if ! /usr/bin/pw-agent --version >/tmp/pw-agent-running-version 2>/dev/null \
   || ! /etc/init.d/pw-agent running >/dev/null 2>&1; then
    echo "new Agent failed startup health check; restoring previous binary" >&2
    if [ -f "$PREV_BIN" ]; then
        cp "$PREV_BIN" /usr/bin/pw-agent.rollback
        chmod 755 /usr/bin/pw-agent.rollback
        mv -f /usr/bin/pw-agent.rollback /usr/bin/pw-agent
    fi
    if [ -f "$PREV_INIT" ]; then
        cp "$PREV_INIT" /etc/init.d/pw-agent
        chmod 755 /etc/init.d/pw-agent
    fi
    /etc/init.d/pw-agent restart >/dev/null 2>&1 || true
    sleep 3
    /etc/init.d/pw-agent running >/dev/null 2>&1 || {
        echo "automatic rollback could not restore a running Agent" >&2
        exit 1
    }
    echo "automatic rollback completed" >&2
fi
rm -rf "$BASE" >/dev/null 2>&1 || true
