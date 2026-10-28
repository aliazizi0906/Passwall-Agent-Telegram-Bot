#!/usr/bin/env bash
# Build a config-free, cPanel File-Manager-installable backend ZIP.
# Called by GitHub Actions; can be run locally with rsync and zip.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
VERSION="$(php -r 'require "server/setup_core.php"; echo pw_setup_version();')"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-z0-9.]+)?$ ]] || { echo "Invalid version: $VERSION" >&2; exit 1; }
command -v rsync >/dev/null
command -v zip >/dev/null

mkdir -p dist
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/backend"
rsync -a \
  --exclude='config.php' \
  --exclude='*.bak' \
  --exclude='*.backup' \
  --exclude='*.swp' \
  --exclude='.env' \
  --exclude='.git/' \
  --exclude='releases/' \
  --exclude='tests/' \
  --exclude='config.example.php' \
  --exclude='logs/' \
  --exclude='backups/' \
  --exclude='cache/' \
  --exclude='*.log' \
  --exclude='*.sqlite' \
  server/ "$STAGE/backend/"

[ -f "$STAGE/backend/setup.php" ]
[ -f "$STAGE/backend/health.php" ]
[ -f "$STAGE/backend/control/index.php" ]
[ -f "$STAGE/backend/.htaccess" ]
[ ! -e "$STAGE/backend/config.php" ]
[ ! -e "$STAGE/backend/tests" ]
[ ! -e "$STAGE/backend/config.example.php" ]
if find "$STAGE/backend" -type l | grep -q .; then
  echo "Unexpected symbolic link in cPanel release" >&2
  exit 1
fi
if find "$STAGE/backend" -type f \( -name '*.bak' -o -name '*.backup' -o -name '*.log' -o -name '.env' \) | grep -q .; then
  echo "Unexpected runtime artifact in cPanel release" >&2
  exit 1
fi

ARCHIVE="$ROOT/dist/pw-agent-cpanel-v${VERSION}.zip"
rm -f "$ARCHIVE"
(cd "$STAGE/backend" && zip -q -r "$ARCHIVE" .)
sha256sum "$ARCHIVE" > "$ARCHIVE.sha256"
unzip -l "$ARCHIVE" | grep -q 'control/index.php'
echo "Packaged $ARCHIVE"
