<?php
/**
 * Plugin Name: Gulf Breeze Teen Programs
 * Description: Shared English teen classroom architecture with separate Regular Online Teen and Parent-Taught enrollment, contract, product, and DE-964 routing records.
 * Version: 0.1.0-dev-r1
 * Author: Gulf Breeze Driving School / Rodney Crawford
 * Text Domain: gulf-breeze-teen-programs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gulf_Breeze_Teen_Programs {
	const VERSION = '0.1.0-dev-r1';
	const OPTION = 'gb_teen_program_registry';
	const PAGE = 'gulf-breeze-teen-programs';
	const CERT_PAGE = 'gulf-breeze-teen-certificates';
	const SHARED_CORE_KEY = 'ptde_en';
	const CERTIFICATE_TYPE = 'DE-964';

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'bootstrap_architecture' ), 30 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_program_contract' ), 5 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_program_contract' ), 30, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'freeze_program_on_order' ), 30, 2 );
		add_action( 'learn-press/user-course-finished', array( $this, 'capture_course_completion' ), 30, 3 );
		add_action( 'admin_post_gb_teen_repair_architecture', array( $this, 'handle_repair' ) );
	}

	public static function activate() {
		self::instance()->install_schema();
		update_option( 'gb_teen_program_schema_version', '1.0.0', false );
	}

	private function definitions() {
		return array(
			'teen_classroom_en' => array(
				'label' => 'Regular Online Teen Classroom-Only — English',
				'program_type' => 'teen_classroom_only',
				'contract_type' => 'teen_classroom_only_en',
				'certificate_type' => self::CERTIFICATE_TYPE,
				'product_title' => 'Online Teen Classroom-Only — English (Building)',
			),
			'teen_parent_taught_en' => array(
				'label' => 'Parent-Taught Driver Education — English',
				'program_type' => 'teen_parent_taught',
				'contract_type' => 'teen_parent_taught_en',
				'certificate_type' => self::CERTIFICATE_TYPE,
				'product_title' => 'Parent-Taught Driver Education — English (Building)',
			),
		);
	}

	private function shared_course_id() {
		$registry = get_option( 'gb_course_registry', array() );
		return absint( $registry[ self::SHARED_CORE_KEY ]['learnpress_course_id'] ?? 0 );
	}

	private function registry() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$rows = isset( $stored['programs'] ) && is_array( $stored['programs'] ) ? $stored['programs'] : array();
		foreach ( $this->definitions() as $key => $definition ) {
			$rows[ $key ] = wp_parse_args( $rows[ $key ] ?? array(), array(
				'product_id' => 0,
				'course_id' => $this->shared_course_id(),
				'status' => 'building',
			) );
		}
		return array(
			'schema_version' => '1.0.0',
			'shared_course_key' => self::SHARED_CORE_KEY,
			'shared_course_id' => $this->shared_course_id(),
			'programs' => $rows,
			'updated_at_utc' => sanitize_text_field( $stored['updated_at_utc'] ?? '' ),
		);
	}

	public function bootstrap_architecture() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->install_schema();
		$course_id = $this->shared_course_id();
		if ( ! $course_id || 'lp_course' !== get_post_type( $course_id ) ) {
			return;
		}
		$course = get_post( $course_id );
		if ( $course && 'Online Teen Classroom — English (Shared)' !== $course->post_title ) {
			wp_update_post( array(
				'ID' => $course_id,
				'post_title' => 'Online Teen Classroom — English (Shared)',
				'post_status' => 'draft',
			) );
		}
		$registry = $this->registry();
		foreach ( $this->definitions() as $key => $definition ) {
			$product_id = absint( $registry['programs'][ $key ]['product_id'] ?? 0 );
			if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
				$product_id = $this->create_building_product( $key, $definition, $course_id );
			}
			if ( $product_id ) {
				update_post_meta( $product_id, '_gb_catalog_key', $key );
				update_post_meta( $product_id, '_gb_program_type', $definition['program_type'] );
				update_post_meta( $product_id, '_gb_contract_type', $definition['contract_type'] );
				update_post_meta( $product_id, '_gb_certificate_type', self::CERTIFICATE_TYPE );
				update_post_meta( $product_id, '_gb_enrollment_locale', 'en-US' );
				update_post_meta( $product_id, '_gb_learnpress_course_id', $course_id );
				$registry['programs'][ $key ]['product_id'] = $product_id;
				$registry['programs'][ $key ]['course_id'] = $course_id;
				$registry['programs'][ $key ]['status'] = 'building';
			}
		}
		$registry['shared_course_id'] = $course_id;
		$registry['updated_at_utc'] = current_time( 'mysql', true );
		update_option( self::OPTION, $registry, false );
	}

	private function create_building_product( $key, $definition, $course_id ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}
		$existing = get_posts( array(
			'post_type' => 'product',
			'post_status' => array( 'draft', 'publish', 'private', 'pending' ),
			'meta_key' => '_gb_catalog_key',
			'meta_value' => $key,
			'fields' => 'ids',
			'posts_per_page' => 1,
		) );
		if ( $existing ) {
			return absint( $existing[0] );
		}
		$product = new WC_Product_Simple();
		$product->set_name( $definition['product_title'] );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_description( $definition['label'] . '. This product remains in Building status and is not available for purchase.' );
		$product_id = $product->save();
		if ( $product_id ) {
			update_post_meta( $product_id, '_gb_learnpress_course_id', $course_id );
		}
		return absint( $product_id );
	}

	private function cart_program() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = absint( $item['product_id'] ?? 0 );
			$key = sanitize_key( get_post_meta( $product_id, '_gb_catalog_key', true ) );
			if ( isset( $this->definitions()[ $key ] ) ) {
				return array_merge( array( 'key' => $key, 'product_id' => $product_id ), $this->definitions()[ $key ] );
			}
		}
		return false;
	}

	public function render_program_contract() {
		$program = $this->cart_program();
		if ( ! $program ) {
			return;
		}
		$is_parent_taught = 'teen_parent_taught' === $program['program_type'];
		?>
		<section class="gb-teen-program-contract" style="margin:20px 0;padding:18px;border:2px solid #153b66;border-radius:10px;background:#f4f8fc">
			<h3><?php echo esc_html( $program['label'] ); ?></h3>
			<?php if ( $is_parent_taught ) : ?>
				<p>This enrollment provides the shared 24-hour online teen classroom course. The parent or other eligible instructor remains responsible for obtaining and following the current Texas Parent-Taught Driver Education Program Guide, meeting instructor eligibility requirements, conducting and documenting the required in-car instruction, and completing all Parent-Taught records.</p>
				<p><label><strong>Parent-Taught instructor full legal name</strong><br><input type="text" name="gb_teen_instructor_name" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_POST['gb_teen_instructor_name'] ?? '' ) ) ); ?>" style="width:100%"></label></p>
			<?php else : ?>
				<p>This enrollment is for the shared 24-hour online teen classroom course only. It does not include behind-the-wheel instruction, in-car observation, a Parent-Taught program guide, or Parent-Taught instructor authorization.</p>
			<?php endif; ?>
			<p><label><input type="checkbox" name="gb_teen_program_ack" value="1" <?php checked( ! empty( $_POST['gb_teen_program_ack'] ) ); ?>> I understand the program selected above and the responsibilities and services it includes.</label></p>
		</section>
		<?php
	}

	public function validate_program_contract( $data, $errors ) {
		$program = $this->cart_program();
		if ( ! $program ) {
			return;
		}
		if ( empty( $_POST['gb_teen_program_ack'] ) ) {
			$errors->add( 'gb_teen_program_ack', 'Acknowledge the selected teen program and its responsibilities.' );
		}
		if ( 'teen_parent_taught' === $program['program_type'] && '' === trim( sanitize_text_field( wp_unslash( $_POST['gb_teen_instructor_name'] ?? '' ) ) ) ) {
			$errors->add( 'gb_teen_instructor_name', 'Enter the Parent-Taught instructor full legal name.' );
		}
	}

	public function freeze_program_on_order( $order, $data ) {
		$program = $this->cart_program();
		if ( ! $program || ! $order ) {
			return;
		}
		$order->update_meta_data( '_gb_teen_program_key', $program['key'] );
		$order->update_meta_data( '_gb_ep_program_type', $program['program_type'] );
		$order->update_meta_data( '_gb_ep_contract_type', $program['contract_type'] );
		$order->update_meta_data( '_gb_ep_certificate_type', self::CERTIFICATE_TYPE );
		$order->update_meta_data( '_gb_teen_program_acknowledged', 'yes' );
		if ( 'teen_parent_taught' === $program['program_type'] ) {
			$order->update_meta_data( '_gb_teen_parent_taught_instructor_name', sanitize_text_field( wp_unslash( $_POST['gb_teen_instructor_name'] ?? '' ) ) );
		}
	}

	public function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'gb_teen_certificate_routes';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			program_key varchar(64) NOT NULL,
			program_type varchar(64) NOT NULL,
			certificate_type varchar(24) NOT NULL DEFAULT 'DE-964',
			completion_utc datetime NOT NULL,
			issuance_state varchar(32) NOT NULL DEFAULT 'building_hold',
			created_utc datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_course_program (user_id,course_id,program_key),
			KEY issuance_state (issuance_state)
		) {$charset};" );
		update_option( 'gb_teen_program_schema_version', '1.0.0', false );
	}

	public function capture_course_completion( $course_id, $user_id, $result = null ) {
		$course_id = absint( $course_id );
		$user_id = absint( $user_id );
		if ( ! $course_id || $course_id !== $this->shared_course_id() || ! $user_id ) {
			return;
		}
		$order_id = absint( get_user_meta( $user_id, '_gb_ep_active_course_' . $course_id, true ) );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! $order->is_paid() || 'paid_enrolled' !== $order->get_meta( '_gb_ep_state' ) ) {
			return;
		}
		$program_key = sanitize_key( $order->get_meta( '_gb_teen_program_key' ) );
		$definitions = $this->definitions();
		if ( ! isset( $definitions[ $program_key ] ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'gb_teen_certificate_routes';
		$wpdb->replace( $table, array(
			'user_id' => $user_id,
			'course_id' => $course_id,
			'order_id' => $order_id,
			'program_key' => $program_key,
			'program_type' => $definitions[ $program_key ]['program_type'],
			'certificate_type' => self::CERTIFICATE_TYPE,
			'completion_utc' => current_time( 'mysql', true ),
			'issuance_state' => 'building_hold',
			'created_utc' => current_time( 'mysql', true ),
		), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	}

	public function admin_menu() {
		add_submenu_page( 'gulf-breeze-configuration', 'Teen Programs', 'Teen Programs', 'manage_options', self::PAGE, array( $this, 'render_admin_page' ) );
		add_submenu_page( 'gulf-breeze-configuration', 'Teen DE-964 Routing', 'Teen DE-964 Routing', 'manage_options', self::CERT_PAGE, array( $this, 'render_certificate_page' ) );
	}

	public function handle_repair() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'gb_teen_repair_architecture' );
		$this->bootstrap_architecture();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&repaired=1' ) );
		exit;
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$registry = $this->registry();
		$course_id = absint( $registry['shared_course_id'] );
		?>
		<div class="wrap"><h1>Gulf Breeze Teen Programs</h1>
		<p><strong>BUILDING / TEST STATUS.</strong> The two English teen programs below share one LearnPress classroom course. Their products, contracts, enrollment records, program responsibilities, and certificate routes remain separate.</p>
		<table class="widefat striped"><thead><tr><th>Program</th><th>Program identity</th><th>Product</th><th>Shared course</th><th>Contract</th><th>Certificate</th><th>Status</th></tr></thead><tbody>
		<?php foreach ( $this->definitions() as $key => $definition ) : $row = $registry['programs'][ $key ]; ?>
		<tr><td><?php echo esc_html( $definition['label'] ); ?><br><code><?php echo esc_html( $key ); ?></code></td><td><code><?php echo esc_html( $definition['program_type'] ); ?></code></td><td><?php echo esc_html( get_the_title( absint( $row['product_id'] ) ) ); ?> (#<?php echo absint( $row['product_id'] ); ?>)</td><td><?php echo esc_html( get_the_title( $course_id ) ); ?> (#<?php echo $course_id; ?>)</td><td><code><?php echo esc_html( $definition['contract_type'] ); ?></code></td><td><?php echo esc_html( self::CERTIFICATE_TYPE ); ?></td><td>Building</td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<p>No Adult course, ADEE-1317 record, existing order, payment, enrollment, student record, or published content is changed by this registry.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="gb_teen_repair_architecture"><?php wp_nonce_field( 'gb_teen_repair_architecture' ); ?><?php submit_button( 'Re-verify Teen Architecture', 'secondary' ); ?></form></div>
		<?php
	}

	public function render_certificate_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'gb_teen_certificate_routes';
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );
		?>
		<div class="wrap"><h1>Teen DE-964 Routing</h1>
		<p><strong>TEST MODE — ISSUANCE DISABLED.</strong> Eligible completions are routed here by the frozen enrollment program. No DE-964 serial, PDF, email, or TDLR report is produced until the Teen electronic certificate template, test inventory, and approval settings are installed and verified.</p>
		<table class="widefat striped"><thead><tr><th>Completed UTC</th><th>Student</th><th>Course</th><th>Program</th><th>Certificate</th><th>State</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="6">No eligible Teen completions recorded.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( $row['completion_utc'] ); ?></td><td>#<?php echo absint( $row['user_id'] ); ?></td><td>#<?php echo absint( $row['course_id'] ); ?></td><td><code><?php echo esc_html( $row['program_key'] ); ?></code></td><td><?php echo esc_html( $row['certificate_type'] ); ?></td><td><?php echo esc_html( $row['issuance_state'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'Gulf_Breeze_Teen_Programs', 'activate' ) );
Gulf_Breeze_Teen_Programs::instance();

