#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import re
import sys
import zipfile
from pathlib import Path

from pypdf import PdfReader


ROOT = Path(__file__).resolve().parents[1]
PLUGIN_DIR = ROOT / "build-r3" / "gulf-breeze-certificates"
PHP = PLUGIN_DIR / "gulf-breeze-certificates.php"
README = PLUGIN_DIR / "README.md"
R2_ZIP = ROOT / "source" / "gulf-breeze-certificates-0.1.0-dev-r2.zip"
ASSETS = ("adee-1317-test-en.pdf", "adee-1317-test-es.pdf")

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
    if brace < 0:
        return ""
    depth = 0
    quote = ""
    escaped = False
    i = brace
    while i < len(source):
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


def delimiter_check(source: str) -> bool:
    cleaned = re.sub(r"<\?(?:php|=)?|\?>", "", source, flags=re.I)
    stack: list[str] = []
    pairs = {')': '(', ']': '[', '}': '{'}
    quote = ""
    escaped = False
    in_line_comment = False
    in_block_comment = False
    i = 0
    while i < len(cleaned):
        ch = cleaned[i]
        nxt = cleaned[i + 1] if i + 1 < len(cleaned) else ""
        if in_line_comment:
            if ch == "\n":
                in_line_comment = False
            i += 1
            continue
        if in_block_comment:
            if ch == "*" and nxt == "/":
                in_block_comment = False
                i += 2
            else:
                i += 1
            continue
        if quote:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = ""
            i += 1
            continue
        if ch == "/" and nxt == "/":
            in_line_comment = True
            i += 2
            continue
        if ch == "/" and nxt == "*":
            in_block_comment = True
            i += 2
            continue
        if ch in ("'", '"'):
            quote = ch
        elif ch in "([{":
            stack.append(ch)
        elif ch in ")]}":
            if not stack or stack.pop() != pairs[ch]:
                return False
        i += 1
    return not stack and not quote and not in_block_comment


source = PHP.read_text(encoding="utf-8")
readme = README.read_text(encoding="utf-8")

check("Version: 0.1.0-dev-r3" in source and "const VERSION = '0.1.0-dev-r3';" in source, "revision 3 identity is consistent")
check("const SCHEMA_VERSION = '0.4.0';" in source, "schema version advances for the migration")
check(delimiter_check(source), "PHP-aware delimiter structure across mixed PHP/HTML template")
check("gb_certificate_download_log" in source, "private download ledger table is declared")
check("contract_signed_utc datetime NULL" in source and "student_download_until_utc datetime NULL" in source, "issuance schema freezes contract dates")
check("$this->backfill_contract_dates();" in extract_function(source, "install_schema"), "schema migration backfills existing certificate records")

contract = extract_function(source, "contract_dates")
check("_gb_ep_contract_signed_at_utc" in contract, "contract signing timestamp is authoritative")
check("modify( '+1 year' )" in contract, "expiration is one calendar year from signing")
check("_gb_ep_contract_expires_at_utc" in contract and "hash_equals( $expected, $saved )" in contract, "frozen order expiration is persisted and conflict checked")
check("add_order_note" in contract and "$order->save()" in contract, "contract-expiration freeze is preserved in the order audit")

issue = extract_function(source, "maybe_issue")
check("$contract_dates = $this->contract_dates( $order, true );" in issue, "issuance requires verifiable contract dates before serial allocation")
check("'contract_signed_utc' => $contract_dates['signed_utc']" in issue, "issuance freezes signed timestamp")
check("'student_download_until_utc' => $contract_dates['expires_utc']" in issue, "issuance freezes student download deadline")

insert_match = re.search(r"\$inserted\s*=\s*\$wpdb->insert\(\s*\$issuances,\s*array\((.*?)\),\s*array\((.*?)\)\s*\);", issue, re.S)
if insert_match:
    insert_keys = re.findall(r"^\s*'[^']+'\s*=>", insert_match.group(1), re.M)
    formats = re.findall(r"'%(?:s|d|f)'", insert_match.group(2))
    check(len(insert_keys) == len(formats) == 30, "issuance insert has matching data and format counts")
else:
    check(False, "issuance insert has matching data and format counts")

