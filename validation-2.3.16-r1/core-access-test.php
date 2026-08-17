<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$administrator = get_user_by( 'login', 'admin' );
if ( ! $administrator ) {
	throw new RuntimeException( 'Disposable WordPress administrator is unavailable.' );
}
if ( ! function_exists( 'learn_press_add_user_roles' ) ) {
	throw new RuntimeException( 'LearnPress role-capability initializer is unavailable.' );
}
learn_press_add_user_roles();
$administrator_id = (int) $administrator->ID;
wp_set_current_user( $administrator_id );

$tester_id = wp_create_user( 'gb_validation_tester', 'validation-only-password', 'tester@example.invalid' );
$other_id  = wp_create_user( 'gb_validation_other', wp_generate_password(), 'other@example.invalid' );
$course_id = wp_insert_post( array( 'post_type' => 'lp_course', 'post_status' => 'publish', 'post_title' => 'Controlled Access Validation Course' ) );
$lesson_one_id = wp_insert_post( array(
	'post_type' => 'lp_lesson', 'post_status' => 'publish', 'post_title' => 'Validation Timed Lesson One',
	'post_content' => '<p>Disposable regulated lesson content.</p><div><strong>Before continuing:</strong> Explain the lesson rule in your own words and identify the action that reduces risk. If you cannot do both without looking, review the lesson and the official source again.</div><p>Preservation sentinel after the self-check.</p>',
) );
$lesson_two_id = wp_insert_post( array( 'post_type' => 'lp_lesson', 'post_status' => 'publish', 'post_title' => 'Validation Locked Lesson Two', 'post_content' => '<p>Disposable locked lesson content.</p>' ) );
if ( is_wp_error( $tester_id ) || is_wp_error( $other_id ) || ! $course_id || ! $lesson_one_id || ! $lesson_two_id ) {
	throw new RuntimeException( 'Could not create disposable validation records.' );
}

$workspace = getenv( 'GITHUB_WORKSPACE' );
$validation_dir = $workspace . '/validation-2.3.16-r1';
if ( ! $workspace || false === file_put_contents( $validation_dir . '/course-id.txt', (string) $course_id ) ) {
	throw new RuntimeException( 'Could not publish the disposable course ID to the browser test.' );
}
file_put_contents( $validation_dir . '/lesson-one-id.txt', (string) $lesson_one_id );
file_put_contents( $validation_dir . '/lesson-two-id.txt', (string) $lesson_two_id );

update_post_meta( $course_id, '_gb_course_key', 'adult_en' );
$lesson_ids = array( $lesson_one_id, $lesson_two_id );
for ( $index = 3; $index <= 46; $index++ ) {
	$lesson_id = wp_insert_post( array( 'post_type' => 'lp_lesson', 'post_status' => 'publish', 'post_title' => sprintf( 'Validation Lesson %02d', $index ), 'post_content' => '<p>Preserved disposable lesson content.</p>' ) );
	if ( ! $lesson_id || is_wp_error( $lesson_id ) ) {
		throw new RuntimeException( 'Could not create all 46 validation lessons.' );
	}
	$lesson_ids[] = $lesson_id;
}
$quiz_ids = array();
for ( $index = 1; $index <= 10; $index++ ) {
	$quiz_id = wp_insert_post( array( 'post_type' => 'lp_quiz', 'post_status' => 'publish', 'post_title' => sprintf( 'Validation Quiz %02d', $index ) ) );
	if ( ! $quiz_id || is_wp_error( $quiz_id ) ) {
		throw new RuntimeException( 'Could not create all 10 validation quizzes.' );
	}
	$quiz_ids[] = $quiz_id;
}
foreach ( array_merge( $lesson_ids, $quiz_ids ) as $item_id ) {
	update_post_meta( $item_id, '_gb_course_key', 'adult_en' );
}
$crosswalk = Gulf_Breeze_Configuration::adult_english_crosswalk();
if ( 46 !== count( $crosswalk ) ) {
	throw new RuntimeException( 'Adult English crosswalk did not provide all 46 lesson records.' );
}
$fixture_minutes = 0;
foreach ( $lesson_ids as $lesson_index => $lesson_id ) {
	$lesson_number = $lesson_index + 1;
	$minutes       = (int) ( $crosswalk[ $lesson_index ]['minutes'] ?? 0 );
	update_post_meta( $lesson_id, '_gb_blueprint_key', sprintf( 'adult_en_%03d', $lesson_number ) );
	update_post_meta( $lesson_id, '_gb_required_minutes', $minutes );
	$fixture_minutes += $minutes;
}
if ( 330 !== $fixture_minutes ) {
	throw new RuntimeException( 'Adult English fixture did not preserve the exact 330-minute ledger.' );
}
update_post_meta( $lesson_one_id, '_gb_required_seconds', 120 );
update_post_meta( $lesson_two_id, '_gb_required_seconds', 120 );

