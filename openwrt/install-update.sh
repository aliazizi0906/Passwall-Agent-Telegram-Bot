#!/bin/sh
set -eu
BASE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BIN="$BASE/pw-agent"
[ -f "$BIN" ] || BIN="$BASE/pw-agent-armv7"
[ -f "$BIN" ] || { echo "missing pw-agent binary" >&2; exit 1; }
mkdir -p /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety
chmod 700 /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety 2>/dev/null || true
cp "$BIN" /usr/bin/pw-agent.new
chmod 755 /usr/bin/pw-agent.new
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
rm -rf "$BASE" >/dev/null 2>&1 || true
