<?php
/**
 * Admin UI and actions.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Admin {
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Settings service.
	 *
	 * @var Schrack_Settings
	 */
	private Schrack_Settings $settings;

	/**
	 * Logger service.
	 *
	 * @var Schrack_Logger
	 */
	private Schrack_Logger $logger;

	/**
	 * Cron service.
	 *
	 * @var Schrack_Cron
	 */
	private Schrack_Cron $cron;

	/**
	 * Category markup service.
	 *
	 * @var Schrack_Category_Markup
	 */
	private Schrack_Category_Markup $markups;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, Schrack_Cron $cron ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->cron     = $cron;
		$this->markups  = new Schrack_Category_Markup( $settings );
	}

	/**
	 * Registers admin hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_schrack_wc_sync_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_schrack_wc_sync_save_markups', array( $this, 'save_markups' ) );
		add_action( 'admin_post_schrack_wc_sync_save_b2b_customers', array( $this, 'save_b2b_customers' ) );
		add_action( 'admin_post_schrack_wc_sync_soap_debug', array( $this, 'soap_debug' ) );
		add_action( 'admin_post_schrack_wc_sync_manual_sync', array( $this, 'manual_sync' ) );
		add_action( 'admin_post_schrack_wc_sync_stop_syncs', array( $this, 'stop_syncs' ) );
		add_action( 'admin_post_schrack_wc_sync_sku_action', array( $this, 'sku_action' ) );
		add_action( 'admin_post_schrack_wc_sync_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'show_user_profile', array( $this, 'render_user_b2b_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_b2b_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_b2b_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_b2b_fields' ) );
	}

	/**
	 * Registers WooCommerce submenu pages.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Schrack Sync Settings', 'schrack-woocommerce-sync' ),
			__( 'Schrack Sync', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			'schrack-sync',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Schrack Category Markups', 'schrack-woocommerce-sync' ),
			__( 'Schrack Markups', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			'schrack-sync-markups',
			array( $this, 'render_markups_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Schrack B2B Customers', 'schrack-woocommerce-sync' ),
			__( 'Schrack B2B', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			'schrack-sync-b2b',
			array( $this, 'render_b2b_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Schrack Manual Sync', 'schrack-woocommerce-sync' ),
			__( 'Schrack Manual Sync', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			'schrack-sync-manual',
			array( $this, 'render_manual_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Schrack Logs', 'schrack-woocommerce-sync' ),
			__( 'Schrack Logs', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			'schrack-sync-logs',
			array( $this, 'render_logs_page' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Schrack Status', 'schrack-woocommerce-sync' ),
			__( 'Schrack Status', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			'schrack-sync-status',
			array( $this, 'render_status_page' )
		);
	}

	/**
	 * Enqueues admin assets.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'schrack-sync' ) ) {
			return;
		}

		wp_enqueue_style( 'schrack-wc-sync-admin', SCHRACK_WC_SYNC_URL . 'assets/admin.css', array(), SCHRACK_WC_SYNC_VERSION );
		wp_enqueue_script( 'schrack-wc-sync-admin', SCHRACK_WC_SYNC_URL . 'assets/admin.js', array(), SCHRACK_WC_SYNC_VERSION, true );
	}

	/**
	 * Saves settings.
	 */
	public function save_settings(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_settings' );

		$input = isset( $_POST['schrack_settings'] ) && is_array( $_POST['schrack_settings'] )
			? $_POST['schrack_settings']
			: array();

		$this->settings->update( $input );
		$this->cron->reschedule();
		$this->logger->info( 'admin', 'Schrack Sync settings were updated.' );
		$this->set_notice( 'success', __( 'Settings saved.', 'schrack-woocommerce-sync' ) );
		$this->redirect( 'schrack-sync' );
	}

	/**
	 * Saves category markup rules.
	 */
	public function save_markups(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_markups' );

		$input = isset( $_POST['schrack_markups'] ) && is_array( $_POST['schrack_markups'] )
			? wp_unslash( $_POST['schrack_markups'] )
			: array();

		if ( isset( $_POST['schrack_bulk_submit'] ) ) {
			$bulk = isset( $_POST['schrack_bulk'] ) && is_array( $_POST['schrack_bulk'] )
				? wp_unslash( $_POST['schrack_bulk'] )
				: array();
			$input = $this->markups->merge_bulk_input( $input, $bulk );
		}

		$this->markups->update( $input );
		$this->logger->info( 'admin', 'Schrack category markup rules were updated.' );
		$this->set_notice( 'success', __( 'Category markups saved.', 'schrack-woocommerce-sync' ) );
		$this->redirect( 'schrack-sync-markups' );
	}

	/**
	 * Saves B2B customer verification and discount rows.
	 */
	public function save_b2b_customers(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_b2b_customers' );

		$input = isset( $_POST['schrack_b2b_customers'] ) && is_array( $_POST['schrack_b2b_customers'] )
			? wp_unslash( $_POST['schrack_b2b_customers'] )
			: array();

		$updated = 0;

		foreach ( $input as $user_id => $row ) {
			$user_id = absint( $user_id );

			if ( $user_id <= 0 || ! is_array( $row ) || ! current_user_can( 'edit_user', $user_id ) ) {
				continue;
			}

			$this->save_b2b_user_meta( $user_id, $row );
			$updated++;
		}

		$this->logger->info( 'admin', 'Schrack B2B customers were updated.', '', array( 'updated' => $updated ) );
		$this->set_notice(
			'success',
			sprintf(
				/* translators: %d: updated customers. */
				__( '%d B2B customer rows saved.', 'schrack-woocommerce-sync' ),
				$updated
			)
		);
		$this->redirect( 'schrack-sync-b2b' );
	}

	/**
	 * Handles SOAP connection and WSDL debug actions.
	 */
	public function soap_debug(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_soap_debug' );

		$debug_task = isset( $_POST['debug_task'] ) ? sanitize_key( wp_unslash( (string) $_POST['debug_task'] ) ) : 'test_connection';
		$client     = new Schrack_Soap_Client( $this->settings, $this->logger );

		try {
			if ( 'list_wsdl' === $debug_task ) {
				if ( 'yes' !== $this->settings->get( 'debug_enabled', 'no' ) ) {
					$this->set_notice( 'warning', __( 'Enable debug mode before listing WSDL functions and types.', 'schrack-woocommerce-sync' ) );
					$this->redirect( 'schrack-sync' );
				}

				$functions = $client->get_functions();
				$types     = $client->get_types();
				$data      = array(
					'wsdl_url'          => $client->get_loaded_wsdl_url(),
					'soap_endpoint_url' => (string) $this->settings->get( 'soap_endpoint_url' ),
					'functions'         => $functions,
					'types'             => $types,
				);

				$this->set_notice(
					'success',
					$this->append_wsdl_fallback_note( __( 'WSDL functions and types loaded.', 'schrack-woocommerce-sync' ), $client ),
					$data
				);
			} else {
				$functions = $client->get_functions();
				$this->set_notice(
					'success',
					$this->append_wsdl_fallback_note(
						sprintf(
							/* translators: %d: SOAP function count. */
							__( 'WSDL connection OK. WSDL exposes %d functions.', 'schrack-woocommerce-sync' ),
							count( $functions )
						),
						$client
					)
				);
			}
		} catch ( Throwable $exception ) {
			$this->logger->error( 'soap', 'SOAP debug action failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->set_notice( 'error', $exception->getMessage() );
		}

		$this->redirect( 'schrack-sync' );
	}

	/**
	 * Queues manual sync tasks.
	 */
	public function manual_sync(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_manual_sync' );

		$task = isset( $_POST['sync_task'] ) ? sanitize_key( wp_unslash( (string) $_POST['sync_task'] ) ) : '';
		$result = $this->cron->queue_action( $task );

		if ( ! empty( $result['queued'] ) ) {
			$this->set_notice( 'success', (string) $result['message'], $this->format_debug_data( $result ) );
		} elseif ( in_array( (string) ( $result['code'] ?? '' ), array( 'active_sync', 'image_import_disabled' ), true ) ) {
			$this->set_notice( 'warning', (string) $result['message'] );
		} else {
			$this->set_notice( 'error', (string) ( $result['message'] ?? __( 'Unknown sync task.', 'schrack-woocommerce-sync' ) ) );
		}

		$this->redirect( 'schrack-sync-manual' );
	}

	/**
	 * Stops queued sync actions and asks running batches to exit safely.
	 */
	public function stop_syncs(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_stop_syncs' );

		$result        = $this->cron->stop_actions();
		$redirect_page = isset( $_POST['redirect_page'] ) ? sanitize_key( wp_unslash( (string) $_POST['redirect_page'] ) ) : 'schrack-sync-manual';
		$redirect_page = in_array( $redirect_page, array( 'schrack-sync-manual', 'schrack-sync-status' ), true ) ? $redirect_page : 'schrack-sync-manual';

		$this->set_notice(
			'warning',
			sprintf(
				/* translators: 1: cancelled pending actions, 2: running actions. */
				__( 'Stop requested. Cancelled %1$d queued sync actions. %2$d running action(s) will stop at the next safe checkpoint. Configured recurring syncs were reset.', 'schrack-woocommerce-sync' ),
				absint( $result['pending_cancelled'] ?? 0 ),
				absint( $result['running'] ?? 0 )
			),
			$result
		);
		$this->redirect( $redirect_page );
	}

	/**
	 * Handles manual SKU tests and MVP product upsert.
	 */
	public function sku_action(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_sku_action' );

		$task = isset( $_POST['sku_task'] ) ? sanitize_key( wp_unslash( (string) $_POST['sku_task'] ) ) : '';
		$sku  = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['sku'] ) ) : '';

		if ( '' === $sku ) {
			$this->set_notice( 'error', __( 'SKU is required.', 'schrack-woocommerce-sync' ) );
			$this->redirect( 'schrack-sync-manual' );
		}

		try {
			if ( 'fetch_price' === $task ) {
				$sync   = new Schrack_Price_Sync( $this->settings, $this->logger );
				$result = $sync->fetch_price( $sku );
				$this->set_notice( 'success', __( 'Price lookup finished.', 'schrack-woocommerce-sync' ), $this->format_debug_data( $result ) );
			} elseif ( 'fetch_stock' === $task ) {
				$sync   = new Schrack_Stock_Sync( $this->settings, $this->logger );
				$result = $sync->fetch_stock( $sku );
				$this->set_notice( 'success', __( 'Stock lookup finished.', 'schrack-woocommerce-sync' ), $this->format_debug_data( $result ) );
			} elseif ( 'upsert_product' === $task ) {
				$result = $this->upsert_manual_product( $sku );
				$this->set_notice( 'success', __( 'Product create/update finished.', 'schrack-woocommerce-sync' ), $result );
			} else {
				$this->set_notice( 'error', __( 'Unknown SKU action.', 'schrack-woocommerce-sync' ) );
			}
		} catch ( Throwable $exception ) {
			$this->logger->error( 'admin', 'Manual SKU action failed.', $sku, array( 'error' => $exception->getMessage() ) );
			$this->set_notice( 'error', $exception->getMessage() );
		}

		$this->redirect( 'schrack-sync-manual' );
	}

	/**
	 * Clears all logs.
	 */
	public function clear_logs(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_clear_logs' );

		$this->logger->delete_all();
		$this->set_notice( 'success', __( 'Logs cleared.', 'schrack-woocommerce-sync' ) );
		$this->redirect( 'schrack-sync-logs' );
	}

	/**
	 * Renders B2B fields on WordPress user profiles.
	 */
	public function render_user_b2b_fields( WP_User $user ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$user_id      = (int) $user->ID;
		$account_type = sanitize_key( (string) get_user_meta( $user_id, '_schrack_account_type', true ) );
		$b2b_status   = sanitize_key( (string) get_user_meta( $user_id, '_schrack_b2b_status', true ) );
		$company      = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_company_name', true ) );
		$cui          = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_cui', true ) );
		$reg_number   = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_registration_number', true ) );
		$requested_at = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_requested_at', true ) );
		$discount     = $this->sanitize_b2b_discount_percent( get_user_meta( $user_id, '_schrack_b2b_discount_percent', true ) );

		if ( '' === $company ) {
			$company = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_company', true ) );
		}

		if ( '' === $cui ) {
			$cui = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_vat_number', true ) );
		}

		$account_type = 'b2b' === $account_type ? 'b2b' : 'customer';
		$b2b_status   = in_array( $b2b_status, array( 'pending', 'approved', 'rejected', 'disabled' ), true ) ? $b2b_status : 'pending';

		?>
		<h2><?php esc_html_e( 'Schrack B2B', 'schrack-woocommerce-sync' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="schrack_account_type"><?php esc_html_e( 'Tip cont', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_account_type" name="schrack_b2b[account_type]">
						<option value="customer" <?php selected( $account_type, 'customer' ); ?>><?php esc_html_e( 'Client standard', 'schrack-woocommerce-sync' ); ?></option>
						<option value="b2b" <?php selected( $account_type, 'b2b' ); ?>><?php esc_html_e( 'B2B', 'schrack-woocommerce-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="schrack_b2b_status"><?php esc_html_e( 'Status B2B', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<select id="schrack_b2b_status" name="schrack_b2b[status]">
						<option value="pending" <?php selected( $b2b_status, 'pending' ); ?>><?php esc_html_e( 'In verificare', 'schrack-woocommerce-sync' ); ?></option>
						<option value="approved" <?php selected( $b2b_status, 'approved' ); ?>><?php esc_html_e( 'Aprobat', 'schrack-woocommerce-sync' ); ?></option>
						<option value="rejected" <?php selected( $b2b_status, 'rejected' ); ?>><?php esc_html_e( 'Respins', 'schrack-woocommerce-sync' ); ?></option>
						<option value="disabled" <?php selected( $b2b_status, 'disabled' ); ?>><?php esc_html_e( 'Dezactivat', 'schrack-woocommerce-sync' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Acest status este afisat in pagina de cont client/B2B.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="schrack_b2b_discount"><?php esc_html_e( 'Discount B2B %', 'schrack-woocommerce-sync' ); ?></label></th>
				<td>
					<input id="schrack_b2b_discount" type="number" min="0" max="100" step="0.01" name="schrack_b2b[discount_percent]" value="<?php echo esc_attr( $this->format_percent_value( $discount ) ); ?>">
					<p class="description"><?php esc_html_e( 'Se aplica automat doar clientilor cu tip cont B2B si status Aprobat.', 'schrack-woocommerce-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="schrack_b2b_company"><?php esc_html_e( 'Companie', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input id="schrack_b2b_company" class="regular-text" type="text" name="schrack_b2b[company]" value="<?php echo esc_attr( $company ); ?>"></td>
			</tr>
			<tr>
				<th><label for="schrack_b2b_cui"><?php esc_html_e( 'CUI / Cod fiscal', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input id="schrack_b2b_cui" class="regular-text" type="text" name="schrack_b2b[cui]" value="<?php echo esc_attr( $cui ); ?>"></td>
			</tr>
			<tr>
				<th><label for="schrack_b2b_reg_number"><?php esc_html_e( 'Nr. Registrul Comertului', 'schrack-woocommerce-sync' ); ?></label></th>
				<td><input id="schrack_b2b_reg_number" class="regular-text" type="text" name="schrack_b2b[registration_number]" value="<?php echo esc_attr( $reg_number ); ?>"></td>
			</tr>
			<?php if ( '' !== $requested_at ) : ?>
				<tr>
					<th><?php esc_html_e( 'Cerere primita', 'schrack-woocommerce-sync' ); ?></th>
					<td><?php echo esc_html( $requested_at ); ?></td>
				</tr>
			<?php endif; ?>
		</table>
		<?php wp_nonce_field( 'schrack_user_b2b_fields', 'schrack_user_b2b_nonce' ); ?>
		<?php
	}

	/**
	 * Saves B2B fields from WordPress user profiles.
	 */
	public function save_user_b2b_fields( int $user_id ): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['schrack_user_b2b_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['schrack_user_b2b_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'schrack_user_b2b_fields' ) ) {
			return;
		}

		$input = isset( $_POST['schrack_b2b'] ) && is_array( $_POST['schrack_b2b'] )
			? wp_unslash( $_POST['schrack_b2b'] )
			: array();

		$this->save_b2b_user_meta( $user_id, $input );
	}

	/**
	 * Saves normalized B2B metadata for one customer.
	 *
	 * @param array<string,mixed> $input Unsanitized row input.
	 */
	private function save_b2b_user_meta( int $user_id, array $input ): void {
		$account_type = isset( $input['account_type'] ) && 'b2b' === sanitize_key( (string) $input['account_type'] ) ? 'b2b' : 'customer';
		$status       = $this->sanitize_admin_choice( $input['status'] ?? 'pending', array( 'pending', 'approved', 'rejected', 'disabled' ), 'pending' );
		$company      = isset( $input['company'] ) ? sanitize_text_field( (string) $input['company'] ) : '';
		$cui          = isset( $input['cui'] ) ? sanitize_text_field( (string) $input['cui'] ) : '';
		$reg_number   = isset( $input['registration_number'] ) ? sanitize_text_field( (string) $input['registration_number'] ) : '';
		$discount     = $this->sanitize_b2b_discount_percent( $input['discount_percent'] ?? 0 );

		update_user_meta( $user_id, '_schrack_account_type', $account_type );
		update_user_meta( $user_id, '_schrack_b2b_status', $status );
		update_user_meta( $user_id, '_schrack_b2b_company_name', $company );
		update_user_meta( $user_id, '_schrack_b2b_cui', $cui );
		update_user_meta( $user_id, '_schrack_b2b_registration_number', $reg_number );
		update_user_meta( $user_id, '_schrack_b2b_discount_percent', $this->format_percent_value( $discount ) );

		if ( 'b2b' === $account_type && '' === (string) get_user_meta( $user_id, '_schrack_b2b_requested_at', true ) ) {
			update_user_meta( $user_id, '_schrack_b2b_requested_at', current_time( 'mysql' ) );
		}

		if ( 'b2b' === $account_type && 'approved' === $status && '' === (string) get_user_meta( $user_id, '_schrack_b2b_approved_at', true ) ) {
			update_user_meta( $user_id, '_schrack_b2b_approved_at', current_time( 'mysql' ) );
		}

		if ( '' !== $company ) {
			update_user_meta( $user_id, 'billing_company', $company );
		}

		if ( '' !== $cui ) {
			update_user_meta( $user_id, 'billing_vat_number', $cui );
		}
	}

	/**
	 * Sanitizes a B2B discount percentage.
	 */
	private function sanitize_b2b_discount_percent( mixed $value ): float {
		$value = is_scalar( $value ) ? str_replace( ',', '.', (string) $value ) : '0';

		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		return max( 0.0, min( 100.0, round( (float) $value, 2 ) ) );
	}

	/**
	 * Formats percentage values for admin inputs.
	 */
	private function format_percent_value( float $value ): string {
		$value = round( $value, 2 );

		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Sanitizes a small admin select value.
	 *
	 * @param array<int,string> $allowed Allowed values.
	 */
	private function sanitize_admin_choice( mixed $value, array $allowed, string $default ): string {
		$value = sanitize_key( is_scalar( $value ) ? (string) $value : '' );

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Renders settings page.
	 */
	public function render_settings_page(): void {
		$this->assert_can_manage();

		$settings = $this->settings->all();
		$notice   = $this->get_notice();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-settings.php';
	}

	/**
	 * Renders category markups page.
	 */
	public function render_markups_page(): void {
		$this->assert_can_manage();

		$terms  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$terms  = is_wp_error( $terms ) ? array() : $terms;
		$rules  = $this->markups->all();
		$notice = $this->get_notice();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-markups.php';
	}

	/**
	 * Renders B2B verification and discount page.
	 */
	public function render_b2b_page(): void {
		$this->assert_can_manage();

		$customers = $this->b2b_customer_rows();
		$summary   = $this->b2b_customer_summary( $customers );
		$notice    = $this->get_notice();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-b2b.php';
	}

	/**
	 * Renders manual sync page.
	 */
	public function render_manual_page(): void {
		$this->assert_can_manage();

		$settings       = $this->settings->all();
		$notice         = $this->get_notice();
		$queue_status   = $this->cron->queue_status();
		$stop_request   = $this->active_stop_request( $this->settings->stop_request(), $queue_status );
		$sync_dashboard = $this->sync_dashboard_stats();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-sync.php';
	}

	/**
	 * Renders logs page.
	 */
	public function render_logs_page(): void {
		$this->assert_can_manage();

		$args = array(
			'level'     => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( (string) $_GET['level'] ) ) : '',
			'operation' => isset( $_GET['operation'] ) ? sanitize_key( wp_unslash( (string) $_GET['operation'] ) ) : '',
			'sku'       => isset( $_GET['sku'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sku'] ) ) : '',
			'limit'     => 100,
		);
		$logs   = $this->logger->get_logs( $args );
		$notice = $this->get_notice();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-logs.php';
	}

	/**
	 * Renders status page.
	 */
	public function render_status_page(): void {
		$this->assert_can_manage();

		$status         = $this->settings->get_status();
		$settings       = $this->settings->all();
		$notice         = $this->get_notice();
		$queue_status   = $this->cron->queue_status();
		$stop_request   = $this->active_stop_request( $this->settings->stop_request(), $queue_status );
		$sync_dashboard = $this->sync_dashboard_stats();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-status.php';
	}

	/**
	 * Returns sync coverage counters for the admin dashboard.
	 *
	 * @return array<string,mixed>
	 */
	private function sync_dashboard_stats(): array {
		global $wpdb;

		$sql = "
			SELECT
				COUNT(*) AS imported_products,
				SUM(CASE WHEN sync_meta.image_url <> '' THEN 1 ELSE 0 END) AS image_url_products,
				SUM(
					CASE
						WHEN sync_meta.image_url <> ''
							AND sync_meta.imported_image_url = sync_meta.image_url
							AND (
								thumbnail_attachment.ID IS NOT NULL
								OR stored_attachment.ID IS NOT NULL
							)
						THEN 1
						ELSE 0
					END
				) AS image_synced_products,
				SUM(
					CASE
						WHEN sync_meta.image_url <> ''
							AND NOT (
								sync_meta.imported_image_url = sync_meta.image_url
								AND (
									thumbnail_attachment.ID IS NOT NULL
									OR stored_attachment.ID IS NOT NULL
								)
							)
						THEN 1
						ELSE 0
					END
				) AS image_url_only_products,
				SUM(CASE WHEN sync_meta.last_price_sync <> '' THEN 1 ELSE 0 END) AS price_synced_products,
				SUM(CASE WHEN sync_meta.last_stock_sync <> '' THEN 1 ELSE 0 END) AS stock_synced_products
			FROM (
				SELECT
					products.ID AS product_id,
					MAX(CASE WHEN product_meta.meta_key = '_schrack_image_url' THEN product_meta.meta_value ELSE '' END) AS image_url,
					MAX(CASE WHEN product_meta.meta_key = '_schrack_imported_image_url' THEN product_meta.meta_value ELSE '' END) AS imported_image_url,
					MAX(CASE WHEN product_meta.meta_key = '_thumbnail_id' THEN product_meta.meta_value ELSE '' END) AS thumbnail_id,
					MAX(CASE WHEN product_meta.meta_key = '_schrack_image_attachment_id' THEN product_meta.meta_value ELSE '' END) AS image_attachment_id,
					MAX(CASE WHEN product_meta.meta_key = '_schrack_last_price_sync' THEN product_meta.meta_value ELSE '' END) AS last_price_sync,
					MAX(CASE WHEN product_meta.meta_key = '_schrack_last_stock_sync' THEN product_meta.meta_value ELSE '' END) AS last_stock_sync
				FROM {$wpdb->posts} AS products
				INNER JOIN {$wpdb->postmeta} AS item_meta
					ON item_meta.post_id = products.ID
					AND item_meta.meta_key = '_schrack_item_number'
					AND item_meta.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} AS product_meta
					ON product_meta.post_id = products.ID
					AND product_meta.meta_key IN (
						'_schrack_image_url',
						'_schrack_imported_image_url',
						'_thumbnail_id',
						'_schrack_image_attachment_id',
						'_schrack_last_price_sync',
						'_schrack_last_stock_sync'
					)
				WHERE products.post_type = 'product'
					AND products.post_status IN ('publish', 'draft', 'private')
				GROUP BY products.ID
			) AS sync_meta
			LEFT JOIN {$wpdb->posts} AS thumbnail_attachment
				ON thumbnail_attachment.ID = CAST(sync_meta.thumbnail_id AS UNSIGNED)
				AND thumbnail_attachment.post_type = 'attachment'
				AND thumbnail_attachment.post_status = 'inherit'
			LEFT JOIN {$wpdb->posts} AS stored_attachment
				ON stored_attachment.ID = CAST(sync_meta.image_attachment_id AS UNSIGNED)
				AND stored_attachment.post_type = 'attachment'
				AND stored_attachment.post_status = 'inherit'
		";
		$row = $wpdb->get_row( $sql, ARRAY_A );
		$row = is_array( $row ) ? $row : array();

		$imported_products       = absint( $row['imported_products'] ?? 0 );
		$image_url_products      = absint( $row['image_url_products'] ?? 0 );
		$image_synced_products   = absint( $row['image_synced_products'] ?? 0 );
		$image_url_only_products = absint( $row['image_url_only_products'] ?? 0 );
		$price_synced_products   = absint( $row['price_synced_products'] ?? 0 );
		$stock_synced_products   = absint( $row['stock_synced_products'] ?? 0 );

		return array(
			'imported_products'       => $imported_products,
			'image_url_products'      => $image_url_products,
			'image_synced_products'   => $image_synced_products,
			'image_url_only_products' => $image_url_only_products,
			'image_missing_url_products' => max( 0, $imported_products - $image_url_products ),
			'price_synced_products'   => $price_synced_products,
			'stock_synced_products'   => $stock_synced_products,
			'image_synced_pct'        => $this->sync_dashboard_percentage( $image_synced_products, $imported_products ),
			'image_url_only_pct'      => $this->sync_dashboard_percentage( $image_url_only_products, $imported_products ),
			'price_synced_pct'        => $this->sync_dashboard_percentage( $price_synced_products, $imported_products ),
			'stock_synced_pct'        => $this->sync_dashboard_percentage( $stock_synced_products, $imported_products ),
			'calculated_at'           => current_time( 'mysql' ),
			'query_error'             => (string) $wpdb->last_error,
		);
	}

	/**
	 * Calculates a dashboard percentage.
	 */
	private function sync_dashboard_percentage( int $value, int $total ): float {
		if ( $total <= 0 ) {
			return 0.0;
		}

		return round( ( $value / $total ) * 100, 1 );
	}

	/**
	 * Returns B2B-related users for admin verification and discount editing.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function b2b_customer_rows(): array {
		$query = new WP_User_Query(
			array(
				'number'     => 300,
				'orderby'    => 'registered',
				'order'      => 'DESC',
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => '_schrack_account_type',
						'value' => 'b2b',
					),
					array(
						'key'     => '_schrack_b2b_status',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_schrack_b2b_cui',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'billing_vat_number',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$rows = array();

		foreach ( $query->get_results() as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$user_id      = (int) $user->ID;
			$account_type = sanitize_key( (string) get_user_meta( $user_id, '_schrack_account_type', true ) );
			$status       = sanitize_key( (string) get_user_meta( $user_id, '_schrack_b2b_status', true ) );
			$company      = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_company_name', true ) );
			$cui          = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_cui', true ) );

			if ( '' === $company ) {
				$company = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_company', true ) );
			}

			if ( '' === $cui ) {
				$cui = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_vat_number', true ) );
			}

			if ( 'b2b' !== $account_type && '' !== $cui ) {
				$account_type = 'b2b';
			}

			$account_type = 'b2b' === $account_type ? 'b2b' : 'customer';
			$status       = in_array( $status, array( 'pending', 'approved', 'rejected', 'disabled' ), true ) ? $status : 'pending';
			$discount     = $this->sanitize_b2b_discount_percent( get_user_meta( $user_id, '_schrack_b2b_discount_percent', true ) );

			$rows[] = array(
				'user_id'             => $user_id,
				'name'                => $user->display_name,
				'email'               => $user->user_email,
				'registered'          => $user->user_registered,
				'account_type'        => $account_type,
				'status'              => $status,
				'status_label'        => $this->b2b_status_label( $status ),
				'company'             => $company,
				'cui'                 => $cui,
				'registration_number' => sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_registration_number', true ) ),
				'requested_at'        => sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_requested_at', true ) ),
				'approved_at'         => sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_approved_at', true ) ),
				'discount'            => $discount,
				'discount_display'    => $this->format_percent_value( $discount ),
				'edit_url'            => get_edit_user_link( $user_id ),
			);
		}

		$priority = array(
			'pending'  => 0,
			'approved' => 1,
			'rejected' => 2,
			'disabled' => 3,
		);

		usort(
			$rows,
			static function ( array $left, array $right ) use ( $priority ): int {
				$status_order = ( $priority[ (string) $left['status'] ] ?? 9 ) <=> ( $priority[ (string) $right['status'] ] ?? 9 );

				if ( 0 !== $status_order ) {
					return $status_order;
				}

				return strnatcasecmp( (string) $left['company'], (string) $right['company'] );
			}
		);

		return $rows;
	}

	/**
	 * Builds B2B customer counters for the admin page.
	 *
	 * @param array<int,array<string,mixed>> $customers Customer rows.
	 * @return array<string,int>
	 */
	private function b2b_customer_summary( array $customers ): array {
		$summary = array(
			'total'      => count( $customers ),
			'pending'    => 0,
			'approved'   => 0,
			'rejected'   => 0,
			'disabled'   => 0,
			'discounted' => 0,
		);

		foreach ( $customers as $customer ) {
			$status = (string) ( $customer['status'] ?? '' );

			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}

			if ( (float) ( $customer['discount'] ?? 0 ) > 0.0 ) {
				$summary['discounted']++;
			}
		}

		return $summary;
	}

	/**
	 * Returns a readable B2B status label.
	 */
	public function b2b_status_label( string $status ): string {
		return match ( $status ) {
			'approved' => __( 'Aprobat', 'schrack-woocommerce-sync' ),
			'rejected' => __( 'Respins', 'schrack-woocommerce-sync' ),
			'disabled' => __( 'Dezactivat', 'schrack-woocommerce-sync' ),
			default    => __( 'In verificare', 'schrack-woocommerce-sync' ),
		};
	}

	/**
	 * Keeps the stop banner visible only while a sync action is actually running.
	 *
	 * @param array<string,mixed>|null      $stop_request Stop request.
	 * @param array<int,array<string,mixed>> $queue_status Queue status rows.
	 * @return array<string,mixed>|null
	 */
	private function active_stop_request( ?array $stop_request, array $queue_status ): ?array {
		if ( null === $stop_request ) {
			return null;
		}

		foreach ( $queue_status as $row ) {
			if ( absint( $row['running'] ?? 0 ) > 0 ) {
				return $stop_request;
			}
		}

		return null;
	}

	/**
	 * Renders page tabs.
	 */
	public function render_tabs( string $active ): void {
		$tabs = array(
			'settings' => array( 'label' => __( 'Settings', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync' ),
			'markups'  => array( 'label' => __( 'Category Markups', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-markups' ),
			'b2b'      => array( 'label' => __( 'B2B Customers', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-b2b' ),
			'manual'   => array( 'label' => __( 'Manual Sync', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-manual' ),
			'logs'     => array( 'label' => __( 'Logs', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-logs' ),
			'status'   => array( 'label' => __( 'Status', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-status' ),
		);

		echo '<nav class="nav-tab-wrapper schrack-sync-tabs">';
		foreach ( $tabs as $key => $tab ) {
			printf(
				'<a class="nav-tab %1$s" href="%2$s">%3$s</a>',
				$key === $active ? 'nav-tab-active' : '',
				esc_url( admin_url( 'admin.php?page=' . $tab['slug'] ) ),
				esc_html( $tab['label'] )
			);
		}
		echo '</nav>';
	}

	/**
	 * Renders notices stored after redirects.
	 *
	 * @param array<string,mixed>|null $notice Notice.
	 */
	public function render_notice( ?array $notice ): void {
		if ( empty( $notice ) ) {
			return;
		}

		$notice_type    = isset( $notice['type'] ) ? (string) $notice['type'] : 'info';
		$notice_message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
		$type           = in_array( $notice_type, array( 'success', 'warning', 'error', 'info' ), true ) ? $notice_type : 'info';

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice_message )
		);

		if ( ! empty( $notice['data'] ) ) {
			echo '<div class="schrack-debug-output"><pre>';
			echo esc_html( wp_json_encode( $notice['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			echo '</pre></div>';
		}
	}

	/**
	 * Returns a masked configured label.
	 */
	public function configured_label( string $value ): string {
		return '' !== $value ? __( 'Configured', 'schrack-woocommerce-sync' ) : __( 'Missing', 'schrack-woocommerce-sync' );
	}

	/**
	 * Builds a manual product from POST fields.
	 *
	 * @return array<string,mixed>
	 */
	private function upsert_manual_product( string $sku ): array {
		$mapper = new Schrack_Product_Mapper( $this->settings, $this->logger );
		$data   = array(
			'sku'               => $sku,
			'name'              => isset( $_POST['product_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['product_name'] ) ) : '',
			'short_description' => isset( $_POST['short_description'] ) ? wp_kses_post( wp_unslash( (string) $_POST['short_description'] ) ) : '',
			'description'       => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( (string) $_POST['description'] ) ) : '',
			'manufacturer'      => isset( $_POST['manufacturer'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['manufacturer'] ) ) : '',
			'ean'               => isset( $_POST['ean'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ean'] ) ) : '',
			'category_path'     => isset( $_POST['category_path'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['category_path'] ) ) : '',
			'unit'              => isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['unit'] ) ) : '',
		);

		$product_id = $mapper->upsert( $data );
		$response   = array( 'product_id' => $product_id );

		$purchase_price = isset( $_POST['purchase_price'] ) ? trim( str_replace( ',', '.', wp_unslash( (string) $_POST['purchase_price'] ) ) ) : '';
		if ( '' !== $purchase_price && is_numeric( $purchase_price ) ) {
			$response['regular_price'] = $mapper->update_price( $product_id, (float) $purchase_price );
		}

		$stock_quantity = isset( $_POST['stock_quantity'] ) ? trim( str_replace( ',', '.', wp_unslash( (string) $_POST['stock_quantity'] ) ) ) : '';
		if ( '' !== $stock_quantity && is_numeric( $stock_quantity ) ) {
			$response['total_stock'] = $mapper->update_stock(
				$product_id,
				array(
					'total_stock' => (float) $stock_quantity,
					'warehouses'  => array(
						array(
							'quantity' => (float) $stock_quantity,
							'source'   => 'manual',
							'label'    => 'Manual admin input',
						),
					),
				)
			);
		}

		return $response;
	}

	/**
	 * Keeps debug data compact and JSON-friendly.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private function format_debug_data( mixed $data ): mixed {
		$encoded = wp_json_encode( $data );

		if ( false !== $encoded && strlen( $encoded ) > 20000 ) {
			return array(
				'notice'  => 'Response was truncated for display.',
				'preview' => substr( $encoded, 0, 20000 ),
			);
		}

		return $data;
	}

	/**
	 * Adds a note when the SOAP client used a fallback WSDL URL.
	 */
	private function append_wsdl_fallback_note( string $message, Schrack_Soap_Client $client ): string {
		$loaded_wsdl     = $client->get_loaded_wsdl_url();
		$configured_wsdl = (string) $this->settings->get( 'wsdl_url' );

		if ( '' === $loaded_wsdl || $loaded_wsdl === $configured_wsdl ) {
			return $message;
		}

		return $message . ' ' . sprintf(
			/* translators: %s: fallback WSDL URL. */
			__( 'Loaded WSDL from fallback URL %s because the configured TEST WSDL is unavailable. SOAP calls still use the configured endpoint URL.', 'schrack-woocommerce-sync' ),
			$loaded_wsdl
		);
	}

	/**
	 * Stores a redirect notice.
	 *
	 * @param string              $type Notice type.
	 * @param string              $message Message.
	 * @param array<string,mixed>|mixed $data Optional data.
	 */
	private function set_notice( string $type, string $message, mixed $data = array() ): void {
		set_transient(
			$this->notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
				'data'    => $data,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Gets and clears a redirect notice.
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_notice(): ?array {
		$key    = $this->notice_key();
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Notice transient key.
	 */
	private function notice_key(): string {
		return 'schrack_wc_sync_notice_' . get_current_user_id();
	}

	/**
	 * Redirects back to an admin page.
	 */
	private function redirect( string $page ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	/**
	 * Capability guard.
	 */
	private function assert_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Schrack Sync.', 'schrack-woocommerce-sync' ) );
		}
	}
}
