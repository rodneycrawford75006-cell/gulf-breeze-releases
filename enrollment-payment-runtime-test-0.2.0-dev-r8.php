<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function gb_ep_020_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new Exception( $message );
	}
}

function gb_ep_020_find_mail( $mail_log, $subject_fragment ) {
	foreach ( $mail_log as $mail ) {
		if ( false !== strpos( (string) $mail['subject'], $subject_fragment ) ) {
			return $mail;
		}
	}
	return array();
}

gb_ep_020_assert( class_exists( 'Gulf_Breeze_Enrollment_Payment' ), 'Enrollment plugin class missing.' );
gb_ep_020_assert( defined( 'WC_VERSION' ), 'WooCommerce missing.' );
gb_ep_020_assert( function_exists( 'learn_press_get_user' ), 'LearnPress missing.' );
gb_ep_020_assert( function_exists( 'gb_core_provider_profile' ), 'Core provider-profile API missing.' );

// LearnPress protects course metadata writes in the same way as wp-admin. WP-CLI
// starts without a current user, so establish the disposable stack administrator
// before creating the registered course fixture.
$runtime_original_user_id = get_current_user_id();
wp_set_current_user( 1 );

$plugin = Gulf_Breeze_Enrollment_Payment::instance();
$catalog = Gulf_Breeze_Catalog_Cart::instance();
$core = Gulf_Breeze_Configuration::instance();
$ucp_before = get_option( 'ucp_options' );
$mail_log = array();
$password_generated_observed = null;
add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) use ( &$mail_log ) {
		$mail_log[] = $atts;
		return true;
	},
	10,
	2
);
add_action(
	'woocommerce_created_customer',
	function ( $customer_id, $new_customer_data, $password_generated ) use ( &$password_generated_observed ) {
		$password_generated_observed = $password_generated;
	},
	0,
	3
);

gb_ep_020_assert( false === has_action( 'template_redirect', array( $catalog, 'block_checkout_stage' ) ), 'Catalog checkout redirect lock remains active.' );
gb_ep_020_assert( false === has_filter( 'woocommerce_available_payment_gateways', array( $catalog, 'disable_payment_gateways' ) ), 'Catalog payment-gateway lock remains active.' );
gb_ep_020_assert( false === has_filter( 'rest_pre_dispatch', array( $catalog, 'block_store_api_checkout' ) ), 'Catalog Store API lock remains active.' );
$plugin->replace_core_course_access_gate();
gb_ep_020_assert( false === has_action( 'template_redirect', array( $core, 'protect_internal_course_objects' ) ), 'Core blanket non-administrator course 404 remains active.' );
gb_ep_020_assert( 0 === has_action( 'template_redirect', array( $plugin, 'protect_course_objects_for_entitled_students' ) ), 'Entitlement-aware course protection is not registered at the Core boundary.' );

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
update_post_meta( $course_id, '_gb_course_key', 'adult_en' );
$course_registry = get_option( 'gb_course_registry', array() );
$course_registry = is_array( $course_registry ) ? $course_registry : array();
$course_registry['adult_en'] = array(
	'learnpress_course_id' => $course_id,
	'status' => 'internal_testing',
);
update_option( 'gb_course_registry', $course_registry, false );
gb_ep_020_assert( 'adult_en' === get_post_meta( $course_id, '_gb_course_key', true ), 'LearnPress rejected the registered course-key fixture.' );
$registered_course = get_option( 'gb_course_registry', array() );
gb_ep_020_assert( $course_id === absint( $registered_course['adult_en']['learnpress_course_id'] ?? 0 ), 'Registered course fixture was not saved.' );

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
$order->set_billing_first_name( 'Runtime' );
$order->set_billing_last_name( 'Purchaser' );
$order->set_billing_email( 'runtime-purchaser@example.invalid' );
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

