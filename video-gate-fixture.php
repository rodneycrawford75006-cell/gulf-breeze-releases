<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

global $wpdb;

function gbvqg_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function gbvqg_insert_lp_user_item( $values ) {
	global $wpdb;
	$table = $wpdb->prefix . 'learnpress_user_items';
	$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );
	gbvqg_assert( is_array( $columns ) && in_array( 'user_id', $columns, true ) && in_array( 'item_id', $columns, true ), 'LearnPress user-items schema is unavailable.' );
	$data = array();
	foreach ( $values as $key => $value ) {
		if ( in_array( $key, $columns, true ) ) {
			$data[ $key ] = $value;
		}
	}
	gbvqg_assert( false !== $wpdb->insert( $table, $data ), 'Could not create a LearnPress user-item record: ' . $wpdb->last_error );
	return (int) $wpdb->insert_id;
}

function gbvqg_create_question_bank( $quiz_id, $prefix ) {
	$question_ids = array();
	for ( $index = 1; $index <= 4; $index++ ) {
		$question_id = wp_insert_post( array(
			'post_type'    => 'lp_question',
			'post_status'  => 'publish',
			'post_title'   => $prefix . ' Question ' . $index,
			'post_content' => 'Choose the verified answer for ' . $prefix . ' item ' . $index . '.',
		) );
		gbvqg_assert( $question_id && ! is_wp_error( $question_id ), 'Could not create validation question.' );
		update_post_meta( $question_id, '_lp_answers', array(
			array( 'id' => 'wrong-' . $index, 'title' => 'Incorrect ' . $index, 'is_true' => 0 ),
			array( 'id' => 'correct-' . $index, 'title' => 'Correct ' . $index, 'is_true' => 1 ),
		) );
		$question_ids[] = (int) $question_id;
	}
	update_post_meta( $quiz_id, '_lp_questions', $question_ids );
	return $question_ids;
}

function gbvqg_create_video_triplet( $course_id, $section_id, $order, $label, $video_id ) {
	global $wpdb;
	$lesson_id = wp_insert_post( array(
		'post_type'    => 'lp_lesson',
		'post_status'  => 'publish',
		'post_title'   => $label . ' Required Video',
		'post_name'    => sanitize_title( $label . '-required-video' ),
		'post_content' => '<p>Required Gulf Breeze instructional video.</p><iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $video_id ) . '" title="' . esc_attr( $label ) . ' required video"></iframe><p><a class="lp-button next gbvqg-test-next" href="#">Next</a></p>',
	) );
	$quiz_id = wp_insert_post( array(
		'post_type'   => 'lp_quiz',
		'post_status' => 'publish',
		'post_title'  => $label . ' Video Check',
		'post_name'   => sanitize_title( $label . '-video-check' ),
	) );
	$next_id = wp_insert_post( array(
		'post_type'    => 'lp_lesson',
		'post_status'  => 'publish',
		'post_title'   => $label . ' Follow-up Lesson',
		'post_name'    => sanitize_title( $label . '-follow-up-lesson' ),
		'post_content' => '<h2>' . esc_html( $label ) . ' Follow-up Available</h2>',
	) );
	foreach ( array( $lesson_id, $quiz_id, $next_id ) as $post_id ) {
		gbvqg_assert( $post_id && ! is_wp_error( $post_id ), 'Could not create a video-gate curriculum item.' );
	}
	$items_table = $wpdb->prefix . 'learnpress_section_items';
	$items = array(
		array( 'id' => $lesson_id, 'type' => 'lp_lesson' ),
		array( 'id' => $quiz_id, 'type' => 'lp_quiz' ),
		array( 'id' => $next_id, 'type' => 'lp_lesson' ),
	);
	foreach ( $items as $offset => $item ) {
		gbvqg_assert( false !== $wpdb->insert( $items_table, array(
			'section_id' => $section_id,
			'item_id'    => $item['id'],
			'item_order' => $order + $offset,
			'item_type'  => $item['type'],
		) ), 'Could not add a curriculum item.' );
	}
	gbvqg_create_question_bank( $quiz_id, $label );
	return array( 'course_id' => (int) $course_id, 'lesson_id' => (int) $lesson_id, 'quiz_id' => (int) $quiz_id, 'next_id' => (int) $next_id );
}

