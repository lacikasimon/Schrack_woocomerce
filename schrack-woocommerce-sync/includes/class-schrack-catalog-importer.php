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
	 * @return array<string,int>
	 */
	public function import_from_soap( string $format = 'CSV', int $limit = 25 ): array {
		$raw = $this->client->get_catalog_as( $format );
		$items = $this->parse_catalog_response( $raw, $format );

		return $this->import_items( array_slice( $items, 0, max( 1, $limit ) ) );
	}

	/**
	 * Imports normalized catalog rows.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<string,int>
	 */
	public function import_items( array $items ): array {
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
					isset( $item['sku'] ) ? (string) $item['sku'] : null,
					array( 'error' => $exception->getMessage() )
				);
			}
		}

		$this->settings->update_status(
			'catalog',
			array(
				'processed' => $processed,
				'errors'    => $errors,
			)
		);

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
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
	 * Writes downloaded binary data to a temporary file through WP_Filesystem.
	 */
	private function write_temp_file( string $path, string $body ): bool {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents( $path, $body, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
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
		$headers   = array_map( 'sanitize_key', str_getcsv( array_shift( $lines ), $delimiter ) );
		$items     = array();

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$row = array_combine( $headers, str_getcsv( $line, $delimiter ) );

			if ( ! is_array( $row ) ) {
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
		$xml = simplexml_load_string( $content );

		if ( false === $xml ) {
			$this->logger->warning( 'catalog', 'Unable to parse Schrack XML catalog response.' );
			return array();
		}

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
	 * Normalizes a parser row to mapper data.
	 *
	 * @param array<string,mixed> $row Raw parser row.
	 * @return array<string,mixed>
	 */
	private function normalize_catalog_row( array $row ): array {
		$get = static function ( array $keys ) use ( $row ): string {
			foreach ( $keys as $key ) {
				$normalized = sanitize_key( $key );
				if ( isset( $row[ $normalized ] ) && '' !== $row[ $normalized ] ) {
					return is_scalar( $row[ $normalized ] ) ? (string) $row[ $normalized ] : '';
				}
				if ( isset( $row[ $key ] ) && '' !== $row[ $key ] ) {
					return is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
				}
			}

			return '';
		};

		return array(
			'sku'               => sanitize_text_field( $get( array( 'sku', 'item_number', 'itemnumber', 'article', 'artikelnr' ) ) ),
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
}
