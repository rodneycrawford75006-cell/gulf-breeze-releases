<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$tester_id = wp_create_user( 'gb_validation_tester', 'validation-only-password', 'tester@example.invalid' );
$other_id  = wp_create_user( 'gb_validation_other', wp_generate_password(), 'other@example.invalid' );
$course_id = wp_insert_post(
	array(
		'post_type'   => 'lp_course',
		'post_status' => 'publish',
		'post_title'  => 'Controlled Access Validation Course',
	)
);

if ( is_wp_error( $tester_id ) || is_wp_error( $other_id ) || ! $course_id ) {
	throw new RuntimeException( 'Could not create disposable validation records.' );
}

$workspace = getenv( 'GITHUB_WORKSPACE' );
if ( ! $workspace || false === file_put_contents( $workspace . '/validation/course-id.txt', (string) $course_id ) ) {
	throw new RuntimeException( 'Could not publish the disposable course ID to the browser test.' );
}

update_post_meta( $course_id, '_gb_course_key', 'adult_en' );
flush_rewrite_rules( false );

$base_registry = array(
	'adult_en' => array(
		'learnpress_course_id' => $course_id,
		'status'               => 'internal_testing',
	),
	'_internal_tester_user_ids' => array( $tester_id ),
);
update_option( 'gb_course_registry', $base_registry, false );

$core = Gulf_Breeze_Configuration::instance();

$sanitized = $core->sanitize_course_registry(
	array(
		'adult_en' => array(
			'learnpress_course_id' => $course_id,
			'status'               => 'internal_testing',
		),
		'_internal_tester_user_ids' => $tester_id . ', ' . $tester_id . ', 999999999',
	)
);
if ( array( $tester_id ) !== $sanitized['_internal_tester_user_ids'] ) {
	throw new RuntimeException( 'Tester-ID sanitization failed.' );
}

function gb_validation_access_case( $label, $user_id, $status, $expect_404, $course_id, $base_registry, $core ) {
	$registry = $base_registry;
	$registry['adult_en']['status'] = $status;
	update_option( 'gb_course_registry', $registry, false );
	wp_set_current_user( $user_id );

	global $wp_query, $post;
	$wp_query = new WP_Query(
		array(
			'post_type' => 'lp_course',
			'p'         => $course_id,
		)
	);
	$post = get_post( $course_id );
	setup_postdata( $post );
	$core->protect_internal_course_objects();

	$actual_404 = $wp_query->is_404();
	wp_reset_postdata();
	if ( $actual_404 !== $expect_404 ) {
		throw new RuntimeException( $label . ' failed: expected 404=' . ( $expect_404 ? 'true' : 'false' ) . ', received ' . ( $actual_404 ? 'true' : 'false' ) . '.' );
	}
	echo 'PASS ' . $label . PHP_EOL;
}

gb_validation_access_case( 'administrator', 1, 'internal_testing', false, $course_id, $base_registry, $core );
gb_validation_access_case( 'approved tester on internal course', $tester_id, 'internal_testing', false, $course_id, $base_registry, $core );
gb_validation_access_case( 'approved tester on building course', $tester_id, 'building', true, $course_id, $base_registry, $core );
gb_validation_access_case( 'unlisted authenticated user', $other_id, 'internal_testing', true, $course_id, $base_registry, $core );
gb_validation_access_case( 'anonymous visitor', 0, 'internal_testing', true, $course_id, $base_registry, $core );

echo "Controlled-access runtime validation passed.\n";