$username_collision_id = wp_create_user( 'native.checkout.student', wp_generate_password(), 'username-collision@example.invalid' );
gb_ep_020_assert( ! is_wp_error( $username_collision_id ), 'Could not create the name-based username collision fixture.' );

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
gb_ep_020_assert( 'completed' === $order->get_status(), 'Verified paid online-course order did not move to Completed after enrollment.' );
$user = get_user_by( 'email', $student_email );
gb_ep_020_assert( $user, 'Paid order did not create/link the student account.' );
$user_id = absint( $user->ID );
gb_ep_020_assert( 'native.checkout.student2' === $user->user_login, 'Name-based username or collision suffix is incorrect.' );
gb_ep_020_assert( $user_id === absint( $order->get_customer_id() ), 'Paid order was not assigned to the student.' );
gb_ep_020_assert( $user_id === absint( $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID ) ), 'Student link meta missing.' );
gb_ep_020_assert( 'yes' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_ACCOUNT_CREATED ), 'New paid student was not recorded as an account created by this order.' );
gb_ep_020_assert( 'yes' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_ONBOARDING_SENT ), 'Student onboarding email was not marked sent.' );
gb_ep_020_assert( 'native_new_account_email_requested' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_ONBOARDING_RESULT ), 'Native WooCommerce first-time account email was not recorded.' );
gb_ep_020_assert( true === $password_generated_observed, 'WooCommerce was not allowed to generate the first-time student password.' );
gb_ep_020_assert( $order->get_id() === absint( get_user_meta( $user_id, '_gb_ep_initial_order_id', true ) ), 'Native account email lost its durable order context.' );
gb_ep_020_assert( 'en-US' === get_user_meta( $user_id, 'gb_preferred_locale', true ), 'Purchased language was not saved.' );
gb_ep_020_assert( $order->get_id() === absint( get_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, true ) ), 'Active paid course marker missing.' );
$learnpress_item_class = '\\LearnPress\\Models\\UserItems\\UserCourseModel';
$learnpress_item = $learnpress_item_class::find( $user_id, $course_id, false );
gb_ep_020_assert( $learnpress_item && $learnpress_item->has_enrolled_or_finished(), 'Paid student does not have active LearnPress access.' );

$onboarding_mail = gb_ep_020_find_mail( $mail_log, 'account has been created' );
gb_ep_020_assert( $onboarding_mail, 'Native WooCommerce new-account email was not generated.' );
gb_ep_020_assert( $student_email === $onboarding_mail['to'], 'Native account email was not addressed to the student.' );
gb_ep_020_assert( false !== strpos( $onboarding_mail['message'], 'action=newaccount' ), 'Native account email lacks WooCommerce first-time password creation.' );
gb_ep_020_assert( false !== strpos( $onboarding_mail['message'], 'Create My Password' ), 'Native account email lacks the prominent Gulf Breeze password button.' );
gb_ep_020_assert( false !== strpos( $onboarding_mail['message'], 'gb_ep_email_password' ), 'Native account email lacks the signed Gulf Breeze password endpoint.' );
gb_ep_020_assert( false !== strpos( $onboarding_mail['message'], 'No old password is required' ), 'Native account email does not explain that no old password is required.' );
gb_ep_020_assert( false !== strpos( $onboarding_mail['message'], 'Native Checkout Runtime Course' ), 'Native account email lacks the purchased course.' );
gb_ep_020_assert( false !== strpos( $onboarding_mail['message'], 'Signed agreement copy' ), 'Native account email lacks the signed agreement copy.' );
gb_ep_020_assert( false !== strpos( $onboarding_mail['message'], $snapshot_hash ), 'Native account email lacks the agreement hash.' );
gb_ep_020_assert( ! gb_ep_020_find_mail( $mail_log, 'Create your password and access your Gulf Breeze course' ), 'A second password email was generated and could invalidate the native link.' );

$mail_count_after_enrollment = count( $mail_log );
$plugin->process_paid_order( $order->get_id() );
gb_ep_020_assert( $mail_count_after_enrollment === count( $mail_log ), 'Duplicate paid callback sent another onboarding email.' );

