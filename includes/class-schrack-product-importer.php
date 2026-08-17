<?php
/**
 * Resumable WooCommerce product CSV imports.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Product_Importer {
	public const STATUS_KEY = 'product_import';
	public const STATUS_OPTION = 'schrack_wc_product_import_status';
	public const HOOK       = 'schrack_wc_sync_product_import_batch';

	private const GROUP            = 'schrack-wc-sync';
	private const BATCH_SIZE       = 20;
	private const IMPORT_DIRECTORY = 'schrack-private-imports';
	private const FILE_MAX_AGE     = 2 * DAY_IN_SECONDS;
	private const WARNING_LIMIT    = 20;
	private const STRUCTURED_META_PREFIX = 'schrack-wc-json:v1:';
	private const ESCAPED_STRING_PREFIX  = 'schrack-wc-string:v1:';
	private const PLACEHOLDER_JOB_META   = '_schrack_product_import_job';

	private Schrack_Settings $settings;
	private Schrack_Logger $logger;
	private string $active_import_id = '';
	/** @var array<string,string> */
	private array $lock_tokens = array();

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Registers the background worker.
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 1 );
	}

	/**
	 * Returns current import status.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$row = get_option( self::STATUS_OPTION, null );

		if ( ! is_array( $row ) ) {
			$status = $this->settings->get_status();
			$row    = $status[ self::STATUS_KEY ] ?? array();

			if ( is_array( $row ) && ! empty( $row ) ) {
				update_option( self::STATUS_OPTION, $row, false );
			}
		}

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Validates and copies an uploaded WooCommerce CSV into protected storage.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function prepare_upload( string $tmp_name, string $original_name, bool $update_existing, int $user_id, bool $enforce_upload_limit = true ): array|WP_Error {
		$current = $this->status();
		$export  = get_option( Schrack_Product_Exporter::STATUS_OPTION, null );
		$category_import = ( new Schrack_Category_CSV_Importer( $this->settings, $this->logger ) )->active_import();

		if ( ! is_array( $export ) ) {
			$all_status = $this->settings->get_status();
			$export     = isset( $all_status[ Schrack_Product_Exporter::STATUS_KEY ] ) && is_array( $all_status[ Schrack_Product_Exporter::STATUS_KEY ] ) ? $all_status[ Schrack_Product_Exporter::STATUS_KEY ] : array();
		}

		if ( in_array( (string) ( $current['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true ) ) {
			return new WP_Error( 'product_import_active', __( 'A product import is already running.', 'schrack-woocommerce-sync' ) );
		}

		if ( in_array( (string) ( $export['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true ) ) {
			return new WP_Error( 'product_export_active', __( 'A product export is running. Wait for it to finish before importing.', 'schrack-woocommerce-sync' ) );
		}

		if ( null !== $category_import ) {
			return new WP_Error( 'category_import_active', __( 'A category import is running. Wait for it to finish before importing products.', 'schrack-woocommerce-sync' ) );
		}

		if ( $user_id <= 0 || ! user_can( $user_id, 'manage_woocommerce' ) ) {
			return new WP_Error( 'product_import_user', __( 'The importing administrator is not authorized.', 'schrack-woocommerce-sync' ) );
		}

		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$size      = is_file( $tmp_name ) ? (int) filesize( $tmp_name ) : 0;
		$max_size  = (int) apply_filters( 'import_upload_size_limit', wp_max_upload_size() );

		if ( ! is_readable( $tmp_name ) || $size <= 0 ) {
			return new WP_Error( 'product_import_file', __( 'The uploaded product CSV is empty or unreadable.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			return new WP_Error( 'product_import_type', __( 'Only CSV and TXT product import files are accepted.', 'schrack-woocommerce-sync' ) );
		}

		if ( $enforce_upload_limit && $max_size > 0 && $size > $max_size ) {
			return new WP_Error( 'product_import_size', __( 'The uploaded product CSV exceeds the WordPress import size limit.', 'schrack-woocommerce-sync' ) );
		}

		try {
			$this->ensure_woocommerce_importer();
			$file_data = $this->inspect_csv( $tmp_name, $update_existing );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'product_import_invalid', $exception->getMessage() );
		}

		$dir       = $this->import_directory( true );
		$import_id = sanitize_key( wp_generate_uuid4() );

		if ( null === $dir || '' === $import_id ) {
			return new WP_Error( 'product_import_dir', __( 'The private product import directory could not be created.', 'schrack-woocommerce-sync' ) );
		}

		$this->cleanup_old_files( $dir );
		$this->cleanup_placeholders( sanitize_key( (string) ( $current['import_id'] ?? '' ) ) );
		$this->delete_status_file( $current );

		$base_name = sanitize_file_name( pathinfo( $original_name, PATHINFO_FILENAME ) );
		$base_name = '' !== $base_name ? $base_name : 'products';
		$file_name = sprintf( 'product-import-%1$s-%2$s-%3$s.csv', gmdate( 'Ymd-His' ), $base_name, substr( str_replace( '-', '', $import_id ), 0, 12 ) );
		$target    = trailingslashit( $dir ) . $file_name;

		$linked = @link( $tmp_name, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.link_link

		if ( ! $linked && ! copy( $tmp_name, $target ) ) {
			return new WP_Error( 'product_import_copy', __( 'The uploaded product CSV could not be saved for background import.', 'schrack-woocommerce-sync' ) );
		}

		@chmod( $target, 0660 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$now    = time();
		$status = array_merge(
			array(
				'state'               => 'queued',
				'import_id'           => $import_id,
				'file'                => $target,
				'file_name'           => $file_name,
				'original_name'       => sanitize_file_name( $original_name ),
				'total_bytes'         => (int) filesize( $target ),
				'position'            => 0,
				'percentage'          => 0,
				'mapping'             => $file_data['mapping'],
				'columns'             => count( $file_data['headers'] ),
				'update_existing'     => $update_existing ? 'yes' : 'no',
				'user_id'             => $user_id,
				'imported'            => 0,
				'imported_variations' => 0,
				'updated'             => 0,
				'failed'              => 0,
				'skipped'             => 0,
				'processed'           => 0,
				'warnings'            => array(),
				'batch_size'          => Schrack_Memory_Guard::import_batch_size(),
				'cleanup_batch_size'  => Schrack_Memory_Guard::cleanup_batch_size(),
				'started_at'          => $now,
				'last_progress_at'    => $now,
			),
			Schrack_Memory_Guard::diagnostics()
		);

		$this->save_status( $status );

		if ( ! $this->enqueue_batch( $import_id ) ) {
			$status['state']   = 'error';
			$status['message'] = __( 'The first background product import batch could not be queued.', 'schrack-woocommerce-sync' );
			$this->save_status( $status );
		}

		$this->logger->info(
			'import',
			'Queued WooCommerce product CSV import.',
			null,
			array(
				'import_id'       => $import_id,
				'file_name'       => $file_name,
				'bytes'           => $status['total_bytes'],
				'columns'         => $status['columns'],
				'update_existing' => $status['update_existing'],
			)
		);

		return $this->status();
	}

	/**
	 * Runs one importer batch.
	 */
	public function run_batch( string $import_id ): void {
		$import_id = sanitize_key( $import_id );

		if ( '' === $import_id || ! $this->acquire_lock( $import_id ) ) {
			return;
		}

		try {
			$this->run_locked_batch( $import_id );
		} catch ( Throwable $exception ) {
			$status = $this->status();
			$this->cleanup_placeholders( $import_id, false );

			if ( $import_id === (string) ( $status['import_id'] ?? '' ) ) {
				$status['state']            = 'error';
				$status['message']          = $exception->getMessage();
				$status['last_progress_at'] = time();
				$status                     = array_merge( $status, Schrack_Memory_Guard::diagnostics() );
				$this->save_status( $status );
			}

			$this->logger->error(
				'import',
				'WooCommerce product CSV import failed.',
				null,
				array(
					'import_id' => $import_id,
					'error'     => $exception->getMessage(),
				)
			);
		} finally {
			$this->release_lock( $import_id );
		}
	}

	/**
	 * Cancels the active import and removes its uploaded copy.
	 */
	public function reset(): void {
		$status    = $this->status();
		$import_id = sanitize_key( (string) ( $status['import_id'] ?? '' ) );

		if ( '' !== $import_id ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, array( $import_id ), self::GROUP );
			}

			wp_clear_scheduled_hook( self::HOOK, array( $import_id ) );
			$this->force_release_lock( $import_id );
			$this->cleanup_placeholders( $import_id );
		}

		$this->delete_status_file( $status );
		$this->save_status( array( 'state' => 'idle' ) );
	}

	/**
	 * Requeues a stale or failed import at its last confirmed file position.
	 *
	 * @return array<string,mixed>
	 */
	public function resume(): array {
		$status    = $this->status();
		$state     = (string) ( $status['state'] ?? '' );
		$import_id = sanitize_key( (string) ( $status['import_id'] ?? '' ) );
		$path      = isset( $status['file'] ) ? (string) $status['file'] : '';
		$export    = get_option( Schrack_Product_Exporter::STATUS_OPTION, array() );

		if ( is_array( $export ) && in_array( (string) ( $export['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true ) ) {
			return array( 'state' => 'error', 'message' => __( 'A product export is running. Wait for it to finish before resuming the import.', 'schrack-woocommerce-sync' ) );
		}

		if ( '' === $import_id || ! in_array( $state, array( 'queued', 'running', 'finalizing', 'error' ), true ) ) {
			return array( 'state' => 'error', 'message' => __( 'There is no resumable product import.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! $this->is_valid_import_file( $path ) || ! is_readable( $path ) ) {
			return array( 'state' => 'error', 'message' => __( 'The private product import CSV is no longer available.', 'schrack-woocommerce-sync' ) );
		}

		$locked_at = absint( get_option( $this->lock_key( $import_id ), 0 ) );

		if ( $locked_at >= time() - 20 * MINUTE_IN_SECONDS ) {
			return array( 'state' => 'error', 'message' => __( 'An import batch is still running. Wait before retrying it.', 'schrack-woocommerce-sync' ) );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $import_id ), self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK, array( $import_id ) );
		$this->force_release_lock( $import_id );
		$status['state']            = 'finalizing' === $state || absint( $status['percentage'] ?? 0 ) >= 100 ? 'finalizing' : 'queued';
		$status['last_progress_at'] = time();
		unset( $status['message'] );
		$this->save_status( $status );

		if ( ! $this->enqueue_batch( $import_id ) ) {
			$status['state']   = 'error';
			$status['message'] = __( 'The product import retry could not be queued.', 'schrack-woocommerce-sync' );
			$this->save_status( $status );
		}

		return $this->status();
	}

	/**
	 * Decodes this plugin's reversible JSON marker before Woo saves metadata.
	 *
	 * @param array<string,mixed> $data Parsed WooCommerce product data.
	 * @return array<string,mixed>
	 */
	public function decode_structured_meta( array $data ): array {
		$separate_attributes     = array();
		$has_separate_attributes = false;

		foreach ( array_keys( $data ) as $column_id ) {
			$definition = Schrack_WC_Product_CSV_Exporter::schrack_decode_attribute_column_id( (string) $column_id );

			if ( null === $definition ) {
				continue;
			}

			$has_separate_attributes = true;

			$value = $data[ $column_id ];
			unset( $data[ $column_id ] );

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$values = $this->split_separate_attribute_values( (string) $value );

			if ( empty( $values ) ) {
				continue;
			}

			$separate_attributes[] = array(
				'name'     => $definition['name'],
				'value'    => $values,
				'taxonomy' => $definition['taxonomy'],
				'visible'  => true,
			);
		}

		if ( $has_separate_attributes ) {
			$existing_raw          = isset( $data['raw_attributes'] ) && is_array( $data['raw_attributes'] ) ? $data['raw_attributes'] : array();
			$data['raw_attributes'] = array_merge( $existing_raw, $separate_attributes );
		}

		if ( empty( $data['meta_data'] ) || ! is_array( $data['meta_data'] ) ) {
			return $data;
		}

		foreach ( $data['meta_data'] as $index => $meta ) {
			$key              = is_array( $meta ) && isset( $meta['key'] ) ? (string) $meta['key'] : '';
			$value            = is_array( $meta ) && isset( $meta['value'] ) && is_string( $meta['value'] ) ? $meta['value'] : '';
			$has_scalar_value = is_array( $meta ) && array_key_exists( 'value', $meta ) && is_scalar( $meta['value'] );

			if ( '_schrack_catalog_source' === $key && $has_scalar_value ) {
				$data['meta_data'][ $index ]['value'] = sanitize_key( (string) $meta['value'] );
				$value                                = (string) $data['meta_data'][ $index ]['value'];
			}

			if ( in_array( $key, $this->supplier_price_meta_keys(), true ) && $has_scalar_value ) {
				$normalized = wc_format_decimal( (string) $meta['value'] );

				if ( '' !== $normalized || '' === trim( (string) $meta['value'] ) ) {
					$data['meta_data'][ $index ]['value'] = $normalized;
					$value                                = $normalized;
				}
			}

			if ( str_starts_with( $value, self::ESCAPED_STRING_PREFIX ) ) {
				$literal = base64_decode( substr( $value, strlen( self::ESCAPED_STRING_PREFIX ) ), true );

				if ( false !== $literal ) {
					$data['meta_data'][ $index ]['value'] = $literal;
				}

				continue;
			}

			if ( ! str_starts_with( $value, self::STRUCTURED_META_PREFIX ) ) {
				continue;
			}

			$encoded = substr( $value, strlen( self::STRUCTURED_META_PREFIX ) );
			$json    = base64_decode( $encoded, true );

			if ( false === $json ) {
				continue;
			}

			$decoded = json_decode( $json, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$data['meta_data'][ $index ]['value'] = $decoded;
			}
		}

		return $data;
	}

	/**
	 * Forces this job to use the importer that scopes temporary placeholders.
	 *
	 * @return class-string<WC_Product_CSV_Importer>
	 */
	public function scoped_importer_class(): string {
		return 'Schrack_Scoped_Product_CSV_Importer';
	}

	/**
	 * Keeps the job identifier intact after third-party importer argument filters.
	 *
	 * @param array<string,mixed> $args Importer arguments.
	 * @return array<string,mixed>
	 */
	public function scoped_importer_args( array $args ): array {
		$args['schrack_import_id'] = $this->active_import_id;
		return $args;
	}

	/**
	 * Runs one locked WooCommerce importer batch.
	 */
	private function run_locked_batch( string $import_id ): void {
		$status = $this->status();

		if (
			$import_id !== (string) ( $status['import_id'] ?? '' ) ||
			! in_array( (string) ( $status['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true )
		) {
			return;
		}

		if ( 'finalizing' === (string) $status['state'] ) {
			$this->finish_import( $status );
			return;
		}

		$path    = isset( $status['file'] ) ? (string) $status['file'] : '';
		$user_id = absint( $status['user_id'] ?? 0 );

		if ( ! $this->is_valid_import_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( __( 'The private product import CSV is missing or unreadable.', 'schrack-woocommerce-sync' ) );
		}

		if ( $user_id <= 0 || ! user_can( $user_id, 'manage_woocommerce' ) ) {
			throw new RuntimeException( __( 'The administrator who started this import is no longer authorized.', 'schrack-woocommerce-sync' ) );
		}

		wp_set_current_user( $user_id );
		$this->ensure_woocommerce_importer();

		// Re-evaluate in the worker because cPanel cron may use a different
		// memory_limit than the administrator request which queued the import.
		$batch_size                 = max( 1, min( self::BATCH_SIZE, Schrack_Memory_Guard::import_batch_size() ) );
		$status['state']            = 'running';
		$status['batch_size']       = $batch_size;
		$status['last_progress_at'] = time();
		$this->save_status( $status );

		$position = absint( $status['position'] ?? 0 );
		$mapping  = isset( $status['mapping'] ) && is_array( $status['mapping'] ) ? $status['mapping'] : array();
		$params   = array(
			'start_pos'          => $position,
			'lines'              => $batch_size,
			'mapping'            => $mapping,
			'update_existing'    => 'yes' === (string) ( $status['update_existing'] ?? 'no' ),
			'delimiter'          => ',',
			'character_encoding' => 'UTF-8',
			'parse'              => true,
			'prevent_timeouts'   => true,
			'schrack_import_id'  => $import_id,
		);

		$this->active_import_id = $import_id;
		add_filter( 'woocommerce_product_importer_parsed_data', array( $this, 'decode_structured_meta' ), 20, 1 );
		add_filter( 'woocommerce_product_csv_importer_class', array( $this, 'scoped_importer_class' ), PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_product_csv_importer_args', array( $this, 'scoped_importer_args' ), PHP_INT_MAX, 1 );

		try {
			$importer = WC_Product_CSV_Importer_Controller::get_importer( $path, $params );
			$results  = $importer->import();
		} finally {
			remove_filter( 'woocommerce_product_importer_parsed_data', array( $this, 'decode_structured_meta' ), 20 );
			remove_filter( 'woocommerce_product_csv_importer_class', array( $this, 'scoped_importer_class' ), PHP_INT_MAX );
			remove_filter( 'woocommerce_product_csv_importer_args', array( $this, 'scoped_importer_args' ), PHP_INT_MAX );
			$this->active_import_id = '';
		}

		$next_position = absint( $importer->get_file_position() );
		$percentage    = absint( $importer->get_percent_complete() );
		$batch_counts  = array(
			'imported'            => $this->result_count( $results, 'imported' ),
			'imported_variations' => $this->result_count( $results, 'imported_variations' ),
			'updated'             => $this->result_count( $results, 'updated' ),
			'failed'              => $this->result_count( $results, 'failed' ),
			'skipped'             => $this->result_count( $results, 'skipped' ),
		);
		$memory_product_ids = array();

		foreach ( array( 'imported', 'imported_variations', 'updated' ) as $result_key ) {
			if ( isset( $results[ $result_key ] ) && is_array( $results[ $result_key ] ) ) {
				$memory_product_ids = array_merge( $memory_product_ids, array_map( 'absint', $results[ $result_key ] ) );
			}
		}

		$memory_product_ids = array_values( array_filter( array_unique( $memory_product_ids ) ) );
		$latest = $this->status();

		if ( $import_id !== (string) ( $latest['import_id'] ?? '' ) ) {
			unset( $importer, $results );
			Schrack_Memory_Guard::release_runtime_memory( $memory_product_ids );
			$this->cleanup_placeholders( $import_id );
			return;
		}

		$status   = array_merge( $status, $latest );
		$warnings = isset( $status['warnings'] ) && is_array( $status['warnings'] ) ? $status['warnings'] : array();
		$warnings = $this->append_result_warnings( $warnings, $results );

		foreach ( $batch_counts as $key => $count ) {
			$status[ $key ] = absint( $status[ $key ] ?? 0 ) + $count;
		}

		$status['processed']        = absint( $status['imported'] ) + absint( $status['imported_variations'] ) + absint( $status['updated'] ) + absint( $status['failed'] ) + absint( $status['skipped'] );
		$status['position']         = $next_position;
		$status['percentage']       = min( 100, $percentage );
		$status['warnings']         = $warnings;
		$status['errors']           = absint( $status['failed'] );
		$status['last_progress_at'] = time();
		$status                     = array_merge( $status, Schrack_Memory_Guard::diagnostics() );

		unset( $importer, $results );
		Schrack_Memory_Guard::release_runtime_memory( $memory_product_ids );

		if ( $percentage < 100 && $next_position <= $position ) {
			throw new RuntimeException( __( 'The product importer could not advance in the CSV file.', 'schrack-woocommerce-sync' ) );
		}

		if ( $percentage >= 100 ) {
			$status['state']            = 'finalizing';
			$status['last_progress_at'] = time();
			$this->save_status( $status );
			$this->release_lock( $import_id );

			if ( ! $this->enqueue_batch( $import_id ) ) {
				$status['state']   = 'error';
				$status['message'] = __( 'The final product import cleanup could not be queued.', 'schrack-woocommerce-sync' );
				$this->save_status( $status );
			}
			return;
		}

		$this->save_status( $status );
		$this->release_lock( $import_id );

		if ( ! $this->enqueue_batch( $import_id ) ) {
			$status['state']   = 'error';
			$status['message'] = __( 'The next background product import batch could not be queued.', 'schrack-woocommerce-sync' );
			$this->save_status( $status );
		}
	}

	/**
	 * Completes an import and removes only Woo's temporary placeholders.
	 *
	 * @param array<string,mixed> $status Import status.
	 */
	private function finish_import( array $status ): void {
		$import_id          = sanitize_key( (string) ( $status['import_id'] ?? '' ) );
		$cleanup_batch_size = max( 1, min( 500, absint( $status['cleanup_batch_size'] ?? Schrack_Memory_Guard::cleanup_batch_size() ) ) );
		$cleanup            = $this->cleanup_placeholders( $import_id, true, absint( $status['cleanup_last_id'] ?? 0 ), true, $cleanup_batch_size );

		if ( $cleanup['has_more'] ) {
			$latest = $this->status();

			if ( $import_id !== (string) ( $latest['import_id'] ?? '' ) ) {
				return;
			}

			$status                       = array_merge( $status, $latest );
			$status['cleanup_last_id']    = $cleanup['last_id'];
			$status['cleanup_batch_size'] = $cleanup_batch_size;
			$status['last_progress_at']   = time();
			$status                       = array_merge( $status, Schrack_Memory_Guard::diagnostics() );
			$this->save_status( $status );
			$this->release_lock( $import_id );

			if ( ! $this->enqueue_batch( $import_id ) ) {
				$status['state']   = 'error';
				$status['message'] = __( 'The next product import cleanup batch could not be queued.', 'schrack-woocommerce-sync' );
				$this->save_status( $status );
			}
			return;
		}

		$this->delete_status_file( $status );
		$latest = $this->status();

		if ( $import_id !== (string) ( $latest['import_id'] ?? '' ) ) {
			return;
		}

		$status                     = array_merge( $status, $latest, Schrack_Memory_Guard::diagnostics() );
		$status['state']            = 'done';
		$status['percentage']       = 100;
		$status['finished_at']      = time();
		$status['last_progress_at'] = time();
		$status['file_deleted']     = 'yes';
		unset( $status['file'], $status['mapping'], $status['cleanup_last_id'] );
		$this->save_status( $status );

		$this->logger->info(
			'import',
			'Finished WooCommerce product CSV import.',
			null,
			array(
				'import_id'           => $status['import_id'] ?? '',
				'imported'            => $status['imported'] ?? 0,
				'imported_variations' => $status['imported_variations'] ?? 0,
				'updated'             => $status['updated'] ?? 0,
				'failed'              => $status['failed'] ?? 0,
				'skipped'             => $status['skipped'] ?? 0,
			)
		);
	}
	/**
	 * Removes only temporary data that belongs to the given plugin import.
	 *
	 * @param string $import_id        Import job identifier.
	 * @param bool   $remove_converted Whether completed rows should lose retry markers.
	 * @param int    $start_id         First product ID checkpoint.
	 * @param bool   $single_batch     Whether to stop after one bounded group.
	 * @param int    $batch_size       Maximum marked products read at once.
	 * @return array{last_id:int,has_more:bool}
	 */
	private function cleanup_placeholders( string $import_id, bool $remove_converted = true, int $start_id = 0, bool $single_batch = false, int $batch_size = 500 ): array {
		global $wpdb;

		if ( '' === $import_id ) {
			return array( 'last_id' => $start_id, 'has_more' => false );
		}

		$batch_size = max( 1, min( 500, $batch_size ) );
		$last_id  = max( 0, $start_id );
		$has_more = false;

		do {
			$status_clause = $remove_converted ? '' : "AND p.post_status = 'importing'";
			$rows          = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID, p.post_status
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type IN ( 'product', 'product_variation' )
					AND p.ID > %d
					AND pm.meta_key = %s
					AND pm.meta_value = %s
					{$status_clause}
					ORDER BY p.ID ASC
					LIMIT %d",
					$last_id,
					self::PLACEHOLDER_JOB_META,
					$import_id,
					$batch_size
				),
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();

			if ( empty( $rows ) ) {
				break;
			}

			$has_more = count( $rows ) >= $batch_size;

			$converted_ids = array();

			foreach ( $rows as $row ) {
				$product_id = absint( $row['ID'] ?? 0 );
				$last_id    = max( $last_id, $product_id );

				if ( $product_id <= 0 ) {
					continue;
				}

				if ( 'importing' === (string) ( $row['post_status'] ?? '' ) ) {
					wp_delete_post( $product_id, true );
				} elseif ( $remove_converted ) {
					$converted_ids[] = $product_id;
				}
			}

			if ( ! empty( $converted_ids ) ) {
				$id_list = implode( ',', array_map( 'absint', $converted_ids ) );
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$id_list}) AND meta_key IN (%s, %s)",
						'_original_id',
						self::PLACEHOLDER_JOB_META
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are normalized integers.

				if ( false === $deleted ) {
					throw new RuntimeException( __( 'The product import retry markers could not be removed.', 'schrack-woocommerce-sync' ) );
				}

				foreach ( $converted_ids as $product_id ) {
					wp_cache_delete( $product_id, 'post_meta' );
				}
			}

			Schrack_Memory_Guard::release_runtime_memory( array_map( 'absint', array_column( $rows, 'ID' ) ) );

			if ( $single_batch ) {
				break;
			}
		} while ( $has_more );

		return array( 'last_id' => $last_id, 'has_more' => $has_more );
	}

	/**
	 * Loads WooCommerce's official CSV importer and controller.
	 */
	private function ensure_woocommerce_importer(): void {
		if ( ! class_exists( 'WP_Importer', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		}

		if ( ! class_exists( 'WC_Product_CSV_Importer_Controller', false ) ) {
			require_once WC_ABSPATH . 'includes/admin/importers/class-wc-product-csv-importer-controller.php';
		}

		if ( ! class_exists( 'WC_Product_CSV_Importer', false ) ) {
			require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		}

		if ( ! class_exists( 'WC_Product_CSV_Importer_Controller', false ) || ! class_exists( 'WC_Product_CSV_Importer', false ) ) {
			throw new RuntimeException( __( 'WooCommerce product CSV importer is unavailable.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! class_exists( 'Schrack_Scoped_Product_CSV_Importer', false ) ) {
			require_once __DIR__ . '/class-schrack-scoped-product-csv-importer.php';
		}

		if ( ! class_exists( 'Schrack_Scoped_Product_CSV_Importer', false ) ) {
			throw new RuntimeException( __( 'The scoped product CSV importer is unavailable.', 'schrack-woocommerce-sync' ) );
		}
	}

	/**
	 * Reads and automatically maps a WooCommerce CSV header.
	 *
	 * @return array{headers:array<int,string>,mapping:array{from:array<int,string>,to:array<int,string>}}
	 */
	private function inspect_csv( string $path, bool $update_existing ): array {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new RuntimeException( __( 'The uploaded product CSV could not be opened.', 'schrack-woocommerce-sync' ) );
		}

		$headers = fgetcsv( $handle, 0, ',', '"', "\0" );
		$sample  = fgetcsv( $handle, 0, ',', '"', "\0" );
		fclose( $handle );

		if ( ! is_array( $headers ) || ! is_array( $sample ) || empty( array_filter( $sample, 'strlen' ) ) ) {
			throw new RuntimeException( __( 'The product CSV has no header or product rows.', 'schrack-woocommerce-sync' ) );
		}

		$headers = array_map( 'trim', $headers );

		if ( isset( $headers[0] ) && 'efbbbf' === substr( bin2hex( $headers[0] ), 0, 6 ) ) {
			$headers[0] = substr( $headers[0], 3 );
		}

		$controller = new class() extends WC_Product_CSV_Importer_Controller {
			/** @return array<int,string> */
			public function schrack_map_headers( array $raw_headers ): array {
				return $this->auto_map_columns( $raw_headers );
			}
		};
		$mapped = $controller->schrack_map_headers( $headers );
		$readable_supplier_headers = $this->readable_supplier_header_meta_map();

		foreach ( $headers as $index => $header ) {
			$normalized_header = $this->normalize_supplier_header( $header );
			$attribute         = $this->readable_attribute_definition_from_header( $header );

			if ( isset( $readable_supplier_headers[ $normalized_header ] ) ) {
				$mapped[ $index ] = 'meta:' . $readable_supplier_headers[ $normalized_header ];
			} elseif ( null !== $attribute ) {
				$mapped[ $index ] = Schrack_WC_Product_CSV_Exporter::schrack_attribute_column_id(
					$attribute['name'],
					$attribute['label'],
					$attribute['taxonomy']
				);
			}
		}

		$recognized = array_values(
			array_filter(
				$mapped,
				static fn ( mixed $column ): bool => is_string( $column ) && '' !== trim( $column )
			)
		);

		if ( count( $mapped ) !== count( $headers ) || empty( $recognized ) ) {
			throw new RuntimeException( __( 'This is not a recognizable WooCommerce product CSV export.', 'schrack-woocommerce-sync' ) );
		}

		if ( $update_existing && ! in_array( 'id', $recognized, true ) && ! in_array( 'sku', $recognized, true ) ) {
			throw new RuntimeException( __( 'A product update CSV must contain an ID or SKU column.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! $update_existing && ! in_array( 'name', $recognized, true ) ) {
			throw new RuntimeException( __( 'A CSV used to create products must contain a Name column.', 'schrack-woocommerce-sync' ) );
		}

		return array(
			'headers' => $headers,
			'mapping' => array(
				'from' => $headers,
				'to'   => array_values( $mapped ),
			),
		);
	}

	/**
	 * Maps the named supplier export headers back to their product metadata.
	 *
	 * @return array<string,string> Normalized header => product meta key.
	 */
	private function readable_supplier_header_meta_map(): array {
		$column_names = Schrack_WC_Product_CSV_Exporter::schrack_supplier_column_names();
		$column_meta  = Schrack_WC_Product_CSV_Exporter::schrack_supplier_column_meta_map();
		$headers      = array();

		foreach ( $column_meta as $column_id => $meta_key ) {
			if ( isset( $column_names[ $column_id ] ) ) {
				$headers[ $this->normalize_supplier_header( $column_names[ $column_id ] ) ] = $meta_key;
			}

			// Also accept the stable internal ID when a CSV header was edited by hand.
			$headers[ $this->normalize_supplier_header( $column_id ) ] = $meta_key;
		}

		return $headers;
	}

	/**
	 * Produces a case/accent-insensitive header key for reliable re-import.
	 */
	private function normalize_supplier_header( string $header ): string {
		$header = remove_accents( trim( $header ) );
		$header = function_exists( 'mb_strtolower' ) ? mb_strtolower( $header, 'UTF-8' ) : strtolower( $header );

		return (string) preg_replace( '/\s+/u', ' ', $header );
	}

	/**
	 * Recognizes the readable wide-attribute header emitted by the exporter.
	 * The label remains human-friendly while the bracketed Woo name makes the
	 * mapping deterministic on another shop.
	 *
	 * @return array{name:string,label:string,taxonomy:bool}|null
	 */
	private function readable_attribute_definition_from_header( string $header ): ?array {
		if ( 1 !== preg_match( '/^(?:Atribut|Attribútum|Attribute)\s*:\s*(.+)\s+\[([^\r\n]+)\]\s*$/iu', trim( $header ), $matches ) ) {
			return null;
		}

		$label = trim( sanitize_text_field( html_entity_decode( (string) $matches[1], ENT_QUOTES ) ) );
		$name  = trim( sanitize_text_field( html_entity_decode( (string) $matches[2], ENT_QUOTES ) ) );

		if (
			'' === $label ||
			'' === $name ||
			strlen( $label ) > 255 ||
			strlen( $name ) > 191 ||
			preg_match( '/[\x00-\x1F\x7F]/', $name ) ||
			preg_match( '/[\x00-\x1F\x7F]/', $label )
		) {
			return null;
		}

		return array(
			'name'     => $name,
			'label'    => $label,
			'taxonomy' => str_starts_with( $name, 'pa_' ),
		);
	}

	/**
	 * Splits WooCommerce's comma-separated attribute list while retaining an
	 * escaped literal comma ("\,") inside an individual value.
	 *
	 * @return array<int,string>
	 */
	private function split_separate_attribute_values( string $raw_value ): array {
		$raw_value = trim( $raw_value );
		$escape_triggers = array( '=', '+', '-', '@', "\t", "\r" );

		if ( strlen( $raw_value ) > 1 && "'" === $raw_value[0] && in_array( $raw_value[1], $escape_triggers, true ) ) {
			$raw_value = substr( $raw_value, 1 );
		}

		if ( '' === $raw_value ) {
			return array();
		}

		$values  = array();
		$current = '';
		$length  = strlen( $raw_value );

		for ( $offset = 0; $offset < $length; $offset++ ) {
			$character = $raw_value[ $offset ];

			if ( '\\' === $character && $offset + 1 < $length ) {
				$escaped_character = $raw_value[ $offset + 1 ];

				if ( '\\' === $escaped_character || ',' === $escaped_character ) {
					$current .= $escaped_character;
					$offset++;
					continue;
				}
			}

			if ( ',' === $character ) {
				$value = trim( $current );

				if ( '' !== $value ) {
					$values[] = wc_clean( $value );
				}

				$current = '';
				continue;
			}

			$current .= $character;
		}

		$current = trim( $current );

		if ( '' !== $current ) {
			$values[] = wc_clean( $current );
		}

		return array_values( $values );
	}

	/**
	 * Supplier price fields that must return to storage in machine decimals.
	 *
	 * @return array<int,string>
	 */
	private function supplier_price_meta_keys(): array {
		return array(
			'_schrack_purchase_price',
			'_schrack_purchase_price_raw',
			'_telesystem_price_1',
			'_telesystem_price_2',
		);
	}

	/**
	 * Returns the size of one importer result bucket.
	 *
	 * @param array<string,mixed> $results Import results.
	 */
	private function result_count( array $results, string $key ): int {
		return isset( $results[ $key ] ) && is_countable( $results[ $key ] ) ? count( $results[ $key ] ) : 0;
	}

	/**
	 * Adds a bounded set of actionable row errors to the status.
	 *
	 * @param array<int,string>   $warnings Existing warnings.
	 * @param array<string,mixed> $results Import results.
	 * @return array<int,string>
	 */
	private function append_result_warnings( array $warnings, array $results ): array {
		foreach ( array( 'failed', 'skipped' ) as $bucket ) {
			foreach ( isset( $results[ $bucket ] ) && is_array( $results[ $bucket ] ) ? $results[ $bucket ] : array() as $error ) {
				if ( count( $warnings ) >= self::WARNING_LIMIT ) {
					break 2;
				}

				if ( $error instanceof WP_Error ) {
					$data = $error->get_error_data();
					$row  = is_array( $data ) && isset( $data['row'] ) ? (string) $data['row'] : '';
					$warnings[] = '' !== $row ? $row . ': ' . $error->get_error_message() : $error->get_error_message();
				}
			}
		}

		return array_values( $warnings );
	}

	/**
	 * Queues one background import batch.
	 */
	private function enqueue_batch( string $import_id ): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$queued = absint( as_enqueue_async_action( self::HOOK, array( $import_id ), self::GROUP ) ) > 0;

			if ( $queued && class_exists( 'Schrack_Cron' ) ) {
				Schrack_Cron::dispatch_queue_runner_ping();
			}

			return $queued;
		}

		return false !== wp_schedule_single_event( time() + 5, self::HOOK, array( $import_id ) );
	}

	/**
	 * Creates and protects the import directory.
	 */
	private function import_directory( bool $create ): ?string {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$dir = trailingslashit( (string) $uploads['basedir'] ) . self::IMPORT_DIRECTORY;

		if ( $create && ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		if ( ! is_dir( $dir ) ) {
			return null;
		}

		if ( $create ) {
			$this->protect_import_directory( $dir );
		}

		return $dir;
	}

	/**
	 * Prevents direct web access to uploaded import files.
	 */
	private function protect_import_directory( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>\n",
		);

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;

			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			}
		}
	}

	/**
	 * Validates an import copy inside the private directory.
	 */
	private function is_valid_import_file( string $path ): bool {
		$dir = $this->import_directory( false );

		if ( null === $dir || '' === $path || 'csv' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return false;
		}

		$real_dir  = realpath( $dir );
		$real_path = realpath( $path );

		return is_string( $real_dir ) && is_string( $real_path ) && str_starts_with( $real_path, trailingslashit( $real_dir ) ) && is_file( $real_path );
	}

	/**
	 * Deletes only the validated import file referenced by status.
	 *
	 * @param array<string,mixed> $status Import status.
	 */
	private function delete_status_file( array $status ): void {
		$path = isset( $status['file'] ) ? (string) $status['file'] : '';

		if ( $this->is_valid_import_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Removes abandoned private import copies after two days.
	 */
	private function cleanup_old_files( string $dir ): void {
		$files = glob( trailingslashit( $dir ) . 'product-import-*.csv' );

		foreach ( is_array( $files ) ? $files : array() as $path ) {
			if ( is_file( $path ) && filemtime( $path ) < time() - self::FILE_MAX_AGE ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Saves import progress separately from high-frequency supplier sync status.
	 *
	 * @param array<string,mixed> $status Import status.
	 */
	private function save_status( array $status ): void {
		$status = array_merge(
			array(
				'last_run'  => current_time( 'mysql' ),
				'processed' => 0,
				'errors'    => 0,
			),
			$status
		);

		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Uses an atomic option lock to avoid duplicate workers.
	 */
	private function acquire_lock( string $import_id ): bool {
		$key   = $this->lock_key( $import_id );
		$token = time() . ':' . wp_generate_uuid4();

		if ( add_option( $key, $token, '', false ) ) {
			$this->lock_tokens[ $import_id ] = $token;
			return true;
		}

		$current   = (string) get_option( $key, '' );
		$locked_at = absint( strtok( $current, ':' ) );

		if ( $locked_at > 0 && $locked_at >= time() - 20 * MINUTE_IN_SECONDS ) {
			return false;
		}

		delete_option( $key );

		if ( add_option( $key, $token, '', false ) ) {
			$this->lock_tokens[ $import_id ] = $token;
			return true;
		}

		return false;
	}

	private function release_lock( string $import_id ): void {
		$token = $this->lock_tokens[ $import_id ] ?? '';

		if ( '' !== $token && hash_equals( $token, (string) get_option( $this->lock_key( $import_id ), '' ) ) ) {
			delete_option( $this->lock_key( $import_id ) );
		}

		unset( $this->lock_tokens[ $import_id ] );
	}

	/**
	 * Clears a lock only for explicit reset or a verified stale-job retry.
	 */
	private function force_release_lock( string $import_id ): void {
		delete_option( $this->lock_key( $import_id ) );
		unset( $this->lock_tokens[ $import_id ] );
	}

	private function lock_key( string $import_id ): string {
		return 'schrack_wc_product_import_lock_' . md5( $import_id );
	}
}
