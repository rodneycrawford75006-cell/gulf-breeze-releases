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
$lesson_one_id = wp_insert_post(
	array(
		'post_type'   => 'lp_lesson',
		'post_status' => 'publish',
		'post_title'  => 'Validation Timed Lesson One',
		'post_content'=> '<p>Disposable regulated lesson content.</p>',
	)
);
$lesson_two_id = wp_insert_post(
	array(
		'post_type'   => 'lp_lesson',
		'post_status' => 'publish',
		'post_title'  => 'Validation Locked Lesson Two',
		'post_content'=> '<p>Disposable locked lesson content.</p>',
	)
);

if ( is_wp_error( $tester_id ) || is_wp_error( $other_id ) || ! $course_id || ! $lesson_one_id || ! $lesson_two_id ) {
	throw new RuntimeException( 'Could not create disposable validation records.' );
}

$workspace = getenv( 'GITHUB_WORKSPACE' );
if ( ! $workspace || false === file_put_contents( $workspace . '/validation/course-id.txt', (string) $course_id ) ) {
	throw new RuntimeException( 'Could not publish the disposable course ID to the browser test.' );
}
file_put_contents( $workspace . '/validation/lesson-one-id.txt', (string) $lesson_one_id );
file_put_contents( $workspace . '/validation/lesson-two-id.txt', (string) $lesson_two_id );

update_post_meta( $course_id, '_gb_course_key', 'adult_en' );
update_post_meta( $lesson_one_id, '_gb_course_key', 'adult_en' );
update_post_meta( $lesson_one_id, '_gb_required_seconds', 120 );
update_post_meta( $lesson_two_id, '_gb_course_key', 'adult_en' );
update_post_meta( $lesson_two_id, '_gb_required_seconds', 120 );

global $wpdb;
$wpdb->insert( $wpdb->prefix . 'learnpress_sections', array(
	'section_name' => 'Validation Section', 'section_course_id' => $course_id, 'section_order' => 1, 'section_description' => '',
) );
$section_id = (int) $wpdb->insert_id;
$wpdb->insert( $wpdb->prefix . 'learnpress_section_items', array( 'section_id' => $section_id, 'item_id' => $lesson_one_id, 'item_order' => 1, 'item_type' => 'lp_lesson' ) );
$wpdb->insert( $wpdb->prefix . 'learnpress_section_items', array( 'section_id' => $section_id, 'item_id' => $lesson_two_id, 'item_order' => 2, 'item_type' => 'lp_lesson' ) );
$enrolled = $wpdb->insert(
	$wpdb->prefix . 'learnpress_user_items',
	array(
		'user_id'    => $tester_id,
		'item_id'    => $course_id,
		'item_type'  => 'lp_course',
		'status'     => 'enrolled',
		'start_time' => current_time( 'mysql' ),
		'ref_id'     => 0,
		'ref_type'   => '',
		'parent_id'  => 0,
	)
);
if ( false === $enrolled ) {
	throw new RuntimeException( 'Could not enroll the disposable validation tester.' );
}
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

$resolver = new ReflectionMethod( $core, 'current_learnpress_item_id' );
$resolver->setAccessible( true );
$original_request_uri = $_SERVER['REQUEST_URI'] ?? '';
$wp_query = new WP_Query( array( 'post_type' => 'lp_course', 'p' => $course_id ) );
$post = get_post( $course_id );
setup_postdata( $post );
$_SERVER['REQUEST_URI'] = '/courses/controlled-access-validation-course/lessons/validation-locked-lesson-two/';
if ( $lesson_two_id !== $resolver->invoke( $core ) ) {
	throw new RuntimeException( 'Nested LearnPress route resolver did not identify lesson two.' );
}

$wp_query = new WP_Query();
$wp_query->queried_object = get_post( $lesson_one_id );
$wp_query->queried_object_id = $lesson_one_id;
$post = get_post( $lesson_one_id );
setup_postdata( $post );
$_SERVER['REQUEST_URI'] = '/?post_type=lp_lesson&p=' . $lesson_one_id;
if ( $lesson_one_id !== $resolver->invoke( $core ) ) {
	throw new RuntimeException( 'Ordinary lesson query resolver did not identify lesson one.' );
}
$_SERVER['REQUEST_URI'] = $original_request_uri;
wp_reset_postdata();
echo "PASS shared LearnPress course-item route resolver\n";

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
$lesson_one_id = wp_insert_post(
	array(
		'post_type'   => 'lp_lesson',
		'post_status' => 'publish',
		'post_title'  => 'Validation Timed Lesson One',
		'post_content'=> '<p>Disposable regulated lesson content.</p>',
	)
);
$lesson_two_id = wp_insert_post(
	array(
		'post_type'   => 'lp_lesson',
		'post_status' => 'publish',
		'post_title'  => 'Validation Locked Lesson Two',
		'post_content'=> '<p>Disposable locked lesson content.</p>',
	)
);

