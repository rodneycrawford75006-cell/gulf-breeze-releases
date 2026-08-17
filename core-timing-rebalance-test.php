<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

global $wpdb;
$administrator = get_user_by( 'login', 'admin' );
if ( ! $administrator ) {
	throw new RuntimeException( 'Disposable WordPress administrator is unavailable.' );
}
wp_set_current_user( (int) $administrator->ID );
$administrator->add_cap( 'edit_lp_lesson' );
$administrator->add_cap( 'edit_lp_lessons' );
$administrator->add_cap( 'edit_others_lp_lessons' );
$administrator->add_cap( 'publish_lp_lessons' );
$administrator->add_cap( 'read_private_lp_lessons' );
$administrator->add_cap( 'edit_lp_course' );
$administrator->add_cap( 'edit_lp_courses' );
$administrator->add_cap( 'edit_others_lp_courses' );
$administrator->add_cap( 'publish_lp_courses' );
$administrator->add_cap( 'read_private_lp_courses' );

if ( ! user_can( $administrator, 'edit_lp_lessons' ) ) {
	throw new RuntimeException( 'Disposable WordPress administrator lacks LearnPress lesson-edit capability.' );
}

$course_id = wp_insert_post( array( 'post_type' => 'lp_course', 'post_status' => 'publish', 'post_title' => 'Gulf Breeze Timing Validation Course', 'post_author' => (int) $administrator->ID ) );
if ( ! $course_id || is_wp_error( $course_id ) ) {
	throw new RuntimeException( 'Could not create the disposable course.' );
}
if ( ! user_can( $administrator, 'edit_lp_course', $course_id ) ) {
	throw new RuntimeException( 'Disposable WordPress administrator cannot edit the LearnPress validation course.' );
}
update_post_meta( $course_id, '_gb_course_key', 'adult_en' );

$target_minutes = array( 1=>2,2=>3,3=>3,4=>2,5=>4,6=>5,7=>5,8=>5,9=>5,10=>5,11=>8,12=>7,13=>7,14=>8,15=>7,16=>4,18=>6,38=>5 );
$base_words = array( 1=>439,2=>488,3=>486,4=>453,5=>850,6=>610,7=>577,8=>624,9=>607,10=>465,11=>638,12=>639,13=>612,14=>734,15=>659,16=>358,18=>446,38=>487 );
$remove_versions = array( 1=>'2.3.9',2=>'2.3.9',3=>'2.3.9',4=>'2.3.9',5=>'2.3.8',6=>'2.3.8',7=>'2.3.8',8=>'2.3.8',9=>'2.3.8',10=>'2.3.7',11=>'2.3.7',12=>'2.3.7',13=>'2.3.7',14=>'2.3.7',15=>'2.3.7',16=>'2.3.6',18=>'2.3.6',38=>'2.3.4' );
$visual_numbers = array( 10,11,12,13,14,15,16,18 );
$lesson_ids = array(); $original_target_state = array();
for ( $number = 1; $number <= 46; $number++ ) {
	$key = sprintf( 'adult_en_%03d', $number );
	$minutes = $target_minutes[ $number ] ?? ( 46 === $number ? 23 : 8 );
	$content = '<p>Untargeted preservation sentinel.</p>';
	if ( isset( $target_minutes[ $number ] ) ) {
		$visual = in_array( $number, $visual_numbers, true ) ? '<div class="gb-sign-gallery">visualsentinel</div>' : '';
		$visual_word_count = '' === $visual ? 0 : 1;
		$base = '<p>' . implode( ' ', array_fill( 0, $base_words[ $number ] - $visual_word_count, 'preserved' ) ) . '</p>' . $visual;
		$old_marker = 'gb-duration-hardening-' . $remove_versions[ $number ] . ':' . $key;
		$old_expansion = '<p>' . implode( ' ', array_fill( 0, $minutes * 40, 'overfilled' ) ) . '</p>';
		$content = $base . '<!-- ' . $old_marker . ' -->' . $old_expansion . '<!-- /gb-duration-hardening-' . $remove_versions[ $number ] . ' -->';
	}
	$lesson_id = wp_insert_post( array( 'post_type'=>'lp_lesson', 'post_status'=>'publish', 'post_title'=>'Validation Lesson '.$number, 'post_content'=>$content, 'post_author'=>(int)$administrator->ID ) );
	if ( ! $lesson_id || is_wp_error( $lesson_id ) ) throw new RuntimeException( 'Could not create lesson ' . $number . '.' );
	$lesson_ids[ $number ] = (int) $lesson_id;
	if ( ! user_can( $administrator, 'edit_lp_lesson', $lesson_id ) ) throw new RuntimeException( 'Disposable WordPress administrator cannot edit validation lesson ' . $number . '.' );
	update_post_meta( $lesson_id, '_gb_blueprint_key', $key );
	update_post_meta( $lesson_id, '_gb_required_minutes', $minutes );
	update_post_meta( $lesson_id, '_gb_required_seconds', $minutes * 60 );
	update_post_meta( $lesson_id, '_gb_poi_coverage', 'objective-sentinel-' . $key );
	update_post_meta( $lesson_id, '_gb_content_status', 'pre-rebalance-status' );
	update_post_meta( $lesson_id, '_gb_duration_audit_words', 9999 );
	if ( isset( $target_minutes[ $number ] ) ) {
		$original_target_state[ $key ] = array(
			'content' => (string) get_post_field( 'post_content', $lesson_id ),
			'status' => get_post_meta( $lesson_id, '_gb_content_status', true ),
			'audit' => get_post_meta( $lesson_id, '_gb_duration_audit_words', true ),
			'objective' => get_post_meta( $lesson_id, '_gb_poi_coverage', true ),
			'minutes' => get_post_meta( $lesson_id, '_gb_required_minutes', true ),
			'seconds' => get_post_meta( $lesson_id, '_gb_required_seconds', true ),
		);
	}
}

