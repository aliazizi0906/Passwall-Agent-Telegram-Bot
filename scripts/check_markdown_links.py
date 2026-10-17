#!/usr/bin/env python3
from pathlib import Path
from urllib.parse import unquote
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
LINK_RE = re.compile(r'!?\[[^\]]*\]\(([^)]+)\)')
errors = []

for md in ROOT.rglob("*.md"):
    if ".git" in md.parts:
        continue
    text = md.read_text(encoding="utf-8")
    for match in LINK_RE.finditer(text):
        raw = match.group(1).strip()
        if not raw or raw.startswith("#"):
            continue
        target = raw.split()[0].strip("<>")
        if re.match(r"^[a-zA-Z][a-zA-Z0-9+.-]*://", target) or target.startswith("mailto:"):
            continue
        path_part = unquote(target.split("#", 1)[0].split("?", 1)[0])
        if not path_part:
            continue
        if path_part.startswith("/"):
            resolved = ROOT / path_part.lstrip("/")
        else:
            resolved = md.parent / path_part
        if not resolved.resolve().exists():
            errors.append(f"{md.relative_to(ROOT)} -> {raw}")

if errors:
    print("Broken local Markdown links:")
    for error in errors:
        print(f"  - {error}")
    sys.exit(1)

print("Markdown local links: OK")
