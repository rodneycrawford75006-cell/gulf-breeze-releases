#!/usr/bin/env python3
from __future__ import annotations

import re
import sys
import os
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = Path(os.environ.get(
    "GB_SITE_PROTECTION_PLUGIN_DIR",
    ROOT / "work" / "site-protection-r1" / "gulf-breeze-site-protection",
))
PHP = PLUGIN / "gulf-breeze-site-protection.php"
README = PLUGIN / "README.md"

passed = 0
failed = 0
lines: list[str] = []


def check(condition: bool, label: str) -> None:
    global passed, failed
    if condition:
        passed += 1
        lines.append(f"PASS {label}")
    else:
        failed += 1
        lines.append(f"FAIL {label}")


def extract_function(source: str, name: str) -> str:
    match = re.search(rf"\bfunction\s+{re.escape(name)}\s*\(", source)
    if not match:
        return ""
    brace = source.find("{", match.end())
    depth = 0
    quote = ""
    escaped = False
    i = brace
    while i >= 0 and i < len(source):
        ch = source[i]
        nxt = source[i + 1] if i + 1 < len(source) else ""
        if quote:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = ""
            i += 1
            continue
        if ch in ("'", '"'):
            quote = ch
        elif ch == "/" and nxt == "/":
            end = source.find("\n", i + 2)
            i = len(source) if end < 0 else end
            continue
        elif ch == "/" and nxt == "*":
            end = source.find("*/", i + 2)
            i = len(source) if end < 0 else end + 2
            continue
        elif ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return source[match.start() : i + 1]
        i += 1
    return ""


def balanced(source: str) -> bool:
    cleaned = re.sub(r"\?>.*?<\?php", "", source, flags=re.I | re.S)
    cleaned = re.sub(r"<\?(?:php|=)?|\?>", "", cleaned, flags=re.I)
    stack: list[str] = []
    pairs = {")": "(", "]": "[", "}": "{"}
    quote = ""
    escaped = False
    i = 0
    while i < len(cleaned):
        ch = cleaned[i]
        nxt = cleaned[i + 1] if i + 1 < len(cleaned) else ""
        if quote:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = ""
            i += 1
            continue
        if ch == "/" and nxt in ("/", "*"):
            end = cleaned.find("\n" if nxt == "/" else "*/", i + 2)
            if end < 0:
                return nxt == "/" and not stack
            i = end + (0 if nxt == "/" else 2)
            continue
        if ch in ("'", '"'):
            quote = ch
        elif ch in "([{":
            stack.append(ch)
        elif ch in ")]}":
            if not stack or stack.pop() != pairs[ch]:
                return False
        i += 1
    return not stack and not quote


source = PHP.read_text(encoding="utf-8")
readme = README.read_text(encoding="utf-8")

check("Version: 0.1.0-dev-r1" in source and "const VERSION = '0.1.0-dev-r1';" in source, "revision identity is consistent")
check(balanced(source), "mixed PHP and HTML delimiters are balanced")
check("register_activation_hook" in source and "'enabled' => 1" in extract_function(source, "activate"), "first activation defaults to protected")
check("add_action( 'template_redirect'" in source, "front-end protection is registered")

protect = extract_function(source, "protect_logged_out_request")
check("is_user_logged_in()" in protect and protect.find("is_user_logged_in()") < protect.find("protection_is_active()"), "every authenticated user bypasses before protection logic")
check("render_construction_page" in protect, "only non-exempt logged-out requests render protection")

infra = extract_function(source, "infrastructure_request")
for marker, label in (
    ("wp_doing_ajax", "WordPress AJAX"),
    ("wp_doing_cron", "WordPress cron"),
    ("REST_REQUEST", "REST and webhook routes"),
    ("XMLRPC_REQUEST", "XML-RPC"),
    ("WP_CLI", "WP-CLI"),
    ("wc-api", "WooCommerce API callbacks"),
    ("wc-ajax", "WooCommerce AJAX callbacks"),
    ("/wp-login.php", "login and password reset"),
):
    check(marker in infra, f"{label} bypasses protection")

render = extract_function(source, "render_construction_page")
check("status_header( 503 )" in render and "Retry-After: 3600" in render, "construction response is an explicit temporary 503")
check("nocache_headers()" in render and "no-store, no-cache" in render, "construction response cannot be reused as an authenticated page")
check("X-Robots-Tag: noindex" in render, "construction response is excluded from indexing")
check("Student sign in / Iniciar sesión" in render and "wp_login_url" in render, "bilingual student sign-in remains available")

for handler in ("handle_save", "handle_open_window", "handle_close_window"):
    body = extract_function(source, handler)
    check("require_admin_action" in body, f"{handler} is capability and nonce gated")

window = extract_function(source, "handle_open_window")
check("array( 15, 30, 60, 120 )" in window and "MINUTE_IN_SECONDS" in window, "timed windows are bounded to approved durations")
check("time() >= $open_until" in extract_function(source, "protection_is_active"), "public window closes automatically without cron")
check("add_options_page" in extract_function(source, "admin_menu"), "control is limited to a dedicated settings page")
check("admin_notices" not in source and "admin_bar" not in source, "plugin adds no global administrator or student notice")
check("Drive Smart" not in source + readme, "package contains only Gulf Breeze branding")
check(not re.search(r"bypass|allow" + r"[_-]?(?:key|token|cookie)", source, re.I), "no public bypass token or cookie exists")
check("course" not in extract_function(source, "handle_save").lower() and "order" not in extract_function(source, "handle_save").lower(), "settings do not mutate course or order data")
check("safe replacement sequence" in readme.lower(), "README documents no-gap replacement")

lines.append(f"RESULT {passed} passed, {failed} failed")
print("\n".join(lines))
sys.exit(1 if failed else 0)
