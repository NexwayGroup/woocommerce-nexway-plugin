<?php
/**
 * Nexway plugin admin (gateway settings, product meta box, receiver registration)
 *
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'NXP_admin' ) ) {

	class NXP_admin {

		public function __construct() {}

		public function init_actions() {

			add_action( 'add_meta_boxes', array( $this, 'add_product_meta_box' ) );
			add_action( 'save_post_product', array( $this, 'save_product_meta' ) );
			add_action( 'save_post_product_variation', array( $this, 'save_product_meta' ) );
			add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_field' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_meta' ), 10, 2 );

			add_action( 'wp_ajax_nxp_register_receiver', array( $this, 'ajax_register_receiver' ) );

			add_action( 'admin_menu', array( $this, 'add_mapping_page' ) );
		}

		public function form_fields() {

			return apply_filters( NXP_PROCESSOR_PREFIX . 'form_fields', array(
				'enabled'             => array(
					'title'   => __( 'Enable/Disable', 'nexway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Nexway Payment', 'nexway' ),
					'default' => 'no',
				),
				'title'               => array(
					'title'       => __( 'Title', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'Title shown to the customer at checkout.', 'nexway' ),
					'default'     => __( 'Pay via Nexway', 'nexway' ),
					'desc_tip'    => true,
				),
				'description'         => array(
					'title'       => __( 'Description', 'nexway' ),
					'type'        => 'textarea',
					'description' => __( 'Description shown to the customer at checkout.', 'nexway' ),
					'default'     => __( 'You will be redirected to Nexway to complete your payment.', 'nexway' ),
					'desc_tip'    => true,
				),
				'base_url'            => array(
					'title'       => __( 'Base URL', 'nexway' ),
					'type'        => 'text',
					'default'     => 'https://api.nexway.store',
					'description' => __( 'Root URL for the Nexway API (no trailing slash).', 'nexway' ),
					'desc_tip'    => true,
				),
				'client_id'           => array(
					'title'       => __( 'Client ID', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'OAuth client_id from your Nexway account.', 'nexway' ),
					'desc_tip'    => true,
				),
				'client_secret'       => array(
					'title'       => __( 'Client Secret', 'nexway' ),
					'type'        => 'password',
					'description' => __( 'OAuth client_secret from your Nexway account.', 'nexway' ),
					'desc_tip'    => true,
				),
				'realm'               => array(
					'title'       => __( 'Realm', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'Your Nexway realm identifier.', 'nexway' ),
					'desc_tip'    => true,
				),
				'customer_id'         => array(
					'title'       => __( 'Customer ID', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'Nexway customerId used when registering the notification receiver.', 'nexway' ),
					'desc_tip'    => true,
				),
				'store_id'            => array(
					'title'       => __( 'Store ID', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'UUID of the Nexway store carts are created against.', 'nexway' ),
					'desc_tip'    => true,
				),
				'default_country'     => array(
					'title'       => __( 'Default billing country', 'nexway' ),
					'type'        => 'text',
					'default'     => 'FR',
					'description' => __( 'ISO country code used when the WC order has no billing country.', 'nexway' ),
					'desc_tip'    => true,
				),
				'allowed_currencies'  => array(
					'title'       => __( 'Allowed currencies', 'nexway' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'default'     => '',
					'options'     => get_woocommerce_currencies(),
					'description' => __( 'Currencies for which Nexway is offered at checkout. Leave empty to skip the check.', 'nexway' ),
					'desc_tip'    => true,
				),
				'fulfillment_basic_user'  => array(
					'title'       => __( 'Fulfillment Basic auth user', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'Username Nexway will use to authenticate to the fulfillment endpoint on this site.', 'nexway' ),
					'desc_tip'    => true,
				),
				'fulfillment_basic_pass'  => array(
					'title'       => __( 'Fulfillment Basic auth password', 'nexway' ),
					'type'        => 'password',
					'description' => __( 'Password Nexway will use to authenticate to the fulfillment endpoint on this site.', 'nexway' ),
					'desc_tip'    => true,
				),
				'notification_basic_user' => array(
					'title'       => __( 'Notification Basic auth user', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'Username Nexway will use to authenticate to the notification webhook on this site.', 'nexway' ),
					'desc_tip'    => true,
				),
				'notification_basic_pass' => array(
					'title'       => __( 'Notification Basic auth password', 'nexway' ),
					'type'        => 'password',
					'description' => __( 'Password Nexway will use to authenticate to the notification webhook on this site.', 'nexway' ),
					'desc_tip'    => true,
				),
				'notification_definition_ids' => array(
					'title'       => __( 'Notification definition IDs', 'nexway' ),
					'type'        => 'text',
					'description' => __( 'Comma-separated list of Nexway notification definition IDs to subscribe to (e.g. order-completed, order-paymentFailed).', 'nexway' ),
					'desc_tip'    => true,
				),
				'webhook_info'        => array(
					'title'       => __( 'Webhook URL', 'nexway' ),
					'type'        => 'title',
					'description' => sprintf(
						/* translators: 1: webhook URL, 2: "Register" button HTML */
						__( 'Point Nexway at: <code>%1$s</code><br>%2$s', 'nexway' ),
						esc_url( rest_url( 'nexway/v1/notification/' ) ),
						'<button type="button" class="button button-secondary" id="nxp-register-receiver">'
							. esc_html__( 'Register with Nexway', 'nexway' )
							. '</button> <span id="nxp-register-result"></span>'
					),
				),
				'completed_status'    => array(
					'title'   => __( 'Skip processing status', 'nexway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Move paid orders straight to Completed instead of Processing.', 'nexway' ),
					'default' => 'no',
				),
			) );
		}

		public function add_product_meta_box() {

			add_meta_box(
				'nxp_product',
				__( 'Nexway product mapping', 'nexway' ),
				array( $this, 'render_product_meta_box' ),
				'product',
				'side',
				'default'
			);
		}

		public function render_product_meta_box( $post ) {

			wp_nonce_field( 'nxp_product_meta', 'nxp_product_meta_nonce' );
			$value = get_post_meta( $post->ID, '_nexway_product_id', true );
			?>
			<p>
				<label for="_nexway_product_id"><strong><?php esc_html_e( 'Nexway product ID', 'nexway' ); ?></strong></label><br>
				<input
					type="text"
					id="_nexway_product_id"
					name="_nexway_product_id"
					value="<?php echo esc_attr( $value ); ?>"
					style="width:100%"
					placeholder="2f9bb37b-3558-49f0-bea6-69ab834013de"
				>
			</p>
			<p class="description">
				<?php esc_html_e( 'UUID of the corresponding product in Nexway. Products without a mapping are not offered for Nexway payment.', 'nexway' ); ?>
			</p>
			<?php
		}

		public function save_product_meta( $post_id ) {

			if ( ! isset( $_POST['nxp_product_meta_nonce'] )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nxp_product_meta_nonce'] ) ), 'nxp_product_meta' ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
			if ( isset( $_POST['_nexway_product_id'] ) ) {
				update_post_meta( $post_id, '_nexway_product_id', sanitize_text_field( wp_unslash( $_POST['_nexway_product_id'] ) ) );
			}
		}

		public function variation_field( $loop, $variation_data, $variation ) {

			$value = get_post_meta( $variation->ID, '_nexway_product_id', true );
			?>
			<p class="form-row form-row-full">
				<label><?php esc_html_e( 'Nexway product ID', 'nexway' ); ?></label>
				<input type="text" name="_nexway_product_id_variation[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="UUID">
			</p>
			<?php
		}

		public function save_variation_meta( $variation_id, $loop ) {

			if ( isset( $_POST['_nexway_product_id_variation'][ $loop ] ) ) {
				update_post_meta( $variation_id, '_nexway_product_id',
					sanitize_text_field( wp_unslash( $_POST['_nexway_product_id_variation'][ $loop ] ) ) );
			}
		}

		public function add_mapping_page() {

			add_submenu_page(
				'woocommerce',
				__( 'Nexway Product Mapping', 'nexway' ),
				__( 'Nexway Mapping', 'nexway' ),
				'manage_woocommerce',
				'nxp-product-mapping',
				array( $this, 'render_mapping_page' )
			);
		}

		public function render_mapping_page() {

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Not allowed.', 'nexway' ) );
			}

			$results = null;
			if ( isset( $_POST['nxp_mapping_nonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nxp_mapping_nonce'] ) ), 'nxp_mapping_import' )
				&& ! empty( $_FILES['nxp_mapping_csv']['tmp_name'] ) ) {
				$results = $this->process_csv_upload( $_FILES['nxp_mapping_csv']['tmp_name'] );
			}

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Nexway Product Mapping', 'nexway' ); ?></h1>
				<p><?php esc_html_e( 'Upload a CSV file with two columns: WooCommerce product ID and Nexway product ID. A header row is optional and will be skipped automatically.', 'nexway' ); ?></p>
				<p><code>woo_id,nexway_id</code></p>

				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'nxp_mapping_import', 'nxp_mapping_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="nxp_mapping_csv"><?php esc_html_e( 'CSV file', 'nexway' ); ?></label></th>
							<td><input type="file" id="nxp_mapping_csv" name="nxp_mapping_csv" accept=".csv,text/csv" required></td>
						</tr>
					</table>
					<?php submit_button( __( 'Import', 'nexway' ) ); ?>
				</form>

				<?php if ( $results !== null ) : ?>
				<h2><?php esc_html_e( 'Import results', 'nexway' ); ?></h2>
				<p>
					<?php printf(
						/* translators: 1: updated count, 2: skipped count */
						esc_html__( '%1$d updated, %2$d skipped.', 'nexway' ),
						(int) $results['updated'],
						(int) $results['skipped']
					); ?>
				</p>
				<?php if ( ! empty( $results['rows'] ) ) : ?>
				<table class="widefat striped" style="max-width:700px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Row', 'nexway' ); ?></th>
							<th><?php esc_html_e( 'WC ID', 'nexway' ); ?></th>
							<th><?php esc_html_e( 'Nexway ID', 'nexway' ); ?></th>
							<th><?php esc_html_e( 'Result', 'nexway' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $results['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['row'] ); ?></td>
							<td><?php echo esc_html( $row['woo_id'] ); ?></td>
							<td><?php echo esc_html( $row['nexway_id'] ); ?></td>
							<td><?php echo esc_html( $row['result'] ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
		}

		private function process_csv_upload( $tmp_path ) {

			$results = array( 'updated' => 0, 'skipped' => 0, 'rows' => array() );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $tmp_path, 'r' );
			if ( ! $handle ) {
				return $results;
			}

			$line_number = 0;
			while ( ( $cols = fgetcsv( $handle ) ) !== false ) {
				$line_number++;

				if ( count( $cols ) < 2 ) {
					continue;
				}

				$woo_id    = trim( $cols[0] );
				$nexway_id = trim( $cols[1] );

				// Skip header row.
				if ( $line_number === 1 && ! is_numeric( $woo_id ) ) {
					continue;
				}

				if ( ! is_numeric( $woo_id ) || $nexway_id === '' ) {
					$results['skipped']++;
					$results['rows'][] = array(
						'row'       => $line_number,
						'woo_id'    => $woo_id,
						'nexway_id' => $nexway_id,
						'result'    => __( 'Skipped: invalid data', 'nexway' ),
					);
					continue;
				}

				$woo_id = (int) $woo_id;
				$product = wc_get_product( $woo_id );
				if ( ! $product ) {
					$results['skipped']++;
					$results['rows'][] = array(
						'row'       => $line_number,
						'woo_id'    => $woo_id,
						'nexway_id' => $nexway_id,
						'result'    => __( 'Skipped: product not found', 'nexway' ),
					);
					continue;
				}

				update_post_meta( $woo_id, '_nexway_product_id', sanitize_text_field( $nexway_id ) );
				$results['updated']++;
				$results['rows'][] = array(
					'row'       => $line_number,
					'woo_id'    => $woo_id,
					'nexway_id' => $nexway_id,
					'result'    => __( 'Updated', 'nexway' ),
				);
			}

			fclose( $handle );
			return $results;
		}

		public function ajax_register_receiver() {

			check_ajax_referer( 'nxp_admin', 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'Not allowed', 'nexway' ) ), 403 );
			}

			$settings = get_option( 'woocommerce_' . NXP_PROCESSOR_ID . '_settings', array() );
			$base_url = ! empty( $settings['base_url'] ) ? $settings['base_url'] : 'https://api.nexway.store';

			foreach ( array( 'client_id', 'client_secret', 'realm', 'customer_id',
				'notification_basic_user', 'notification_basic_pass', 'notification_definition_ids' ) as $key ) {
				if ( empty( $settings[ $key ] ) ) {
					wp_send_json_error( array( 'message' => sprintf(
						/* translators: %s: setting key */
						__( 'Setting "%s" is empty.', 'nexway' ), $key ) ) );
				}
			}

			$ids = array_filter( array_map( 'trim', explode( ',', $settings['notification_definition_ids'] ) ) );
			$client = new NXP_client(
				$settings['client_id'],
				$settings['client_secret'],
				$settings['realm'],
				$base_url
			);
			$result = $client->register_receiver( array(
				'customer_id'                 => $settings['customer_id'],
				'url'                         => rest_url( 'nexway/v1/notification/' ),
				'basic_user'                  => $settings['notification_basic_user'],
				'basic_pass'                  => $settings['notification_basic_pass'],
				'notification_definition_ids' => $ids,
			) );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			wp_send_json_success( array( 'message' => __( 'Receiver registered.', 'nexway' ), 'response' => $result ) );
		}
	}
}
