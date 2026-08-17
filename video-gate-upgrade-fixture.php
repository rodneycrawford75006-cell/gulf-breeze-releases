<?php
/**
 * Plugin Name: Gulf Breeze Video Quiz Gate Upgrade Fixture
 * Description: Disposable validation-only option fixture for the 0.2.33-to-0.3.1 upgrade test.
 * Version: 0.2.33
 * Author: Gulf Breeze Driving School / Rodney Crawford
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

register_activation_hook( __FILE__, static function() {
	add_option( 'gbvqg_gates', array(), '', false );
	add_option( 'gbvqg_events', array(), '', false );
} );