$admin_email = (object) array( 'id' => 'new_order' );
ob_start();
$plugin->email_order_context( $order, true, false, $admin_email );
$admin_email_html = ob_get_clean();
gb_ep_020_assert( false !== strpos( $admin_email_html, 'Native Checkout Student' ), 'Administrator email lacks the student identity.' );
gb_ep_020_assert( false !== strpos( $admin_email_html, $student_email ), 'Administrator email lacks the student email.' );
gb_ep_020_assert( false !== strpos( $admin_email_html, 'Runtime Purchaser' ), 'Administrator email lacks the purchaser identity.' );
gb_ep_020_assert( false !== strpos( $admin_email_html, 'runtime-purchaser@example.invalid' ), 'Administrator email lacks the purchaser email.' );
gb_ep_020_assert( false !== strpos( $admin_email_html, 'Final payment and enrollment status is recorded on the WooCommerce order' ), 'Administrator email lacks the honest final-status direction.' );
gb_ep_020_assert( false === strpos( $admin_email_html, 'Enrollment state:' ), 'Administrator new-order email exposes a stale enrollment state.' );
gb_ep_020_assert( false === strpos( $admin_email_html, 'Student user ID:' ), 'Administrator new-order email exposes a blank or stale student user ID.' );

$customer_email = (object) array( 'id' => 'customer_processing_order' );
ob_start();
$plugin->email_order_context( $order, false, false, $customer_email );
$customer_email_html = ob_get_clean();
gb_ep_020_assert( false !== strpos( $customer_email_html, 'Login email:' ), 'Purchaser receipt lacks the student login identity.' );
gb_ep_020_assert( false !== strpos( $customer_email_html, 'Student signature:' ), 'Purchaser receipt lacks the signed-agreement evidence.' );

$menu = $plugin->student_account_menu( array( 'dashboard' => 'Dashboard', 'orders' => 'Orders' ), array() );
gb_ep_020_assert( isset( $menu['my-courses'] ) && 'My Courses' === $menu['my-courses'], 'WooCommerce account menu lacks My Courses.' );

$previous_user_id = get_current_user_id();
wp_set_current_user( $user_id );
ob_start();
$plugin->student_courses_page();
$courses_html = ob_get_clean();
gb_ep_020_assert( false !== strpos( $courses_html, 'Native Checkout Runtime Course' ), 'My Courses lacks the paid course.' );
gb_ep_020_assert( false !== strpos( $courses_html, '36.00' ), 'My Courses lacks the actual paid amount.' );
gb_ep_020_assert( false !== strpos( $courses_html, '#' . $order->get_order_number() ), 'My Courses lacks the WooCommerce order number.' );
gb_ep_020_assert( false !== strpos( $courses_html, 'Start/Continue Course' ), 'My Courses lacks a course access button.' );

$original_wp_query = $GLOBALS['wp_query'];
$original_wp_the_query = $GLOBALS['wp_the_query'];
$course_query = static function ( $queried_course_id ) {
	$query = new WP_Query();
	$query->is_single = true;
	$query->queried_object = get_post( $queried_course_id );
	$query->queried_object_id = absint( $queried_course_id );
	return $query;
};
$GLOBALS['wp_query'] = $course_query( $course_id );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
gb_ep_020_assert( is_singular( array( 'lp_course', 'lp_lesson', 'lp_quiz' ) ), 'Simulated paid-student course request is not singular.' );
gb_ep_020_assert( $course_id === get_queried_object_id(), 'Simulated paid-student course request lost its object ID.' );
$plugin->protect_course_objects_for_entitled_students();
gb_ep_020_assert( ! $GLOBALS['wp_query']->is_404(), 'Paid enrolled student was converted to a 404 on the published course.' );

