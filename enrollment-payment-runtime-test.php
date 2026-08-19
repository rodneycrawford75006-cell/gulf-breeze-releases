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
$checkout_page_id = (int) wc_get_page_id( 'checkout' );
gb_ep_assert( $checkout_page_id > 0, 'WooCommerce Checkout page is missing.' );
gb_ep_assert( has_shortcode( (string) get_post_field( 'post_content', $checkout_page_id ), 'woocommerce_checkout' ), 'Checkout Block was not migrated to classic checkout.' );
gb_ep_assert( ! has_block( 'woocommerce/checkout', (string) get_post_field( 'post_content', $checkout_page_id ) ), 'Checkout Block remains active after migration.' );
gb_ep_assert( 'classic-r6:' . $checkout_page_id === get_option( Gulf_Breeze_Enrollment_Payment::CHECKOUT_MODE_OPTION ), 'Classic checkout mode marker is missing.' );
$checkout_content_before = (string) get_post_field( 'post_content', $checkout_page_id );
gb_ep_assert( Gulf_Breeze_Enrollment_Payment::enforce_classic_checkout_page(), 'Classic checkout enforcement did not remain active.' );
gb_ep_assert( $checkout_content_before === (string) get_post_field( 'post_content', $checkout_page_id ), 'Classic checkout enforcement was not idempotent.' );
wp_update_post( array( 'ID' => $checkout_page_id, 'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' ) );
gb_ep_assert( has_block( 'woocommerce/checkout', (string) get_post_field( 'post_content', $checkout_page_id ) ), 'Disposable Checkout Block fixture was not installed.' );
gb_ep_assert( Gulf_Breeze_Enrollment_Payment::enforce_classic_checkout_page(), 'Checkout Block fixture was not migrated.' );
gb_ep_assert( '[woocommerce_checkout]' === trim( (string) get_post_field( 'post_content', $checkout_page_id ) ), 'Checkout Block did not become the classic shortcode.' );
$contract_page_id = (int) get_option( Gulf_Breeze_Enrollment_Payment::CONTRACT_PAGE_OPTION );
$mfa_page_id = (int) get_option( Gulf_Breeze_Enrollment_Payment::MFA_PAGE_OPTION );
gb_ep_assert( $contract_page_id > 0 && 'publish' === get_post_status( $contract_page_id ), 'Enrollment Agreement page was not provisioned.' );
gb_ep_assert( has_shortcode( (string) get_post_field( 'post_content', $contract_page_id ), 'gb_enrollment_contract' ), 'Enrollment Agreement shortcode missing.' );
gb_ep_assert( 'enrollment-agreement' === get_post_field( 'post_name', $contract_page_id ), 'Enrollment Agreement slug incorrect.' );
gb_ep_assert( $mfa_page_id > 0 && 'publish' === get_post_status( $mfa_page_id ), 'MFA page was not provisioned.' );
gb_ep_assert( has_shortcode( (string) get_post_field( 'post_content', $mfa_page_id ), 'gb_mfa_setup' ), 'MFA shortcode missing.' );
gb_ep_assert( 'mfa-setup' === get_post_field( 'post_name', $mfa_page_id ), 'MFA page slug incorrect.' );
Gulf_Breeze_Enrollment_Payment::activate();
gb_ep_assert( $contract_page_id === (int) get_option( Gulf_Breeze_Enrollment_Payment::CONTRACT_PAGE_OPTION ), 'Repeated activation duplicated the Enrollment Agreement page.' );
gb_ep_assert( $mfa_page_id === (int) get_option( Gulf_Breeze_Enrollment_Payment::MFA_PAGE_OPTION ), 'Repeated activation duplicated the MFA page.' );

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
WC()->cart->empty_cart();
gb_ep_assert( WC()->cart->add_to_cart( $product_id, 1 ), 'Could not prepare disposable mapped cart.' );
$paypal_locations_fixture = array( 'product', 'cart', 'checkout', 'mini-cart', 'checkout-block-express', 'cart-block' );
if ( ! function_exists( 'set_current_screen' ) ) { require_once ABSPATH . 'wp-admin/includes/screen.php'; }
$screen_before = $GLOBALS['current_screen'] ?? null;
set_current_screen( 'plugins' );
gb_ep_assert( is_admin(), 'Disposable administrator request context was not established.' );
$admin_paypal_locations = apply_filters( 'woocommerce_paypal_payments_selected_button_locations', $paypal_locations_fixture, 'locations' );
gb_ep_assert( $paypal_locations_fixture === $admin_paypal_locations, 'Administrator bootstrap inspected or changed enrollment cart placement.' );
$GLOBALS['current_screen'] = $screen_before;
$checkout_express = $enrollment_plugin->remove_express_checkout_blocks( '<div>express</div>', array( 'blockName' => 'woocommerce/checkout-express-payment-block' ) );
$cart_express = $enrollment_plugin->remove_express_checkout_blocks( '<div>express</div>', array( 'blockName' => 'woocommerce/cart-express-payment-block' ) );
$ordinary_block = $enrollment_plugin->remove_express_checkout_blocks( '<div>billing</div>', array( 'blockName' => 'woocommerce/checkout-billing-address-block' ) );
gb_ep_assert( '' === $checkout_express && '' === $cart_express, 'Express checkout block was not suppressed for enrollment.' );
gb_ep_assert( '<div>billing</div>' === $ordinary_block, 'Non-express checkout content was changed.' );
$paypal_locations = apply_filters( 'woocommerce_paypal_payments_selected_button_locations', $paypal_locations_fixture, 'locations' );
gb_ep_assert( array( 'checkout' ) === $paypal_locations, 'PayPal smart buttons were not limited to Classic Checkout for enrollment.' );
$raw_checkout_url = wc_get_page_permalink( 'checkout' );
gb_ep_assert( $raw_checkout_url === wc_get_checkout_url(), 'WooCommerce native checkout URL was changed.' );
gb_ep_assert( false === has_filter( 'woocommerce_get_checkout_url', array( $enrollment_plugin, 'contract_first_checkout_url' ) ), 'Enrollment plugin still filters the checkout URL.' );
$GLOBALS['post'] = get_post( $contract_page_id );
setup_postdata( $GLOBALS['post'] );
$contract_markup = $enrollment_plugin->contract_shortcode();
wp_reset_postdata();
gb_ep_assert( false !== strpos( $contract_markup, 'name="gb_ep_contract_submit" value="1"' ), 'Contract form does not use the frontend submission boundary.' );
gb_ep_assert( false === strpos( $contract_markup, 'admin-post.php' ), 'Contract form still posts through the unreliable admin boundary.' );
gb_ep_assert( false !== strpos( $contract_markup, 'action="' . esc_url( get_permalink( $contract_page_id ) ) . '"' ), 'Contract form action is not the provisioned agreement page.' );
WC()->cart->empty_cart();
$ordinary_locations = array( 'product', 'cart', 'checkout', 'mini-cart' );
gb_ep_assert( $ordinary_locations === apply_filters( 'woocommerce_paypal_payments_selected_button_locations', $ordinary_locations, 'locations' ), 'PayPal locations changed outside an enrollment purchase.' );

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
$runtime_token = str_repeat( 'a', 64 );
$restart_token = str_repeat( 'b', 64 );
$wpdb->insert( $contracts, array( 'token_hash' => hash( 'sha256', $restart_token ), 'status' => 'signed_unpaid', 'locale' => 'en-US', 'product_id' => $product_id, 'course_id' => $course_id, 'order_id' => 0, 'student_email_hash' => hash( 'sha256', $student_email ), 'snapshot' => $json, 'snapshot_hash' => $hash, 'signed_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
$restart_contract_id = (int) $wpdb->insert_id;
$_COOKIE[ Gulf_Breeze_Enrollment_Payment::COOKIE ] = $restart_contract_id . '.' . $restart_token;
$enrollment_plugin->invalidate_contract_on_new_enrollment( 'runtime-cart-key', $product_id, 1, 0, array(), array() );
gb_ep_assert( 'abandoned_restarted' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$contracts} WHERE id=%d", $restart_contract_id ) ), 'Starting a new enrollment did not invalidate the unused agreement.' );
gb_ep_assert( ! isset( $_COOKIE[ Gulf_Breeze_Enrollment_Payment::COOKIE ] ), 'Restarted enrollment contract cookie was not cleared.' );
$wpdb->insert( $contracts, array( 'token_hash' => hash( 'sha256', $runtime_token ), 'status' => 'signed_unpaid', 'locale' => 'en-US', 'product_id' => $product_id, 'course_id' => $course_id, 'order_id' => 0, 'student_email_hash' => hash( 'sha256', $student_email ), 'snapshot' => $json, 'snapshot_hash' => $hash, 'signed_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
$contract_id = (int) $wpdb->insert_id;
WC()->cart->add_to_cart( $product_id, 1 );
$_COOKIE[ Gulf_Breeze_Enrollment_Payment::COOKIE ] = $contract_id . '.' . $runtime_token;
gb_ep_assert( $raw_checkout_url === wc_get_checkout_url(), 'Signed agreement changed the native checkout URL.' );
WC()->cart->empty_cart();

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
$received_url = $order->get_checkout_order_received_url();
gb_ep_assert( false !== strpos( $received_url, 'order-received' ) && false !== strpos( $received_url, (string) $order->get_id() ), 'Native order-received URL is incomplete.' );
gb_ep_assert( 0 === strpos( $received_url, $raw_checkout_url ) && false === strpos( $received_url, '/enrollment-agreement/' ), 'Order confirmation URL was rebased onto the Enrollment Agreement page.' );

$duplicate_token = str_repeat( 'c', 64 );
$wpdb->insert( $contracts, array( 'token_hash' => hash( 'sha256', $duplicate_token ), 'status' => 'signed_unpaid', 'locale' => 'en-US', 'product_id' => $product_id, 'course_id' => $course_id, 'order_id' => 0, 'student_email_hash' => hash( 'sha256', $student_email ), 'snapshot' => $json, 'snapshot_hash' => $hash, 'signed_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
$duplicate_contract_id = (int) $wpdb->insert_id;
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $product_id, 1 );
$_COOKIE[ Gulf_Breeze_Enrollment_Payment::COOKIE ] = $duplicate_contract_id . '.' . $duplicate_token;
$duplicate_errors = new WP_Error();
$enrollment_plugin->validate_checkout( array(), $duplicate_errors );
gb_ep_assert( $duplicate_errors->get_error_message( 'gb_ep_duplicate_active_course' ), 'Second payment for an active course was not blocked.' );
unset( $_COOKIE[ Gulf_Breeze_Enrollment_Payment::COOKIE ] );
WC()->cart->empty_cart();

$claim_token = str_repeat( 'd', 64 );
$wpdb->insert( $contracts, array( 'token_hash' => hash( 'sha256', $claim_token ), 'status' => 'signed_unpaid', 'locale' => 'en-US', 'product_id' => $product_id, 'course_id' => $course_id, 'order_id' => 0, 'student_email_hash' => hash( 'sha256', 'claim-runtime@example.invalid' ), 'snapshot' => $json, 'snapshot_hash' => $hash, 'signed_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
$claim_contract_id = (int) $wpdb->insert_id;
$claim_order = wc_create_order();
$claim_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_CONTRACT_ID, $claim_contract_id );
$claim_order->save();
$enrollment_plugin->bind_contract_order( $claim_order );
gb_ep_assert( (int) $claim_order->get_id() === (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$contracts} WHERE id=%d", $claim_contract_id ) ), 'Agreement was not atomically claimed by its first order.' );
$duplicate_claim_order = wc_create_order();
$duplicate_claim_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_CONTRACT_ID, $claim_contract_id );
$duplicate_claim_order->save();
$duplicate_claim_blocked = false;
try { $enrollment_plugin->bind_contract_order( $duplicate_claim_order ); } catch ( Exception $error ) { $duplicate_claim_blocked = true; }
$duplicate_claim_order = wc_get_order( $duplicate_claim_order->get_id() );
gb_ep_assert( $duplicate_claim_blocked && 'duplicate_contract_blocked' === $duplicate_claim_order->get_meta( Gulf_Breeze_Enrollment_Payment::META_STATE ), 'A second order reused the same agreement claim.' );

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
