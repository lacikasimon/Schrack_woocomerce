<?php
/**
 * Resumable WooCommerce-compatible product CSV exports.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Admin_Exporters', false ) ) {
	require_once WC_ABSPATH . 'includes/admin/class-wc-admin-exporters.php';
}

if ( ! class_exists( 'WC_Product_CSV_Exporter', false ) ) {
	require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
}

/**
 * Exposes WooCommerce's protected row builder while preserving raw prices.
 */
class Schrack_WC_Product_CSV_Exporter extends WC_Product_CSV_Exporter {
	/**
	 * Builds one associative WooCommerce CSV row.
	 *
	 * @return array<string,mixed>
	 */
	public function schrack_product_row( WC_Product $product ): array {
		return $this->generate_row_data( $product );
	}

	/**
	 * Avoids customer/B2B price filters during an administrative backup.
	 */
	protected function get_column_value_sale_price( $product ) {
		return wc_format_localized_price( $product->get_sale_price( 'edit' ) );
	}

	/**
	 * Avoids customer/B2B price filters during an administrative backup.
	 */
	protected function get_column_value_regular_price( $product ) {
		return wc_format_localized_price( $product->get_regular_price( 'edit' ) );
	}
}

class Schrack_Product_Exporter {
	public const STATUS_KEY = 'product_export';
	public const STATUS_OPTION = 'schrack_wc_product_export_status';
	public const HOOK       = 'schrack_wc_sync_product_export_batch';

	private const GROUP            = 'schrack-wc-sync';
	private const BATCH_SIZE       = 50;
	private const EXPORT_DIRECTORY = 'schrack-private-exports';
	private const FILE_MAX_AGE     = 7 * DAY_IN_SECONDS;
	private const FINALIZE_COPY_BUDGET = 64 * 1024 * 1024;
	private const FINALIZE_CHUNK_SIZE  = 256 * 1024;
	private const FINALIZE_TIME_BUDGET = 8.0;
	private const STRUCTURED_META_PREFIX = 'schrack-wc-json:v1:';
	private const ESCAPED_STRING_PREFIX  = 'schrack-wc-string:v1:';

