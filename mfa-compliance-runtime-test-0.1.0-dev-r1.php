<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function gb_mfa_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL - {$message}\n" );
		exit( 1 );
	}
	echo "PASS - {$message}\n";
}

global $wpdb;

$owner = get_user_by( 'login', 'admin' );
gb_mfa_test_assert( $owner instanceof WP_User, 'fixture owner exists' );

wp_set_current_user( $owner->ID );
delete_option( Gulf_Breeze_MFA_Compliance::OPTION_OWNER_ID );
update_option( 'ucp_options', array( 'status' => 1, 'end_date_toggle' => 0, 'whitelisted_roles' => array( 'administrator' ) ) );
Gulf_Breeze_MFA_Compliance::activate();

$plugin = Gulf_Breeze_MFA_Compliance::instance();
gb_mfa_test_assert( Gulf_Breeze_MFA_Compliance::VERSION === '0.1.0-dev-r1', 'expected plugin version active' );
gb_mfa_test_assert( (int) get_option( Gulf_Breeze_MFA_Compliance::OPTION_OWNER_ID ) === (int) $owner->ID, 'activating owner ID is pinned' );
gb_mfa_test_assert( gb_mfa_is_owner_immune( $owner->ID ), 'pinned owner is completely MFA-immune' );
gb_mfa_test_assert( gb_mfa_is_verified( $owner->ID ), 'owner is treated as verified without MFA metadata' );
gb_mfa_test_assert( ! get_user_meta( $owner->ID, Gulf_Breeze_MFA_Compliance::META_SECRET, true ), 'owner exemption does not create an MFA secret' );

$settings = get_option( Gulf_Breeze_MFA_Compliance::OPTION_SETTINGS );
gb_mfa_test_assert( 60 === (int) $settings['verify_ttl_minutes'], 'normal verification defaults to 60 minutes' );
gb_mfa_test_assert( 30 === (int) $settings['checkpoint_minutes'], 'exam and certificate freshness defaults to 30 minutes' );
gb_mfa_test_assert( 6 === (int) $settings['max_attempts'] && 30 === (int) $settings['lock_minutes'], 'lockout defaults are enforced' );

foreach ( array(
	'mfa-enroll' => '[gb_mfa_enroll]',
	'mfa-verify' => '[gb_mfa_verify]',
	'mfa-recovery' => '[gb_mfa_recovery]',
) as $slug => $shortcode ) {
	$page = get_page_by_path( $slug );
	gb_mfa_test_assert( $page instanceof WP_Post && has_shortcode( $page->post_content, trim( $shortcode, '[]' ) ), "{$slug} page is present" );
}

$table = $wpdb->prefix . 'gb_mfa_events';
gb_mfa_test_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table, 'MFA audit table exists' );
gb_mfa_test_assert( 1 === (int) get_option( 'ucp_options' )['status'], 'Under Construction remains enabled' );

$other_admin_id = wp_create_user( 'validation-admin-2', 'validation-only-password', 'validation-admin-2@example.invalid' );
gb_mfa_test_assert( ! is_wp_error( $other_admin_id ), 'secondary administrator fixture created' );
( new WP_User( $other_admin_id ) )->set_role( 'administrator' );
gb_mfa_test_assert( ! gb_mfa_is_owner_immune( $other_admin_id ), 'immunity is limited to the exact pinned owner account' );

$student_id = wp_create_user( 'validation-student', 'validation-only-password', 'validation-student@example.invalid' );
gb_mfa_test_assert( ! is_wp_error( $student_id ), 'student fixture created' );
update_user_meta( $student_id, 'gb_mfa_required', 1 );
foreach ( array(
	'gb_student_legal_name' => 'Validation Student',
	'gb_student_dob' => '2000-01-01',
	'gb_student_phone' => '9725550100',
	'gb_student_address1' => '100 Validation Way',
	'gb_student_city' => 'Carrollton',
	'gb_student_state' => 'TX',
	'gb_student_zip' => '75007',
) as $key => $value ) {
	update_user_meta( $student_id, $key, $value );
}
wp_set_current_user( $student_id );

$redirect = $plugin->guard_login_redirect( home_url( '/my-account/' ), home_url( '/course/' ), get_user_by( 'id', $student_id ) );
gb_mfa_test_assert( false !== strpos( $redirect, '/mfa-enroll/' ), 'unenrolled student is directed to MFA enrollment' );

$english = do_shortcode( '[gb_mfa_enroll]' );
gb_mfa_test_assert( false !== strpos( $english, 'Protect your student record' ), 'English enrollment explains the purpose' );
gb_mfa_test_assert( false !== strpos( $english, 'I am using this phone' ) && false !== strpos( $english, 'Copy setup key' ), 'same-phone setup path is available' );
gb_mfa_test_assert( false !== strpos( $english, 'I can scan with another device' ), 'QR scanning path is available' );
gb_mfa_test_assert( false !== strpos( $english, 'Current six-digit code' ), 'enrollment gives a directed verification step' );
gb_mfa_test_assert( false === stripos( $english, $owner->user_email ) && false === stripos( $english, $owner->display_name ), 'student enrollment exposes no owner information' );

update_user_meta( $student_id, Gulf_Breeze_MFA_Compliance::META_LANGUAGE, 'es' );
$spanish = do_shortcode( '[gb_mfa_enroll]' );
gb_mfa_test_assert( false !== strpos( $spanish, 'Protege tu expediente de estudiante' ), 'Spanish enrollment copy is available' );
gb_mfa_test_assert( false !== strpos( $spanish, 'Estoy usando este teléfono' ) && false !== strpos( $spanish, 'Copiar clave de configuración' ), 'Spanish same-phone instructions are available' );

$recovery = do_shortcode( '[gb_mfa_recovery]' );
gb_mfa_test_assert( false !== strpos( $recovery, 'Código de recuperación' ) && false !== strpos( $recovery, 'Restaurar acceso' ), 'Spanish recovery controls are available' );

$request = new WP_REST_Request( 'POST', '/gulf-breeze/v1/seat/heartbeat' );
$blocked = $plugin->guard_rest( null, rest_get_server(), $request );
gb_mfa_test_assert( is_wp_error( $blocked ) && 'gb_mfa_required' === $blocked->get_error_code(), 'protected REST route blocks an unverified student' );

wp_set_current_user( $owner->ID );
$owner_result = $plugin->guard_rest( null, rest_get_server(), $request );
gb_mfa_test_assert( null === $owner_result, 'protected REST route bypasses the pinned owner' );

$quiz_id = wp_insert_post( array( 'post_type' => 'lp_quiz', 'post_status' => 'publish', 'post_title' => 'Validation Final' ) );
update_post_meta( $quiz_id, '_gb_assessment_key', 'adult_en_final' );
delete_option( 'gb_mfa_known_checkpoint_version' );
$plugin->mark_known_exam_checkpoints();
gb_mfa_test_assert( 'final_exam' === get_post_meta( $quiz_id, '_gb_mfa_checkpoint', true ), 'known adult final is marked as a fresh MFA checkpoint' );

wp_set_current_user( $student_id );
$plugin->guard_rest( null, rest_get_server(), $request );
$logged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND event_type='rest_access_blocked'", $student_id ) );
gb_mfa_test_assert( $logged >= 1, 'blocked access is recorded in the compliance audit' );

echo "PASS - MFA exact-stack runtime validation complete\n";
