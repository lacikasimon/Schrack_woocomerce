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
		add_action( 'admin_post_schrack_wc_sync_soap_debug', array( $this, 'soap_debug' ) );
		add_action( 'admin_post_schrack_wc_sync_manual_sync', array( $this, 'manual_sync' ) );
		add_action( 'admin_post_schrack_wc_sync_sku_action', array( $this, 'sku_action' ) );
		add_action( 'admin_post_schrack_wc_sync_clear_logs', array( $this, 'clear_logs' ) );
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

		$this->markups->update( $input );
		$this->logger->info( 'admin', 'Schrack category markup rules were updated.' );
		$this->set_notice( 'success', __( 'Category markups saved.', 'schrack-woocommerce-sync' ) );
		$this->redirect( 'schrack-sync-markups' );
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
			$this->set_notice( 'success', (string) $result['message'] );
		} elseif ( 'active_sync' === (string) ( $result['code'] ?? '' ) ) {
			$this->set_notice( 'warning', (string) $result['message'] );
		} else {
			$this->set_notice( 'error', (string) ( $result['message'] ?? __( 'Unknown sync task.', 'schrack-woocommerce-sync' ) ) );
		}

		$this->redirect( 'schrack-sync-manual' );
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
	 * Renders manual sync page.
	 */
	public function render_manual_page(): void {
		$this->assert_can_manage();

		$notice       = $this->get_notice();
		$queue_status = $this->cron->queue_status();

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

		$status   = $this->settings->get_status();
		$settings = $this->settings->all();
		$notice   = $this->get_notice();
		$queue_status = $this->cron->queue_status();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-status.php';
	}

	/**
	 * Renders page tabs.
	 */
	public function render_tabs( string $active ): void {
		$tabs = array(
			'settings' => array( 'label' => __( 'Settings', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync' ),
			'markups'  => array( 'label' => __( 'Category Markups', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-markups' ),
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