$quiz_ids = array();
for ( $number = 1; $number <= 10; $number++ ) {
	$quiz_id = wp_insert_post( array( 'post_type'=>'lp_quiz', 'post_status'=>'publish', 'post_title'=>'Validation Quiz '.$number, 'post_author'=>(int)$administrator->ID ) );
	if ( ! $quiz_id || is_wp_error( $quiz_id ) ) throw new RuntimeException( 'Could not create quiz ' . $number . '.' );
	$quiz_ids[] = (int) $quiz_id;
}

$sections_table = $wpdb->prefix . 'learnpress_sections';
$items_table = $wpdb->prefix . 'learnpress_section_items';
$section_ids = array();
for ( $number = 1; $number <= 9; $number++ ) {
	if ( false === $wpdb->insert( $sections_table, array( 'section_name'=>'Topic 4.1.'.$number.' — Validation', 'section_course_id'=>$course_id, 'section_order'=>$number, 'section_description'=>'Validation section.' ) ) ) throw new RuntimeException( 'Could not create section ' . $number . '.' );
	$section_ids[] = (int) $wpdb->insert_id;
}
$items = array();
foreach ( $lesson_ids as $lesson_id ) $items[] = array( 'id'=>$lesson_id, 'type'=>'lp_lesson' );
foreach ( $quiz_ids as $quiz_id ) $items[] = array( 'id'=>$quiz_id, 'type'=>'lp_quiz' );
$section_sizes = array( 7,7,6,6,6,6,6,6,6 ); $cursor = 0;
foreach ( $section_sizes as $section_index => $section_size ) {
	for ( $order = 1; $order <= $section_size; $order++ ) {
		$item = $items[ $cursor++ ];
		if ( false === $wpdb->insert( $items_table, array( 'section_id'=>$section_ids[$section_index], 'item_id'=>$item['id'], 'item_order'=>$order, 'item_type'=>$item['type'] ) ) ) throw new RuntimeException( 'Could not create ordered curriculum item.' );
	}
}
if ( 56 !== $cursor ) throw new RuntimeException( 'Fixture did not create 56 ordered items.' );

$registry = array( 'adult_en'=>array( 'learnpress_course_id'=>(int)$course_id, 'status'=>'internal_testing' ), '_internal_tester_user_ids'=>array() );
update_option( 'gb_course_registry', $registry, false );
update_option( 'gb_curriculum_migration_version', '2.3.9', false );
$ledger_query = $wpdb->prepare( "SELECT s.section_id,s.section_name,s.section_order,si.item_id,si.item_type,si.item_order FROM {$sections_table} s INNER JOIN {$items_table} si ON si.section_id=s.section_id WHERE s.section_course_id=%d ORDER BY s.section_order ASC,si.item_order ASC,si.section_id ASC,si.item_id ASC", $course_id );
$ledger_before = wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) );

$core = Gulf_Breeze_Configuration::instance();

