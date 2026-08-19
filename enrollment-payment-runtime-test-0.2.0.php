<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function gb_ep_020_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new Exception( $message );
	}
}

gb_ep_020_assert( class_exists( 'Gulf_Breeze_Enrollment_Payment' ), 'Enrollment plugin class missing.' );
gb_ep_020_assert( defined( 'WC_VERSION' ), 'WooCommerce missing.' );
gb_ep_020_assert( function_exists( 'learn_press_get_user' ), 'LearnPress missing.' );
gb_ep_020_assert( function_exists( 'gb_core_provider_profile' ), 'Core provider-profile API missing.' );

$plugin = Gulf_Breeze_Enrollment_Payment::instance();
$catalog = Gulf_Breeze_Catalog_Cart::instance();
$ucp_before = get_option( 'ucp_options' );

gb_ep_020_assert( false === has_action( 'template_redirect', array( $catalog, 'block_checkout_stage' ) ), 'Catalog checkout redirect lock remains active.' );
gb_ep_020_assert( false === has_filter( 'woocommerce_available_payment_gateways', array( $catalog, 'disable_payment_gateways' ) ), 'Catalog payment-gateway lock remains active.' );
gb_ep_020_assert( false === has_filter( 'rest_pre_dispatch', array( $catalog, 'block_store_api_checkout' ) ), 'Catalog Store API lock remains active.' );

$checkout_id = absint( wc_get_page_id( 'checkout' ) );
gb_ep_020_assert( $checkout_id > 0, 'Checkout page missing.' );
gb_ep_020_assert( has_shortcode( (string) get_post_field( 'post_content', $checkout_id ), 'woocommerce_checkout' ), 'Classic WooCommerce checkout is not configured.' );
$native_checkout_url = wc_get_checkout_url();

$course_id = wp_insert_post(
	array(
		'post_type' => 'lp_course',
		'post_status' => 'publish',
		'post_title' => 'Native Checkout Runtime Course',
	)
);
gb_ep_020_assert( $course_id && ! is_wp_error( $course_id ), 'Could not create LearnPress course.' );

$product = new WC_Product_Simple();
$product->set_name( 'Native Checkout Runtime Course — English' );
$product->set_regular_price( '36.00' );
$product->set_price( '36.00' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product_id = $product->save();
update_post_meta( $product_id, '_gb_catalog_key', 'adult_6h_en' );
update_post_meta( $product_id, '_gb_enrollment_locale', 'en-US' );
update_post_meta( $product_id, '_gb_learnpress_course_id', $course_id );

WC()->cart->empty_cart();
gb_ep_020_assert( WC()->cart->add_to_cart( $product_id, 1 ), 'Could not create mapped single-course cart.' );

$student_email = 'native-checkout-runtime@example.invalid';
$_POST = array(
	'gb_ep_agreement_nonce' => wp_create_nonce( 'gb_ep_checkout_agreement' ),
	'gb_ep_student_name' => 'Native Checkout Student',
	'gb_ep_student_email' => $student_email,
	'gb_ep_student_dob' => '1990-01-01',
	'gb_ep_student_gender' => 'Male',
	'gb_ep_student_phone' => '9725550100',
	'gb_ep_student_address_1' => '100 Test Street',
	'gb_ep_student_city' => 'Carrollton',
	'gb_ep_student_state' => 'TX',
	'gb_ep_student_postcode' => '75007',
	'gb_ep_student_signature' => 'Native Checkout Student',
	'gb_ep_purchaser_is_student' => '1',
	'gb_ep_consent' => '1',
);

ob_start();
$plugin->render_agreement();
$agreement_html = ob_get_clean();
gb_ep_020_assert( false !== strpos( $agreement_html, 'id="gb-ep-agreement"' ), 'Agreement is not rendered inside checkout.' );
gb_ep_020_assert( false !== strpos( $agreement_html, 'name="gb_ep_student_signature"' ), 'Student signature field missing.' );
gb_ep_020_assert( false === strpos( $agreement_html, 'admin-post.php' ), 'Agreement posts to a separate admin endpoint.' );

$errors = new WP_Error();
$plugin->validate_agreement( array(), $errors );
gb_ep_020_assert( ! $errors->has_errors(), 'Valid native checkout agreement failed validation: ' . $errors->get_error_message() );

$posted = array( 'createaccount' => 1, 'account_username' => 'should-not-exist', 'account_password' => 'should-not-exist' );
$posted = $plugin->keep_course_checkout_guest_until_paid( $posted );
gb_ep_020_assert( 0 === $posted['createaccount'] && ! isset( $posted['account_username'], $posted['account_password'] ), 'Pre-payment account creation was not disabled.' );
gb_ep_020_assert( 0 === $plugin->keep_course_order_unassigned_until_paid( 1 ), 'Pre-payment order remained assigned to a logged-in customer.' );

$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_billing_first_name( 'Native' );
$order->set_billing_last_name( 'Checkout Student' );
$order->set_billing_email( $student_email );
$order->set_billing_phone( '9725550100' );
$order->set_billing_address_1( '100 Test Street' );
$order->set_billing_city( 'Carrollton' );
$order->set_billing_state( 'TX' );
$order->set_billing_postcode( '75007' );
$order->set_billing_country( 'US' );
$order->set_payment_method( 'ppcp-gateway' );
$order->set_transaction_id( 'SANDBOX-NATIVE-CHECKOUT-001' );
$order->calculate_totals();
$plugin->save_agreement_to_order( $order, array() );
$order->set_status( 'pending' );
$order->save();

gb_ep_020_assert( 'yes' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_REQUIRED ), 'Order is not marked contract-required.' );
$snapshot_json = (string) $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_SNAPSHOT );
$snapshot_hash = (string) $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_HASH );
gb_ep_020_assert( $snapshot_json && hash_equals( $snapshot_hash, hash( 'sha256', $snapshot_json ) ), 'Atomic order agreement snapshot is invalid.' );
$plugin->guard_classic_order( $order->get_id(), array(), $order );

