<?php
/**
 * Plugin Name: Gulf Breeze Core
 * Description: Permanent modular foundation for Gulf Breeze configuration, course compliance, enrollment, payments, records, certificates, reporting, and system health.
 * Version: 1.5.0
 * Author: Gulf Breeze Driving School Texas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gulf_Breeze_Configuration {
	const OPTION = 'gb_config';
	const PAGE   = 'gulf-breeze-configuration';
	const COURSE_OPTION = 'gb_course_registry';

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'define_core_constants' ), 1 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_gb_create_course_shells', array( $this, 'create_course_shells' ) );
		add_action( 'admin_post_gb_build_adult_english_shells', array( $this, 'build_adult_english_shells' ) );
		add_action( 'admin_post_gb_build_adult_topic_one', array( $this, 'build_adult_topic_one' ) );
		add_action( 'admin_post_gb_build_adult_topic_two', array( $this, 'build_adult_topic_two' ) );
		add_action( 'admin_post_gb_build_adult_topic_three', array( $this, 'build_adult_topic_three' ) );
		add_action( 'admin_post_gb_build_adult_topic_four', array( $this, 'build_adult_topic_four' ) );
		add_action( 'admin_post_gb_build_adult_topic_five', array( $this, 'build_adult_topic_five' ) );
		add_action( 'init', array( $this, 'install_seat_time_schema' ), 5 );
		add_action( 'init', array( $this, 'block_early_lesson_completion' ), 0 );
		add_action( 'wp_ajax_gb_seat_start', array( $this, 'ajax_seat_start' ) );
		add_action( 'wp_ajax_gb_seat_heartbeat', array( $this, 'ajax_seat_heartbeat' ) );
		add_action( 'wp_ajax_gb_seat_complete', array( $this, 'ajax_seat_complete' ) );
		add_filter( 'the_content', array( $this, 'inject_seat_time_controller' ), 20 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'admin_init', array( $this, 'protect_admin_boundary' ), 0 );
		add_action( 'after_setup_theme', array( $this, 'hide_admin_toolbar' ) );
		add_filter( 'show_admin_bar', array( $this, 'filter_admin_toolbar' ), 99 );
		add_action( 'template_redirect', array( $this, 'protect_internal_course_objects' ), 0 );
		add_filter( 'wp_sitemaps_post_types', array( $this, 'exclude_lms_from_sitemaps' ) );
		add_filter( 'wp_robots', array( $this, 'noindex_lms_objects' ) );
		add_shortcode( 'gb_setting', array( $this, 'setting_shortcode' ) );
	}

	public function define_core_constants() {
		if ( ! defined( 'GB_CORE_VERSION' ) ) {
			define( 'GB_CORE_VERSION', '1.5.0' );
		}
	}

	private function seat_time_table() {
		global $wpdb;
		return $wpdb->prefix . 'gb_seat_time';
	}

	private function refresh_learnpress_course_cache( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return;
		}
		clean_post_cache( $course_id );
		if ( class_exists( '\\LearnPress\\Models\\CourseModel' ) ) {
			$model = \LearnPress\Models\CourseModel::find( $course_id, false );
			if ( $model && method_exists( $model, 'clean_caches' ) ) {
				$model->clean_caches();
			}
		}
		wp_update_post( array( 'ID' => $course_id, 'post_status' => 'draft' ) );
		clean_post_cache( $course_id );
	}

	public function install_seat_time_schema() {
		if ( '1.0' === get_option( 'gb_seat_time_schema_version' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $this->seat_time_table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL,
			required_seconds int(10) unsigned NOT NULL DEFAULT 0,
			active_seconds int(10) unsigned NOT NULL DEFAULT 0,
			started_at_utc datetime NOT NULL,
			last_seen_utc datetime NOT NULL,
			completed_at_utc datetime NULL,
			ip_address varchar(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY user_course_lesson (user_id,course_id,lesson_id),
			KEY completed_at_utc (completed_at_utc)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'gb_seat_time_schema_version', '1.0', false );
	}

	private function regulated_lesson_context( $lesson_id, $course_id ) {
		$lesson_id = absint( $lesson_id );
		$course_id = absint( $course_id );
		if ( ! $lesson_id || ! $course_id || 'adult_en' !== get_post_meta( $lesson_id, '_gb_course_key', true ) ) {
			return false;
		}
		$registry = get_option( self::COURSE_OPTION, array() );
		if ( $course_id !== absint( $registry['adult_en']['learnpress_course_id'] ?? 0 ) ) {
			return false;
		}
		$required = absint( get_post_meta( $lesson_id, '_gb_required_seconds', true ) );
		if ( ! $required ) {
			return false;
		}
		global $wpdb;
		$attached = absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT si.section_id FROM {$wpdb->learnpress_section_items} si INNER JOIN {$wpdb->learnpress_sections} s ON s.section_id = si.section_id WHERE si.item_id = %d AND s.section_course_id = %d LIMIT 1",
			$lesson_id,
			$course_id
		) ) );
		return $attached ? array( 'lesson_id' => $lesson_id, 'course_id' => $course_id, 'required_seconds' => $required ) : false;
	}

	private function posted_seat_context() {
		check_ajax_referer( 'gb_seat_time', 'nonce' );
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Student session required.' ), 403 );
		}
		$context = $this->regulated_lesson_context( $_POST['lesson_id'] ?? 0, $_POST['course_id'] ?? 0 );
		if ( ! $context ) {
			wp_send_json_error( array( 'message' => 'Invalid regulated lesson.' ), 400 );
		}
		return $context;
	}

	private function get_seat_record( $user_id, $course_id, $lesson_id ) {
		global $wpdb;
		$table = $this->seat_time_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d AND lesson_id = %d LIMIT 1", $user_id, $course_id, $lesson_id ), ARRAY_A );
	}

	public function ajax_seat_start() {
		$context = $this->posted_seat_context();
		$user_id = get_current_user_id();
		$record  = $this->get_seat_record( $user_id, $context['course_id'], $context['lesson_id'] );
		if ( ! $record ) {
			global $wpdb;
			$now = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert( $this->seat_time_table(), array(
				'user_id' => $user_id, 'course_id' => $context['course_id'], 'lesson_id' => $context['lesson_id'],
				'required_seconds' => $context['required_seconds'], 'active_seconds' => 0,
				'started_at_utc' => $now, 'last_seen_utc' => $now,
				'ip_address' => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'user_agent' => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
			), array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ) );
			$record = $this->get_seat_record( $user_id, $context['course_id'], $context['lesson_id'] );
		}
		wp_send_json_success( array(
			'required' => absint( $record['required_seconds'] ),
			'active' => absint( $record['active_seconds'] ),
			'completed' => ! empty( $record['completed_at_utc'] ),
		) );
	}

	public function ajax_seat_heartbeat() {
		$context = $this->posted_seat_context();
		$user_id = get_current_user_id();
		$record  = $this->get_seat_record( $user_id, $context['course_id'], $context['lesson_id'] );
		if ( ! $record ) {
			wp_send_json_error( array( 'message' => 'Seat-time session has not started.' ), 409 );
		}
		if ( ! empty( $record['completed_at_utc'] ) ) {
			wp_send_json_success( array( 'required' => absint( $record['required_seconds'] ), 'active' => absint( $record['active_seconds'] ), 'completed' => true ) );
		}
		$now_ts  = time();
		$last_ts = strtotime( $record['last_seen_utc'] . ' UTC' );
		$delta   = max( 0, $now_ts - $last_ts );
		$active  = absint( $record['active_seconds'] );
		if ( $delta >= 20 && ! empty( $_POST['active'] ) ) {
			$active = min( absint( $record['required_seconds'] ), $active + min( 30, $delta ) );
			global $wpdb;
			$wpdb->update( $this->seat_time_table(), array( 'active_seconds' => $active, 'last_seen_utc' => gmdate( 'Y-m-d H:i:s', $now_ts ) ), array( 'id' => absint( $record['id'] ) ), array( '%d', '%s' ), array( '%d' ) );
		}
		wp_send_json_success( array( 'required' => absint( $record['required_seconds'] ), 'active' => $active, 'completed' => false ) );
	}

	public function ajax_seat_complete() {
		$context = $this->posted_seat_context();
		$user_id = get_current_user_id();
		$record  = $this->get_seat_record( $user_id, $context['course_id'], $context['lesson_id'] );
		if ( ! $record ) {
			wp_send_json_error( array( 'message' => 'Seat-time session has not started.' ), 409 );
		}
		$elapsed = max( 0, time() - strtotime( $record['started_at_utc'] . ' UTC' ) );
		$required = absint( $record['required_seconds'] );
		if ( absint( $record['active_seconds'] ) < $required || $elapsed < $required ) {
			wp_send_json_error( array( 'message' => 'Required active instructional time has not been completed.', 'remaining' => max( 0, $required - absint( $record['active_seconds'] ) ) ), 403 );
		}
		$lp_result = true;
		if ( empty( $record['completed_at_utc'] ) && function_exists( 'learn_press_get_user' ) ) {
			$lp_user = learn_press_get_user( $user_id );
			if ( $lp_user && method_exists( $lp_user, 'complete_lesson' ) ) {
				$lp_result = $lp_user->complete_lesson( $context['lesson_id'], $context['course_id'] );
			}
		}
		if ( is_wp_error( $lp_result ) ) {
			wp_send_json_error( array( 'message' => $lp_result->get_error_message() ), 409 );
		}
		global $wpdb;
		$wpdb->update( $this->seat_time_table(), array( 'completed_at_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => absint( $record['id'] ) ), array( '%s' ), array( '%d' ) );
		wp_send_json_success( array( 'required' => $required, 'active' => absint( $record['active_seconds'] ), 'completed' => true ) );
	}

	public function block_early_lesson_completion() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'user_complete_lesson' !== ( $_POST['lp-load-ajax'] ?? '' ) ) {
			return;
		}
		$context = $this->regulated_lesson_context( $_POST['lesson_id'] ?? 0, $_POST['course_id'] ?? 0 );
		if ( ! $context || current_user_can( 'manage_options' ) ) {
			return;
		}
		$record = $this->get_seat_record( get_current_user_id(), $context['course_id'], $context['lesson_id'] );
		if ( ! $record || empty( $record['completed_at_utc'] ) ) {
			wp_die( esc_html__( 'Required active instructional time must be completed before this lesson can be marked complete.', 'gulf-breeze-core' ), esc_html__( 'Lesson time incomplete', 'gulf-breeze-core' ), array( 'response' => 403 ) );
		}
	}

	public function inject_seat_time_controller( $content ) {
		if ( is_admin() || ! is_user_logged_in() || current_user_can( 'manage_options' ) || ! class_exists( 'LP_Global' ) || ! function_exists( 'learn_press_get_course' ) ) {
			return $content;
		}
		$item   = LP_Global::course_item();
		$course = learn_press_get_course();
		if ( ! $item || ! $course || ! method_exists( $item, 'get_id' ) || ! method_exists( $course, 'get_id' ) ) {
			return $content;
		}
		$context = $this->regulated_lesson_context( $item->get_id(), $course->get_id() );
		if ( ! $context ) {
			return $content;
		}
		$config = array(
			'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'gb_seat_time' ),
			'lesson' => $context['lesson_id'], 'course' => $context['course_id'], 'required' => $context['required_seconds'],
		);
		$timer = '<section id="gb-seat-time" style="border:1px solid #c8d6e5;border-radius:12px;padding:16px;margin:0 0 22px;background:#f7fbff" aria-live="polite"><strong>Required active study time</strong><p id="gb-seat-status" style="margin:6px 0 0">Connecting to the secure course timer…</p><progress id="gb-seat-progress" value="0" max="' . esc_attr( $context['required_seconds'] ) . '" style="width:100%;height:14px"></progress><p style="font-size:13px;margin:8px 0 0">Time is credited only while this lesson is visible and you remain active. Your progress is saved securely.</p></section>';
		$script = '<script>(function(){const c=' . wp_json_encode( $config ) . ',box=document.getElementById("gb-seat-time"),status=document.getElementById("gb-seat-status"),bar=document.getElementById("gb-seat-progress");if(!box)return;let active=0,required=c.required,lastActivity=Date.now(),finishing=false;const fmt=s=>Math.floor(s/60)+":"+String(s%60).padStart(2,"0");const draw=done=>{bar.max=required;bar.value=Math.min(active,required);status.textContent=done?"Required study time completed.":"Server-confirmed study time: "+fmt(active)+" of "+fmt(required);document.querySelectorAll(".button-complete-lesson").forEach(b=>{b.disabled=!done;b.style.display=done?"":"none";});};const post=(action,extra={})=>fetch(c.ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action,nonce:c.nonce,lesson_id:c.lesson,course_id:c.course,...extra})}).then(r=>r.json());const finish=()=>{if(finishing)return;finishing=true;post("gb_seat_complete").then(j=>{if(j.success){active=j.data.active;draw(true);window.location.reload();}else{finishing=false;}}).catch(()=>{finishing=false;});};["pointerdown","keydown","scroll","touchstart"].forEach(e=>addEventListener(e,()=>{lastActivity=Date.now();},{passive:true}));draw(false);post("gb_seat_start").then(j=>{if(!j.success)throw 0;required=j.data.required;active=j.data.active;draw(j.data.completed);if(j.data.completed)return;setInterval(()=>{const engaged=!document.hidden&&(Date.now()-lastActivity)<90000;if(!engaged)return;post("gb_seat_heartbeat",{active:"1"}).then(h=>{if(!h.success)return;active=h.data.active;draw(h.data.completed);if(h.data.completed||active>=required)finish();});},30000);}).catch(()=>{status.textContent="The secure course timer could not connect. Refresh the lesson before continuing.";});})();</script>';
		return $timer . $content . $script;
	}

	public function filter_admin_toolbar( $show ) {
		return current_user_can( 'manage_options' ) ? $show : false;
	}

	public function hide_admin_toolbar() {
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			show_admin_bar( false );
		}
	}

	public function protect_admin_boundary() {
		if ( current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	public function protect_internal_course_objects() {
		if ( current_user_can( 'manage_options' ) || ! is_singular( array( 'lp_course', 'lp_lesson', 'lp_quiz' ) ) ) {
			return;
		}
		$object_id  = get_queried_object_id();
		$course_key = get_post_meta( $object_id, '_gb_course_key', true );
		if ( $course_key && array_key_exists( $course_key, self::course_definitions() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public function exclude_lms_from_sitemaps( $post_types ) {
		unset( $post_types['lp_course'], $post_types['lp_lesson'], $post_types['lp_quiz'], $post_types['lp_question'] );
		return $post_types;
	}

	public function noindex_lms_objects( $robots ) {
		if ( is_singular( array( 'lp_course', 'lp_lesson', 'lp_quiz', 'lp_question' ) ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function schema() {
		return array(
			'business' => array(
				'label'  => 'Business Identity',
				'fields' => array(
					'legal_name'       => array( 'label' => 'Exact legal business name', 'required' => true ),
					'public_name'      => array( 'label' => 'Public/approved provider name', 'required' => true ),
					'owner_name'       => array( 'label' => 'Owner name', 'required' => true ),
					'entity_type'      => array( 'label' => 'Entity type' ),
					'assumed_name'     => array( 'label' => 'Assumed name / DBA' ),
					'federal_ein_last4'=> array( 'label' => 'EIN last four digits only', 'maxlength' => 4 ),
				),
			),
			'licensing' => array(
				'label'  => 'TDLR Approval and Course Identity',
				'fields' => array(
					'provider_number'          => array( 'label' => 'TDLR provider/license number' ),
					'ptde_en_approval'         => array( 'label' => 'Parent-Taught English approval/course identifier' ),
					'ptde_es_approval'         => array( 'label' => 'Parent-Taught Spanish approval/course identifier' ),
					'adult_en_approval'        => array( 'label' => 'Adult Six-Hour English approval/course identifier' ),
					'adult_es_approval'        => array( 'label' => 'Adult Six-Hour Spanish approval/course identifier' ),
					'license_effective_date'   => array( 'label' => 'License effective date', 'type' => 'date' ),
					'license_expiration_date'  => array( 'label' => 'License expiration date', 'type' => 'date' ),
				),
			),
			'contact' => array(
				'label'  => 'Business and Student Support',
				'fields' => array(
					'business_address_1' => array( 'label' => 'Business address line 1' ),
					'business_address_2' => array( 'label' => 'Business address line 2' ),
					'business_city'      => array( 'label' => 'City' ),
					'business_state'     => array( 'label' => 'State', 'default' => 'Texas' ),
					'business_zip'       => array( 'label' => 'ZIP code' ),
					'mailing_same'       => array( 'label' => 'Mailing address is the same', 'type' => 'checkbox' ),
					'mailing_address'    => array( 'label' => 'Mailing address, if different', 'type' => 'textarea' ),
					'phone'              => array( 'label' => 'Public phone number' ),
					'support_email'      => array( 'label' => 'Student support email', 'type' => 'email' ),
					'legal_email'        => array( 'label' => 'Legal/compliance email', 'type' => 'email' ),
					'support_hours_en'   => array( 'label' => 'Support hours — English', 'type' => 'textarea' ),
					'support_hours_es'   => array( 'label' => 'Support hours — Spanish', 'type' => 'textarea' ),
				),
			),
			'policies' => array(
				'label'  => 'Policy and Complaint Details',
				'fields' => array(
					'contract_version'       => array( 'label' => 'Current enrollment-contract version' ),
					'privacy_version'        => array( 'label' => 'Current privacy-policy version' ),
					'refund_version'         => array( 'label' => 'Current cancellation/refund-policy version' ),
					'grievance_version'      => array( 'label' => 'Current grievance-procedure version' ),
					'tdlr_complaint_text_en' => array( 'label' => 'Required TDLR complaint notice — English', 'type' => 'textarea' ),
					'tdlr_complaint_text_es' => array( 'label' => 'Required TDLR complaint notice — Spanish', 'type' => 'textarea' ),
				),
			),
			'operations' => array(
				'label'  => 'Operational Defaults',
				'fields' => array(
					'timezone'                => array( 'label' => 'Compliance timezone', 'default' => 'America/Chicago', 'readonly' => true ),
					'certificate_signer_name' => array( 'label' => 'Authorized certificate signer name' ),
					'certificate_signer_title'=> array( 'label' => 'Authorized certificate signer title' ),
					'record_retention_note'   => array( 'label' => 'Internal record-retention note', 'type' => 'textarea', 'private' => true ),
				),
			),
		);
	}

	public static function course_definitions() {
		return array(
			'ptde_en' => array(
				'label'               => 'Parent-Taught Driver Education — English',
				'program'             => 'parent_taught',
				'language'            => 'English',
				'total_minutes'       => 1440,
				'instruction_minutes' => 1320,
				'other_minutes'       => 120,
				'modules'             => 12,
				'approval_key'        => 'ptde_en_approval',
			),
			'ptde_es' => array(
				'label'               => 'Parent-Taught Driver Education — Spanish',
				'program'             => 'parent_taught',
				'language'            => 'Spanish',
				'total_minutes'       => 1440,
				'instruction_minutes' => 1320,
				'other_minutes'       => 120,
				'modules'             => 12,
				'approval_key'        => 'ptde_es_approval',
			),
			'adult_en' => array(
				'label'               => 'Online Adult Six-Hour — English',
				'program'             => 'adult_six_hour',
				'language'            => 'English',
				'total_minutes'       => 360,
				'instruction_minutes' => 330,
				'other_minutes'       => 30,
				'modules'             => null,
				'approval_key'        => 'adult_en_approval',
			),
			'adult_es' => array(
				'label'               => 'Online Adult Six-Hour — Spanish',
				'program'             => 'adult_six_hour',
				'language'            => 'Spanish',
				'total_minutes'       => 360,
				'instruction_minutes' => 330,
				'other_minutes'       => 30,
				'modules'             => null,
				'approval_key'        => 'adult_es_approval',
			),
		);
	}

	public static function curriculum_blueprints() {
		return array(
			'parent_taught' => array(
				'source'         => 'POI-DE — May 2026 adopted edition',
				'source_url'     => 'https://www.sos.state.tx.us/texreg/archive/April242026/In%20Addition/202601592-1.pdf',
				'total_minutes'  => 1440,
				'minimum_content'=> 1320,
				'flex_minutes'   => 120,
				'units'          => array(
					'1'  => 'Traffic Laws',
					'2'  => 'Driver Preparation',
					'3'  => 'Vehicle Movements',
					'4'  => 'Driver Readiness',
					'5'  => 'Risk Reduction (Management)',
					'6'  => 'Environmental Factors',
					'7'  => 'Distractions',
					'8'  => 'Alcohol and Other Drugs',
					'9'  => 'Adverse Conditions',
					'10' => 'Vehicle Requirements',
					'11' => 'Consumer Responsibilities',
					'12' => 'Personal Responsibilities',
				),
				'work_zone_units' => array( '3', '6' ),
			),
			'adult_six_hour' => array(
				'source'         => 'POI-Adult Six-Hour — May 2026 adopted edition',
				'source_url'     => 'https://www.sos.state.tx.us/texreg/archive/April242026/In%20Addition/202601590-1.pdf',
				'total_minutes'  => 360,
				'minimum_content'=> 330,
				'flex_minutes'   => 30,
				'units'          => array(
					'4.1.1' => 'Course Introduction',
					'4.1.2' => 'Your License to Drive',
					'4.1.3' => 'Right-of-Way',
					'4.1.4' => 'Traffic Control Devices',
					'4.1.5' => 'Controlling Traffic Flow',
					'4.1.6' => 'Alcohol and Other Drugs',
					'4.1.7' => 'Cooperating with Other Roadway Users',
					'4.1.8' => 'Managing Risk',
					'4.1.9' => 'Classroom Progress Assessment',
				),
				'work_zone_units' => array( '4.1.7' ),
			),
		);
	}

	public static function adult_english_crosswalk() {
		return array(
			array( 'topic' => '4.1.1', 'lesson' => 'Welcome and Course Purpose', 'minutes' => 2, 'coverage' => '4.1.1.1(A)' ),
			array( 'topic' => '4.1.1', 'lesson' => 'How the Online Course Works', 'minutes' => 3, 'coverage' => 'Orientation and logical navigation' ),
			array( 'topic' => '4.1.1', 'lesson' => 'Course Rules, Identity, Seat Time, and Completion', 'minutes' => 3, 'coverage' => 'Online participation and security requirements' ),
			array( 'topic' => '4.1.1', 'lesson' => 'Driving as a Privilege and Lifelong Responsibility', 'minutes' => 2, 'coverage' => '4.1.1.1(B)–(C)' ),
			array( 'topic' => '4.1.2', 'lesson' => 'Applying for a Texas Driver License', 'minutes' => 4, 'coverage' => '4.1.2.1(A)' ),
			array( 'topic' => '4.1.2', 'lesson' => 'License Classes, Restrictions, and Endorsements', 'minutes' => 5, 'coverage' => '4.1.2.1(B)' ),
			array( 'topic' => '4.1.2', 'lesson' => 'Suspensions, Revocations, and Renewal', 'minutes' => 5, 'coverage' => '4.1.2.1(A)–(C)' ),
			array( 'topic' => '4.1.2', 'lesson' => 'Vehicle Registration and Financial Responsibility', 'minutes' => 5, 'coverage' => '4.1.2.1(D)–(F)' ),
			array( 'topic' => '4.1.2', 'lesson' => 'Texas Driving with Disabilities Program', 'minutes' => 5, 'coverage' => '4.1.2.1(G)' ),
			array( 'topic' => '4.1.3', 'lesson' => 'What Right-of-Way Means', 'minutes' => 5, 'coverage' => '4.1.3.1(A)' ),
			array( 'topic' => '4.1.3', 'lesson' => 'Controlled and Uncontrolled Intersections', 'minutes' => 8, 'coverage' => '4.1.3.1(B)–(C)' ),
			array( 'topic' => '4.1.3', 'lesson' => 'Turns, T-Intersections, Circles, and Entering Traffic', 'minutes' => 7, 'coverage' => '4.1.3.1(C)' ),
			array( 'topic' => '4.1.3', 'lesson' => 'Pedestrians, Crosswalks, and School Crossings', 'minutes' => 7, 'coverage' => '4.1.3.1(D)' ),
			array( 'topic' => '4.1.3', 'lesson' => 'School Buses, Emergency Vehicles, and Move Over or Slow Down', 'minutes' => 8, 'coverage' => '4.1.3.1(D)–(E)' ),
			array( 'topic' => '4.1.3', 'lesson' => 'Railroad Crossings and Right-of-Way Decisions', 'minutes' => 7, 'coverage' => '4.1.3.1(C), (F)–(G)' ),
			array( 'topic' => '4.1.4', 'lesson' => 'Why Traffic Control Devices Matter', 'minutes' => 4, 'coverage' => '4.1.4.1(C)–(D)' ),
			array( 'topic' => '4.1.4', 'lesson' => 'Regulatory Signs', 'minutes' => 6, 'coverage' => '4.1.4.1(A)–(B)' ),
			array( 'topic' => '4.1.4', 'lesson' => 'Warning Signs', 'minutes' => 6, 'coverage' => '4.1.4.1(A)–(B)' ),
			array( 'topic' => '4.1.4', 'lesson' => 'Guide, School-Zone, and Work-Zone Signs', 'minutes' => 6, 'coverage' => '4.1.4.1(A)–(B)' ),
			array( 'topic' => '4.1.4', 'lesson' => 'Traffic Signals and Flashing Signals', 'minutes' => 8, 'coverage' => '4.1.4.1(B)–(D)' ),
			array( 'topic' => '4.1.4', 'lesson' => 'Pavement and Lane-Control Markings', 'minutes' => 7, 'coverage' => '4.1.4.1(B)–(D)' ),
			array( 'topic' => '4.1.5', 'lesson' => 'Speed Control, Following Distance, and Stopping', 'minutes' => 7, 'coverage' => '4.1.5.1(F)–(I)' ),
			array( 'topic' => '4.1.5', 'lesson' => 'Lane Position, Lane Changes, and Blind Spots', 'minutes' => 6, 'coverage' => '4.1.5.1(A)–(F)' ),
			array( 'topic' => '4.1.5', 'lesson' => 'Turning, Signaling, and Communication', 'minutes' => 6, 'coverage' => '4.1.5.1(B)–(D)' ),
			array( 'topic' => '4.1.5', 'lesson' => 'Passing and Being Passed', 'minutes' => 6, 'coverage' => '4.1.5.1(D)' ),
			array( 'topic' => '4.1.5', 'lesson' => 'Parking, Backing, Freeway Travel, Emergencies, and Work Zones', 'minutes' => 7, 'coverage' => '4.1.5.1(D), (K)–(Q)' ),
			array( 'topic' => '4.1.6', 'lesson' => 'Zero-Tolerance Driving Decisions', 'minutes' => 6, 'coverage' => '4.1.6.1(G)' ),
			array( 'topic' => '4.1.6', 'lesson' => 'Alcohol, BAC, and Impairment', 'minutes' => 8, 'coverage' => '4.1.6.1(A)–(B)' ),
			array( 'topic' => '4.1.6', 'lesson' => 'Drugs, Prescriptions, Cannabis, and Mixed Substances', 'minutes' => 7, 'coverage' => '4.1.6.1(B)' ),
			array( 'topic' => '4.1.6', 'lesson' => 'Texas DWI Laws, Penalties, and Consequences', 'minutes' => 10, 'coverage' => '4.1.6.1(C)–(F)' ),
			array( 'topic' => '4.1.6', 'lesson' => 'Planning a Safe Ride', 'minutes' => 6, 'coverage' => '4.1.6.1(G)' ),
			array( 'topic' => '4.1.7', 'lesson' => 'Pedestrians, Bicyclists, Motorcyclists, and Other Roadway Users', 'minutes' => 8, 'coverage' => '4.1.7.1(A), (C)–(E), (K)' ),
			array( 'topic' => '4.1.7', 'lesson' => 'Large and Oversize Vehicles, Buses, and Emergency Vehicles', 'minutes' => 8, 'coverage' => '4.1.7.1(C), (L)' ),
			array( 'topic' => '4.1.7', 'lesson' => 'Construction and Maintenance Work-Zone Safety', 'minutes' => 7, 'coverage' => '4.1.7.1(C); SB 1366 safe driving, flaggers/signs, penalties and dangers' ),
			array( 'topic' => '4.1.7', 'lesson' => 'Crash Responsibilities and the Good Samaritan Law', 'minutes' => 6, 'coverage' => '4.1.7.1(B)' ),
			array( 'topic' => '4.1.7', 'lesson' => 'Road Rage, Courtesy, Cargo, Towing, and Carbon Monoxide', 'minutes' => 8, 'coverage' => '4.1.7.1(H)–(J)' ),
			array( 'topic' => '4.1.7', 'lesson' => 'Traffic Stops and the Community Safety Education Act Video', 'minutes' => 16, 'coverage' => '4.1.7.1(F)–(G)' ),
			array( 'topic' => '4.1.8', 'lesson' => 'The Risk Formula: Driver, Vehicle, Roadway, and Environment', 'minutes' => 5, 'coverage' => '4.1.8.1(A)–(C)' ),
			array( 'topic' => '4.1.8', 'lesson' => 'Distractions, Cell Phones, Fatigue, and Inattention', 'minutes' => 7, 'coverage' => '4.1.8.1(C)–(D), (H)–(I)' ),
			array( 'topic' => '4.1.8', 'lesson' => 'Night Driving, Weather, Skids, and Emergencies', 'minutes' => 7, 'coverage' => '4.1.8.1(C), (G), (I)' ),
			array( 'topic' => '4.1.8', 'lesson' => 'Aggressive Driving, Street Racing, and High-Risk Choices', 'minutes' => 6, 'coverage' => '4.1.8.1(A)–(B), (E)–(I)' ),
			array( 'topic' => '4.1.8', 'lesson' => 'Recognizing and Reporting Human Trafficking', 'minutes' => 6, 'coverage' => '4.1.8.1(J)' ),
			array( 'topic' => '4.1.8', 'lesson' => 'Recreational Water Safety Video', 'minutes' => 12, 'coverage' => '§84.500 educational objective' ),
			array( 'topic' => '4.1.9', 'lesson' => 'Highway Sign Review', 'minutes' => 10, 'coverage' => 'Pre-exam instructional review' ),
			array( 'topic' => '4.1.9', 'lesson' => 'Traffic Law Review', 'minutes' => 20, 'coverage' => 'Pre-exam instructional review' ),
			array( 'topic' => '4.1.9', 'lesson' => 'Comprehensive Licensing Readiness Review', 'minutes' => 22, 'coverage' => 'Pre-exam summation and review' ),
		);
	}

	private static function timed_practice( $key ) {
		$sets = array(
			'adult_en_001' => array(
				'heading' => 'Build Your Safe-Driver Foundation',
				'intro' => 'Use these situations to connect course knowledge with the decisions a responsible driver must make. Read each situation, decide what you would do, and then open the explanation.',
				'cases' => array(
					array( 'A friend says that passing the written and road tests means a new driver has finished learning. What is missing from that statement?', 'Licensing tests measure minimum knowledge and skill at a point in time. Safe driving still requires supervised practice, continued observation, honest self-evaluation, and experience in gradually more complex conditions.' ),
					array( 'Name one personal reason for becoming a safe driver and one person besides yourself who may be affected by your choices.', 'The particular answer is personal. A complete response recognizes that driving choices affect passengers, pedestrians, other drivers, family members, employers, emergency responders, and the community—not only the driver.' ),
				),
			),
			'adult_en_002' => array(
				'heading' => 'Practice the Course Process',
				'intro' => 'The online format divides the required instruction into short lessons, but every lesson remains part of one regulated course ledger.',
				'cases' => array(
					array( 'You finish reading before the assigned lesson time has elapsed. What should you do?', 'Review the examples, revisit the official-source links, complete the guided practice, and identify any rule you could not explain without looking. Opening another tab or leaving the page does not turn waiting time into instruction.' ),
					array( 'You remember a rule differently from an older handbook saved on your phone. Which source controls?', 'Use the current official Texas source supplied with the course. Laws and agency procedures change. Report an apparent conflict to the school rather than guessing or relying on an older copy.' ),
					array( 'A scheduled break begins after a topic. Can it be counted toward that topic’s instructional minutes?', 'No. The course ledger separates required instructional time, scheduled breaks, and the comprehensive final examination.' ),
				),
			),
			'adult_en_003' => array(
				'heading' => 'Protect the Integrity of Your Record',
				'intro' => 'A compliant record must show that the enrolled student personally participated for the required time and demonstrated knowledge.',
				'cases' => array(
					array( 'A family member offers to answer several questions while you make a phone call. Why is that unacceptable?', 'The enrolled student must personally complete the instruction and assessments. Shared answers make the participation record inaccurate and can invalidate completion.' ),
					array( 'The lesson is visible, but there has been no keyboard, pointer, scrolling, or touch activity for an extended period. Should time continue?', 'No. The Gulf Breeze timer requires both page visibility and recent activity. The server—not the device clock—records credited active seconds.' ),
					array( 'You miss a required question linked to a multimedia segment. What may the course require?', 'The segment may have to be replayed before a different question is presented. This supports actual review instead of memorizing one answer.' ),
				),
			),
			'adult_en_004' => array(
				'heading' => 'Apply the Reduced-Risk Decision Process',
				'intro' => 'For each situation, identify the law, the developing hazard, who may be harmed, and the safest legal response.',
				'cases' => array(
					array( 'Your light turns green while a pedestrian is finishing the crossing. A driver behind you honks. What controls your decision?', 'The pedestrian’s safety and the duty to yield control—not pressure from the driver behind. Wait until the path is clear.' ),
					array( 'You have the legal right-of-way, but another vehicle is entering your lane. Does being legally correct remove your responsibility to react?', 'No. Brake, create space, or take another reasonable evasive action. Right-of-way rules organize traffic; they do not authorize a preventable collision.' ),
				),
			),
			'adult_en_005' => array(
				'heading' => 'Licensing Decision Workshop',
				'intro' => 'Licensing requirements depend on the applicant and transaction. Work through the distinctions instead of memorizing one applicant’s checklist.',
				'cases' => array(
					array( 'A 20-year-old first-time applicant asks whether the adult six-hour course is optional. What should the applicant verify?', 'Texas generally requires the six-hour adult course for a first-time applicant age 18 through 24 unless an exception applies. The applicant should confirm current DPS eligibility and document requirements for the specific transaction.' ),
					array( 'A 27-year-old first-time applicant wants supervised practice before the road test. Does age alone authorize practice on public roads?', 'No. The applicant must obtain and obey the credential and restriction DPS requires for supervised practice. Course completion alone is not driving authority.' ),
					array( 'An applicant arrives with identity documents but owns an uninsured, unregistered vehicle. Why can that still affect the transaction or driving test?', 'Driver eligibility and vehicle legality are separate. DPS may require registration and insurance evidence, and any vehicle used for testing must meet the testing authority’s current requirements.' ),
					array( 'A new resident has a valid, unexpired license from another state. Should the person assume the first-time Texas checklist applies unchanged?', 'No. New-resident procedures can differ. Use the current DPS instructions for transferring an out-of-state credential.' ),
				),
			),
			'adult_en_006' => array(
				'heading' => 'Class, Restriction, and Endorsement Practice',
				'intro' => 'Authorization depends on the actual vehicle, its use, and the exact credential—not what the vehicle looks like.',
				'cases' => array(
					array( 'A driver with a Class C license is asked to operate a large passenger van for pay. What must be checked before accepting?', 'Check passenger capacity, weight ratings, commercial use, and applicable state and federal requirements. A familiar steering wheel does not mean the existing class is sufficient.' ),
					array( 'A restriction requires corrective lenses, but the driver can read nearby text without them. May the driver ignore it for a short trip?', 'No. The restriction remains part of the legal privilege until DPS officially changes it.' ),
					array( 'A person has years of motorcycle riding experience on private property but no motorcycle authorization. Is experience an endorsement?', 'No. Experience does not replace the required application, testing, and licensing process.' ),
					array( 'Before towing an unfamiliar trailer, what should be examined?', 'Review vehicle and combination weight ratings, trailer type, use, license class, endorsements or exemptions, vehicle equipment, registration, and insurance.' ),
				),
			),
			'adult_en_007' => array(
				'heading' => 'License-Status Practice',
				'intro' => 'The physical card and the legal status of the privilege are not always the same.',
				'cases' => array(
					array( 'The card’s expiration date is next year, but the driver received a suspension notice. Which status controls?', 'The suspension controls. Possessing an unexpired-looking card does not authorize driving while the privilege is suspended.' ),
					array( 'A friend says paying one fee automatically reinstates every case. Why is that unsafe advice?', 'Reinstatement requirements are individualized and may involve a period of ineligibility, fees, court compliance, education, an SR-22 filing, or other documents. Verify the official DPS eligibility record.' ),
					array( 'An SR-22 is required. Can the driver carry an ordinary insurance card instead?', 'No. An SR-22 is a certificate filed by an authorized insurer when specifically required. Ordinary proof of insurance does not replace that filing.' ),
					array( 'A driver renews the plastic card while an enforcement action is unresolved. Did renewal erase the action?', 'No. Renewal and eligibility are separate. The driver must resolve the enforcement requirements shown by DPS.' ),
				),
			),
			'adult_en_008' => array(
				'heading' => 'Vehicle-Legality Checklist',
				'intro' => 'Before driving, verify the driver and the vehicle as separate legal systems.',
				'cases' => array(
					array( 'A noncommercial passenger vehicle is registered in a county without emissions testing. Must the owner obtain the former annual safety inspection?', 'Texas eliminated the annual safety inspection for most noncommercial vehicles beginning in 2025. Commercial safety inspections and emissions inspections for applicable vehicles in designated counties continue.' ),
					array( 'A liability policy lapsed yesterday, but the registration sticker is current. Is the vehicle ready to drive?', 'No. Registration does not replace financial responsibility. Confirm active coverage before driving.' ),
					array( 'What does 30/60/25 describe, and what does it not guarantee?', 'It describes Texas minimum liability limits commonly stated as $30,000 injury to one person, $60,000 total injury per crash, and $25,000 property damage. It does not guarantee payment for every loss to the insured driver or vehicle.' ),
					array( 'A driver submits an insurance application and payment. When is it safe to assume coverage began?', 'Only after the insurer confirms an effective policy and effective date. An application or attempted payment by itself may not establish coverage.' ),
				),
			),
			'adult_en_009' => array(
				'heading' => 'Communication-Support Practice',
				'intro' => 'The Driving with Disability Program offers voluntary communication tools. It does not change the duty to obey traffic law.',
				'cases' => array(
					array( 'A qualifying person wants an indicator on the front of a driver license. Which agency process applies?', 'Use the current DPS process, including the required in-person transaction documents and DL-101 completed by the appropriate healthcare provider.' ),
					array( 'A person wants information connected to a vehicle record so an officer may receive an alert through TLETS. Is that the same request?', 'No. That is the separate voluntary TxDMV vehicle-registration disclosure process.' ),
					array( 'Does an indicator permit a driver to disregard an officer’s lawful instruction during a stop?', 'No. It alerts the officer to a potential communication need. The driver should keep hands visible, avoid sudden movements, and follow lawful instructions using the safest available communication method.' ),
					array( 'May another person diagnose a communication disability from unusual behavior during a traffic encounter?', 'No. Avoid assumptions. The program relies on voluntary disclosure and required documentation, and individual communication needs differ.' ),
				),
			),
			'adult_en_010' => array(
				'heading' => 'Right-of-Way Decision Lab',
				'intro' => 'Right-of-way decisions must combine the legal rule with a fresh search for actual danger.',
				'cases' => array(
					array( 'You are first at a stop, but a bicyclist enters the conflict area unexpectedly. What should you do?', 'Wait or stop again. Being first does not authorize movement into an occupied path.' ),
					array( 'An approaching motorcycle looks far away. Why should you delay a quick left-turn judgment?', 'A motorcycle’s smaller profile can make speed and distance harder to judge. Look twice and turn only with a clearly safe gap.' ),
					array( 'Another driver waves you across two traffic lanes. What must you verify?', 'The gesture covers only that driver’s intention. Search every lane, the sidewalk, and all other conflict paths yourself.' ),
				),
			),
			'adult_en_011' => array(
				'heading' => 'Intersection Search and Decision Practice',
				'intro' => 'For every intersection, identify the control, stop position, users with priority, blocked views, and escape space before proceeding.',
				'cases' => array(
					array( 'A STOP sign has a marked stop line several feet before the crosswalk. Where is the required first stop?', 'At the stop line. After stopping, creep forward only as needed for visibility while yielding and keeping the crosswalk protected.' ),
					array( 'Two vehicles arrive at an uncontrolled intersection at approximately the same time. One is on the left. Who normally yields?', 'The driver on the left yields to the driver on the right. Both drivers still verify that the other is actually responding.' ),
					array( 'You are on an unpaved road entering a paved road with no sign. What is the duty?', 'Yield to traffic on the paved road and enter only with a safe gap.' ),
					array( 'Your signal is green, but stopped traffic leaves no room beyond the intersection. May you enter and wait in the intersection?', 'No. Remain behind the entry point until there is space to clear the intersection.' ),
					array( 'A four-way signal is dark. What is the safe process?', 'Reduce speed, identify all approaches, follow current Texas law and any officer or temporary control, communicate, and proceed only after yielding and confirming a clear path.' ),
				),
			),
			'adult_en_012' => array(
				'heading' => 'Turning and Entering-Traffic Workshop',
				'intro' => 'Turning errors often begin before the wheel moves: late signals, wrong lane choice, incomplete searches, or accepting a gap that forces others to react.',
				'cases' => array(
					array( 'You plan a left turn across approaching traffic. What makes an approaching vehicle an immediate hazard?', 'Its distance and speed are close enough that your turn would interfere with its safe movement. When uncertain, wait for a larger gap.' ),
					array( 'At a T-intersection, your road ends and the crossroad continues. No assumption gives you priority. What should you do?', 'Obey the posted control, search both directions and for pedestrians, yield to through traffic, and enter only with adequate space.' ),
					array( 'You approach a two-lane roundabout in the wrong lane for your exit. Should you cut across inside the circle?', 'No. Follow lane markings, continue safely, and use another route or circle again as permitted rather than making an abrupt cross-lane movement.' ),
					array( 'You are exiting a parking lot across a sidewalk. Who must be protected first?', 'Pedestrians and other sidewalk users. Stop or yield as required before crossing the sidewalk, then yield to roadway traffic.' ),
					array( 'The freeway acceleration lane is ending and no safe gap exists. Does signaling force highway traffic to let you enter?', 'No. Entering traffic must yield. Adjust speed and locate a safe gap without forcing another driver to brake or swerve.' ),
				),
			),
			'adult_en_013' => array(
				'heading' => 'Pedestrian-Hazard Workshop',
				'intro' => 'Pedestrian risk is highest when visibility is blocked or the driver’s attention is directed toward vehicle traffic instead of the crosswalk.',
				'cases' => array(
					array( 'A vehicle stops before an unmarked crosswalk. You do not see a pedestrian. Should you pass?', 'Slow and determine why it stopped. The vehicle may be hiding a pedestrian. Do not pass until the crossing is clear and the maneuver is lawful.' ),
					array( 'You are turning right while watching traffic approaching from the left. What must happen before the vehicle moves?', 'Turn your head and rescan the crosswalk and sidewalk in the direction of travel. A pedestrian may have entered while you were looking left.' ),
					array( 'A person using a white cane is preparing to cross. What is the correct attitude?', 'Stop and provide the protection required by law. Do not honk, crowd the crosswalk, or assume the person can see your gesture.' ),
					array( 'A crossing guard directs traffic differently from the normal signal pattern. Which direction controls?', 'Follow the crossing guard’s lawful direction and remain stopped until children and the conflict area are clear.' ),
					array( 'A pedestrian crosses improperly. Does that allow the driver to continue at an unchanged speed toward the person?', 'No. Use reasonable braking, steering, warning, and space to prevent injury even when the pedestrian made an error.' ),
				),
			),
			'adult_en_014' => array(
				'heading' => 'Protected-Vehicle Response Workshop',
				'intro' => 'Identify the vehicle, its signals, the roadway division, adjacent workers or children, and the safest legal space before acting.',
				'cases' => array(
					array( 'A school bus ahead activates alternating flashing red lights. What ends the stop duty?', 'Proceed only when the bus resumes motion, the bus driver signals, or the visual signal is no longer activated, and only after checking for children.' ),
					array( 'A school bus is stopped on the opposite side of a roadway separated only by a painted center turn lane. Should you assume you are exempt?', 'No. A painted lane is not necessarily the physical division required for the opposite-roadway exception. Stop as the law requires.' ),
					array( 'An emergency vehicle approaches from behind with required audible and visual signals while you are in an intersection. What should you avoid?', 'Do not stop in the intersection. Clear it safely, move toward the right edge or curb, and stop as directed by law.' ),
					array( 'The limit is 70 mph and a covered stopped vehicle has activated overhead lights. Moving over is unsafe. What speed threshold applies?', 'Slow to at least 20 mph below the posted limit—50 mph or less—while also choosing a speed safe for actual conditions.' ),
					array( 'The limit is 25 mph. Moving over is not possible. What does the Texas rule require?', 'Slow to 5 mph and maintain control while passing the protected area.' ),
					array( 'You can move over only by cutting sharply in front of another vehicle. Is that required?', 'No. Do not create another emergency. Slow as required, maintain control, and move over only when the lane change can be made safely.' ),
				),
			),
			'adult_en_015' => array(
				'heading' => 'Railroad-Crossing Decision Workshop',
				'intro' => 'At a railroad crossing, the controlling question is whether the entire vehicle can clear every track before a train arrives or traffic stops.',
				'cases' => array(
					array( 'A warning signal activates. Where must the vehicle stop?', 'Between 15 and 50 feet from the nearest rail, as required by Texas law.' ),
					array( 'The gate rises immediately after one train passes. What must you check before moving?', 'Look and listen again for a second train on any track, confirm signals and gates permit movement, and cross only when every track and the far side are clear.' ),
					array( 'Traffic beyond the tracks is stopped but may move soon. Can you enter and wait?', 'No. Wait before the tracks until enough space exists for the entire vehicle to clear all rails.' ),
					array( 'Your vehicle stalls on a crossing and a train is approaching. Where should occupants move?', 'Exit immediately and move away from the tracks toward the direction from which the train is approaching, then call 911 and use the crossing’s Emergency Notification System information once clear.' ),
					array( 'No train is visible, but a flagger directs you to stop. Does the absence of a visible train cancel the instruction?', 'No. Stop as directed. A flagger or warning device may identify danger before the train is visible to you.' ),
				),
			),
		);
		if ( empty( $sets[ $key ] ) ) {
			return '';
		}
		$set  = $sets[ $key ];
		$html = '<section class="gb-guided-practice"><h2>' . esc_html( $set['heading'] ) . '</h2><p>' . esc_html( $set['intro'] ) . '</p><ol>';
		foreach ( $set['cases'] as $case ) {
			$html .= '<li><p><strong>' . esc_html( $case[0] ) . '</strong></p><details><summary>Compare your decision</summary><p>' . esc_html( $case[1] ) . '</p></details></li>';
		}
		$html .= '</ol><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Before continuing:</strong> Explain the lesson rule in your own words and identify the action that reduces risk. If you cannot do both without looking, review the lesson and the official source again.</div></section>';
		return $html;
	}

	private static function complete_timed_content( $key, $content ) {
		$practice = self::timed_practice( $key ) . self::topic_four_visuals( $key );
		$position = strpos( $content, '<hr>' );
		if ( false === $position ) {
			return $content . $practice;
		}
		return substr( $content, 0, $position ) . $practice . substr( $content, $position );
	}

	public static function adult_topic_one_content() {
		$poi_url      = 'https://www.sos.state.tx.us/texreg/archive/April242026/In%20Addition/202601590-1.pdf';
		$handbook_url = 'https://www.dps.texas.gov/internetforms/forms/dl-7.pdf';
		$source_note  = '<hr><p><small><strong>Official course sources:</strong> <a href="' . esc_url( $poi_url ) . '" target="_blank" rel="noopener noreferrer">TDLR Adult Six-Hour POI, May 2026</a> and <a href="' . esc_url( $handbook_url ) . '" target="_blank" rel="noopener noreferrer">Texas Driver Handbook, January 2026</a>.</small></p>';

		return array(
			'adult_en_001' => array(
				'title'    => 'Welcome and Course Purpose',
				'objective'=> '4.1.1.1(A)',
				'minutes'  => 2,
				'content'  => '<div class="gb-lesson"><p><strong>Welcome to Gulf Breeze Driving School Texas.</strong> This course is designed to give a new adult driver a reliable starting point for learning to drive legally, responsibly, and with less risk.</p><h2>What driver education provides</h2><p>A driver education course cannot create an experienced driver in six hours. It provides the foundation for the learning that continues through study, supervised practice, observation, and every responsible decision made behind the wheel.</p><p>That foundation includes four connected parts:</p><ul><li><strong>Knowledge:</strong> understanding traffic laws, signs, signals, roadway markings, and safe-driving principles.</li><li><strong>Understanding:</strong> knowing why a rule or procedure matters and what risk it is meant to reduce.</li><li><strong>Skills:</strong> learning how to control a vehicle, observe traffic, communicate, and respond safely.</li><li><strong>Experience:</strong> applying knowledge and skills repeatedly in real traffic conditions.</li></ul><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Key idea:</strong> Completing the course is one step in a lifelong learning process. Safe drivers continue to learn, evaluate their choices, and improve.</div><h2>Before moving forward</h2><p>Think about one reason you want to become a safe driver. Your reason may involve independence, work, family, education, or protecting other people. Keep that purpose in mind throughout the course.</p><h3>Lesson check</h3><p><strong>Which statement best describes the purpose of adult driver education?</strong></p><ol type="A"><li>To replace supervised driving practice</li><li>To provide a foundation for continued reduced-risk learning</li><li>To guarantee that every student will pass a road test</li></ol><details><summary>Check your answer</summary><p><strong>B.</strong> The course provides a foundation of knowledge, understanding, skills, and experiences for continued lifelong learning. It does not replace practice or guarantee a test result.</p></details>' . $source_note,
			),
			'adult_en_002' => array(
				'title'    => 'How the Online Course Works',
				'objective'=> 'Online course orientation supporting 4.1.1.1(A)',
				'minutes'  => 3,
				'content'  => '<div class="gb-lesson"><p>This is an online adult six-hour driver education course. It is organized into short lessons so you can focus on one subject at a time while still completing every required Texas topic.</p><h2>Your course path</h2><ol><li><strong>Read or watch the assigned material.</strong> Give the lesson your full attention.</li><li><strong>Complete the required instructional time.</strong> Instructional time is measured separately from scheduled breaks and the comprehensive final examination.</li><li><strong>Answer lesson and topic questions.</strong> These checks confirm participation and help you identify material that needs another review.</li><li><strong>Continue in order.</strong> Later topics build on the legal and safety principles introduced earlier.</li><li><strong>Complete the comprehensive final examination.</strong> The final measures your understanding of Texas highway signs and traffic laws.</li></ol><h2>Short lessons, complete coverage</h2><p>A short lesson is not permission to rush. Each lesson has an assigned instructional-time requirement based on the course timing ledger. The complete Adult English course contains 330 minutes of instruction and three separate 10-minute breaks, for a total course time of 360 minutes. The comprehensive final examination is not counted as instructional time.</p><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Stay active:</strong> Course time is intended for active study. Leaving the lesson, switching away for an extended period, or allowing another person to complete work does not satisfy the learning requirement.</div><h2>Use the official handbook</h2><p>The current Texas Driver Handbook is a primary course resource. Keep it available throughout the course. Laws, procedures, and official guidance may change, so the current official edition controls over an older saved copy.</p><h3>Lesson check</h3><p><strong>Does the final examination count toward the 330 minutes of required instruction?</strong></p><details><summary>Check your answer</summary><p><strong>No.</strong> The instructional ledger is completed separately. The comprehensive final examination follows the required instruction and breaks.</p></details>' . $source_note,
			),
			'adult_en_003' => array(
				'title'    => 'Course Rules, Identity, Seat Time, and Completion',
				'objective'=> 'Online participation and security requirements supporting 4.1.1.1(A)',
				'minutes'  => 3,
				'content'  => '<div class="gb-lesson"><p>Your course record must accurately show who completed the work, how instructional time was earned, and how knowledge was demonstrated.</p><h2>Complete your own work</h2><p>The enrolled student must personally complete the lessons, participation questions, multimedia checks, and final examination. Do not share course access or allow another person to answer for you. Identity and participation controls protect the validity of the course record.</p><h2>How instructional time is earned</h2><ul><li>Remain present and actively engaged with the assigned lesson.</li><li>Follow lessons in the required sequence.</li><li>Do not count a scheduled break as instructional time.</li><li>Do not count time spent taking the comprehensive final examination as instruction.</li><li>Return to material when a response shows that more review is needed.</li></ul><p>The course may use activity checks, timed lessons, identity validation, and question history to document participation. A page being open by itself is not the same as active study.</p><h2>Participation questions and multimedia</h2><p>Required topics include participation-question banks. Multimedia longer than three minutes may also have clip-specific questions. If a required multimedia question is missed, the course may require the material to be replayed before a different question is presented.</p><h2>Final examination standard</h2><p>The comprehensive final contains at least 30 questions drawn from separate highway-sign and traffic-law banks. A score of 70 percent or higher demonstrates mastery. Retests use different questions. Three failed comprehensive-final attempts result in failure of the course.</p><div style="border-left:4px solid #9a6700;padding:12px 16px;background:#fff8e5;margin:18px 0"><strong>Protect your account:</strong> Keep your sign-in information private and sign out when using a shared device.</div><h3>Lesson check</h3><p><strong>True or false:</strong> Leaving a lesson open while you do something unrelated automatically earns instructional time.</p><details><summary>Check your answer</summary><p><strong>False.</strong> The course record must reflect active participation and the student must personally complete the work.</p></details>' . $source_note,
			),
			'adult_en_004' => array(
				'title'    => 'Driving as a Privilege and Lifelong Responsibility',
				'objective'=> '4.1.1.1(B)–(C)',
				'minutes'  => 2,
				'content'  => '<div class="gb-lesson"><p>Driving gives a person mobility and independence, but it also places that person in control of a machine capable of causing injury, death, and property damage. Texas therefore treats driving as a privilege that carries continuing responsibilities and obligations.</p><h2>Knowledge supports responsible decisions</h2><p>Traffic laws create a shared system for people who use public roadways. Knowing the law helps a driver predict what others should do and choose a legal response. Knowledge alone is not enough: the driver must understand the situation and apply the rule correctly.</p><p>A reduced-risk driver asks:</p><ul><li>What does the law require?</li><li>What hazards are present or developing?</li><li>Who could be affected by my decision?</li><li>What legal action gives me the safest available margin?</li></ul><h2>Responsibilities and consequences</h2><p>Driver responsibilities include maintaining attention, yielding when required, controlling speed, communicating intentions, protecting passengers, and cooperating with other roadway users. Failing to meet those responsibilities can lead to a collision, injury, financial loss, a citation, license action, criminal consequences, or loss of life.</p><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Reduced-risk driving:</strong> You cannot remove every risk from driving. You can recognize risk early and make informed, legal, and responsible choices that reduce it.</div><h3>Apply the principle</h3><p>A traffic signal turns green, but a pedestrian is still in the crosswalk. The green signal permits movement only when it is legal and safe. A responsible driver waits, protects the pedestrian, and proceeds only after the path is clear.</p><h3>Lesson check</h3><p><strong>What is the best foundation for a reduced-risk driving decision?</strong></p><details><summary>Check your answer</summary><p>Use knowledge of the law, identify the actual risk, consider your responsibilities to others, and choose the safest legal action available.</p></details><p><strong>Topic One takeaway:</strong> Driving is a privilege. Legal knowledge, responsible judgment, and continued learning are obligations that come with that privilege.</p>' . $source_note,
			),
		);
	}

	public static function adult_topic_two_content() {
		$poi       = 'https://www.sos.state.tx.us/texreg/archive/April242026/In%20Addition/202601590-1.pdf';
		$handbook  = 'https://www.dps.texas.gov/internetforms/forms/dl-7.pdf';
		$apply     = 'https://www.dps.texas.gov/section/driver-license/apply-texas-driver-license';
		$register  = 'https://www.txdmv.gov/motorists/register-your-vehicle';
		$sr22      = 'https://www.dps.texas.gov/section/driver-license/financial-responsibility-insurance-certificate-sr-22';
		$disability= 'https://www.dps.texas.gov/news/dps-announces-driver-license-card-updates-under-texas-driving-disability-program';
		$base_note = '<hr><p><small><strong>Official sources:</strong> <a href="' . esc_url( $poi ) . '" target="_blank" rel="noopener noreferrer">TDLR Adult Six-Hour POI, May 2026</a> and <a href="' . esc_url( $handbook ) . '" target="_blank" rel="noopener noreferrer">Texas Driver Handbook, January 2026</a>.</small></p>';

		return array(
			'adult_en_005' => array(
				'title' => 'Applying for a Texas Driver License', 'objective' => '4.1.2.1(A)', 'minutes' => 4,
				'content' => '<div class="gb-lesson"><p>A Texas driver license is evidence that the state has authorized a person to operate the class of vehicle shown on the license, subject to any restrictions. Obtaining it is a legal process, not simply a driving test.</p><h2>Start with the correct requirements</h2><p>Requirements depend on age, prior licensing history, lawful presence, the type of vehicle, and the transaction being requested. Always check the current DPS instructions before an appointment. A first-time applicant generally must establish identity, lawful presence or U.S. citizenship, Texas residency, and a Social Security number, and must meet applicable education, testing, vision, and fee requirements.</p><p>Applicants who own vehicles may also be asked for evidence of current Texas registration and insurance. A person who does not own a vehicle may make the statement DPS requires for that situation.</p><h2>Adult driver education</h2><p>Texas requires a six-hour adult driver education course for a first-time applicant who is 18 through 24 years old, unless an applicable exception applies. Adults 25 or older are not subject to that age-based education requirement, although they may take the course to prepare for licensing. A new Texas resident surrendering a valid, unexpired out-of-state license may have different requirements.</p><h2>The instruction permit and practice</h2><p>An adult who needs to practice before the road test must hold the credential DPS requires for supervised driving. The license may carry a restriction requiring a properly licensed adult to be in the front seat. The student must follow the exact restrictions printed on the credential.</p><h2>Knowledge, vision, and driving skills</h2><p>DPS may require vision, knowledge, and driving examinations. Driver education supports preparation, but the student remains responsible for meeting DPS requirements. If a driving test is required, the applicant must bring the documents and vehicle required by the testing authority and must satisfy the current Impact Texas Drivers requirement when applicable.</p><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Best practice:</strong> Use the DPS application checklist shortly before the appointment. Do not rely on an old social-media post or another applicant’s document list.</div><h3>Lesson check</h3><p><strong>Why should an applicant review current DPS instructions before applying?</strong></p><details><summary>Check your answer</summary><p>Requirements vary by applicant and transaction and can change. The applicant must bring the current documents and satisfy the current education, testing, and licensing requirements.</p></details><p><small><a href="' . esc_url( $apply ) . '" target="_blank" rel="noopener noreferrer">Current DPS application guidance</a></small></p>' . $base_note,
			),
			'adult_en_006' => array(
				'title' => 'License Classes, Restrictions, and Endorsements', 'objective' => '4.1.2.1(B)', 'minutes' => 5,
				'content' => '<div class="gb-lesson"><p>A driver is authorized to operate only the vehicles permitted by the license class and must obey every restriction shown on the credential. An endorsement adds a specific authorization; a restriction limits how or what the person may drive.</p><h2>Noncommercial and commercial classes</h2><p>Most passenger-car drivers use a noncommercial Class C license. Other classes apply when vehicle weight, configuration, passenger capacity, cargo, or use places the vehicle in another category. Commercial motor vehicles are governed by additional licensing, knowledge, skills, medical, and federal requirements.</p><p>Do not choose a class by the vehicle’s appearance. A large pickup, van, trailer combination, bus, or recreational vehicle may require closer review of weight ratings, passenger capacity, use, and statutory exemptions.</p><h2>Restrictions</h2><p>A restriction appears on the license when driving privileges are limited. Common examples may address corrective lenses, vehicle equipment, daytime operation, supervised driving, or another condition DPS has placed on the credential. The exact code and wording on the license control.</p><p>Ignoring a restriction means operating outside the privilege granted by the state. Before driving, read the front and back of the credential and understand every restriction. If circumstances change, follow the DPS procedure to request removal or modification; do not simply stop obeying it.</p><h2>Endorsements</h2><p>An endorsement represents additional qualification for a particular vehicle or operation, such as motorcycle operation or certain commercial activities. An endorsement is not automatic merely because the driver has experience. The person must meet the applicable application, testing, and eligibility requirements.</p><h2>Special information</h2><p>A Texas license or identification card may also contain information that assists with identification, emergency response, or communication. Some indicators are voluntary and require supporting documentation.</p><div style="border-left:4px solid #9a6700;padding:12px 16px;background:#fff8e5;margin:18px 0"><strong>Before using an unfamiliar vehicle:</strong> Confirm that your license class, endorsements, and restrictions authorize that operation. Also verify that the vehicle itself is legally equipped, registered, and insured.</div><h3>Decision practice</h3><p>A driver has a restriction requiring corrective lenses. The trip is short and the driver believes vision is “good enough.” May the driver ignore the restriction?</p><details><summary>Check your answer</summary><p>No. The restriction is part of the legal driving privilege. The driver must use the required corrective lenses until DPS officially changes the restriction.</p></details><h3>Lesson check</h3><p><strong>Match the term:</strong> A limitation placed on driving privileges is a <em>restriction</em>. An added authorization based on additional qualification is an <em>endorsement</em>.</p>' . $base_note,
			),
			'adult_en_007' => array(
				'title' => 'Suspensions, Revocations, and Renewal', 'objective' => '4.1.2.1(A)–(C)', 'minutes' => 5,
				'content' => '<div class="gb-lesson"><p>A driver must maintain legal eligibility after the license is issued. A card that appears unexpired does not guarantee that the driving privilege is currently valid.</p><h2>Suspension, revocation, cancellation, and denial</h2><ul><li><strong>Suspension:</strong> driving privilege is temporarily withdrawn for a stated period or until requirements are satisfied.</li><li><strong>Revocation:</strong> the license or privilege is terminated under law; restoration generally requires satisfying the applicable eligibility and application requirements.</li><li><strong>Cancellation:</strong> DPS withdraws a license that should not remain in effect, such as when eligibility or required information is not established.</li><li><strong>Denial:</strong> issuance of a license or privilege is refused for the applicable period or reason.</li></ul><p>Enforcement actions can result from driving-related convictions, impaired-driving proceedings, failure to maintain required financial responsibility, certain crashes or judgments, medical determinations, administrative actions, or other grounds established by law.</p><h2>Do not drive while ineligible</h2><p>If DPS has suspended, revoked, cancelled, or denied the privilege, the person must not drive merely because the physical card is still in possession. Driving while the license is invalid can create additional criminal and administrative consequences.</p><h2>Reinstatement is individualized</h2><p>There is no single reinstatement checklist for every case. DPS directs a person to the License Eligibility system to identify the period, fees, education, insurance filing, court documentation, or other compliance items that apply. In some cases an SR-22 certificate of financial responsibility must be maintained for the required period. An ordinary insurance card is not a substitute when an SR-22 filing is specifically required.</p><h2>Renewal responsibilities</h2><p>Drivers are responsible for monitoring expiration and eligibility, maintaining a current address with DPS, and completing renewal requirements on time. Eligibility for online renewal varies. Renewal does not erase unresolved enforcement actions.</p><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Verify before driving:</strong> If you received a notice, missed a court obligation, had an insurance-related action, or are uncertain about status, use the official DPS License Eligibility service.</div><h3>Lesson check</h3><p>A license card expires next year, but DPS suspended the person’s driving privilege. Is the person legally permitted to drive?</p><details><summary>Check your answer</summary><p>No. The status of the driving privilege controls. Possession of an apparently unexpired card does not override a suspension.</p></details><p><small><a href="https://www.dps.texas.gov/section/driver-license/reinstating-your-driver-license-or-driving-privilege" target="_blank" rel="noopener noreferrer">DPS reinstatement guidance</a> · <a href="' . esc_url( $sr22 ) . '" target="_blank" rel="noopener noreferrer">DPS SR-22 guidance</a></small></p>' . $base_note,
			),
			'adult_en_008' => array(
				'title' => 'Vehicle Registration and Financial Responsibility', 'objective' => '4.1.2.1(D)–(F)', 'minutes' => 5,
				'content' => '<div class="gb-lesson"><p>Legal driving requires more than a valid driver license. The vehicle must also meet current registration, equipment, inspection when applicable, and financial-responsibility requirements.</p><h2>Registration</h2><p>Texas vehicle registration connects the vehicle to its owner and authorizes operation under the registration laws. Owners are responsible for completing title and registration transactions, keeping registration current, displaying the required plates, and reporting required ownership or address changes.</p><p>Registration procedures depend on whether the vehicle is new, used, newly brought into Texas, commercial, exempt, or subject to an emissions requirement. Current TxDMV instructions and the county tax assessor-collector provide the controlling transaction guidance.</p><h2>Current inspection framework</h2><p>Beginning in 2025, Texas eliminated the annual safety inspection requirement for most noncommercial vehicles. Commercial vehicles remain subject to safety-inspection requirements. Emissions inspections continue in designated counties for applicable vehicles. A driver must check the current rule for the vehicle and county rather than assuming every Texas vehicle follows the same inspection procedure.</p><h2>Financial responsibility</h2><p>The Texas Motor Vehicle Safety Responsibility Act requires drivers and owners to maintain proof of financial responsibility. Most people satisfy this through motor-vehicle liability insurance. Texas minimum liability limits are commonly described as 30/60/25: up to $30,000 for bodily injury to one person, $60,000 total bodily injury per crash, and $25,000 for property damage, subject to the policy and law.</p><p>Minimum liability coverage pays covered losses owed to other people; it does not automatically pay for every loss to the insured driver or vehicle. Drivers should understand what their policy covers, who is listed, which vehicles are covered, deductibles, exclusions, and effective dates.</p><h2>Proof and verification</h2><p>Carry acceptable proof of financial responsibility and provide it when legally required. Texas also uses electronic insurance verification. Never drive after a policy has lapsed, and do not assume a payment or application created coverage until the insurer confirms the effective policy.</p><div style="border-left:4px solid #9a6700;padding:12px 16px;background:#fff8e5;margin:18px 0"><strong>Before every trip:</strong> The driver, vehicle, registration, and insurance must all be legally ready. Fixing one does not cure a problem with another.</div><h3>Lesson check</h3><p>True or false: Every noncommercial vehicle in every Texas county must receive the former annual safety inspection.</p><details><summary>Check your answer</summary><p>False. Texas eliminated that safety inspection for most noncommercial vehicles beginning in 2025, while commercial safety inspections and designated-county emissions requirements continue.</p></details><p><small><a href="' . esc_url( $register ) . '" target="_blank" rel="noopener noreferrer">Current TxDMV registration guidance</a></small></p>' . $base_note,
			),
			'adult_en_009' => array(
				'title' => 'Texas Driving with Disabilities Program', 'objective' => '4.1.2.1(G)', 'minutes' => 5,
				'content' => '<div class="gb-lesson"><p>The Texas Driving with Disability Program provides voluntary ways for a person with a disability or health condition affecting communication to alert law enforcement before or during an interaction. Its purpose is safer, clearer communication—not reduced legal responsibility.</p><h2>Driver license and identification-card indicators</h2><p>Qualifying Texans may request a <strong>Communication Impediment</strong> indicator or a <strong>Deaf/Hard of Hearing</strong> indicator on the front of a Texas driver license or identification card. Participation is voluntary.</p><p>The applicant must use the current DPS process. DPS guidance requires Physician/Psychiatrist’s Statement Form DL-101 signed by the appropriate healthcare provider, along with the documents required for the driver license or identification-card transaction, at an in-person DPS appointment.</p><h2>Conditions and communication needs</h2><p>A communication impediment may arise from a disability or health condition that affects how a person understands, processes, or responds during an encounter. Eligibility is supported by the healthcare professional’s statement. A driver should not attempt to diagnose another person based on appearance or behavior.</p><h2>Vehicle-registration disclosure and TLETS</h2><p>Texas also provides a voluntary vehicle-registration disclosure process through TxDMV. Information associated with a vehicle can be made available to law enforcement through the Texas Law Enforcement Telecommunications System (TLETS). This is separate from placing an indicator on the front of a driver license or ID card, and the required forms and agency procedures differ.</p><h2>During a traffic stop</h2><p>An indicator or disclosure can alert the officer that communication may require additional time or a different approach. The driver should still follow lawful instructions, keep hands visible, avoid sudden movements, and communicate in the safest available way. A program indicator is not a waiver of traffic laws, licensing rules, or officer safety procedures.</p><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Remember:</strong> The program is voluntary and intended to reduce misunderstanding. The individual chooses whether to participate.</div><h3>Lesson check</h3><p>Which form does current DPS guidance identify for requesting a qualifying communication indicator on a driver license or ID card?</p><details><summary>Check your answer</summary><p>Physician/Psychiatrist’s Statement Form DL-101, completed and signed by the appropriate healthcare provider.</p></details><h3>Apply the distinction</h3><p>An indicator on the front of a driver license or ID card is requested through DPS. A vehicle-related disclosure is handled through the TxDMV process and may be available to officers through TLETS.</p><p><small><a href="' . esc_url( $disability ) . '" target="_blank" rel="noopener noreferrer">May 2026 DPS program update</a> · <a href="https://gov.texas.gov/organization/disabilities/texas-driving-with-disability" target="_blank" rel="noopener noreferrer">Texas Driving with Disability Program</a></small></p>' . $base_note,
			),
		);
	}

	public static function adult_topic_three_content() {
		$poi      = 'https://www.sos.state.tx.us/texreg/archive/April242026/In%20Addition/202601590-1.pdf';
		$handbook = 'https://www.dps.texas.gov/internetforms/forms/dl-7.pdf';
		$law      = 'https://statutes.capitol.texas.gov/Docs/TN/htm/TN.545.htm';
		$moveover = 'https://www.txdot.gov/safety/traffic-safety-campaigns/move-over-or-slow-down.html';
		$note     = '<hr><p><small><strong>Official sources:</strong> <a href="' . esc_url( $poi ) . '" target="_blank" rel="noopener noreferrer">TDLR Adult Six-Hour POI, May 2026</a>, <a href="' . esc_url( $handbook ) . '" target="_blank" rel="noopener noreferrer">Texas Driver Handbook, January 2026</a>, and <a href="' . esc_url( $law ) . '" target="_blank" rel="noopener noreferrer">Texas Transportation Code, Chapter 545</a>.</small></p>';

		return array(
			'adult_en_010' => array(
				'title' => 'What Right-of-Way Means', 'objective' => '4.1.3.1(A), (F)–(G)', 'minutes' => 5,
				'content' => '<div class="gb-lesson"><p><strong>Right-of-way</strong> describes who may lawfully proceed first when roadway paths conflict. It is never a guarantee that another person will yield, and it does not excuse a driver from avoiding a crash.</p><h2>Yielding and accepting</h2><p>To yield is to give another road user the time and space needed to proceed. Before accepting right-of-way, search left, front, right, mirrors, and blind areas; confirm that others are actually yielding; and enter only when the available gap is safe.</p><p>Drivers must share the road with pedestrians, bicyclists, motorcyclists, and vehicles of every size. A motorcycle is entitled to a full lane. Its smaller size can make its speed and distance difficult to judge, so look twice and never turn across its path based on a quick glance.</p><h2>Responsibility remains</h2><p>A driver who fails to yield may receive a citation and may cause injury, death, or property loss. A driver who technically has priority must still brake or wait when proceeding would create a collision.</p><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Reduced-risk rule:</strong> Know when the law requires you to yield, then verify that the path is clear before moving.</div><h3>Lesson check</h3><p>A driver has a green light, but a vehicle is still blocking the intersection. Should the driver enter?</p><details><summary>Check your answer</summary><p>No. A signal does not authorize a collision or entry into a blocked path. Wait until movement is legal and safe.</p></details>' . $note,
			),
			'adult_en_011' => array(
				'title' => 'Controlled and Uncontrolled Intersections', 'objective' => '4.1.3.1(B)–(C)', 'minutes' => 8,
				'content' => '<div class="gb-lesson"><p>An intersection is a conflict area where roads or roadway movements meet. Approach every intersection at a speed that permits observation, decision, and stopping.</p><h2>Controlled intersections</h2><p>Traffic signals, STOP signs, YIELD signs, pavement markings, or an officer may control movement. Stop at the marked stop line; if none, stop before the crosswalk; if neither exists, stop where the intersecting roadway can be seen without entering it. A green light permits movement only after yielding as required and confirming a clear path. A driver turning left yields to approaching traffic close enough to be hazardous.</p><h2>Uncontrolled intersections</h2><p>When no sign or signal controls the intersection, reduce speed and prepare to yield. When vehicles arrive at approximately the same time, the driver on the left yields to the driver on the right. A driver entering from an unpaved road yields to traffic on a paved road. Never assume another driver understands or will follow the rule.</p><h2>Four-way stops</h2><p>Each driver makes a complete stop. The vehicle that stopped first normally proceeds first. If vehicles stop at approximately the same time, apply the right-side rule. Communicate with signals and vehicle position, but do not wave another road user into danger.</p><h2>Blocked or failed controls</h2><p>Do not enter an intersection unless there is room to clear it. If a traffic signal is dark or malfunctioning, slow, identify the condition, follow current law and any officer or temporary control, and proceed only when safe.</p><h3>Lesson check</h3><p>Two vehicles reach an uncontrolled intersection at approximately the same time. Which driver normally yields?</p><details><summary>Check your answer</summary><p>The driver on the left yields to the driver on the right, while both remain responsible for avoiding a crash.</p></details>' . $note,
			),
			'adult_en_012' => array(
				'title' => 'Turns, T-Intersections, Circles, and Entering Traffic', 'objective' => '4.1.3.1(B)–(C), (F)–(G)', 'minutes' => 7,
				'content' => '<div class="gb-lesson"><p>Turning and entering traffic require a driver to cross or join another road user’s path. Plan early, signal, position correctly, control speed, and yield before the paths conflict.</p><h2>Turns and T-intersections</h2><p>A left-turning driver yields to approaching traffic that is in the intersection or close enough to be an immediate hazard. At a T-intersection, traffic on the road that ends must stop or yield as directed and enter the through road only when safe. Make turns into the lawful lane without cutting corners or swinging wide.</p><h2>Traffic circles and roundabouts</h2><p>Slow before entry, read signs and lane arrows, and yield to traffic already circulating. Choose the correct lane before entering, follow the circular roadway, and signal the exit when practical. Never stop inside merely to allow an entering vehicle to go unless traffic conditions require stopping.</p><h2>Private roads, driveways, alleys, and parking lots</h2><p>A driver entering a roadway from a private road, driveway, alley, building, or parking area must stop or yield as the law requires and protect pedestrians using the sidewalk or shoulder. Enter only with a gap large enough to accelerate without forcing roadway traffic to brake sharply.</p><h2>Controlled-access roads</h2><p>Use the acceleration lane to build an appropriate speed, signal, find a safe gap, and yield to traffic already on the highway. Drivers on the highway should maintain a predictable path and may change lanes when lawful and safe, but entering traffic remains responsible for yielding.</p><h3>Lesson check</h3><p>Who yields at a roundabout entrance?</p><details><summary>Check your answer</summary><p>The entering driver yields to traffic already circulating and enters only when the selected lane and gap are safe.</p></details>' . $note,
			),
			'adult_en_013' => array(
				'title' => 'Pedestrians, Crosswalks, and School Crossings', 'objective' => '4.1.3.1(C)–(D), (G)', 'minutes' => 7,
				'content' => '<div class="gb-lesson"><p>Pedestrians are vulnerable road users. Drivers must search for them before turning, backing, entering a roadway, passing stopped traffic, or moving through a crosswalk.</p><h2>Crosswalk awareness</h2><p>A crosswalk may be marked or unmarked. Yield as required to pedestrians in a crosswalk, and never pass a vehicle stopped at a crosswalk without first determining why it stopped. A pedestrian can be hidden by the stopped vehicle.</p><p>Before turning, scan the sidewalk and both ends of the crosswalk. Check again immediately before movement. Keep the entire crosswalk clear while stopped. When backing from a driveway or parking space, move slowly and yield to people on the sidewalk or behind the vehicle.</p><h2>Signals and special risk</h2><p>Obey pedestrian-control signals and traffic signals, but remain ready for error. Children may move unpredictably; older adults and people with disabilities may need more time. A white cane or guide dog can identify a person who is blind or has impaired vision. Stop and protect the person as the law requires.</p><h2>School areas</h2><p>Reduce speed, obey the posted school-zone control when active, follow crossing-guard directions, and expect children between parked vehicles. Do not use a phone or other distraction in a way prohibited by law or that prevents full attention.</p><div style="border-left:4px solid #9a6700;padding:12px 16px;background:#fff8e5;margin:18px 0"><strong>Do not surrender judgment:</strong> Even when a pedestrian acts unlawfully, use every reasonable action to avoid injury.</div><h3>Lesson check</h3><p>A vehicle is stopped before a crosswalk with no visible traffic signal problem. What should an approaching driver do?</p><details><summary>Check your answer</summary><p>Slow and determine whether a pedestrian is hidden from view. Do not pass until the crosswalk and path are confirmed clear and passing is lawful.</p></details>' . $note,
			),
			'adult_en_014' => array(
				'title' => 'School Buses, Emergency Vehicles, and Move Over or Slow Down', 'objective' => '4.1.3.1(D)–(E)', 'minutes' => 8,
				'content' => '<div class="gb-lesson"><p>Some stopped or approaching vehicles create special legal duties because people may be working, responding to emergencies, or entering the roadway.</p><h2>School buses</h2><p>When a school bus displays alternating flashing red signals, approaching drivers must stop as Texas law requires and may not proceed until the bus resumes motion, the driver signals, or the visual signal is no longer activated. A divided highway can change the duty for traffic on the opposite roadway; a painted center line or turn lane alone is not necessarily the physical separation required by law. Slow early and watch for children.</p><h2>Approaching emergency vehicles</h2><p>When an authorized emergency vehicle approaches using required audible and visual signals, yield the right-of-way, move toward the right edge or curb clear of the intersection, and stop until it passes, unless an officer directs otherwise. Do not follow closely or enter a scene.</p><h2>Move Over or Slow Down</h2><p>When approaching a stopped vehicle covered by the Texas law with activated overhead lights, move out of the lane closest to it when the roadway and traffic permit. If you cannot safely move over, slow to at least 20 mph below the posted speed limit. When the posted limit is 25 mph or less, slow to 5 mph. Maintain control and be prepared for workers or people near the lane.</p><p>Effective September 1, 2025, Texas expanded the covered group to include animal-control officers and parking-enforcement employees. Current law also covers the other vehicle and worker categories identified in the statute and official TxDOT guidance. Learn the current list rather than relying on an older memory of the rule.</p><div style="border-left:4px solid #1769aa;padding:12px 16px;background:#f3f8fc;margin:18px 0"><strong>Make space early:</strong> Check mirrors, signal, and change lanes smoothly. Never create a second emergency by forcing an unsafe lane change.</div><h3>Lesson check</h3><p>The speed limit is 65 mph and moving over is unsafe. What does the rule require?</p><details><summary>Check your answer</summary><p>Slow to at least 20 mph below the posted limit—45 mph or less—while maintaining safe control for conditions.</p></details><p><small><a href="' . esc_url( $moveover ) . '" target="_blank" rel="noopener noreferrer">Current TxDOT Move Over or Slow Down guidance</a></small></p>' . $note,
			),
			'adult_en_015' => array(
				'title' => 'Railroad Crossings and Right-of-Way Decisions', 'objective' => '4.1.3.1(C), (F)–(G)', 'minutes' => 7,
				'content' => '<div class="gb-lesson"><p>A train cannot swerve and may require a long distance to stop. The safe decision is made before the vehicle enters the crossing.</p><h2>When Texas law requires a stop</h2><p>Stop between <strong>15 and 50 feet</strong> from the nearest rail when a signal warns of a train, a crossing gate is lowered, a flagger signals, a train is plainly visible and dangerously close, or an approaching train emits an audible signal as specified by law. Never drive around, under, or through a lowered or moving gate.</p><h2>Before crossing</h2><p>Slow, look both directions, listen, and confirm enough space exists on the far side for the entire vehicle. Never begin crossing unless you can clear every track without stopping. Expect a second train from either direction after the first passes. Cross only at a designated roadway crossing and obey signs, pavement markings, signals, and flaggers.</p><h2>Special vehicles and conditions</h2><p>Some vehicles must stop at railroad crossings even when an ordinary passenger vehicle might not. Follow the rules for the vehicle being operated. Use extra caution when visibility is limited, traffic is backed up, the surface is uneven, or multiple tracks are present.</p><h2>If the vehicle becomes trapped</h2><p>Leave the vehicle and move away from the tracks immediately, toward the direction from which the train is approaching so debris is carried away from you. Once clear, use the Emergency Notification System information posted at the crossing and call 911. Do not remain in or return to a vehicle in the train’s path.</p><h3>Decision practice</h3><p>Traffic beyond the tracks leaves no room for your vehicle. The signal is inactive. May you cross?</p><details><summary>Check your answer</summary><p>No. Do not enter until there is enough room to clear all tracks completely.</p></details><h3>Topic takeaway</h3><p>Right-of-way law organizes traffic, but reduced-risk driving requires observation, communication, space, and a willingness to yield whenever proceeding would be unsafe.</p>' . $note,
			),
		);
	}

	public static function adult_topic_four_content() {
		$poi = 'https://www.sos.state.tx.us/texreg/archive/April242026/In%20Addition/202601590-1.pdf';
		$handbook = 'https://www.dps.texas.gov/internetforms/forms/dl-7.pdf';
		$note = '<hr><p><small><strong>Official sources:</strong> <a href="' . esc_url( $poi ) . '" target="_blank" rel="noopener noreferrer">TDLR Adult Six-Hour POI, May 2026, Topic 4.1.4</a> and <a href="' . esc_url( $handbook ) . '" target="_blank" rel="noopener noreferrer">Texas Driver Handbook, January 2026, Chapter 5</a>.</small></p>';
		return array(
			'adult_en_016' => array( 'title'=>'Why Traffic Control Devices Matter','objective'=>'4.1.4.1(C)–(D)','minutes'=>4,'content'=>'<div class="gb-lesson"><p>Traffic control devices create a shared visual language for drivers, pedestrians, bicyclists, and workers. Signs, signals, and pavement markings assign movement, warn of hazards, guide routes, and organize roadway space.</p><h2>Use a decision sequence</h2><ol><li><strong>Detect:</strong> search far enough ahead to notice the device early.</li><li><strong>Identify:</strong> use color, shape, symbol, words, location, and illumination.</li><li><strong>Interpret:</strong> decide what the device requires or warns about in the present conditions.</li><li><strong>Respond:</strong> check surrounding traffic, communicate, and adjust speed or position smoothly.</li></ol><p>A device states a rule or warning; it does not guarantee the path is safe. A green signal does not remove the duty to yield to a pedestrian, and a posted speed is not a promise that the speed is safe in rain or congestion.</p><h2>Conflicting directions</h2><p>Follow lawful directions from a police officer or authorized traffic controller even when they differ from a routine signal. Temporary work-zone controls may replace normal lane markings. When a device is damaged, dark, blocked, or unclear, reduce speed, increase space, and follow current law rather than guessing.</p><h3>Guided decisions</h3><ol><li><p><strong>A signal is green but the intersection is blocked. Enter?</strong></p><details><summary>Compare your decision</summary><p>No. Wait until there is room to clear the intersection.</p></details></li><li><p><strong>A flagger directs traffic through a red signal in a work area. Which direction controls?</strong></p><details><summary>Compare your decision</summary><p>Follow the authorized flagger while continuing to watch for workers and conflicting movement.</p></details></li><li><p><strong>A sign is partly hidden by a truck. What reduces risk?</strong></p><details><summary>Compare your decision</summary><p>Slow, create space, search for repeated markings or signals, and do not make an abrupt maneuver based on a partial view.</p></details></li></ol></div>'.$note ),
			'adult_en_017' => array( 'title'=>'Regulatory Signs','objective'=>'4.1.4.1(A)–(B)','minutes'=>6,'content'=>'<div class="gb-lesson"><p>Regulatory signs communicate laws and legally enforceable movement rules. Most use white backgrounds with black or red symbols or words, but shape can identify a critical rule before the message is readable.</p><h2>Shapes and meanings</h2><ul><li><strong>Octagon:</strong> STOP. Make a complete stop at the correct location and yield before proceeding.</li><li><strong>Downward-pointing triangle:</strong> YIELD. Slow and give right-of-way; stop when necessary.</li><li><strong>Vertical rectangle:</strong> common regulatory form for speed, lane use, parking, turning, and other rules.</li><li><strong>Circle with a slash:</strong> the pictured action is prohibited.</li></ul><h2>Common regulatory decisions</h2><p>A speed-limit sign establishes the legal maximum under favorable conditions; drivers must choose a lower safe speed when conditions require. ONE WAY and DO NOT ENTER prevent opposing movement. Lane-use arrows and turn restrictions control which movements may be made from a lane. Parking signs can limit location, direction, duration, vehicle class, or time.</p><p>STOP means wheels cease movement. Stop at the line, before the crosswalk, or at the point required by law. YIELD never means force another road user to brake. It means select a gap that allows the other movement to continue safely.</p><h2>Red, white, and black</h2><p>Red emphasizes stop, prohibition, or a critical restriction. White and black commonly state enforceable rules. Always interpret the complete sign; color alone is not enough.</p><h3>Guided decisions</h3><ol><li><p><strong>The limit is 70 mph during heavy rain. Is 70 automatically safe?</strong></p><details><summary>Compare your decision</summary><p>No. It is the maximum under favorable conditions, not a required speed.</p></details></li><li><p><strong>You slow to 2 mph at STOP with no traffic visible. Is that a stop?</strong></p><details><summary>Compare your decision</summary><p>No. The vehicle must come to a complete stop.</p></details></li><li><p><strong>A YIELD-controlled merge has no immediate traffic. Must you always stop?</strong></p><details><summary>Compare your decision</summary><p>No, but slow, search, and yield; stop whenever needed to avoid interference.</p></details></li><li><p><strong>A turn arrow has a red slash. What does it mean?</strong></p><details><summary>Compare your decision</summary><p>The depicted turn is prohibited under the sign’s stated conditions.</p></details></li></ol></div>'.$note ),
			'adult_en_018' => array( 'title'=>'Warning Signs','objective'=>'4.1.4.1(A)–(B)','minutes'=>6,'content'=>'<div class="gb-lesson"><p>Warning signs provide advance notice of a roadway condition or hazard. Most are yellow diamonds with black symbols or words. Their purpose is to create time to search, slow, position, and prepare—not to replace observation.</p><h2>Recognizable shapes</h2><ul><li><strong>Diamond:</strong> general warning.</li><li><strong>Pennant:</strong> no-passing zone, placed on the left side of the road.</li><li><strong>Round:</strong> railroad crossing advance warning.</li><li><strong>Crossbuck:</strong> railroad grade crossing at the tracks.</li><li><strong>Pentagon:</strong> school area or school crossing.</li></ul><h2>Read the symbol, then the road</h2><p>Curve, intersection, merge, lane-ending, divided-highway, slippery-road, low-clearance, animal, bicycle, and pedestrian symbols identify the type of risk. Advisory-speed plaques describe a recommended speed for the condition; they do not cancel the duty to choose an even lower speed when weather, visibility, traffic, or vehicle condition requires it.</p><p>After seeing a warning sign, check mirrors before slowing, cover the brake when appropriate, select a safe lane position, and search for the actual hazard. The hazard may begin before or after the sign’s apparent location.</p><h3>Guided decisions</h3><ol><li><p><strong>A yellow curve sign includes a 35 mph plaque while the road limit is 55. What should change?</strong></p><details><summary>Compare your decision</summary><p>Reduce speed before the curve toward a safe speed no greater than conditions allow; the plaque warns of the curve’s recommended handling speed.</p></details></li><li><p><strong>You see a lane-ending symbol. Should you wait until the taper to force a merge?</strong></p><details><summary>Compare your decision</summary><p>No. Check traffic early, communicate, and merge according to signs and conditions without forcing another driver to react.</p></details></li><li><p><strong>A round yellow sign appears ahead. What risk should you anticipate?</strong></p><details><summary>Compare your decision</summary><p>A railroad crossing. Slow, look, listen, and be prepared to stop.</p></details></li><li><p><strong>Why look beyond a deer-crossing sign?</strong></p><details><summary>Compare your decision</summary><p>The sign identifies an area of increased risk; the animal can appear anywhere nearby and may be followed by others.</p></details></li></ol></div>'.$note ),
			'adult_en_019' => array( 'title'=>'Guide, School-Zone, and Work-Zone Signs','objective'=>'4.1.4.1(A)–(B)','minutes'=>6,'content'=>'<div class="gb-lesson"><p>Guide signs help drivers select routes and services. School and work-zone devices add warnings and controls where vulnerable people or changing roadway conditions require extra attention.</p><h2>Guide colors</h2><ul><li><strong>Green:</strong> destinations, directions, distances, and permitted movements.</li><li><strong>Blue:</strong> motorist services such as fuel, food, lodging, or medical help.</li><li><strong>Brown:</strong> recreation, parks, and cultural-interest destinations.</li></ul><p>Read guide signs early. Confirm route number, direction, exit, and lane before making a gradual lane change. Missing an exit is safer than crossing a gore area or making a sudden turn.</p><h2>School devices</h2><p>Fluorescent yellow-green signs and pentagon shapes draw attention to school areas, crossings, pedestrians, and bicyclists. Obey the posted school-zone speed when the sign, schedule, beacon, or other control makes it active. Search between parked vehicles and follow crossing-guard directions.</p><h2>Work zones</h2><p>Orange signs, channelizing devices, arrow boards, temporary markings, and flaggers warn that the normal roadway pattern may be changed. Slow before the activity area, increase following space, keep out of closed lanes, and expect workers and equipment near traffic. Temporary controls govern while in effect.</p><h3>Guided decisions</h3><ol><li><p><strong>You realize your exit is across a solid gore area. Cross it?</strong></p><details><summary>Compare your decision</summary><p>No. Continue and reroute safely.</p></details></li><li><p><strong>Normal white markings conflict with clear temporary orange controls. Which path applies?</strong></p><details><summary>Compare your decision</summary><p>Follow the temporary work-zone traffic control while it is in effect.</p></details></li><li><p><strong>A school-zone beacon is active but no children are visible. Obey the zone?</strong></p><details><summary>Compare your decision</summary><p>Yes. Follow the activated posted control and continue searching for children.</p></details></li><li><p><strong>A flagger holds a STOP paddle. May you proceed when the lane looks clear?</strong></p><details><summary>Compare your decision</summary><p>No. Remain stopped until the flagger directs movement.</p></details></li></ol></div>'.$note ),
			'adult_en_020' => array( 'title'=>'Traffic Signals and Flashing Signals','objective'=>'4.1.4.1(B)–(D)','minutes'=>8,'content'=>'<div class="gb-lesson"><p>Signals assign movement by color, arrow, position, and flashing pattern. Approach with a plan to stop; then proceed only when the indication and the roadway both permit it.</p><h2>Steady indications</h2><ul><li><strong>Red:</strong> stop at the required location. Remain stopped until a permitted movement is lawful and safe.</li><li><strong>Yellow:</strong> the green movement is ending. Stop if this can be done safely; never accelerate merely to beat red.</li><li><strong>Green:</strong> proceed only after yielding as required and checking that the intersection can be cleared.</li></ul><p>An arrow controls the movement it points toward. A green arrow gives a protected movement subject to yielding to people already lawfully in the intersection. A red arrow prohibits that movement until a signal permits it, subject to current law and posted controls.</p><h2>Flashing indications</h2><p>A flashing red signal is treated as a stop: stop completely, yield, and proceed when safe. A flashing yellow signal means slow and proceed with caution. A flashing yellow arrow permits the indicated turn after yielding to conflicting traffic and pedestrians.</p><h2>Signal failures and officers</h2><p>When a signal is dark or malfunctioning, reduce speed, identify every approach, follow current Texas law, and obey any officer or temporary control. Never assume cross traffic has the same display.</p><h2>Timing the decision</h2><p>Scan the signal while still checking the intersection. A stale green may change; cover the brake when circumstances suggest the phase may end. Rear-view awareness matters before braking, but a close follower never requires entering against a signal.</p><h3>Guided decisions</h3><ol><li><p><strong>Your light turns green while a pedestrian remains in the crosswalk.</strong></p><details><summary>Compare your decision</summary><p>Wait and yield until the path is clear.</p></details></li><li><p><strong>The signal turns yellow and a safe controlled stop is available.</strong></p><details><summary>Compare your decision</summary><p>Stop; do not accelerate to enter before red.</p></details></li><li><p><strong>A flashing red signal has no visible cross traffic.</strong></p><details><summary>Compare your decision</summary><p>Make a complete stop, search, yield, and then proceed when safe.</p></details></li><li><p><strong>A flashing yellow arrow permits a left turn. Is oncoming traffic required to stop?</strong></p><details><summary>Compare your decision</summary><p>No. Turn only after yielding to conflicting traffic and pedestrians.</p></details></li><li><p><strong>An officer signals you to remain stopped during green.</strong></p><details><summary>Compare your decision</summary><p>Obey the officer.</p></details></li><li><p><strong>Traffic beyond green is backed into the intersection.</strong></p><details><summary>Compare your decision</summary><p>Wait before entering until there is room to clear.</p></details></li></ol></div>'.$note ),
			'adult_en_021' => array( 'title'=>'Pavement and Lane-Control Markings','objective'=>'4.1.4.1(B)–(D)','minutes'=>7,'content'=>'<div class="gb-lesson"><p>Pavement markings organize opposing traffic, lanes traveling in the same direction, passing, turning, stopping, crossings, and roadway edges. Interpret color, line pattern, arrows, and surrounding signs together.</p><h2>Color and line pattern</h2><p><strong>Yellow</strong> generally separates traffic moving in opposite directions or marks the left edge of a divided roadway. <strong>White</strong> generally separates lanes moving in the same direction or marks the right edge. Broken lines permit crossing when lawful and safe; solid lines restrict or discourage crossing according to their location and the applicable rule.</p><p>A double solid yellow center line prohibits crossing for ordinary passing. When one side is broken and the other solid, passing permission differs by side. Never pass merely because the line is broken; sight distance, signs, intersections, hills, curves, traffic, and law must also permit it.</p><h2>Arrows, stop lines, and crosswalks</h2><p>Lane arrows assign or guide movements. Select the correct lane early and follow its arrow. Stop behind a stop line. Keep crosswalks clear and search for pedestrians before turning. Diagonal gore markings separate traffic streams and are not travel lanes.</p><h2>Special markings</h2><p>Center turn lanes are for lawful turning movements, not passing or extended travel. Bicycle-lane and shared-lane markings alert drivers to bicycle movements but do not replace a full search before crossing or turning. Raised markers and reflectors reinforce lane boundaries, especially at night.</p><h3>Guided decisions</h3><ol><li><p><strong>A broken yellow line is on your side. Is passing automatic?</strong></p><details><summary>Compare your decision</summary><p>No. Pass only when every legal and visibility condition is satisfied and the maneuver can be completed safely.</p></details></li><li><p><strong>Your lane arrow indicates left turn only, but you want to continue straight.</strong></p><details><summary>Compare your decision</summary><p>Follow the lane assignment; change lanes only before the control and only when lawful and safe, otherwise turn and reroute.</p></details></li><li><p><strong>May a center turn lane be used to pass stopped traffic?</strong></p><details><summary>Compare your decision</summary><p>No. It is reserved for lawful turning use, not passing or ordinary travel.</p></details></li><li><p><strong>A white stop line is before the crosswalk. Where do you stop?</strong></p><details><summary>Compare your decision</summary><p>Behind the stop line, leaving the crosswalk clear.</p></details></li><li><p><strong>A bicycle lane crosses your right-turn path.</strong></p><details><summary>Compare your decision</summary><p>Signal, search mirrors and blind areas, yield to the bicyclist, and cross only when safe.</p></details></li></ol></div>'.$note ),
		);
	}

	public static function adult_topic_five_content() {
		$poi='https://www.sos.state.tx.us/texreg/archive/April242026/In%20Addition/202601590-1.pdf'; $hb='https://www.dps.texas.gov/internetforms/forms/dl-7.pdf';
		$note='<hr><p><small><strong>Official sources:</strong> <a href="'.esc_url($poi).'" target="_blank" rel="noopener noreferrer">TDLR Adult Six-Hour POI, May 2026, Topic 4.1.5</a> and <a href="'.esc_url($hb).'" target="_blank" rel="noopener noreferrer">Texas Driver Handbook, January 2026, Chapters 6–9</a>.</small></p>';
		return array(
		'adult_en_022'=>array('title'=>'Speed Control, Following Distance, and Stopping','objective'=>'4.1.5.1(A), (F)–(J), (P)','minutes'=>7,'content'=>'<div class="gb-lesson"><p><strong>Traffic flow</strong> is the organized movement of roadway users through a transportation system. Safe flow depends on predictable speed, adequate space, communication, and cooperation with traffic controls and lawful directions.</p><h2>Choose speed for the whole situation</h2><p>A posted maximum applies under favorable conditions. The safe speed may be lower because of traffic, rain, darkness, glare, hills, curves, work zones, vehicle condition, cargo, fatigue, or limited sight distance. Never drive faster than the distance you can see and manage. Route planning or postponing the trip may be the only responsible choice in severe conditions.</p><h2>Following interval</h2><p>Use a fixed roadside object to measure the time between the vehicle ahead passing it and your vehicle reaching it. Maintain at least the interval recommended by the current Texas Driver Handbook, and add substantial space for poor traction, darkness, heavy vehicles, motorcycles, blocked views, work zones, or a following driver who crowds you. Space is time to perceive, decide, and brake.</p><h2>Total stopping distance</h2><p>Stopping includes perception distance, reaction distance, and braking distance. Speed increases every part of the problem: the vehicle travels farther before braking begins, and braking distance rises sharply. Wet or loose pavement, worn tires, downhill grade, heavy load, and brake condition increase it further.</p><h2>Lights and visibility</h2><p>Use vehicle lights when required by law and whenever visibility demands them. Headlights help you see and help others detect you. High beams can improve distance vision but must be dimmed as required and whenever glare would endanger another road user.</p><h3>Guided decisions</h3><ol><li><p><strong>The limit is 65, but heavy rain limits sight distance. What controls?</strong></p><details><summary>Compare your decision</summary><p>Slow to a speed that allows control and stopping within the visible path; the posted maximum is not a safe-speed guarantee.</p></details></li><li><p><strong>A truck blocks your view ahead. Keep the normal interval?</strong></p><details><summary>Compare your decision</summary><p>Add space so you can see farther and respond without relying only on the truck driver.</p></details></li><li><p><strong>A driver tailgates you.</strong></p><details><summary>Compare your decision</summary><p>Increase space ahead, remain predictable, and allow the driver to pass when lawful and safe; do not brake-check.</p></details></li><li><p><strong>You feel highway hypnosis or repeated yawning.</strong></p><details><summary>Compare your decision</summary><p>Leave the roadway safely and rest. Music, open windows, or caffeine do not make a fatigued driver reliably safe.</p></details></li></ol></div>'.$note),
		'adult_en_023'=>array('title'=>'Lane Position, Lane Changes, and Blind Spots','objective'=>'4.1.5.1(A)–(F), (P)','minutes'=>6,'content'=>'<div class="gb-lesson"><p>Lane position controls visibility, space, and communication. Stay centered in the lawful lane unless a hazard or maneuver requires a deliberate adjustment.</p><h2>Blind spots</h2><p>Mirrors do not show every area beside and behind a vehicle. Adjust mirrors properly, scan them regularly, and make a brief shoulder check before moving laterally. Do not remain beside another vehicle where its driver may not see you—especially beside large trucks, buses, and motorcycles.</p><h2>Safe lane-change sequence</h2><ol><li>Search well ahead and identify why the change is needed.</li><li>Check inside and outside mirrors.</li><li>Signal early enough to communicate—not to demand space.</li><li>Check the blind area with a quick shoulder glance.</li><li>Confirm a safe gap ahead and behind.</li><li>Move smoothly one lane at a time and cancel the signal.</li></ol><p>Avoid changing lanes in intersections, across prohibited markings, through gore areas, or where visibility is inadequate. Temporary work-zone lanes may be narrower or shifted; follow the active controls and avoid drifting toward workers or barriers.</p><h2>Lane choice</h2><p>Use lane-control signs and arrows early. Keep right except when passing or when another lawful movement requires a different lane. Never use a center turn lane as a passing or travel lane. If you miss a turn or exit, continue and reroute instead of cutting across traffic.</p><h3>Guided decisions</h3><ol><li><p><strong>Your mirror looks clear. Is the lane change ready?</strong></p><details><summary>Compare your decision</summary><p>No. Signal, check the blind area, confirm the gap, and move only when safe.</p></details></li><li><p><strong>You are beside a truck near its rear wheels.</strong></p><details><summary>Compare your decision</summary><p>Avoid lingering. Fall back or pass efficiently when lawful, while staying out of the truck’s blind area.</p></details></li><li><p><strong>Your exit is separated by gore markings.</strong></p><details><summary>Compare your decision</summary><p>Do not cross the gore. Continue to the next lawful route.</p></details></li><li><p><strong>A work-zone taper ends your lane.</strong></p><details><summary>Compare your decision</summary><p>Obey signs and flaggers, communicate, manage speed and spacing, and merge without forcing another driver to brake or swerve.</p></details></li></ol></div>'.$note),
		'adult_en_024'=>array('title'=>'Turning, Signaling, and Communication','objective'=>'4.1.5.1(B)–(D), (P)','minutes'=>6,'content'=>'<div class="gb-lesson"><p>Drivers communicate with signals, brake lights, headlights, horn, lane position, speed, and eye contact. Communication must be timely and clear, but it never transfers responsibility to another road user.</p><h2>Before turning</h2><p>Plan early, choose the lawful lane, signal for the distance required by Texas law, reduce speed before steering, search the intersection and crosswalk, and yield as required. Keep wheels straight while waiting for a gap in a left turn so a rear impact is less likely to push the vehicle into opposing traffic.</p><p>Turn into the lawful lane without cutting the corner or swinging wide. Search again immediately before movement because pedestrians, bicyclists, and motorcycles can enter after the first check.</p><h2>Stopping, parking, backing, and leaving a space</h2><p>Signal or communicate before slowing substantially when practical. Stop where required without blocking a crosswalk. Before leaving a parking space, check around the vehicle, signal, yield, and enter traffic only with adequate space. Avoid backing whenever a forward option exists. When backing is necessary, look through the rear window and around the vehicle, move at walking speed, and stop if the view is lost. Cameras assist but do not replace direct observation.</p><h2>Horn and headlights</h2><p>Use the horn to warn of immediate danger, not to punish or express anger. Flashing headlights is not a legal command and may be misunderstood. Never rely on a wave from another driver without checking every conflict path yourself.</p><h3>Guided decisions</h3><ol><li><p><strong>A driver waves you across two lanes.</strong></p><details><summary>Compare your decision</summary><p>Verify every lane, sidewalk, and blind area yourself; the gesture covers only that driver.</p></details></li><li><p><strong>You begin turning right while looking left for traffic.</strong></p><details><summary>Compare your decision</summary><p>Stop and rescan the crosswalk and right-side path before moving.</p></details></li><li><p><strong>Your backup camera is clear.</strong></p><details><summary>Compare your decision</summary><p>Still check around and behind the vehicle directly and move slowly.</p></details></li><li><p><strong>Another driver makes an error.</strong></p><details><summary>Compare your decision</summary><p>Use the horn only if needed as a danger warning; create space rather than retaliating.</p></details></li></ol></div>'.$note),
		'adult_en_025'=>array('title'=>'Passing and Being Passed','objective'=>'4.1.5.1(D), (E), (P)','minutes'=>6,'content'=>'<div class="gb-lesson"><p>Passing creates closing-speed, visibility, and lane-conflict risks. Pass only when law, markings, sight distance, speed, and traffic all allow the maneuver.</p><h2>Before passing</h2><p>Ask whether passing is necessary. Check signs and markings, the road far ahead, mirrors, blind spots, vehicles entering from side roads, and the vehicle behind. Do not pass on hills, curves, intersections, railroad crossings, or other locations where law or limited view prohibits it. Never exceed the lawful safe speed to complete a pass.</p><h2>Passing sequence</h2><p>Signal, confirm the lane remains clear, move smoothly, maintain safe lateral space, and continue until the passed vehicle is visible in the mirror before returning. Signal the return and avoid cutting in. Motorcycles and bicycles require full recognition as roadway users; do not crowd them.</p><h2>When being passed</h2><p>Maintain a predictable lane and do not increase speed. Create space if needed. Competitive acceleration traps the passing driver beside you and increases danger for everyone.</p><h2>Passing on the right</h2><p>Passing on the right is permitted only under specific legal conditions and must never be done by driving off the pavement or using a non-travel lane. A shoulder, bicycle lane, parking lane, or center turn lane is not an ordinary passing lane.</p><h3>Guided decisions</h3><ol><li><p><strong>The center line is broken, but a hill blocks the view.</strong></p><details><summary>Compare your decision</summary><p>Do not pass. A permissive marking does not overcome inadequate sight distance.</p></details></li><li><p><strong>A vehicle begins passing you.</strong></p><details><summary>Compare your decision</summary><p>Hold a steady lane and do not accelerate; allow safe completion.</p></details></li><li><p><strong>Traffic stops for a pedestrian and the right shoulder is open.</strong></p><details><summary>Compare your decision</summary><p>Do not pass on the shoulder; the stopped traffic may conceal a pedestrian.</p></details></li><li><p><strong>You cannot return without cutting closely in front.</strong></p><details><summary>Compare your decision</summary><p>The pass was not safe to begin. Before passing, ensure enough clear distance to complete the entire maneuver legally.</p></details></li></ol></div>'.$note),
		'adult_en_026'=>array('title'=>'Parking, Backing, Freeway Travel, Emergencies, and Work Zones','objective'=>'4.1.5.1(D), (K)–(Q)','minutes'=>7,'content'=>'<div class="gb-lesson"><p>This lesson combines procedures used when ordinary traffic flow becomes more complex: freeway entry and exit, breakdowns, loss of vehicle control, poor weather, parking, and work zones.</p><h2>Freeway entry, travel, and exit</h2><p>Use the acceleration lane to approach traffic speed, signal, locate a gap, and yield to freeway traffic. Do not stop in the acceleration lane unless traffic leaves no safe alternative. On the freeway, maintain space, scan far ahead, avoid blind spots, and choose lanes early. For an exit, signal and enter the deceleration lane before reducing speed substantially.</p><h2>Breakdown</h2><p>Move out of travel lanes when possible, activate hazard warning lights, stop in the safest available location, and call for help. Stay away from moving traffic. Whether occupants should remain in or leave the vehicle depends on location and immediate hazards; choose the option that places people behind a barrier or farthest from traffic without crossing active lanes.</p><h2>Vehicle emergencies</h2><ul><li><strong>Skid:</strong> ease off the accelerator, look and steer toward the intended path, and avoid abrupt inputs.</li><li><strong>Blowout:</strong> hold the wheel firmly, ease off the accelerator, maintain direction, and slow gradually before leaving the roadway.</li><li><strong>Brake failure:</strong> try controlled braking methods appropriate to the system, shift to a lower gear when safe, use the parking brake gradually, and search for an escape path.</li><li><strong>Run off pavement:</strong> ease off the accelerator, stabilize, slow, and return gradually only when the edge and traffic permit.</li><li><strong>Steep downgrade:</strong> select a lower gear before the descent and avoid riding the brakes.</li></ul><h2>Winter and work zones</h2><p>Winter countermeasures include postponing travel, reducing speed, increasing space, making smooth inputs, clearing all glass and lights, and avoiding cruise control on slick surfaces. In construction or maintenance zones, obey temporary signs, markings, flaggers, and reduced speeds; expect abrupt stops, narrow lanes, equipment, and workers near traffic. Violations can cause bodily injury, death, property damage, and enhanced legal consequences.</p><h3>Guided decisions</h3><ol><li><p><strong>No freeway gap exists near the end of the acceleration lane.</strong></p><details><summary>Compare your decision</summary><p>Continue yielding and adjust speed; entering traffic does not gain priority by signaling.</p></details></li><li><p><strong>A tire blows at highway speed.</strong></p><details><summary>Compare your decision</summary><p>Grip firmly, maintain direction, ease off power, and slow gradually before moving off the road.</p></details></li><li><p><strong>Your wheels drop off pavement.</strong></p><details><summary>Compare your decision</summary><p>Do not jerk the wheel back. Stabilize, slow, and return gradually when safe.</p></details></li><li><p><strong>Ice is forecast along the route.</strong></p><details><summary>Compare your decision</summary><p>Consider delaying the trip; if travel is necessary, prepare the vehicle, reduce speed, add space, and use smooth controls.</p></details></li><li><p><strong>A flagger’s direction conflicts with normal markings.</strong></p><details><summary>Compare your decision</summary><p>Follow the authorized temporary control while continuing to protect workers and traffic.</p></details></li></ol></div>'.$note),
		);
	}

	private static function sign_visual_card( $file, $alt, $caption, $designation ) {
		$url = plugin_dir_url( __FILE__ ) . 'assets/signs/' . sanitize_file_name( $file );
		return '<figure style="margin:0;padding:16px;border:1px solid #cbd8e5;border-radius:12px;background:#fff;text-align:center"><img src="' . esc_url( $url ) . '?gb_signs=2026.07" alt="' . esc_attr( $alt ) . '" loading="lazy" style="display:block;width:100%;height:190px;object-fit:contain;margin:0 auto 12px"><figcaption><strong>' . esc_html( $caption ) . '</strong><br><small>FHWA Standard Highway Sign ' . esc_html( $designation ) . '</small></figcaption></figure>';
	}

	private static function topic_four_visuals( $key ) {
		$sets = array(
			'adult_en_010' => array(
				array('official-2026-r1-2-yield.svg','Red and white downward-pointing YIELD sign','Yield means give the required time and space','R1-2'),
			),
			'adult_en_011' => array(
				array('official-2026-r1-1-r1-3p-all-way-stop.png','STOP sign with ALL WAY plaque','Every approach must stop; then apply right-of-way rules','R1-1 + R1-3P'),
				array('official-2026-w2-1-crossroad.svg','Yellow diamond crossroad warning sign','Intersecting roadway ahead','W2-1'),
				array('official-2026-w2-2-side-road.svg','Yellow diamond side-road warning sign','Traffic may enter from the side road','W2-2'),
			),
			'adult_en_012' => array(
				array('official-2026-w4-1-merge.svg','Yellow diamond merging-traffic sign','Merging traffic — adjust speed and space','W4-1'),
				array('official-2026-r6-1-one-way.svg','Black and white ONE WAY arrow sign','Enter only in the arrow’s direction','R6-1'),
				array('official-2026-w6-1-divided-highway.svg','Yellow diamond divided-highway-begins sign','Keep right of the median','W6-1'),
			),
			'adult_en_013' => array(
				array('official-2026-w11-2-pedestrian.svg','Yellow diamond pedestrian warning sign','Pedestrians may be in or near the roadway','W11-2'),
			),
			'adult_en_014' => array(
				array('official-2026-w20-1-road-work.svg','Orange diamond ROAD WORK AHEAD sign','Workers and temporary traffic controls may be ahead','W20-1'),
			),
			'adult_en_015' => array(
				array('official-2026-w10-1-railroad-ahead.svg','Round yellow railroad advance-warning sign','Railroad crossing ahead — look, listen, prepare to stop','W10-1'),
				array('official-2026-r15-1-crossbuck.svg','White railroad crossbuck sign','Railroad grade crossing at the tracks','R15-1'),
			),
			'adult_en_016' => array(
				array('official-2026-r1-1-stop.svg','Red octagonal STOP regulatory sign','Regulatory: a required legal action','R1-1'),
				array('official-2026-w3-3-signal-ahead.svg','Yellow diamond warning sign showing a traffic signal','Warning: a condition requires preparation','W3-3'),
				array('official-2026-r6-1-one-way.svg','Black and white ONE WAY sign with arrow','Direction and roadway organization','R6-1'),
			),
			'adult_en_017' => array(
				array('official-2026-r1-1-stop.svg','Red octagonal STOP sign','STOP — complete stop required','R1-1'),
				array('official-2026-r1-2-yield.svg','Red and white downward-pointing YIELD sign','YIELD — give right-of-way','R1-2'),
				array('official-2026-r2-1-speed-limit.svg','White rectangular SPEED LIMIT sign','SPEED LIMIT — regulatory maximum','R2-1'),
				array('official-2026-r5-1-do-not-enter.svg','Red and white DO NOT ENTER sign','DO NOT ENTER — entry prohibited','R5-1'),
				array('official-2026-r5-1a-wrong-way.svg','Red rectangular WRONG WAY sign','WRONG WAY — opposing direction danger','R5-1a'),
				array('official-2026-r3-4-no-u-turn.svg','White NO U-TURN regulatory sign','NO U-TURN — movement prohibited','R3-4'),
				array('official-2026-r6-1-one-way.svg','Black and white ONE WAY sign','ONE WAY — permitted direction','R6-1'),
				array('official-2026-r1-1-r1-3p-all-way-stop.png','STOP sign with ALL WAY plaque','ALL WAY — every approach stops','R1-1 + R1-3P'),
			),
			'adult_en_018' => array(
				array('official-2026-w1-2-curve.svg','Yellow diamond sign showing a right curve','Curve ahead — choose speed before entry','W1-2'),
				array('official-2026-w1-1-turn.svg','Yellow diamond sign showing a sharp right turn','Sharp turn ahead — greater speed reduction','W1-1'),
				array('official-2026-w2-1-crossroad.svg','Yellow diamond crossroad warning sign','Crossroad ahead — search both sides','W2-1'),
				array('official-2026-w4-1-merge.svg','Yellow diamond merging traffic sign','Merging traffic — manage speed and space','W4-1'),
				array('official-2026-w8-5-slippery-when-wet.svg','Yellow diamond slippery when wet sign','Slippery when wet — smooth inputs','W8-5'),
				array('official-2026-w10-1-railroad-ahead.svg','Round yellow railroad advance warning sign','Railroad crossing ahead — look, listen, prepare','W10-1'),
				array('official-2026-w14-3-no-passing-zone.svg','Yellow pennant no-passing-zone sign','No-passing zone — remain in lane','W14-3'),
				array('official-2026-w12-2-low-clearance.svg','Yellow diamond low-clearance warning sign','Low clearance — verify vehicle height','W12-2'),
			),
			'adult_en_019' => array(
				array('official-2026-w20-1-road-work.svg','Orange diamond ROAD WORK AHEAD sign','Work zone — temporary conditions ahead','W20-1'),
				array('official-2026-w11-2-pedestrian.svg','Yellow diamond pedestrian warning sign','Pedestrians — reduce speed and search','W11-2'),
				array('official-2026-w3-3-signal-ahead.svg','Yellow diamond signal-ahead warning sign','Signal ahead — stopped traffic may be hidden','W3-3'),
			),
			'adult_en_020' => array(
				array('official-2026-w3-3-signal-ahead.svg','Yellow diamond sign displaying red yellow and green traffic lights','Signal ahead — prepare to stop','W3-3'),
				array('official-2026-r5-1-do-not-enter.svg','Red and white DO NOT ENTER sign','Red communicates prohibition or stop-related control','R5-1'),
			),
			'adult_en_021' => array(
				array('official-2026-w6-1-divided-highway.svg','Yellow diamond divided-highway-begins sign','Divider begins — keep right of median','W6-1'),
				array('official-2026-w6-3-two-way-traffic.svg','Yellow diamond two-way-traffic sign','Opposing traffic begins — keep right','W6-3'),
				array('official-2026-w14-3-no-passing-zone.svg','Yellow pennant no-passing-zone sign','Markings and signs work together','W14-3'),
			),
			'adult_en_022' => array(
				array('official-2026-r2-1-speed-limit.svg','White rectangular SPEED LIMIT sign','The posted maximum does not replace a safe speed for conditions','R2-1'),
				array('official-2026-w8-5-slippery-when-wet.svg','Yellow diamond slippery-when-wet sign','Reduce speed and increase following space','W8-5'),
			),
			'adult_en_023' => array(
				array('official-2026-w4-1-merge.svg','Yellow diamond merging-traffic sign','Search blind areas and manage a safe gap','W4-1'),
				array('official-2026-w6-1-divided-highway.svg','Yellow diamond divided-highway sign','Use the correct lane and keep right of the median','W6-1'),
			),
			'adult_en_024' => array(
				array('official-2026-r3-4-no-u-turn.svg','White regulatory NO U-TURN sign','Signals cannot authorize a prohibited movement','R3-4'),
				array('official-2026-r6-1-one-way.svg','Black and white ONE WAY sign','Communicate and enter in the permitted direction','R6-1'),
			),
			'adult_en_025' => array(
				array('official-2026-w14-3-no-passing-zone.svg','Yellow pennant no-passing-zone sign','Do not begin a passing maneuver','W14-3'),
				array('official-2026-w1-2-curve.svg','Yellow diamond curve warning sign','Limited sight distance can prohibit a safe pass','W1-2'),
			),
			'adult_en_026' => array(
				array('official-2026-w20-1-road-work.svg','Orange diamond ROAD WORK AHEAD sign','Expect temporary controls, workers, equipment, and abrupt stops','W20-1'),
				array('official-2026-w8-5-slippery-when-wet.svg','Yellow diamond slippery-when-wet sign','Smooth steering, braking, and acceleration reduce loss-of-control risk','W8-5'),
				array('official-2026-w3-3-signal-ahead.svg','Yellow diamond signal-ahead sign','Stopped freeway or work-zone traffic may be beyond sight distance','W3-3'),
			),
		);
		if ( empty( $sets[$key] ) ) return '';
		$html = '<section class="gb-sign-gallery" aria-labelledby="gb-signs-' . esc_attr( $key ) . '"><h2 id="gb-signs-' . esc_attr( $key ) . '">Learn the Device by Sight</h2><p>Study each official sign face. Identify its color and shape first, then explain the legal or risk-reducing response before reading the caption.</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:18px 0">';
		foreach ( $sets[$key] as $sign ) $html .= self::sign_visual_card( $sign[0], $sign[1], $sign[2], $sign[3] );
		$html .= '</div><p><small>Artwork: Federal Highway Administration 2024 Standard Highway Signs releases; traffic-control device designs are public domain under 23 CFR 655.603.</small></p></section>';
		return $html;
	}

	private static function complete_topic_four_content( $key, $content ) {
		$visuals = self::topic_four_visuals( $key );
		$position = strpos( $content, '<hr>' );
		return false === $position ? $content . $visuals : substr($content,0,$position) . $visuals . substr($content,$position);
	}

	public function admin_menu() {
		add_menu_page(
			'Gulf Breeze Core',
			'Gulf Breeze Core',
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' ),
			'dashicons-admin-settings',
			3
		);
		add_submenu_page(
			self::PAGE,
			'Core Status',
			'Core Status',
			'manage_options',
			'gulf-breeze-core-status',
			array( $this, 'render_status_page' )
		);
		add_submenu_page(
			self::PAGE,
			'Configuration',
			'Configuration',
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
		add_submenu_page(
			self::PAGE,
			'Course Registry',
			'Course Registry',
			'manage_options',
			'gulf-breeze-course-registry',
			array( $this, 'render_course_registry' )
		);
		add_submenu_page(
			self::PAGE,
			'Curriculum Blueprint',
			'Curriculum Blueprint',
			'manage_options',
			'gulf-breeze-curriculum-blueprint',
			array( $this, 'render_curriculum_blueprint' )
		);
	}

	public function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$learnpress     = in_array( 'learnpress/learnpress.php', $active_plugins, true );
		$under_plugin  = false;
		foreach ( $active_plugins as $plugin_file ) {
			if ( false !== stripos( $plugin_file, 'under-construction' ) ) {
				$under_plugin = true;
				break;
			}
		}
		$ucp_options = get_option( 'ucp_options', array() );
		$ucp_enabled = $under_plugin && is_array( $ucp_options ) && ! empty( $ucp_options['status'] );
		$ucp_no_end  = $ucp_enabled && empty( $ucp_options['end_date_toggle'] );
		$ucp_admin   = $ucp_enabled && in_array( 'administrator', (array) ( $ucp_options['whitelisted_roles'] ?? array() ), true );
		?>
		<div class="wrap">
			<h1>Gulf Breeze Core Status</h1>
			<p>Permanent modular foundation for the Gulf Breeze course and compliance system.</p>
			<table class="widefat striped" style="max-width:900px"><tbody>
				<tr><th>Core version</th><td><?php echo esc_html( defined( 'GB_CORE_VERSION' ) ? GB_CORE_VERSION : '1.0.0' ); ?></td></tr>
				<tr><th>Configuration module</th><td><strong style="color:#16752a">ACTIVE</strong></td></tr>
				<tr><th>LearnPress plugin</th><td><?php echo $learnpress ? '<strong style="color:#16752a">ACTIVE</strong>' : '<strong style="color:#b32d2e">NOT ACTIVE</strong>'; ?></td></tr>
				<tr><th>Under Construction plugin</th><td><?php echo $under_plugin ? '<strong style="color:#16752a">ACTIVE</strong>' : '<strong style="color:#b32d2e">NOT ACTIVE</strong>'; ?></td></tr>
				<tr><th>Public-site protection mode</th><td><?php echo $ucp_enabled ? '<strong style="color:#16752a">ENABLED</strong>' : '<strong style="color:#b32d2e">DISABLED — ACTION REQUIRED</strong>'; ?></td></tr>
				<tr><th>Automatic protection end date</th><td><?php echo $ucp_no_end ? 'None configured' : 'Configured or protection unavailable'; ?></td></tr>
				<tr><th>Administrator preview access</th><td><?php echo $ucp_admin ? 'Allowed' : 'Not verified'; ?></td></tr>
				<tr><th>Course Registry module</th><td><strong style="color:#16752a">ACTIVE</strong></td></tr>
				<tr><th>Admin/student privacy boundary</th><td><strong style="color:#16752a">ACTIVE</strong> — internal controls hidden from non-administrators</td></tr>
				<tr><th>May 2026 POI blueprint</th><td><strong style="color:#16752a">ACTIVE</strong> — adopted teen and adult source editions locked</td></tr>
				<tr><th>Adult English objective crosswalk</th><td><strong style="color:#16752a">ACTIVE</strong> — 46 short lessons / 330 instructional minutes</td></tr>
				<tr><th>Seat-time enforcement</th><td><strong style="color:#16752a">ACTIVE</strong> — server-recorded active time, visibility/activity checks, early-completion block, LearnPress completion bridge</td></tr>
				<tr><th>Assessment enforcement</th><td>Not installed yet</td></tr>
				<tr><th>Payment modules</th><td>Not installed yet</td></tr>
			</tbody></table>
			<p><strong>Safety rule:</strong> missing legal configuration values render blank. The plugin does not fabricate provider numbers, approvals, addresses, or policy text.</p>
		</div>
		<?php
	}

	public function register_settings() {
		register_setting(
			'gb_config_group',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => array() )
		);
		register_setting(
			'gb_course_registry_group',
			self::COURSE_OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize_course_registry' ), 'default' => array() )
		);
	}

	public function render_curriculum_blueprint() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Gulf Breeze Curriculum Blueprint</h1>
			<?php if ( isset( $_GET['gb_adult_built'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( absint( $_GET['gb_adult_built'] ) ); ?> Adult English lesson shell(s) created; <?php echo esc_html( absint( $_GET['gb_adult_reused'] ?? 0 ) ); ?> existing shell(s) verified and retained.</p></div>
			<?php elseif ( isset( $_GET['gb_topic_one_built'] ) ) : ?>
				<div class="notice notice-success inline"><p>Adult English Topic 4.1.1 content loaded into <?php echo esc_html( absint( $_GET['gb_topic_one_built'] ) ); ?> internal lesson item(s). The parent course remains Draft. Required time: 10 instructional minutes.</p></div>
			<?php elseif ( isset( $_GET['gb_topic_two_built'] ) ) : ?>
				<div class="notice notice-success inline"><p>Adult English Topic 4.1.2 content loaded into <?php echo esc_html( absint( $_GET['gb_topic_two_built'] ) ); ?> internal lesson item(s). Required time: 24 instructional minutes.</p></div>
			<?php elseif ( isset( $_GET['gb_topic_three_built'] ) ) : ?>
				<div class="notice notice-success inline"><p>Adult English Topic 4.1.3 content loaded into <?php echo esc_html( absint( $_GET['gb_topic_three_built'] ) ); ?> internal lesson item(s). Required time: 42 instructional minutes.</p></div>
			<?php elseif ( isset( $_GET['gb_topic_four_built'] ) ) : ?>
				<div class="notice notice-success inline"><p>Adult English Topic 4.1.4 content loaded into <?php echo esc_html( absint( $_GET['gb_topic_four_built'] ) ); ?> internal lesson item(s). Required time: 37 instructional minutes.</p></div>
			<?php elseif ( isset( $_GET['gb_topic_five_built'] ) ) : ?>
				<div class="notice notice-success inline"><p>Adult English Topic 4.1.5 content loaded into <?php echo esc_html( absint( $_GET['gb_topic_five_built'] ) ); ?> internal lesson item(s). Required time: 32 instructional minutes.</p></div>
			<?php elseif ( isset( $_GET['gb_adult_error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['gb_adult_error'] ) ) ); ?></p></div>
			<?php endif; ?>
			<div class="notice notice-warning inline"><p><strong>Internal only.</strong> This is a compliance construction ledger, not student-facing course content and not a public approval claim.</p></div>
			<p><strong>Lesson architecture:</strong> divide each required POI objective into multiple focused lessons, normally 10–20 minutes each. No hour-long lesson blocks. English and Spanish must use equivalent objectives and timing.</p>
			<p><strong>Source rule:</strong> lesson content may be written only after it is cross-referenced to the controlling POI objective, current Texas law, current Texas Driver Handbook, and current safety data. Unknown facts remain unbuilt until verified.</p>
			<?php foreach ( self::curriculum_blueprints() as $program_key => $blueprint ) : ?>
				<h2><?php echo esc_html( 'parent_taught' === $program_key ? 'Parent-Taught — English and Spanish' : 'Adult Six-Hour — English and Spanish' ); ?></h2>
				<p><strong>Controlling source:</strong> <a href="<?php echo esc_url( $blueprint['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $blueprint['source'] ); ?></a></p>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr><th>POI unit</th><th>Required subject</th><th>Timing ledger</th><th>2026 work-zone overlay</th></tr></thead>
					<tbody>
					<?php foreach ( $blueprint['units'] as $unit_key => $unit_label ) : ?>
						<tr>
							<td><code><?php echo esc_html( $unit_key ); ?></code></td>
							<td><?php echo esc_html( $unit_label ); ?></td>
							<td>Short-lesson allocation pending objective-level crosswalk</td>
							<td><?php echo in_array( (string) $unit_key, $blueprint['work_zone_units'], true ) ? '<strong>REQUIRED</strong>' : 'Covered where required by POI'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot><tr><th colspan="2">Program totals</th><td colspan="2"><?php echo esc_html( number_format_i18n( $blueprint['total_minutes'] ) ); ?> total = <?php echo esc_html( number_format_i18n( $blueprint['minimum_content'] ) ); ?> required content + <?php echo esc_html( number_format_i18n( $blueprint['flex_minutes'] ) ); ?> additional instruction/break</td></tr></tfoot>
				</table>
			<?php endforeach; ?>
			<h2>Adult English — Objective-Level Lesson Crosswalk</h2>
			<p><strong>Instruction ledger:</strong> 46 focused lessons ranging from 2–22 minutes = exactly 330 instructional minutes. Add three separate 10-minute breaks after Topics 3, 6, and 8 and before the comprehensive final examination. Total course ledger: 360 minutes. The final examination does not count toward instructional minutes.</p>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>#</th><th>POI topic</th><th>English lesson shell</th><th>Minutes</th><th>Required objective coverage</th><th>Validation</th></tr></thead>
				<tbody>
				<?php $adult_total = 0; foreach ( self::adult_english_crosswalk() as $index => $lesson ) : $adult_total += $lesson['minutes']; ?>
					<tr>
						<td><?php echo esc_html( $index + 1 ); ?></td>
						<td><code><?php echo esc_html( $lesson['topic'] ); ?></code></td>
						<td><?php echo esc_html( $lesson['lesson'] ); ?></td>
						<td><?php echo esc_html( $lesson['minutes'] ); ?></td>
						<td><?php echo esc_html( $lesson['coverage'] ); ?></td>
						<td><?php echo '4.1.1' === $lesson['topic'] ? 'Lesson check' : 'Lesson check + Topic participation bank'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot><tr><th colspan="3">Instructional total</th><th><?php echo esc_html( $adult_total ); ?></th><th colspan="2"><?php echo 330 === $adult_total ? '<strong>EXACT</strong>' : '<strong>ERROR — MUST EQUAL 330</strong>'; ?></th></tr></tfoot>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 26px">
				<input type="hidden" name="action" value="gb_build_adult_english_shells">
				<?php wp_nonce_field( 'gb_build_adult_english_shells' ); ?>
				<?php submit_button( 'Create or Repair Adult English LearnPress Shells', 'primary', 'submit', false ); ?>
				<p class="description">Creates nine POI topic sections and 46 internal LearnPress lesson items while keeping the parent course Draft and inaccessible. LearnPress requires attached lesson items to use its published item status before it will count them. Direct access and sitemap exposure remain blocked by Gulf Breeze Core.</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 26px">
				<input type="hidden" name="action" value="gb_build_adult_topic_one">
				<?php wp_nonce_field( 'gb_build_adult_topic_one' ); ?>
				<?php submit_button( 'Load or Refresh Adult English Topic 4.1.1 Content', 'secondary', 'submit', false ); ?>
				<p class="description">Loads the four sourced Course Introduction lessons into their internal LearnPress items. It does not publish the parent course or claim approval.</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 26px">
				<input type="hidden" name="action" value="gb_build_adult_topic_two">
				<?php wp_nonce_field( 'gb_build_adult_topic_two' ); ?>
				<?php submit_button( 'Load or Refresh Adult English Topic 4.1.2 Content', 'secondary', 'submit', false ); ?>
				<p class="description">Loads five sourced Your License to Drive lessons totaling 24 instructional minutes. The public construction barrier remains unchanged.</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 26px">
				<input type="hidden" name="action" value="gb_build_adult_topic_three">
				<?php wp_nonce_field( 'gb_build_adult_topic_three' ); ?>
				<?php submit_button( 'Load or Refresh Adult English Topic 4.1.3 Content', 'secondary', 'submit', false ); ?>
				<p class="description">Loads six sourced Right-of-Way lessons totaling 42 instructional minutes. The public construction barrier remains unchanged.</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 26px">
				<input type="hidden" name="action" value="gb_build_adult_topic_four">
				<?php wp_nonce_field( 'gb_build_adult_topic_four' ); ?>
				<?php submit_button( 'Load or Refresh Adult English Topic 4.1.4 Content', 'secondary', 'submit', false ); ?>
				<p class="description">Loads six sourced Traffic Control Devices lessons totaling 37 instructional minutes.</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0 26px">
				<input type="hidden" name="action" value="gb_build_adult_topic_five">
				<?php wp_nonce_field( 'gb_build_adult_topic_five' ); ?>
				<?php submit_button( 'Load or Refresh Adult English Topic 4.1.5 Content', 'secondary', 'submit', false ); ?>
				<p class="description">Loads five sourced Controlling Traffic Flow lessons totaling 32 instructional minutes with locally hosted instructional visuals.</p>
			</form>
			<h3>Adult English — Live Timing Audit (Topics 4.1.1–4.1.3)</h3>
			<p>This audit reads the installed LearnPress lesson records. A lesson passes only when its server-enforced timer exactly matches the adopted POI ledger and its guided-practice block is present.</p>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>#</th><th>POI topic</th><th>Lesson</th><th>Required</th><th>Installed timer</th><th>Guided practice</th><th>Result</th></tr></thead>
				<tbody>
				<?php
				$timing_expected = 0;
				$timing_installed = 0;
				$timing_passed = 0;
				foreach ( array_slice( self::adult_english_crosswalk(), 0, 15 ) as $audit_index => $audit_lesson ) :
					$audit_key = 'adult_en_' . str_pad( (string) ( $audit_index + 1 ), 3, '0', STR_PAD_LEFT );
					$audit_posts = get_posts( array( 'post_type' => 'lp_lesson', 'post_status' => array( 'publish', 'draft', 'private', 'pending' ), 'meta_key' => '_gb_blueprint_key', 'meta_value' => $audit_key, 'posts_per_page' => 1 ) );
					$audit_post = $audit_posts ? $audit_posts[0] : null;
					$expected_seconds = absint( $audit_lesson['minutes'] ) * 60;
					$installed_seconds = $audit_post ? absint( get_post_meta( $audit_post->ID, '_gb_required_seconds', true ) ) : 0;
					$has_practice = $audit_post && false !== strpos( $audit_post->post_content, 'gb-guided-practice' );
					$is_exact = $audit_post && $expected_seconds === $installed_seconds && $has_practice;
					$timing_expected += $expected_seconds;
					$timing_installed += $installed_seconds;
					$timing_passed += $is_exact ? 1 : 0;
				?>
				<tr>
					<td><?php echo esc_html( $audit_index + 1 ); ?></td>
					<td><code><?php echo esc_html( $audit_lesson['topic'] ); ?></code></td>
					<td><?php echo esc_html( $audit_lesson['lesson'] ); ?></td>
					<td><?php echo esc_html( gmdate( 'i:s', $expected_seconds ) ); ?></td>
					<td><?php echo $audit_post ? esc_html( gmdate( 'i:s', $installed_seconds ) ) : '<strong style="color:#b32d2e">MISSING</strong>'; ?></td>
					<td><?php echo $has_practice ? '<strong style="color:#16752a">PRESENT</strong>' : '<strong style="color:#b32d2e">MISSING</strong>'; ?></td>
					<td><?php echo $is_exact ? '<strong style="color:#16752a">EXACT</strong>' : '<strong style="color:#b32d2e">REPAIR REQUIRED</strong>'; ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot><tr><th colspan="3">Topics 4.1.1–4.1.3 total</th><th><?php echo esc_html( round( $timing_expected / 60 ) ); ?> minutes</th><th><?php echo esc_html( round( $timing_installed / 60 ) ); ?> minutes</th><th colspan="2"><?php echo 15 === $timing_passed && $timing_expected === $timing_installed ? '<strong style="color:#16752a">15/15 EXACT</strong>' : '<strong style="color:#b32d2e">AUDIT FAILED</strong>'; ?></th></tr></tfoot>
			</table>
			<h3>Adult Online Assessment and Security Controls</h3>
			<ul>
				<li>Topics 2–8: at least 10 participation questions in each topic bank; administer at least 2 at the end of each topic.</li>
				<li>Any multimedia clip longer than 180 seconds: at least 4 clip-specific questions; ask at least 1 after the clip; an incorrect answer requires replay and a different question.</li>
				<li>Comprehensive final: at least 30 questions, drawn equally from separate highway-sign and traffic-law banks; mastery is 70% or above.</li>
				<li>Each retest uses different questions. Three failed comprehensive-final attempts fail the course.</li>
				<li>Identity validation, question/response history, staff changes, time per unit, and total instructional time must be retained in the protected student record.</li>
			</ul>
			<p><strong>Build lock:</strong> course shells remain drafts. No course can advance to internal testing until every objective has a source reference, assigned minutes, English content, equivalent Spanish content, and an assessment mapping.</p>
		</div>
		<?php
	}

	public function build_adult_english_shells() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to build course curriculum.', 'gulf-breeze-core' ) );
		}
		check_admin_referer( 'gb_build_adult_english_shells' );

		$registry  = get_option( self::COURSE_OPTION, array() );
		$course_id = absint( $registry['adult_en']['learnpress_course_id'] ?? 0 );
		$base_url  = admin_url( 'admin.php?page=gulf-breeze-curriculum-blueprint' );

		if ( ! $course_id || 'lp_course' !== get_post_type( $course_id ) ) {
			wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'Adult English must be mapped to a valid LearnPress course in Course Registry first.' ), $base_url ) );
			exit;
		}
		if ( ! class_exists( 'LP_Section_CURD' ) || ! defined( 'LP_LESSON_CPT' ) ) {
			wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'LearnPress curriculum services are not available.' ), $base_url ) );
			exit;
		}

		global $wpdb;
		$section_curd = new LP_Section_CURD( $course_id );
		$topic_names  = self::curriculum_blueprints()['adult_six_hour']['units'];
		$sections     = array();

		foreach ( $topic_names as $topic => $name ) {
			$section_name = 'Topic ' . $topic . ' — ' . $name;
			$section_id   = absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_sections} WHERE section_course_id = %d AND section_name = %s LIMIT 1", $course_id, $section_name ) ) );
			if ( ! $section_id ) {
				$args    = array( 'section_course_id' => $course_id, 'section_name' => $section_name, 'section_description' => 'Internal Gulf Breeze POI curriculum section ' . $topic . '.' );
				$section = $section_curd->create( $args );
				$section_id = absint( $section['section_id'] ?? 0 );
			}
			if ( ! $section_id ) {
				wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A LearnPress topic section could not be created. No course was published.' ), $base_url ) );
				exit;
			}
			$sections[ $topic ] = $section_id;
		}

		$created = 0;
		$reused  = 0;
		foreach ( self::adult_english_crosswalk() as $index => $lesson ) {
			$key      = 'adult_en_' . str_pad( (string) ( $index + 1 ), 3, '0', STR_PAD_LEFT );
			$existing = get_posts( array( 'post_type' => LP_LESSON_CPT, 'post_status' => array( 'draft', 'pending', 'private', 'publish' ), 'meta_key' => '_gb_blueprint_key', 'meta_value' => $key, 'posts_per_page' => 1, 'fields' => 'ids' ) );
			$item_id  = $existing ? absint( $existing[0] ) : 0;

			if ( $item_id ) {
				if ( 'publish' !== get_post_status( $item_id ) ) {
					wp_update_post( array( 'ID' => $item_id, 'post_status' => 'publish' ) );
				}
				$assigned_section = absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_section_items} WHERE item_id = %d LIMIT 1", $item_id ) ) );
				if ( ! $assigned_section ) {
					$section_curd->add_items_section( $sections[ $lesson['topic'] ], array( array( 'id' => $item_id, 'type' => LP_LESSON_CPT ) ) );
				}
				$reused++;
			} else {
				$result = $section_curd->new_item( $sections[ $lesson['topic'] ], array( 'title' => $lesson['lesson'], 'type' => LP_LESSON_CPT, 'content' => '', 'status' => 'publish' ) );
				$item_id = is_array( $result ) && ! empty( $result[0]['id'] ) ? absint( $result[0]['id'] ) : 0;
				if ( ! $item_id ) {
					wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A LearnPress lesson shell could not be created. Existing drafts were left intact.' ), $base_url ) );
					exit;
				}
				$created++;
			}

			update_post_meta( $item_id, '_gb_blueprint_key', $key );
			update_post_meta( $item_id, '_gb_course_key', 'adult_en' );
			update_post_meta( $item_id, '_gb_poi_topic', $lesson['topic'] );
			update_post_meta( $item_id, '_gb_poi_coverage', $lesson['coverage'] );
			update_post_meta( $item_id, '_gb_required_minutes', absint( $lesson['minutes'] ) );
			update_post_meta( $item_id, '_gb_required_seconds', absint( $lesson['minutes'] ) * 60 );
			update_post_meta( $item_id, '_gb_content_status', 'internal_item_empty' );
		}

		$this->refresh_learnpress_course_cache( $course_id );
		wp_safe_redirect( add_query_arg( array( 'gb_adult_built' => $created, 'gb_adult_reused' => $reused ), $base_url ) );
		exit;
	}

	public function build_adult_topic_one() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to build course content.', 'gulf-breeze-core' ) );
		}
		check_admin_referer( 'gb_build_adult_topic_one' );

		$base_url  = admin_url( 'admin.php?page=gulf-breeze-curriculum-blueprint' );
		$registry  = get_option( self::COURSE_OPTION, array() );
		$course_id = absint( $registry['adult_en']['learnpress_course_id'] ?? 0 );
		$built     = 0;
		global $wpdb;
		$section_name = 'Topic 4.1.1 — Course Introduction';
		$section_id   = $course_id ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_sections} WHERE section_course_id = %d AND section_name = %s LIMIT 1", $course_id, $section_name ) ) ) : 0;
		$section_curd = $course_id && class_exists( 'LP_Section_CURD' ) ? new LP_Section_CURD( $course_id ) : null;
		if ( ! $section_id || ! $section_curd ) {
			wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'The Adult English Topic 4.1.1 LearnPress section is missing. Run the shell builder first.' ), $base_url ) );
			exit;
		}
		foreach ( self::adult_topic_one_content() as $key => $lesson ) {
			$posts = get_posts( array( 'post_type' => 'lp_lesson', 'post_status' => array( 'draft', 'pending', 'private', 'publish' ), 'meta_key' => '_gb_blueprint_key', 'meta_value' => $key, 'posts_per_page' => 1 ) );
			if ( ! $posts ) {
				wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A required Topic 4.1.1 lesson shell is missing. Run the Adult English shell builder first.' ), $base_url ) );
				exit;
			}
			$lesson_id = absint( $posts[0]->ID );
			$result    = wp_update_post( array( 'ID' => $lesson_id, 'post_title' => $lesson['title'], 'post_content' => self::complete_timed_content( $key, $lesson['content'] ), 'post_status' => 'publish' ), true );
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A Topic 4.1.1 lesson could not be updated. No lesson was published.' ), $base_url ) );
				exit;
			}
			update_post_meta( $lesson_id, '_gb_poi_topic', '4.1.1' );
			update_post_meta( $lesson_id, '_gb_poi_coverage', $lesson['objective'] );
			update_post_meta( $lesson_id, '_gb_required_minutes', absint( $lesson['minutes'] ) );
			update_post_meta( $lesson_id, '_gb_required_seconds', absint( $lesson['minutes'] ) * 60 );
			update_post_meta( $lesson_id, '_gb_content_status', 'internal_item_sourced_topic_one' );
			update_post_meta( $lesson_id, '_gb_source_poi', 'TDLR POI-Adult Six-Hour, May 2026, 4.1.1' );
			update_post_meta( $lesson_id, '_gb_source_handbook', 'Texas Driver Handbook DL-7, January 2026' );
			$assigned_section = absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_section_items} WHERE item_id = %d LIMIT 1", $lesson_id ) ) );
			if ( ! $assigned_section ) {
				$section_curd->add_items_section( $section_id, array( array( 'id' => $lesson_id, 'type' => LP_LESSON_CPT ) ) );
			}
			$built++;
		}

		$this->refresh_learnpress_course_cache( $course_id );
		wp_safe_redirect( add_query_arg( 'gb_topic_one_built', $built, $base_url ) );
		exit;
	}

	public function build_adult_topic_two() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to build course content.', 'gulf-breeze-core' ) );
		}
		check_admin_referer( 'gb_build_adult_topic_two' );
		$base_url  = admin_url( 'admin.php?page=gulf-breeze-curriculum-blueprint' );
		$registry  = get_option( self::COURSE_OPTION, array() );
		$course_id = absint( $registry['adult_en']['learnpress_course_id'] ?? 0 );
		global $wpdb;
		$section_name = 'Topic 4.1.2 — Your License to Drive';
		$section_id   = $course_id ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_sections} WHERE section_course_id = %d AND section_name = %s LIMIT 1", $course_id, $section_name ) ) ) : 0;
		$section_curd = $course_id && class_exists( 'LP_Section_CURD' ) ? new LP_Section_CURD( $course_id ) : null;
		if ( ! $section_id || ! $section_curd ) {
			wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'The Adult English Topic 4.1.2 LearnPress section is missing. Run the shell builder first.' ), $base_url ) );
			exit;
		}
		$built = 0;
		foreach ( self::adult_topic_two_content() as $key => $lesson ) {
			$posts = get_posts( array( 'post_type' => 'lp_lesson', 'post_status' => array( 'draft', 'pending', 'private', 'publish' ), 'meta_key' => '_gb_blueprint_key', 'meta_value' => $key, 'posts_per_page' => 1 ) );
			if ( ! $posts ) {
				wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A required Topic 4.1.2 lesson shell is missing.' ), $base_url ) );
				exit;
			}
			$lesson_id = absint( $posts[0]->ID );
			$result = wp_update_post( array( 'ID' => $lesson_id, 'post_title' => $lesson['title'], 'post_content' => self::complete_timed_content( $key, $lesson['content'] ), 'post_status' => 'publish' ), true );
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A Topic 4.1.2 lesson could not be updated.' ), $base_url ) );
				exit;
			}
			update_post_meta( $lesson_id, '_gb_poi_topic', '4.1.2' );
			update_post_meta( $lesson_id, '_gb_poi_coverage', $lesson['objective'] );
			update_post_meta( $lesson_id, '_gb_required_minutes', absint( $lesson['minutes'] ) );
			update_post_meta( $lesson_id, '_gb_required_seconds', absint( $lesson['minutes'] ) * 60 );
			update_post_meta( $lesson_id, '_gb_content_status', 'internal_item_sourced_topic_two' );
			update_post_meta( $lesson_id, '_gb_source_poi', 'TDLR POI-Adult Six-Hour, May 2026, 4.1.2' );
			update_post_meta( $lesson_id, '_gb_source_handbook', 'Texas Driver Handbook DL-7, January 2026, Chapters 1-3' );
			$assigned = absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_section_items} WHERE item_id = %d LIMIT 1", $lesson_id ) ) );
			if ( ! $assigned ) {
				$section_curd->add_items_section( $section_id, array( array( 'id' => $lesson_id, 'type' => LP_LESSON_CPT ) ) );
			}
			$built++;
		}
		$this->refresh_learnpress_course_cache( $course_id );
		wp_safe_redirect( add_query_arg( 'gb_topic_two_built', $built, $base_url ) );
		exit;
	}

	public function build_adult_topic_three() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to build course content.', 'gulf-breeze-core' ) );
		}
		check_admin_referer( 'gb_build_adult_topic_three' );
		$base_url  = admin_url( 'admin.php?page=gulf-breeze-curriculum-blueprint' );
		$registry  = get_option( self::COURSE_OPTION, array() );
		$course_id = absint( $registry['adult_en']['learnpress_course_id'] ?? 0 );
		global $wpdb;
		$section_name = 'Topic 4.1.3 — Right-of-Way';
		$section_id   = $course_id ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_sections} WHERE section_course_id = %d AND section_name = %s LIMIT 1", $course_id, $section_name ) ) ) : 0;
		$section_curd = $course_id && class_exists( 'LP_Section_CURD' ) ? new LP_Section_CURD( $course_id ) : null;
		if ( ! $section_id || ! $section_curd ) {
			wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'The Adult English Topic 4.1.3 LearnPress section is missing. Run the shell builder first.' ), $base_url ) );
			exit;
		}
		$built = 0;
		foreach ( self::adult_topic_three_content() as $key => $lesson ) {
			$posts = get_posts( array( 'post_type' => 'lp_lesson', 'post_status' => array( 'draft', 'pending', 'private', 'publish' ), 'meta_key' => '_gb_blueprint_key', 'meta_value' => $key, 'posts_per_page' => 1 ) );
			if ( ! $posts ) {
				wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A required Topic 4.1.3 lesson shell is missing.' ), $base_url ) );
				exit;
			}
			$lesson_id = absint( $posts[0]->ID );
			$result = wp_update_post( array( 'ID' => $lesson_id, 'post_title' => $lesson['title'], 'post_content' => self::complete_timed_content( $key, $lesson['content'] ), 'post_status' => 'publish' ), true );
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A Topic 4.1.3 lesson could not be updated.' ), $base_url ) );
				exit;
			}
			update_post_meta( $lesson_id, '_gb_poi_topic', '4.1.3' );
			update_post_meta( $lesson_id, '_gb_poi_coverage', $lesson['objective'] );
			update_post_meta( $lesson_id, '_gb_required_minutes', absint( $lesson['minutes'] ) );
			update_post_meta( $lesson_id, '_gb_required_seconds', absint( $lesson['minutes'] ) * 60 );
			update_post_meta( $lesson_id, '_gb_content_status', 'internal_item_sourced_topic_three' );
			update_post_meta( $lesson_id, '_gb_source_poi', 'TDLR POI-Adult Six-Hour, May 2026, 4.1.3' );
			update_post_meta( $lesson_id, '_gb_source_handbook', 'Texas Driver Handbook DL-7, January 2026' );
			$assigned = absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_section_items} WHERE item_id = %d LIMIT 1", $lesson_id ) ) );
			if ( ! $assigned ) {
				$section_curd->add_items_section( $section_id, array( array( 'id' => $lesson_id, 'type' => LP_LESSON_CPT ) ) );
			}
			$built++;
		}
		$this->refresh_learnpress_course_cache( $course_id );
		wp_safe_redirect( add_query_arg( 'gb_topic_three_built', $built, $base_url ) );
		exit;
	}

	public function build_adult_topic_four() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to build course content.', 'gulf-breeze-core' ) );
		}
		check_admin_referer( 'gb_build_adult_topic_four' );
		$base_url = admin_url( 'admin.php?page=gulf-breeze-curriculum-blueprint' );
		$registry = get_option( self::COURSE_OPTION, array() );
		$course_id = absint( $registry['adult_en']['learnpress_course_id'] ?? 0 );
		global $wpdb;
		$section_name = 'Topic 4.1.4 — Traffic Control Devices';
		$section_id = $course_id ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_sections} WHERE section_course_id = %d AND section_name = %s LIMIT 1", $course_id, $section_name ) ) ) : 0;
		$section_curd = $course_id && class_exists( 'LP_Section_CURD' ) ? new LP_Section_CURD( $course_id ) : null;
		if ( ! $section_id || ! $section_curd ) {
			wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'The Adult English Topic 4.1.4 LearnPress section is missing.' ), $base_url ) ); exit;
		}
		$built = 0;
		foreach ( self::adult_topic_four_content() as $key => $lesson ) {
			$posts = get_posts( array( 'post_type'=>'lp_lesson', 'post_status'=>array('draft','pending','private','publish'), 'meta_key'=>'_gb_blueprint_key', 'meta_value'=>$key, 'posts_per_page'=>1 ) );
			if ( ! $posts ) { wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A required Topic 4.1.4 lesson shell is missing.' ), $base_url ) ); exit; }
			$lesson_id = absint( $posts[0]->ID );
			$result = wp_update_post( array( 'ID'=>$lesson_id, 'post_title'=>$lesson['title'], 'post_content'=>self::complete_topic_four_content( $key, $lesson['content'] ), 'post_status'=>'publish' ), true );
			if ( is_wp_error( $result ) ) { wp_safe_redirect( add_query_arg( 'gb_adult_error', rawurlencode( 'A Topic 4.1.4 lesson could not be updated.' ), $base_url ) ); exit; }
			update_post_meta( $lesson_id, '_gb_poi_topic', '4.1.4' );
			update_post_meta( $lesson_id, '_gb_poi_coverage', $lesson['objective'] );
			update_post_meta( $lesson_id, '_gb_required_minutes', absint( $lesson['minutes'] ) );
			update_post_meta( $lesson_id, '_gb_required_seconds', absint( $lesson['minutes'] ) * 60 );
			update_post_meta( $lesson_id, '_gb_content_status', 'internal_item_sourced_topic_four' );
			update_post_meta( $lesson_id, '_gb_source_poi', 'TDLR POI-Adult Six-Hour, May 2026, 4.1.4' );
			update_post_meta( $lesson_id, '_gb_source_handbook', 'Texas Driver Handbook DL-7, January 2026, Chapter 5' );
			$assigned = absint( $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->learnpress_section_items} WHERE item_id = %d LIMIT 1", $lesson_id ) ) );
			if ( ! $assigned ) { $section_curd->add_items_section( $section_id, array( array( 'id'=>$lesson_id, 'type'=>LP_LESSON_CPT ) ) ); }
			$built++;
		}
		$this->refresh_learnpress_course_cache( $course_id );
		wp_safe_redirect( add_query_arg( 'gb_topic_four_built', $built, $base_url ) ); exit;
	}

	public function build_adult_topic_five() {
		if(!current_user_can('manage_options'))wp_die(esc_html__('You are not allowed to build course content.','gulf-breeze-core'));
		check_admin_referer('gb_build_adult_topic_five');
		$base_url=admin_url('admin.php?page=gulf-breeze-curriculum-blueprint'); $registry=get_option(self::COURSE_OPTION,array()); $course_id=absint($registry['adult_en']['learnpress_course_id']??0); global $wpdb;
		$section_name='Topic 4.1.5 — Controlling Traffic Flow'; $section_id=$course_id?absint($wpdb->get_var($wpdb->prepare("SELECT section_id FROM {$wpdb->learnpress_sections} WHERE section_course_id=%d AND section_name=%s LIMIT 1",$course_id,$section_name))):0; $section_curd=$course_id&&class_exists('LP_Section_CURD')?new LP_Section_CURD($course_id):null;
		if(!$section_id||!$section_curd){wp_safe_redirect(add_query_arg('gb_adult_error',rawurlencode('The Adult English Topic 4.1.5 LearnPress section is missing.'),$base_url));exit;}
		$built=0; foreach(self::adult_topic_five_content() as $key=>$lesson){
			$posts=get_posts(array('post_type'=>'lp_lesson','post_status'=>array('draft','pending','private','publish'),'meta_key'=>'_gb_blueprint_key','meta_value'=>$key,'posts_per_page'=>1)); if(!$posts){wp_safe_redirect(add_query_arg('gb_adult_error',rawurlencode('A required Topic 4.1.5 lesson shell is missing.'),$base_url));exit;}
			$lesson_id=absint($posts[0]->ID); $result=wp_update_post(array('ID'=>$lesson_id,'post_title'=>$lesson['title'],'post_content'=>self::complete_topic_four_content($key,$lesson['content']),'post_status'=>'publish'),true); if(is_wp_error($result)){wp_safe_redirect(add_query_arg('gb_adult_error',rawurlencode('A Topic 4.1.5 lesson could not be updated.'),$base_url));exit;}
			update_post_meta($lesson_id,'_gb_poi_topic','4.1.5'); update_post_meta($lesson_id,'_gb_poi_coverage',$lesson['objective']); update_post_meta($lesson_id,'_gb_required_minutes',absint($lesson['minutes'])); update_post_meta($lesson_id,'_gb_required_seconds',absint($lesson['minutes'])*60); update_post_meta($lesson_id,'_gb_content_status','internal_item_sourced_topic_five'); update_post_meta($lesson_id,'_gb_source_poi','TDLR POI-Adult Six-Hour, May 2026, 4.1.5'); update_post_meta($lesson_id,'_gb_source_handbook','Texas Driver Handbook DL-7, January 2026, Chapters 6-9');
			$assigned=absint($wpdb->get_var($wpdb->prepare("SELECT section_id FROM {$wpdb->learnpress_section_items} WHERE item_id=%d LIMIT 1",$lesson_id))); if(!$assigned)$section_curd->add_items_section($section_id,array(array('id'=>$lesson_id,'type'=>LP_LESSON_CPT))); $built++;
		}
		$this->refresh_learnpress_course_cache($course_id); wp_safe_redirect(add_query_arg('gb_topic_five_built',$built,$base_url));exit;
	}

	public function sanitize_course_registry( $submitted ) {
		$submitted = is_array( $submitted ) ? $submitted : array();
		$clean = array();
		foreach ( self::course_definitions() as $course_key => $definition ) {
			$row = isset( $submitted[ $course_key ] ) && is_array( $submitted[ $course_key ] ) ? $submitted[ $course_key ] : array();
			$course_id = absint( $row['learnpress_course_id'] ?? 0 );
			if ( $course_id && 'lp_course' !== get_post_type( $course_id ) ) {
				$course_id = 0;
			}
			$clean[ $course_key ] = array(
				'learnpress_course_id' => $course_id,
				'status'               => in_array( $row['status'] ?? '', array( 'planned', 'building', 'internal_testing', 'ready_for_review', 'approved' ), true ) ? $row['status'] : 'planned',
			);
		}
		$clean['_updated_at'] = current_time( 'mysql', true );
		$clean['_updated_by'] = get_current_user_id();
		return $clean;
	}

	public function create_course_shells() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to create course shells.', 'gulf-breeze-core' ) );
		}
		check_admin_referer( 'gb_create_course_shells' );

		$registry = get_option( self::COURSE_OPTION, array() );
		$created  = 0;
		foreach ( self::course_definitions() as $course_key => $definition ) {
			$row = isset( $registry[ $course_key ] ) && is_array( $registry[ $course_key ] ) ? $registry[ $course_key ] : array();
			$course_id = absint( $row['learnpress_course_id'] ?? 0 );
			if ( $course_id && 'lp_course' === get_post_type( $course_id ) ) {
				continue;
			}
			$existing = get_posts( array(
				'post_type'      => 'lp_course',
				'post_status'    => array( 'draft', 'pending', 'private', 'publish' ),
				'meta_key'       => '_gb_course_key',
				'meta_value'     => $course_key,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );
			if ( $existing ) {
				$course_id = absint( $existing[0] );
			} else {
				$course_id = wp_insert_post( array(
					'post_type'   => 'lp_course',
					'post_status' => 'draft',
					'post_title'  => $definition['label'],
				) );
				if ( is_wp_error( $course_id ) || ! $course_id ) {
					continue;
				}
				update_post_meta( $course_id, '_gb_course_key', $course_key );
				$created++;
			}
			$registry[ $course_key ] = array(
				'learnpress_course_id' => absint( $course_id ),
				'status'               => 'building',
			);
		}
		$registry['_updated_at'] = current_time( 'mysql', true );
		$registry['_updated_by'] = get_current_user_id();
		update_option( self::COURSE_OPTION, $registry );

		$url = add_query_arg( array( 'page' => 'gulf-breeze-course-registry', 'gb_created' => $created ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	public function render_course_registry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$registry = get_option( self::COURSE_OPTION, array() );
		$lp_courses = get_posts( array( 'post_type' => 'lp_course', 'post_status' => array( 'draft', 'pending', 'private', 'publish' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$statuses = array(
			'planned'          => 'Planned',
			'building'         => 'Building',
			'internal_testing' => 'Internal testing',
			'ready_for_review' => 'Ready for TDLR review',
			'approved'         => 'TDLR approved',
		);
		?>
		<div class="wrap">
			<h1>Gulf Breeze Course Registry</h1>
			<?php if ( isset( $_GET['gb_created'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( absint( $_GET['gb_created'] ) ); ?> new private draft course shell(s) created and mapped.</p></div>
			<?php endif; ?>
			<p>These four regulatory course identities are fixed. LearnPress mappings and lifecycle status may be changed here without changing their required program type, language, or instructional-time baseline.</p>
			<div class="notice notice-info inline"><p>No registry status makes an approval claim on the public site. Approval identifiers remain blank until entered on the Configuration page.</p></div>
			<form method="post" action="options.php">
				<?php settings_fields( 'gb_course_registry_group' ); ?>
				<table class="widefat striped"><thead><tr><th>Course</th><th>Program</th><th>Language</th><th>Verified time baseline</th><th>LearnPress course</th><th>Internal status</th><th>Approval identifier</th></tr></thead><tbody>
				<?php foreach ( self::course_definitions() as $course_key => $definition ) :
					$row = isset( $registry[ $course_key ] ) && is_array( $registry[ $course_key ] ) ? $registry[ $course_key ] : array();
					$selected_course = absint( $row['learnpress_course_id'] ?? 0 );
					$status = $row['status'] ?? 'planned';
					$approval = self::get( $definition['approval_key'] );
				?>
				<tr>
					<td><strong><?php echo esc_html( $definition['label'] ); ?></strong><br><code><?php echo esc_html( $course_key ); ?></code></td>
					<td><?php echo esc_html( 'parent_taught' === $definition['program'] ? 'Parent-Taught' : 'Adult Six-Hour' ); ?></td>
					<td><?php echo esc_html( $definition['language'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $definition['total_minutes'] ) ); ?> total minutes<br><?php echo esc_html( number_format_i18n( $definition['instruction_minutes'] ) ); ?> instruction + <?php echo esc_html( number_format_i18n( $definition['other_minutes'] ) ); ?> additional/break</td>
					<td><select name="<?php echo esc_attr( self::COURSE_OPTION . '[' . $course_key . '][learnpress_course_id]' ); ?>"><option value="0">Not mapped</option><?php foreach ( $lp_courses as $lp_course ) : ?><option value="<?php echo esc_attr( $lp_course->ID ); ?>" <?php selected( $selected_course, $lp_course->ID ); ?>><?php echo esc_html( $lp_course->post_title . ' (#' . $lp_course->ID . ')' ); ?></option><?php endforeach; ?></select></td>
					<td><select name="<?php echo esc_attr( self::COURSE_OPTION . '[' . $course_key . '][status]' ); ?>"><?php foreach ( $statuses as $status_key => $status_label ) : ?><option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option><?php endforeach; ?></select></td>
					<td><?php echo '' === $approval ? '<em>Not entered</em>' : esc_html( $approval ); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( 'Save Course Registry' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gb_create_course_shells">
				<?php wp_nonce_field( 'gb_create_course_shells' ); ?>
				<?php submit_button( 'Create or Repair Four Draft Course Shells', 'secondary' ); ?>
				<p class="description">Creates only missing LearnPress course shells as drafts, maps them here, and marks them Building. It does not add curriculum, publish courses, or claim approval.</p>
			</form>
		</div>
		<?php
	}

	public function sanitize( $submitted ) {
		$submitted = is_array( $submitted ) ? $submitted : array();
		$current   = get_option( self::OPTION, array() );
		$clean     = array();

		foreach ( self::schema() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				if ( ! empty( $field['readonly'] ) ) {
					$clean[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : ( $field['default'] ?? '' );
					continue;
				}
				$value = $submitted[ $key ] ?? '';
				if ( 'checkbox' === ( $field['type'] ?? '' ) ) {
					$clean[ $key ] = $value ? '1' : '';
				} elseif ( 'email' === ( $field['type'] ?? '' ) ) {
					$clean[ $key ] = sanitize_email( $value );
				} elseif ( 'textarea' === ( $field['type'] ?? '' ) ) {
					$clean[ $key ] = sanitize_textarea_field( $value );
				} else {
					$clean[ $key ] = sanitize_text_field( $value );
				}
				if ( ! empty( $field['maxlength'] ) ) {
					$clean[ $key ] = substr( $clean[ $key ], 0, (int) $field['maxlength'] );
				}
			}
		}

		$clean['timezone']   = 'America/Chicago';
		$clean['_updated_at']= current_time( 'mysql', true );
		$clean['_updated_by']= get_current_user_id();
		return $clean;
	}

	public static function get( $key, $fallback = '' ) {
		$values = get_option( self::OPTION, array() );
		$value  = isset( $values[ $key ] ) ? $values[ $key ] : $fallback;
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	public static function field_exists( $key ) {
		foreach ( self::schema() as $section ) {
			if ( isset( $section['fields'][ $key ] ) ) {
				return $section['fields'][ $key ];
			}
		}
		return false;
	}

	public function setting_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'key' => '', 'fallback' => '' ), $atts, 'gb_setting' );
		$field = self::field_exists( $atts['key'] );
		if ( ! $field || ! empty( $field['private'] ) ) {
			return '';
		}
		$value = self::get( $atts['key'] );
		// Missing configuration must never leak a token or fabricated placeholder.
		return '' === $value ? esc_html( $atts['fallback'] ) : esc_html( $value );
	}

	private function missing_required() {
		$missing = array();
		foreach ( self::schema() as $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				if ( ! empty( $field['required'] ) && '' === self::get( $key ) ) {
					$missing[] = $field['label'];
				}
			}
		}
		return $missing;
	}

	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || self::PAGE === ( $_GET['page'] ?? '' ) ) {
			return;
		}
		$missing = $this->missing_required();
		if ( $missing ) {
			$url = admin_url( 'admin.php?page=' . self::PAGE );
			echo '<div class="notice notice-warning"><p><strong>Gulf Breeze configuration is incomplete.</strong> Production output will remain blank for missing legal details. <a href="' . esc_url( $url ) . '">Review configuration</a>.</p></div>';
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$values  = get_option( self::OPTION, array() );
		$missing = $this->missing_required();
		?>
		<div class="wrap">
			<h1>Gulf Breeze Core — Configuration</h1>
			<p>This page is the authoritative source for reusable business, licensing, contact, and policy details. Blank fields remain blank everywhere; the system does not invent or publish placeholders.</p>
			<?php if ( $missing ) : ?>
				<div class="notice notice-warning inline"><p><strong>Required facts still missing:</strong> <?php echo esc_html( implode( ', ', $missing ) ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-success inline"><p>All required identity fields currently have values.</p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'gb_config_group' ); ?>
				<?php foreach ( self::schema() as $section_key => $section ) : ?>
					<h2><?php echo esc_html( $section['label'] ); ?></h2>
					<table class="form-table" role="presentation"><tbody>
					<?php foreach ( $section['fields'] as $key => $field ) :
						$type  = $field['type'] ?? 'text';
						$value = isset( $values[ $key ] ) ? $values[ $key ] : ( $field['default'] ?? '' );
					?>
					<tr>
						<th scope="row"><label for="gb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' *' : ''; ?></label></th>
						<td>
						<?php if ( 'textarea' === $type ) : ?>
							<textarea class="large-text" rows="3" id="gb-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
						<?php elseif ( 'checkbox' === $type ) : ?>
							<input type="checkbox" value="1" id="gb-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" <?php checked( $value, '1' ); ?> />
						<?php else : ?>
							<input class="regular-text" type="<?php echo esc_attr( $type ); ?>" id="gb-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo ! empty( $field['readonly'] ) ? 'readonly' : ''; ?> <?php echo ! empty( $field['maxlength'] ) ? 'maxlength="' . esc_attr( $field['maxlength'] ) . '"' : ''; ?> />
						<?php endif; ?>
						<?php if ( ! empty( $field['private'] ) ) : ?><p class="description">Internal only; unavailable through the public shortcode.</p><?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endforeach; ?>
				<?php submit_button( 'Save Gulf Breeze Configuration' ); ?>
			</form>
			<hr>
			<h2>Developer use</h2>
			<p><code>Gulf_Breeze_Configuration::get( 'provider_number' )</code> or <code>[gb_setting key="provider_number"]</code>. Private fields are never exposed by shortcode.</p>
		</div>
		<?php
	}
}

Gulf_Breeze_Configuration::instance();

function gb_config_get( $key, $fallback = '' ) {
	return Gulf_Breeze_Configuration::get( $key, $fallback );
}