	private Schrack_Settings $settings;
	private Schrack_Logger $logger;
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
	 * Registers the background batch hook.
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ), 10, 1 );
	}

	/**
	 * Returns the current export status.
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
	 * Creates a complete WooCommerce product backup and queues its first batch.
	 *
	 * @return array<string,mixed>
	 */
	public function queue(): array {
		$current = $this->status();
		$import  = get_option( Schrack_Product_Importer::STATUS_OPTION, null );

		if ( ! is_array( $import ) ) {
			$all_status = $this->settings->get_status();
			$import     = isset( $all_status[ Schrack_Product_Importer::STATUS_KEY ] ) && is_array( $all_status[ Schrack_Product_Importer::STATUS_KEY ] ) ? $all_status[ Schrack_Product_Importer::STATUS_KEY ] : array();
		}

		if ( in_array( (string) ( $current['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true ) ) {
			return array(
				'state'   => 'error',
				'message' => __( 'A product export is already running.', 'schrack-woocommerce-sync' ),
			);
		}

		if ( in_array( (string) ( $import['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true ) ) {
			return array(
				'state'   => 'error',
				'message' => __( 'A product import is running. Wait for it to finish before exporting.', 'schrack-woocommerce-sync' ),
			);
		}

		$dir       = $this->export_directory( true );
		$export_id = sanitize_key( wp_generate_uuid4() );

		if ( null === $dir || '' === $export_id ) {
			return array(
				'state'   => 'error',
				'message' => __( 'The private export directory could not be created.', 'schrack-woocommerce-sync' ),
			);
		}

		$this->cleanup_old_files( $dir );
		$this->delete_status_files( $current );

		$basename  = sprintf( 'schrack-products-full-%1$s-%2$s', gmdate( 'Y-m-d-His' ), str_replace( '-', '', $export_id ) );
		$path      = trailingslashit( $dir ) . $basename . '.csv';
		$rows_path = trailingslashit( $dir ) . $basename . '.rows';
		$handle    = fopen( $rows_path, 'xb' );

		if ( false === $handle ) {
			return array(
				'state'   => 'error',
				'message' => __( 'The product export work file could not be created.', 'schrack-woocommerce-sync' ),
			);
		}

		fclose( $handle );
		@chmod( $rows_path, 0660 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		try {
			$snapshot = $this->snapshot();
			$adapter  = $this->new_adapter();
		} catch ( Throwable $exception ) {
			wp_delete_file( $rows_path );

			return array(
				'state'   => 'error',
				'message' => $exception->getMessage(),
			);
		}

		$now    = time();
		$status = array_merge(
			array(
				'state'               => 'queued',
				'export_id'           => $export_id,
				'file'                => $path,
				'rows_file'           => $rows_path,
				'file_name'           => basename( $path ),
				'bytes'               => 0,
				'work_bytes'          => 0,
				'total'               => $snapshot['total'],
				'max_id'              => $snapshot['max_id'],
				'last_id'             => 0,
				'processed'           => 0,
				'rows'                => 0,
				'errors'              => 0,
				'batch_size'          => Schrack_Memory_Guard::export_batch_size(),
				'column_names'        => $adapter->get_column_names(),
				'format'              => 'woocommerce-product-csv',
				'includes_meta'       => 'yes',
				'includes_variations' => 'yes',
				'started_at'          => $now,
				'last_progress_at'    => $now,
			),
			Schrack_Memory_Guard::diagnostics()
		);

		$this->save_status( $status );

		if ( 0 === $snapshot['total'] ) {
			try {
				$this->finish_export( $status );
			} catch ( Throwable $exception ) {
				$status['state']   = 'error';
				$status['message'] = $exception->getMessage();
				$this->save_status( $status );
			}

			return $this->status();
		}

		if ( ! $this->enqueue_batch( $export_id ) ) {
			$status['state']   = 'error';
			$status['message'] = __( 'The first background export batch could not be queued. Check Action Scheduler or WP-Cron.', 'schrack-woocommerce-sync' );
			$this->save_status( $status );
		}

		$this->logger->info(
			'export',
			'Queued complete WooCommerce product CSV export.',
			null,
			array(
				'export_id' => $export_id,
				'total'     => $snapshot['total'],
			)
		);

		return $this->status();
	}

	/**
	 * Processes one stable ID range and schedules the next range.
	 */
	public function run_batch( string $export_id ): void {
		$export_id = sanitize_key( $export_id );

		if ( '' === $export_id || ! $this->acquire_lock( $export_id ) ) {
			return;
		}

		try {
			$this->run_locked_batch( $export_id );
		} catch ( Throwable $exception ) {
			$status = $this->status();

			if ( $export_id === (string) ( $status['export_id'] ?? '' ) ) {
				$status['state']            = 'error';
				$status['message']          = $exception->getMessage();
				$status['last_progress_at'] = time();
				$status                     = array_merge( $status, Schrack_Memory_Guard::diagnostics() );
				$this->save_status( $status );
			}

			$this->logger->error(
				'export',
				'Complete WooCommerce product CSV export failed.',
				null,
				array(
					'export_id' => $export_id,
					'error'     => $exception->getMessage(),
				)
			);
		} finally {
			$this->release_lock( $export_id );
		}
	}

	/**
	 * Streams a completed export without loading the CSV into memory.
	 */
	public function stream_download( string $export_id ): bool {
		$status    = $this->status();
		$export_id = sanitize_key( $export_id );
		$path      = isset( $status['file'] ) ? (string) $status['file'] : '';

		if (
			'done' !== (string) ( $status['state'] ?? '' ) ||
			$export_id !== (string) ( $status['export_id'] ?? '' ) ||
			! $this->is_valid_private_file( $path, array( 'csv' ) )
		) {
			return false;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$filename = sanitize_file_name( (string) ( $status['file_name'] ?? basename( $path ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1024 * 1024 );

			if ( false === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		fclose( $handle );

		return true;
	}

	/**
	 * Returns a validated completed export for direct background import.
	 *
	 * @return array{path:string,name:string,bytes:int}|null
	 */
	public function completed_file( string $export_id ): ?array {
		$status    = $this->status();
		$export_id = sanitize_key( $export_id );
		$path      = isset( $status['file'] ) ? (string) $status['file'] : '';

		if (
			'done' !== (string) ( $status['state'] ?? '' ) ||
			$export_id !== (string) ( $status['export_id'] ?? '' ) ||
			! $this->is_valid_private_file( $path, array( 'csv' ) )
		) {
			return null;
		}

		return array(
			'path'  => $path,
			'name'  => sanitize_file_name( (string) ( $status['file_name'] ?? basename( $path ) ) ),
			'bytes' => (int) filesize( $path ),
		);
	}

	/**
	 * Cancels the active job and removes its private files.
	 */
	public function reset(): void {
		$status    = $this->status();
		$export_id = sanitize_key( (string) ( $status['export_id'] ?? '' ) );

		if ( '' !== $export_id ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, array( $export_id ), self::GROUP );
			}

			wp_clear_scheduled_hook( self::HOOK, array( $export_id ) );
			$this->force_release_lock( $export_id );
		}

		$this->delete_status_files( $status );
		$this->save_status( array( 'state' => 'idle' ) );
	}

	/**
	 * Requeues a stale or failed export from its last durable checkpoint.
	 *
	 * @return array<string,mixed>
	 */
	public function resume(): array {
		$status    = $this->status();
		$state     = (string) ( $status['state'] ?? '' );
		$export_id = sanitize_key( (string) ( $status['export_id'] ?? '' ) );
		$rows_path = isset( $status['rows_file'] ) ? (string) $status['rows_file'] : '';
		$import    = get_option( Schrack_Product_Importer::STATUS_OPTION, array() );

		if ( is_array( $import ) && in_array( (string) ( $import['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true ) ) {
			return array( 'state' => 'error', 'message' => __( 'A product import is running. Wait for it to finish before resuming the export.', 'schrack-woocommerce-sync' ) );
		}

		if ( '' === $export_id || ! in_array( $state, array( 'queued', 'running', 'finalizing', 'error' ), true ) ) {
			return array( 'state' => 'error', 'message' => __( 'There is no resumable product export.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! $this->is_valid_private_file( $rows_path, array( 'rows' ) ) ) {
			return array( 'state' => 'error', 'message' => __( 'The product export work file is no longer available.', 'schrack-woocommerce-sync' ) );
		}

		$locked_at = absint( get_option( $this->lock_key( $export_id ), 0 ) );

		if ( $locked_at >= time() - 10 * MINUTE_IN_SECONDS ) {
			return array( 'state' => 'error', 'message' => __( 'An export batch is still running. Wait before retrying it.', 'schrack-woocommerce-sync' ) );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $export_id ), self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK, array( $export_id ) );
		$this->force_release_lock( $export_id );

		$status['state'] = 'finalizing' === $state || absint( $status['last_id'] ?? 0 ) >= absint( $status['max_id'] ?? 0 )
			? 'finalizing'
			: 'queued';
		$status['last_progress_at'] = time();
		unset( $status['message'] );
		$this->save_status( $status );

		if ( ! $this->enqueue_batch( $export_id ) ) {
			$status['state']   = 'error';
			$status['message'] = __( 'The product export retry could not be queued.', 'schrack-woocommerce-sync' );
			$this->save_status( $status );
		}

		return $this->status();
	}

	/**
	 * Converts structured metadata to a reversible scalar value for Woo CSV.
	 */
	public function encode_structured_meta( mixed $value ): mixed {
		if (
			is_string( $value ) &&
			( str_starts_with( $value, self::STRUCTURED_META_PREFIX ) || str_starts_with( $value, self::ESCAPED_STRING_PREFIX ) )
		) {
			return self::ESCAPED_STRING_PREFIX . base64_encode( $value );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		return is_string( $json ) ? self::STRUCTURED_META_PREFIX . base64_encode( $json ) : '';
	}

	/**
	 * Excludes short-lived locks and importer bookkeeping from a restore file.
	 *
	 * @param array<int,string> $keys Existing skipped meta keys.
	 * @return array<int,string>
	 */
	public function skip_transient_meta_keys( array $keys ): array {
		return array_values(
			array_unique(
				array_merge(
					$keys,
					array(
						'_original_id',
						'_schrack_product_import_job',
						'_schrack_image_sync_claim',
						'_schrack_image_sync_claimed_at',
					)
				)
			)
		);
	}

	/**
	 * Runs one export batch after acquiring the per-job lock.
	 */
	private function run_locked_batch( string $export_id ): void {
		$status = $this->status();

		if (
			$export_id !== (string) ( $status['export_id'] ?? '' ) ||
			! in_array( (string) ( $status['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true )
		) {
			return;
		}

		if ( 'finalizing' === (string) $status['state'] ) {
			$this->finish_export( $status );
			return;
		}

		$rows_path = isset( $status['rows_file'] ) ? (string) $status['rows_file'] : '';

		if ( ! $this->is_valid_private_file( $rows_path, array( 'rows' ) ) || ! is_writable( $rows_path ) ) {
			throw new RuntimeException( __( 'The product export work file is missing or is not writable.', 'schrack-woocommerce-sync' ) );
		}

		$status['state']            = 'running';
		$status['last_progress_at'] = time();
		$this->save_status( $status );

		$batch_size = max( 1, min( self::BATCH_SIZE, absint( $status['batch_size'] ?? Schrack_Memory_Guard::export_batch_size() ) ) );
		$ids        = $this->product_ids(
			absint( $status['last_id'] ?? 0 ),
			absint( $status['max_id'] ?? 0 ),
			$batch_size
		);

		if ( empty( $ids ) ) {
			$this->finish_export( $status );
			return;
		}

		$adapter = $this->new_adapter();
		$columns = isset( $status['column_names'] ) && is_array( $status['column_names'] ) ? $status['column_names'] : array();

		if ( ! empty( $columns ) ) {
			$adapter->set_column_names( $columns );
		}

		$checkpoint = absint( $status['work_bytes'] ?? 0 );
		$handle     = fopen( $rows_path, 'c+b' );

		if ( false === $handle ) {
			throw new RuntimeException( __( 'The product export work file could not be opened.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! flock( $handle, LOCK_EX ) ) {
			fclose( $handle );
			throw new RuntimeException( __( 'The product export work file could not be locked for writing.', 'schrack-woocommerce-sync' ) );
		}

		$actual_size = fstat( $handle );
		$actual_size = is_array( $actual_size ) ? absint( $actual_size['size'] ?? 0 ) : -1;

		if ( $actual_size < $checkpoint ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			throw new RuntimeException( __( 'The product export work file is shorter than its last saved checkpoint.', 'schrack-woocommerce-sync' ) );
		}

		if ( $actual_size > $checkpoint && ! ftruncate( $handle, $checkpoint ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			throw new RuntimeException( __( 'An incomplete product export batch could not be rolled back safely.', 'schrack-woocommerce-sync' ) );
		}

		if ( 0 !== fseek( $handle, $checkpoint, SEEK_SET ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			throw new RuntimeException( __( 'The product export work file checkpoint could not be restored.', 'schrack-woocommerce-sync' ) );
		}

		$written         = 0;
		$errors          = 0;
		$attempted       = 0;
		$last_product_id = absint( $status['last_id'] ?? 0 );
		$memory_yielded  = false;
		add_filter( 'woocommerce_product_export_meta_value', array( $this, 'encode_structured_meta' ), 10, 1 );
		add_filter( 'woocommerce_product_export_skip_meta_keys', array( $this, 'skip_transient_meta_keys' ), 20, 1 );

		try {
			foreach ( $ids as $product_id ) {
				if ( $attempted > 0 && Schrack_Memory_Guard::is_pressure_high() ) {
					$memory_yielded = true;
					break;
				}

				++$attempted;
				$last_product_id = $product_id;

				try {
					try {
						$product = wc_get_product( $product_id );

						if ( ! $product instanceof WC_Product ) {
							throw new RuntimeException( __( 'WooCommerce could not load the product.', 'schrack-woocommerce-sync' ) );
						}

						$row     = $adapter->schrack_product_row( $product );
						$columns = $adapter->get_column_names();
						$values  = array();

						foreach ( $columns as $column_id => $column_name ) {
							$values[] = $adapter->format_data( $row[ $column_id ] ?? '' );
						}
					} catch ( Throwable $exception ) {
						++$errors;
						$this->logger->warning(
							'export',
							'Skipped a product during complete WooCommerce CSV export.',
							null,
							array(
								'export_id' => $export_id,
								'product_id' => $product_id,
								'error'      => $exception->getMessage(),
							)
						);

						continue;
					}

					// File write failures must stop the job; skipping them would create an incomplete backup.
					$this->write_csv_row( $handle, $values );
					++$written;
				} finally {
					unset( $product, $row, $values );
					Schrack_Memory_Guard::forget_product( $product_id );
				}
			}
		} finally {
			remove_filter( 'woocommerce_product_export_meta_value', array( $this, 'encode_structured_meta' ), 10 );
			remove_filter( 'woocommerce_product_export_skip_meta_keys', array( $this, 'skip_transient_meta_keys' ), 20 );
		}

		if ( ! fflush( $handle ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			throw new RuntimeException( __( 'The product export work file could not be flushed to disk.', 'schrack-woocommerce-sync' ) );
		}

		flock( $handle, LOCK_UN );
		fclose( $handle );
		Schrack_Memory_Guard::release_runtime_memory();

		$latest = $this->status();

		if ( $export_id !== (string) ( $latest['export_id'] ?? '' ) ) {
			return;
		}

		$status                     = array_merge( $status, $latest, Schrack_Memory_Guard::diagnostics() );
		$status['column_names']     = $adapter->get_column_names();
		$status['last_id']          = $last_product_id;
		$status['processed']        = absint( $status['processed'] ?? 0 ) + $attempted;
		$status['rows']             = absint( $status['rows'] ?? 0 ) + $written;
		$status['errors']           = absint( $status['errors'] ?? 0 ) + $errors;
		$status['memory_yields']    = absint( $status['memory_yields'] ?? 0 ) + ( $memory_yielded ? 1 : 0 );
		clearstatcache( true, $rows_path );
		$status['work_bytes']       = (int) filesize( $rows_path );
		$status['last_progress_at'] = time();
		$is_complete                = absint( $status['last_id'] ) >= absint( $status['max_id'] ?? 0 ) || ( ! $memory_yielded && $attempted === count( $ids ) && count( $ids ) < $batch_size );

		if ( $is_complete ) {
			$status['state']                = 'finalizing';
			$status['finalize_position']    = 0;
			$status['finalize_bytes']       = 0;
			$status['finalize_total_bytes'] = absint( $status['work_bytes'] ?? 0 );
			$status['last_progress_at']     = time();
			$this->save_status( $status );
			$this->release_lock( $export_id );

			if ( ! $this->enqueue_batch( $export_id ) ) {
				$status['state']   = 'error';
				$status['message'] = __( 'The final product CSV assembly could not be queued.', 'schrack-woocommerce-sync' );
				$this->save_status( $status );
			}
			return;
		}

		$this->save_status( $status );
		$this->release_lock( $export_id );

		if ( ! $this->enqueue_batch( $export_id ) ) {
			$status['state']   = 'error';
			$status['message'] = __( 'The next background export batch could not be queued.', 'schrack-woocommerce-sync' );
			$this->save_status( $status );
		}
	}

	/**
	 * Adds the final dynamic header and resumes a bounded CSV assembly chunk.
	 *
	 * @param array<string,mixed> $status Export status.
	 */
	private function finish_export( array $status ): void {
		$export_id         = sanitize_key( (string) ( $status['export_id'] ?? '' ) );
		$path              = isset( $status['file'] ) ? (string) $status['file'] : '';
		$rows_path         = isset( $status['rows_file'] ) ? (string) $status['rows_file'] : '';
		$columns           = isset( $status['column_names'] ) && is_array( $status['column_names'] ) ? $status['column_names'] : array();
		$source_position   = absint( $status['finalize_position'] ?? 0 );
		$target_checkpoint = absint( $status['finalize_bytes'] ?? 0 );

		if (
			'' === $export_id ||
			! $this->is_valid_private_file( $rows_path, array( 'rows' ) ) ||
			empty( $columns ) ||
			! $this->is_valid_target_path( $path, 'csv' ) ||
			( file_exists( $path ) && ! $this->is_valid_private_file( $path, array( 'csv' ) ) )
		) {
			throw new RuntimeException( __( 'The complete product CSV could not be finalized.', 'schrack-woocommerce-sync' ) );
		}

		clearstatcache( true, $rows_path );
		$source_size = filesize( $rows_path );

		if ( false === $source_size ) {
			throw new RuntimeException( __( 'The product CSV rows file size could not be read.', 'schrack-woocommerce-sync' ) );
		}

		if ( $source_position > $source_size || ( $source_position > 0 && 0 === $target_checkpoint ) ) {
			throw new RuntimeException( __( 'The product CSV finalization checkpoint is invalid.', 'schrack-woocommerce-sync' ) );
		}

		$free_space      = function_exists( 'disk_free_space' ) ? disk_free_space( dirname( $path ) ) : false;
		$remaining_bytes = max( 0, $source_size - $source_position );

		if ( false !== $free_space && $free_space < $remaining_bytes + 16 * MB_IN_BYTES ) {
			throw new RuntimeException( __( 'There is not enough free disk space to assemble the final product CSV. Free space and resume the export.', 'schrack-woocommerce-sync' ) );
		}

		$output = fopen( $path, 'c+b' );
		$input  = fopen( $rows_path, 'rb' );

		if ( false === $output || false === $input ) {
			if ( is_resource( $output ) ) {
				fclose( $output );
			}
			if ( is_resource( $input ) ) {
				fclose( $input );
			}
			throw new RuntimeException( __( 'The complete product CSV could not be opened for finalization.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! flock( $output, LOCK_EX ) ) {
			fclose( $input );
			fclose( $output );
			throw new RuntimeException( __( 'The product CSV final file could not be locked.', 'schrack-woocommerce-sync' ) );
		}

		$next_source_position = $source_position;
		$next_target_position = $target_checkpoint;

		try {
			$target_stat = fstat( $output );

			if ( ! is_array( $target_stat ) || ! isset( $target_stat['size'] ) ) {
				throw new RuntimeException( __( 'The final product CSV size could not be read.', 'schrack-woocommerce-sync' ) );
			}

			$target_size = absint( $target_stat['size'] );

			if ( $target_size < $target_checkpoint ) {
				throw new RuntimeException( __( 'The final product CSV is shorter than its saved checkpoint.', 'schrack-woocommerce-sync' ) );
			}

			if ( $target_size > $target_checkpoint && ! ftruncate( $output, $target_checkpoint ) ) {
				throw new RuntimeException( __( 'An incomplete final product CSV could not be rolled back.', 'schrack-woocommerce-sync' ) );
			}

			if ( 0 !== fseek( $output, $target_checkpoint, SEEK_SET ) ) {
				throw new RuntimeException( __( 'The final product CSV checkpoint could not be restored.', 'schrack-woocommerce-sync' ) );
			}

			if ( 0 === $source_position && 0 === $target_checkpoint ) {
				if ( ! ftruncate( $output, 0 ) || 0 !== fseek( $output, 0, SEEK_SET ) ) {
					throw new RuntimeException( __( 'The final product CSV could not be initialized.', 'schrack-woocommerce-sync' ) );
				}

				$this->write_all( $output, "\xEF\xBB\xBF" );
				$this->write_csv_row( $output, array_values( $columns ) );
			}

			if ( 0 !== fseek( $input, $source_position, SEEK_SET ) ) {
				throw new RuntimeException( __( 'The product CSV rows checkpoint could not be restored.', 'schrack-woocommerce-sync' ) );
			}

			$copied     = 0;
			$started_at = microtime( true );

			while (
				ftell( $input ) < $source_size &&
				$copied < self::FINALIZE_COPY_BUDGET &&
				microtime( true ) - $started_at < self::FINALIZE_TIME_BUDGET
			) {
				$remaining = min( self::FINALIZE_CHUNK_SIZE, self::FINALIZE_COPY_BUDGET - $copied, $source_size - (int) ftell( $input ) );
				$chunk     = fread( $input, $remaining );

				if ( false === $chunk || '' === $chunk ) {
					throw new RuntimeException( __( 'The product CSV rows could not be copied into the final file.', 'schrack-woocommerce-sync' ) );
				}

				$this->write_all( $output, $chunk );
				$copied += strlen( $chunk );
			}

			if ( ! fflush( $output ) ) {
				throw new RuntimeException( __( 'The completed product CSV could not be flushed to disk.', 'schrack-woocommerce-sync' ) );
			}

			$next_source_position = (int) ftell( $input );
			$next_target_position = (int) ftell( $output );
		} finally {
			flock( $output, LOCK_UN );
			fclose( $input );
			fclose( $output );
		}

		@chmod( $path, 0660 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		Schrack_Memory_Guard::release_runtime_memory();

		$latest = $this->status();

		if (
			$export_id !== (string) ( $latest['export_id'] ?? '' ) ||
			! in_array( (string) ( $latest['state'] ?? '' ), array( 'queued', 'running', 'finalizing' ), true )
		) {
			wp_delete_file( $path );
			return;
		}

		$status                         = array_merge( $status, $latest, Schrack_Memory_Guard::diagnostics() );
		$status['state']                = 'finalizing';
		$status['finalize_position']    = $next_source_position;
		$status['finalize_bytes']       = $next_target_position;
		$status['finalize_total_bytes'] = $source_size;
		$status['bytes']                = $next_target_position;
		$status['last_progress_at']     = time();

		if ( $next_source_position < $source_size ) {
			$this->save_status( $status );
			$this->release_lock( $export_id );

			if ( ! $this->enqueue_batch( $export_id ) ) {
				$status['state']   = 'error';
				$status['message'] = __( 'The next final product CSV assembly batch could not be queued.', 'schrack-woocommerce-sync' );
				$this->save_status( $status );
			}
			return;
		}

		$status['state']            = 'done';
		$status['finished_at']      = time();
		$status['last_progress_at'] = time();
		clearstatcache( true, $path );
		$status['bytes']            = (int) filesize( $path );
		unset( $status['rows_file'], $status['work_bytes'], $status['finalize_position'], $status['finalize_bytes'], $status['finalize_total_bytes'] );
		$this->save_status( $status );
		wp_delete_file( $rows_path );

		$this->logger->info(
			'export',
			'Finished complete WooCommerce product CSV export.',
			null,
			array(
				'export_id' => $status['export_id'] ?? '',
				'rows'      => $status['rows'] ?? 0,
				'errors'    => $status['errors'] ?? 0,
				'bytes'     => $status['bytes'] ?? 0,
				'columns'   => count( $columns ),
			)
		);
	}

	/**
	 * Returns a configured adapter that exports every Woo field and custom meta.
	 */
	private function new_adapter(): Schrack_WC_Product_CSV_Exporter {
		$adapter = new Schrack_WC_Product_CSV_Exporter();
		$adapter->enable_meta_export( true );
		return $adapter;
	}

	/**
	 * Returns a stable count and highest eligible product ID.
	 *
	 * @return array{total:int,max_id:int}
	 */
	private function snapshot(): array {
		global $wpdb;

		$where = "p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft', 'importing')";
		$sql   = "SELECT COUNT(*) AS total, COALESCE(MAX(p.ID), 0) AS max_id FROM {$wpdb->posts} p WHERE {$where}";
		$row   = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			throw new RuntimeException( __( 'The product export snapshot could not be read from the database.', 'schrack-woocommerce-sync' ) );
		}

		return array(
			'total'  => absint( $row['total'] ?? 0 ),
			'max_id' => absint( $row['max_id'] ?? 0 ),
		);
	}

	/**
	 * Returns the next product IDs using keyset pagination.
	 *
	 * @return array<int,int>
	 */
	private function product_ids( int $last_id, int $max_id, int $limit ): array {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status NOT IN ('trash', 'auto-draft', 'importing')
			AND p.ID > %d AND p.ID <= %d
			ORDER BY p.ID ASC LIMIT %d",
			max( 0, $last_id ),
			max( 0, $max_id ),
			max( 1, $limit )
		);
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( __( 'The next product export batch could not be read from the database.', 'schrack-woocommerce-sync' ) );
		}

		return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * Writes one RFC-4180-compatible CSV row.
	 *
	 * @param resource         $handle File handle.
	 * @param array<int,mixed> $row CSV row.
	 */
	private function write_csv_row( $handle, array $row ): void {
		if ( false === fputcsv( $handle, $row, ',', '"', "\0" ) ) {
			throw new RuntimeException( __( 'A row could not be written to the product export file.', 'schrack-woocommerce-sync' ) );
		}
	}

	/**
	 * Writes a complete binary chunk, including when fwrite() performs a partial write.
	 *
	 * @param resource $handle File handle.
	 */
	private function write_all( $handle, string $data ): void {
		$length = strlen( $data );
		$offset = 0;

		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $data, $offset ) );

			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( __( 'A binary chunk could not be written to the product export file.', 'schrack-woocommerce-sync' ) );
			}

			$offset += $written;
		}
	}

	/**
	 * Queues one background batch through Action Scheduler or WP-Cron.
	 */
	private function enqueue_batch( string $export_id ): bool {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return absint( as_enqueue_async_action( self::HOOK, array( $export_id ), self::GROUP ) ) > 0;
		}

		return false !== wp_schedule_single_event( time() + 5, self::HOOK, array( $export_id ) );
	}

	/**
	 * Creates and protects the private export directory.
	 */
	private function export_directory( bool $create ): ?string {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$dir = trailingslashit( (string) $uploads['basedir'] ) . self::EXPORT_DIRECTORY;

		if ( $create && ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		if ( ! is_dir( $dir ) ) {
			return null;
		}

		if ( $create ) {
			$this->protect_export_directory( $dir );
		}

		return $dir;
	}

	/**
	 * Adds common Apache, IIS, and directory-index protections.
	 */
	private function protect_export_directory( string $dir ): void {
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
	 * Validates an existing file inside the dedicated private directory.
	 *
	 * @param array<int,string> $extensions Allowed extensions.
	 */
	private function is_valid_private_file( string $path, array $extensions ): bool {
		$dir = $this->export_directory( false );

		if ( null === $dir || '' === $path || ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), $extensions, true ) ) {
			return false;
		}

		$real_dir  = realpath( $dir );
		$real_path = realpath( $path );

		return is_string( $real_dir ) && is_string( $real_path ) && str_starts_with( $real_path, trailingslashit( $real_dir ) ) && is_file( $real_path );
	}

	/**
	 * Validates a not-yet-created target path inside the private directory.
	 */
	private function is_valid_target_path( string $path, string $extension ): bool {
		$dir         = $this->export_directory( false );
		$real_dir    = null !== $dir ? realpath( $dir ) : false;
		$real_parent = '' !== $path ? realpath( dirname( $path ) ) : false;

		return is_string( $real_dir ) && is_string( $real_parent ) && $real_parent === $real_dir && strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === $extension;
	}

	/**
	 * Deletes files referenced by a validated status row.
	 *
	 * @param array<string,mixed> $status Export status.
	 */
	private function delete_status_files( array $status ): void {
		foreach ( array( 'file' => array( 'csv' ), 'rows_file' => array( 'rows' ) ) as $key => $extensions ) {
			$path = isset( $status[ $key ] ) ? (string) $status[ $key ] : '';

			if ( $this->is_valid_private_file( $path, $extensions ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Removes abandoned private product CSV work/output files after seven days.
	 */
	private function cleanup_old_files( string $dir ): void {
		foreach ( array( 'schrack-products-*.csv', 'schrack-products-*.rows' ) as $pattern ) {
			$files = glob( trailingslashit( $dir ) . $pattern );

			foreach ( is_array( $files ) ? $files : array() as $path ) {
				if ( is_file( $path ) && filemtime( $path ) < time() - self::FILE_MAX_AGE ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	/**
	 * Saves export progress separately from high-frequency supplier sync status.
	 *
	 * @param array<string,mixed> $status Export status.
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
	 * Uses an atomic option insert to prevent duplicate concurrent batches.
	 */
	private function acquire_lock( string $export_id ): bool {
		$key   = $this->lock_key( $export_id );
		$token = time() . ':' . wp_generate_uuid4();

		if ( add_option( $key, $token, '', false ) ) {
			$this->lock_tokens[ $export_id ] = $token;
			return true;
		}

		$current   = (string) get_option( $key, '' );
		$locked_at = absint( strtok( $current, ':' ) );

		if ( $locked_at > 0 && $locked_at >= time() - 10 * MINUTE_IN_SECONDS ) {
			return false;
		}

		delete_option( $key );

		if ( add_option( $key, $token, '', false ) ) {
			$this->lock_tokens[ $export_id ] = $token;
			return true;
		}

		return false;
	}

	/**
	 * Releases a per-export batch lock.
	 */
	private function release_lock( string $export_id ): void {
		$token = $this->lock_tokens[ $export_id ] ?? '';

		if ( '' !== $token && hash_equals( $token, (string) get_option( $this->lock_key( $export_id ), '' ) ) ) {
			delete_option( $this->lock_key( $export_id ) );
		}

		unset( $this->lock_tokens[ $export_id ] );
	}

	/**
	 * Clears a lock only for explicit reset or a verified stale-job retry.
	 */
	private function force_release_lock( string $export_id ): void {
		delete_option( $this->lock_key( $export_id ) );
		unset( $this->lock_tokens[ $export_id ] );
	}

	private function lock_key( string $export_id ): string {
		return 'schrack_wc_product_export_lock_' . md5( $export_id );
	}
}