$plugin->process_paid_order( $order->get_id() );
gb_ep_020_assert( ! get_user_by( 'email', $student_email ), 'Unpaid order created a student account.' );
gb_ep_020_assert( ! $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_PROCESSED ), 'Unpaid order was processed.' );

remove_action( 'woocommerce_order_status_processing', array( $plugin, 'process_paid_order' ), 1 );
$order->set_date_paid( time() );
$order->set_status( 'processing' );
$order->save();
add_action( 'woocommerce_order_status_processing', array( $plugin, 'process_paid_order' ), 1 );
$plugin->process_paid_order( $order->get_id() );
wp_cache_flush();
$order = wc_get_order( $order->get_id() );

gb_ep_020_assert( 'yes' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_PROCESSED ), 'Paid order was not processed.' );
gb_ep_020_assert( 'paid_enrolled' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_STATE ), 'Paid enrollment state is incorrect.' );
$user = get_user_by( 'email', $student_email );
gb_ep_020_assert( $user, 'Paid order did not create/link the student account.' );
$user_id = absint( $user->ID );
gb_ep_020_assert( $user_id === absint( $order->get_customer_id() ), 'Paid order was not assigned to the student.' );
gb_ep_020_assert( $user_id === absint( $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID ) ), 'Student link meta missing.' );
gb_ep_020_assert( 'en-US' === get_user_meta( $user_id, 'gb_preferred_locale', true ), 'Purchased language was not saved.' );
gb_ep_020_assert( $order->get_id() === absint( get_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, true ) ), 'Active paid course marker missing.' );
$learnpress_item_class = '\\LearnPress\\Models\\UserItems\\UserCourseModel';
$learnpress_item = $learnpress_item_class::find( $user_id, $course_id, false );
gb_ep_020_assert( $learnpress_item && $learnpress_item->has_enrolled_or_finished(), 'Paid student does not have active LearnPress access.' );

$received_url = $order->get_checkout_order_received_url();
gb_ep_020_assert( 0 === strpos( $received_url, $native_checkout_url ), 'Confirmation URL is not based on WooCommerce checkout.' );
gb_ep_020_assert( false !== strpos( $received_url, 'order-received' ), 'Confirmation endpoint missing.' );
gb_ep_020_assert( false === strpos( $received_url, 'enrollment-agreement' ), 'Confirmation URL was rebased onto a separate agreement page.' );

$duplicate_errors = new WP_Error();
$plugin->validate_agreement( array(), $duplicate_errors );
gb_ep_020_assert( $duplicate_errors->get_error_message( 'gb_ep_duplicate' ), 'Active paid enrollment did not block a second payment.' );

$store_order = wc_create_order();
$store_order->add_product( $product, 1 );
$store_order->calculate_totals();
$store_order->save();
$store_api_blocked = false;
try {
	$plugin->guard_store_api_order( $store_order );
} catch ( Exception $error ) {
	$store_api_blocked = true;
}
gb_ep_020_assert( $store_api_blocked, 'Store API / Express order bypass was not blocked.' );

$plugin->handle_refunded_status( $order->get_id() );
$order = wc_get_order( $order->get_id() );
gb_ep_020_assert( 'yes' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_REVOKED ), 'Refund did not revoke course access.' );
gb_ep_020_assert( ! get_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, true ), 'Refund left the active course marker in place.' );
$learnpress_item = $learnpress_item_class::find( $user_id, $course_id, false );
gb_ep_020_assert( ! $learnpress_item || ! $learnpress_item->has_enrolled_or_finished(), 'Refund left LearnPress course access active.' );
gb_ep_020_assert( get_user_by( 'id', $user_id ), 'Refund deleted the student account.' );

$repurchase_errors = new WP_Error();
$plugin->validate_agreement( array(), $repurchase_errors );
gb_ep_020_assert( ! $repurchase_errors->get_error_message( 'gb_ep_duplicate' ), 'Refunded student could not repurchase with the same email.' );
gb_ep_020_assert( $ucp_before === get_option( 'ucp_options' ), 'Under Construction configuration changed.' );

echo "PASS Enrollment & Payment 0.2.0-dev-r1 runtime regression\n";
