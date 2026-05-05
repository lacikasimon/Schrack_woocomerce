<?php
/**
 * Catalog import orchestration.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Catalog_Importer {
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
	 * SOAP client.
	 *
	 * @var Schrack_Soap_Client
	 */
	private Schrack_Soap_Client $client;

	/**
	 * Product mapper.
	 *
	 * @var Schrack_Product_Mapper
	 */
	private Schrack_Product_Mapper $mapper;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, ?Schrack_Soap_Client $client = null, ?Schrack_Product_Mapper $mapper = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->client   = $client ?: new Schrack_Soap_Client( $settings, $logger );
		$this->mapper   = $mapper ?: new Schrack_Product_Mapper( $settings, $logger );
	}

	/**
	 * Imports catalog data from Schrack SOAP.
	 *
	 * @param string $format CSV, XML, or DATANORM.
	 * @param int    $limit Batch limit.
	 * @return array<string,mixed>
	 */
	public function import_from_soap( string $format = 'CSV', int $limit = 25 ): array {
		$format = strtoupper( $format );
		$limit  = max( 1, $limit );

		$cache = $this->active_catalog_cache( $format );

		if ( null !== $cache ) {
			return $this->import_cached_items_with_cursor( $cache, $format, $limit );
		}

		$raw = $this->client->get_catalog_as( $format );

		$streamed_result = $this->import_streamed_catalog_response( $raw, $format, $limit );

		if ( null !== $streamed_result ) {
			return $streamed_result;
		}

		$items     = $this->parse_catalog_response( $raw, $format );
		$signature = $this->catalog_signature( $items );
		$context   = empty( $items )
			? array( 'catalog_cache' => 'empty' )
			: $this->write_catalog_cache( $format, $signature, $items );

		return $this->import_items_with_cursor( $items, $format, $limit, $context, $signature );
	}

	/**
	 * Imports normalized catalog rows.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $status_context Extra status fields.
	 * @return array<string,mixed>
	 */
	public function import_items( array $items, array $status_context = array() ): array {
		$processed             = 0;
		$errors                = 0;
		$image_urls_seen       = 0;
		$image_urls_stored     = 0;
		$image_urls_backfilled = 0;
		$image_url_meta_errors = 0;

		$this->mapper->prime_product_ids_by_skus( $this->item_skus( $items ) );

		foreach ( $items as $item ) {
			try {
				$product_id = $this->mapper->upsert( $item );
				++$processed;

				$image_url = $this->item_image_url( $item );

				if ( '' !== $image_url ) {
					++$image_urls_seen;

					$meta_status = $this->ensure_product_image_url_meta( $product_id, $image_url, $this->item_sku( $item ) );

					if ( in_array( $meta_status, array( 'verified', 'backfilled' ), true ) ) {
						++$image_urls_stored;
					}

					if ( 'backfilled' === $meta_status ) {
						++$image_urls_backfilled;
					} elseif ( 'failed' === $meta_status ) {
						++$image_url_meta_errors;
					}
				}
			} catch ( Throwable $exception ) {
				++$errors;
				$this->logger->error(
					'catalog',
					'Failed to import Schrack catalog item.',
					$this->item_sku( $item ),
					array( 'error' => $exception->getMessage() )
				);
			}
		}

		$result = array_merge(
			array(
				'processed'             => $processed,
				'errors'                => $errors,
				'image_urls_seen'       => $image_urls_seen,
				'image_urls_stored'     => $image_urls_stored,
				'image_urls_backfilled' => $image_urls_backfilled,
				'image_url_meta_errors' => $image_url_meta_errors,
			),
			$status_context
		);

		$this->settings->update_status(
			'catalog',
			$result
		);

		return $result;
	}

	/**
	 * Ensures the catalog image URL is actually persisted on the WooCommerce product.
	 */
	private function ensure_product_image_url_meta( int $product_id, string $image_url, ?string $sku = null ): string {
		if ( $product_id <= 0 || '' === $image_url ) {
			return 'failed';
		}

		$current_url = $this->normalize_catalog_url( (string) get_post_meta( $product_id, '_schrack_image_url', true ) );

		if ( $current_url === $image_url ) {
			return 'verified';
		}

		update_post_meta( $product_id, '_schrack_image_url', $image_url );
		update_post_meta( $product_id, '_schrack_image_status', 'pending' );
		delete_post_meta( $product_id, '_schrack_image_error' );

		$stored_url = $this->normalize_catalog_url( (string) get_post_meta( $product_id, '_schrack_image_url', true ) );

		if ( $stored_url === $image_url ) {
			$this->logger->debug(
				'catalog',
				'Backfilled missing Schrack image URL meta during catalog sync.',
				$sku,
				array(
					'product_id' => $product_id,
					'image_url'  => $image_url,
				)
			);

			return 'backfilled';
		}

		$this->logger->warning(
			'catalog',
			'Could not verify Schrack image URL meta after catalog sync.',
			$sku,
			array(
				'product_id'   => $product_id,
				'expected_url' => $image_url,
				'stored_url'   => $stored_url,
			)
		);

		return 'failed';
	}

	/**
	 * Imports a catalog batch and advances the persisted cursor.
	 *
	 * @param array<int,array<string,mixed>> $items Parsed catalog items.
	 * @return array<string,mixed>
	 */
	private function import_items_with_cursor( array $items, string $format, int $limit, array $status_context = array(), ?string $signature = null ): array {
		$total_items = count( $items );
		$format      = strtoupper( $format );

		if ( 0 === $total_items ) {
			return $this->import_items(
				array(),
				array(
					'cursor'          => 0,
					'total_items'     => 0,
					'batch_start'     => 0,
					'batch_count'     => 0,
					'batch_limit'     => $limit,
					'completed_cycle' => 'yes',
					'catalog_format'  => $format,
				)
			);
		}

		$signature = $signature ?: $this->catalog_signature( $items );
		$offset    = $this->catalog_cursor( $format, $signature, $total_items );
		$batch     = array_slice( $items, $offset, $limit );

		if ( empty( $batch ) && $offset > 0 ) {
			$offset = 0;
			$batch  = array_slice( $items, 0, $limit );
		}

		$batch_count     = count( $batch );
		$next_cursor     = $offset + $batch_count;
		$completed_cycle = $next_cursor >= $total_items;

		if ( $completed_cycle ) {
			$next_cursor = 0;
			$this->delete_catalog_cache( $format, $signature );
		}

		return $this->import_items(
			$batch,
			array_merge(
				$status_context,
				array(
					'cursor'            => $next_cursor,
					'total_items'       => $total_items,
					'batch_start'       => $offset,
					'batch_count'       => $batch_count,
					'batch_limit'       => $limit,
					'completed_cycle'   => $completed_cycle ? 'yes' : 'no',
					'catalog_format'    => $format,
					'catalog_signature' => $signature,
				)
			)
		);
	}

	/**
	 * Imports one batch from the parsed catalog cache.
	 *
	 * @param array<string,mixed> $cache Catalog cache metadata.
	 * @return array<string,mixed>
	 */
	private function import_cached_items_with_cursor( array $cache, string $format, int $limit, array $status_context = array() ): array {
		$signature   = (string) $cache['signature'];
		$total_items = absint( $cache['total_items'] ?? 0 );

		if ( 0 === $total_items ) {
			$this->delete_catalog_cache( $format, $signature );
			return $this->import_items_with_cursor( array(), $format, $limit );
		}

		$offset = $this->catalog_cursor( $format, $signature, $total_items );
		$batch  = $this->read_catalog_cache_batch( (string) $cache['items_path'], $offset, $limit );

		if ( empty( $batch ) && $offset > 0 ) {
			$offset = 0;
			$batch  = $this->read_catalog_cache_batch( (string) $cache['items_path'], 0, $limit );
		}

		if ( empty( $batch ) && $offset < $total_items ) {
			$this->logger->warning(
				'catalog',
				'Parsed Schrack catalog cache was unreadable; refreshing from SOAP.',
				null,
				array(
					'catalog_cache_key' => $cache['key'] ?? '',
					'cursor'            => $offset,
					'total_items'       => $total_items,
				)
			);
			$this->delete_catalog_cache( $format, $signature );
			return $this->import_from_soap( $format, $limit );
		}

		$batch_count     = count( $batch );
		$next_cursor     = $offset + $batch_count;
		$completed_cycle = $next_cursor >= $total_items;

		if ( $completed_cycle ) {
			$next_cursor = 0;
			$this->delete_catalog_cache( $format, $signature );
		}

		return $this->import_items(
			$batch,
			array_merge(
				array(
					'catalog_cache'     => 'hit',
					'catalog_cache_key' => $cache['key'] ?? '',
					'cursor'            => $next_cursor,
					'total_items'       => $total_items,
					'batch_start'       => $offset,
					'batch_count'       => $batch_count,
					'batch_limit'       => $limit,
					'completed_cycle'   => $completed_cycle ? 'yes' : 'no',
					'catalog_format'    => $format,
					'catalog_signature' => $signature,
				),
				$status_context
			)
		);
	}

	/**
	 * Returns the stored catalog cursor, resetting it when the catalog changed.
	 */
	private function catalog_cursor( string $format, string $signature, int $total_items ): int {
		$status = $this->settings->get_status();
		$row    = isset( $status['catalog'] ) && is_array( $status['catalog'] ) ? $status['catalog'] : array();

		if (
			$format !== (string) ( $row['catalog_format'] ?? '' ) ||
			$signature !== (string) ( $row['catalog_signature'] ?? '' )
		) {
			return 0;
		}

		$cursor = absint( $row['cursor'] ?? 0 );

		return $cursor < $total_items ? $cursor : 0;
	}

	/**
	 * Builds a stable signature from the parsed SKU sequence.
	 *
	 * @param array<int,array<string,mixed>> $items Parsed catalog items.
	 */
	private function catalog_signature( array $items ): string {
		$context = hash_init( 'sha256' );

		foreach ( $items as $item ) {
			hash_update( $context, (string) $this->item_sku( $item ) . "\n" );
		}

		return hash_final( $context );
	}

	/**
	 * Returns the cache for an in-progress catalog import cycle.
	 *
	 * @return array<string,mixed>|null
	 */
	private function active_catalog_cache( string $format ): ?array {
		$status = $this->settings->get_status();
		$row    = isset( $status['catalog'] ) && is_array( $status['catalog'] ) ? $status['catalog'] : array();

		if (
			'no' !== (string) ( $row['completed_cycle'] ?? 'yes' ) ||
			$format !== (string) ( $row['catalog_format'] ?? '' ) ||
			'' === (string) ( $row['catalog_signature'] ?? '' ) ||
			absint( $row['cursor'] ?? 0 ) <= 0
		) {
			return null;
		}

		$cache = $this->catalog_cache_metadata( $format, (string) $row['catalog_signature'] );

		if ( null === $cache ) {
			return null;
		}

		$this->logger->debug(
			'catalog',
			'Using cached parsed Schrack catalog.',
			null,
			array(
				'catalog_cache_key' => $cache['key'] ?? '',
				'cursor'            => absint( $row['cursor'] ?? 0 ),
				'total_items'       => absint( $cache['total_items'] ?? 0 ),
			)
		);

		return $cache;
	}

	/**
	 * Writes parsed catalog items to a JSONL cache for the remaining batches.
	 *
	 * @param array<int,array<string,mixed>> $items Parsed catalog items.
	 * @return array<string,mixed>
	 */
	private function write_catalog_cache( string $format, string $signature, array $items ): array {
		$paths = $this->catalog_cache_paths( $format, $signature );

		if ( empty( $paths ) ) {
			return array( 'catalog_cache' => 'disabled' );
		}

		$this->cleanup_catalog_caches( $format, (string) $paths['key'] );

		$items_temp = (string) $paths['items_path'] . '.tmp';
		$meta_temp  = (string) $paths['meta_path'] . '.tmp';
		$handle     = fopen( $items_temp, 'wb' );

		if ( false === $handle ) {
			$this->logger->warning( 'catalog', 'Could not open parsed Schrack catalog cache for writing.' );
			return array( 'catalog_cache' => 'write_failed' );
		}

		$written = 0;

		foreach ( $items as $item ) {
			$encoded = wp_json_encode( $item );

			if ( ! is_string( $encoded ) ) {
				continue;
			}

			if ( false === fwrite( $handle, $encoded . "\n" ) ) {
				fclose( $handle );
				wp_delete_file( $items_temp );
				$this->logger->warning( 'catalog', 'Could not write parsed Schrack catalog cache.' );
				return array( 'catalog_cache' => 'write_failed' );
			}

			++$written;
		}

		fclose( $handle );

		$meta = array(
			'format'      => $format,
			'signature'   => $signature,
			'total_items' => $written,
			'created_at'  => time(),
			'key'         => $paths['key'],
		);

		$meta_json = wp_json_encode( $meta );

		if (
			! is_string( $meta_json ) ||
			false === file_put_contents( $meta_temp, $meta_json ) ||
			! rename( $items_temp, (string) $paths['items_path'] ) ||
			! rename( $meta_temp, (string) $paths['meta_path'] )
		) {
			wp_delete_file( $items_temp );
			wp_delete_file( $meta_temp );
			$this->logger->warning( 'catalog', 'Could not finalize parsed Schrack catalog cache.' );
			return array( 'catalog_cache' => 'write_failed' );
		}

		$this->logger->debug(
			'catalog',
			'Wrote parsed Schrack catalog cache.',
			null,
			array(
				'catalog_cache_key' => $paths['key'],
				'total_items'       => $written,
			)
		);

		return array(
			'catalog_cache'     => 'written',
			'catalog_cache_key' => $paths['key'],
		);
	}

	/**
	 * Reads one batch from a parsed catalog JSONL cache.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function read_catalog_cache_batch( string $path, int $offset, int $limit ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$items = array();

		try {
			$file = new SplFileObject( $path, 'r' );
			$file->seek( max( 0, $offset ) );

			while ( ! $file->eof() && count( $items ) < $limit ) {
				$line = trim( (string) $file->current() );
				$file->next();

				if ( '' === $line ) {
					continue;
				}

				$item = json_decode( $line, true );

				if ( is_array( $item ) && '' !== $this->item_sku( $item ) ) {
					$items[] = $item;
				}
			}
		} catch ( Throwable ) {
			return array();
		}

		return $items;
	}

	/**
	 * Returns parsed catalog cache metadata when cache files are present and valid.
	 *
	 * @return array<string,mixed>|null
	 */
	private function catalog_cache_metadata( string $format, string $signature ): ?array {
		$paths = $this->catalog_cache_paths( $format, $signature );

		if ( empty( $paths ) || ! is_readable( (string) $paths['items_path'] ) || ! is_readable( (string) $paths['meta_path'] ) ) {
			return null;
		}

		$meta_raw = file_get_contents( (string) $paths['meta_path'] );
		$meta     = is_string( $meta_raw ) ? json_decode( $meta_raw, true ) : null;

		if (
			! is_array( $meta ) ||
			$format !== (string) ( $meta['format'] ?? '' ) ||
			$signature !== (string) ( $meta['signature'] ?? '' ) ||
			absint( $meta['total_items'] ?? 0 ) <= 0
		) {
			return null;
		}

		return array_merge(
			$meta,
			array(
				'items_path' => $paths['items_path'],
				'meta_path'  => $paths['meta_path'],
				'key'        => $paths['key'],
			)
		);
	}

	/**
	 * Returns deterministic cache paths for a catalog signature.
	 *
	 * @return array<string,string>
	 */
	private function catalog_cache_paths( string $format, string $signature ): array {
		$dir = $this->catalog_cache_dir();

		if ( '' === $dir ) {
			return array();
		}

		$key  = strtolower( $format ) . '-' . substr( preg_replace( '/[^a-f0-9]/', '', strtolower( $signature ) ) ?? '', 0, 24 );
		$base = trailingslashit( $dir ) . 'catalog-' . $key;

		return array(
			'key'        => $key,
			'items_path' => $base . '.jsonl',
			'meta_path'  => $base . '.meta.json',
		);
	}

	/**
	 * Returns the plugin upload cache directory.
	 */
	private function catalog_cache_dir(): string {
		$upload = wp_upload_dir( null, false );

		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( (string) $upload['basedir'] ) . 'schrack-wc-sync';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		return $dir;
	}

	/**
	 * Deletes a parsed catalog cache.
	 */
	private function delete_catalog_cache( string $format, string $signature ): void {
		$paths = $this->catalog_cache_paths( $format, $signature );

		foreach ( array( 'items_path', 'meta_path' ) as $key ) {
			if ( ! empty( $paths[ $key ] ) && file_exists( (string) $paths[ $key ] ) ) {
				wp_delete_file( (string) $paths[ $key ] );
			}
		}
	}

	/**
	 * Removes stale parsed catalog cache files for a format.
	 */
	private function cleanup_catalog_caches( string $format, string $keep_key = '' ): void {
		$dir = $this->catalog_cache_dir();

		if ( '' === $dir ) {
			return;
		}

		$pattern = trailingslashit( $dir ) . 'catalog-' . strtolower( $format ) . '-*';

		foreach ( glob( $pattern ) ?: array() as $path ) {
			if ( '' !== $keep_key && str_contains( basename( $path ), 'catalog-' . $keep_key ) ) {
				continue;
			}

			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Extracts a log-safe SKU from a normalized item.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 */
	private function item_sku( array $item ): ?string {
		if ( ! isset( $item['sku'] ) || ! is_scalar( $item['sku'] ) ) {
			return null;
		}

		$sku = trim( (string) $item['sku'] );

		return '' !== $sku ? $sku : null;
	}

	/**
	 * Extracts SKUs from a normalized item batch for lookup priming.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 * @return array<int,string>
	 */
	private function item_skus( array $items ): array {
		$skus = array();

		foreach ( $items as $item ) {
			$sku = $this->item_sku( $item );

			if ( null !== $sku ) {
				$skus[] = $sku;
			}
		}

		return $skus;
	}

	/**
	 * Returns the normalized catalog image URL from an item.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 */
	private function item_image_url( array $item ): string {
		if ( ! isset( $item['image_url'] ) || ! is_scalar( $item['image_url'] ) ) {
			return '';
		}

		return esc_url_raw( trim( (string) $item['image_url'] ) );
	}

	/**
	 * Imports a catalog response through a streamed on-disk cache when possible.
	 *
	 * This avoids keeping the downloaded ZIP, extracted CSV, parsed rows, and batch in
	 * memory at the same time.
	 *
	 * @return array<string,mixed>|null Null when the response should use the legacy parser.
	 */
	private function import_streamed_catalog_response( mixed $raw, string $format, int $limit ): ?array {
		if ( 'CSV' !== strtoupper( $format ) ) {
			return null;
		}

		$content = $this->extract_catalog_content( $raw );

		if ( '' === $content ) {
			return null;
		}

		$source = $this->catalog_response_source_file( $content );
		unset( $content );

		if ( null === $source ) {
			return null;
		}

		try {
			$cache = $this->write_csv_catalog_cache_from_file( (string) $source['path'], $format );
		} finally {
			$this->delete_temp_files( (array) ( $source['cleanup_paths'] ?? array() ) );
		}

		if ( null === $cache ) {
			return null;
		}

		if ( 0 === absint( $cache['total_items'] ?? 0 ) ) {
			return $this->import_items_with_cursor(
				array(),
				$format,
				$limit,
				array(
					'catalog_cache'     => 'empty',
					'catalog_streamed'  => 'yes',
					'catalog_signature' => (string) ( $cache['signature'] ?? '' ),
				),
				(string) ( $cache['signature'] ?? '' )
			);
		}

		return $this->import_cached_items_with_cursor(
			$cache,
			$format,
			$limit,
			array(
				'catalog_cache'    => 'written',
				'catalog_streamed' => 'yes',
			)
		);
	}

	/**
	 * Stores a catalog response as a temporary source file.
	 *
	 * @return array{path:string,cleanup_paths:array<int,string>}|null
	 */
	private function catalog_response_source_file( string $content ): ?array {
		if ( filter_var( $content, FILTER_VALIDATE_URL ) ) {
			$this->logger->debug(
				'catalog',
				'Schrack catalog response contained a download URL.',
				null,
				array( 'download_url' => $this->safe_download_url_label( $content ) )
			);

			$download = $this->download_catalog_file( $content );

			if ( null === $download ) {
				return null;
			}

			$path    = (string) $download['path'];
			$cleanup = array( $path );

			if ( ! empty( $download['is_zip'] ) ) {
				$entry = $this->extract_first_zip_entry_file( $path );

				if ( null === $entry ) {
					$this->delete_temp_files( $cleanup );
					return null;
				}

				$path      = (string) $entry['path'];
				$cleanup[] = $path;
			}

			return array(
				'path'          => $path,
				'cleanup_paths' => $cleanup,
			);
		}

		if ( $this->looks_like_markup( $content ) ) {
			return null;
		}

		$temp_file = wp_tempnam( 'schrack-catalog-content' );

		if ( ! $temp_file || ! $this->write_temp_file( $temp_file, $content ) ) {
			if ( $temp_file ) {
				wp_delete_file( $temp_file );
			}

			$this->logger->warning( 'catalog', 'Could not write Schrack catalog response to a temporary file.' );
			return null;
		}

		$path    = $temp_file;
		$cleanup = array( $temp_file );

		if ( $this->is_zip_file( $temp_file ) ) {
			$entry = $this->extract_first_zip_entry_file( $temp_file );

			if ( null === $entry ) {
				$this->delete_temp_files( $cleanup );
				return null;
			}

			$path      = (string) $entry['path'];
			$cleanup[] = $path;
		}

		return array(
			'path'          => $path,
			'cleanup_paths' => $cleanup,
		);
	}

	/**
	 * Downloads a catalog file directly to disk.
	 *
	 * @return array{path:string,bytes:int,is_zip:bool}|null
	 */
	private function download_catalog_file( string $url ): ?array {
		$temp_file = wp_tempnam( 'schrack-catalog-download' );

		if ( ! $temp_file ) {
			$this->logger->error( 'catalog', 'Could not create a temporary file for Schrack catalog download.' );
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $temp_file,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp_file );
			$this->logger->error( 'catalog', 'Failed to download Schrack catalog file.', null, array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			wp_delete_file( $temp_file );
			$this->logger->error( 'catalog', 'Failed to download Schrack catalog file.', null, array( 'status_code' => $status_code ) );
			return null;
		}

		$bytes  = is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0;
		$is_zip = $this->is_zip_file( $temp_file );

		if ( $bytes <= 0 ) {
			wp_delete_file( $temp_file );
			$this->logger->warning( 'catalog', 'Downloaded Schrack catalog file was empty.' );
			return null;
		}

		$this->logger->debug(
			'catalog',
			'Downloaded Schrack catalog file.',
			null,
			array(
				'status_code'  => $status_code,
				'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
				'bytes'        => $bytes,
				'is_zip'       => $is_zip ? 'yes' : 'no',
				'streamed'     => 'yes',
			)
		);

		return array(
			'path'   => $temp_file,
			'bytes'  => $bytes,
			'is_zip' => $is_zip,
		);
	}

	/**
	 * Checks whether a file starts with a ZIP signature.
	 */
	private function is_zip_file( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		$signature = fread( $handle, 4 );
		fclose( $handle );

		return "PK\x03\x04" === $signature || "PK\x05\x06" === $signature;
	}

	/**
	 * Extracts the first non-directory ZIP entry to a temporary file without loading it into memory.
	 *
	 * @return array{path:string,entry_name:string,bytes:int}|null
	 */
	private function extract_first_zip_entry_file( string $zip_path ): ?array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->logger->error( 'catalog', 'Schrack catalog response is a ZIP file, but PHP ZipArchive is not available.' );
			return null;
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			$this->logger->error( 'catalog', 'Could not open Schrack catalog ZIP.' );
			return null;
		}

		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$name = $zip->getNameIndex( $index );

			if ( false === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}

			$source = $zip->getStream( $name );

			if ( false === $source ) {
				continue;
			}

			$temp_file = wp_tempnam( 'schrack-catalog-entry' );

			if ( ! $temp_file ) {
				fclose( $source );
				$zip->close();
				$this->logger->error( 'catalog', 'Could not create a temporary file for Schrack catalog ZIP entry.' );
				return null;
			}

			$target = fopen( $temp_file, 'wb' );

			if ( false === $target ) {
				fclose( $source );
				wp_delete_file( $temp_file );
				$zip->close();
				$this->logger->error( 'catalog', 'Could not write Schrack catalog ZIP entry to a temporary file.' );
				return null;
			}

			stream_copy_to_stream( $source, $target );
			fclose( $source );
			fclose( $target );
			$zip->close();

			$bytes = is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0;

			$this->logger->debug(
				'catalog',
				'Extracted Schrack catalog ZIP entry.',
				null,
				array(
					'entry_name' => $name,
					'bytes'      => $bytes,
					'streamed'   => 'yes',
				)
			);

			return array(
				'path'       => $temp_file,
				'entry_name' => $name,
				'bytes'      => $bytes,
			);
		}

		$zip->close();
		$this->logger->warning( 'catalog', 'Schrack catalog ZIP did not contain a readable file.' );

		return null;
	}

	/**
	 * Parses a CSV source file directly into the parsed JSONL catalog cache.
	 *
	 * @return array<string,mixed>|null
	 */
	private function write_csv_catalog_cache_from_file( string $path, string $format ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		$first_line = fgets( $handle );

		if ( false === $first_line || '' === trim( $first_line ) ) {
			fclose( $handle );
			$this->logger->warning( 'catalog', 'Schrack CSV catalog content was empty.' );
			return $this->empty_streamed_catalog_cache( $format );
		}

		if ( $this->looks_like_markup( $first_line ) ) {
			fclose( $handle );
			$this->logger->warning(
				'catalog',
				'Schrack catalog download returned markup instead of CSV.',
				null,
				array( 'preview' => $this->content_preview( $first_line ) )
			);
			return $this->empty_streamed_catalog_cache( $format );
		}

		$delimiter   = $this->detect_csv_delimiter_from_line( $first_line );
		$raw_headers = str_getcsv( $first_line, $delimiter );
		$headers     = $this->normalize_csv_headers( $raw_headers );

		if ( empty( $headers ) ) {
			fclose( $handle );
			$this->logger->warning( 'catalog', 'Schrack CSV catalog did not contain readable headers.' );
			return $this->empty_streamed_catalog_cache( $format );
		}

		$items_temp = wp_tempnam( 'schrack-catalog-items' );

		if ( ! $items_temp ) {
			fclose( $handle );
			$this->logger->warning( 'catalog', 'Could not create a temporary parsed Schrack catalog cache.' );
			return null;
		}

		$output = fopen( $items_temp, 'wb' );

		if ( false === $output ) {
			fclose( $handle );
			wp_delete_file( $items_temp );
			$this->logger->warning( 'catalog', 'Could not open parsed Schrack catalog cache for writing.' );
			return null;
		}

		$signature_context = hash_init( 'sha256' );
		$rows_seen         = 0;
		$rows_without_sku  = 0;
		$header_rows       = 0;
		$written           = 0;
		$first_row         = array();
		$write_failed      = false;

		while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( $this->csv_values_are_blank( $values ) ) {
				continue;
			}

			$row = $this->combine_csv_row( $headers, $values );

			if ( empty( $row ) ) {
				continue;
			}

			if ( $this->is_csv_header_continuation_row( $row ) ) {
				++$header_rows;
				continue;
			}

			++$rows_seen;

			if ( empty( $first_row ) ) {
				$first_row = $row;
			}

			$item = $this->normalize_catalog_row( $row );

			if ( '' === $item['sku'] ) {
				++$rows_without_sku;
				continue;
			}

			hash_update( $signature_context, (string) $this->item_sku( $item ) . "\n" );
			$encoded = wp_json_encode( $item );

			if ( ! is_string( $encoded ) || false === fwrite( $output, $encoded . "\n" ) ) {
				$write_failed = true;
				break;
			}

			++$written;
		}

		fclose( $handle );
		fclose( $output );

		$signature = hash_final( $signature_context );

		if ( $write_failed ) {
			wp_delete_file( $items_temp );
			$this->logger->warning( 'catalog', 'Could not write parsed Schrack catalog cache.' );
			return null;
		}

		if ( 0 === $rows_seen ) {
			wp_delete_file( $items_temp );
			$this->logger->warning(
				'catalog',
				'Schrack CSV catalog contained headers but no data rows.',
				null,
				array(
					'delimiter'   => $delimiter,
					'headers'     => $headers,
					'raw_headers' => array_values( array_map( static fn ( mixed $header ): string => (string) $header, $raw_headers ) ),
					'streamed'    => 'yes',
				)
			);

			return $this->empty_streamed_catalog_cache( $format, $signature );
		}

		if ( 0 === $written ) {
			wp_delete_file( $items_temp );
			$this->logger->warning(
				'catalog',
				'Schrack CSV catalog rows were found, but no SKU/item-number column was recognized.',
				null,
				array(
					'delimiter'        => $delimiter,
					'headers'          => $headers,
					'raw_headers'      => array_values( array_map( static fn ( mixed $header ): string => (string) $header, $raw_headers ) ),
					'rows_seen'        => $rows_seen,
					'rows_without_sku' => $rows_without_sku,
					'header_rows'      => $header_rows,
					'first_row'        => $this->preview_row( $first_row ),
					'streamed'         => 'yes',
				)
			);

			return $this->empty_streamed_catalog_cache( $format, $signature );
		}

		$cache = $this->finalize_streamed_catalog_cache( $format, $signature, $items_temp, $written );

		if ( null === $cache ) {
			return null;
		}

		$this->logger->debug(
			'catalog',
			'Parsed Schrack CSV catalog.',
			null,
			array(
				'delimiter'        => $delimiter,
				'headers'          => $headers,
				'rows_seen'        => $rows_seen,
				'items'            => $written,
				'rows_without_sku' => $rows_without_sku,
				'header_rows'      => $header_rows,
				'streamed'         => 'yes',
			)
		);

		return $cache;
	}

	/**
	 * Creates a metadata-only empty cache descriptor.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_streamed_catalog_cache( string $format, string $signature = '' ): array {
		return array(
			'format'      => strtoupper( $format ),
			'signature'   => '' !== $signature ? $signature : hash( 'sha256', '' ),
			'total_items' => 0,
			'created_at'  => time(),
			'key'         => '',
		);
	}

	/**
	 * Moves a streamed JSONL temp file into the deterministic catalog cache path.
	 *
	 * @return array<string,mixed>|null
	 */
	private function finalize_streamed_catalog_cache( string $format, string $signature, string $items_temp, int $written ): ?array {
		$paths = $this->catalog_cache_paths( $format, $signature );

		if ( empty( $paths ) ) {
			wp_delete_file( $items_temp );
			return null;
		}

		$this->cleanup_catalog_caches( $format, (string) $paths['key'] );

		foreach ( array( 'items_path', 'meta_path' ) as $target_key ) {
			if ( ! empty( $paths[ $target_key ] ) && is_file( (string) $paths[ $target_key ] ) ) {
				wp_delete_file( (string) $paths[ $target_key ] );
			}
		}

		$meta_temp = (string) $paths['meta_path'] . '.tmp';
		$meta      = array(
			'format'      => strtoupper( $format ),
			'signature'   => $signature,
			'total_items' => $written,
			'created_at'  => time(),
			'key'         => $paths['key'],
		);
		$meta_json = wp_json_encode( $meta );

		if (
			! is_string( $meta_json ) ||
			false === file_put_contents( $meta_temp, $meta_json ) ||
			! rename( $items_temp, (string) $paths['items_path'] ) ||
			! rename( $meta_temp, (string) $paths['meta_path'] )
		) {
			wp_delete_file( $items_temp );
			wp_delete_file( $meta_temp );
			wp_delete_file( (string) $paths['items_path'] );
			$this->logger->warning( 'catalog', 'Could not finalize parsed Schrack catalog cache.' );
			return null;
		}

		$this->logger->debug(
			'catalog',
			'Wrote parsed Schrack catalog cache.',
			null,
			array(
				'catalog_cache_key' => $paths['key'],
				'total_items'       => $written,
				'streamed'          => 'yes',
			)
		);

		return array_merge(
			$meta,
			array(
				'items_path' => $paths['items_path'],
				'meta_path'  => $paths['meta_path'],
			)
		);
	}

	/**
	 * Deletes temporary source files created during streamed import.
	 *
	 * @param array<int,mixed> $paths Temporary paths.
	 */
	private function delete_temp_files( array $paths ): void {
		foreach ( array_unique( array_filter( array_map( 'strval', $paths ) ) ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Parser boundary for future CSV/XML/DATANORM implementations.
	 *
	 * @param mixed  $raw Raw SOAP response.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function parse_catalog_response( mixed $raw, string $format ): array {
		$content = $this->extract_catalog_content( $raw );
		$format  = strtoupper( $format );

		if ( '' === $content ) {
			$this->logger->warning( 'catalog', 'Schrack catalog response did not contain parsable content.' );
			return array();
		}

		if ( filter_var( $content, FILTER_VALIDATE_URL ) ) {
			$this->logger->debug(
				'catalog',
				'Schrack catalog response contained a download URL.',
				null,
				array( 'download_url' => $this->safe_download_url_label( $content ) )
			);
			$content = $this->download_catalog_content( $content );

			if ( '' === $content ) {
				$this->logger->warning( 'catalog', 'Downloaded Schrack catalog content was empty or unreadable.' );
				return array();
			}
		}

		if ( 'XML' === $format ) {
			return $this->parse_xml_catalog( $content );
		}

		if ( 'CSV' === $format ) {
			return $this->parse_csv_catalog( $content );
		}

		$this->logger->warning( 'catalog', 'Catalog parser for this format is not implemented yet.', null, array( 'format' => $format ) );

		return array();
	}

	/**
	 * Extracts catalog content from a loose SOAP response.
	 */
	private function extract_catalog_content( mixed $raw ): string {
		if ( is_string( $raw ) ) {
			return $raw;
		}

		if ( is_object( $raw ) ) {
			$raw = get_object_vars( $raw );
		}

		if ( ! is_array( $raw ) ) {
			return '';
		}

		foreach ( array( 'DownloadURL', 'downloadUrl', 'download_url', 'Catalog', 'CatalogData', 'Data', 'Content', 'GetCatalogAsResult', 'Return', 'return' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
				return $raw[ $key ];
			}
		}

		foreach ( $raw as $value ) {
			$content = $this->extract_catalog_content( $value );

			if ( '' !== $content ) {
				return $content;
			}
		}

		return '';
	}

	/**
	 * Downloads a catalog zip or plain catalog file from a Schrack DownloadURL response.
	 */
	private function download_catalog_content( string $url ): string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'catalog', 'Failed to download Schrack catalog file.', null, array( 'error' => $response->get_error_message() ) );
			return '';
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->logger->error( 'catalog', 'Failed to download Schrack catalog file.', null, array( 'status_code' => $status_code ) );
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			$this->logger->warning( 'catalog', 'Downloaded Schrack catalog file was empty.' );
			return '';
		}

		$this->logger->debug(
			'catalog',
			'Downloaded Schrack catalog file.',
			null,
			array(
				'status_code'  => $status_code,
				'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
				'bytes'        => strlen( $body ),
				'is_zip'       => $this->looks_like_zip( $body ) ? 'yes' : 'no',
			)
		);

		if ( $this->looks_like_zip( $body ) ) {
			return $this->extract_first_zip_entry( $body );
		}

		return $body;
	}

	/**
	 * Checks whether a binary string looks like a ZIP file.
	 */
	private function looks_like_zip( string $body ): bool {
		return str_starts_with( $body, "PK\x03\x04" ) || str_starts_with( $body, "PK\x05\x06" );
	}

	/**
	 * Extracts the first non-directory file from a ZIP catalog.
	 */
	private function extract_first_zip_entry( string $body ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->logger->error( 'catalog', 'Schrack catalog response is a ZIP file, but PHP ZipArchive is not available.' );
			return '';
		}

		$temp_file = wp_tempnam( 'schrack-catalog.zip' );

		if ( ! $temp_file ) {
			$this->logger->error( 'catalog', 'Could not create a temporary file for Schrack catalog ZIP.' );
			return '';
		}

		if ( ! $this->write_temp_file( $temp_file, $body ) ) {
			wp_delete_file( $temp_file );
			$this->logger->error( 'catalog', 'Could not write Schrack catalog ZIP to a temporary file.' );
			return '';
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $temp_file ) ) {
			wp_delete_file( $temp_file );
			$this->logger->error( 'catalog', 'Could not open Schrack catalog ZIP.' );
			return '';
		}

		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$name = $zip->getNameIndex( $index );

			if ( false === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}

			$content = $zip->getFromIndex( $index );
			$zip->close();
			wp_delete_file( $temp_file );

			$this->logger->debug(
				'catalog',
				'Extracted Schrack catalog ZIP entry.',
				null,
				array(
					'entry_name' => $name,
					'bytes'      => false === $content ? 0 : strlen( $content ),
				)
			);

			return false === $content ? '' : $content;
		}

		$zip->close();
		wp_delete_file( $temp_file );

		$this->logger->warning( 'catalog', 'Schrack catalog ZIP did not contain a readable file.' );

		return '';
	}

	/**
	 * Writes downloaded binary data to a temporary file for ZIP extraction.
	 */
	private function write_temp_file( string $path, string $body ): bool {
		$bytes = file_put_contents( $path, $body );

		if ( false === $bytes ) {
			return false;
		}

		if ( defined( 'FS_CHMOD_FILE' ) && is_writable( $path ) ) {
			chmod( $path, FS_CHMOD_FILE );
		}

		return $bytes === strlen( $body );
	}

	/**
	 * Basic CSV parser. Header names are intentionally broad for first integration.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_csv_catalog( string $content ): array {
		if ( '' === trim( $content ) ) {
			$this->logger->warning( 'catalog', 'Schrack CSV catalog content was empty.' );
			return array();
		}

		$lines = preg_split( '/\r\n|\n|\r/', trim( $content ) );

		if ( empty( $lines ) ) {
			return array();
		}

		if ( $this->looks_like_markup( $content ) ) {
			$this->logger->warning(
				'catalog',
				'Schrack catalog download returned markup instead of CSV.',
				null,
				array( 'preview' => $this->content_preview( $content ) )
			);

			return array();
		}

		$delimiter        = $this->detect_csv_delimiter( $lines );
		$raw_headers      = str_getcsv( array_shift( $lines ), $delimiter );
		$headers          = $this->normalize_csv_headers( $raw_headers );
		$items            = array();
		$rows_seen        = 0;
		$rows_without_sku = 0;
		$header_rows      = 0;
		$first_row        = array();

		if ( empty( $headers ) ) {
			$this->logger->warning( 'catalog', 'Schrack CSV catalog did not contain readable headers.' );
			return array();
		}

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$row = $this->combine_csv_row( $headers, str_getcsv( $line, $delimiter ) );

			if ( empty( $row ) ) {
				continue;
			}

			if ( $this->is_csv_header_continuation_row( $row ) ) {
				++$header_rows;
				continue;
			}

			++$rows_seen;

			if ( empty( $first_row ) ) {
				$first_row = $row;
			}

			$item = $this->normalize_catalog_row( $row );

			if ( '' !== $item['sku'] ) {
				$items[] = $item;
			} else {
				++$rows_without_sku;
			}
		}

		if ( 0 === $rows_seen ) {
			$this->logger->warning(
				'catalog',
				'Schrack CSV catalog contained headers but no data rows.',
				null,
				array(
					'delimiter'   => $delimiter,
					'headers'     => $headers,
					'raw_headers' => array_values( array_map( static fn ( mixed $header ): string => (string) $header, $raw_headers ) ),
				)
			);
		} elseif ( empty( $items ) ) {
			$this->logger->warning(
				'catalog',
				'Schrack CSV catalog rows were found, but no SKU/item-number column was recognized.',
				null,
				array(
					'delimiter'        => $delimiter,
					'headers'          => $headers,
					'raw_headers'      => array_values( array_map( static fn ( mixed $header ): string => (string) $header, $raw_headers ) ),
					'rows_seen'        => $rows_seen,
					'rows_without_sku' => $rows_without_sku,
					'header_rows'      => $header_rows,
					'first_row'        => $this->preview_row( $first_row ),
				)
			);
		} else {
			$this->logger->debug(
				'catalog',
				'Parsed Schrack CSV catalog.',
				null,
				array(
					'delimiter'        => $delimiter,
					'headers'          => $headers,
					'rows_seen'        => $rows_seen,
					'items'            => count( $items ),
					'rows_without_sku' => $rows_without_sku,
					'header_rows'      => $header_rows,
				)
			);
		}

		return $items;
	}

	/**
	 * Basic XML parser boundary.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_xml_catalog( string $content ): array {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			$this->logger->warning( 'catalog', 'PHP SimpleXML extension is not available for Schrack XML catalog parsing.' );
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $content, 'SimpleXMLElement', LIBXML_NONET );

		if ( false === $xml ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$this->logger->warning( 'catalog', 'Unable to parse Schrack XML catalog response.' );
			return array();
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$items = array();

		foreach ( $xml->xpath( '//*[contains(translate(name(), "ITEMPRODUCT", "itemproduct"), "item") or contains(translate(name(), "PRODUCT", "product"), "product")]' ) ?: array() as $node ) {
			$row  = json_decode( wp_json_encode( $node ), true );
			$item = $this->normalize_catalog_row( is_array( $row ) ? $row : array() );

			if ( '' !== $item['sku'] ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Normalizes CSV headers and keeps duplicate/empty columns addressable.
	 *
	 * @param array<int,mixed> $headers Raw headers.
	 * @return array<int,string>
	 */
	private function normalize_csv_headers( array $headers ): array {
		$normalized = array();
		$seen       = array();

		foreach ( $headers as $index => $header ) {
			$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
			$key    = $this->catalog_key( (string) $header );

			if ( '' === $key ) {
				$key = 'column_' . $index;
			}

			if ( isset( $seen[ $key ] ) ) {
				$key = $key . '_' . $index;
			}

			$seen[ $key ] = true;
			$normalized[] = $key;
		}

		return $normalized;
	}

	/**
	 * Combines a CSV row with headers without allowing malformed rows to fatal.
	 *
	 * @param array<int,string> $headers CSV headers.
	 * @param array<int,mixed>  $values CSV row values.
	 * @return array<string,mixed>
	 */
	private function combine_csv_row( array $headers, array $values ): array {
		$column_count = count( $headers );

		if ( 0 === $column_count ) {
			return array();
		}

		if ( count( $values ) < $column_count ) {
			$values = array_pad( $values, $column_count, '' );
		} elseif ( count( $values ) > $column_count ) {
			$values = array_slice( $values, 0, $column_count );
		}

		$row = array_combine( $headers, $values );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Normalizes a parser row to mapper data.
	 *
	 * @param array<int|string,mixed> $row Raw parser row.
	 * @return array<string,mixed>
	 */
	private function normalize_catalog_row( array $row ): array {
		$get           = fn ( array $keys ): string => $this->find_catalog_value( $row, $keys );
		$category_path = $this->catalog_category_path( $row );

		$item = array(
			'sku'               => sanitize_text_field( $get( array( 'sku', 'id', 'item_id', 'itemid', 'item_number', 'itemnumber', 'item_no', 'itemno', 'article', 'article_id', 'articleid', 'article_number', 'articlenumber', 'artikel', 'artikelnummer', 'artikelnr', 'artnr', 'artno', 'bestellnummer', 'ordernumber', 'materialnumber', 'materialnr', 'productid', 'productnumber', 'partnumber', 'produs', 'schrackarticlenumber', 'schrackartikelnummer', 'schrackartikel', 'edsarticleid', 'edsartikelnummer' ) ) ),
			'name'              => sanitize_text_field( $get( array( 'name', 'title', 'productname', 'itemname', 'produsname', 'textprodus', 'description_short', 'descriptionshort', 'shorttext', 'kurztext', 'bezeichnung', 'bezeichnung1', 'artikelbezeichnung' ) ) ),
			'short_description' => wp_kses_post( $get( array( 'short_description', 'shortdescription', 'description_short', 'descriptionshort', 'textprodus', 'shorttext', 'kurztext' ) ) ),
			'description'       => wp_kses_post( $get( array( 'description', 'long_description', 'longdescription', 'textprodus', 'longtext', 'langtext', 'beschreibung' ) ) ),
			'manufacturer'      => sanitize_text_field( $get( array( 'manufacturer', 'brand', 'hersteller', 'producer', 'supplier' ) ) ),
			'ean'               => sanitize_text_field( $get( array( 'ean', 'gtin', 'barcode', 'barcodeno' ) ) ),
			'image_url'         => $this->normalize_catalog_url( $get( array( 'image_url', 'imageurl', 'imageurl1', 'photo_url', 'photourl', 'foto_url', 'fotourl', 'foto', 'fotografie', 'photo', 'photograph', 'picture', 'pictureurl', 'bild', 'bildurl', 'artikelbild', 'image', 'image1', 'mainimage', 'mainimageurl', 'thumbnail', 'thumbnailurl', 'productimage', 'productimageurl', 'product_image', 'product_image_url', 'mediaurl' ) ) ),
			'category_path'     => sanitize_text_field( '' !== $category_path ? $category_path : $get( array( 'category_path', 'categorypath', 'category', 'categories', 'warenhauptgruppe', 'warengruppe', 'productgroup', 'cataloggroup' ) ) ),
			'unit'              => sanitize_text_field( $get( array( 'unit', 'uom', 'measure', 'unitatedemasura', 'mengeneinheit', 'salesunit' ) ) ),
			'catalog_status'    => sanitize_text_field( $get( array( 'catalog_status', 'status' ) ) ),
		);

		$item['technical_attributes'] = $this->catalog_technical_attributes( $row, $item );

		return $item;
	}

	/**
	 * Extracts all public, non-core catalog fields for the product detail table.
	 *
	 * @param array<int|string,mixed> $row  Raw parser row.
	 * @param array<string,mixed>     $item Normalized mapper data.
	 * @return array<int,array{label:string,value:string}>
	 */
	private function catalog_technical_attributes( array $row, array $item ): array {
		$items          = array();
		$seen           = array();
		$core_values    = $this->catalog_core_values( $item );
		$excluded_keys  = $this->catalog_excluded_technical_keys();
		$sensitive_keys = $this->catalog_sensitive_technical_keys();

		$this->collect_catalog_technical_attributes( $row, '', $items, $seen, $core_values, $excluded_keys, $sensitive_keys );

		return $items;
	}

	/**
	 * Walks nested parser data and collects scalar technical values.
	 *
	 * @param array<int|string,mixed>              $data           Parser fragment.
	 * @param string                              $prefix         Label prefix for nested data.
	 * @param array<int,array{label:string,value:string}> $items  Collected items.
	 * @param array<string,bool>                  $seen           Duplicate guard.
	 * @param array<string,bool>                  $core_values    Normalized core values.
	 * @param array<string,bool>                  $excluded_keys  Core/public-duplicate keys.
	 * @param array<string,bool>                  $sensitive_keys Internal/commercial keys.
	 */
	private function collect_catalog_technical_attributes( array $data, string $prefix, array &$items, array &$seen, array $core_values, array $excluded_keys, array $sensitive_keys ): void {
		foreach ( $data as $key => $value ) {
			$label = $this->catalog_technical_label( (string) $key, $prefix );

			if ( is_array( $value ) ) {
				if ( $this->array_is_scalar_list( $value ) ) {
					$this->add_catalog_technical_attribute( $label, $this->array_text_value( $value ), $items, $seen, $core_values, $excluded_keys, $sensitive_keys );
				} else {
					$this->collect_catalog_technical_attributes( $value, $label, $items, $seen, $core_values, $excluded_keys, $sensitive_keys );
				}

				continue;
			}

			$this->add_catalog_technical_attribute( $label, $value, $items, $seen, $core_values, $excluded_keys, $sensitive_keys );
		}
	}

	/**
	 * Adds one technical attribute after duplicate and safety filtering.
	 *
	 * @param array<int,array{label:string,value:string}> $items Collected items.
	 * @param array<string,bool>                         $seen  Duplicate guard.
	 * @param array<string,bool>                         $core_values Normalized core values.
	 * @param array<string,bool>                         $excluded_keys Core/public-duplicate keys.
	 * @param array<string,bool>                         $sensitive_keys Internal/commercial keys.
	 */
	private function add_catalog_technical_attribute( string $label, mixed $value, array &$items, array &$seen, array $core_values, array $excluded_keys, array $sensitive_keys ): void {
		if ( '' === $label || ! is_scalar( $value ) ) {
			return;
		}

		$value = $this->technical_text_value( $value );

		if ( '' === $value ) {
			return;
		}

		$label_key = $this->catalog_key( $label );
		$value_key = $this->catalog_key( $value );

		if ( isset( $excluded_keys[ $label_key ] ) || $this->catalog_key_matches_any( $label_key, $sensitive_keys ) || isset( $core_values[ $value_key ] ) ) {
			return;
		}

		$seen_key = $label_key . ':' . $value_key;

		if ( isset( $seen[ $seen_key ] ) ) {
			return;
		}

		$seen[ $seen_key ] = true;
		$items[] = array(
			'label' => sanitize_text_field( $label ),
			'value' => sanitize_text_field( $value ),
		);
	}

	/**
	 * Returns normalized core values that are already mapped to first-class fields.
	 *
	 * @param array<string,mixed> $item Normalized mapper data.
	 * @return array<string,bool>
	 */
	private function catalog_core_values( array $item ): array {
		$values = array();

		foreach ( array( 'sku', 'name', 'short_description', 'description', 'manufacturer', 'ean', 'image_url', 'category_path', 'unit', 'catalog_status' ) as $key ) {
			if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
				$value = $this->catalog_key( wp_strip_all_tags( (string) $item[ $key ] ) );

				if ( '' !== $value ) {
					$values[ $value ] = true;
				}
			}
		}

		return $values;
	}

	/**
	 * Returns catalog keys that are displayed through dedicated fields already.
	 *
	 * @return array<string,bool>
	 */
	private function catalog_excluded_technical_keys(): array {
		$keys = array(
			'sku', 'id', 'item_id', 'itemid', 'item_number', 'itemnumber', 'item_no', 'itemno', 'article', 'article_id', 'articleid', 'article_number', 'articlenumber', 'artikel', 'artikelnummer', 'artikelnr', 'artnr', 'artno', 'bestellnummer', 'ordernumber', 'materialnumber', 'materialnr', 'productid', 'productnumber', 'partnumber', 'produs', 'schrackarticlenumber', 'schrackartikelnummer', 'schrackartikel', 'edsarticleid', 'edsartikelnummer',
			'name', 'title', 'productname', 'itemname', 'produsname', 'textprodus', 'description_short', 'descriptionshort', 'shorttext', 'kurztext', 'bezeichnung', 'bezeichnung1', 'artikelbezeichnung',
			'short_description', 'shortdescription', 'description', 'long_description', 'longdescription', 'longtext', 'langtext', 'beschreibung',
			'manufacturer', 'brand', 'hersteller', 'producer', 'supplier',
			'ean', 'gtin', 'barcode', 'barcodeno',
			'image_url', 'imageurl', 'imageurl1', 'photo_url', 'photourl', 'foto_url', 'fotourl', 'foto', 'fotografie', 'photo', 'photograph', 'picture', 'pictureurl', 'bild', 'bildurl', 'artikelbild', 'image', 'image1', 'mainimage', 'mainimageurl', 'thumbnail', 'thumbnailurl', 'productimage', 'productimageurl', 'product_image', 'product_image_url', 'mediaurl',
			'category_path', 'categorypath', 'category', 'categories', 'warenhauptgruppe', 'warengruppe', 'productgroup', 'cataloggroup', 'maingroup', 'group',
			'unit', 'uom', 'measure', 'unitatedemasura', 'mengeneinheit', 'salesunit',
			'catalog_status', 'status',
			'import', 'imported', 'importstatus', 'sync', 'syncstatus', 'lastsync', 'lastupdate', 'lastupdated', 'updated', 'updatedat', 'created', 'createdat', 'timestamp', 'cache', 'cursor',
			'source', 'resulttype', 'download', 'downloadurl', 'file', 'filename', 'path',
			'soap', 'wsdl', 'endpoint', 'token', 'password', 'username', 'userid', 'session',
		);

		return array_fill_keys( array_map( array( $this, 'catalog_key' ), $keys ), true );
	}

	/**
	 * Returns catalog keys that must not be exposed as public product attributes.
	 *
	 * @return array<string,bool>
	 */
	private function catalog_sensitive_technical_keys(): array {
		$keys = array(
			'cost',
			'costprice',
			'currency',
			'customer',
			'customernumber',
			'discount',
			'ekpreis',
			'grossprice',
			'netprice',
			'price',
			'pricegross',
			'pricenet',
			'pricespecial',
			'pret',
			'pretnet',
			'pretspecial',
			'purchaseprice',
			'purchasingprice',
			'rabatt',
			'stock',
			'warehouse',
			'import',
			'imported',
			'importstatus',
			'sync',
			'syncstatus',
			'lastsync',
			'lastupdate',
			'lastupdated',
			'internal',
			'private',
			'secret',
			'token',
			'password',
			'session',
		);

		return array_fill_keys( array_map( array( $this, 'catalog_key' ), $keys ), true );
	}

	/**
	 * Checks a normalized key against a lookup, allowing nested labels like Item Price.
	 *
	 * @param array<string,bool> $lookup Normalized key lookup.
	 */
	private function catalog_key_matches_any( string $key, array $lookup ): bool {
		foreach ( $lookup as $lookup_key => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			if ( $key === $lookup_key || str_contains( $key, $lookup_key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a readable technical label from a parser key.
	 */
	private function catalog_technical_label( string $key, string $prefix = '' ): string {
		$key = trim( preg_replace( '/[_-]+/', ' ', $key ) ?? $key );

		if ( '' === $key ) {
			return $prefix;
		}

		$known = array(
			'assortment'            => __( 'Sortiment', 'schrack-woocommerce-sync' ),
			'businesslineid'        => __( 'ID linie business', 'schrack-woocommerce-sync' ),
			'businesslinetext'      => __( 'Linie business', 'schrack-woocommerce-sync' ),
			'datasheet'             => __( 'Fisa tehnica', 'schrack-woocommerce-sync' ),
			'minorderquantity'      => __( 'Cantitate minima comanda', 'schrack-woocommerce-sync' ),
			'productadditionaltext' => __( 'Text suplimentar produs', 'schrack-woocommerce-sync' ),
			'validdela'             => __( 'Valabil de la', 'schrack-woocommerce-sync' ),
			'validpanala'           => __( 'Valabil pana la', 'schrack-woocommerce-sync' ),
		);

		$normalized = $this->catalog_key( $key );
		$label      = $known[ $normalized ] ?? ucwords( $key );

		return '' === $prefix ? $label : $prefix . ' - ' . $label;
	}

	/**
	 * Converts scalar catalog values to frontend-safe text.
	 */
	private function technical_text_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Da', 'schrack-woocommerce-sync' ) : __( 'Nu', 'schrack-woocommerce-sync' );
		}

		return trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES ) ) );
	}

	/**
	 * Returns whether an array contains only scalar values.
	 *
	 * @param array<int|string,mixed> $value Value.
	 */
	private function array_is_scalar_list( array $value ): bool {
		foreach ( $value as $part ) {
			if ( is_array( $part ) || is_object( $part ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Converts a scalar array to display text.
	 *
	 * @param array<int|string,mixed> $value Value.
	 */
	private function array_text_value( array $value ): string {
		$parts = array();

		foreach ( $value as $part ) {
			$text = is_scalar( $part ) ? $this->technical_text_value( $part ) : '';

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Normalizes catalog URL values that may arrive as plain URLs or small HTML fragments.
	 */
	private function normalize_catalog_url( string $value ): string {
		$value = html_entity_decode( trim( $value ), ENT_QUOTES );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/\bsrc=[\'"]([^\'"]+)[\'"]/i', $value, $matches ) ) {
			$value = $matches[1];
		} elseif ( preg_match( '/https?:\/\/[^\s,;"\'<>|]+/i', $value, $matches ) ) {
			$value = $matches[0];
		} elseif ( str_starts_with( $value, '//' ) ) {
			$value = 'https:' . $value;
		}

		return esc_url_raw( $value );
	}

	/**
	 * Builds a category path from split catalog group columns when available.
	 *
	 * @param array<int|string,mixed> $row Raw parser row.
	 */
	private function catalog_category_path( array $row ): string {
		$parts = array(
			$this->find_catalog_value( $row, array( 'maingroup' ) ),
			$this->find_catalog_value( $row, array( 'group' ) ),
		);

		$parts = array_values( array_filter( array_map( 'trim', $parts ) ) );

		return implode( ' > ', $parts );
	}

	/**
	 * Finds the first scalar catalog value by broad key aliases, including nested XML rows.
	 *
	 * @param array<int|string,mixed> $row Raw parser row.
	 * @param array<int,string>   $keys Key aliases.
	 */
	private function find_catalog_value( array $row, array $keys ): string {
		$normalized_keys = array_map( array( $this, 'catalog_key' ), $keys );

		foreach ( $row as $key => $value ) {
			$normalized_key = $this->catalog_key( (string) $key );

			if ( in_array( $normalized_key, $normalized_keys, true ) ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					return (string) $value;
				}

				if ( is_array( $value ) ) {
					$scalar = $this->first_scalar_value( $value );

					if ( '' !== $scalar ) {
						return $scalar;
					}
				}
			}

			if ( is_array( $value ) ) {
				$nested = $this->find_catalog_value( $value, $keys );

				if ( '' !== $nested ) {
					return $nested;
				}
			}
		}

		return '';
	}

	/**
	 * Detects the most likely CSV delimiter from the header line.
	 *
	 * @param array<int,string> $lines CSV lines.
	 */
	private function detect_csv_delimiter( array $lines ): string {
		return $this->detect_csv_delimiter_from_line( (string) ( $lines[0] ?? '' ) );
	}

	/**
	 * Detects the most likely CSV delimiter from one header line.
	 */
	private function detect_csv_delimiter_from_line( string $first_line ): string {
		$candidates  = array( ';', ',', "\t", '|' );
		$best        = ';';
		$best_columns = 0;

		foreach ( $candidates as $candidate ) {
			$columns = count( str_getcsv( $first_line, $candidate ) );

			if ( $columns > $best_columns ) {
				$best         = $candidate;
				$best_columns = $columns;
			}
		}

		return $best;
	}

	/**
	 * Returns whether fgetcsv yielded an empty line.
	 *
	 * @param mixed $values CSV values.
	 */
	private function csv_values_are_blank( mixed $values ): bool {
		if ( ! is_array( $values ) ) {
			return true;
		}

		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalizes loose catalog keys so separators and accents do not matter.
	 */
	private function catalog_key( string $key ): string {
		$key = preg_replace( '/^\xEF\xBB\xBF/', '', $key );

		if ( function_exists( 'remove_accents' ) ) {
			$key = remove_accents( $key );
		}

		$key = strtolower( $key );
		$key = preg_replace( '/[^a-z0-9]+/', '', $key );

		return null === $key ? '' : $key;
	}

	/**
	 * Detects secondary header rows in Schrack CSV exports.
	 *
	 * @param array<string,mixed> $row CSV row.
	 */
	private function is_csv_header_continuation_row( array $row ): bool {
		$header_like_values = array(
			'assortment',
			'businesslineid',
			'businesslinetext',
			'datasheet',
			'ean',
			'minorderquantity',
			'pret',
			'pretnet',
			'pretspecial',
			'productadditionaltext',
			'validdela',
			'validpanala',
		);
		$matches = 0;

		foreach ( $row as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( in_array( $this->catalog_key( (string) $value ), $header_like_values, true ) ) {
				++$matches;
			}
		}

		return $matches >= 3;
	}

	/**
	 * Builds a short row preview for parser diagnostics.
	 *
	 * @param array<string,mixed> $row CSV row.
	 * @return array<string,string>
	 */
	private function preview_row( array $row ): array {
		$preview = array();

		foreach ( array_slice( $row, 0, 12, true ) as $key => $value ) {
			$preview[ (string) $key ] = is_scalar( $value ) ? substr( (string) $value, 0, 80 ) : gettype( $value );
		}

		return $preview;
	}

	/**
	 * Checks whether a download looks like an HTML/XML error page.
	 */
	private function looks_like_markup( string $content ): bool {
		return (bool) preg_match( '/^\s*</', $content );
	}

	/**
	 * Returns a safe, token-free label for a download URL.
	 */
	private function safe_download_url_label( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' !== $host ? $host . $path : '[download-url]';
	}

	/**
	 * Returns a short content preview for diagnostics.
	 */
	private function content_preview( string $content ): string {
		$content = preg_replace( '/\s+/', ' ', trim( $content ) );

		if ( null === $content ) {
			return '';
		}

		return substr( $content, 0, 300 );
	}

	/**
	 * Returns the first scalar value in a nested parser fragment.
	 *
	 * @param array<int|string,mixed> $data Parser fragment.
	 */
	private function first_scalar_value( array $data ): string {
		foreach ( $data as $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}

			if ( is_array( $value ) ) {
				$nested = $this->first_scalar_value( $value );

				if ( '' !== $nested ) {
					return $nested;
				}
			}
		}

		return '';
	}
}
