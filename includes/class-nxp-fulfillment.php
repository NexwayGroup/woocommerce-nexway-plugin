<?php
/**
 * Nexway fulfillment endpoint receiver.
 *
 * Nexway POSTs here (per line item) when an order needs product delivery.
 * Unlike the notification webhook this call is synchronous and blocks order
 * completion — we must reply with activation data or an errorCode.
 *
 * The site hooks into `nxp_fulfillment_response` to supply actual values:
 *
 *   add_filter( 'nxp_fulfillment_response', function( $response, $payload, $order ) {
 *       $response['activationLink'] = 'https://example.com/activate?key=...';
 *       return $response;
 *   }, 10, 3 );
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXP_fulfillment' ) ) {

	class NXP_fulfillment {

		public function __construct() {}

		public function init_actions() {

			add_action( 'rest_api_init', array( $this, 'register_route' ) );
		}

		public function register_route() {

			register_rest_route( 'nexway/v1', '/fulfillment/', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'check_basic_auth' ),
			) );
		}

		public function check_basic_auth( WP_REST_Request $request ) {

			$settings      = get_option( 'woocommerce_' . NXP_PROCESSOR_ID . '_settings', array() );
			$expected_user = isset( $settings['fulfillment_basic_user'] ) ? (string) $settings['fulfillment_basic_user'] : '';
			$expected_pass = isset( $settings['fulfillment_basic_pass'] ) ? (string) $settings['fulfillment_basic_pass'] : '';

			if ( $expected_user === '' || $expected_pass === '' ) {
				return new WP_Error( 'nxp_no_creds', 'Fulfillment Basic auth not configured', array( 'status' => 500 ) );
			}

			$user = isset( $_SERVER['PHP_AUTH_USER'] ) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
			$pass = isset( $_SERVER['PHP_AUTH_PW'] )   ? (string) $_SERVER['PHP_AUTH_PW']   : '';

			$header = $request->get_header( 'authorization' );
			if ( ( $user === '' || $pass === '' ) && $header && stripos( $header, 'basic ' ) === 0 ) {
				$decoded = base64_decode( substr( $header, 6 ) );
				if ( $decoded && strpos( $decoded, ':' ) !== false ) {
					list( $user, $pass ) = explode( ':', $decoded, 2 );
				}
			}

			if ( ! hash_equals( $expected_user, $user ) || ! hash_equals( $expected_pass, $pass ) ) {
				return new WP_Error( 'nxp_auth', 'Invalid credentials', array( 'status' => 401 ) );
			}
			return true;
		}

		public function handle( WP_REST_Request $request ) {

			$payload = $request->get_json_params();
			$this->log( "Fulfillment received:\n" . wp_json_encode( $payload, JSON_PRETTY_PRINT ) );

			if ( ! is_array( $payload ) ) {
				return $this->error_response( 'invalid_payload', __( 'Invalid JSON payload.', 'nexway' ) );
			}

			$order = $this->resolve_order( $payload );

			$response = apply_filters( 'nxp_fulfillment_response', array(
				'errorCode'           => '',
				'errorMessage'        => '',
			), $payload, $order );

			if ( ! empty( $response['errorCode'] ) ) {
				$this->log( 'Fulfillment returning error: ' . $response['errorCode'] . ' — ' . $response['errorMessage'] );
			} else {
				$this->log( 'Fulfillment returning success.' );
			}

			return new WP_REST_Response( $response, 200 );
		}

		private function resolve_order( $payload ) {

			$context = isset( $payload['checkout']['cartExternalContext'] )
				? (string) $payload['checkout']['cartExternalContext']
				: '';

			if ( $context !== '' ) {
				$map = json_decode( base64_decode( $context ), true );
				if ( is_array( $map ) && ! empty( $map['merchant_reference'] ) ) {
					$order_id = wc_get_order_id_by_order_key( $map['merchant_reference'] );
					if ( $order_id ) {
						return wc_get_order( $order_id );
					}
				}
			}

			// Fall back to Nexway order ID stored by the notification handler.
			$nexway_order_id = isset( $payload['checkout']['orderId'] )
				? (string) $payload['checkout']['orderId']
				: '';

			if ( $nexway_order_id !== '' ) {
				$orders = wc_get_orders( array(
					'meta_key'   => 'nxp_nexway_order_id',
					'meta_value' => $nexway_order_id,
					'limit'      => 1,
				) );
				if ( ! empty( $orders ) ) {
					return $orders[0];
				}
			}

			$this->log( 'Fulfillment: could not resolve a WC order from payload.' );
			return null;
		}

		private function error_response( $code, $message ) {

			return new WP_REST_Response( array(
				'errorCode'    => $code,
				'errorMessage' => $message,
			), 200 );
		}

		private function log( $message ) {

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( $message, array( 'source' => NXP_PROCESSOR_SYSTEM_NAME ) );
			}
		}
	}
}
