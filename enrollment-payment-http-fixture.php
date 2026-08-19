<?php
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

global $wpdb;

$course_id = wp_insert_post(
	array(
		'post_type'   => 'lp_course',
		'post_status' => 'publish',
		'post_title'  => 'Enrollment HTTP Integration Course',
	)
);
if ( ! $course_id || is_wp_error( $course_id ) ) { throw new Exception( 'Could not create HTTP fixture course.' ); }

$product = new WC_Product_Simple();
$product->set_name( 'Enrollment HTTP Integration Course — English' );
$product->set_regular_price( '36.00' );
$product->set_price( '36.00' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product_id = $product->save();
if ( ! $product_id ) { throw new Exception( 'Could not create HTTP fixture product.' ); }

update_post_meta( $product_id, '_gb_catalog_key', 'adult_6h_en' );
update_post_meta( $product_id, '_gb_enrollment_locale', 'en-US' );
update_post_meta( $product_id, '_gb_learnpress_course_id', $course_id );

$token = str_repeat( 'e', 64 );
$snapshot = array(
	'schema_version'  => '1.0',
	'contract_version' => 'DEV-0.1.0',
	'locale'          => 'en-US',
	'catalog'         => array(
		'catalog_key' => 'adult_6h_en',
		'product_id'  => $product_id,
		'course_id'   => $course_id,
		'course_name' => $product->get_name(),
		'price'       => '36.00',
		'currency'    => get_woocommerce_currency(),
	),
	'student'         => array( 'legal_name' => 'HTTP Fixture Student', 'email' => 'http-fixture@example.invalid', 'dob' => '1990-01-01' ),
	'purchaser'       => array( 'legal_name' => 'HTTP Fixture Purchaser', 'email' => 'http-purchaser@example.invalid', 'is_student' => false ),
	'signatures'      => array( 'student' => 'HTTP Fixture Student', 'guardian' => '', 'provider' => 'Rodney Crawford' ),
	'terms_html'      => '<p>Disposable HTTP integration agreement.</p>',
	'evidence'        => array( 'signed_at_utc' => gmdate( 'c' ), 'ip_hash' => hash( 'sha256', 'http-fixture-ip' ), 'user_agent_hash' => hash( 'sha256', 'http-fixture-agent' ) ),
);
$json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$wpdb->insert(
	$wpdb->prefix . 'gb_ep_contracts',
	array(
		'token_hash'        => hash( 'sha256', $token ),
		'status'            => 'signed_unpaid',
		'locale'            => 'en-US',
		'product_id'        => $product_id,
		'course_id'         => $course_id,
		'order_id'          => 0,
		'student_email_hash' => hash( 'sha256', 'http-fixture@example.invalid' ),
		'snapshot'          => $json,
		'snapshot_hash'     => hash( 'sha256', $json ),
		'signed_at_utc'     => gmdate( 'Y-m-d H:i:s' ),
		'created_at_utc'    => gmdate( 'Y-m-d H:i:s' ),
	)
);
$contract_id = (int) $wpdb->insert_id;
if ( ! $contract_id ) { throw new Exception( 'Could not create HTTP fixture contract.' ); }

$order = wc_create_order();
if ( is_wp_error( $order ) ) { throw new Exception( 'Could not create HTTP fixture order.' ); }
$order->add_product( $product, 1 );
$order->calculate_totals();
$order->set_status( 'pending' );
$order->save();

echo wp_json_encode(
	array(
		'product_id'       => $product_id,
		'product_name'     => $product->get_name(),
		'cart_url'         => wc_get_cart_url(),
		'checkout_url'     => wc_get_checkout_url(),
		'contract_cookie'  => $contract_id . '.' . $token,
		'confirmation_url' => $order->get_checkout_order_received_url(),
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
