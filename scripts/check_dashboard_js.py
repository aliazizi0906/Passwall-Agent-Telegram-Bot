#!/usr/bin/env python3
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
targets = [
    ROOT / "server" / "dashboard" / "index.php",
    ROOT / "server" / "control" / "index.php",
    ROOT / "server" / "setup.php",
    ROOT / "server" / "terminal" / "index.php",
]
failed = False

for path in targets:
    text = path.read_text(encoding="utf-8")
    blocks = re.findall(r"<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>", text, flags=re.I | re.S)
    if not blocks:
        print(f"{path.relative_to(ROOT)}: no inline JavaScript found")
        failed = True
        continue
    for i, js in enumerate(blocks, 1):
        proc = subprocess.run(["node", "--check", "-"], input=js, text=True, capture_output=True)
        if proc.returncode != 0:
            print(f"{path.relative_to(ROOT)} inline script #{i}: invalid")
            print(proc.stderr)
            failed = True
        else:
            print(f"{path.relative_to(ROOT)} inline script #{i}: OK")

sys.exit(1 if failed else 0)