global $wpdb;
$sections_table = $wpdb->prefix . 'learnpress_sections';
$items_table    = $wpdb->prefix . 'learnpress_section_items';
$expected_descriptions = array(
	'4.1.1' => 'Learn how the course works, what completion requires, and how responsible driving begins with informed choices.',
	'4.1.2' => 'Review driver licensing, lawful vehicle use, and the responsibilities that come with operating a motor vehicle.',
	'4.1.3' => 'Practice recognizing right-of-way duties and choosing actions that reduce conflict at intersections and crossings.',
	'4.1.4' => 'Learn to recognize and respond correctly to traffic signs, signals, pavement markings, and other roadway controls.',
	'4.1.5' => 'Build safe vehicle-control, speed-management, positioning, communication, and roadway-use habits.',
	'4.1.6' => 'Examine how alcohol and other drugs affect driving ability, legal responsibility, and crash risk.',
	'4.1.7' => 'Learn how to share the roadway safely with pedestrians, cyclists, motorcyclists, large vehicles, and other road users.',
	'4.1.8' => 'Apply observation, space management, speed control, and sound judgment to identify and reduce driving risk.',
	'4.1.9' => 'Review the course concepts and demonstrate readiness for the controlled final assessment.',
);
$section_ids = array();
for ( $topic_number = 1; $topic_number <= 9; $topic_number++ ) {
	$topic = '4.1.' . $topic_number;
	$inserted = $wpdb->insert( $sections_table, array(
		'section_name' => 'Topic ' . $topic . ' — Validation Section', 'section_course_id' => $course_id,
		'section_order' => $topic_number, 'section_description' => 'Internal Gulf Breeze POI curriculum section ' . $topic . '.',
	) );
	if ( false === $inserted ) {
		throw new RuntimeException( 'Could not create all nine validation sections.' );
	}
	$section_ids[] = (int) $wpdb->insert_id;
}
$curriculum_items = array();
foreach ( $lesson_ids as $item_id ) {
	$curriculum_items[] = array( 'item_id' => $item_id, 'item_type' => 'lp_lesson' );
}
foreach ( $quiz_ids as $item_id ) {
	$curriculum_items[] = array( 'item_id' => $item_id, 'item_type' => 'lp_quiz' );
}
$section_sizes = array( 7, 7, 6, 6, 6, 6, 6, 6, 6 );
$cursor = 0;
foreach ( $section_sizes as $section_index => $section_size ) {
	for ( $item_order = 1; $item_order <= $section_size; $item_order++ ) {
		$item = $curriculum_items[ $cursor++ ];
		$wpdb->insert( $items_table, array( 'section_id' => $section_ids[ $section_index ], 'item_id' => $item['item_id'], 'item_order' => $item_order, 'item_type' => $item['item_type'] ) );
	}
}
if ( 56 !== $cursor ) {
	throw new RuntimeException( 'Validation fixture did not create the exact 56-item curriculum.' );
}
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
wp_set_current_user( $administrator_id );
update_option( 'gb_curriculum_migration_version', '2.3.9', false );