wp_set_current_user( 0 );
$GLOBALS['wp_query'] = $course_query( $course_id );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
gb_ep_020_assert( is_singular( array( 'lp_course', 'lp_lesson', 'lp_quiz' ) ), 'Simulated public course request is not singular.' );
gb_ep_020_assert( $course_id === get_queried_object_id(), 'Simulated public course request lost its object ID.' );
$plugin->protect_course_objects_for_entitled_students();
gb_ep_020_assert( $GLOBALS['wp_query']->is_404(), 'Public visitor could directly open the protected registered course.' );

wp_set_current_user( $previous_user_id );
$GLOBALS['wp_query'] = $original_wp_query;
$GLOBALS['wp_the_query'] = $original_wp_the_query;

$paid_price_html = $plugin->mapped_course_price_html( 'Free', get_post( $course_id ) );
gb_ep_020_assert( false !== strpos( $paid_price_html, 'Paid' ) && false === strpos( $paid_price_html, 'Free' ), 'Paid LearnPress course still displays Free.' );

$legacy_order = wc_create_order();
$legacy_order->add_product( $product, 1 );
$legacy_order->set_customer_id( $user_id );
$legacy_order->set_payment_method( 'ppcp-gateway' );
$legacy_order->set_transaction_id( 'SANDBOX-R8-LEGACY-PROCESSING-001' );
$legacy_order->calculate_totals();
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_REQUIRED, 'yes' );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_SNAPSHOT, $snapshot_json );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_HASH, $snapshot_hash );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_SIGNED_AT, $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_SIGNED_AT ) );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_COURSE_ID, $course_id );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID, $user_id );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_PROCESSED, 'yes' );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_REVOKED, 'no' );
$legacy_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_STATE, 'paid_enrolled' );
$legacy_order->set_date_paid( time() );
$legacy_order->set_status( 'processing' );
$legacy_order->save();
update_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, $legacy_order->get_id() );
$plugin->reconcile_existing_paid_course_orders();
$legacy_order = wc_get_order( $legacy_order->get_id() );
gb_ep_020_assert( 'completed' === $legacy_order->get_status(), 'Qualifying existing paid Processing order was not reconciled to Completed.' );
$legacy_note_count = count( wc_get_order_notes( array( 'order_id' => $legacy_order->get_id(), 'type' => 'internal' ) ) );
$plugin->reconcile_existing_paid_course_orders();
gb_ep_020_assert( $legacy_note_count === count( wc_get_order_notes( array( 'order_id' => $legacy_order->get_id(), 'type' => 'internal' ) ) ), 'Repeat reconciliation changed an already completed order.' );
gb_ep_020_assert( false === get_option( 'gb_ep_order_completion_sync_version', false ), 'A one-time global completion flag can suppress a later order retry.' );
update_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, $order->get_id() );

$unpaid_user_id = wp_create_user( 'gb-ep-unpaid-runtime', wp_generate_password(), 'gb-ep-unpaid-runtime@example.invalid' );
gb_ep_020_assert( ! is_wp_error( $unpaid_user_id ), 'Could not create unpaid runtime user.' );
$unpaid_lp_user = learn_press_get_user( $unpaid_user_id );
$unpaid_permission = $plugin->block_unpaid_learnpress_enrollment( true, get_post( $course_id ), $unpaid_lp_user );
gb_ep_020_assert( is_wp_error( $unpaid_permission ), 'Mapped LearnPress course allowed direct unpaid enrollment.' );
gb_ep_020_assert( false === $plugin->hide_mapped_free_enroll_button( true, $unpaid_lp_user, get_post( $course_id ) ), 'Mapped LearnPress free-enroll button remains visible.' );