// Force a final preservation failure after content writes and prove the migration restores every lesson write and audit value.
$sabotaged = false; $save_count = 0;
$sabotage = function() use ( &$sabotaged, &$save_count, $wpdb, $sections_table, $section_ids, &$sabotage ) {
	$save_count++;
	if ( ! $sabotaged && 6 === $save_count ) {
		$sabotaged = true;
		remove_action( 'save_post_lp_lesson', $sabotage, 10 );
		$wpdb->update( $sections_table, array( 'section_order'=>99 ), array( 'section_id'=>$section_ids[0] ) );
	}
};
add_action( 'save_post_lp_lesson', $sabotage, 10 );
$core->run_verified_curriculum_migrations();
if ( '2.3.9' !== (string) get_option( 'gb_curriculum_migration_version', '' ) || 'error' !== ( get_option( 'gb_curriculum_migration_status', array() )['status'] ?? '' ) ) throw new RuntimeException( 'Sabotaged migration did not fail closed.' );
foreach ( $original_target_state as $key => $before ) {
	$number = (int) substr( $key, -3 ); $lesson_id = $lesson_ids[ $number ];
	if ( $before['content'] !== (string)get_post_field('post_content',$lesson_id) || $before['status'] !== get_post_meta($lesson_id,'_gb_content_status',true) || $before['audit'] !== get_post_meta($lesson_id,'_gb_duration_audit_words',true) ) throw new RuntimeException( 'Rollback did not restore ' . $key . '.' );
}
$wpdb->update( $sections_table, array( 'section_order'=>1 ), array( 'section_id'=>$section_ids[0] ) );
if ( $ledger_before !== wp_json_encode( $wpdb->get_results( $ledger_query, ARRAY_A ) ) ) throw new RuntimeException( 'Could not restore sabotaged curriculum order.' );

$core->run_verified_curriculum_migrations();
$status = get_option( 'gb_curriculum_migration_status', array() );
if ( '2.3.10' !== (string)get_option('gb_curriculum_migration_version','') || 'success' !== ($status['status']??'') ) throw new RuntimeException( 'Successful timing migration did not finalize: ' . wp_json_encode( $status ) );
$successful_content = array();
foreach ( $original_target_state as $key => $before ) {
	$number = (int) substr( $key, -3 ); $lesson_id = $lesson_ids[ $number ]; $content = (string)get_post_field('post_content',$lesson_id);
	$plain = wp_strip_all_tags( $content ); preg_match_all( "/\b[\p{L}\p{N}][\p{L}\p{N}’'-]*\b/u", $plain, $matches ); $count = count( $matches[0] ); $minutes = $target_minutes[$number];
	if ( $count < $minutes*120 || $count > $minutes*150 ) throw new RuntimeException( $key . ' finished outside its readable-word band: ' . $count . '.' );
	if ( 1 !== substr_count( $content, '<!-- gb-timing-rebalance-2.3.10:' . $key . ' -->' ) || false !== strpos( $content, 'gb-duration-hardening-' . $remove_versions[$number] . ':' . $key ) ) throw new RuntimeException( $key . ' retained an old marker or has a nonsingular new marker.' );
	if ( in_array($number,$visual_numbers,true) && false === strpos($content,'gb-sign-gallery') ) throw new RuntimeException( $key . ' lost its sign gallery.' );
	if ( $before['objective'] !== get_post_meta($lesson_id,'_gb_poi_coverage',true) || $before['minutes'] !== get_post_meta($lesson_id,'_gb_required_minutes',true) || $before['seconds'] !== get_post_meta($lesson_id,'_gb_required_seconds',true) ) throw new RuntimeException( $key . ' changed required objective or timer metadata.' );
	if ( 'timing_rebalance_2_3_10_dev' !== get_post_meta($lesson_id,'_gb_content_status',true) || $count !== (int)get_post_meta($lesson_id,'_gb_duration_audit_words',true) ) throw new RuntimeException( $key . ' did not record its audit state.' );
	$successful_content[$key] = $content;
}
if ( 'publish' !== get_post_status($course_id) || $registry !== get_option('gb_course_registry',array()) || $ledger_before !== wp_json_encode($wpdb->get_results($ledger_query,ARRAY_A)) ) throw new RuntimeException( 'Successful migration changed course status, registry, or ordered curriculum.' );
$minute_total = 0; foreach($lesson_ids as $lesson_id)$minute_total+=(int)get_post_meta($lesson_id,'_gb_required_minutes',true);
if ( 330 !== $minute_total ) throw new RuntimeException( 'Successful migration changed the 330-minute ledger.' );

update_option( 'gb_curriculum_migration_version', '2.3.9', false );
$core->run_verified_curriculum_migrations();
foreach ( $successful_content as $key => $content ) { $number=(int)substr($key,-3); if($content!==(string)get_post_field('post_content',$lesson_ids[$number])) throw new RuntimeException( 'Forced retry was not idempotent for '.$key.'.' ); }

echo "PASS Core 2.3.17 WordPress/LearnPress timing rebalance, rollback, preservation, and idempotency\n";
