<?php

/**
 * Plugin Name: Woocommerce Nexway Payment
 * Description: <a href="https://apidoc.nexway.store">Nexway Monetize</a> Payment Gateway integration
 * Version: 1.0.1
 * Text Domain: nexway
 * Domain Path: /languages/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 * License: GPL-3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NXP_VERSION', '1.0.1' );

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

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
add_action( 'before_woocommerce_init', function () {

	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'wp_enqueue_scripts', function () {

	if( is_checkout() ) {
		wp_enqueue_style( 'nxp-checkout', NXP_PLUGIN_URL . 'assets/css/checkout.css', false, NXP_VERSION, 'all' );
	}
});

add_action( 'admin_enqueue_scripts', function () {

	wp_enqueue_style( 'nxp-admin', NXP_PLUGIN_URL . 'assets/css/admin.css', false, NXP_VERSION, 'all' );
});

// Load API client (used by gateway, admin, and notification receiver).
require_once NXP_PLUGIN_DIR . 'includes/class-nxp-client.php';

// Register the gateway class without instantiating it during plugins_loaded.
// The WordPress rewrite object does not exist yet at this point. Constructing
// the gateway here caused rest_url() to crash during requests such as AJAX.
add_action( 'plugins_loaded', 'nxp_init_gateway', 11 );

function nxp_init_gateway() {

	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once NXP_PLUGIN_DIR . 'includes/class-nxp-payment.php';
	add_filter( 'woocommerce_payment_gateways', 'nxp_register_payment_gateway' );
}

function nxp_register_payment_gateway( $gateways ) {

	$gateways[] = 'NXP_payment';
	return $gateways;
}

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
