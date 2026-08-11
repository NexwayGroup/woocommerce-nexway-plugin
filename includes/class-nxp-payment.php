<?php
/**
 * Woocommerce Nexway Payment main class
 *
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXP_payment' ) ) {

	class NXP_payment extends WC_Payment_Gateway {

		private $client_id;
		private $client_secret;
		private $realm;
		private $store_id;
		private $default_country;
		private $base_url;
		private $allowed_currencies;

		public function __construct() {

			$this->id                 = NXP_PROCESSOR_ID;
			$this->method_title       = NXP_PROCESSOR_NAME;
			$this->method_description = __( 'Redirect customers to Nexway Monetize hosted checkout.', 'nexway' );

			$this->init_form_fields();
			$this->init_settings();

			$this->title               = $this->get_option( 'title' );
			$this->description         = $this->get_option( 'description' );
			$this->client_id           = $this->get_option( 'client_id' );
			$this->client_secret       = $this->get_option( 'client_secret' );
			$this->realm               = $this->get_option( 'realm' );
			$this->store_id            = $this->get_option( 'store_id' );
			$this->default_country     = $this->get_option( 'default_country' );
			$this->base_url            = $this->get_option( 'base_url', 'https://api.nexway.store' );
			$allowed = $this->get_option( 'allowed_currencies' );
			$this->allowed_currencies  = is_array( $allowed ) ? $allowed : array();

			$this->supports = array( 'products', 'orders' );
		}

		public function init_actions() {

			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_payment_gateway' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ), 10 );
			add_filter( 'woocommerce_order_item_needs_processing', array( $this, 'needs_processing' ), 10, 3 );
		}

		public function add_payment_gateway( $gateways ) {

			$gateways[] = 'NXP_payment';
			return $gateways;
		}

		public function init_form_fields() {

			$NXP_admin = new NXP_admin();
			$this->form_fields = $NXP_admin->form_fields();
		}

		/**
		 * Only offer the gateway when currency is allowed and every line item
		 * has a Nexway product ID mapping.
		 */
		public function is_available() {

			if ( ! $this->client_id || ! $this->client_secret || ! $this->realm || ! $this->store_id ) {
				return false;
			}
			if ( ! empty( $this->allowed_currencies ) && ! in_array( get_woocommerce_currency(), $this->allowed_currencies, true ) ) {
				return false;
			}
			if ( function_exists( 'WC' ) && WC()->cart ) {
				foreach ( WC()->cart->get_cart() as $item ) {
					$product_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
					if ( ! get_post_meta( $product_id, '_nexway_product_id', true ) ) {
						return false;
					}
				}
			}
			return parent::is_available();
		}

		public function process_payment( $order_id ) {

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$this->log( 'Order ' . $order_id . ' not found in process_payment' );
				wc_add_notice( __( 'Order not found', 'nexway' ), 'error' );
				return array( 'result' => 'fail', 'redirect' => '' );
			}

			$items = array();
			foreach ( $order->get_items() as $line ) {
				$product_id  = $line->get_variation_id() ? $line->get_variation_id() : $line->get_product_id();
				$nexway_id   = get_post_meta( $product_id, '_nexway_product_id', true );
				if ( ! $nexway_id ) {
					$this->log( sprintf( 'Order %d line "%s" (WC product %d) missing _nexway_product_id',
						$order_id, $line->get_name(), $product_id ) );
					wc_add_notice( sprintf(
						/* translators: %s: product name */
						__( '"%s" is not available for Nexway payment.', 'nexway' ),
						$line->get_name()
					), 'error' );
					return array( 'result' => 'fail', 'redirect' => '' );
				}
				$items[] = array(
					'id'       => $nexway_id,
					'quantity' => max( 1, (int) $line->get_quantity() ),
				);
			}

			$country = $order->get_billing_country();
			if ( ! $country ) {
				$country = $this->default_country;
			}

			$client = new NXP_client( $this->client_id, $this->client_secret, $this->realm, $this->base_url );
			$cart_id = $client->create_public_cart( array(
				'store_id'           => $this->store_id,
				'country'            => $country,
				'locale'             => str_replace( '_', '-', get_locale() ),
				'items'              => $items,
				'merchant_reference' => $order->get_order_key(),
				'return_url'         => wc_get_account_endpoint_url( 'orders' ),
			) );
			if ( is_wp_error( $cart_id ) ) {
				wc_add_notice( NXP_PROCESSOR_NAME . ': ' . $cart_id->get_error_message(), 'error' );
				return array( 'result' => 'fail', 'redirect' => '' );
			}

			$cart = $client->get_cart( $cart_id );
			if ( is_wp_error( $cart ) ) {
				wc_add_notice( NXP_PROCESSOR_NAME . ': ' . $cart->get_error_message(), 'error' );
				return array( 'result' => 'fail', 'redirect' => '' );
			}

			if ( empty( $cart['checkoutUrl'] ) ) {
				$this->log( 'Cart ' . $cart_id . ' has no checkoutUrl in response' );
				wc_add_notice( __( 'Nexway did not return a checkout URL.', 'nexway' ), 'error' );
				return array( 'result' => 'fail', 'redirect' => '' );
			}

			$order->update_meta_data( 'nxp_cart_id', $cart_id );
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => $cart['checkoutUrl'],
			);
		}

		public function needs_processing( $needs_processing, $product, $order_id ) {

			$order = wc_get_order( $order_id );
			if ( $order && $order->get_payment_method() === $this->id ) {
				if ( $this->get_option( 'completed_status' ) === 'yes' ) {
					return false;
				}
			}
			return $needs_processing;
		}

		private function log( $message ) {

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( $message, array( 'source' => NXP_PROCESSOR_SYSTEM_NAME ) );
			}
		}
	}
}