$before_privacy_content = (string) get_post_field( 'post_content', $lesson_one_id );
$legacy_prompt = '<strong>Before continuing:</strong> Explain the lesson rule in your own words and identify the action that reduces risk. If you cannot do both without looking, review the lesson and the official source again.';
$student_prompt = '<strong>Private self-check — no submission required:</strong> Explain the lesson rule aloud or in your own private notes, then identify the action that reduces risk. If you cannot explain both without looking, review the lesson and official source before continuing.';
$expected_privacy_content = str_replace( $legacy_prompt, $student_prompt, $before_privacy_content );
$ledger_query = $wpdb->prepare( "SELECT s.section_id, s.section_order, si.item_id, si.item_order, si.item_type FROM {$sections_table} s INNER JOIN {$items_table} si ON si.section_id = s.section_id WHERE s.section_course_id = %d ORDER BY s.section_order ASC, s.section_id ASC, si.item_order ASC, si.item_id ASC", $course_id );
$ledger_before = $wpdb->get_results( $ledger_query, ARRAY_A );
if ( 56 !== count( $ledger_before ) ) {
	throw new RuntimeException( 'Validation ledger was not exact before migration.' );
}

if ( ! class_exists( '\\LearnPress\\Models\\CourseModel' ) ) {
	throw new RuntimeException( 'LearnPress CourseModel is unavailable for the stale-JSON regression fixture.' );
}
$stale_course_model = \LearnPress\Models\CourseModel::find( $course_id, false );
if ( ! $stale_course_model || ! method_exists( $stale_course_model, 'get_section_items' ) || ! method_exists( $stale_course_model, 'save' ) ) {
	throw new RuntimeException( 'LearnPress CourseModel cannot seed the stale-JSON regression fixture.' );
}
$stale_course_model->get_section_items();
$stale_course_model->save( true );
$raw_json_before = (string) $wpdb->get_var( $wpdb->prepare( "SELECT json FROM {$wpdb->prefix}learnpress_courses WHERE ID = %d", $course_id ) );
if ( false === strpos( $raw_json_before, 'Internal Gulf Breeze POI curriculum section 4.1.1.' ) ) {
	throw new RuntimeException( 'The regression fixture did not persist stale section descriptions in course JSON.' );
}

// Prove the migration fails closed before any mutation when the regulated ledger is short.
$removed_item = end( $ledger_before );
$wpdb->delete( $items_table, array( 'section_id' => $removed_item['section_id'], 'item_id' => $removed_item['item_id'] ), array( '%d', '%d' ) );
update_option( 'gb_student_privacy_migration_version', '2.3.13', false );
$core->run_student_facing_privacy_migration();
if ( '2.3.13' !== get_option( 'gb_student_privacy_migration_version' ) ) {
	throw new RuntimeException( 'Short-ledger migration incorrectly advanced its version.' );
}
$failed_status = get_option( 'gb_student_privacy_migration_status', array() );
if ( 'error' !== ( $failed_status['status'] ?? '' ) ) {
	throw new RuntimeException( 'Short-ledger migration did not record a fail-closed error.' );
}
$failed_descriptions = $wpdb->get_col( $wpdb->prepare( "SELECT section_description FROM {$sections_table} WHERE section_course_id = %d ORDER BY section_order ASC", $course_id ) );
foreach ( $failed_descriptions as $topic_index => $description ) {
	if ( $description !== 'Internal Gulf Breeze POI curriculum section 4.1.' . ( $topic_index + 1 ) . '.' ) {
		throw new RuntimeException( 'Short-ledger migration partially changed a section description.' );
	}
}
$wpdb->insert( $items_table, array(
	'section_id' => $removed_item['section_id'], 'item_id' => $removed_item['item_id'],
	'item_order' => $removed_item['item_order'], 'item_type' => $removed_item['item_type'],
) );
if ( wp_json_encode( $ledger_before ) !== wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) ) ) {
	throw new RuntimeException( 'Validation ledger was not restored exactly after fail-closed testing.' );
}