gbvqg_assert( class_exists( 'GulfBreeze_Video_Quiz_Gate' ), 'Video Quiz Gate did not activate.' );
gbvqg_assert( defined( 'LEARNPRESS_VERSION' ) || post_type_exists( 'lp_course' ), 'LearnPress did not activate.' );

$admin = get_user_by( 'login', 'admin' );
gbvqg_assert( $admin instanceof WP_User, 'Disposable administrator is unavailable.' );
wp_set_current_user( (int) $admin->ID );

$student_id = wp_create_user( 'video-student', 'validation-only-password', 'video-student@example.invalid' );
gbvqg_assert( $student_id && ! is_wp_error( $student_id ), 'Could not create the disposable student.' );
$student = get_user_by( 'id', $student_id );
$student->set_role( 'subscriber' );

$course_id = wp_insert_post( array(
	'post_type'   => 'lp_course',
	'post_status' => 'publish',
	'post_title'  => 'Gulf Breeze Required Video Validation',
	'post_name'   => 'gulf-breeze-required-video-validation',
) );
gbvqg_assert( $course_id && ! is_wp_error( $course_id ), 'Could not create the disposable course.' );

$sections_table = $wpdb->prefix . 'learnpress_sections';
gbvqg_assert( false !== $wpdb->insert( $sections_table, array(
	'section_name'        => 'Required Video Evidence',
	'section_course_id'   => $course_id,
	'section_order'       => 1,
	'section_description' => 'Disposable CSEA and water-safety gate validation.',
) ), 'Could not create the disposable section.' );
$section_id = (int) $wpdb->insert_id;

$csea = gbvqg_create_video_triplet( $course_id, $section_id, 1, 'CSEA', 'csea-validation-video' );
$water = gbvqg_create_video_triplet( $course_id, $section_id, 4, 'Recreational Water Safety', 'water-safety-validation-video' );

// A supported video that is not followed by a title containing "Video Check" must not be registered.
$decoy_lesson_id = wp_insert_post( array(
	'post_type' => 'lp_lesson', 'post_status' => 'publish', 'post_title' => 'Decoy Video',
	'post_content' => '<video controls src="/decoy.mp4"></video>',
) );
$decoy_quiz_id = wp_insert_post( array( 'post_type' => 'lp_quiz', 'post_status' => 'publish', 'post_title' => 'Ordinary Topic Quiz' ) );
$items_table = $wpdb->prefix . 'learnpress_section_items';
foreach ( array( array( $decoy_lesson_id, 'lp_lesson' ), array( $decoy_quiz_id, 'lp_quiz' ) ) as $offset => $item ) {
	gbvqg_assert( false !== $wpdb->insert( $items_table, array( 'section_id' => $section_id, 'item_id' => $item[0], 'item_order' => 7 + $offset, 'item_type' => $item[1] ) ), 'Could not create the decoy adjacency.' );
}

$manual_gate = array(
	'gate_id' => 'manual-preserved', 'enabled' => 1, 'enabled_explicitly_saved_v025' => 1,
	'label' => 'Preserved administrator mapping', 'course_id' => 900001, 'video_lesson_id' => 900002,
	'quiz_id' => 900003, 'next_item_id' => 900004, 'expected_questions' => 7,
	'show_questions' => 2, 'fail_mode' => 'fresh_completion', 'admin_sentinel' => 'unchanged',
);
update_option( 'gbvqg_gates', array( $manual_gate ), false );
update_option( 'gbvqg_registry_version', '', false );

$plugin = GulfBreeze_Video_Quiz_Gate::instance();
$plugin->maybe_register_adjacent_video_gates();
$gates = get_option( 'gbvqg_gates', array() );
gbvqg_assert( 3 === count( $gates ), 'Dynamic migration did not preserve one mapping and add exactly two required gates.' );
gbvqg_assert( $manual_gate === $gates[0], 'Dynamic migration changed the administrator mapping.' );
gbvqg_assert( '2026-08-14-v3' === get_option( 'gbvqg_registry_version', '' ), 'Registry policy version did not advance.' );

