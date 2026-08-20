#!/usr/bin/env python3
"""Focused source/package checks for Gulf Breeze Test Student Fixtures r2."""

from pathlib import Path
import hashlib
import re
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "gulf-breeze-test-student-fixtures" / "gulf-breeze-test-student-fixtures.php"
README = ROOT / "gulf-breeze-test-student-fixtures" / "readme.txt"
zip_args = [arg for arg in sys.argv[1:] if arg.endswith(".zip")]
archive = Path(zip_args[0]) if zip_args else None

passed = 0
failed = 0


def check(name: str, condition: bool) -> None:
    global passed, failed
    if condition:
        passed += 1
        print(f"PASS {name}")
    else:
        failed += 1
        print(f"FAIL {name}")


if PLUGIN.is_file() and README.is_file():
    source_bytes = PLUGIN.read_bytes()
    readme_bytes = README.read_bytes()
elif archive and archive.is_file():
    with zipfile.ZipFile(archive) as package:
        source_bytes = package.read("gulf-breeze-test-student-fixtures/gulf-breeze-test-student-fixtures.php")
        readme_bytes = package.read("gulf-breeze-test-student-fixtures/readme.txt")
else:
    source_bytes = b""
    readme_bytes = b""

source = source_bytes.decode("utf-8")
readme = readme_bytes.decode("utf-8")

check("plugin source is available", bool(source_bytes))
check("readme is available", bool(readme_bytes))
check("version is r2", "Version: 0.1.0-dev-r2" in source and "const VERSION = '0.1.0-dev-r2'" in source)
check("direct access is blocked", "if ( ! defined( 'ABSPATH' ) )" in source)
check("admin page requires manage_options", source.count("current_user_can( 'manage_options' )") >= 3)
check("POST uses a nonce", "check_admin_referer( 'gb_create_test_student_fixtures' )" in source)
check("exact confirmation phrase is required", "const CONFIRMATION = 'CREATE SAMPLE STUDENTS'" in source)
check("Under Construction gate exists", "under_construction_enabled" in source and "ucp_options" in source)
check("certificate test-mode gate exists", "'test' !== Gulf_Breeze_Certificates::MODE" in source)
check("WooCommerce dependency gate exists", "class_exists( 'WooCommerce' )" in source)
check("LearnPress dependency gate exists", "UserCourseModel" in source)
check("enrollment dependency gate exists", "Gulf_Breeze_Enrollment_Payment" in source)
check("mastery dependency gate exists", "GulfBreeze_Course_Mastery_Gate" in source)
check("five controlled English profiles exist", len(re.findall(r"array\( 'slug' =>", source)) == 5)
check("English eligible profile exists", "eligible-en" in source)
check("unfinished Spanish course is not required", "eligible-es" not in source and "adult_es" not in source)
check("two deliberate partial-gate controls exist", "final-only" in source and "complete-no-final" in source)
check("accounts are marked disposable", "update_user_meta( $user_id, self::MARKER, 'yes' )" in source)
check("fixture accounts cannot be administrators", "user_can( $user_id, 'manage_options' )" in source)
check("internal tester access is granted", "_internal_tester_user_ids" in source)
check("orders are zero-dollar", "set_total( 0 )" in source)
check("orders are visibly synthetic", "TEST FIXTURE — NO PAYMENT" in source)
check("no PayPal transaction is created", "set_transaction_id" not in source and "payment_complete(" not in source)
check("required enrollment state is present", "'_gb_ep_state', 'paid_enrolled'" in source)
check("order/student/course identity links are present", all(x in source for x in ("_gb_ep_student_user_id", "_gb_ep_course_id", "_gb_ep_active_course_")))
check("contract snapshot is visibly test-only", "'test_fixture' => true" in source and "_gb_ep_contract_snapshot_json" in source)
check("reset-compatible contract marker is present", "'_gb_ep_contract_required', 'yes'" in source)
check("real LearnPress completion fields are used", "'status' => 'finished'" in source and "'graduation' => 'passed'" in source)
check("mid-course lesson rows are simulated", "insert_user_item" in source and "item_type='lp_lesson'" in source)
check("real mastery mapping key is reproduced", "'_gbcmg_state_' . $this->mapping_id( $map )" in source)
check("mastery evidence is explicitly marked test", "'test_fixture_batch' => $batch" in source)
check("certificate issuance is not called directly", "maybe_issue(" not in source and "reconcile_eligible_completions(" not in source)
check("automatic issuance hook is deliberately avoided", "$wpdb->insert( $wpdb->usermeta" in source)
check("Woo transactional emails are suppressed during seeding", "woocommerce_email_enabled_customer_completed_order" in source)
check("mail filters are removed", "remove_filter( $filter, '__return_false', 999 )" in source)
check("existing Under Construction settings are never changed", "update_option( 'ucp_options'" not in source and "delete_option( 'ucp_options'" not in source)
check("no user/order deletion exists", "wp_delete_user(" not in source and "wp_delete_post(" not in source and "delete_order(" not in source)
check("no live install or publication code exists", "plugins_api(" not in source and "GitHub" not in source)
check("readme documents exactly one expected issuance", "Exactly one English fixture student" in readme)

# Basic delimiter balance is intentionally secondary to PHP 8.4 lint on the exact stack.
for opening, closing, label in (("(", ")", "parentheses"), ("{", "}", "braces"), ("[", "]", "brackets")):
    check(f"raw {label} counts balance", source.count(opening) == source.count(closing))

if zip_args:
    check("ZIP exists", archive.is_file())
    with zipfile.ZipFile(archive) as package:
        names = sorted(package.namelist())
        expected = sorted([
            "gulf-breeze-test-student-fixtures/gulf-breeze-test-student-fixtures.php",
            "gulf-breeze-test-student-fixtures/readme.txt",
        ])
        check("ZIP contains only plugin files", names == expected)
        archived_source = package.read(expected[0])
        check("ZIP source matches screened source", hashlib.sha256(archived_source).hexdigest() == hashlib.sha256(source_bytes).hexdigest())

print(f"RESULT passed={passed} failed={failed}")
sys.exit(1 if failed else 0)
