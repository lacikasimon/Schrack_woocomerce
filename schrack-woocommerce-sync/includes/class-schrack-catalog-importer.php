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
		$raw   = $this->client->get_catalog_as( $format );
		$items = $this->parse_catalog_response( $raw, $format );

		return $this->import_items_with_cursor( $items, $format, max( 1, $limit ) );
	}

	/**
	 * Imports normalized catalog rows.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $status_context Extra status fields.
	 * @return array<string,mixed>
	 */
	public function import_items( array $items, array $status_context = array() ): array {
		$processed = 0;
		$errors    = 0;

		foreach ( $items as $item ) {
			try {
				$this->mapper->upsert( $item );
				++$processed;
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
				'processed' => $processed,
				'errors'    => $errors,
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
	 * Imports a catalog batch and advances the persisted cursor.
	 *
	 * @param array<int,array<string,mixed>> $items Parsed catalog items.
	 * @return array<string,mixed>
	 */
	private function import_items_with_cursor( array $items, string $format, int $limit ): array {
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

		$signature = $this->catalog_signature( $items );
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
				'catalog_format'    => $format,
				'catalog_signature' => $signature,
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
			$content = $this->download_catalog_content( $content );
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
		$lines = preg_split( '/\r\n|\n|\r/', trim( $content ) );

		if ( empty( $lines ) ) {
			return array();
		}

		$delimiter = str_contains( $lines[0], ';' ) ? ';' : ',';
		$headers   = $this->normalize_csv_headers( str_getcsv( array_shift( $lines ), $delimiter ) );
		$items     = array();

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

			$item = $this->normalize_catalog_row( $row );

			if ( '' !== $item['sku'] ) {
				$items[] = $item;
			}
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
			$key    = sanitize_key( (string) $header );

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
		$get = fn ( array $keys ): string => $this->find_catalog_value( $row, $keys );

		return array(
			'sku'               => sanitize_text_field( $get( array( 'sku', 'id', 'item_id', 'itemid', 'item_number', 'itemnumber', 'item_no', 'itemno', 'article', 'article_number', 'articlenumber', 'artikelnr' ) ) ),
			'name'              => sanitize_text_field( $get( array( 'name', 'title', 'description_short', 'bezeichnung' ) ) ),
			'short_description' => wp_kses_post( $get( array( 'short_description', 'shortdescription', 'description_short' ) ) ),
			'description'       => wp_kses_post( $get( array( 'description', 'long_description', 'longdescription' ) ) ),
			'manufacturer'      => sanitize_text_field( $get( array( 'manufacturer', 'brand', 'hersteller' ) ) ),
			'ean'               => sanitize_text_field( $get( array( 'ean', 'gtin' ) ) ),
			'category_path'     => sanitize_text_field( $get( array( 'category_path', 'category', 'warenhauptgruppe' ) ) ),
			'unit'              => sanitize_text_field( $get( array( 'unit', 'uom', 'measure' ) ) ),
			'catalog_status'    => sanitize_text_field( $get( array( 'catalog_status', 'status' ) ) ),
		);
	}

	/**
	 * Finds the first scalar catalog value by broad key aliases, including nested XML rows.
	 *
	 * @param array<int|string,mixed> $row Raw parser row.
	 * @param array<int,string>   $keys Key aliases.
	 */
	private function find_catalog_value( array $row, array $keys ): string {
		$normalized_keys = array_map( 'sanitize_key', $keys );

		foreach ( $row as $key => $value ) {
			$normalized_key = sanitize_key( (string) $key );

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
