#!/bin/sh
set -eu

BASE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BIN="$BASE/../pw-agent-armv7"
[ -f "$BIN" ] || BIN="$BASE/pw-agent-armv7"

if [ ! -x "$BIN" ]; then
    echo "ERROR: pw-agent-armv7 was not found." >&2
    echo "Expected: $BASE/../pw-agent-armv7" >&2
    exit 1
fi
if [ ! -f /etc/config/passwall2 ]; then
    echo "ERROR: /etc/config/passwall2 not found. This build targets Passwall2." >&2
    exit 1
fi

/etc/init.d/pw-agent stop 2>/dev/null || true
mkdir -p /etc/pw-agent/backups /etc/pw-agent/safety
chmod 700 /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety 2>/dev/null || true

cp "$BIN" /usr/bin/pw-agent
chmod 755 /usr/bin/pw-agent
cp "$BASE/pw-agent.init" /etc/init.d/pw-agent
chmod 755 /etc/init.d/pw-agent

# Install the LuCI health/diagnostics page when LuCI is present.
if [ -d "$BASE/luci" ] && [ -d /www/luci-static ]; then
    cp -a "$BASE/luci/." /
    rm -rf /tmp/luci-* 2>/dev/null || true
    /etc/init.d/rpcd restart >/dev/null 2>&1 || true
    /etc/init.d/uhttpd reload >/dev/null 2>&1 || true
fi

if [ ! -f /etc/pw-agent/config.json ]; then
    cp "$BASE/config.example.json" /etc/pw-agent/config.json
    chmod 600 /etc/pw-agent/config.json
    echo "Created /etc/pw-agent/config.json"
    echo "Edit server_url, device_id and device_secret before starting the agent."
fi

# Ensure the agent credentials survive standard OpenWrt configuration backups.
touch /etc/sysupgrade.conf
if ! grep -qxF '/etc/pw-agent/' /etc/sysupgrade.conf; then
    sed -i '\|^/etc/pw-agent/config.json$|d' /etc/sysupgrade.conf 2>/dev/null || true
    echo '/etc/pw-agent/' >> /etc/sysupgrade.conf
fi

/etc/init.d/pw-agent enable
/etc/init.d/pw-agent start

echo
printf '%s\n' \
  "pw-agent installed/updated successfully." \
  "Config: /etc/pw-agent/config.json" \
  "Status: pgrep -af pw-agent" \
  "Logs:   logread -e pw-agent"
