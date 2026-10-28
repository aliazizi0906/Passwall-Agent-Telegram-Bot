#!/bin/sh
# Initial OpenWrt install with preflight and best-effort local rollback.
# The signed remote/bootstrap installer verifies the archive BEFORE invoking us.
set -eu
umask 077

BASE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BIN="$BASE/pw-agent"
[ -s "$BIN" ] || BIN="$BASE/../pw-agent-armv7"
[ -s "$BIN" ] || BIN="$BASE/pw-agent-armv7"
[ -s "$BIN" ] || { echo "ERROR: pw-agent binary was not found." >&2; exit 1; }
[ -s "$BASE/pw-agent.init" ] || { echo "ERROR: init script is missing." >&2; exit 1; }

# Validate ALL provisioning values before touching the live service.
CONFIG_MODE=keep
if [ -n "${PW_SERVER_URL:-}" ] || [ -n "${PW_DEVICE_ID:-}" ] || [ -n "${PW_DEVICE_SECRET:-}" ]; then
    : "${PW_SERVER_URL:?PW_SERVER_URL is required}"
    : "${PW_DEVICE_ID:?PW_DEVICE_ID is required}"
    : "${PW_DEVICE_SECRET:?PW_DEVICE_SECRET is required}"
    printf '%s\n' "$PW_SERVER_URL" | grep -Eq '^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~-]+)*/api/?$' || {
        echo "ERROR: PW_SERVER_URL must be a valid HTTPS /api URL." >&2; exit 1;
    }
    printf '%s\n' "$PW_DEVICE_ID" | grep -Eq '^[A-Za-z0-9_-]{1,64}$' || {
        echo "ERROR: invalid PW_DEVICE_ID." >&2; exit 1;
    }
    printf '%s\n' "$PW_DEVICE_SECRET" | grep -Eq '^[A-Fa-f0-9]{32,128}$' || {
        echo "ERROR: invalid PW_DEVICE_SECRET." >&2; exit 1;
    }
    if [ -n "${PW_UPDATE_PUBLIC_KEY:-}" ]; then
        printf '%s\n' "$PW_UPDATE_PUBLIC_KEY" | grep -Eq '^[A-Za-z0-9+/=]{40,88}$' || {
            echo "ERROR: invalid PW_UPDATE_PUBLIC_KEY." >&2; exit 1;
        }
    fi
    CONFIG_MODE=provision
elif [ ! -s /etc/pw-agent/config.json ]; then
    [ -s "$BASE/config.example.json" ] || {
        echo "ERROR: initial config template is missing." >&2; exit 1;
    }
    CONFIG_MODE=template
fi

# A stale lock after power loss is intentionally not cleared automatically:
# an administrator should inspect the router before attempting a second install.
mkdir -p /etc/pw-agent/backups /etc/pw-agent/safety
chmod 700 /etc/pw-agent /etc/pw-agent/backups /etc/pw-agent/safety 2>/dev/null || :
LOCK=/etc/pw-agent/safety/install.lock
if ! mkdir "$LOCK" 2>/dev/null; then
    echo "ERROR: another install is active or an old lock needs inspection: $LOCK" >&2
    exit 1
fi
WORK=
SWITCHED=0
COMMITTED=0
HAD_BIN=0
HAD_INIT=0
HAD_CONFIG=0
NEW_BIN=/usr/bin/pw-agent.new.$$
NEW_INIT=/etc/init.d/pw-agent.new.$$
NEW_CONFIG=/etc/pw-agent/config.json.new.$$
rollback_install() {
    rc=$?
    trap - 0 INT TERM
    if [ "$SWITCHED" = 1 ] && [ "$COMMITTED" != 1 ]; then
        echo "ERROR: install failed, attempting to restore prior Agent, init and config." >&2
        /etc/init.d/pw-agent stop >/dev/null 2>&1 || :
        if [ "$HAD_BIN" = 1 ]; then
            cp "$WORK/old-agent" /usr/bin/pw-agent.restore.$$ &&
                chmod 755 /usr/bin/pw-agent.restore.$$ &&
                mv -f /usr/bin/pw-agent.restore.$$ /usr/bin/pw-agent || :
        else
            rm -f /usr/bin/pw-agent
        fi
        if [ "$HAD_INIT" = 1 ]; then
            cp "$WORK/old-init" /etc/init.d/pw-agent.restore.$$ &&
                chmod 755 /etc/init.d/pw-agent.restore.$$ &&
                mv -f /etc/init.d/pw-agent.restore.$$ /etc/init.d/pw-agent || :
        else
            rm -f /etc/init.d/pw-agent
        fi
        if [ "$HAD_CONFIG" = 1 ]; then
            cp "$WORK/old-config" /etc/pw-agent/config.json.restore.$$ &&
                chmod 600 /etc/pw-agent/config.json.restore.$$ &&
                mv -f /etc/pw-agent/config.json.restore.$$ /etc/pw-agent/config.json || :
        else
            rm -f /etc/pw-agent/config.json
        fi
        if [ "$HAD_BIN" = 1 ] && [ "$HAD_INIT" = 1 ]; then
            /etc/init.d/pw-agent restart >/dev/null 2>&1 || :
        fi
    fi
    rm -f "$NEW_BIN" "$NEW_INIT" "$NEW_CONFIG" \
        /usr/bin/pw-agent.restore.$$ /etc/init.d/pw-agent.restore.$$ \
        /etc/pw-agent/config.json.restore.$$
    [ -z "$WORK" ] || rm -rf "$WORK"
    rmdir "$LOCK" 2>/dev/null || :
    exit "$rc"
}
trap rollback_install 0
trap 'exit 130' INT
trap 'exit 143' TERM