$order_actions = $plugin->student_order_actions( array(), $order );
gb_ep_020_assert( isset( $order_actions['gb_ep_resend_student_access'] ), 'Paid order lacks the administrator resend action.' );
$mail_count_before_resend = count( $mail_log );
$plugin->resend_student_access( $order );
gb_ep_020_assert( count( $mail_log ) === $mail_count_before_resend + 1, 'Administrator resend did not generate exactly one email.' );
$resent_mail = end( $mail_log );
gb_ep_020_assert( $student_email === $resent_mail['to'], 'Administrator resend was not addressed to the student.' );
gb_ep_020_assert( false !== strpos( $resent_mail['message'], 'Create My Password' ), 'New-account resend lacks a fresh password button.' );
gb_ep_020_assert( false !== strpos( $resent_mail['message'], 'Open My Courses' ), 'Administrator resend lacks My Courses.' );
gb_ep_020_assert( false !== strpos( $resent_mail['message'], '36.00' ), 'Administrator resend lacks the actual paid amount.' );
gb_ep_020_assert( 'administrator_resend_accepted_by_woocommerce_mailer' === $order->get_meta( Gulf_Breeze_Enrollment_Payment::META_ONBOARDING_RESULT ), 'Administrator resend did not use or record the WooCommerce transactional mail path.' );

global $wpdb;
$wc_verified_meta = '_wc_email_verified_' . rtrim( $wpdb->get_blog_prefix( get_current_blog_id() ), '_' );
delete_user_meta( $user_id, $wc_verified_meta );
do_action( 'after_password_reset', $user, 'runtime-password-not-used-for-login' );
gb_ep_020_assert( strtolower( $student_email ) === get_user_meta( $user_id, $wc_verified_meta, true ), 'WooCommerce did not verify the student email after the normal password-reset completion event.' );

$_GET['key'] = $order->get_order_key();
ob_start();
$plugin->thankyou_student_access( $order->get_id() );
$separate_purchaser_thankyou = ob_get_clean();
unset( $_GET['key'] );
gb_ep_020_assert( false !== strpos( $separate_purchaser_thankyou, 'secure password-creation link was sent' ), 'Separate purchaser confirmation does not direct the student to email.' );
gb_ep_020_assert( false === strpos( $separate_purchaser_thankyou, '>Create my password<' ), 'Separate purchaser received the student-only password button.' );

$received_url = $order->get_checkout_order_received_url();
gb_ep_020_assert( 0 === strpos( $received_url, $native_checkout_url ), 'Confirmation URL is not based on WooCommerce checkout.' );
gb_ep_020_assert( false !== strpos( $received_url, 'order-received' ), 'Confirmation endpoint missing.' );
gb_ep_020_assert( false === strpos( $received_url, 'enrollment-agreement' ), 'Confirmation URL was rebased onto a separate agreement page.' );

$self_snapshot = json_decode( $snapshot_json, true );
$self_snapshot['signatures']['purchaser_is_student'] = true;
$self_json = wp_json_encode( $self_snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$self_order = wc_create_order();
$self_order->add_product( $product, 1 );
$self_order->set_customer_id( $user_id );
$self_order->set_billing_first_name( 'Native Checkout' );
$self_order->set_billing_last_name( 'Student' );
$self_order->set_billing_email( $student_email );
$self_order->set_payment_method( 'ppcp-gateway' );
$self_order->set_transaction_id( 'SANDBOX-NATIVE-CHECKOUT-SELF-001' );
$self_order->calculate_totals();
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_REQUIRED, 'yes' );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_SNAPSHOT, $self_json );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_HASH, hash( 'sha256', $self_json ) );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_SIGNED_AT, gmdate( 'c' ) );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_COURSE_ID, $course_id );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_LOCALE, 'en-US' );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID, $user_id );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_PROCESSED, 'yes' );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_REVOKED, 'no' );
$self_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_STATE, 'paid_enrolled' );
$self_order->set_date_paid( time() );
$self_order->set_status( 'processing' );
$self_order->save();

$migration_user_before = get_current_user_id();
wp_set_current_user( 1 );
update_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, $self_order->get_id() );
$plugin->reconcile_existing_paid_course_orders();
wp_set_current_user( $migration_user_before );
$self_order = wc_get_order( $self_order->get_id() );
gb_ep_020_assert( 'completed' === $self_order->get_status(), 'Repeatable reconciliation did not complete an already-processed verified paid course order.' );

