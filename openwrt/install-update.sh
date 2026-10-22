#!/bin/sh
# Signed bundle is verified by the Go agent before this script is invoked.
# Offline fallback preserves existing config and last-known-good binary.
set -eu
umask 077

BASE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BIN="$BASE/pw-agent"
[ -f "$BIN" ] || { echo "ERROR: signed bundle has no pw-agent binary" >&2; exit 1; }
[ -s /etc/pw-agent/config.json ] || { echo "ERROR: router config is missing; refusing update" >&2; exit 1; }

BACKUP_DIR=/etc/pw-agent/backups
mkdir -p "$BACKUP_DIR" /etc/pw-agent/safety
chmod 700 /etc/pw-agent "$BACKUP_DIR" /etc/pw-agent/safety 2>/dev/null || :

WORK="$(mktemp -d /tmp/pw-agent-switch.XXXXXX)" || exit 1
APPLIED=0
DONE=0
rollback() {
    rc=$?
    trap - EXIT INT TERM
    if [ "$APPLIED" = 1 ] && [ "$DONE" != 1 ]; then
        echo "ERROR: Agent update failed; restoring previous executable" >&2
        if [ -f "$WORK/old-agent" ]; then
            cp "$WORK/old-agent" /usr/bin/pw-agent.rollback
            chmod 755 /usr/bin/pw-agent.rollback
            mv -f /usr/bin/pw-agent.rollback /usr/bin/pw-agent
        fi
        if [ -f "$WORK/old-init" ]; then
            cp "$WORK/old-init" /etc/init.d/pw-agent
            chmod 755 /etc/init.d/pw-agent
        fi
        /etc/init.d/pw-agent restart >/dev/null 2>&1 || :
    fi
    rm -rf "$WORK"
    # Only delete the updater workspace when it is the known, signed staging path.
    if [ "$BASE" = "/tmp/pw-agent-update" ]; then
        rm -rf "$BASE"
    fi
    exit "$rc"
}
trap rollback EXIT INT TERM

cp "$BIN" "$WORK/new-agent"
chmod 755 "$WORK/new-agent"
NEW_VERSION="$("$WORK/new-agent" --version)" || {
    echo "ERROR: new agent cannot execute on this architecture" >&2
    exit 1
}
case "$NEW_VERSION" in
    [0-9]*.[0-9]*.[0-9]*) : ;;
    *) echo "ERROR: staged binary returned invalid version: $NEW_VERSION" >&2; exit 1 ;;
esac

if [ -f /usr/bin/pw-agent ]; then
    cp /usr/bin/pw-agent "$WORK/old-agent"
    cp /usr/bin/pw-agent "$BACKUP_DIR/pw-agent.previous"
    chmod 755 "$BACKUP_DIR/pw-agent.previous"
fi
[ ! -f /etc/init.d/pw-agent ] || cp /etc/init.d/pw-agent "$WORK/old-init"

/etc/init.d/pw-agent stop >/dev/null 2>&1 || :
cp "$WORK/new-agent" /usr/bin/pw-agent.new
chmod 755 /usr/bin/pw-agent.new
mv -f /usr/bin/pw-agent.new /usr/bin/pw-agent
APPLIED=1

if [ -f "$BASE/pw-agent.init" ]; then
    cp "$BASE/pw-agent.init" /etc/init.d/pw-agent
    chmod 755 /etc/init.d/pw-agent
fi

# LuCI is optional. Do not silently fail if installation of new UI was requested.
if [ -d "$BASE/luci" ] && [ -d /www/luci-static ]; then
    cp -a "$BASE/luci/." /
    rm -rf /tmp/luci-* 2>/dev/null || :
    /etc/init.d/rpcd restart >/dev/null 2>&1 || :
    /etc/init.d/uhttpd reload >/dev/null 2>&1 || :
fi

/etc/init.d/pw-agent enable >/dev/null 2>&1 || :
/etc/init.d/pw-agent restart >/dev/null 2>&1 || {
    echo "ERROR: service failed to restart" >&2
    exit 1
}
sleep 3
if command -v pidof >/dev/null 2>&1; then
    pidof pw-agent >/dev/null 2>&1 || {
        echo "ERROR: Agent is not running after update" >&2
        exit 1
    }
fi
DONE=1
echo "PW Agent update complete: $NEW_VERSION (previous binary: $BACKUP_DIR/pw-agent.previous)"
