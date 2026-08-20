<?php
/** Focused disposable exact-stack validation for Gulf Breeze Certificates r2. */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['gb_cert_r2_counts'] = array( 'passed' => 0, 'failed' => 0 );
function gb_cert_r2_check( $name, $condition ) {
	if ( $condition ) {
		$GLOBALS['gb_cert_r2_counts']['passed']++;
		WP_CLI::log( 'PASS ' . $name );
	} else {
		$GLOBALS['gb_cert_r2_counts']['failed']++;
		WP_CLI::warning( 'FAIL ' . $name );
	}
}

function gb_cert_r2_mapping_id( $map ) {
	return substr( hash( 'sha256', implode( ':', array(
		absint( $map['course_id'] ),
		sanitize_key( $map['unit_type'] ),
		absint( $map['unit_number'] ),
		absint( $map['bank_quiz_id'] ),
		absint( $map['second_bank_quiz_id'] ),
	) ) ), 0, 12 );
}

function gb_cert_r2_add_lp_completion( $user_id, $course_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'learnpress_user_items';
	$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM {$table}", ARRAY_A );
	if ( ! $columns ) {
		return false;
	}
	$now = current_time( 'mysql', true );
	$known = array(
		'user_id' => $user_id,
		'item_id' => $course_id,
		'item_type' => 'lp_course',
		'status' => 'finished',
		'graduation' => 'passed',
		'start_time' => $now,
		'end_time' => $now,
		'graduation_time' => $now,
		'update_time' => $now,
		'ref_id' => 0,
		'ref_type' => '',
		'parent_id' => 0,
		'access_level' => 0,
	);
	$data = array();
	foreach ( $columns as $column ) {
		$field = $column['Field'];
		if ( false !== stripos( $column['Extra'], 'auto_increment' ) ) {
			continue;
		}
		if ( array_key_exists( $field, $known ) ) {
			$data[ $field ] = $known[ $field ];
			continue;
		}
		if ( 'YES' === $column['Null'] || null !== $column['Default'] ) {
			continue;
		}
		if ( preg_match( '/int|decimal|float|double|bit/i', $column['Type'] ) ) {
			$data[ $field ] = 0;
		} elseif ( preg_match( '/date|time/i', $column['Type'] ) ) {
			$data[ $field ] = $now;
		} else {
			$data[ $field ] = '';
		}
	}
	return false !== $wpdb->insert( $table, $data );
}

function gb_cert_r2_fixture( $locale, $index ) {
	wp_set_current_user( 1 );
	$course_key = 'es-US' === $locale ? 'adult_es' : 'adult_en';
	$user_id = wp_create_user( 'gb-cert-' . $index, 'validation-password', 'gb-cert-' . $index . '@example.com' );
	wp_update_user( array( 'ID' => $user_id, 'display_name' => 'Taylor Test' ) );
	update_user_meta( $user_id, 'locale', $locale );
	update_user_meta( $user_id, 'gb_preferred_locale', $locale );
	$course_id = wp_insert_post( array(
		'post_type' => 'lp_course',
		'post_status' => 'publish',
		'post_title' => 'Certificate Runtime ' . ( 'es-US' === $locale ? 'Spanish' : 'English' ),
	) );
	update_post_meta( $course_id, '_gb_course_key', $course_key );

	$product = new WC_Product_Simple();
	$product->set_name( 'Certificate Runtime Product ' . $index );
	$product->set_regular_price( '36.00' );
	$product->set_virtual( true );
	$product_id = $product->save();
	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_billing_email( 'gb-cert-' . $index . '@example.com' );
	$order->update_meta_data( '_gb_ep_state', 'paid_enrolled' );
	$order->update_meta_data( '_gb_ep_student_user_id', $user_id );
	$order->update_meta_data( '_gb_ep_course_id', $course_id );
	$order->update_meta_data( '_gb_ep_locale', $locale );
	$order->update_meta_data( '_gb_ep_contract_snapshot_json', wp_json_encode( array(
		'student' => array(
			'legal_name' => 'Taylor Test',
			'dob' => '1990-01-15',
			'gender' => 1 === $index ? 'male' : 'female',
		),
	) ) );
	$order->calculate_totals();
	$order->save();
	$order->payment_complete( 'VALIDATION-' . $index );
	update_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, $order->get_id() );

	$map = array(
		'active' => 1,
		'course_id' => $course_id,
		'unit_type' => 'adult_final',
		'unit_number' => 6,
		'bank_quiz_id' => 1000 + $index,
		'second_bank_quiz_id' => 0,
		'pass_percent' => 70,
	);
	$maps = get_option( 'gbcmg_mappings', array() );
	$maps[] = $map;
	update_option( 'gbcmg_mappings', $maps, false );
	$state = array(
		'passed_at' => '2026-08-20 15:00:00',
		'last_result' => array( 'score_percent' => 88, 'passed' => true ),
	);
	update_user_meta( $user_id, '_gbcmg_state_' . gb_cert_r2_mapping_id( $map ), $state );
	gb_cert_r2_check( $locale . ' LearnPress completion inserted', gb_cert_r2_add_lp_completion( $user_id, $course_id ) );
	return array( $user_id, $course_id, $order->get_id() );
}