$login_user_before = get_current_user_id();
wp_set_current_user( 0 );
$GLOBALS['wp']->query_vars['order-received'] = $self_order->get_id();
$GLOBALS['wp_query']->query_vars['order-received'] = $self_order->get_id();
$_GET['key'] = 'invalid-order-key';
$plugin->maybe_sign_in_self_purchaser();
gb_ep_020_assert( 0 === get_current_user_id(), 'Invalid order key signed a student in.' );
$_GET['key'] = $self_order->get_order_key();
$plugin->maybe_sign_in_self_purchaser();
$self_order = wc_get_order( $self_order->get_id() );
gb_ep_020_assert( $user_id === get_current_user_id(), 'Verified paid self-purchaser was not signed in.' );
gb_ep_020_assert( $self_order->get_meta( '_gb_ep_auto_login_used_at_utc' ), 'One-time automatic-login marker was not recorded.' );
$auto_login_marker = $self_order->get_meta( '_gb_ep_auto_login_used_at_utc' );
wp_set_current_user( 0 );
$plugin->maybe_sign_in_self_purchaser();
$self_order = wc_get_order( $self_order->get_id() );
gb_ep_020_assert( 0 === get_current_user_id() && $auto_login_marker === $self_order->get_meta( '_gb_ep_auto_login_used_at_utc' ), 'Paid-return automatic login was reusable.' );
unset( $GLOBALS['wp']->query_vars['order-received'], $GLOBALS['wp_query']->query_vars['order-received'], $_GET['key'] );
wp_set_current_user( $login_user_before );

$_GET['key'] = $self_order->get_order_key();
ob_start();
$plugin->thankyou_student_access( $self_order->get_id() );
$self_thankyou = ob_get_clean();
unset( $_GET['key'] );
gb_ep_020_assert( false !== strpos( $self_thankyou, '>Create my password<' ), 'Self-purchasing student confirmation lacks the password-creation button.' );
gb_ep_020_assert( false !== strpos( $self_thankyou, 'You are signed in now' ), 'Self-purchasing student confirmation does not explain the automatic sign-in.' );
gb_ep_020_assert( false !== strpos( $self_thankyou, '>Go to My Course<' ), 'Self-purchasing student confirmation lacks direct course access.' );

$reflection = new ReflectionClass( 'Gulf_Breeze_Enrollment_Payment' );
$self_service_method = $reflection->getMethod( 'self_service_password_url' );
$self_service_method->setAccessible( true );
$self_service_url = $self_service_method->invoke( $plugin, $self_order );
parse_str( (string) wp_parse_url( $self_service_url, PHP_URL_QUERY ), $self_service_query );
$expected_signature = hash_hmac( 'sha256', $self_order->get_id() . '|' . $self_order->get_order_key(), wp_salt( 'auth' ) );
gb_ep_020_assert( '1' === (string) $self_service_query['gb_ep_create_password'], 'Self-service password URL lacks the endpoint flag.' );
gb_ep_020_assert( $self_order->get_order_key() === $self_service_query['order_key'], 'Self-service password URL lacks the native order key.' );
gb_ep_020_assert( hash_equals( $expected_signature, $self_service_query['signature'] ), 'Self-service password URL signature is invalid.' );