access = extract_function(source, "student_download_access")
check("owner_mismatch" in access and "absint( $row['user_id']" in access, "student download enforces certificate ownership")
check("contract_expired" in access and "time() > strtotime" in access, "student download expires at the frozen deadline")
check("_gb_ep_access_revoked" in access, "student download blocks explicit access revocation")
check(all(status in access for status in ("'refunded'", "'cancelled'", "'failed'")), "student download blocks reversed order states")
check("enrollment_identity_mismatch" in access, "student download re-verifies order, course, and student identity")

handler = extract_function(source, "handle_download")
check("wp_verify_nonce" in handler and "check_admin_referer" not in handler, "download verifies nonce while retaining denied-request audit control")
check("$is_admin ? true : $this->student_download_access" in handler, "administrator retrieval remains permanent")
check(handler.count("record_download_event") >= 5, "successful and denied download paths write audit records")
check("administrator_permanent_access" in handler and "student_contract_active" in handler, "successful audit distinguishes administrator and student reasons")
check("The certificate download could not be recorded. No file was released." in handler, "successful downloads fail closed if the ledger write fails")
check("pdf_integrity_failed" in handler, "PDF integrity failures are audited and blocked")

ledger = extract_function(source, "record_download_event")
for field in ("issuance_id", "serial_number", "subject_user_id", "actor_user_id", "actor_role", "outcome", "reason", "requested_utc"):
    check(f"'{field}'" in ledger, f"download ledger captures {field}")

account = extract_function(source, "account_certificates")
check("Download available until:" in account and "Descarga disponible hasta:" in account, "student deadline display is bilingual")
check("true === $access" in account and "$this->download_url" in account, "student download button is shown only after access approval")
check("The student download period has ended" in account and "El período de descarga para estudiantes terminó" in account, "expired student message is bilingual")

admin = extract_function(source, "admin_page")
check("current_user_can( 'manage_options' )" in admin, "certificate administration remains capability restricted")
check("Certificate download audit" in admin and "Student access until UTC" in admin, "administrator dashboard exposes expiry and download audit")
check("LIMIT 200" in admin, "administrator audit view is bounded")
check("file_put_contents" not in handler and "wp_upload_dir" not in source, "downloads never expose a certificate through public uploads")

email = extract_function(source, "send_certificate_email")
check("wp_mail" in email and "email_status" in email, "existing controlled certificate email path remains intact")
check("Email" in admin and "Resend" in admin, "existing administrator email controls remain unchanged in scope")

check("one-calendar-year contract expiration" in readme, "README documents the one-year student deadline")
check("permanent administrator retrieval" in readme, "README documents permanent administrator access")
check("append-only administrator-only download audit" in readme, "README documents the private download ledger")

with zipfile.ZipFile(R2_ZIP) as old_zip:
    for asset in ASSETS:
        old = old_zip.read(f"gulf-breeze-certificates/assets/{asset}")
        new = (PLUGIN_DIR / "assets" / asset).read_bytes()
        check(hashlib.sha256(old).digest() == hashlib.sha256(new).digest(), f"{asset} is byte-identical to approved revision 2")
        reader = PdfReader(PLUGIN_DIR / "assets" / asset)
        check(len(reader.pages) == 3, f"{asset} retains three Letter pages")
        check(all((float(page.mediabox.width), float(page.mediabox.height)) == (612.0, 792.0) for page in reader.pages), f"{asset} page sizes remain Letter")
        raw = new
        check(raw.count(b"GBCTRL00000000000000") == 2, f"{asset} retains two control-number tokens")
        check(b"TEST" in raw or "TEST" in "\n".join((page.extract_text() or "") for page in reader.pages), f"{asset} retains visible test marking")

check("const MODE = 'test';" in source, "test mode remains immutable")
check("90000001" in source and "99999999" in source, "mock serial range remains isolated to eight digits beginning with 9")
check("FOR UPDATE" in source and "START TRANSACTION" in source and "ROLLBACK" in source and "COMMIT" in source, "single-use serial allocation remains transactional")
check("UNIQUE KEY eligibility_key" in source and "UNIQUE KEY type_serial" in source, "issuance uniqueness protections remain intact")
check("TEST-NOT-FOR-TDLR" in source, "test export cannot appear production-ready")

lines.append(f"RESULT {passed} passed, {failed} failed")
print("\n".join(lines))
sys.exit(1 if failed else 0)
