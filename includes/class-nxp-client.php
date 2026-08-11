<?php
/**
 * Nexway API HTTP client
 *
 * Handles JWT authentication (with refresh-token caching), cart creation,
 * cart lookup, and notification-receiver registration.
 *
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXP_client' ) ) {

	class NXP_client {

		private $client_id;
		private $client_secret;
		private $realm;
		private $base_url;

		public function __construct( $client_id, $client_secret, $realm, $base_url ) {

			$this->client_id     = $client_id;
			$this->client_secret = $client_secret;
			$this->realm         = $realm;
			$this->base_url      = rtrim( $base_url, '/' );
		}

		/**
		 * Return a valid access token, refreshing or re-authenticating as needed.
		 */
		public function get_access_token() {

			$cached = get_transient( 'nxp_access_token' );
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}

			$refresh = get_transient( 'nxp_refresh_token' );
			if ( is_string( $refresh ) && $refresh !== '' ) {
				$token = $this->refresh_token( $refresh );
				if ( $token ) {
					return $token;
				}
			}

			return $this->authenticate();
		}

		private function authenticate() {

			$body = array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'realm'         => $this->realm,
				'grant_type'    => 'client_credentials',
			);
			return $this->request_token( $body );
		}

		private function refresh_token( $refresh ) {

			$body = array(
				'realm'         => $this->realm,
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			);
			return $this->request_token( $body );
		}

		private function request_token( $body ) {

			$url = $this->base_url . '/iam/tokens';
			$response = wp_remote_post( $url, array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 10,
			) );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Token request failed: ' . $response->get_error_message() );
				return false;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$data   = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $status !== 200 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
				$this->log( 'Token request rejected (' . $status . '): ' . wp_remote_retrieve_body( $response ) );
				return false;
			}

			$access_ttl  = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 30 ) : 240;
			$refresh_ttl = isset( $data['refresh_expires_in'] ) ? max( 60, (int) $data['refresh_expires_in'] - 30 ) : 1740;

			set_transient( 'nxp_access_token', $data['access_token'], $access_ttl );
			if ( ! empty( $data['refresh_token'] ) ) {
				set_transient( 'nxp_refresh_token', $data['refresh_token'], $refresh_ttl );
			}
			return $data['access_token'];
		}

		/**
		 * Create an anonymous cart. Returns cartId on success, WP_Error on failure.
		 *
		 * @param array $args {
		 *   store_id, country, locale, items (array of {id, quantity}),
		 *   merchant_reference, return_url
		 * }
		 */
		public function create_public_cart( $args ) {

			$token = $this->get_access_token();
			if ( ! $token ) {
				return new WP_Error( 'nxp_auth', __( 'Nexway authentication failed', 'nexway' ) );
			}

			$body = array(
				'country'         => $args['country'],
				'locale'          => $args['locale'],
				'storeId'         => $args['store_id'],
				'wantedProducts'  => $args['wantedProducts'],
				'externalContext' => base64_encode( wp_json_encode( array( 'merchant_reference' => $args['merchant_reference'] ) ) ),
			);

			$url = $this->base_url . '/cart/carts/public';
			$response = wp_remote_post( $url, array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			) );

			$this->log( sprintf(
				"POST %s\nBody %s",
				$url,
				wp_json_encode( $body, JSON_PRETTY_PRINT )
			) );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Cart create transport error: ' . $response->get_error_message() );
				return $response;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$body_str = wp_remote_retrieve_body( $response );
			$this->log( 'Response ' . $status . ': ' . $body_str );

			if ( $status !== 201 && $status !== 200 ) {
				return new WP_Error( 'nxp_cart', sprintf( 'Cart create failed (%d)', $status ), $body_str );
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( $location ) {
				$parts = explode( '/', rtrim( $location, '/' ) );
				$cart_id = end( $parts );
				if ( $cart_id ) {
					return $cart_id;
				}
			}
			$data = json_decode( $body_str, true );
			if ( is_array( $data ) && ! empty( $data['id'] ) ) {
				return $data['id'];
			}
			return new WP_Error( 'nxp_cart', __( 'Cart created but ID not returned', 'nexway' ) );
		}

		/**
		 * Fetch a cart. Returns the parsed body on success or WP_Error.
		 */
		public function get_cart( $cart_id ) {

			$token = $this->get_access_token();
			if ( ! $token ) {
				return new WP_Error( 'nxp_auth', __( 'Nexway authentication failed', 'nexway' ) );
			}

			$url = $this->base_url . '/cart/carts/' . rawurlencode( $cart_id );
			$response = wp_remote_get( $url, array(
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 10,
			) );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Cart get transport error: ' . $response->get_error_message() );
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );
			$this->log( sprintf( "GET %s\nResponse %d: %s", $url, $status, $body ) );

			if ( $status !== 200 ) {
				return new WP_Error( 'nxp_cart_get', sprintf( 'Cart lookup failed (%d)', $status ), $body );
			}
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'nxp_cart_get', __( 'Cart response is not JSON', 'nexway' ) );
			}
			return $data;
		}

		/**
		 * Register a notification receiver.
		 */
		public function register_receiver( $args ) {

			$token = $this->get_access_token();
			if ( ! $token ) {
				return new WP_Error( 'nxp_auth', __( 'Nexway authentication failed', 'nexway' ) );
			}

			$body = array(
				'customerId'                => $args['customer_id'],
				'url'                       => $args['url'],
				'authentication'            => array(
					'type'     => 'basic',
					'login'    => $args['basic_user'],
					'password' => $args['basic_pass'],
				),
				'notificationDefinitionIds' => $args['notification_definition_ids'],
			);

			$url = $this->base_url . '/notification/receivers';
			$response = wp_remote_post( $url, array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			) );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Receiver register transport error: ' . $response->get_error_message() );
				return $response;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$body_str = wp_remote_retrieve_body( $response );
			$this->log( sprintf( "POST %s\nResponse %d: %s", $url, $status, $body_str ) );

			if ( $status !== 200 && $status !== 201 ) {
				return new WP_Error( 'nxp_receiver', sprintf( 'Receiver registration failed (%d)', $status ), $body_str );
			}
			$data = json_decode( $body_str, true );
			return is_array( $data ) ? $data : true;
		}

		private function log( $message ) {

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( $message, array( 'source' => NXP_PROCESSOR_SYSTEM_NAME ) );
			}
		}
	}
}