$core->run_student_facing_privacy_migration();
$diagnostic_status  = get_option( 'gb_student_privacy_migration_status', array() );
$diagnostic_version = (string) get_option( 'gb_student_privacy_migration_version', '' );
if ( '2.3.14' !== $diagnostic_version || 'success' !== ( $diagnostic_status['status'] ?? '' ) ) {
	throw new RuntimeException( 'Migration diagnostic: version=' . $diagnostic_version . '; status=' . wp_json_encode( $diagnostic_status ) );
}
$after_privacy_content = (string) get_post_field( 'post_content', $lesson_one_id );
if ( $after_privacy_content !== $expected_privacy_content || false === strpos( $after_privacy_content, 'Preservation sentinel after the self-check.' ) ) {
	throw new RuntimeException( 'Student-facing migration changed content beyond the exact self-check replacement.' );
}
if ( 'publish' !== get_post_status( $course_id ) ) {
	throw new RuntimeException( 'Student-facing migration changed the published course status.' );
}
foreach ( array_merge( array( $course_id ), $lesson_ids, $quiz_ids ) as $closed_post_id ) {
	$closed_post = get_post( $closed_post_id );
	if ( ! $closed_post || 'closed' !== $closed_post->comment_status || 'closed' !== $closed_post->ping_status || comments_open( $closed_post_id ) ) {
		throw new RuntimeException( 'Student-facing migration did not close LMS comments and pings.' );
	}
}
$stored_sections = $wpdb->get_results( $wpdb->prepare( "SELECT section_name, section_description FROM {$sections_table} WHERE section_course_id = %d ORDER BY section_order ASC", $course_id ) );
if ( 9 !== count( $stored_sections ) ) {
	throw new RuntimeException( 'Successful migration did not preserve all nine sections.' );
}
foreach ( $stored_sections as $index => $stored_section ) {
	$topic = '4.1.' . ( $index + 1 );
	if ( 0 !== strpos( $stored_section->section_name, 'Topic ' . $topic ) || $stored_section->section_description !== $expected_descriptions[ $topic ] ) {
		throw new RuntimeException( 'A legacy section row did not contain the exact student-facing description.' );
	}
}
$fresh_course_model = \LearnPress\Models\CourseModel::find( $course_id, false );
$model_topics = array();
foreach ( (array) $fresh_course_model->get_section_items() as $model_section ) {
	foreach ( $expected_descriptions as $topic => $description ) {
		if ( 0 === strpos( (string) ( $model_section->section_name ?? '' ), 'Topic ' . $topic ) ) {
			if ( (string) ( $model_section->section_description ?? '' ) !== $description ) {
				throw new RuntimeException( 'LearnPress course JSON/model retained a stale section description.' );
			}
			$model_topics[ $topic ] = true;
		}
	}
}
if ( 9 !== count( $model_topics ) ) {
	throw new RuntimeException( 'LearnPress course JSON/model did not return all nine synchronized sections.' );
}
$raw_json_after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT json FROM {$wpdb->prefix}learnpress_courses WHERE ID = %d", $course_id ) );
if ( false !== strpos( $raw_json_after, 'Internal Gulf Breeze POI curriculum section' ) ) {
	throw new RuntimeException( 'LearnPress course JSON still contains an internal section description.' );
}
if ( wp_json_encode( $ledger_before ) !== wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) ) ) {
	throw new RuntimeException( 'Successful migration changed curriculum identity or ordering.' );
}
if ( '2.3.14' !== get_option( 'gb_student_privacy_migration_version' ) || 'success' !== ( get_option( 'gb_student_privacy_migration_status', array() )['status'] ?? '' ) ) {
	throw new RuntimeException( 'Successful migration did not record the 2.3.14 completion state.' );
}
$filtered_output = $core->filter_student_facing_output( '<div>Internal Gulf Breeze POI curriculum section 4.1.1.</div><p>Preservation sentinel.</p>' );
if ( false !== stripos( $filtered_output, 'Internal Gulf Breeze POI' ) || false === strpos( $filtered_output, 'Learn how the course works' ) || false === strpos( $filtered_output, 'Preservation sentinel.' ) ) {
	throw new RuntimeException( 'Student-facing output fallback did not perform an exact privacy replacement.' );
}
$idempotent_content = $after_privacy_content;
$idempotent_ledger  = wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) );
$idempotent_json    = $raw_json_after;
$core->run_student_facing_privacy_migration();
if ( $idempotent_content !== (string) get_post_field( 'post_content', $lesson_one_id ) || $idempotent_ledger !== wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) ) || $idempotent_json !== (string) $wpdb->get_var( $wpdb->prepare( "SELECT json FROM {$wpdb->prefix}learnpress_courses WHERE ID = %d", $course_id ) ) ) {
	throw new RuntimeException( 'Completed migration was not idempotent.' );
}
echo "PASS LearnPress section-row/course-JSON synchronization, fail-closed preservation, and idempotency\n";

