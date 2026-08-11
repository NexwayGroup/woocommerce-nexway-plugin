<?php

/**
 * Plugin Name: Woocommerce Nexway Payment
 * Description: <a href="https://apidoc.nexway.store">Nexway Monetize</a> Payment Gateway integration
 * Version: 1.0.0
 * Text Domain: nexway
 * Domain Path: /languages/
 * License: GPL-3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NXP_VERSION', '1.0.0' );

define( 'NXP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NXP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'NXP_PROCESSOR_ID', 'wc_nexway' );
define( 'NXP_PROCESSOR_SYSTEM_NAME', 'nexway' );
define( 'NXP_PROCESSOR_NAME', 'Nexway' );
define( 'NXP_PROCESSOR_PREFIX', 'nxp_' );

/**
 * The code that runs during plugin activation.
 */
function nxp_activate() {

}

/**
 * The code that runs during plugin deactivation.
 */
function nxp_deactivate() {

}

register_activation_hook( __FILE__, 'nxp_activate' );
register_deactivation_hook( __FILE__, 'nxp_deactivate' );

add_action( 'wp_enqueue_scripts', function () {

	if( is_checkout() ) {
		wp_enqueue_style( 'nxp-checkout', NXP_PLUGIN_URL . 'assets/css/checkout.css', false, NXP_VERSION, 'all' );
	}
});

add_action( 'admin_enqueue_scripts', function () {

	wp_enqueue_style( 'nxp-admin', NXP_PLUGIN_URL . 'assets/css/admin.css', false, NXP_VERSION, 'all' );
	wp_enqueue_script( 'nxp-admin', NXP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), NXP_VERSION, true );
	wp_localize_script( 'nxp-admin', 'nxp_admin', array(
		'ajax_url'	=> admin_url( 'admin-ajax.php' ),
		'nonce'		=> wp_create_nonce( 'nxp_admin' ),
	) );
});

// Load API client (used by gateway, admin, and notification receiver).
require_once NXP_PLUGIN_DIR . 'includes/class-nxp-client.php';

// Require gateway class late — WC_Payment_Gateway must exist first.
add_action( 'plugins_loaded', function () {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	require_once NXP_PLUGIN_DIR . 'includes/class-nxp-payment.php';
	$NXP_payment = new NXP_payment();
	$NXP_payment->init_actions();
});

require_once NXP_PLUGIN_DIR . 'includes/class-nxp-admin.php';
$NXP_admin = new NXP_admin();
$NXP_admin->init_actions();

require_once NXP_PLUGIN_DIR . 'includes/class-nxp-notification.php';
$NXP_notification = new NXP_notification();
$NXP_notification->init_actions();

require_once NXP_PLUGIN_DIR . 'includes/class-nxp-fulfillment.php';
$NXP_fulfillment = new NXP_fulfillment();
$NXP_fulfillment->init_actions();

add_action( 'plugins_loaded', function () {

	load_plugin_textdomain( 'nexway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
});
