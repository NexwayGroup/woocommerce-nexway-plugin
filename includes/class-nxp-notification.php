<?php
/**
 * Nexway notification (webhook) receiver.
 *
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXP_notification' ) ) {

	class NXP_notification {

		public function __construct() {}

		public function init_actions() {

			add_action( 'rest_api_init', array( $this, 'register_route' ) );
		}

		public function register_route() {

			register_rest_route( 'nexway/v1', '/notification/', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'check_basic_auth' ),
			) );
		}

		public function check_basic_auth( WP_REST_Request $request ) {

			$settings = get_option( 'woocommerce_' . NXP_PROCESSOR_ID . '_settings', array() );
			$expected_user = isset( $settings['notification_basic_user'] ) ? (string) $settings['notification_basic_user'] : '';
			$expected_pass = isset( $settings['notification_basic_pass'] ) ? (string) $settings['notification_basic_pass'] : '';

			if ( $expected_user === '' || $expected_pass === '' ) {
				$this->log( 'Notification auth: no credentials configured in the gateway settings.' );
				return new WP_Error( 'nxp_no_creds', 'Basic auth not configured', array( 'status' => 500 ) );
			}

			$user   = isset( $_SERVER['PHP_AUTH_USER'] ) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
			$pass   = isset( $_SERVER['PHP_AUTH_PW'] )   ? (string) $_SERVER['PHP_AUTH_PW']   : '';
			$source = ( $user !== '' || $pass !== '' ) ? 'PHP_AUTH_*' : 'none';

			// Fallback for servers that don't populate PHP_AUTH_* (e.g. FastCGI without rewrite rules).
			$header = $request->get_header( 'authorization' );
			if ( ( $user === '' || $pass === '' ) && $header && stripos( $header, 'basic ' ) === 0 ) {
				$decoded = base64_decode( substr( $header, 6 ) );
				if ( $decoded && strpos( $decoded, ':' ) !== false ) {
					list( $user, $pass ) = explode( ':', $decoded, 2 );
					$source = 'Authorization header';
				}
			}

			$user_ok = hash_equals( $expected_user, $user );
			$pass_ok = hash_equals( $expected_pass, $pass );
			if ( ! $user_ok || ! $pass_ok ) {
				$this->log( $this->describe_auth_failure( 'Notification', $source, $header, $user, $pass, $user_ok, $pass_ok ) );
				return new WP_Error( 'nxp_auth', 'Invalid credentials', array( 'status' => 401 ) );
			}
			return true;
		}

		/**
		 * Build a diagnostic line for a rejected Basic auth attempt.
		 *
		 * Distinguishes "no credentials reached PHP" from "credentials did not
		 * match", which the 401 response itself cannot express. The password is
		 * never logged, only its length. The username is stripped of control
		 * characters because this endpoint is unauthenticated and anyone can put
		 * arbitrary bytes there.
		 *
		 * @param string      $label   Endpoint name for the log line.
		 * @param string      $source  Where the credentials were read from.
		 * @param string|null $header  Raw Authorization header, if any.
		 * @param string      $user    Username received.
		 * @param string      $pass    Password received.
		 * @param bool        $user_ok Whether the username matched.
		 * @param bool        $pass_ok Whether the password matched.
		 * @return string
		 */
		private function describe_auth_failure( $label, $source, $header, $user, $pass, $user_ok, $pass_ok ) {

			$safe_user = substr( preg_replace( '/[^\x20-\x7E]/', '', $user ), 0, 64 );

			return sprintf(
				'%s auth rejected. Read from: %s. Username: %s (match: %s). Password: %s (match: %s). '
					. 'Authorization header: %s. $_SERVER keys — PHP_AUTH_USER: %s, HTTP_AUTHORIZATION: %s, REDIRECT_HTTP_AUTHORIZATION: %s.',
				$label,
				$source,
				$safe_user === '' ? '(empty)' : '"' . $safe_user . '"',
				$user_ok ? 'yes' : 'no',
				$pass === '' ? '(empty)' : sprintf( '%d chars', strlen( $pass ) ),
				$pass_ok ? 'yes' : 'no',
				$header ? 'present' : 'absent',
				isset( $_SERVER['PHP_AUTH_USER'] ) ? 'set' : 'unset',
				isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? 'set' : 'unset',
				isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? 'set' : 'unset'
			);
		}

		public function handle( WP_REST_Request $request ) {

			$data = $request->get_json_params();
			$this->log( "Notification received:\n" . wp_json_encode( $data, JSON_PRETTY_PRINT ) );

			if ( ! is_array( $data )
				|| empty( $data['subject'] )
				|| $data['subject'] !== 'order'
				|| empty( $data['type'] ) ) {
				return new WP_REST_Response( 'ignored', 200 );
			}

			$merchant_ref = $this->find_merchant_reference( $data );
			if ( ! $merchant_ref ) {
				$this->log( 'Notification without a merchant reference — cannot correlate to a WC order.' );
				return new WP_REST_Response( 'no-ref', 200 );
			}
			$order_id = wc_get_order_id_by_order_key( $merchant_ref );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( ! $order ) {
				$this->log( 'No WC order for merchant reference ' . $merchant_ref );
				return new WP_REST_Response( 'unknown-order', 200 );
			}

			if ( ! empty( $data['order']['id'] ) ) {
				$order->update_meta_data( 'nxp_nexway_order_id', $data['order']['id'] );
			}
			$order->add_order_note( sprintf(
				/* translators: %s: notification type */
				__( 'Nexway notification received (type=%s).', 'nexway' ),
				sanitize_text_field( $data['type'] )
			) );

			$paid_types     = array( 'completed' );
			$failed_types   = array( 'paymentFailed', 'paymentRefused', 'canceled', 'aborted' );

			if ( in_array( $data['type'], $paid_types, true ) ) {
				if ( ! $order->is_paid() ) {
					$order->payment_complete( isset( $data['order']['id'] ) ? $data['order']['id'] : '' );
				}
			}
			elseif ( in_array( $data['type'], $failed_types, true ) ) {
				if ( $order->get_status() !== 'failed' ) {
					$reason = isset( $data['order']['payment']['refusalReason'] )
						? sanitize_text_field( $data['order']['payment']['refusalReason'] )
						: $data['type'];
					$order->update_status( 'failed', $reason );
				}
			}
			// created / partiallyCompleted / fulfillmentFailed → note only, no state change.

			$order->save();
			return new WP_REST_Response( 'ok', 200 );
		}

		/**
		 * Nexway echoes the merchant reference back somewhere on the order payload.
		 * The exact field name isn't in the docs I could read; try the most likely
		 * shapes and fall through.
		 */
		private function find_merchant_reference( $data ) {

			$candidates = array(
				isset( $data['order']['merchantReference'] )         ? $data['order']['merchantReference']         : null,
				isset( $data['merchantReference'] )                  ? $data['merchantReference']                  : null,
				isset( $data['order']['externalReference'] )         ? $data['order']['externalReference']         : null,
				isset( $data['order']['cart']['merchantReference'] ) ? $data['order']['cart']['merchantReference'] : null,
			);
			foreach ( $candidates as $c ) {
				if ( is_string( $c ) && $c !== '' ) {
					return $c;
				}
			}
			return '';
		}

		private function log( $message ) {

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( $message, array( 'source' => NXP_PROCESSOR_SYSTEM_NAME ) );
			}
		}
	}
}