$found = array();
foreach ( array_slice( $gates, 1 ) as $gate ) {
	$found[ (int) $gate['video_lesson_id'] ] = $gate;
	gbvqg_assert( 4 === (int) $gate['expected_questions'] && 1 === (int) $gate['show_questions'], 'Discovered gate defaults are incorrect.' );
	gbvqg_assert( 'return_video' === $gate['fail_mode'] && ! empty( $gate['enabled'] ), 'Discovered gate enforcement mode is incorrect.' );
}
gbvqg_assert( isset( $found[ $csea['lesson_id'] ], $found[ $water['lesson_id'] ] ), 'CSEA and water-safety mappings were not both discovered.' );
gbvqg_assert( ! isset( $found[ $decoy_lesson_id ] ), 'The non-Video-Check decoy was incorrectly registered.' );

update_option( 'gbvqg_registry_version', '', false );
$plugin->maybe_register_adjacent_video_gates();
gbvqg_assert( 3 === count( get_option( 'gbvqg_gates', array() ) ), 'Forced registry retry duplicated a gate.' );

$user_items_table = $wpdb->prefix . 'learnpress_user_items';
$now = current_time( 'mysql', true );
$course_user_item_id = gbvqg_insert_lp_user_item( array(
	'user_id' => $student_id, 'item_id' => $course_id, 'item_type' => 'lp_course', 'ref_id' => 0,
	'ref_type' => '', 'status' => 'enrolled', 'graduation' => 'in-progress', 'start_time' => $now,
) );
foreach ( array( $csea['lesson_id'], $water['lesson_id'] ) as $lesson_id ) {
	gbvqg_insert_lp_user_item( array(
		'user_id' => $student_id, 'item_id' => $lesson_id, 'item_type' => 'lp_lesson', 'ref_id' => $course_id,
		'ref_type' => 'lp_course', 'status' => 'completed', 'graduation' => 'completed',
		'parent_id' => $course_user_item_id, 'start_time' => $now, 'end_time' => $now,
	) );
}

// Signed playback evidence must fail closed after any field is altered.
$reflection = new ReflectionClass( $plugin );
$has_verified = $reflection->getMethod( 'has_verified_playback' );
$has_verified->setAccessible( true );
$gate = $found[ $csea['lesson_id'] ];
$evidence = array(
	'schema' => 1, 'complete' => 1, 'gate_id' => $gate['gate_id'], 'course_id' => $course_id,
	'lesson_id' => $csea['lesson_id'], 'duration_seconds' => 10.0, 'verified_watch_seconds' => 10.0,
	'started_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 10 ), 'completed_at_utc' => gmdate( 'Y-m-d H:i:s' ),
	'heartbeat_count' => 2, 'violation_count' => 0,
);
$evidence['record_hash'] = hash_hmac( 'sha256', wp_json_encode( $evidence ), wp_salt( 'auth' ) );
$meta_key = '_gbvqg_playback_' . $gate['gate_id'];
update_user_meta( $student_id, $meta_key, $evidence );
gbvqg_assert( true === $has_verified->invoke( $plugin, $student_id, $gate ), 'Valid signed playback evidence was rejected.' );
$evidence['course_id']++;
update_user_meta( $student_id, $meta_key, $evidence );
gbvqg_assert( false === $has_verified->invoke( $plugin, $student_id, $gate ), 'Tampered playback evidence was accepted.' );
delete_user_meta( $student_id, $meta_key );

foreach ( array( 'csea' => &$csea, 'water' => &$water ) as $key => &$row ) {
	$gate = $found[ $row['lesson_id'] ];
	$row['gate_id'] = $gate['gate_id'];
	$row['lesson_url'] = get_permalink( $row['lesson_id'] );
	$row['quiz_url'] = get_permalink( $row['quiz_id'] );
	$row['next_url'] = get_permalink( $row['next_id'] );
}
unset( $row );

$fixture = array(
	'base_url' => home_url( '/' ),
	'student' => array( 'username' => 'video-student', 'password' => 'validation-only-password', 'id' => (int) $student_id ),
	'course_id' => (int) $course_id,
	'csea' => $csea,
	'water' => $water,
);

file_put_contents( getenv( 'GBVQG_FIXTURE_JSON' ), wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo "PASS Video Gate 0.3.1 activation, dynamic two-gate migration, preservation, idempotency, enrollment fixture, and signed-evidence tamper rejection\n";
