<?php
/** Focused disposable PHP 8.4 exact-stack validation for Test Student Fixtures r1. */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['gb_fixture_r1_counts'] = array( 'passed' => 0, 'failed' => 0 );
function gb_fixture_r1_check( $name, $condition ) {
	if ( $condition ) {
		$GLOBALS['gb_fixture_r1_counts']['passed']++;
		WP_CLI::log( 'PASS ' . $name );
	} else {
		$GLOBALS['gb_fixture_r1_counts']['failed']++;
		WP_CLI::warning( 'FAIL ' . $name );
	}
}

function gb_fixture_r1_course( $course_key, &$created_courses ) {
	$ids = get_posts( array(
		'post_type' => 'lp_course',
		'post_status' => 'publish',
		'posts_per_page' => 1,
		'fields' => 'ids',
		'meta_key' => '_gb_course_key',
		'meta_value' => $course_key,
	) );
	if ( ! empty( $ids ) ) {
		return absint( $ids[0] );
	}
	$course_id = wp_insert_post( array(
		'post_type' => 'lp_course',
		'post_status' => 'publish',
		'post_title' => 'Fixture Runtime ' . ( 'adult_es' === $course_key ? 'Spanish' : 'English' ),
	) );
	if ( $course_id && ! is_wp_error( $course_id ) ) {
		update_post_meta( $course_id, '_gb_course_key', $course_key );
		$created_courses[] = absint( $course_id );
		return absint( $course_id );
	}
	return 0;
}

wp_set_current_user( 1 );
gb_fixture_r1_check( 'fixture plugin class loaded', class_exists( 'Gulf_Breeze_Test_Student_Fixtures' ) );
gb_fixture_r1_check( 'fixture plugin version is r1', defined( 'Gulf_Breeze_Test_Student_Fixtures::VERSION' ) && '0.1.0-dev-r1' === Gulf_Breeze_Test_Student_Fixtures::VERSION );
gb_fixture_r1_check( 'administrator context is active', current_user_can( 'manage_options' ) );
gb_fixture_r1_check( 'certificate plugin remains in test mode', class_exists( 'Gulf_Breeze_Certificates' ) && 'test' === Gulf_Breeze_Certificates::MODE );

$under_construction_before = get_option( 'ucp_options' );
$registry_before = get_option( 'gb_course_registry' );
$maps_before = get_option( 'gbcmg_mappings', array() );
$certificate_settings_before = get_option( 'gb_certificates_settings', array() );
$created_courses = array();
$english_course_id = gb_fixture_r1_course( 'adult_en', $created_courses );
$spanish_course_id = gb_fixture_r1_course( 'adult_es', $created_courses );
$maps = is_array( $maps_before ) ? $maps_before : array();
foreach ( array( $english_course_id => 910001, $spanish_course_id => 910002 ) as $course_id => $bank_id ) {
	$found = false;
	foreach ( $maps as $map ) {
		if ( ! empty( $map['active'] ) && 'adult_final' === sanitize_key( $map['unit_type'] ?? '' ) && absint( $map['course_id'] ?? 0 ) === absint( $course_id ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		$maps[] = array( 'active' => 1, 'course_id' => $course_id, 'unit_type' => 'adult_final', 'unit_number' => 6, 'bank_quiz_id' => $bank_id, 'second_bank_quiz_id' => 0, 'pass_percent' => 70 );
	}
}
update_option( 'gbcmg_mappings', $maps, false );
update_option( 'gb_certificates_settings', array(
	'provider_number' => 'C-TEST',
	'school_name' => 'Gulf Breeze Driving School',
	'instructor_first_name' => 'Test',
	'instructor_last_name' => 'Instructor',
	'instructor_license_number' => '0101',
	'chief_official_name' => 'Test Official',
), false );
$fixture = Gulf_Breeze_Test_Student_Fixtures::instance();
$preflight = new ReflectionMethod( $fixture, 'preflight_errors' );
$preflight_errors = $preflight->invoke( $fixture );
gb_fixture_r1_check( 'exact-stack preflight passes', empty( $preflight_errors ) );

$mail = array();
add_filter( 'pre_wp_mail', function( $pre, $atts ) use ( &$mail ) {
	$mail[] = $atts;
	return true;
}, 10, 2 );

global $wpdb;
$issuances = $wpdb->prefix . 'gb_certificate_issuances';
$serials = $wpdb->prefix . 'gb_certificate_serials';
$issued_before = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$issuances}" ) );
$certificates = Gulf_Breeze_Certificates::instance();
$certificates->import_mock_serials( 2 );
$available_before = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$serials} WHERE certificate_type='ADEE-1317' AND status='available'" ) );
gb_fixture_r1_check( 'at least two mock certificate serials are available', $available_before >= 2 );

$create = new ReflectionMethod( $fixture, 'create_batch' );
$result = $create->invoke( $fixture, array( 'english' => '', 'spanish' => '' ) );
$rows = is_array( $result ) && isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
gb_fixture_r1_check( 'fixture batch completes without error', is_array( $result ) && empty( $result['error'] ) );
gb_fixture_r1_check( 'exactly six fixtures are created', 6 === count( $rows ) );

