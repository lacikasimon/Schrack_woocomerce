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
	 * Whether product category CSV tools have already been rendered this request.
	 *
	 * @var bool
	 */
	private bool $category_csv_tools_rendered = false;

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
		add_action( 'admin_post_schrack_wc_sync_export_markups', array( $this, 'export_markups' ) );
		add_action( 'admin_post_schrack_wc_sync_import_markups', array( $this, 'import_markups' ) );
		add_action( 'admin_post_schrack_wc_sync_export_categories', array( $this, 'export_categories' ) );
		add_action( 'admin_post_schrack_wc_sync_import_categories', array( $this, 'import_categories' ) );
		add_action( 'admin_post_schrack_wc_sync_save_b2b_customers', array( $this, 'save_b2b_customers' ) );
		add_action( 'admin_post_schrack_wc_sync_soap_debug', array( $this, 'soap_debug' ) );
		add_action( 'admin_post_schrack_wc_sync_debug_fetch', array( $this, 'debug_fetch' ) );
		add_action( 'admin_post_schrack_wc_sync_debug_download', array( $this, 'debug_download' ) );
		add_action( 'admin_post_schrack_wc_sync_debug_reset', array( $this, 'debug_reset' ) );
		add_action( 'admin_post_schrack_wc_sync_manual_sync', array( $this, 'manual_sync' ) );
		add_action( 'admin_post_schrack_wc_sync_stop_syncs', array( $this, 'stop_syncs' ) );
		add_action( 'admin_post_schrack_wc_sync_sku_action', array( $this, 'sku_action' ) );
		add_action( 'admin_post_schrack_wc_sync_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'show_user_profile', array( $this, 'render_user_b2b_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_b2b_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_b2b_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_b2b_fields' ) );
		add_action( 'product_cat_pre_add_form', array( $this, 'render_category_csv_tools' ), 10, 0 );
		add_action( 'after-product_cat-table', array( $this, 'render_category_csv_tools' ), 10, 0 );
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
			__( 'Clienti B2B', 'schrack-woocommerce-sync' ),
			__( 'Clienti B2B', 'schrack-woocommerce-sync' ),
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

		add_submenu_page(
			'woocommerce',
			__( 'Schrack Debug', 'schrack-woocommerce-sync' ),
			__( 'Schrack Debug', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			'schrack-sync-debug',
			array( $this, 'render_debug_page' )
		);
	}

	/**
	 * Enqueues admin assets.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screen                   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product_category_page = $this->is_product_category_admin_screen( $screen );

		if ( ! str_contains( $hook_suffix, 'schrack-sync' ) && ! $is_product_category_page ) {
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
	 * Exports all WooCommerce category markup rows as CSV.
	 */
	public function export_markups(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_markups_csv' );

		$tree         = $this->product_category_tree();
		$rules        = $this->markups->all();
		$default_rule = array( 'markup' => '', 'min_margin' => '', 'rounding' => 'none' );
		$filename     = 'schrack-category-markups-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Could not create CSV export.', 'schrack-woocommerce-sync' ) );
		}

		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array( 'term_id', 'slug', 'path', 'name', 'markup', 'min_margin', 'rounding' ), ',', '"', '\\' );

		foreach ( $tree['terms'] as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$term_id = (int) $term->term_id;
			$rule    = isset( $rules[ $term_id ] ) && is_array( $rules[ $term_id ] )
				? wp_parse_args( $rules[ $term_id ], $default_rule )
				: $default_rule;

			fputcsv(
				$output,
				array(
					$term_id,
					$term->slug,
					(string) ( $tree['paths'][ $term_id ] ?? $term->name ),
					$term->name,
					(string) $rule['markup'],
					(string) $rule['min_margin'],
					(string) $rule['rounding'],
				),
				',',
				'"',
				'\\'
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Imports category markup rows from CSV.
	 */
	public function import_markups(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_markups_csv' );

		$file = isset( $_FILES['schrack_markups_csv'] ) && is_array( $_FILES['schrack_markups_csv'] )
			? $_FILES['schrack_markups_csv']
			: array();

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			$this->set_notice( 'error', $this->markup_csv_upload_error_message( $error ) );
			$this->redirect( 'schrack-sync-markups' );
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			$this->set_notice( 'error', __( 'CSV upload could not be read.', 'schrack-woocommerce-sync' ) );
			$this->redirect( 'schrack-sync-markups' );
		}

		$handle = fopen( $tmp_name, 'r' );

		if ( false === $handle ) {
			$this->set_notice( 'error', __( 'CSV upload could not be opened.', 'schrack-woocommerce-sync' ) );
			$this->redirect( 'schrack-sync-markups' );
		}

		$first_line = fgets( $handle );

		if ( false === $first_line ) {
			fclose( $handle );
			$this->set_notice( 'error', __( 'CSV file is empty.', 'schrack-woocommerce-sync' ) );
			$this->redirect( 'schrack-sync-markups' );
		}

		$delimiter  = $this->detect_markup_csv_delimiter( $first_line );
		$headers    = str_getcsv( $this->strip_utf8_bom( $first_line ), $delimiter, '"', '\\' );
		$header_map = $this->markup_csv_header_map( $headers );

		if ( empty( array_intersect( array_keys( $header_map ), array( 'term_id', 'slug', 'path' ) ) ) ) {
			fclose( $handle );
			$this->set_notice( 'error', __( 'CSV must include term_id, slug, or path column.', 'schrack-woocommerce-sync' ) );
			$this->redirect( 'schrack-sync-markups' );
		}

		if ( empty( array_intersect( array_keys( $header_map ), array( 'markup', 'min_margin', 'rounding' ) ) ) ) {
			fclose( $handle );
			$this->set_notice( 'error', __( 'CSV must include markup, min_margin, or rounding column.', 'schrack-woocommerce-sync' ) );
			$this->redirect( 'schrack-sync-markups' );
		}

		$tree             = $this->product_category_tree();
		$lookup           = $this->product_category_lookup( $tree['terms'], $tree['paths'] );
		$merged           = $this->markups->all();
		$updated_term_ids = array();
		$imported_rows    = 0;
		$skipped_rows     = 0;
		$warnings         = array();
		$line_number      = 1;

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			$line_number++;

			if ( $this->is_empty_csv_row( $row ) ) {
				continue;
			}

			$term_id = $this->resolve_markup_csv_term_id( $row, $header_map, $lookup );

			if ( $term_id <= 0 ) {
				$skipped_rows++;

				if ( count( $warnings ) < 10 ) {
					$warnings[] = sprintf(
						/* translators: %d: CSV line number. */
						__( 'Line %d skipped: category was not found.', 'schrack-woocommerce-sync' ),
						$line_number
					);
				}

				continue;
			}

			$rule = isset( $merged[ $term_id ] ) && is_array( $merged[ $term_id ] )
				? $merged[ $term_id ]
				: array( 'markup' => '', 'min_margin' => '', 'rounding' => 'none' );

			if ( isset( $header_map['markup'] ) ) {
				$rule['markup'] = $this->markup_csv_cell( $row, $header_map, 'markup' );
			}

			if ( isset( $header_map['min_margin'] ) ) {
				$rule['min_margin'] = $this->markup_csv_cell( $row, $header_map, 'min_margin' );
			}

			if ( isset( $header_map['rounding'] ) ) {
				$rule['rounding'] = $this->normalize_markup_csv_rounding( $this->markup_csv_cell( $row, $header_map, 'rounding' ) );
			}

			$merged[ $term_id ]           = $rule;
			$updated_term_ids[ $term_id ] = true;
			$imported_rows++;
		}

		fclose( $handle );

		if ( 0 === $imported_rows ) {
			$this->set_notice(
				empty( $warnings ) ? 'warning' : 'error',
				__( 'No category markup rows were imported.', 'schrack-woocommerce-sync' ),
				$warnings
			);
			$this->redirect( 'schrack-sync-markups' );
		}

		$this->markups->update( $merged );
		$this->logger->info(
			'admin',
			'Schrack category markup CSV was imported.',
			'',
			array(
				'imported_rows'      => $imported_rows,
				'updated_categories' => count( $updated_term_ids ),
				'skipped_rows'       => $skipped_rows,
			)
		);

		$message = sprintf(
			/* translators: 1: imported CSV rows, 2: updated categories, 3: skipped rows. */
			__( 'CSV import finished. Imported %1$d row(s), updated %2$d categories, skipped %3$d row(s).', 'schrack-woocommerce-sync' ),
			$imported_rows,
			count( $updated_term_ids ),
			$skipped_rows
		);

		if ( count( $warnings ) >= 10 && $skipped_rows > count( $warnings ) ) {
			$warnings[] = sprintf(
				/* translators: %d: omitted warning count. */
				__( '%d additional skipped rows are not shown.', 'schrack-woocommerce-sync' ),
				$skipped_rows - count( $warnings )
			);
		}

		$this->set_notice( $skipped_rows > 0 ? 'warning' : 'success', $message, $warnings );
		$this->redirect( 'schrack-sync-markups' );
	}

	/**
	 * Renders CSV tools on the WooCommerce product category admin screen.
	 */
	public function render_category_csv_tools(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $this->category_csv_tools_rendered || ! $this->is_product_category_admin_screen( $screen ) || ! $this->can_manage_product_categories() ) {
			return;
		}

		$this->category_csv_tools_rendered = true;

		$notice = $this->get_notice();
		?>
		<div class="schrack-sync-admin schrack-category-csv-admin">
			<?php $this->render_notice( $notice ); ?>

			<section class="schrack-panel schrack-markups-csv schrack-category-csv-tools">
				<div class="schrack-panel-header">
					<div class="schrack-markups-csv__intro">
						<h2><?php esc_html_e( 'Category CSV import / export', 'schrack-woocommerce-sync' ); ?></h2>
						<p><?php esc_html_e( 'Export WooCommerce product categories, edit the CSV, then import it back. New hierarchy can be created from the path column.', 'schrack-woocommerce-sync' ); ?></p>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="schrack_wc_sync_export_categories">
						<?php wp_nonce_field( 'schrack_wc_sync_categories_csv' ); ?>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Export categories CSV', 'schrack-woocommerce-sync' ); ?></button>
					</form>
				</div>

				<form class="schrack-markups-csv__import" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="schrack_wc_sync_import_categories">
					<?php wp_nonce_field( 'schrack_wc_sync_categories_csv' ); ?>
					<label for="schrack_categories_csv"><?php esc_html_e( 'CSV file', 'schrack-woocommerce-sync' ); ?></label>
					<input id="schrack_categories_csv" type="file" name="schrack_categories_csv" accept=".csv,text/csv">
					<button type="submit" class="button"><?php esc_html_e( 'Import categories CSV', 'schrack-woocommerce-sync' ); ?></button>
				</form>

				<p class="description">
					<?php esc_html_e( 'Supported columns:', 'schrack-woocommerce-sync' ); ?>
					<code>term_id</code>, <code>parent_id</code>, <code>parent_slug</code>, <code>parent_path</code>, <code>path</code>, <code>name</code>, <code>slug</code>, <code>description</code>, <code>display_type</code>, <code>image_id</code>, <code>image_url</code>, <code>menu_order</code>.
				</p>
			</section>
		</div>
		<?php
	}

	/**
	 * Exports WooCommerce product categories as CSV.
	 */
	public function export_categories(): void {
		$this->assert_can_manage_product_categories();
		check_admin_referer( 'schrack_wc_sync_categories_csv' );

		$tree     = $this->product_category_tree();
		$filename = 'schrack-product-categories-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Could not create CSV export.', 'schrack-woocommerce-sync' ) );
		}

		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv(
			$output,
			array( 'term_id', 'parent_id', 'parent_slug', 'parent_path', 'path', 'name', 'slug', 'description', 'display_type', 'image_id', 'image_url', 'menu_order', 'count' ),
			',',
			'"',
			'\\'
		);

		foreach ( $tree['terms'] as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$term_id      = (int) $term->term_id;
			$parent_id    = absint( $term->parent );
			$parent_term  = $parent_id > 0 ? get_term( $parent_id, 'product_cat' ) : null;
			$image_id     = absint( get_term_meta( $term_id, 'thumbnail_id', true ) );
			$image_url    = $image_id > 0 ? wp_get_attachment_url( $image_id ) : '';
			$display_type = (string) get_term_meta( $term_id, 'display_type', true );

			fputcsv(
				$output,
				array(
					$term_id,
					$parent_id > 0 ? $parent_id : '',
					$parent_term instanceof WP_Term ? $parent_term->slug : '',
					$parent_id > 0 ? (string) ( $tree['paths'][ $parent_id ] ?? '' ) : '',
					(string) ( $tree['paths'][ $term_id ] ?? $term->name ),
					$term->name,
					$term->slug,
					$term->description,
					'' === $display_type ? 'default' : $display_type,
					$image_id > 0 ? $image_id : '',
					is_string( $image_url ) ? $image_url : '',
					(string) get_term_meta( $term_id, 'order', true ),
					(int) $term->count,
				),
				',',
				'"',
				'\\'
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Imports WooCommerce product categories from CSV.
	 */
	public function import_categories(): void {
		$this->assert_can_manage_product_categories();
		check_admin_referer( 'schrack_wc_sync_categories_csv' );

		$file = isset( $_FILES['schrack_categories_csv'] ) && is_array( $_FILES['schrack_categories_csv'] )
			? $_FILES['schrack_categories_csv']
			: array();

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			$this->set_notice( 'error', $this->markup_csv_upload_error_message( $error ) );
			$this->redirect_categories_page();
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			$this->set_notice( 'error', __( 'CSV upload could not be read.', 'schrack-woocommerce-sync' ) );
			$this->redirect_categories_page();
		}

		$handle = fopen( $tmp_name, 'r' );

		if ( false === $handle ) {
			$this->set_notice( 'error', __( 'CSV upload could not be opened.', 'schrack-woocommerce-sync' ) );
			$this->redirect_categories_page();
		}

		$first_line = fgets( $handle );

		if ( false === $first_line ) {
			fclose( $handle );
			$this->set_notice( 'error', __( 'CSV file is empty.', 'schrack-woocommerce-sync' ) );
			$this->redirect_categories_page();
		}

		$delimiter  = $this->detect_markup_csv_delimiter( $first_line );
		$headers    = str_getcsv( $this->strip_utf8_bom( $first_line ), $delimiter, '"', '\\' );
		$header_map = $this->category_csv_header_map( $headers );

		if ( empty( array_intersect( array_keys( $header_map ), array( 'term_id', 'slug', 'path', 'name' ) ) ) ) {
			fclose( $handle );
			$this->set_notice( 'error', __( 'CSV must include term_id, slug, path, or name column.', 'schrack-woocommerce-sync' ) );
			$this->redirect_categories_page();
		}

		$tree         = $this->product_category_tree();
		$lookup       = $this->product_category_lookup( $tree['terms'], $tree['paths'] );
		$created      = 0;
		$updated      = 0;
		$skipped      = 0;
		$warnings     = array();
		$line_number  = 1;

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			$line_number++;

			if ( $this->is_empty_csv_row( $row ) ) {
				continue;
			}

			$result = $this->import_category_csv_row( $row, $header_map, $lookup );

			if ( ! empty( $result['warning'] ) && count( $warnings ) < 10 ) {
				$warnings[] = sprintf(
					/* translators: 1: CSV line number, 2: warning message. */
					__( 'Line %1$d: %2$s', 'schrack-woocommerce-sync' ),
					$line_number,
					(string) $result['warning']
				);
			}

			if ( 'created' === (string) ( $result['status'] ?? '' ) ) {
				$created++;
			} elseif ( 'updated' === (string) ( $result['status'] ?? '' ) ) {
				$updated++;
			} else {
				$skipped++;
			}

			if ( ! empty( $result['term_id'] ) ) {
				$tree   = $this->product_category_tree();
				$lookup = $this->product_category_lookup( $tree['terms'], $tree['paths'] );
			}
		}

		fclose( $handle );

		if ( 0 === $created && 0 === $updated ) {
			$this->set_notice(
				empty( $warnings ) ? 'warning' : 'error',
				__( 'No product categories were imported.', 'schrack-woocommerce-sync' ),
				$warnings
			);
			$this->redirect_categories_page();
		}

		$this->logger->info(
			'admin',
			'Schrack product category CSV was imported.',
			'',
			array(
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
			)
		);

		$message = sprintf(
			/* translators: 1: created categories, 2: updated categories, 3: skipped rows. */
			__( 'Category CSV import finished. Created %1$d, updated %2$d, skipped %3$d row(s).', 'schrack-woocommerce-sync' ),
			$created,
			$updated,
			$skipped
		);

		if ( count( $warnings ) >= 10 && $skipped > count( $warnings ) ) {
			$warnings[] = sprintf(
				/* translators: %d: omitted warning count. */
				__( '%d additional warnings are not shown.', 'schrack-woocommerce-sync' ),
				$skipped - count( $warnings )
			);
		}

		$this->set_notice( ! empty( $warnings ) || $skipped > 0 ? 'warning' : 'success', $message, $warnings );
		$this->redirect_categories_page();
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
		$warnings = array();

		foreach ( $input as $user_id => $row ) {
			$user_id = absint( $user_id );

			if ( $user_id <= 0 || ! is_array( $row ) || ! current_user_can( 'edit_user', $user_id ) ) {
				continue;
			}

			$warning = $this->save_b2b_user_meta( $user_id, $row );

			if ( null !== $warning ) {
				$warnings[] = $warning;
			}

			$updated++;
		}

		$this->logger->info( 'admin', 'Clienti B2B were updated.', '', array( 'updated' => $updated ) );
		$message = sprintf(
			/* translators: %d: updated customers. */
			__( '%d clienti B2B salvati.', 'schrack-woocommerce-sync' ),
			$updated
		);

		if ( ! empty( $warnings ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: warning count. */
				__( '%d campuri nu au putut fi actualizate.', 'schrack-woocommerce-sync' ),
				count( $warnings )
			);
		}

		$this->set_notice( empty( $warnings ) ? 'success' : 'warning', $message, empty( $warnings ) ? array() : $warnings );
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
	 * Queues a raw feed debug export in the background, so a large row count
	 * can never time out the request (fetch + parse can take a while).
	 */
	public function debug_fetch(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_debug_fetch' );

		$source = isset( $_POST['debug_source'] ) ? sanitize_key( wp_unslash( (string) $_POST['debug_source'] ) ) : 'schrack_csv';
		$limit  = isset( $_POST['debug_limit'] ) ? max( 1, min( 5000, absint( $_POST['debug_limit'] ) ) ) : 10;

		$queued = $this->cron->queue_debug_export( $source, $limit );

		if ( ! empty( $queued['queued'] ) ) {
			$this->set_notice( 'success', __( 'Debug export queued. This page will refresh automatically while it runs.', 'schrack-woocommerce-sync' ) );
		} else {
			$this->set_notice( 'error', __( 'Could not queue the debug export. Please check Action Scheduler/WP-Cron.', 'schrack-woocommerce-sync' ) );
		}

		$this->redirect( 'schrack-sync-debug' );
	}

	/**
	 * Streams a completed debug export file for download.
	 */
	public function debug_download(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_debug_download' );

		$status = $this->settings->get_status();
		$export = isset( $status['debug_export'] ) && is_array( $status['debug_export'] ) ? $status['debug_export'] : array();
		$path   = isset( $export['file'] ) ? (string) $export['file'] : '';
		$upload = wp_upload_dir( null, false );
		$dir    = ! empty( $upload['basedir'] ) ? trailingslashit( (string) $upload['basedir'] ) . 'schrack-wc-sync/debug' : '';

		if (
			'' === $path ||
			'' === $dir ||
			! is_file( $path ) ||
			0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $dir ) )
		) {
			wp_die( esc_html__( 'The debug export file was not found. Run a new export first.', 'schrack-woocommerce-sync' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Clears a stuck or finished debug export status so a new one can be queued.
	 */
	public function debug_reset(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_debug_reset' );

		$this->cron->reset_debug_export();
		$this->set_notice( 'success', __( 'Debug export status cleared.', 'schrack-woocommerce-sync' ) );

		$this->redirect( 'schrack-sync-debug' );
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
		} elseif ( in_array( (string) ( $result['code'] ?? '' ), array( 'active_sync', 'image_import_disabled', 'schrack_disabled', 'telesystem_disabled' ), true ) ) {
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
		<h2><?php esc_html_e( 'Clienti B2B', 'schrack-woocommerce-sync' ); ?></h2>
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
	private function save_b2b_user_meta( int $user_id, array $input ): ?string {
		$account_type     = isset( $input['account_type'] ) && 'b2b' === sanitize_key( (string) $input['account_type'] ) ? 'b2b' : 'customer';
		$status           = $this->sanitize_admin_choice( $input['status'] ?? 'pending', array( 'pending', 'approved', 'rejected', 'disabled' ), 'pending' );
		$has_first_name   = array_key_exists( 'first_name', $input );
		$has_last_name    = array_key_exists( 'last_name', $input );
		$has_display_name = array_key_exists( 'display_name', $input );
		$first_name       = isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '';
		$last_name        = isset( $input['last_name'] ) ? sanitize_text_field( (string) $input['last_name'] ) : '';
		$display_name     = isset( $input['display_name'] ) ? sanitize_text_field( (string) $input['display_name'] ) : '';
		$email            = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		$registered       = isset( $input['registered'] ) ? $this->sanitize_admin_datetime( $input['registered'] ) : '';
		$company          = isset( $input['company'] ) ? sanitize_text_field( (string) $input['company'] ) : '';
		$cui              = isset( $input['cui'] ) ? sanitize_text_field( (string) $input['cui'] ) : '';
		$reg_number       = isset( $input['registration_number'] ) ? sanitize_text_field( (string) $input['registration_number'] ) : '';
		$discount         = $this->sanitize_b2b_discount_percent( $input['discount_percent'] ?? 0 );
		$phone            = isset( $input['billing_phone'] ) ? sanitize_text_field( (string) $input['billing_phone'] ) : '';
		$address          = isset( $input['billing_address_1'] ) ? sanitize_text_field( (string) $input['billing_address_1'] ) : '';
		$city             = isset( $input['billing_city'] ) ? sanitize_text_field( (string) $input['billing_city'] ) : '';
		$county           = isset( $input['billing_state'] ) ? sanitize_text_field( (string) $input['billing_state'] ) : '';
		$postcode         = isset( $input['billing_postcode'] ) ? sanitize_text_field( (string) $input['billing_postcode'] ) : '';
		$requested_at     = array_key_exists( 'requested_at', $input ) ? $this->sanitize_admin_datetime( $input['requested_at'] ) : null;
		$approved_at      = array_key_exists( 'approved_at', $input ) ? $this->sanitize_admin_datetime( $input['approved_at'] ) : null;
		$warning          = null;

		$user_data = array( 'ID' => $user_id );

		if ( $has_first_name ) {
			$user_data['first_name'] = $first_name;
		}

		if ( $has_last_name ) {
			$user_data['last_name'] = $last_name;
		}

		if ( $has_display_name && '' === $display_name ) {
			$display_name = trim( $first_name . ' ' . $last_name );
		}

		if ( $has_display_name && '' !== $display_name ) {
			$user_data['display_name'] = $display_name;
		}

		if ( '' !== $registered ) {
			$user_data['user_registered'] = $registered;
		}

		if ( '' !== $email && is_email( $email ) ) {
			$email_owner = email_exists( $email );

			if ( false === $email_owner || (int) $email_owner === $user_id ) {
				$user_data['user_email'] = $email;
			} else {
				$warning = sprintf(
					/* translators: 1: user id, 2: email address. */
					__( 'User #%1$d: emailul %2$s este deja folosit de alt cont.', 'schrack-woocommerce-sync' ),
					$user_id,
					$email
				);
			}
		} elseif ( array_key_exists( 'email', $input ) ) {
			$warning = sprintf(
				/* translators: %d: user id. */
				__( 'User #%d: email invalid, restul datelor au fost salvate.', 'schrack-woocommerce-sync' ),
				$user_id
			);
		}

		if ( count( $user_data ) > 1 ) {
			$user_result = wp_update_user( $user_data );

			if ( is_wp_error( $user_result ) ) {
				$warning = sprintf(
					/* translators: 1: user id, 2: error message. */
					__( 'User #%1$d: datele userului nu au putut fi actualizate: %2$s', 'schrack-woocommerce-sync' ),
					$user_id,
					$user_result->get_error_message()
				);
			}
		}

		update_user_meta( $user_id, '_schrack_account_type', $account_type );
		update_user_meta( $user_id, '_schrack_b2b_status', $status );
		update_user_meta( $user_id, '_schrack_b2b_company_name', $company );
		update_user_meta( $user_id, '_schrack_b2b_cui', $cui );
		update_user_meta( $user_id, '_schrack_b2b_registration_number', $reg_number );
		update_user_meta( $user_id, '_schrack_b2b_discount_percent', $this->format_percent_value( $discount ) );

		if ( null !== $requested_at ) {
			update_user_meta( $user_id, '_schrack_b2b_requested_at', $requested_at );
		}

		if ( null !== $approved_at ) {
			update_user_meta( $user_id, '_schrack_b2b_approved_at', $approved_at );
		}

		if ( 'b2b' === $account_type && null === $requested_at && '' === (string) get_user_meta( $user_id, '_schrack_b2b_requested_at', true ) ) {
			update_user_meta( $user_id, '_schrack_b2b_requested_at', current_time( 'mysql' ) );
		}

		if ( 'b2b' === $account_type && 'approved' === $status && null === $approved_at && '' === (string) get_user_meta( $user_id, '_schrack_b2b_approved_at', true ) ) {
			update_user_meta( $user_id, '_schrack_b2b_approved_at', current_time( 'mysql' ) );
		}

		if ( $has_first_name ) {
			update_user_meta( $user_id, 'billing_first_name', $first_name );
		}

		if ( $has_last_name ) {
			update_user_meta( $user_id, 'billing_last_name', $last_name );
		}

		update_user_meta( $user_id, 'billing_company', $company );
		update_user_meta( $user_id, 'billing_vat_number', $cui );
		if ( array_key_exists( 'billing_phone', $input ) ) {
			update_user_meta( $user_id, 'billing_phone', $phone );
		}

		if ( array_key_exists( 'billing_address_1', $input ) ) {
			update_user_meta( $user_id, 'billing_address_1', $address );
		}

		if ( array_key_exists( 'billing_city', $input ) ) {
			update_user_meta( $user_id, 'billing_city', $city );
		}

		if ( array_key_exists( 'billing_state', $input ) ) {
			update_user_meta( $user_id, 'billing_state', $county );
		}

		if ( array_key_exists( 'billing_postcode', $input ) ) {
			update_user_meta( $user_id, 'billing_postcode', $postcode );
		}

		update_user_meta( $user_id, 'billing_country', 'RO' );

		if ( '' !== $email && is_email( $email ) ) {
			update_user_meta( $user_id, 'billing_email', $email );
		}

		return $warning;
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
	 * Sanitizes admin-editable datetime values.
	 */
	private function sanitize_admin_datetime( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( str_replace( 'T', ' ', sanitize_text_field( (string) $value ) ) ) : '';

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/', $value ) ) {
			return 16 === strlen( $value ) ? $value . ':00' : $value;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
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
	 * Renders the raw feed debug page.
	 */
	public function render_debug_page(): void {
		$this->assert_can_manage();

		$notice        = $this->get_notice();
		$status        = $this->settings->get_status();
		$debug_export  = isset( $status['debug_export'] ) && is_array( $status['debug_export'] ) ? $status['debug_export'] : array();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-debug.php';
	}

	/**
	 * Returns sync coverage counters for the admin dashboard.
	 *
	 * @return array<string,mixed>
	 */
	private function sync_dashboard_stats(): array {
		$sources = array(
			'schrack'    => $this->sync_dashboard_source_stats(
				'schrack',
				__( 'Schrack', 'schrack-woocommerce-sync' ),
				'_schrack_item_number',
				'_schrack_last_price_sync',
				'_schrack_last_stock_sync'
			),
			'telesystem' => $this->sync_dashboard_source_stats(
				'telesystem',
				__( 'Telesystem', 'schrack-woocommerce-sync' ),
				'_telesystem_item_number',
				'_telesystem_last_price_sync',
				'_telesystem_last_stock_sync'
			),
		);

		$totals                  = $this->sync_dashboard_total_stats( $sources );
		$totals['calculated_at'] = current_time( 'mysql' );
		$totals['sources']       = $sources;

		return $totals;
	}

	/**
	 * Returns sync coverage counters for one catalog source.
	 *
	 * @param string $source Catalog source key.
	 * @param string $label Human readable source label.
	 * @param string $item_meta_key Product item-number meta key for the source.
	 * @param string $last_price_meta_key Last price sync meta key for the source.
	 * @param string $last_stock_meta_key Last stock sync meta key for the source.
	 * @return array<string,mixed>
	 */
	private function sync_dashboard_source_stats( string $source, string $label, string $item_meta_key, string $last_price_meta_key, string $last_stock_meta_key ): array {
		global $wpdb;

		$source_condition = 'schrack' === $source
			? "( source_meta.meta_id IS NULL OR source_meta.meta_value = '' OR source_meta.meta_value = %s )"
			: 'source_meta.meta_value = %s';

		$sql = $wpdb->prepare(
			"
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
						MAX(CASE WHEN product_meta.meta_key = %s THEN product_meta.meta_value ELSE '' END) AS last_price_sync,
						MAX(CASE WHEN product_meta.meta_key = %s THEN product_meta.meta_value ELSE '' END) AS last_stock_sync
					FROM {$wpdb->posts} AS products
					INNER JOIN {$wpdb->postmeta} AS item_meta
						ON item_meta.post_id = products.ID
						AND item_meta.meta_key = %s
						AND item_meta.meta_value <> ''
					LEFT JOIN {$wpdb->postmeta} AS source_meta
						ON source_meta.post_id = products.ID
						AND source_meta.meta_key = '_schrack_catalog_source'
					LEFT JOIN {$wpdb->postmeta} AS product_meta
						ON product_meta.post_id = products.ID
						AND product_meta.meta_key IN (
							'_schrack_image_url',
							'_schrack_imported_image_url',
							'_thumbnail_id',
							'_schrack_image_attachment_id',
							%s,
							%s
						)
					WHERE products.post_type = 'product'
						AND products.post_status IN ('publish', 'draft', 'private')
						AND {$source_condition}
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
			",
			$last_price_meta_key,
			$last_stock_meta_key,
			$item_meta_key,
			$last_price_meta_key,
			$last_stock_meta_key,
			$source
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );
		$row = is_array( $row ) ? $row : array();

		$imported_products       = absint( $row['imported_products'] ?? 0 );
		$image_url_products      = absint( $row['image_url_products'] ?? 0 );
		$image_synced_products   = absint( $row['image_synced_products'] ?? 0 );
		$image_url_only_products = absint( $row['image_url_only_products'] ?? 0 );
		$price_synced_products   = absint( $row['price_synced_products'] ?? 0 );
		$stock_synced_products   = absint( $row['stock_synced_products'] ?? 0 );

		return array(
			'source'                  => $source,
			'label'                   => $label,
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
			'query_error'             => (string) $wpdb->last_error,
		);
	}

	/**
	 * Combines source counters into the legacy dashboard summary keys.
	 *
	 * @param array<string,array<string,mixed>> $sources Source counters.
	 * @return array<string,mixed>
	 */
	private function sync_dashboard_total_stats( array $sources ): array {
		$imported_products       = 0;
		$image_url_products      = 0;
		$image_synced_products   = 0;
		$image_url_only_products = 0;
		$price_synced_products   = 0;
		$stock_synced_products   = 0;
		$query_errors            = array();

		foreach ( $sources as $source_stats ) {
			$imported_products       += absint( $source_stats['imported_products'] ?? 0 );
			$image_url_products      += absint( $source_stats['image_url_products'] ?? 0 );
			$image_synced_products   += absint( $source_stats['image_synced_products'] ?? 0 );
			$image_url_only_products += absint( $source_stats['image_url_only_products'] ?? 0 );
			$price_synced_products   += absint( $source_stats['price_synced_products'] ?? 0 );
			$stock_synced_products   += absint( $source_stats['stock_synced_products'] ?? 0 );

			if ( '' !== (string) ( $source_stats['query_error'] ?? '' ) ) {
				$query_errors[] = (string) $source_stats['query_error'];
			}
		}

		return array(
			'imported_products'          => $imported_products,
			'image_url_products'         => $image_url_products,
			'image_synced_products'      => $image_synced_products,
			'image_url_only_products'    => $image_url_only_products,
			'image_missing_url_products' => max( 0, $imported_products - $image_url_products ),
			'price_synced_products'      => $price_synced_products,
			'stock_synced_products'      => $stock_synced_products,
			'image_synced_pct'           => $this->sync_dashboard_percentage( $image_synced_products, $imported_products ),
			'image_url_only_pct'         => $this->sync_dashboard_percentage( $image_url_only_products, $imported_products ),
			'price_synced_pct'           => $this->sync_dashboard_percentage( $price_synced_products, $imported_products ),
			'stock_synced_pct'           => $this->sync_dashboard_percentage( $stock_synced_products, $imported_products ),
			'query_error'                => implode( ' ', array_unique( $query_errors ) ),
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
			$first_name   = sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) );
			$last_name    = sanitize_text_field( (string) get_user_meta( $user_id, 'last_name', true ) );
			$company      = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_company_name', true ) );
			$cui          = sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_cui', true ) );
			$phone        = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_phone', true ) );
			$address      = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_address_1', true ) );
			$city         = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_city', true ) );
			$county       = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_state', true ) );
			$postcode     = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_postcode', true ) );

			if ( '' === $first_name ) {
				$first_name = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_first_name', true ) );
			}

			if ( '' === $last_name ) {
				$last_name = sanitize_text_field( (string) get_user_meta( $user_id, 'billing_last_name', true ) );
			}

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
				'first_name'          => $first_name,
				'last_name'           => $last_name,
				'display_name'        => $user->display_name,
				'email'               => $user->user_email,
				'registered'          => $user->user_registered,
				'account_type'        => $account_type,
				'status'              => $status,
				'status_label'        => $this->b2b_status_label( $status ),
				'company'             => $company,
				'cui'                 => $cui,
				'billing_phone'       => $phone,
				'billing_address_1'   => $address,
				'billing_city'        => $city,
				'billing_state'       => $county,
				'billing_postcode'    => $postcode,
				'registration_number' => sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_registration_number', true ) ),
				'requested_at'        => sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_requested_at', true ) ),
				'approved_at'         => sanitize_text_field( (string) get_user_meta( $user_id, '_schrack_b2b_approved_at', true ) ),
				'discount'            => $discount,
				'discount_display'    => $this->format_percent_value( $discount ),
				'customer_url'        => $this->b2b_customer_admin_url( $user_id ),
				'user_url'            => get_edit_user_link( $user_id ),
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
	 * Returns the WooCommerce customer admin URL for a user.
	 */
	private function b2b_customer_admin_url( int $user_id ): string {
		return add_query_arg(
			array(
				'page'      => 'wc-admin',
				'path'      => '/customers',
				'filter'    => 'single_customer',
				'customers' => $user_id,
			),
			admin_url( 'admin.php' )
		);
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
			'b2b'      => array( 'label' => __( 'Clienti B2B', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-b2b' ),
			'manual'   => array( 'label' => __( 'Manual Sync', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-manual' ),
			'logs'     => array( 'label' => __( 'Logs', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-logs' ),
			'status'   => array( 'label' => __( 'Status', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-status' ),
			'debug'    => array( 'label' => __( 'Debug', 'schrack-woocommerce-sync' ), 'slug' => 'schrack-sync-debug' ),
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
	 * Returns WooCommerce product categories in tree order with display paths.
	 *
	 * @return array{terms:array<int,WP_Term>,paths:array<int,string>}
	 */
	private function product_category_tree(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array( 'terms' => array(), 'paths' => array() );
		}

		$terms_by_id     = array();
		$terms_by_parent = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$term_id                         = (int) $term->term_id;
			$parent_id                       = absint( $term->parent );
			$terms_by_id[ $term_id ]         = $term;
			$terms_by_parent[ $parent_id ]   = $terms_by_parent[ $parent_id ] ?? array();
			$terms_by_parent[ $parent_id ][] = $term;
		}

		foreach ( $terms_by_parent as $parent_id => $children ) {
			usort(
				$children,
				static function ( WP_Term $left, WP_Term $right ): int {
					return strnatcasecmp( $left->name, $right->name );
				}
			);
			$terms_by_parent[ $parent_id ] = $children;
		}

		$paths   = array();
		$ordered = array();
		$append  = static function ( int $parent_id, string $parent_path ) use ( &$append, &$ordered, &$paths, $terms_by_parent ): void {
			foreach ( $terms_by_parent[ $parent_id ] ?? array() as $term ) {
				$term_id           = (int) $term->term_id;
				$path              = '' === $parent_path ? $term->name : $parent_path . ' > ' . $term->name;
				$paths[ $term_id ] = $path;
				$ordered[]         = $term;

				$append( $term_id, $path );
			}
		};

		$append( 0, '' );

		foreach ( $terms_by_id as $term_id => $term ) {
			if ( isset( $paths[ $term_id ] ) ) {
				continue;
			}

			$paths[ $term_id ] = $term->name;
			$ordered[]         = $term;
			$append( $term_id, $term->name );
		}

		return array( 'terms' => $ordered, 'paths' => $paths );
	}

	/**
	 * Builds lookup maps for CSV category matching.
	 *
	 * @param array<int,WP_Term> $terms Product category terms.
	 * @param array<int,string>  $paths Category paths by term ID.
	 * @return array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>}
	 */
	private function product_category_lookup( array $terms, array $paths ): array {
		$lookup = array(
			'ids'   => array(),
			'slugs' => array(),
			'paths' => array(),
		);

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$term_id                         = (int) $term->term_id;
			$lookup['ids'][ $term_id ]      = true;
			$lookup['slugs'][ $term->slug ] = $term_id;

			$path_key = $this->markup_category_path_key( (string) ( $paths[ $term_id ] ?? $term->name ) );

			if ( '' !== $path_key && ! isset( $lookup['paths'][ $path_key ] ) ) {
				$lookup['paths'][ $path_key ] = $term_id;
			}
		}

		return $lookup;
	}

	/**
	 * Maps category CSV header labels to importer fields.
	 *
	 * @param array<int,string> $headers CSV headers.
	 * @return array<string,int>
	 */
	private function category_csv_header_map( array $headers ): array {
		$aliases = array(
			'term_id'      => array( 'termid', 'id', 'categoryid', 'categorytermid' ),
			'parent_id'    => array( 'parentid', 'parenttermid', 'parentcategoryid' ),
			'parent_slug'  => array( 'parentslug', 'parentcategoryslug' ),
			'parent_path'  => array( 'parentpath', 'parentcategorypath' ),
			'path'         => array( 'path', 'categorypath', 'productcatpath', 'categoriepath' ),
			'name'         => array( 'name', 'categoryname', 'nume', 'nev' ),
			'slug'         => array( 'slug', 'categoryslug', 'productcatslug' ),
			'description'  => array( 'description', 'desc', 'categorydescription', 'descriere' ),
			'display_type' => array( 'displaytype', 'display', 'woodisplaytype' ),
			'image_id'     => array( 'imageid', 'thumbnailid', 'thumbnail_id', 'imageattachmentid' ),
			'image_url'    => array( 'imageurl', 'thumbnailurl', 'thumbnail_url' ),
			'menu_order'   => array( 'menuorder', 'order', 'sortorder', 'position' ),
		);
		$map     = array();

		foreach ( $headers as $index => $header ) {
			$key = $this->markup_csv_key( (string) $header );

			if ( '' === $key ) {
				continue;
			}

			foreach ( $aliases as $field => $field_aliases ) {
				if ( isset( $map[ $field ] ) || ! in_array( $key, $field_aliases, true ) ) {
					continue;
				}

				$map[ $field ] = (int) $index;
				break;
			}
		}

		return $map;
	}

	/**
	 * Imports one product category CSV row.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 * @param array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>} $lookup Category lookup.
	 * @return array{status:string,term_id?:int,warning?:string}
	 */
	private function import_category_csv_row( array $row, array $header_map, array $lookup ): array {
		$path       = $this->markup_csv_cell( $row, $header_map, 'path' );
		$path_parts = $this->product_category_path_parts( $path );
		$name       = $this->markup_csv_cell( $row, $header_map, 'name' );
		$slug       = sanitize_title( $this->markup_csv_cell( $row, $header_map, 'slug' ) );

		if ( '' === $name && ! empty( $path_parts ) ) {
			$name = (string) end( $path_parts );
		}

		if ( '' === $name && '' !== $slug ) {
			$name = ucwords( str_replace( '-', ' ', $slug ) );
		}

		$term_id = $this->resolve_category_csv_term_id( $row, $header_map, $lookup, $path_parts );

		if ( '' === $name && $term_id > 0 ) {
			$existing_term = get_term( $term_id, 'product_cat' );
			$name          = $existing_term instanceof WP_Term ? $existing_term->name : '';
		}

		if ( '' === $name ) {
			return array(
				'status'  => 'skipped',
				'warning' => __( 'missing category name or path.', 'schrack-woocommerce-sync' ),
			);
		}

		$parent_id = $this->category_csv_parent_id( $row, $header_map, $path_parts );
		$args      = array();

		if ( isset( $header_map['name'] ) || ! empty( $path_parts ) || 0 === $term_id ) {
			$args['name'] = $name;
		}

		if ( isset( $header_map['slug'] ) && '' !== $slug ) {
			$args['slug'] = $slug;
		}

		if ( isset( $header_map['description'] ) ) {
			$args['description'] = wp_kses_post( $this->markup_csv_cell( $row, $header_map, 'description' ) );
		}

		if ( $parent_id > 0 && $parent_id !== $term_id ) {
			$args['parent'] = $parent_id;
		} elseif ( isset( $header_map['parent_id'] ) || isset( $header_map['parent_slug'] ) || isset( $header_map['parent_path'] ) || count( $path_parts ) > 1 ) {
			$args['parent'] = 0;
		}

		if ( $term_id > 0 ) {
			$result = empty( $args ) ? array( 'term_id' => $term_id ) : wp_update_term( $term_id, 'product_cat', $args );

			if ( is_wp_error( $result ) ) {
				return array(
					'status'  => 'skipped',
					'warning' => $result->get_error_message(),
				);
			}

			$this->update_category_csv_meta( $term_id, $row, $header_map );

			return array(
				'status'  => 'updated',
				'term_id' => $term_id,
			);
		}

		$result = wp_insert_term( $name, 'product_cat', $args );

		if ( is_wp_error( $result ) ) {
			$existing_id = absint( $result->get_error_data( 'term_exists' ) );

			if ( $existing_id > 0 ) {
				$this->update_category_csv_meta( $existing_id, $row, $header_map );

				return array(
					'status'  => 'updated',
					'term_id' => $existing_id,
					'warning' => __( 'existing category was updated instead of created.', 'schrack-woocommerce-sync' ),
				);
			}

			return array(
				'status'  => 'skipped',
				'warning' => $result->get_error_message(),
			);
		}

		$created_id = absint( $result['term_id'] ?? 0 );
		$this->update_category_csv_meta( $created_id, $row, $header_map );

		return array(
			'status'  => 'created',
			'term_id' => $created_id,
		);
	}

	/**
	 * Resolves the target category for a CSV row.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 * @param array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>} $lookup Category lookup.
	 * @param array<int,string>      $path_parts Category path parts.
	 */
	private function resolve_category_csv_term_id( array $row, array $header_map, array $lookup, array $path_parts ): int {
		$term_id = absint( $this->markup_csv_cell( $row, $header_map, 'term_id' ) );

		if ( $term_id > 0 && isset( $lookup['ids'][ $term_id ] ) ) {
			return $term_id;
		}

		$slug = sanitize_title( $this->markup_csv_cell( $row, $header_map, 'slug' ) );

		if ( '' !== $slug ) {
			if ( isset( $lookup['slugs'][ $slug ] ) ) {
				return (int) $lookup['slugs'][ $slug ];
			}

			$term = get_term_by( 'slug', $slug, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				return (int) $term->term_id;
			}
		}

		if ( ! empty( $path_parts ) ) {
			$term_id = $this->find_product_category_path( $path_parts );

			if ( $term_id > 0 ) {
				return $term_id;
			}
		}

		$path_key = $this->markup_category_path_key( $this->markup_csv_cell( $row, $header_map, 'path' ) );

		return '' !== $path_key && isset( $lookup['paths'][ $path_key ] ) ? (int) $lookup['paths'][ $path_key ] : 0;
	}

	/**
	 * Resolves or creates the parent category for a CSV row.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 * @param array<int,string>      $path_parts Category path parts.
	 */
	private function category_csv_parent_id( array $row, array $header_map, array $path_parts ): int {
		if ( count( $path_parts ) > 1 ) {
			return $this->ensure_product_category_path( array_slice( $path_parts, 0, -1 ) );
		}

		$parent_path = $this->markup_csv_cell( $row, $header_map, 'parent_path' );

		if ( '' !== $parent_path ) {
			return $this->ensure_product_category_path( $this->product_category_path_parts( $parent_path ) );
		}

		$parent_id = absint( $this->markup_csv_cell( $row, $header_map, 'parent_id' ) );

		if ( $parent_id > 0 && get_term( $parent_id, 'product_cat' ) instanceof WP_Term ) {
			return $parent_id;
		}

		$parent_slug = sanitize_title( $this->markup_csv_cell( $row, $header_map, 'parent_slug' ) );

		if ( '' !== $parent_slug ) {
			$parent = get_term_by( 'slug', $parent_slug, 'product_cat' );

			if ( $parent instanceof WP_Term ) {
				return (int) $parent->term_id;
			}
		}

		return 0;
	}

	/**
	 * Finds a product category by hierarchical path.
	 *
	 * @param array<int,string> $parts Category path parts.
	 */
	private function find_product_category_path( array $parts ): int {
		$parent_id = 0;
		$term_id   = 0;

		foreach ( $parts as $part ) {
			$term = $this->product_category_term_by_name( $part, $parent_id );

			if ( ! $term instanceof WP_Term ) {
				return 0;
			}

			$term_id   = (int) $term->term_id;
			$parent_id = $term_id;
		}

		return $term_id;
	}

	/**
	 * Ensures a hierarchical product category path exists.
	 *
	 * @param array<int,string> $parts Category path parts.
	 */
	private function ensure_product_category_path( array $parts ): int {
		$parent_id = 0;
		$term_id   = 0;

		foreach ( $parts as $part ) {
			$name = sanitize_text_field( $part );

			if ( '' === $name ) {
				continue;
			}

			$term = $this->product_category_term_by_name( $name, $parent_id );

			if ( $term instanceof WP_Term ) {
				$term_id   = (int) $term->term_id;
				$parent_id = $term_id;
				continue;
			}

			$result = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_id ) );

			if ( is_wp_error( $result ) ) {
				$existing_id = absint( $result->get_error_data( 'term_exists' ) );

				if ( $existing_id <= 0 ) {
					return 0;
				}

				$term_id = $existing_id;
			} else {
				$term_id = absint( $result['term_id'] ?? 0 );
			}

			$parent_id = $term_id;
		}

		return $term_id;
	}

	/**
	 * Finds one product category by name and parent.
	 */
	private function product_category_term_by_name( string $name, int $parent_id ): ?WP_Term {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'name'       => $name,
				'parent'     => $parent_id,
				'number'     => 1,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) || ! $terms[0] instanceof WP_Term ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Splits a category path into clean parts.
	 *
	 * @return array<int,string>
	 */
	private function product_category_path_parts( string $path ): array {
		if ( '' === trim( $path ) ) {
			return array();
		}

		$parts = preg_split( '/\s*(?:>|\/|\|)\s*/', $path );

		return array_values(
			array_filter(
				array_map(
					static fn ( mixed $part ): string => sanitize_text_field( is_scalar( $part ) ? (string) $part : '' ),
					is_array( $parts ) ? $parts : array()
				),
				static fn ( string $part ): bool => '' !== $part
			)
		);
	}

	/**
	 * Updates WooCommerce product category meta from CSV fields.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 */
	private function update_category_csv_meta( int $term_id, array $row, array $header_map ): void {
		if ( $term_id <= 0 ) {
			return;
		}

		if ( isset( $header_map['display_type'] ) ) {
			$display_type = $this->normalize_category_display_type( $this->markup_csv_cell( $row, $header_map, 'display_type' ) );

			if ( '' === $display_type ) {
				delete_term_meta( $term_id, 'display_type' );
			} else {
				update_term_meta( $term_id, 'display_type', $display_type );
			}
		}

		if ( isset( $header_map['image_id'] ) || isset( $header_map['image_url'] ) ) {
			$image_id_value  = $this->markup_csv_cell( $row, $header_map, 'image_id' );
			$image_url_value = $this->markup_csv_cell( $row, $header_map, 'image_url' );
			$image_id        = absint( $image_id_value );

			if ( $image_id <= 0 ) {
				$image_id = '' !== $image_url_value && function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $image_url_value ) ) : 0;
			}

			if ( $image_id > 0 ) {
				update_term_meta( $term_id, 'thumbnail_id', $image_id );
			} elseif ( isset( $header_map['image_id'] ) && '' === $image_id_value && '' === $image_url_value ) {
				delete_term_meta( $term_id, 'thumbnail_id' );
			}
		}

		if ( isset( $header_map['menu_order'] ) ) {
			$order = $this->markup_csv_cell( $row, $header_map, 'menu_order' );

			if ( '' === $order ) {
				delete_term_meta( $term_id, 'order' );
			} else {
				update_term_meta( $term_id, 'order', max( 0, (int) $order ) );
			}
		}
	}

	/**
	 * Normalizes WooCommerce category display type.
	 */
	private function normalize_category_display_type( string $display_type ): string {
		$key = sanitize_key( $display_type );

		return in_array( $key, array( 'products', 'subcategories', 'both' ), true ) ? $key : '';
	}

	/**
	 * Returns a friendly CSV upload error.
	 */
	private function markup_csv_upload_error_message( int $error ): string {
		return match ( $error ) {
			UPLOAD_ERR_NO_FILE => __( 'Choose a CSV file before importing.', 'schrack-woocommerce-sync' ),
			UPLOAD_ERR_INI_SIZE,
			UPLOAD_ERR_FORM_SIZE => __( 'CSV file is larger than the allowed upload size.', 'schrack-woocommerce-sync' ),
			default => __( 'CSV upload failed.', 'schrack-woocommerce-sync' ),
		};
	}

	/**
	 * Detects common CSV delimiters from the header line.
	 */
	private function detect_markup_csv_delimiter( string $line ): string {
		$best_delimiter = ',';
		$best_columns   = 0;

		foreach ( array( ',', ';', "\t" ) as $delimiter ) {
			$columns = count( str_getcsv( $line, $delimiter, '"', '\\' ) );

			if ( $columns > $best_columns ) {
				$best_columns   = $columns;
				$best_delimiter = $delimiter;
			}
		}

		return $best_delimiter;
	}

	/**
	 * Removes UTF-8 BOM from a CSV header line.
	 */
	private function strip_utf8_bom( string $line ): string {
		return str_starts_with( $line, "\xEF\xBB\xBF" ) ? substr( $line, 3 ) : $line;
	}

	/**
	 * Maps CSV header labels to importer fields.
	 *
	 * @param array<int,string> $headers CSV headers.
	 * @return array<string,int>
	 */
	private function markup_csv_header_map( array $headers ): array {
		$aliases = array(
			'term_id'    => array( 'termid', 'id', 'categoryid', 'categorytermid' ),
			'slug'       => array( 'slug', 'categoryslug', 'productcatslug' ),
			'path'       => array( 'path', 'categorypath', 'productcatpath', 'categoriepath' ),
			'markup'     => array( 'markup', 'markuppercent', 'markuppct', 'markuppercentage', 'adaos', 'adaospercent' ),
			'min_margin' => array( 'minmargin', 'minimummargin', 'minimumprofit', 'marjaminima', 'profitminim' ),
			'rounding'   => array( 'rounding', 'roundingrule', 'rotunjire' ),
		);
		$map     = array();

		foreach ( $headers as $index => $header ) {
			$key = $this->markup_csv_key( (string) $header );

			if ( '' === $key ) {
				continue;
			}

			foreach ( $aliases as $field => $field_aliases ) {
				if ( isset( $map[ $field ] ) || ! in_array( $key, $field_aliases, true ) ) {
					continue;
				}

				$map[ $field ] = (int) $index;
				break;
			}
		}

		return $map;
	}

	/**
	 * Resolves the category term ID for one CSV row.
	 *
	 * @param array<int,string|null>               $row CSV row.
	 * @param array<string,int>                    $header_map Header map.
	 * @param array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>} $lookup Category lookup.
	 */
	private function resolve_markup_csv_term_id( array $row, array $header_map, array $lookup ): int {
		$term_id = absint( $this->markup_csv_cell( $row, $header_map, 'term_id' ) );

		if ( $term_id > 0 && isset( $lookup['ids'][ $term_id ] ) ) {
			return $term_id;
		}

		$slug = sanitize_title( $this->markup_csv_cell( $row, $header_map, 'slug' ) );

		if ( '' !== $slug && isset( $lookup['slugs'][ $slug ] ) ) {
			return (int) $lookup['slugs'][ $slug ];
		}

		$path_key = $this->markup_category_path_key( $this->markup_csv_cell( $row, $header_map, 'path' ) );

		if ( '' !== $path_key && isset( $lookup['paths'][ $path_key ] ) ) {
			return (int) $lookup['paths'][ $path_key ];
		}

		return 0;
	}

	/**
	 * Returns one CSV cell by mapped field.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 */
	private function markup_csv_cell( array $row, array $header_map, string $field ): string {
		if ( ! isset( $header_map[ $field ] ) ) {
			return '';
		}

		$index = (int) $header_map[ $field ];
		$value = $row[ $index ] ?? '';

		return trim( wp_check_invalid_utf8( is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * Checks whether a parsed CSV row is empty.
	 *
	 * @param array<int,string|null> $row CSV row.
	 */
	private function is_empty_csv_row( array $row ): bool {
		foreach ( $row as $value ) {
			if ( '' !== trim( is_scalar( $value ) ? (string) $value : '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalizes imported rounding values to supported keys.
	 */
	private function normalize_markup_csv_rounding( string $value ): string {
		$key = $this->markup_csv_key( $value );

		return match ( $key ) {
			'', 'none', 'no', 'fara', 'fararotunjire' => 'none',
			'ending99', 'roundto99', 'rotunjire99' => 'ending_99',
			'integerron', 'wholeron', 'roundtowholeron', 'ronintreg' => 'integer_ron',
			'fiveron', '5ron', 'roundto5ron', 'rotunjire5ron' => 'five_ron',
			default => sanitize_key( $value ),
		};
	}

	/**
	 * Builds a normalized CSV/key lookup token.
	 */
	private function markup_csv_key( string $value ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}

		$value = strtolower( $value );

		return (string) preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	/**
	 * Normalizes category paths for CSV matching.
	 */
	private function markup_category_path_key( string $path ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$path = remove_accents( $path );
		}

		$path = strtolower( $path );
		$path = preg_replace( '/\s*>\s*/', '>', $path );
		$path = preg_replace( '/\s+/', ' ', (string) $path );

		return trim( (string) $path );
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

		$data['extracted_attributes'] = Schrack_Attribute_Extractor::extract( $data['name'] );

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
	 * Redirects back to the WooCommerce product categories screen.
	 */
	private function redirect_categories_page(): void {
		wp_safe_redirect(
			admin_url(
				add_query_arg(
					array(
						'taxonomy'  => 'product_cat',
						'post_type' => 'product',
					),
					'edit-tags.php'
				)
			)
		);
		exit;
	}

	/**
	 * Detects the WooCommerce product category admin screen.
	 */
	private function is_product_category_admin_screen( mixed $screen ): bool {
		if ( is_object( $screen ) ) {
			$screen_id = isset( $screen->id ) ? (string) $screen->id : '';
			$taxonomy  = isset( $screen->taxonomy ) ? (string) $screen->taxonomy : '';

			if ( 'edit-product_cat' === $screen_id || 'product_cat' === $taxonomy ) {
				return true;
			}
		}

		$taxonomy  = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_GET['taxonomy'] ) ) : '';
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : '';

		return 'product_cat' === $taxonomy && ( '' === $post_type || 'product' === $post_type );
	}

	/**
	 * Checks whether the current user can manage WooCommerce product categories.
	 */
	private function can_manage_product_categories(): bool {
		return current_user_can( 'manage_product_terms' ) || current_user_can( 'manage_categories' ) || current_user_can( self::CAPABILITY );
	}

	/**
	 * Capability guard for product category CSV actions.
	 */
	private function assert_can_manage_product_categories(): void {
		if ( ! $this->can_manage_product_categories() ) {
			wp_die( esc_html__( 'You do not have permission to manage product categories.', 'schrack-woocommerce-sync' ) );
		}
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