// Reproduce the protected live site at curriculum migration 2.3.2. The first
// Topic 4.1.7 lesson retains the exact 379-word pre-hardening baseline that made
// the previous expansion total 955 words for an eight-minute (960-word) screen.
$boundary_filler = implode( ' ', array_fill( 0, 375, 'boundaryword' ) );
$general_filler  = implode( ' ', array_fill( 0, 1000, 'preservationword' ) );
$resume_content_before = array();
foreach ( $lesson_ids as $lesson_index => $resume_lesson_id ) {
	$lesson_number = $lesson_index + 1;
	$key           = sprintf( 'adult_en_%03d', $lesson_number );
	$before = (string) get_post_field( 'post_content', $resume_lesson_id );
	$filler = 'adult_en_032' === $key ? $boundary_filler : $general_filler;
	$visual_fixture = $lesson_number >= 17 && $lesson_number <= 21
		? '<div class="gb-sign-gallery">Preserved official-sign observation fixture.</div>'
		: '';
	$result = wp_update_post( array(
		'ID'           => $resume_lesson_id,
		'post_content' => $before . $visual_fixture . '<p class="gb-resume-fixture">' . $filler . '</p>',
	), true );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( 'Could not prepare duration-resume fixture for ' . $key . '.' );
	}
	$resume_content_before[ $key ] = (string) get_post_field( 'post_content', $resume_lesson_id );
}

$resume_ledger_before       = wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) );
$resume_sections_before     = wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT section_id, section_name, section_order, section_description FROM {$sections_table} WHERE section_course_id = %d ORDER BY section_order ASC, section_id ASC", $course_id ), ARRAY_A ) );
$resume_privacy_version     = (string) get_option( 'gb_student_privacy_migration_version', '' );
$resume_privacy_status      = get_option( 'gb_student_privacy_migration_status', array() );
update_option( 'gb_curriculum_migration_version', '2.3.2', false );
update_option( 'gb_curriculum_migration_status', array( 'status' => 'fixture', 'message' => 'Resume boundary fixture.' ), false );
delete_option( 'gb_core_migration_lock' );
delete_option( 'gb_core_migration_runtime' );
wp_clear_scheduled_hook( 'gb_core_run_curriculum_migrations' );
wp_clear_scheduled_hook( 'gb_core_run_privacy_migration' );
wp_clear_scheduled_hook( 'gb_core_migration_watchdog' );

// Ordinary administrator requests may schedule work, but must never execute it.
for ( $request = 0; $request < 20; $request++ ) {
	$core->schedule_background_migrations();
}
if ( '2.3.2' !== (string) get_option( 'gb_curriculum_migration_version', '' ) || ! wp_next_scheduled( 'gb_core_run_curriculum_migrations' ) ) {
	throw new RuntimeException( 'Repeated administrator scheduling checks executed migration work or failed to coalesce one background event.' );
}

// A current atomic lock must exclude a concurrent worker without changing state.
add_option( 'gb_core_migration_lock', 'concurrent-validation|' . time(), '', false );
$content_before_lock_test = (string) get_post_field( 'post_content', $lesson_ids[31] );
$core->run_verified_curriculum_migrations();
if ( '2.3.2' !== (string) get_option( 'gb_curriculum_migration_version', '' ) || $content_before_lock_test !== (string) get_post_field( 'post_content', $lesson_ids[31] ) ) {
	throw new RuntimeException( 'Concurrent migration lock did not exclude a second worker.' );
}
delete_option( 'gb_core_migration_lock' );

// Force a mid-checkpoint failure after adult_en_032 has been written. The retry
// must leave the version unchanged, release the lock, and preserve one marker.
$failure_lesson_id = $lesson_ids[32];
$failure_minutes   = (int) get_post_meta( $failure_lesson_id, '_gb_required_minutes', true );
update_post_meta( $failure_lesson_id, '_gb_required_minutes', 999 );
$core->run_verified_curriculum_migrations();
$failure_runtime = get_option( 'gb_core_migration_runtime', array() );
if (
	'2.3.2' !== (string) get_option( 'gb_curriculum_migration_version', '' ) ||
	'retry_wait' !== ( $failure_runtime['status'] ?? '' ) ||
	'2.3.3' !== ( $failure_runtime['target'] ?? '' ) ||
	1 !== substr_count( (string) get_post_field( 'post_content', $lesson_ids[31] ), '<!-- gb-duration-hardening-2.3.3:adult_en_032 -->' ) ||
	false !== get_option( 'gb_core_migration_lock', false )
) {
	throw new RuntimeException( 'Failed checkpoint did not remain resumable, singular, and unlocked.' );
}
update_post_meta( $failure_lesson_id, '_gb_required_minutes', $failure_minutes );

