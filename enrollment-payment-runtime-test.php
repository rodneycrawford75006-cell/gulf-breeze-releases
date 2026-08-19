<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

function gb_ep_assert( $condition, $message ) {
	if ( ! $condition ) { throw new Exception( $message ); }
}

gb_ep_assert( class_exists( 'Gulf_Breeze_Enrollment_Payment' ), 'Enrollment plugin class missing.' );
gb_ep_assert( defined( 'WC_VERSION' ), 'WooCommerce missing.' );
gb_ep_assert( function_exists( 'learn_press_get_user' ), 'LearnPress missing.' );
gb_ep_assert( function_exists( 'gb_core_provider_profile' ), 'Core provider-profile API missing.' );
gb_ep_assert( 'sandbox' === get_option( 'gb_ep_environment' ), 'Environment is not Sandbox.' );
wp_set_current_user( 1 );
gb_ep_assert( current_user_can( 'manage_options' ), 'Disposable administrator context missing.' );
add_filter( 'pre_wp_mail', '__return_true' );
$enrollment_plugin = Gulf_Breeze_Enrollment_Payment::instance();

global $wpdb;
$contracts = $wpdb->prefix . 'gb_ep_contracts';
$events = $wpdb->prefix . 'gb_ep_events';
gb_ep_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $contracts ) ) === $contracts, 'Contracts table missing.' );
gb_ep_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) === $events, 'Events table missing.' );

$ucp_before = get_option( 'ucp_options' );
$catalog = Gulf_Breeze_Catalog_Cart::instance();
gb_ep_assert( false === has_filter( 'woocommerce_available_payment_gateways', array( $catalog, 'disable_payment_gateways' ) ), 'Catalog payment lock was not handed off.' );
gb_ep_assert( false === has_action( 'template_redirect', array( $catalog, 'block_checkout_stage' ) ), 'Catalog checkout redirect lock remains active.' );

$course_id = wp_insert_post( array( 'post_type' => 'lp_course', 'post_status' => 'publish', 'post_title' => 'Enrollment Runtime Course' ) );
gb_ep_assert( $course_id && ! is_wp_error( $course_id ), 'Could not create disposable LearnPress course.' );
$product = new WC_Product_Simple();
$product->set_name( 'Enrollment Runtime Course — English' );
$product->set_regular_price( '36.00' );
$product->set_price( '36.00' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product_id = $product->save();
update_post_meta( $product_id, '_gb_catalog_key', 'adult_6h_en' );
update_post_meta( $product_id, '_gb_enrollment_locale', 'en-US' );
update_post_meta( $product_id, '_gb_learnpress_course_id', $course_id );

$profile = gb_core_provider_profile();
$student_email = 'enrollment-runtime@example.invalid';
$snapshot = array(
	'schema_version' => '1.0', 'contract_version' => 'DEV-0.1.0', 'locale' => 'en-US',
	'provider_profile' => $profile, 'provider_profile_hash' => $profile['profile_hash'],
	'catalog' => array( 'catalog_key' => 'adult_6h_en', 'product_id' => $product_id, 'course_id' => $course_id, 'course_name' => $product->get_name(), 'price' => '36.00', 'currency' => get_woocommerce_currency() ),
	'student' => array( 'legal_name' => 'Enrollment Runtime Student', 'email' => $student_email, 'dob' => '1990-01-01' ),
	'purchaser' => array( 'legal_name' => 'Enrollment Runtime Purchaser', 'email' => 'purchaser-runtime@example.invalid', 'is_student' => false ),
	'signatures' => array( 'student' => 'Enrollment Runtime Student', 'guardian' => '', 'provider' => 'Rodney Crawford' ),
	'terms_html' => '<p>Disposable runtime agreement.</p>',
	'evidence' => array( 'signed_at_utc' => gmdate( 'c' ), 'ip_hash' => hash( 'sha256', 'runtime-ip' ), 'user_agent_hash' => hash( 'sha256', 'runtime-agent' ) ),
);
$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$hash = hash( 'sha256', $json );
$wpdb->insert( $contracts, array( 'token_hash' => hash( 'sha256', 'runtime-token-1' ), 'status' => 'order_created_unpaid', 'locale' => 'en-US', 'product_id' => $product_id, 'course_id' => $course_id, 'order_id' => 0, 'student_email_hash' => hash( 'sha256', $student_email ), 'snapshot' => $json, 'snapshot_hash' => $hash, 'signed_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
$contract_id = (int) $wpdb->insert_id;

$order = wc_create_order();
$order->add_product( $product, 1 );
$order->calculate_totals();
$order->set_payment_method( 'ppcp-gateway' );
$order->set_transaction_id( 'SANDBOX-CAPTURE-RUNTIME-001' );
$order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_CONTRACT_ID, $contract_id );
$order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_COURSE_ID, $course_id );
$order->update_meta_data( '_gb_ep_snapshot_hash', $hash );
$order->set_status( 'pending' );
$order->save();
$wpdb->update( $contracts, array( 'order_id' => $order->get_id() ), array( 'id' => $contract_id ) );

Gulf_Breeze_Enrollment_Payment::instance()->process_verified_payment( $order->get_id() );
$order = new WC_Order( $order->get_id() );
gb_ep_assert( 'payment_verification_failed' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_STATE ), 'Unpaid order did not fail closed.' );
gb_ep_assert( ! get_user_by( 'email', $student_email ), 'Unpaid order created a student account.' );

