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

# Guarantee Vazirmatn locally even if the public CDN is unavailable at runtime.
# CDN is the initial source, a second pinned mirror is the download fallback.
# Failing the build is safer than shipping a broken CDN-only font reference.
mkdir -p "$STAGE/backend/assets/fonts"
FONT="$STAGE/backend/assets/fonts/Vazirmatn.woff2"
FONT_URL="https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2"
FONT_MIRROR="https://raw.githubusercontent.com/rastikerdar/vazirmatn/v33.003/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2"
curl --globoff --fail --location --silent --show-error --connect-timeout 15 --max-time 90 --retry 2 \
    "$FONT_URL" --output "$FONT" || \
curl --globoff --fail --location --silent --show-error --connect-timeout 15 --max-time 90 --retry 2 \
    "$FONT_MIRROR" --output "$FONT"
FONT_FILE="$FONT" python3 - <<'PY'
import os
from pathlib import Path
font=Path(os.environ['FONT_FILE'])
data=font.read_bytes()
if len(data)<20000 or data[:4]!=b'wOF2':
    raise SystemExit("Invalid Vazirmatn WOFF2; aborting release package")
PY
curl --fail --location --silent --show-error --max-time 45 \
  https://cdn.jsdelivr.net/npm/vazirmatn@33.0.3/OFL.txt \
  --output "$STAGE/backend/assets/fonts/OFL.txt" || \
curl --fail --location --silent --show-error --max-time 45 \
  https://raw.githubusercontent.com/rastikerdar/vazirmatn/v33.003/OFL.txt \
  --output "$STAGE/backend/assets/fonts/OFL.txt"

mkdir -p "$STAGE/backend/install"
cp openwrt/install-remote.sh "$STAGE/backend/install/remote.sh"
chmod 0644 "$STAGE/backend/install/remote.sh"
[ -f "$STAGE/backend/install/bundle.php" ]
[ -f "$STAGE/backend/install/bundle_core.php" ]
[ -f "$STAGE/backend/auth/login.php" ]
[ -f "$STAGE/backend/web_auth.php" ]
[ -f "$STAGE/backend/assets/vazirmatn.css" ]
[ -s "$STAGE/backend/assets/fonts/Vazirmatn.woff2" ]
[ -s "$STAGE/backend/assets/fonts/OFL.txt" ]
[ -f "$STAGE/backend/assets/control-v14.css" ]
[ -f "$STAGE/backend/assets/login-v14.css" ]
[ -f "$STAGE/backend/assets/login-v14.js" ]

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
