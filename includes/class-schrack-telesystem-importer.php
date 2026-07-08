<?php
/**
 * Telesystem CSV feed import orchestration.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Telesystem_Importer {
	public const DEFAULT_FEED_URL = 'https://b2b.telesystem.ro/index.php?r=equipment%2Fcsvfeed&uuid=729dd094-38a6-11f1-b33d-507c6f54abf0';
	public const STATUS_KEY       = 'telesystem_catalog';

	private const SOURCE    = 'telesystem';
	private const OPERATION = 'telesystem';

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
	 * Product mapper.
	 *
	 * @var Schrack_Product_Mapper
	 */
	private Schrack_Product_Mapper $mapper;

	/**
	 * Category markup service.
	 *
	 * @var Schrack_Category_Markup
	 */
	private Schrack_Category_Markup $markup;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, ?Schrack_Product_Mapper $mapper = null, ?Schrack_Category_Markup $markup = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->mapper   = $mapper ?: new Schrack_Product_Mapper( $settings, $logger );
		$this->markup   = $markup ?: new Schrack_Category_Markup( $settings );
	}

	/**
	 * Imports one Telesystem feed batch.
	 *
	 * @return array<string,mixed>
	 */
	public function import_from_feed( int $limit = 500 ): array {
		$limit = max( 1, $limit );

		if ( 'yes' !== (string) $this->settings->get( 'telesystem_enabled', 'yes' ) ) {
			$result = array(
				'processed'       => 0,
				'errors'          => 0,
				'completed_cycle' => 'yes',
				'disabled'        => 'yes',
			);
			$this->settings->update_status( self::STATUS_KEY, $result );

			return $result;
		}

		$feed_url = $this->feed_url();

		if ( '' === $feed_url ) {
			$result = array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
				'message'         => 'Missing Telesystem feed URL.',
			);
			$this->settings->update_status( self::STATUS_KEY, $result );
			$this->logger->error( self::OPERATION, 'Telesystem feed URL is not configured.' );

			return $result;
		}

		$cache = $this->active_feed_cache( $feed_url );

		if ( null === $cache ) {
			$download = $this->download_feed_file( $feed_url );

			if ( null === $download ) {
				$result = array(
					'processed'       => 0,
					'errors'          => 1,
					'completed_cycle' => 'yes',
					'feed_url_hash'   => $this->feed_url_hash( $feed_url ),
				);
				$this->settings->update_status( self::STATUS_KEY, $result );

				return $result;
			}

			try {
				$cache = $this->write_feed_cache_from_file( (string) $download['path'], $feed_url );
			} finally {
				$this->delete_temp_files( array( (string) $download['path'] ) );
			}
		}

		if ( null === $cache ) {
			$result = array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
				'feed_url_hash'   => $this->feed_url_hash( $feed_url ),
			);
			$this->settings->update_status( self::STATUS_KEY, $result );

			return $result;
		}

		return $this->import_cached_items_with_cursor( $cache, $limit );
	}

	/**
	 * Fetches a raw sample directly from the Telesystem feed for debugging
	 * attribute/filter handling. Does not import or cache anything. Each row is run
	 * through the normal normalize_telesystem_row() mapping so the raw feed columns
	 * and the resulting technical_attributes can be compared side by side.
	 *
	 * The row count is capped well above typical exploratory samples so a "full"
	 * export can cover most/all of the feed's category diversity, while still
	 * bounding memory/response size for a manual, one-off admin action.
	 *
	 * @return array<string,mixed>
	 */
	public function debug_raw_rows( int $limit = 10 ): array {
		$limit    = max( 1, min( 5000, $limit ) );
		$feed_url = $this->feed_url();

		if ( '' === $feed_url ) {
			return array( 'error' => __( 'Telesystem feed URL is not configured.', 'schrack-woocommerce-sync' ) );
		}

		$download = $this->download_feed_file( $feed_url );

		if ( null === $download ) {
			return array( 'error' => __( 'Could not download the Telesystem feed.', 'schrack-woocommerce-sync' ) );
		}

		try {
			return $this->read_debug_feed_rows( (string) $download['path'], $limit );
		} finally {
			$this->delete_temp_files( array( (string) $download['path'] ) );
		}
	}

	/**
	 * Parses a raw Telesystem feed sample without importing, pairing each raw row
	 * with the technical_attributes the current mapping would extract from it.
	 *
	 * @return array<string,mixed>
	 */
	private function read_debug_feed_rows( string $path, int $limit ): array {
		if ( ! is_readable( $path ) ) {
			return array( 'error' => __( 'Downloaded Telesystem feed file was unreadable.', 'schrack-woocommerce-sync' ) );
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return array( 'error' => __( 'Could not open the downloaded Telesystem feed file.', 'schrack-woocommerce-sync' ) );
		}

		$first_line = fgets( $handle );

		if ( false === $first_line || '' === trim( $first_line ) ) {
			fclose( $handle );
			return array( 'error' => __( 'Telesystem feed content was empty.', 'schrack-woocommerce-sync' ) );
		}

		$delimiter  = $this->detect_csv_delimiter_from_line( $first_line );
		$header_map = $this->normalize_csv_headers( str_getcsv( $first_line, $delimiter, '"', '\\' ) );
		$headers    = array_column( $header_map, 'key' );
		$labels     = array();

		foreach ( $header_map as $column ) {
			$labels[ (string) $column['key'] ] = (string) $column['label'];
		}

		if ( empty( $headers ) ) {
			fclose( $handle );
			return array( 'error' => __( 'Telesystem feed did not contain readable headers.', 'schrack-woocommerce-sync' ) );
		}

		$rows         = array();
		$started_at   = time();
		$capped_early = false;
		$line_number  = 0;

		while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			if ( count( $rows ) >= $limit ) {
				break;
			}

			++$line_number;

			if ( 0 === $line_number % 200 && $this->debug_export_should_stop( $started_at ) ) {
				$capped_early = true;
				break;
			}

			if ( $this->csv_values_are_blank( $values ) || $this->csv_values_are_footer( $values ) ) {
				continue;
			}

			$row = $this->combine_csv_row( $headers, $values );

			if ( empty( $row ) ) {
				continue;
			}

			$item = $this->normalize_telesystem_row( $row, $labels );

			$rows[] = array(
				'raw'                  => $row,
				'sku'                  => $item['sku'],
				'name'                 => $item['name'],
				'technical_attributes' => $item['technical_attributes'],
				'extracted_attributes' => $item['extracted_attributes'],
			);
		}

		fclose( $handle );

		return array(
			'format'       => 'CSV',
			'headers'      => $headers,
			'labels'       => $labels,
			'rows'         => $rows,
			'capped_early' => $capped_early,
		);
	}

	/**
	 * Checks whether a long-running debug export is close enough to the PHP
	 * memory or execution-time limit that it should stop and return what it has,
	 * rather than risk a hard fatal error that would leave a background job's
	 * status stuck at "running" forever.
	 */
	private function debug_export_should_stop( int $started_at ): bool {
		$limit = $this->debug_memory_limit_bytes();

		if ( $limit > 0 && memory_get_usage( true ) >= (int) floor( $limit * 0.70 ) ) {
			return true;
		}

		$max_execution_time = (int) ini_get( 'max_execution_time' );

		if ( $max_execution_time <= 0 ) {
			return false;
		}

		return time() - $started_at >= max( 10, $max_execution_time - 15 );
	}

	/**
	 * Returns the PHP memory_limit in bytes, or 0 when unlimited/unknown.
	 */
	private function debug_memory_limit_bytes(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || str_starts_with( $raw, '-' ) ) {
			return 0;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $raw );
		}

		return is_numeric( $raw ) ? max( 0, (int) $raw ) : 0;
	}

	/**
	 * Imports normalized Telesystem rows.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $status_context Extra status fields.
	 * @return array<string,mixed>
	 */
	public function import_items( array $items, array $status_context = array() ): array {
		$processed       = 0;
		$errors          = 0;
		$prices_synced   = 0;
		$stock_synced    = 0;
		$image_urls_seen = 0;

		$this->mapper->prime_product_ids_by_skus( $this->item_skus( $items ) );
		$this->mapper->prime_product_ids_by_source_item_numbers( self::SOURCE, $this->item_source_item_numbers( $items ) );
		$this->mapper->prime_category_cache();

		foreach ( $items as $item ) {
			try {
				$product = $this->mapper->upsert_product( $item );
				++$processed;

				$commercial = $this->apply_feed_commercial_data( $product, $item );

				if ( ! empty( $commercial['price_synced'] ) ) {
					++$prices_synced;
				}

				if ( ! empty( $commercial['stock_synced'] ) ) {
					++$stock_synced;
				}

				if ( '' !== $this->string_value( $item['image_url'] ?? '' ) ) {
					++$image_urls_seen;
				}
			} catch ( Throwable $exception ) {
				++$errors;
				$this->logger->error(
					self::OPERATION,
					'Failed to import Telesystem feed item.',
					$this->item_sku( $item ),
					array( 'error' => $exception->getMessage() )
				);
			}
		}

		$result = array_merge(
			array(
				'processed'       => $processed,
				'errors'          => $errors,
				'prices_synced'   => $prices_synced,
				'stock_synced'    => $stock_synced,
				'image_urls_seen' => $image_urls_seen,
			),
			$status_context
		);

		$this->settings->update_status( self::STATUS_KEY, $result );

		return $result;
	}

	/**
	 * Imports one cached feed batch and advances the cursor.
	 *
	 * @param array<string,mixed> $cache Parsed feed cache metadata.
	 * @return array<string,mixed>
	 */
	private function import_cached_items_with_cursor( array $cache, int $limit ): array {
		$total_items = absint( $cache['total_items'] ?? 0 );
		$signature   = (string) ( $cache['signature'] ?? '' );
		$feed_hash   = (string) ( $cache['feed_url_hash'] ?? '' );

		if ( 0 === $total_items ) {
			$this->delete_feed_cache( $signature );

			return $this->import_items(
				array(),
				array(
					'cursor'          => 0,
					'total_items'     => 0,
					'batch_start'     => 0,
					'batch_count'     => 0,
					'batch_limit'     => $limit,
					'completed_cycle' => 'yes',
					'feed_url_hash'   => $feed_hash,
				)
			);
		}

		$offset = $this->feed_cursor( $signature, $feed_hash, $total_items );
		$batch  = $this->read_feed_cache_batch( (string) $cache['items_path'], $offset, $limit );

		if ( empty( $batch ) && $offset > 0 ) {
			$offset = 0;
			$batch  = $this->read_feed_cache_batch( (string) $cache['items_path'], 0, $limit );
		}

		if ( empty( $batch ) && $offset < $total_items ) {
			$this->logger->warning(
				self::OPERATION,
				'Parsed Telesystem feed cache was unreadable; refreshing feed.',
				null,
				array(
					'feed_cache_key' => $cache['key'] ?? '',
					'cursor'         => $offset,
					'total_items'    => $total_items,
				)
			);
			$this->delete_feed_cache( $signature );

			return $this->import_from_feed( $limit );
		}

		$batch_count     = count( $batch );
		$next_cursor     = $offset + $batch_count;
		$completed_cycle = $next_cursor >= $total_items;

		if ( $completed_cycle ) {
			$next_cursor = 0;
			$this->delete_feed_cache( $signature );
		}

		return $this->import_items(
			$batch,
			array(
				'cursor'            => $next_cursor,
				'total_items'       => $total_items,
				'batch_start'       => $offset,
				'batch_count'       => $batch_count,
				'batch_limit'       => $limit,
				'completed_cycle'   => $completed_cycle ? 'yes' : 'no',
				'catalog_source'    => self::SOURCE,
				'feed_cache'        => 'hit',
				'feed_cache_key'    => $cache['key'] ?? '',
				'feed_url_hash'     => $feed_hash,
				'catalog_signature' => $signature,
			)
		);
	}

	/**
	 * Downloads the feed to a temporary file.
	 *
	 * @return array{path:string,bytes:int}|null
	 */
	private function download_feed_file( string $feed_url ): ?array {
		$temp_file = wp_tempnam( 'telesystem-feed' );

		if ( ! $temp_file ) {
			$this->logger->error( self::OPERATION, 'Could not create a temporary file for Telesystem feed download.' );
			return null;
		}

		$disable_curl_decoding = static function ( $handle ): void {
			if ( defined( 'CURLOPT_HTTP_CONTENT_DECODING' ) ) {
				curl_setopt( $handle, CURLOPT_HTTP_CONTENT_DECODING, false );
			}

			if ( defined( 'CURLOPT_ENCODING' ) ) {
				curl_setopt( $handle, CURLOPT_ENCODING, 'identity' );
			}
		};

		// Telesystem currently sends Content-Encoding: UTF-8, so avoid automatic decompression.
		add_action( 'http_api_curl', $disable_curl_decoding, 10, 1 );

		try {
			$response = wp_remote_get(
				$feed_url,
				array(
					'timeout'    => 120,
					'stream'     => true,
					'filename'   => $temp_file,
					'decompress' => false,
					'headers'    => array(
						'Accept'          => 'text/csv,text/plain,*/*;q=0.8',
						'Accept-Encoding' => 'identity',
					),
				)
			);
		} finally {
			remove_action( 'http_api_curl', $disable_curl_decoding, 10 );
		}

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp_file );
			$this->logger->error( self::OPERATION, 'Failed to download Telesystem feed.', null, array( 'error' => $response->get_error_message() ) );
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			wp_delete_file( $temp_file );
			$this->logger->error( self::OPERATION, 'Failed to download Telesystem feed.', null, array( 'status_code' => $status_code ) );
			return null;
		}

		$bytes = is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0;

		if ( $bytes <= 0 ) {
			wp_delete_file( $temp_file );
			$this->logger->warning( self::OPERATION, 'Downloaded Telesystem feed was empty.' );
			return null;
		}

		$this->logger->debug(
			self::OPERATION,
			'Downloaded Telesystem feed.',
			null,
			array(
				'status_code'      => $status_code,
				'content_type'     => wp_remote_retrieve_header( $response, 'content-type' ),
				'content_encoding' => wp_remote_retrieve_header( $response, 'content-encoding' ),
				'bytes'            => $bytes,
			)
		);

		return array(
			'path'  => $temp_file,
			'bytes' => $bytes,
		);
	}

	/**
	 * Parses the CSV feed into the JSONL cache used for batched imports.
	 *
	 * @return array<string,mixed>|null
	 */
	private function write_feed_cache_from_file( string $path, string $feed_url ): ?array {
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
			$this->logger->warning( self::OPERATION, 'Telesystem feed content was empty.' );
			return $this->empty_feed_cache( $feed_url );
		}

		$delimiter  = $this->detect_csv_delimiter_from_line( $first_line );
		$header_map = $this->normalize_csv_headers( str_getcsv( $first_line, $delimiter, '"', '\\' ) );
		$headers    = array_column( $header_map, 'key' );
		$labels     = array();
		$duplicates = $this->duplicate_header_summary( $header_map );

		foreach ( $header_map as $column ) {
			$labels[ (string) $column['key'] ] = (string) $column['label'];
		}

		if ( empty( $headers ) ) {
			fclose( $handle );
			$this->logger->warning( self::OPERATION, 'Telesystem feed did not contain readable headers.' );
			return $this->empty_feed_cache( $feed_url );
		}

		$items_temp = wp_tempnam( 'telesystem-items' );

		if ( ! $items_temp ) {
			fclose( $handle );
			$this->logger->warning( self::OPERATION, 'Could not create a temporary parsed Telesystem feed cache.' );
			return null;
		}

		$output = fopen( $items_temp, 'wb' );

		if ( false === $output ) {
			fclose( $handle );
			wp_delete_file( $items_temp );
			$this->logger->warning( self::OPERATION, 'Could not open parsed Telesystem feed cache for writing.' );
			return null;
		}

		$signature_context = hash_init( 'sha256' );
		$rows_seen         = 0;
		$rows_without_sku  = 0;
		$footer_rows       = 0;
		$written           = 0;
		$write_failed      = false;
		$header_count      = count( $headers );
		$short_rows        = 0;
		$extra_rows        = 0;
		$shortest_columns  = $header_count;
		$longest_columns   = $header_count;
		$malformed_samples = array();
		$line_number       = 1;

		while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			++$line_number;

			if ( $this->csv_values_are_blank( $values ) ) {
				continue;
			}

			if ( $this->csv_values_are_footer( $values ) ) {
				++$footer_rows;
				continue;
			}

			$value_count      = count( $values );
			$shortest_columns = min( $shortest_columns, $value_count );
			$longest_columns  = max( $longest_columns, $value_count );

			if ( $value_count < $header_count ) {
				++$short_rows;
			} elseif ( $value_count > $header_count ) {
				++$extra_rows;
			}

			if ( $value_count !== $header_count && count( $malformed_samples ) < 5 ) {
				$malformed_samples[] = array(
					'line'    => $line_number,
					'columns' => $value_count,
					'sku'     => sanitize_text_field( $this->string_value( $values[1] ?? '' ) ),
				);
			}

			$row = $this->combine_csv_row( $headers, $values );

			if ( empty( $row ) ) {
				continue;
			}

			++$rows_seen;
			$item = $this->normalize_telesystem_row( $row, $labels );

			if ( '' === $item['sku'] ) {
				++$rows_without_sku;
				continue;
			}

			hash_update( $signature_context, (string) $item['sku'] . "\n" );
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
			$this->logger->warning( self::OPERATION, 'Could not write parsed Telesystem feed cache.' );
			return null;
		}

		if ( 0 === $written ) {
			wp_delete_file( $items_temp );
			$this->logger->warning(
				self::OPERATION,
				'Telesystem feed rows were found, but no product code column was recognized.',
				null,
				array(
					'delimiter'        => $delimiter,
					'rows_seen'        => $rows_seen,
					'rows_without_sku' => $rows_without_sku,
					'footer_rows'      => $footer_rows,
					'header_columns'   => $header_count,
					'short_rows'       => $short_rows,
					'extra_rows'       => $extra_rows,
				)
			);

			return $this->empty_feed_cache( $feed_url, $signature );
		}

		$cache = $this->finalize_feed_cache( $feed_url, $signature, $items_temp, $written );

		if ( null !== $cache ) {
			$this->logger->debug(
				self::OPERATION,
				'Parsed Telesystem CSV feed.',
				null,
				array(
					'delimiter'                => $delimiter,
					'header_columns'           => $header_count,
					'duplicate_headers'        => count( $duplicates ),
					'duplicate_header_summary' => array_slice( $duplicates, 0, 20, true ),
					'rows_seen'                => $rows_seen,
					'items'                    => $written,
					'rows_without_sku'         => $rows_without_sku,
					'footer_rows'              => $footer_rows,
					'short_rows'               => $short_rows,
					'extra_rows'               => $extra_rows,
					'shortest_columns'         => $shortest_columns,
					'longest_columns'          => $longest_columns,
				)
			);
		}

		if ( $short_rows > 0 || $extra_rows > 0 ) {
			$this->logger->warning(
				self::OPERATION,
				'Telesystem feed contained rows with an unexpected column count; affected rows were padded or truncated.',
				null,
				array(
					'header_columns'    => $header_count,
					'short_rows'        => $short_rows,
					'extra_rows'        => $extra_rows,
					'shortest_columns'  => $shortest_columns,
					'longest_columns'   => $longest_columns,
					'malformed_samples' => $malformed_samples,
				)
			);
		}

		return $cache;
	}

	/**
	 * Normalizes one Telesystem CSV row to Product_Mapper data.
	 *
	 * @param array<string,mixed>  $row CSV row.
	 * @param array<string,string> $labels Header labels by normalized key.
	 * @return array<string,mixed>
	 */
	private function normalize_telesystem_row( array $row, array $labels ): array {
		$source_sku = sanitize_text_field( $this->clean_text( $this->row_value( $row, 'codprodus' ) ) );
		$images     = array_values(
			array_filter(
				array_map(
					array( $this, 'normalize_catalog_url' ),
					array(
						$this->row_value( $row, 'imagine1' ),
						$this->row_value( $row, 'imagine2' ),
						$this->row_value( $row, 'imagine3' ),
						$this->row_value( $row, 'imagine4' ),
					)
				)
			)
		);

		$category_path = array_values(
			array_filter(
				array_map(
					array( $this, 'clean_text' ),
					array(
						$this->row_value( $row, 'categorieprincipal' ),
						$this->row_value( $row, 'subcategoria1' ),
						$this->row_value( $row, 'subcategoria2' ),
						$this->row_value( $row, 'subcategoria3' ),
					)
				)
			)
		);

		$manufacturer = $this->clean_text( $this->row_value( $row, 'producator' ) );

		if ( '' === $manufacturer ) {
			$manufacturer = $this->clean_text( $this->row_value( $row, 'modelproducator' ) );
		}

		$item = array(
			'source'               => self::SOURCE,
			'supplier'             => $this->clean_text( $this->row_value( $row, 'furnizor' ) ) ?: 'TELESYSTEM',
			'sku'                  => $this->telesystem_product_sku( $source_sku ),
			'source_item_number'   => $source_sku,
			'name'                 => sanitize_text_field( $this->clean_text( $this->row_value( $row, 'denumire' ) ) ),
			'short_description'    => '',
			'description'          => $this->clean_html( $this->row_value( $row, 'descriereprodus' ) ),
			'manufacturer'         => sanitize_text_field( $manufacturer ),
			'ean'                  => sanitize_text_field( $this->clean_text( $this->row_value( $row, 'ean' ) ) ),
			'image_url'            => $images[0] ?? '',
			'image_urls'           => $images,
			'category_path'        => $category_path,
			'unit'                 => sanitize_text_field( $this->clean_text( $this->row_value( $row, 'um' ) ) ),
			'catalog_status'       => sanitize_text_field( $this->clean_text( $this->row_value( $row, 'stoc' ) ) ),
			'telesystem_price_1'   => $this->parse_decimal( $this->row_value( $row, 'pret1ron' ) ),
			'telesystem_price_2'   => $this->parse_decimal( $this->row_value( $row, 'pret2ron' ) ),
			'telesystem_stock'     => sanitize_text_field( $this->clean_text( $this->row_value( $row, 'stoc' ) ) ),
			'telesystem_special'   => sanitize_text_field( $this->clean_text( $this->row_value( $row, 'ofertaspeciala' ) ) ),
			'telesystem_weight_g'  => $this->parse_decimal( $this->row_value( $row, 'greutategrame' ) ),
			'telesystem_warranty'  => absint( $this->parse_decimal( $this->row_value( $row, 'garantieluni' ) ) ),
		);

		$item['technical_attributes'] = $this->technical_attributes( $row, $labels );
		$item['extracted_attributes'] = Schrack_Attribute_Extractor::extract( $item['name'] );
		$item['dynamic_technical_attributes'] = $this->dynamic_technical_attributes( $row, $labels );

		return $item;
	}

	/**
	 * Applies Telesystem price, stock, and supplier-specific metadata to an already
	 * loaded/saved product object (avoids a redundant wc_get_product() reload).
	 *
	 * @param WC_Product           $product Product already created/updated by upsert_product().
	 * @param array<string,mixed>  $item Normalized feed item.
	 * @return array{price_synced:bool,stock_synced:bool}
	 */
	private function apply_feed_commercial_data( WC_Product $product, array $item ): array {
		$product_id = $product->get_id();

		$price_1 = isset( $item['telesystem_price_1'] ) ? (float) $item['telesystem_price_1'] : 0.0;
		$price_2 = isset( $item['telesystem_price_2'] ) ? (float) $item['telesystem_price_2'] : 0.0;
		$price   = $this->woocommerce_price( $product_id, $price_1, $price_2 );

		if ( $price > 0 ) {
			$formatted_price = $this->format_price( $price );
			$product->set_regular_price( $formatted_price );
			$product->set_price( $formatted_price );
			$product->update_meta_data( '_telesystem_last_price_sync', current_time( 'mysql' ) );
		}

		$stock_text   = sanitize_text_field( $this->string_value( $item['telesystem_stock'] ?? '' ) );
		$stock_status = $this->woocommerce_stock_status( $stock_text );

		if ( '' !== $stock_text ) {
			$product->set_manage_stock( false );
			$product->set_stock_quantity( null );
			$product->set_stock_status( $stock_status );
			$product->set_backorders( 'onbackorder' === $stock_status ? 'notify' : 'no' );
			$product->update_meta_data( '_telesystem_last_stock_sync', current_time( 'mysql' ) );
		}

		$product->update_meta_data( '_telesystem_item_number', sanitize_text_field( $this->source_item_number( $item ) ) );
		$product->update_meta_data( '_telesystem_supplier', sanitize_text_field( $this->string_value( $item['supplier'] ?? 'TELESYSTEM' ) ) );
		$product->update_meta_data( '_telesystem_price_1', $price_1 > 0 ? $this->format_price( $price_1 ) : '' );
		$product->update_meta_data( '_telesystem_price_2', $price_2 > 0 ? $this->format_price( $price_2 ) : '' );
		$product->update_meta_data( '_telesystem_price_source', sanitize_key( (string) $this->settings->get( 'telesystem_price_source', 'pret2' ) ) );
		$product->update_meta_data( '_telesystem_vat_rate', $this->markup->vat_rate() );
		$product->update_meta_data( '_telesystem_stock_text', $stock_text );
		$product->update_meta_data( '_telesystem_special_offer', sanitize_text_field( $this->string_value( $item['telesystem_special'] ?? '' ) ) );
		$product->update_meta_data( '_telesystem_weight_grams', (float) ( $item['telesystem_weight_g'] ?? 0 ) );
		$product->update_meta_data( '_telesystem_warranty_months', absint( $item['telesystem_warranty'] ?? 0 ) );
		$product->update_meta_data( '_telesystem_image_urls', wp_json_encode( $item['image_urls'] ?? array() ) );
		$product->update_meta_data( '_telesystem_last_catalog_sync', current_time( 'mysql' ) );
		$product->save();

		$this->logger->debug(
			self::OPERATION,
			'Imported Telesystem product data.',
			$this->item_sku( $item ),
			array(
				'product_id'   => $product_id,
				'price'        => $price,
				'vat_rate'     => $this->markup->vat_rate(),
				'stock_text'   => $stock_text,
				'stock_status' => $stock_status,
			)
		);

		return array(
			'price_synced' => $price > 0,
			'stock_synced' => '' !== $stock_text,
		);
	}

	/**
	 * Chooses the WooCommerce regular price from Telesystem feed columns.
	 */
	private function woocommerce_price( int $product_id, float $price_1, float $price_2 ): float {
		$source = sanitize_key( (string) $this->settings->get( 'telesystem_price_source', 'pret2' ) );

		if ( 'pret1_markup' === $source && $price_1 > 0 ) {
			return $this->markup->calculate_sale_price( $price_1, $product_id );
		}

		if ( 'pret1' === $source ) {
			return $this->markup->apply_vat( $price_1 > 0 ? $price_1 : $price_2 );
		}

		return $this->markup->apply_vat( $price_2 > 0 ? $price_2 : $price_1 );
	}

	/**
	 * Maps feed stock text to WooCommerce stock status.
	 */
	private function woocommerce_stock_status( string $stock_text ): string {
		$key = $this->catalog_key( $stock_text );

		if ( str_contains( $key, 'lipsa' ) || str_contains( $key, 'epuizat' ) || str_contains( $key, 'nostoc' ) ) {
			return 'outofstock';
		}

		if ( str_contains( $key, 'lacomanda' ) || str_contains( $key, 'comanda' ) ) {
			return 'onbackorder';
		}

		return 'instock';
	}

	/**
	 * Returns the stored cursor, resetting it when the feed or SKU sequence changed.
	 */
	private function feed_cursor( string $signature, string $feed_hash, int $total_items ): int {
		$status = $this->settings->get_status();
		$row    = isset( $status[ self::STATUS_KEY ] ) && is_array( $status[ self::STATUS_KEY ] ) ? $status[ self::STATUS_KEY ] : array();

		if (
			$signature !== (string) ( $row['catalog_signature'] ?? '' ) ||
			$feed_hash !== (string) ( $row['feed_url_hash'] ?? '' )
		) {
			return 0;
		}

		$cursor = absint( $row['cursor'] ?? 0 );

		return $cursor < $total_items ? $cursor : 0;
	}

	/**
	 * Returns cache metadata for an in-progress Telesystem import cycle.
	 *
	 * @return array<string,mixed>|null
	 */
	private function active_feed_cache( string $feed_url ): ?array {
		$status = $this->settings->get_status();
		$row    = isset( $status[ self::STATUS_KEY ] ) && is_array( $status[ self::STATUS_KEY ] ) ? $status[ self::STATUS_KEY ] : array();
		$hash   = $this->feed_url_hash( $feed_url );

		if (
			'no' !== (string) ( $row['completed_cycle'] ?? 'yes' ) ||
			$hash !== (string) ( $row['feed_url_hash'] ?? '' ) ||
			'' === (string) ( $row['catalog_signature'] ?? '' ) ||
			absint( $row['cursor'] ?? 0 ) <= 0
		) {
			return null;
		}

		$cache = $this->feed_cache_metadata( (string) $row['catalog_signature'] );

		if ( null !== $cache ) {
			$this->logger->debug(
				self::OPERATION,
				'Using cached parsed Telesystem feed.',
				null,
				array(
					'feed_cache_key' => $cache['key'] ?? '',
					'cursor'         => absint( $row['cursor'] ?? 0 ),
					'total_items'    => absint( $cache['total_items'] ?? 0 ),
				)
			);
		}

		return $cache;
	}

	/**
	 * Reads one batch from a parsed JSONL feed cache.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function read_feed_cache_batch( string $path, int $offset, int $limit ): array {
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
	 * Creates a metadata-only empty cache descriptor.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_feed_cache( string $feed_url, string $signature = '' ): array {
		return array(
			'signature'     => '' !== $signature ? $signature : hash( 'sha256', '' ),
			'feed_url_hash' => $this->feed_url_hash( $feed_url ),
			'total_items'   => 0,
			'created_at'    => time(),
			'key'           => '',
		);
	}

	/**
	 * Moves a parsed temp file into the deterministic Telesystem cache path.
	 *
	 * @return array<string,mixed>|null
	 */
	private function finalize_feed_cache( string $feed_url, string $signature, string $items_temp, int $written ): ?array {
		$paths = $this->feed_cache_paths( $signature );

		if ( empty( $paths ) ) {
			wp_delete_file( $items_temp );
			return null;
		}

		$this->cleanup_feed_caches( (string) $paths['key'] );

		foreach ( array( 'items_path', 'meta_path' ) as $target_key ) {
			if ( ! empty( $paths[ $target_key ] ) && is_file( (string) $paths[ $target_key ] ) ) {
				wp_delete_file( (string) $paths[ $target_key ] );
			}
		}

		$meta_temp = (string) $paths['meta_path'] . '.tmp';
		$meta      = array(
			'signature'     => $signature,
			'feed_url_hash' => $this->feed_url_hash( $feed_url ),
			'total_items'   => $written,
			'created_at'    => time(),
			'key'           => $paths['key'],
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
			$this->logger->warning( self::OPERATION, 'Could not finalize parsed Telesystem feed cache.' );
			return null;
		}

		return array_merge(
			$meta,
			array(
				'items_path' => $paths['items_path'],
				'meta_path'  => $paths['meta_path'],
			)
		);
	}

	/**
	 * Returns parsed feed cache metadata when cache files are present and valid.
	 *
	 * @return array<string,mixed>|null
	 */
	private function feed_cache_metadata( string $signature ): ?array {
		$paths = $this->feed_cache_paths( $signature );

		if ( empty( $paths ) || ! is_readable( (string) $paths['items_path'] ) || ! is_readable( (string) $paths['meta_path'] ) ) {
			return null;
		}

		$meta_raw = file_get_contents( (string) $paths['meta_path'] );
		$meta     = is_string( $meta_raw ) ? json_decode( $meta_raw, true ) : null;

		if (
			! is_array( $meta ) ||
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
	 * Returns deterministic cache paths for a feed signature.
	 *
	 * @return array<string,string>
	 */
	private function feed_cache_paths( string $signature ): array {
		$dir = $this->feed_cache_dir();

		if ( '' === $dir ) {
			return array();
		}

		$key  = 'telesystem-' . substr( preg_replace( '/[^a-f0-9]/', '', strtolower( $signature ) ) ?? '', 0, 24 );
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
	private function feed_cache_dir(): string {
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
	 * Deletes a parsed feed cache.
	 */
	private function delete_feed_cache( string $signature ): void {
		$paths = $this->feed_cache_paths( $signature );

		foreach ( array( 'items_path', 'meta_path' ) as $key ) {
			if ( ! empty( $paths[ $key ] ) && file_exists( (string) $paths[ $key ] ) ) {
				wp_delete_file( (string) $paths[ $key ] );
			}
		}
	}

	/**
	 * Removes stale parsed Telesystem cache files.
	 */
	private function cleanup_feed_caches( string $keep_key = '' ): void {
		$dir = $this->feed_cache_dir();

		if ( '' === $dir ) {
			return;
		}

		$pattern = trailingslashit( $dir ) . 'catalog-telesystem-*';

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
	 * Returns technical attributes from non-core, non-commercial feed columns.
	 *
	 * @param array<string,mixed>  $row CSV row.
	 * @param array<string,string> $labels Header labels by normalized key.
	 * @return array<int,array{label:string,value:string}>
	 */
	private function technical_attributes( array $row, array $labels ): array {
		$items     = array();
		$seen      = array();
		$excluded  = $this->excluded_technical_keys();
		$sensitive = $this->sensitive_technical_keys();

		foreach ( $row as $key => $value ) {
			$key = (string) $key;

			if ( isset( $excluded[ $this->catalog_key( $key ) ] ) || $this->catalog_key_matches_any( $this->catalog_key( $key ), $sensitive ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = $this->clean_text( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$label    = sanitize_text_field( $labels[ $key ] ?? $key );
			$seen_key = $this->catalog_key( $label ) . ':' . $this->catalog_key( $value );

			if ( isset( $seen[ $seen_key ] ) ) {
				continue;
			}

			$seen[ $seen_key ] = true;
			$items[] = array(
				'label' => $label,
				'value' => sanitize_text_field( $value ),
			);
		}

		return $items;
	}

	/**
	 * Returns technical attribute candidates for promotion into real WooCommerce
	 * product attributes, keyed by their raw feed column instead of deduped by
	 * label+value like technical_attributes(). Telesystem's CSV is a wide,
	 * per-category schema where the same human label (e.g. "Tip") is reused by
	 * unrelated column blocks for cameras, access control, networking, etc. --
	 * keying by the raw column keeps those value domains from merging into one
	 * confusing filter. Values that look like free text or URLs are skipped so
	 * only genuinely categorical candidates reach the mapper.
	 *
	 * @param array<string,mixed>  $row    Raw feed row.
	 * @param array<string,string> $labels Raw column key => human label.
	 * @return array<string,array{label:string,value:string}> Raw column key => {label, value}.
	 */
	private function dynamic_technical_attributes( array $row, array $labels ): array {
		$items     = array();
		$excluded  = $this->excluded_technical_keys();
		$sensitive = $this->sensitive_technical_keys();

		foreach ( $row as $key => $value ) {
			$key = (string) $key;

			if ( isset( $excluded[ $this->catalog_key( $key ) ] ) || $this->catalog_key_matches_any( $this->catalog_key( $key ), $sensitive ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = $this->clean_text( (string) $value );

			if ( '' === $value || mb_strlen( $value ) > 40 || false !== stripos( $value, 'http' ) ) {
				continue;
			}

			$items[ $key ] = array(
				'label' => sanitize_text_field( $labels[ $key ] ?? $key ),
				'value' => sanitize_text_field( $value ),
			);
		}

		return $items;
	}

	/**
	 * Returns normalized feed keys mapped to first-class product fields.
	 *
	 * @return array<string,bool>
	 */
	private function excluded_technical_keys(): array {
		$keys = array(
			'furnizor',
			'codprodus',
			'denumire',
			'categorieprincipal',
			'subcategoria1',
			'subcategoria2',
			'subcategoria3',
			'descriereprodus',
			'imagine1',
			'imagine2',
			'imagine3',
			'imagine4',
			'modelproducator',
			'greutategrame',
			'garantieluni',
			'producator',
			'pret1ron',
			'pret2ron',
			'stoc',
			'um',
			'ofertaspeciala',
			'ean',
		);

		return array_fill_keys( array_map( array( $this, 'catalog_key' ), $keys ), true );
	}

	/**
	 * Returns feed keys that should not be exposed as public technical attributes.
	 *
	 * @return array<string,bool>
	 */
	private function sensitive_technical_keys(): array {
		$keys = array(
			'cost',
			'currency',
			'discount',
			'import',
			'internal',
			'oferta',
			'password',
			'pret',
			'price',
			'private',
			'secret',
			'session',
			'stoc',
			'token',
			'warehouse',
		);

		return array_fill_keys( array_map( array( $this, 'catalog_key' ), $keys ), true );
	}

	/**
	 * Checks a normalized key against a lookup, allowing duplicate-column suffixes.
	 *
	 * @param array<string,bool> $lookup Normalized key lookup.
	 */
	private function catalog_key_matches_any( string $key, array $lookup ): bool {
		foreach ( $lookup as $lookup_key => $enabled ) {
			if ( $enabled && ( $key === $lookup_key || str_contains( $key, $lookup_key ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes CSV headers and keeps duplicate/empty columns addressable.
	 *
	 * @param array<int,mixed> $headers Raw headers.
	 * @return array<int,array{key:string,label:string}>
	 */
	private function normalize_csv_headers( array $headers ): array {
		$normalized = array();
		$seen       = array();

		foreach ( $headers as $index => $header ) {
			$label = trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header ) ?? (string) $header );
			$key   = $this->catalog_key( $label );

			if ( '' === $key ) {
				$key = 'column_' . $index;
			}

			if ( isset( $seen[ $key ] ) ) {
				$key = $key . '_' . $index;
			}

			$seen[ $key ] = true;
			$normalized[] = array(
				'key'   => $key,
				'label' => '' !== $label ? $label : 'Column ' . $index,
			);
		}

		return $normalized;
	}

	/**
	 * Returns duplicate normalized header counts for feed diagnostics.
	 *
	 * @param array<int,array{key:string,label:string}> $header_map Normalized header metadata.
	 * @return array<string,int>
	 */
	private function duplicate_header_summary( array $header_map ): array {
		$counts = array();

		foreach ( $header_map as $column ) {
			$base_key = $this->catalog_key( (string) ( $column['label'] ?? '' ) );

			if ( '' === $base_key ) {
				$base_key = $this->catalog_key( (string) ( $column['key'] ?? '' ) );
			}

			if ( '' === $base_key ) {
				continue;
			}

			$counts[ $base_key ] = ( $counts[ $base_key ] ?? 0 ) + 1;
		}

		$duplicates = array_filter(
			$counts,
			static fn( int $count ): bool => $count > 1
		);
		arsort( $duplicates );

		return $duplicates;
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
	 * Detects the most likely CSV delimiter from one header line.
	 */
	private function detect_csv_delimiter_from_line( string $first_line ): string {
		$candidates   = array( ',', ';', "\t", '|' );
		$best         = ',';
		$best_columns = 0;

		foreach ( $candidates as $candidate ) {
			$columns = count( str_getcsv( $first_line, $candidate, '"', '\\' ) );

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
	 * Detects the timestamp footer row currently appended after Telesystem product rows.
	 *
	 * @param mixed $values CSV values.
	 */
	private function csv_values_are_footer( mixed $values ): bool {
		if ( ! is_array( $values ) || empty( $values ) ) {
			return false;
		}

		$first = is_scalar( $values[0] ?? null ) ? trim( (string) $values[0] ) : '';

		if ( ! preg_match( '/^\d{1,2}-\d{1,2}-\d{4}\s+\d{1,2}:\d{2}$/', $first ) ) {
			return false;
		}

		foreach ( array_slice( $values, 1 ) as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extracts SKUs from a normalized batch for lookup priming.
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
	 * Extracts source item numbers from a normalized batch for lookup priming.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 * @return array<int,string>
	 */
	private function item_source_item_numbers( array $items ): array {
		$item_numbers = array();

		foreach ( $items as $item ) {
			$item_number = $this->source_item_number( $item );

			if ( '' !== $item_number ) {
				$item_numbers[] = $item_number;
			}
		}

		return $item_numbers;
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
	 * Returns the supplier item number stored separately from the WooCommerce SKU.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 */
	private function source_item_number( array $item ): string {
		$item_number = isset( $item['source_item_number'] ) && is_scalar( $item['source_item_number'] )
			? sanitize_text_field( trim( (string) $item['source_item_number'] ) )
			: '';

		if ( '' !== $item_number ) {
			return $item_number;
		}

		return isset( $item['sku'] ) && is_scalar( $item['sku'] )
			? sanitize_text_field( trim( (string) $item['sku'] ) )
			: '';
	}

	/**
	 * Builds a WooCommerce SKU that cannot collide with Schrack catalog products.
	 */
	private function telesystem_product_sku( string $source_sku ): string {
		$source_sku = sanitize_text_field( trim( $source_sku ) );

		return '' !== $source_sku ? 'TS-' . $source_sku : '';
	}

	/**
	 * Reads a scalar row value by normalized key.
	 *
	 * @param array<string,mixed> $row CSV row.
	 */
	private function row_value( array $row, string $key ): string {
		$key = $this->catalog_key( $key );

		if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) {
			return (string) $row[ $key ];
		}

		return '';
	}

	/**
	 * Converts Romanian/CSV decimal text to float.
	 */
	private function parse_decimal( string $value ): float {
		$value = trim( html_entity_decode( $value, ENT_QUOTES ) );

		if ( '' === $value ) {
			return 0.0;
		}

		$value = preg_replace( '/[^\d,.\-]/', '', $value ) ?? '';

		if ( str_contains( $value, ',' ) && str_contains( $value, '.' ) ) {
			$value = str_replace( ',', '', $value );
		} else {
			$value = str_replace( ',', '.', $value );
		}

		return is_numeric( $value ) ? max( 0.0, (float) $value ) : 0.0;
	}

	/**
	 * Formats a product price for WooCommerce.
	 */
	private function format_price( float $price ): string {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return function_exists( 'wc_format_decimal' )
			? wc_format_decimal( max( 0.0, $price ), $decimals )
			: number_format( max( 0.0, $price ), $decimals, '.', '' );
	}

	/**
	 * Normalizes text while tolerating bad remote feed encoding.
	 */
	private function clean_text( string $value ): string {
		$value = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = $this->repair_mojibake( $value );
		$value = wp_check_invalid_utf8( $value, true );

		return trim( wp_strip_all_tags( $value ) );
	}

	/**
	 * Normalizes trusted product-description HTML while fixing common feed mojibake.
	 */
	private function clean_html( string $value ): string {
		$value = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = $this->repair_mojibake( $value );
		$value = wp_check_invalid_utf8( $value, true );

		return wp_kses_post( $value );
	}

	/**
	 * Repairs common double-encoded UTF-8 sequences present in the Telesystem CSV.
	 */
	private function repair_mojibake( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$value = strtr(
			$value,
			array(
				"\xC3\x82\xC2\xB0"             => "\xC2\xB0",
				"\xC3\x82\xC2\xB1"             => "\xC2\xB1",
				"\xC3\x83\xC2\x97"             => "\xC3\x97",
				"\xC3\xA2\xC2\x80\xC2\x93"     => "\xE2\x80\x93",
				"\xC3\xA2\xC2\x80\xC2\x94"     => "\xE2\x80\x94",
				"\xC3\xA2\xC2\x80\xC2\x99"     => "\xE2\x80\x99",
				"\xC3\xA2\xC2\x80\xC2\x9C"     => "\xE2\x80\x9C",
				"\xC3\xA2\xC2\x80\xC2\x9D"     => "\xE2\x80\x9D",
				"\xC3\xA2\xC2\x82\xC2\xAC"     => "\xE2\x82\xAC",
			)
		);

		if ( ! function_exists( 'mb_convert_encoding' ) || ! $this->looks_like_mojibake( $value ) ) {
			return $value;
		}

		$bytes = @mb_convert_encoding( $value, 'ISO-8859-1', 'UTF-8' );

		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return $value;
		}

		$roundtrip = @mb_convert_encoding( $bytes, 'UTF-8', 'ISO-8859-1' );

		if ( $roundtrip !== $value ) {
			return $value;
		}

		$repaired = @mb_convert_encoding( $bytes, 'UTF-8', 'UTF-8' );

		if ( ! is_string( $repaired ) || '' === $repaired ) {
			return $value;
		}

		return $this->mojibake_score( $repaired ) < $this->mojibake_score( $value ) ? $repaired : $value;
	}

	/**
	 * Detects common mojibake marker bytes after they were encoded as UTF-8 text.
	 */
	private function looks_like_mojibake( string $value ): bool {
		return str_contains( $value, "\xC3\x83" )
			|| str_contains( $value, "\xC3\x82" )
			|| str_contains( $value, "\xC3\xA2\xC2\x80" )
			|| str_contains( $value, "\xEF\xBF\xBD" )
			|| 1 === preg_match( '/[\x{0080}-\x{009F}]/u', $value );
	}

	/**
	 * Scores how many mojibake markers remain in a string.
	 */
	private function mojibake_score( string $value ): int {
		$score = substr_count( $value, "\xC3\x83" )
			+ substr_count( $value, "\xC3\x82" )
			+ substr_count( $value, "\xC3\xA2\xC2\x80" )
			+ substr_count( $value, "\xEF\xBF\xBD" );

		if ( preg_match_all( '/[\x{0080}-\x{009F}]/u', $value, $matches ) ) {
			$score += count( $matches[0] );
		}

		return $score;
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

		$value = esc_url_raw( $value );

		if ( preg_match( '/^http:\/\//i', $value ) ) {
			$value = 'https://' . substr( $value, 7 );
		}

		return $value;
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
	 * Returns the configured Telesystem feed URL.
	 */
	private function feed_url(): string {
		$url = esc_url_raw( trim( (string) $this->settings->get( 'telesystem_feed_url', self::DEFAULT_FEED_URL ) ) );

		return '' !== $url ? $url : self::DEFAULT_FEED_URL;
	}

	/**
	 * Builds a stable hash for the current feed URL.
	 */
	private function feed_url_hash( string $feed_url ): string {
		return hash( 'sha256', $feed_url );
	}

	/**
	 * Returns a scalar value as string.
	 */
	private function string_value( mixed $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * Deletes temporary source files.
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
}
