#!/bin/sh
set -eu

BASE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BIN="$BASE/../pw-agent-armv7"
[ -f "$BIN" ] || BIN="$BASE/pw-agent"
[ -f "$BIN" ] || BIN="$BASE/pw-agent-armv7"

if [ ! -f "$BIN" ]; then
    echo "ERROR: pw-agent binary was not found." >&2
    exit 1
fi
if [ ! -f /etc/config/passwall2 ]; then
    echo "ERROR: /etc/config/passwall2 not found. PW Agent requires Passwall2." >&2
    exit 1
fi

/etc/init.d/pw-agent stop 2>/dev/null || true
mkdir -p /etc/pw-agent/backups /etc/pw-agent/safety
chmod 700 /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety 2>/dev/null || true

cp "$BIN" /usr/bin/pw-agent
chmod 755 /usr/bin/pw-agent
cp "$BASE/pw-agent.init" /etc/init.d/pw-agent
chmod 755 /etc/init.d/pw-agent

if [ -d "$BASE/luci" ] && [ -d /www/luci-static ]; then
    cp -a "$BASE/luci/." /
    rm -rf /tmp/luci-* 2>/dev/null || true
    /etc/init.d/rpcd restart >/dev/null 2>&1 || true
    /etc/init.d/uhttpd reload >/dev/null 2>&1 || true
fi

CREATED_CONFIG=0
if [ -n "${PW_SERVER_URL:-}" ] || [ -n "${PW_DEVICE_ID:-}" ] || [ -n "${PW_DEVICE_SECRET:-}" ]; then
    : "${PW_SERVER_URL:?PW_SERVER_URL is required}"
    : "${PW_DEVICE_ID:?PW_DEVICE_ID is required}"
    : "${PW_DEVICE_SECRET:?PW_DEVICE_SECRET is required}"
    echo "$PW_SERVER_URL" | grep -Eq '^https://[^[:space:]"]+/api/?$' || { echo "ERROR: PW_SERVER_URL must be an HTTPS /api URL." >&2; exit 1; }
    echo "$PW_DEVICE_ID" | grep -Eq '^[A-Za-z0-9_-]{1,64}$' || { echo "ERROR: invalid PW_DEVICE_ID." >&2; exit 1; }
    echo "$PW_DEVICE_SECRET" | grep -Eq '^[A-Fa-f0-9]{32,128}$' || { echo "ERROR: invalid PW_DEVICE_SECRET." >&2; exit 1; }
    cat > /etc/pw-agent/config.json <<EOF
{
  "server_url": "$PW_SERVER_URL",
  "device_id": "$PW_DEVICE_ID",
  "device_secret": "$PW_DEVICE_SECRET",
  "update_public_key": "${PW_UPDATE_PUBLIC_KEY:-}",
  "poll_interval_seconds": 3,
  "request_timeout_seconds": 25
}
EOF
    chmod 600 /etc/pw-agent/config.json
    CREATED_CONFIG=1
elif [ ! -f /etc/pw-agent/config.json ]; then
    cp "$BASE/config.example.json" /etc/pw-agent/config.json
    chmod 600 /etc/pw-agent/config.json
    echo "Created template: /etc/pw-agent/config.json"
    echo "Edit server_url, device_id and device_secret, then run: /etc/init.d/pw-agent restart"
fi

touch /etc/sysupgrade.conf
if ! grep -qxF '/etc/pw-agent/' /etc/sysupgrade.conf; then
    sed -i '\|^/etc/pw-agent/config.json$|d' /etc/sysupgrade.conf 2>/dev/null || true
    echo '/etc/pw-agent/' >> /etc/sysupgrade.conf
fi

/etc/init.d/pw-agent enable
if [ "$CREATED_CONFIG" = "1" ] || ! grep -q 'CHANGE_ME' /etc/pw-agent/config.json 2>/dev/null; then
    /etc/init.d/pw-agent restart
    STARTED=yes
else
    /etc/init.d/pw-agent stop 2>/dev/null || true
    STARTED=no
fi

echo
echo "PW Agent installed successfully."
echo "Config: /etc/pw-agent/config.json"
echo "Started: $STARTED"
echo "Version: $(/usr/bin/pw-agent --version 2>/dev/null || true)"
echo "Logs: logread -e pw-agent"
