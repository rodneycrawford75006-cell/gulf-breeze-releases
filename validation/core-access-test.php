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
		'post_content'=> '<p>Disposable regulated lesson content.</p><div><strong>Before continuing:</strong> Explain the lesson rule in your own words and identify the action that reduces risk. If you cannot do both without looking, review the lesson and the official source again.</div><p>Preservation sentinel after the self-check.</p>',
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
	'section_name' => 'Topic 4.1.1 — Validation Section', 'section_course_id' => $course_id, 'section_order' => 1, 'section_description' => 'Internal Gulf Breeze POI curriculum section 4.1.1.',
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

$before_privacy_content = (string) get_post_field( 'post_content', $lesson_one_id );
$legacy_prompt = '<strong>Before continuing:</strong> Explain the lesson rule in your own words and identify the action that reduces risk. If you cannot do both without looking, review the lesson and the official source again.';
$student_prompt = '<strong>Private self-check — no submission required:</strong> Explain the lesson rule aloud or in your own private notes, then identify the action that reduces risk. If you cannot explain both without looking, review the lesson and official source before continuing.';
$expected_privacy_content = str_replace( $legacy_prompt, $student_prompt, $before_privacy_content );
unset( $wpdb->learnpress_sections, $wpdb->learnpress_section_items );
$core->run_student_facing_privacy_migration();
$after_privacy_content = (string) get_post_field( 'post_content', $lesson_one_id );
if ( $after_privacy_content !== $expected_privacy_content || false === strpos( $after_privacy_content, 'Preservation sentinel after the self-check.' ) ) {
	throw new RuntimeException( 'Student-facing migration changed content beyond the exact self-check replacement.' );
}
if ( 'publish' !== get_post_status( $course_id ) ) {
	throw new RuntimeException( 'Student-facing migration changed the published course status.' );
}
foreach ( array( $course_id, $lesson_one_id, $lesson_two_id ) as $closed_post_id ) {
	$closed_post = get_post( $closed_post_id );
	if ( ! $closed_post || 'closed' !== $closed_post->comment_status || 'closed' !== $closed_post->ping_status || comments_open( $closed_post_id ) ) {
		throw new RuntimeException( 'Student-facing migration did not close LMS comments and pings.' );
	}
}
$section_description = (string) $wpdb->get_var( $wpdb->prepare( "SELECT section_description FROM {$wpdb->prefix}learnpress_sections WHERE section_id = %d", $section_id ) );
if ( false !== stripos( $section_description, 'internal' ) || false === strpos( $section_description, 'Learn how the course works' ) ) {
	throw new RuntimeException( 'Internal section description was not replaced with student-facing language.' );
}
$filtered_output = $core->filter_student_facing_output( '<div>Internal Gulf Breeze POI curriculum section 4.1.1.</div><p>Preservation sentinel.</p>' );
if ( false !== stripos( $filtered_output, 'Internal Gulf Breeze POI' ) || false === strpos( $filtered_output, 'Learn how the course works' ) || false === strpos( $filtered_output, 'Preservation sentinel.' ) ) {
	throw new RuntimeException( 'Student-facing output fallback did not perform an exact privacy replacement.' );
}
echo "PASS exact-content student-facing privacy migration\n";

$resolver = new ReflectionMethod( $core, 'current_learnpress_item_id' );
$resolver->setAccessible( true );
$original_request_uri = $_SERVER['REQUEST_URI'] ?? '';
$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'lp_course', 'p' => $course_id ) );
$GLOBALS['post'] = get_post( $course_id );
setup_postdata( $GLOBALS['post'] );
$_SERVER['REQUEST_URI'] = '/courses/controlled-access-validation-course/lessons/validation-locked-lesson-two/';
if ( $lesson_two_id !== $resolver->invoke( $core ) ) {
	throw new RuntimeException( 'Nested LearnPress route resolver did not identify lesson two.' );
}

$GLOBALS['wp_query'] = new WP_Query();
$GLOBALS['wp_query']->queried_object = get_post( $lesson_one_id );
$GLOBALS['wp_query']->queried_object_id = $lesson_one_id;
$GLOBALS['post'] = get_post( $lesson_one_id );
setup_postdata( $GLOBALS['post'] );
$_SERVER['REQUEST_URI'] = '/?post_type=lp_lesson&p=' . $lesson_one_id;
if ( $lesson_one_id !== $resolver->invoke( $core ) ) {
	throw new RuntimeException( 'Ordinary lesson query resolver did not identify lesson one.' );
}
$_SERVER['REQUEST_URI'] = $original_request_uri;
wp_reset_postdata();
echo "PASS shared LearnPress course-item route resolver\n";

$course = function_exists( 'learn_press_get_course' ) ? learn_press_get_course( $course_id ) : null;
if ( ! is_object( $course ) || ! method_exists( $course, 'get_item_link' ) ) {
	throw new RuntimeException( 'LearnPress canonical course-item link API is unavailable.' );
}
$canonical_lesson_url = $course->get_item_link( $lesson_one_id );
if ( ! is_string( $canonical_lesson_url ) || false === strpos( $canonical_lesson_url, '/courses/' ) || false === strpos( $canonical_lesson_url, '/lessons/' ) ) {
	throw new RuntimeException( 'LearnPress canonical lesson URL is not course-nested: ' . (string) $canonical_lesson_url );
}
echo "PASS LearnPress canonical course-item link generation\n";

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
