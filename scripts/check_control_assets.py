#!/usr/bin/env python3
"""Contract tests for the self-hosted cPanel control/login assets."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
server = ROOT / "server"
control = (server / "control/index.php").read_text(encoding="utf-8")
login = (server / "auth/login.php").read_text(encoding="utf-8")
setup = (server / "setup.php").read_text(encoding="utf-8")
font = (server / "assets/vazirmatn.css").read_text(encoding="utf-8")
control_css = (server / "assets/control-v14.css").read_text(encoding="utf-8")
login_css = (server / "assets/login-v14.css").read_text(encoding="utf-8")
terminal = (server / "terminal/index.php").read_text(encoding="utf-8")
assert "name=\"web_username\"" in setup and "name=\"web_password\"" in setup, "Install must require browser credentials"
assert "../assets/vazirmatn.css" in control and "../assets/vazirmatn.css" in login, "Font CSS link missing"
assert "fonts/Vazirmatn.woff2" in font, "Missing local offline WOFF2 fallback"
assert "cdn.jsdelivr.net/npm/vazirmatn@33.0.3/" in font, "Missing pinned CDN origin"
assert "font-display:swap" in font, "Font fallback display policy missing"
assert "AddType font/woff2 .woff2" in (server / ".htaccess").read_text(), "Font MIME declaration missing"
assert "../assets/control-v14.css" in control and "id=\"menuBtn\"" in control, "Control responsive drawer not wired"
assert "menu-open" in control and "menu-open" in control_css, "Menu drawer lacks interactive state"
assert "prefers-reduced-motion:reduce" in control_css and ":focus-visible" in control_css, "Accessible focus/motion not declared"
assert "../assets/login-v14.css" in login and "auth-shell" in login_css, "Standalone login UI missing"
assert "../assets/login-v14.js" in login, "Visibility script must be served from first-party path"
assert not re.search(r"<script>\s*\(function\(\)\s*\{\s*var button=", login), "CSP blocks inline visibility script"
assert "pw_web_auth_login(" in login and "pw_web_auth_csrf_ok(" in login, "Browser authentication path broken"
print("cPanel login, CSS, Vazirmatn CDN/offline and responsive control asset contracts: OK")