gb_cert_r2_check( 'plugin class loaded', class_exists( 'Gulf_Breeze_Certificates' ) );
gb_cert_r2_check( 'plugin version is r2', defined( 'Gulf_Breeze_Certificates::VERSION' ) && '0.1.0-dev-r2' === Gulf_Breeze_Certificates::VERSION );
gb_cert_r2_check( 'schema version installed', '0.3.0' === get_option( 'gb_certificates_schema_version' ) );
gb_cert_r2_check( 'test mode remains enabled', 'test' === Gulf_Breeze_Certificates::MODE );

$under_construction_before = get_option( 'ucp_options' );
update_option( 'gb_certificates_settings', array(
	'provider_number' => 'C1234',
	'school_name' => 'Gulf Breeze Driving School',
	'instructor_first_name' => 'Alex',
	'instructor_last_name' => 'Instructor',
	'instructor_license_number' => 'TEST1234',
	'chief_official_name' => 'Casey Official',
), false );

$mail = array();
add_filter( 'pre_wp_mail', function( $pre, $atts ) use ( &$mail ) {
	$mail[] = array(
		'to' => $atts['to'],
		'subject' => $atts['subject'],
		'message' => $atts['message'],
		'attachment_exists' => ! empty( $atts['attachments'][0] ) && is_readable( $atts['attachments'][0] ),
	);
	return true;
}, 10, 2 );
gb_cert_r2_check( 'WordPress mail interception is active', true === wp_mail( 'mail-probe@example.com', 'Validation probe', 'Validation probe' ) && 1 === count( $mail ) );
$mail = array();

$certificates = Gulf_Breeze_Certificates::instance();
gb_cert_r2_check( 'two mock serials imported', 2 === $certificates->import_mock_serials( 2 ) );
$english = gb_cert_r2_fixture( 'en-US', 1 );
$spanish = gb_cert_r2_fixture( 'es-US', 2 );
$english_row = $certificates->maybe_issue( $english[0], $english[1] );
$spanish_row = $certificates->maybe_issue( $spanish[0], $spanish[1] );
WP_CLI::log( 'INFO certificate email states: ' . ( $english_row['email_status'] ?? 'missing' ) . '/' . ( $spanish_row['email_status'] ?? 'missing' ) . '; recipients: ' . ( $english_row['email_recipient'] ?? 'missing' ) . '/' . ( $spanish_row['email_recipient'] ?? 'missing' ) );

foreach ( array( 'English' => $english_row, 'Spanish' => $spanish_row ) as $label => $row ) {
	gb_cert_r2_check( "$label issuance succeeds", is_array( $row ) && ! empty( $row['id'] ) );
	gb_cert_r2_check( "$label serial is isolated mock eight-digit value", is_array( $row ) && 1 === preg_match( '/^9[0-9]{7}$/', $row['serial_number'] ) );
	gb_cert_r2_check( "$label PDF is preserved", is_array( $row ) && ! empty( $row['pdf_blob_b64'] ) );
	$pdf = is_array( $row ) ? base64_decode( $row['pdf_blob_b64'], true ) : false;
	gb_cert_r2_check( "$label PDF hash matches preserved bytes", false !== $pdf && hash_equals( $row['pdf_sha256'], hash( 'sha256', $pdf ) ) );
	gb_cert_r2_check( "$label template hash is recorded", is_array( $row ) && 64 === strlen( $row['template_sha256'] ) );
	gb_cert_r2_check( "$label report deadline is 15 calendar days", is_array( $row ) && 15 * DAY_IN_SECONDS === strtotime( $row['report_due_utc'] . ' UTC' ) - strtotime( $row['issued_utc'] . ' UTC' ) );
	if ( false !== $pdf ) {
		file_put_contents( '/tmp/gb-cert-' . ( 'English' === $label ? 'en' : 'es' ) . '.pdf', $pdf );
	}
}