// Retrying completes only 2.3.3. It must not chain through the later topics in
// the same request, and replacing the partial marker must remain singular.
$core->run_verified_curriculum_migrations();
$resume_status = get_option( 'gb_curriculum_migration_status', array() );
$resume_runtime = get_option( 'gb_core_migration_runtime', array() );
if (
	'2.3.3' !== (string) get_option( 'gb_curriculum_migration_version', '' ) ||
	'success' !== ( $resume_status['status'] ?? '' ) ||
	'checkpoint_complete' !== ( $resume_runtime['status'] ?? '' ) ||
	'2.3.3' !== ( $resume_runtime['target'] ?? '' ) ||
	1 !== substr_count( (string) get_post_field( 'post_content', $lesson_ids[31] ), '<!-- gb-duration-hardening-2.3.3:adult_en_032 -->' ) ||
	false !== strpos( (string) get_post_field( 'post_content', $lesson_ids[37] ), '<!-- gb-duration-hardening-2.3.4:adult_en_038 -->' )
) {
	throw new RuntimeException( 'The bounded worker did not stop after exactly one successful checkpoint.' );
}

// A stale lock is recoverable and must not strand the next checkpoint.
delete_option( 'gb_core_migration_lock' );
add_option( 'gb_core_migration_lock', 'stale-validation|' . ( time() - 901 ), '', false );
$core->run_verified_curriculum_migrations();
if ( '2.3.4' !== (string) get_option( 'gb_curriculum_migration_version', '' ) || false !== get_option( 'gb_core_migration_lock', false ) ) {
	throw new RuntimeException( 'Stale migration lock recovery failed.' );
}

foreach ( array( '2.3.5', '2.3.6', '2.3.7', '2.3.8', '2.3.9' ) as $expected_checkpoint ) {
	$core->run_verified_curriculum_migrations();
	if ( $expected_checkpoint !== (string) get_option( 'gb_curriculum_migration_version', '' ) ) {
		throw new RuntimeException( 'Bounded resume failed at checkpoint ' . $expected_checkpoint . '.' );
	}
}
$resume_status = get_option( 'gb_curriculum_migration_status', array() );
if ( 'success' !== ( $resume_status['status'] ?? '' ) ) {
	throw new RuntimeException( 'Duration-resume diagnostic: version=' . get_option( 'gb_curriculum_migration_version', '' ) . '; status=' . wp_json_encode( $resume_status ) );
}

$resume_groups = array(
	'2.3.3' => range( 32, 36 ),
	'2.3.4' => range( 38, 42 ),
	'2.3.5' => range( 22, 26 ),
	'2.3.6' => range( 16, 21 ),
	'2.3.7' => range( 10, 15 ),
	'2.3.8' => range( 5, 9 ),
	'2.3.9' => range( 1, 4 ),
);
$resume_content_after = array();
foreach ( $resume_groups as $migration_version => $lesson_numbers ) {
	foreach ( $lesson_numbers as $lesson_number ) {
		$key       = sprintf( 'adult_en_%03d', $lesson_number );
		$lesson_id = $lesson_ids[ $lesson_number - 1 ];
		$content   = (string) get_post_field( 'post_content', $lesson_id );
		$marker    = '<!-- gb-duration-hardening-' . $migration_version . ':' . $key . ' -->';
		if ( 0 !== strpos( $content, trim( $resume_content_before[ $key ] ) ) || 1 !== substr_count( $content, $marker ) ) {
			throw new RuntimeException( 'Duration migration was not additive and singular for ' . $key . '.' );
		}
		if ( 'duration_hardening_' . str_replace( '.', '_', $migration_version ) . '_dev' !== get_post_meta( $lesson_id, '_gb_content_status', true ) ) {
			throw new RuntimeException( 'Duration migration did not record the expected content status for ' . $key . '.' );
		}
		$resume_content_after[ $key ] = $content;
	}
}

