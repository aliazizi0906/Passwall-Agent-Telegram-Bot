#!/usr/bin/env python3
"""Check repository structure, version pins, and safe UI preview artifacts.

No network, third-party dependencies, secrets or production router required.
"""
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
errors = []

def check(ok, message):
    if not ok:
        errors.append(message)

obsolete = (
    "FEATURES_0.4.0.md",
    "MIGRATION_0.5.0.md",
    "UPGRADE_0.4.0.md",
    "docs/en/legacy/FEATURES_0.4.0.md",
    "docs/en/legacy/UPGRADE_0.4.0.md",
    "docs/fa/legacy/FEATURES_0.4.0.md",
    "docs/fa/legacy/UPGRADE_0.4.0.md",
    "docs/en/RELEASE_CANDIDATE_BUILD.md",
)
for path in obsolete:
    check(not (ROOT / path).exists(), f"Obsolete version stub still present: {path}")

# Earlier-version code is intentionally active in the Go package and PHP backend.
required = (
    "agent_main.go",
    "features_v030.go",
    "features_v040.go",
    "features_v050.go",
    "features_v060.go",
    "features_v130.go",
    "server/lib.php",
    "server/hardening_v050.php",
    "server/platform_v060.php",
    "server/setup.php",
    "server/schema.sql",
    "server/health.php",
    "server/control/index.php",
    "server/dashboard/index.php",
    "server/bot/webhook.php",
    "openwrt/install-remote.sh",
    "openwrt/install.sh",
    "openwrt/install-update.sh",
    "openwrt/luci/usr/share/luci/menu.d/pw-agent.json",
    "openwrt/luci/www/luci-static/resources/view/pw-agent/status.js",
    "openwrt/luci/www/luci-static/resources/view/pw-agent/control.js",
    "docs/fa/INSTALL_V1_3.md",
    "docs/en/UPGRADE_V1_3.md",
)
for path in required:
    check((ROOT / path).is_file(), f"Runtime/install dependency missing: {path}")

agent = (ROOT / "agent_main.go").read_text(encoding="utf-8")
setup = (ROOT / "server/setup_core.php").read_text(encoding="utf-8")
lib = (ROOT / "server/lib.php").read_text(encoding="utf-8")
ma = re.search(r'const agentVersion\s*=\s*"([^"]+)"', agent)
ms = re.search(r"function pw_setup_version\(\): string \{ return '([^']+)';", setup)
ml = re.search(r"function app_version\(\): string \{ return '([^']+)';", lib)
check(bool(ma and ms and ml), "Missing version metadata")
if ma and ms and ml:
    version = ma.group(1)
    check(ms.group(1) == version and ml.group(1) == version,
          f"Version mismatch: agent={version} setup={ms.group(1)} backend={ml.group(1)}")

readme = (ROOT / "README.md").read_text(encoding="utf-8")
check("Current public release / نسخه عمومی فعلی: **v0.6.0**" not in readme,
      "Root README advertises obsolete public-release status")
check("pw-agent-cpanel-v0.6.0.zip" not in readme,
      "Quick installer still points to obsolete v0.6 asset")

images = (
    "control-center-overview.svg",
    "control-center-passwall2.svg",
    "control-center-clients.svg",
    "luci-local-panel.svg",
)
for name in images:
    path = ROOT / "docs/assets/previews" / name
    check(path.is_file(), f"Missing UI preview: {name}")
    if not path.is_file():
        continue
    content = path.read_text(encoding="utf-8")
    check(name in readme, f"Preview {name} not linked by README")
    check("<script" not in content.lower() and "foreignobject" not in content.lower(),
          f"Preview contains executable/embedded HTML: {name}")
    check("sample" in content.lower() or "illustrative" in content.lower(),
          f"Preview missing sample-data disclaimer: {name}")
    try:
        root = ET.fromstring(content)
        check(root.tag == "{http://www.w3.org/2000/svg}svg",
              f"Incorrect SVG namespace: {name}")
    except ET.ParseError as exc:
        errors.append(f"Invalid SVG {name}: {exc}")

if errors:
    print("Repository hygiene check FAILED:")
    for error in errors:
        print(f" - {error}")
    sys.exit(1)
print("Repository hygiene and UI previews: OK")