WORK="$(mktemp -d /tmp/pw-agent-install.XXXXXX)"
cp "$BIN" "$WORK/new-agent"
chmod 755 "$WORK/new-agent"
VERSION="$("$WORK/new-agent" --version)" || {
    echo "ERROR: binary cannot execute on this router architecture." >&2; exit 1;
}
case "$VERSION" in
    [0-9]*.[0-9]*.[0-9]*) : ;;
    *) echo "ERROR: invalid Agent binary version." >&2; exit 1 ;;
esac
cp "$BASE/pw-agent.init" "$WORK/new-init"

[ ! -f /usr/bin/pw-agent ] || { cp /usr/bin/pw-agent "$WORK/old-agent"; HAD_BIN=1; }
[ ! -f /etc/init.d/pw-agent ] || { cp /etc/init.d/pw-agent "$WORK/old-init"; HAD_INIT=1; }
[ ! -f /etc/pw-agent/config.json ] || { cp /etc/pw-agent/config.json "$WORK/old-config"; HAD_CONFIG=1; }

if [ "$CONFIG_MODE" = provision ]; then
    cat > "$WORK/new-config" <<EOF
{
  "server_url": "$PW_SERVER_URL",
  "device_id": "$PW_DEVICE_ID",
  "device_secret": "$PW_DEVICE_SECRET",
  "update_public_key": "${PW_UPDATE_PUBLIC_KEY:-}",
  "poll_interval_seconds": 3,
  "request_timeout_seconds": 25
}
EOF
elif [ "$CONFIG_MODE" = template ]; then
    cp "$BASE/config.example.json" "$WORK/new-config"
fi

# Stage on destination filesystems so each live rename is atomic.
cp "$WORK/new-agent" "$NEW_BIN"
chmod 755 "$NEW_BIN"
cp "$WORK/new-init" "$NEW_INIT"
chmod 755 "$NEW_INIT"
if [ "$CONFIG_MODE" != keep ]; then
    cp "$WORK/new-config" "$NEW_CONFIG"
    chmod 600 "$NEW_CONFIG"
fi

# Preserve a last-known-good backup outside /tmp before stopping the Agent.
if [ "$HAD_BIN" = 1 ]; then
    cp "$WORK/old-agent" /etc/pw-agent/backups/pw-agent.previous.tmp.$$
    chmod 700 /etc/pw-agent/backups/pw-agent.previous.tmp.$$
    mv -f /etc/pw-agent/backups/pw-agent.previous.tmp.$$ /etc/pw-agent/backups/pw-agent.previous
fi
if [ "$HAD_INIT" = 1 ]; then
    cp "$WORK/old-init" /etc/pw-agent/backups/pw-agent.init.previous
    chmod 700 /etc/pw-agent/backups/pw-agent.init.previous
fi
if [ "$HAD_CONFIG" = 1 ]; then
    cp "$WORK/old-config" /etc/pw-agent/backups/config.previous
    chmod 600 /etc/pw-agent/backups/config.previous
fi

if [ ! -f /etc/config/passwall2 ]; then
    echo "INFO: Passwall2 is not installed; generic OpenWrt capabilities remain available." >&2
fi

/etc/init.d/pw-agent stop >/dev/null 2>&1 || :
SWITCHED=1
mv -f "$NEW_BIN" /usr/bin/pw-agent
mv -f "$NEW_INIT" /etc/init.d/pw-agent
if [ "$CONFIG_MODE" != keep ]; then
    mv -f "$NEW_CONFIG" /etc/pw-agent/config.json
fi

/etc/init.d/pw-agent enable >/dev/null 2>&1 || {
    echo "ERROR: could not enable Agent service." >&2; exit 1;
}
STARTED=no
if grep -q 'CHANGE_ME' /etc/pw-agent/config.json 2>/dev/null; then
    /etc/init.d/pw-agent stop >/dev/null 2>&1 || :
    echo "INFO: template config installed; edit it before starting the Agent."
else
    /etc/init.d/pw-agent restart >/dev/null 2>&1 || {
        echo "ERROR: Agent failed to restart." >&2; exit 1;
    }
    sleep 2
    if command -v pidof >/dev/null 2>&1; then
        pidof pw-agent >/dev/null 2>&1 || {
            echo "ERROR: Agent process did not remain running." >&2; exit 1;
        }
    fi
    STARTED=yes
fi
COMMITTED=1

# These optional integrations are post-commit and explicitly nontransactional.
touch /etc/sysupgrade.conf 2>/dev/null || :
if [ -f /etc/sysupgrade.conf ] && ! grep -qxF '/etc/pw-agent/' /etc/sysupgrade.conf; then
    echo '/etc/pw-agent/' >> /etc/sysupgrade.conf || echo "WARNING: sysupgrade persistence was not configured." >&2
fi
if [ -d "$BASE/luci" ] && [ -d /www/luci-static ]; then
    if cp -a "$BASE/luci/." /; then
        rm -rf /tmp/luci-* 2>/dev/null || :
        /etc/init.d/rpcd restart >/dev/null 2>&1 || :
        /etc/init.d/uhttpd reload >/dev/null 2>&1 || :
    else
        echo "WARNING: optional LuCI files were not fully installed; retry LuCI deployment separately." >&2
    fi
fi

echo "PW Agent installed: $VERSION"
echo "Config: /etc/pw-agent/config.json"
echo "Started: $STARTED"
echo "Logs: logread -e pw-agent"
echo "Agent/init/config rollback is attempted on managed installer errors; power-loss recovery and LuCI rollback are not guaranteed."