if ( is_wp_error( $tester_id ) || is_wp_error( $other_id ) || ! $course_id || ! $lesson_one_id || ! $lesson_two_id ) {
	throw new RuntimeException( 'Could not create disposable validation records.' );
}

$workspace = getenv( 'GITHUB_WORKSPACE' );
if ( ! $workspace || false === file_put_contents( $workspace . '/validation/course-id.txt', (string) $course_id ) ) {
	throw new RuntimeException( 'Could not publish the disposable course ID to the browser test.' );
}
file_put_contents( $workspace . '/validation/lesson-one-id.txt', (string) $lesson_one_id );
file_put_contents( $workspace . '/validation/lesson-two-id.txt', (string) $lesson_two_id );

update_post_meta( $course_id, '_gb_course_key', 'adult_en' );
update_post_meta( $lesson_one_id, '_gb_course_key', 'adult_en' );
update_post_meta( $lesson_one_id, '_gb_required_seconds', 120 );
update_post_meta( $lesson_two_id, '_gb_course_key', 'adult_en' );
update_post_meta( $lesson_two_id, '_gb_required_seconds', 120 );

global $wpdb;
$wpdb->insert( $wpdb->prefix . 'learnpress_sections', array(
	'section_name' => 'Validation Section', 'section_course_id' => $course_id, 'section_order' => 1, 'section_description' => '',
) );
$section_id = (int) $wpdb->insert_id;
$wpdb->insert( $wpdb->prefix . 'learnpress_section_items', array( 'section_id' => $section_id, 'item_id' => $lesson_one_id, 'item_order' => 1, 'item_type' => 'lp_lesson' ) );
$wpdb->insert( $wpdb->prefix . 'learnpress_section_items', array( 'section_id' => $section_id, 'item_id' => $lesson_two_id, 'item_order' => 2, 'item_type' => 'lp_lesson' ) );
$enrolled = $wpdb->insert(
	$wpdb->prefix . 'learnpress_user_items',
	array(
		'user_id'    => $tester_id,
		'item_id'    => $course_id,
		'item_type'  => 'lp_course',
		'status'     => 'enrolled',
		'start_time' => current_time( 'mysql' ),
		'ref_id'     => 0,
		'ref_type'   => '',
		'parent_id'  => 0,
	)
);
if ( false === $enrolled ) {
	throw new RuntimeException( 'Could not enroll the disposable validation tester.' );
}
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

$resolver = new ReflectionMethod( $core, 'current_learnpress_item_id' );
$resolver->setAccessible( true );
$original_request_uri = $_SERVER['REQUEST_URI'] ?? '';
$wp_query = new WP_Query( array( 'post_type' => 'lp_course', 'p' => $course_id ) );
$post = get_post( $course_id );
setup_postdata( $post );
$_SERVER['REQUEST_URI'] = '/courses/controlled-access-validation-course/lessons/validation-locked-lesson-two/';
if ( $lesson_two_id !== $resolver->invoke( $core ) ) {
	throw new RuntimeException( 'Nested LearnPress route resolver did not identify lesson two.' );
}

$wp_query = new WP_Query( array( 'post_type' => 'lp_lesson', 'p' => $lesson_one_id ) );
$post = get_post( $lesson_one_id );
setup_postdata( $post );
$_SERVER['REQUEST_URI'] = '/?post_type=lp_lesson&p=' . $lesson_one_id;
if ( $lesson_one_id !== $resolver->invoke( $core ) ) {
	throw new RuntimeException( 'Ordinary lesson query resolver did not identify lesson one.' );
}
$_SERVER['REQUEST_URI'] = $original_request_uri;
wp_reset_postdata();
echo "PASS shared LearnPress course-item route resolver\n";

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