remove_action( 'woocommerce_order_status_processing', array( $enrollment_plugin, 'process_verified_payment' ), 20 );
$order->set_date_paid( time() );
$order->set_status( 'processing' );
$order->save();
add_action( 'woocommerce_order_status_processing', array( $enrollment_plugin, 'process_verified_payment' ), 20 );
$enrollment_plugin->process_verified_payment( $order->get_id() );
wp_cache_flush();
$order = wc_get_order( $order->get_id() );
gb_ep_assert( 'yes' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_PROCESSED ), 'Verified payment was not processed.' );
gb_ep_assert( 'paid_enrolled_mfa_required' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_STATE ), 'Paid enrollment state incorrect.' );
$user = get_user_by( 'email', $student_email );
gb_ep_assert( $user, 'Paid order did not create/link account.' );
$user_id = (int) $user->ID;
gb_ep_assert( $user_id === (int) $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID ), 'Order/student link missing.' );
gb_ep_assert( 'en-US' === get_user_meta( $user_id, 'gb_preferred_locale', true ), 'Locale did not persist.' );
gb_ep_assert( 'active' === get_user_meta( $user_id, '_gb_ep_account_state', true ), 'Account was not activated.' );

$success_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE order_id=%d AND event_type='payment_and_enrollment' AND result='success'", $order->get_id() ) );
Gulf_Breeze_Enrollment_Payment::instance()->process_verified_payment( $order->get_id() );
$success_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE order_id=%d AND event_type='payment_and_enrollment' AND result='success'", $order->get_id() ) );
gb_ep_assert( 1 === $success_before && $success_before === $success_after, 'Duplicate callback was not idempotent.' );

$reflection = new ReflectionClass( 'Gulf_Breeze_Enrollment_Payment' );
$secret_method = $reflection->getMethod( 'base32_secret' ); $secret_method->setAccessible( true );
$totp_method = $reflection->getMethod( 'totp' ); $totp_method->setAccessible( true );
$verify_method = $reflection->getMethod( 'verify_totp' ); $verify_method->setAccessible( true );
$encrypt_method = $reflection->getMethod( 'encrypt' ); $encrypt_method->setAccessible( true );
$decrypt_method = $reflection->getMethod( 'decrypt' ); $decrypt_method->setAccessible( true );
$secret = $secret_method->invoke( Gulf_Breeze_Enrollment_Payment::instance() );
$encrypted = $encrypt_method->invoke( Gulf_Breeze_Enrollment_Payment::instance(), $secret );
gb_ep_assert( $secret === $decrypt_method->invoke( Gulf_Breeze_Enrollment_Payment::instance(), $encrypted ), 'MFA secret encryption round trip failed.' );
$code = $totp_method->invoke( Gulf_Breeze_Enrollment_Payment::instance(), $secret, time() );
gb_ep_assert( $verify_method->invoke( Gulf_Breeze_Enrollment_Payment::instance(), $secret, $code ), 'TOTP verification failed.' );
gb_ep_assert( ! $verify_method->invoke( Gulf_Breeze_Enrollment_Payment::instance(), $secret, '000000' ) || '000000' === $code, 'Invalid TOTP unexpectedly passed.' );