gb_cert_r2_check( 'language controls issuance locale', 'en-US' === $english_row['locale'] && 'es-US' === $spanish_row['locale'] );
gb_cert_r2_check( 'serials are unique', $english_row['serial_number'] !== $spanish_row['serial_number'] );
$again = $certificates->maybe_issue( $english[0], $english[1] );
gb_cert_r2_check( 'eligibility is idempotent', is_array( $again ) && $again['id'] === $english_row['id'] );

$certificate_mail = array_values( array_filter( $mail, function( $message ) {
	return false !== strpos( $message['subject'], 'ADEE-1317' );
} ) );
gb_cert_r2_check( 'both localized certificate emails were attempted', 2 === count( $certificate_mail ) );
gb_cert_r2_check( 'English email contains English instructions', isset( $certificate_mail[0] ) && false !== strpos( $certificate_mail[0]['subject'], 'Your Gulf Breeze' ) && false !== strpos( $certificate_mail[0]['message'], 'What to do next' ) && false !== strpos( $certificate_mail[0]['message'], '90 days' ) );
gb_cert_r2_check( 'Spanish email contains Spanish instructions', isset( $certificate_mail[1] ) && false !== strpos( $certificate_mail[1]['subject'], 'Su certificado' ) && false !== strpos( $certificate_mail[1]['message'], 'Qué hacer después' ) && false !== strpos( $certificate_mail[1]['message'], '90 días' ) );
gb_cert_r2_check( 'certificate email attachments exist at send time', isset( $certificate_mail[0], $certificate_mail[1] ) && $certificate_mail[0]['attachment_exists'] && $certificate_mail[1]['attachment_exists'] );

$report_method = new ReflectionMethod( $certificates, 'report_row' );
$report = $report_method->invoke( $certificates, $english_row );
gb_cert_r2_check( 'TDLR row has exactly 21 values', 21 === count( $report ) );
gb_cert_r2_check( 'TDLR Adult values are exact', 'ADE' === $report[0] && 'ADEE' === $report[1] && 'ONLINE ADULT' === $report[4] && '6' === $report[10] );
gb_cert_r2_check( 'TDLR non-applicable Adult fields are blank', '' === $report[12] && '' === $report[13] && '' === $report[14] && '' === $report[19] && '' === $report[20] );

wp_set_current_user( $english[0] );
ob_start();
$certificates->account_certificates();
$english_account = ob_get_clean();
wp_set_current_user( $spanish[0] );
ob_start();
$certificates->account_certificates();
$spanish_account = ob_get_clean();
gb_cert_r2_check( 'student account exposes English certificate download', false !== strpos( $english_account, 'My Certificates' ) && false !== strpos( $english_account, 'Download test certificate' ) );
gb_cert_r2_check( 'student account exposes Spanish certificate download', false !== strpos( $spanish_account, 'Mis certificados' ) && false !== strpos( $spanish_account, 'Descargar certificado de prueba' ) );

wp_set_current_user( 1 );
ob_start();
$certificates->admin_page();
$admin = ob_get_clean();
gb_cert_r2_check( 'administrator page renders normally', false !== strpos( $admin, 'Gulf Breeze Certificates' ) && false !== strpos( $admin, 'TEST MODE' ) && false !== strpos( $admin, 'TDLR reporting identity' ) );
gb_cert_r2_check( 'Under Construction configuration is unchanged', $under_construction_before === get_option( 'ucp_options' ) );

$passed = $GLOBALS['gb_cert_r2_counts']['passed'];
$failed = $GLOBALS['gb_cert_r2_counts']['failed'];
WP_CLI::log( "RESULT {$passed} passed, {$failed} failed" );
if ( $failed ) {
	WP_CLI::error( 'Focused Certificates r2 exact-stack validation failed.' );
}