$boundary_content = $resume_content_after['adult_en_032'];
$boundary_plain   = wp_strip_all_tags( $boundary_content );
preg_match_all( "/\\b[\\p{L}\\p{N}][\\p{L}\\p{N}’'-]*\\b/u", $boundary_plain, $boundary_words );
$boundary_count = count( $boundary_words[0] );
if ( $boundary_count < 960 || $boundary_count !== (int) get_post_meta( $lesson_ids[31], '_gb_duration_audit_words', true ) ) {
	throw new RuntimeException( 'Corrected adult_en_032 did not satisfy and record the strict eight-minute duration screen: ' . $boundary_count . ' words.' );
}

$resume_minute_total = 0;
foreach ( $lesson_ids as $resume_lesson_id ) {
	$resume_minute_total += (int) get_post_meta( $resume_lesson_id, '_gb_required_minutes', true );
}
if ( 330 !== $resume_minute_total || $resume_ledger_before !== wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) ) ) {
	throw new RuntimeException( 'Resumed duration migrations changed the regulated curriculum ledger.' );
}
if ( $resume_sections_before !== wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT section_id, section_name, section_order, section_description FROM {$sections_table} WHERE section_course_id = %d ORDER BY section_order ASC, section_id ASC", $course_id ), ARRAY_A ) ) ) {
	throw new RuntimeException( 'Resumed duration migrations changed a regulated section.' );
}
$resume_course_status          = get_post_status( $course_id );
$resume_privacy_version_after = (string) get_option( 'gb_student_privacy_migration_version', '' );
$resume_privacy_status_after  = get_option( 'gb_student_privacy_migration_status', array() );
if ( 'publish' !== $resume_course_status || $resume_privacy_version !== $resume_privacy_version_after || $resume_privacy_status !== $resume_privacy_status_after ) {
	throw new RuntimeException(
		'Resume preservation diagnostic: course_status=' . (string) $resume_course_status .
		'; privacy_version_before=' . $resume_privacy_version .
		'; privacy_version_after=' . $resume_privacy_version_after .
		'; privacy_status_before=' . wp_json_encode( $resume_privacy_status ) .
		'; privacy_status_after=' . wp_json_encode( $resume_privacy_status_after )
	);
}
foreach ( array_merge( array( $course_id ), $lesson_ids, $quiz_ids ) as $preserved_post_id ) {
	$preserved_post = get_post( $preserved_post_id );
	if ( ! $preserved_post || 'closed' !== $preserved_post->comment_status || 'closed' !== $preserved_post->ping_status ) {
		throw new RuntimeException( 'Resumed duration migrations reopened an LMS discussion surface.' );
	}
}

$core->run_verified_curriculum_migrations();
foreach ( $resume_content_after as $key => $content ) {
	$lesson_number = (int) substr( $key, -3 );
	if ( $content !== (string) get_post_field( 'post_content', $lesson_ids[ $lesson_number - 1 ] ) ) {
		throw new RuntimeException( 'Completed duration-resume sequence was not idempotent for ' . $key . '.' );
	}
}
echo "PASS nonblocking admin scheduling, atomic exclusion, failure recovery, stale-lock recovery, bounded 2.3.3-2.3.9 resume, preservation, and idempotency\n";

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

gb_validation_access_case( 'administrator', $administrator_id, 'internal_testing', false, $course_id, $base_registry, $core );
gb_validation_access_case( 'approved tester on internal course', $tester_id, 'internal_testing', false, $course_id, $base_registry, $core );
gb_validation_access_case( 'approved tester on building course', $tester_id, 'building', true, $course_id, $base_registry, $core );
gb_validation_access_case( 'unlisted authenticated user', $other_id, 'internal_testing', true, $course_id, $base_registry, $core );
gb_validation_access_case( 'anonymous visitor', 0, 'internal_testing', true, $course_id, $base_registry, $core );

echo "Controlled-access runtime validation passed.\n";