$revoke = $reflection->getMethod( 'revoke_order_access' ); $revoke->setAccessible( true );
$revoke->invoke( Gulf_Breeze_Enrollment_Payment::instance(), $order, 'full_refund', 101 );
$order = wc_get_order( $order->get_id() );
gb_ep_assert( 'yes' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_REVOKED ), 'Refund access revocation marker missing.' );
gb_ep_assert( in_array( $course_id, array_map( 'intval', (array) get_user_meta( $user_id, Gulf_Breeze_Enrollment_Payment::USER_REVOKED_COURSES, true ) ), true ), 'Refunded course was not revoked.' );
gb_ep_assert( 'dormant' === get_user_meta( $user_id, '_gb_ep_account_state', true ), 'No-course account was not made dormant.' );

$snapshot['evidence']['signed_at_utc'] = gmdate( 'c' );
$json2 = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $hash2 = hash( 'sha256', $json2 );
$wpdb->insert( $contracts, array( 'token_hash' => hash( 'sha256', 'runtime-token-2' ), 'status' => 'order_created_unpaid', 'locale' => 'en-US', 'product_id' => $product_id, 'course_id' => $course_id, 'order_id' => 0, 'student_email_hash' => hash( 'sha256', $student_email ), 'snapshot' => $json2, 'snapshot_hash' => $hash2, 'signed_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
$contract2 = (int) $wpdb->insert_id;
$order2 = wc_create_order(); $order2->add_product( $product, 1 ); $order2->calculate_totals(); $order2->set_payment_method( 'ppcp-gateway' ); $order2->set_transaction_id( 'SANDBOX-CAPTURE-RUNTIME-002' ); $order2->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_CONTRACT_ID, $contract2 ); $order2->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_COURSE_ID, $course_id ); $order2->update_meta_data( '_gb_ep_snapshot_hash', $hash2 );
remove_action( 'woocommerce_order_status_processing', array( $enrollment_plugin, 'process_verified_payment' ), 20 );
$order2->set_date_paid( time() ); $order2->set_status( 'processing' ); $order2->save();
add_action( 'woocommerce_order_status_processing', array( $enrollment_plugin, 'process_verified_payment' ), 20 );
$wpdb->update( $contracts, array( 'order_id' => $order2->get_id() ), array( 'id' => $contract2 ) );
$enrollment_plugin->process_verified_payment( $order2->get_id() ); wp_cache_flush(); $order2 = wc_get_order( $order2->get_id() );
gb_ep_assert( $user_id === (int) $order2->get_meta( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID ), 'Repurchase created or linked the wrong account.' );
gb_ep_assert( 'active' === get_user_meta( $user_id, '_gb_ep_account_state', true ), 'Repurchase did not reactivate account.' );
gb_ep_assert( ! in_array( $course_id, array_map( 'intval', (array) get_user_meta( $user_id, Gulf_Breeze_Enrollment_Payment::USER_REVOKED_COURSES, true ) ), true ), 'Repurchase did not clear course revocation.' );

$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
$blocked = Gulf_Breeze_Enrollment_Payment::instance()->block_store_api_checkout( null, rest_get_server(), $request );
gb_ep_assert( is_wp_error( $blocked ) && 403 === (int) $blocked->get_error_data()['status'], 'Store API checkout bypass was not blocked.' );
gb_ep_assert( $ucp_before === get_option( 'ucp_options' ), 'Under Construction configuration changed.' );

echo "PASS Enrollment & Payment 0.1.0 runtime regression\n";