$test_user_id = wp_create_user( 'gb-ep-reset-runtime', wp_generate_password(), 'gb-ep-reset-runtime@example.invalid' );
gb_ep_020_assert( ! is_wp_error( $test_user_id ), 'Could not create disposable reset fixture.' );
$test_user = get_user_by( 'id', $test_user_id );
$test_order = wc_create_order();
$test_order->add_product( $product, 1 );
$test_order->set_customer_id( $test_user_id );
$test_order->set_payment_method( 'ppcp-gateway' );
$test_order->set_transaction_id( 'SANDBOX-RESET-PRESERVE-001' );
$test_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_REQUIRED, 'yes' );
$test_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_SNAPSHOT, $snapshot_json );
$test_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_HASH, $snapshot_hash );
$test_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID, $test_user_id );
$test_order->save();
update_user_meta( $test_user_id, '_gb_ep_active_course_' . $course_id, $test_order->get_id() );
update_user_meta( $test_user_id, 'gb_preferred_locale', 'en-US' );
$enroll_method = $reflection->getMethod( 'enroll_course' );
$enroll_method->setAccessible( true );
gb_ep_020_assert( $enroll_method->invoke( $plugin, $test_user_id, $course_id, $test_order->get_id() ), 'Could not create disposable LearnPress progress fixture.' );
$reset_method = $reflection->getMethod( 'reset_marked_test_account' );
$reset_method->setAccessible( true );
gb_ep_020_assert( is_wp_error( $reset_method->invoke( $plugin, $test_user ) ), 'Unmarked account was accepted by the reset engine.' );
$admin_user = get_user_by( 'id', 1 );
gb_ep_020_assert( $admin_user && is_wp_error( $reset_method->invoke( $plugin, $admin_user ) ), 'Administrator account was accepted by the reset engine.' );
update_user_meta( $test_user_id, '_gb_ep_test_account', 'yes' );
$sync_method = $reflection->getMethod( 'sync_internal_tester_access' );
$sync_method->setAccessible( true );
gb_ep_020_assert( true === $sync_method->invoke( $plugin, $test_user_id, true ), 'Marked test account could not synchronize to the Core allowlist.' );
$registry_after_grant = get_option( 'gb_course_registry', array() );
gb_ep_020_assert( in_array( $test_user_id, array_map( 'absint', (array) ( $registry_after_grant['_internal_tester_user_ids'] ?? array() ) ), true ), 'Marked test account was not added to the Core Internal testing allowlist.' );
gb_ep_020_assert( true === $sync_method->invoke( $plugin, $test_user_id, false ), 'Test-account allowlist removal failed.' );
$registry_after_revoke = get_option( 'gb_course_registry', array() );
gb_ep_020_assert( ! in_array( $test_user_id, array_map( 'absint', (array) ( $registry_after_revoke['_internal_tester_user_ids'] ?? array() ) ), true ), 'Unmarked test account remained on the Core Internal testing allowlist.' );
$sync_method->invoke( $plugin, $test_user_id, true );
$test_password_before = $test_user->user_pass;
$notes_before = count( wc_get_order_notes( array( 'order_id' => $test_order->get_id(), 'type' => 'internal' ) ) );
gb_ep_020_assert( true === $reset_method->invoke( $plugin, $test_user ), 'Explicitly marked disposable account was not reset.' );
$test_user_after = get_user_by( 'id', $test_user_id );
$test_order_after = wc_get_order( $test_order->get_id() );
$test_lp_after = $learnpress_item_class::find( $test_user_id, $course_id, false );
gb_ep_020_assert( $test_user_after && $test_password_before !== $test_user_after->user_pass, 'Test reset deleted the user or left the known password active.' );
gb_ep_020_assert( ! get_user_meta( $test_user_id, '_gb_ep_active_course_' . $course_id, true ), 'Test reset retained the active-course marker.' );
gb_ep_020_assert( 'test_reset' === get_user_meta( $test_user_id, '_gb_ep_account_state', true ), 'Test reset did not record the reset account state.' );
gb_ep_020_assert( ! $test_lp_after || ! $test_lp_after->has_enrolled_or_finished(), 'Test reset retained LearnPress progress or access.' );
gb_ep_020_assert( $test_order_after && 'SANDBOX-RESET-PRESERVE-001' === $test_order_after->get_transaction_id(), 'Test reset altered or deleted the PayPal transaction reference.' );
gb_ep_020_assert( $snapshot_json === $test_order_after->get_meta( Gulf_Breeze_Enrollment_Payment::META_SNAPSHOT ), 'Test reset altered the signed contract snapshot.' );
gb_ep_020_assert( $snapshot_hash === $test_order_after->get_meta( Gulf_Breeze_Enrollment_Payment::META_HASH ), 'Test reset altered the signed contract hash.' );
gb_ep_020_assert( 0 === absint( $test_order_after->get_customer_id() ), 'Test reset left the historical order attached to the student-facing account.' );
gb_ep_020_assert( $test_user_id === absint( $test_order_after->get_meta( Gulf_Breeze_Enrollment_Payment::META_TEST_RESET_DETACHED_USER ) ), 'Test reset did not preserve the original detached customer ID.' );
gb_ep_020_assert( $test_order_after->get_meta( Gulf_Breeze_Enrollment_Payment::META_TEST_RESET_DETACHED_AT ), 'Test reset did not record the order-detachment timestamp.' );
gb_ep_020_assert( $test_user_id === absint( $test_order_after->get_meta( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID ) ), 'Test reset removed the preserved original student evidence.' );
$student_visible_orders = wc_get_orders( array( 'customer_id' => $test_user_id, 'limit' => -1, 'return' => 'ids' ) );
gb_ep_020_assert( ! in_array( $test_order->get_id(), array_map( 'absint', $student_visible_orders ), true ), 'Detached historical test order still appears in the student order query.' );
gb_ep_020_assert( count( wc_get_order_notes( array( 'order_id' => $test_order->get_id(), 'type' => 'internal' ) ) ) === $notes_before + 1, 'Test reset did not append exactly one audit note.' );

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