$user_ids = array_map( 'absint', wp_list_pluck( $rows, 'user_id' ) );
$order_ids = array_map( 'absint', wp_list_pluck( $rows, 'order_id' ) );
$eligible_ids = array();
$blocked_ids = array();
foreach ( $rows as $row ) {
	$user_id = absint( $row['user_id'] );
	$order = wc_get_order( absint( $row['order_id'] ) );
	$user = get_user_by( 'id', $user_id );
	$is_eligible = 'Certificate eligible' === $row['expected'];
	if ( $is_eligible ) {
		$eligible_ids[] = $user_id;
	} else {
		$blocked_ids[] = $user_id;
	}
	gb_fixture_r1_check( 'fixture user #' . $user_id . ' is non-administrator and disposable', $user && ! user_can( $user, 'manage_options' ) && 'yes' === get_user_meta( $user_id, '_gb_ep_test_account', true ) && 'yes' === get_user_meta( $user_id, '_gb_test_fixture', true ) );
	gb_fixture_r1_check( 'fixture order #' . absint( $row['order_id'] ) . ' is zero-dollar and synthetic', $order && 'completed' === $order->get_status() && 0.0 === (float) $order->get_total() && '' === $order->get_transaction_id() && 'yes' === $order->get_meta( '_gb_test_fixture' ) );
	gb_fixture_r1_check( 'fixture order identity is linked', $order && $user_id === absint( $order->get_customer_id() ) && $user_id === absint( $order->get_meta( '_gb_ep_student_user_id' ) ) && absint( $row['course_id'] ) === absint( $order->get_meta( '_gb_ep_course_id' ) ) );
}
gb_fixture_r1_check( 'exactly two fixtures are eligible', 2 === count( $eligible_ids ) );
gb_fixture_r1_check( 'exactly four fixtures are negative controls', 4 === count( $blocked_ids ) );

$before_reconcile_fixture_issuances = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$issuances} WHERE user_id IN (" . implode( ',', $user_ids ) . ')' ) );
gb_fixture_r1_check( 'seeding does not directly issue certificates', 0 === $before_reconcile_fixture_issuances );

$certificates->reconcile_eligible_completions();
$eligible_issuances = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$issuances} WHERE user_id IN (" . implode( ',', $eligible_ids ) . ')' ) );
$blocked_issuances = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$issuances} WHERE user_id IN (" . implode( ',', $blocked_ids ) . ')' ) );
gb_fixture_r1_check( 'normal reconciliation issues exactly two eligible certificates', 2 === $eligible_issuances );
gb_fixture_r1_check( 'all four negative controls remain unissued', 0 === $blocked_issuances );
gb_fixture_r1_check( 'exactly two mock serials are allocated', $available_before - 2 === absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$serials} WHERE certificate_type='ADEE-1317' AND status='available'" ) ) );

$fixture_mail = array_values( array_filter( $mail, function( $message ) {
	return isset( $message['to'] ) && false !== strpos( is_array( $message['to'] ) ? implode( ',', $message['to'] ) : $message['to'], '@test.invalid' );
} ) );
$mail_text = wp_json_encode( $fixture_mail );
gb_fixture_r1_check( 'two localized certificate emails are attempted', 2 === count( $fixture_mail ) );
gb_fixture_r1_check( 'English next-step instructions are present', false !== strpos( $mail_text, 'What to do next' ) );
gb_fixture_r1_check( 'Spanish next-step instructions are present', false !== strpos( $mail_text, 'Qué hacer después' ) );

$certificates->reconcile_eligible_completions();
gb_fixture_r1_check( 'second reconciliation is idempotent', 2 === absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$issuances} WHERE user_id IN (" . implode( ',', $eligible_ids ) . ')' ) ) );
gb_fixture_r1_check( 'Under Construction configuration is unchanged', $under_construction_before === get_option( 'ucp_options' ) );

// Restore the disposable exact-stack database to its pre-test state.
$fixture_issuance_rows = $wpdb->get_results( "SELECT id,serial_number FROM {$issuances} WHERE user_id IN (" . implode( ',', $user_ids ) . ')', ARRAY_A );
foreach ( $fixture_issuance_rows as $issuance ) {
	$wpdb->update( $serials, array( 'status' => 'available', 'issuance_id' => 0, 'issued_to_user' => 0, 'course_id' => 0, 'issued_utc' => null ), array( 'serial_number' => $issuance['serial_number'], 'certificate_type' => 'ADEE-1317' ) );
}
$wpdb->query( "DELETE FROM {$issuances} WHERE user_id IN (" . implode( ',', $user_ids ) . ')' );
foreach ( $order_ids as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->delete( true );
	}
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $user_ids as $user_id ) {
	wp_delete_user( $user_id );
}
update_option( 'gb_course_registry', $registry_before, false );
update_option( 'gbcmg_mappings', $maps_before, false );
update_option( 'gb_certificates_settings', $certificate_settings_before, false );
foreach ( $created_courses as $course_id ) {
	wp_delete_post( $course_id, true );
}
gb_fixture_r1_check( 'fixture certificate rows are cleaned up', $issued_before === absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$issuances}" ) ) );
gb_fixture_r1_check( 'fixture mock inventory is restored', $available_before === absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$serials} WHERE certificate_type='ADEE-1317' AND status='available'" ) ) );
gb_fixture_r1_check( 'fixture users are removed from disposable stack', 0 === count( array_filter( $user_ids, function( $user_id ) { return (bool) get_user_by( 'id', $user_id ); } ) ) );
gb_fixture_r1_check( 'Under Construction remains unchanged after cleanup', $under_construction_before === get_option( 'ucp_options' ) );

$passed = $GLOBALS['gb_fixture_r1_counts']['passed'];
$failed = $GLOBALS['gb_fixture_r1_counts']['failed'];
WP_CLI::log( "RESULT {$passed} passed, {$failed} failed" );
if ( $failed ) {
	WP_CLI::error( 'Focused Test Student Fixtures r1 exact-stack validation failed.' );
}