$http_username = 'gb-r8-http-student';
$http_password = 'r8-http-student-password';
$http_user_id = wp_create_user( $http_username, $http_password, 'gb-r8-http-student@example.invalid' );
gb_ep_020_assert( ! is_wp_error( $http_user_id ), 'Could not create the HTTP student-access fixture.' );
$http_snapshot = json_decode( $snapshot_json, true );
$http_snapshot['student']['legal_name'] = 'Revision Eight HTTP Student';
$http_snapshot['student']['email'] = 'gb-r8-http-student@example.invalid';
$http_snapshot_json = wp_json_encode( $http_snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$http_snapshot_hash = hash( 'sha256', $http_snapshot_json );
$http_order = wc_create_order();
$http_order->add_product( $product, 1 );
$http_order->set_customer_id( $http_user_id );
$http_order->set_payment_method( 'ppcp-gateway' );
$http_order->set_transaction_id( 'SANDBOX-R8-HTTP-ACCESS-001' );
$http_order->calculate_totals();
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_REQUIRED, 'yes' );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_SNAPSHOT, $http_snapshot_json );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_HASH, $http_snapshot_hash );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_SIGNED_AT, gmdate( 'c' ) );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_COURSE_ID, $course_id );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_STUDENT_USER_ID, $http_user_id );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_PROCESSED, 'yes' );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_REVOKED, 'no' );
$http_order->update_meta_data( Gulf_Breeze_Enrollment_Payment::META_STATE, 'paid_enrolled' );
$http_order->set_date_paid( time() );
$http_order->set_status( 'completed' );
$http_order->save();
update_user_meta( $http_user_id, '_gb_ep_active_course_' . $course_id, $http_order->get_id() );
gb_ep_020_assert( $enroll_method->invoke( $plugin, $http_user_id, $course_id, $http_order->get_id() ), 'Could not enroll the HTTP student-access fixture.' );
update_option(
	'gb_ep_r8_http_fixture',
	array(
		'username' => $http_username,
		'password' => $http_password,
		'course_url' => get_permalink( $course_id ),
	),
	false
);

wp_set_current_user( $runtime_original_user_id );

echo "PASS Enrollment & Payment 0.2.0-dev-r8 focused runtime regression\n";
