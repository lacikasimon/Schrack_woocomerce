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
	private const SCHRACK_ATTRIBUTE_COLUMN_PREFIX = 'schrack_attribute:';

	/** @var array<string,bool>|null Null exports every scalar meta key. */
	private ?array $schrack_meta_keys_to_export = null;
	/** @var array<string,array{name:string,label:string,taxonomy:bool}> */
	private array $schrack_separate_attributes = array();

	/**
	 * Creates a stable internal column ID containing everything re-import needs.
	 */
	public static function schrack_attribute_column_id( string $name, string $label, bool $taxonomy ): string {
		$payload = wp_json_encode(
			array(
				'name'     => $name,
				'label'    => $label,
				'taxonomy' => $taxonomy,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return self::SCHRACK_ATTRIBUTE_COLUMN_PREFIX . rtrim( strtr( base64_encode( (string) $payload ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes a separate attribute column ID.
	 *
	 * @return array{name:string,label:string,taxonomy:bool}|null
	 */
	public static function schrack_decode_attribute_column_id( string $column_id ): ?array {
		if ( ! str_starts_with( $column_id, self::SCHRACK_ATTRIBUTE_COLUMN_PREFIX ) ) {
			return null;
		}

		$encoded = substr( $column_id, strlen( self::SCHRACK_ATTRIBUTE_COLUMN_PREFIX ) );
		$padding = strlen( $encoded ) % 4;

		if ( $padding > 0 ) {
			$encoded .= str_repeat( '=', 4 - $padding );
		}

		$json = base64_decode( strtr( $encoded, '-_', '+/' ), true );

		if ( false === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		$name = is_array( $data ) && isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		$label = is_array( $data ) && isset( $data['label'] ) ? trim( (string) $data['label'] ) : '';

		if (
			'' === $name ||
			'' === $label ||
			strlen( $name ) > 191 ||
			strlen( $label ) > 255 ||
			preg_match( '/[\x00-\x1F\x7F]/', $name ) ||
			preg_match( '/[\x00-\x1F\x7F]/', $label )
		) {
			return null;
		}

		return array(
			'name'     => $name,
			'label'    => $label,
			'taxonomy' => ! empty( $data['taxonomy'] ),
		);
	}

	/**
	 * Supplier values promoted from opaque Meta columns to readable CSV fields.
	 *
	 * @return array<string,string> Column ID => product meta key.
	 */
	public static function schrack_supplier_column_meta_map(): array {
		return array(
			'supplier_source'             => '_schrack_catalog_source',
			'supplier_purchase_price'     => '_schrack_purchase_price',
			'supplier_purchase_price_raw' => '_schrack_purchase_price_raw',
			'telesystem_price_1'           => '_telesystem_price_1',
			'telesystem_price_2'           => '_telesystem_price_2',
		);
	}

	/**
	 * Human-readable labels shared by complete exports and the header builder.
	 *
	 * @return array<string,string>
	 */
	public static function schrack_supplier_column_names(): array {
		return array(
			'supplier_source'             => __( 'Furnizor', 'schrack-woocommerce-sync' ),
			'supplier_purchase_price'     => __( 'Preț achiziție furnizor', 'schrack-woocommerce-sync' ),
			'supplier_purchase_price_raw' => __( 'Preț furnizor original (sursă)', 'schrack-woocommerce-sync' ),
			'telesystem_price_1'           => __( 'Preț furnizor Telesystem 1', 'schrack-woocommerce-sync' ),
			'telesystem_price_2'           => __( 'Preț furnizor Telesystem 2', 'schrack-woocommerce-sync' ),
		);
	}

	/**
	 * Adds the readable supplier fields to WooCommerce's standard CSV header.
	 *
	 * @return array<string,string>
	 */
	public function get_default_column_names() {
		$columns  = parent::get_default_column_names();
		$result   = array();
		$supplier = self::schrack_supplier_column_names();
		$inserted = false;

		foreach ( $columns as $column_id => $column_name ) {
			$result[ $column_id ] = $column_name;

			if ( 'regular_price' === $column_id ) {
				$result   = array_merge( $result, $supplier );
				$inserted = true;
			}
		}

		return $inserted ? $result : array_merge( $result, $supplier );
	}

	/**
	 * Builds one associative WooCommerce CSV row.
	 *
	 * @return array<string,mixed>
	 */
	public function schrack_product_row( WC_Product $product ): array {
		$row = $this->generate_row_data( $product );

		foreach ( $this->schrack_separate_attributes as $column_id => $definition ) {
			$row[ $column_id ] = $this->schrack_separate_attribute_value( $product, $definition['name'], $definition['taxonomy'] );
		}

		return $row;
	}

	/**
	 * Limits custom metadata to the keys selected in the header builder.
	 *
	 * @param array<int,string>|null $meta_keys Null keeps the complete backup behavior.
	 */
	public function schrack_set_meta_keys( ?array $meta_keys ): void {
		$this->schrack_meta_keys_to_export = null === $meta_keys
			? null
			: array_fill_keys( array_values( array_unique( array_map( 'strval', $meta_keys ) ) ), true );
	}

	/**
	 * Appends one stable CSV column for every discovered attribute name.
	 *
	 * @param array<string,array{name:string,label:string,taxonomy:bool}> $attributes Column definitions.
	 */
	public function schrack_set_separate_attributes( array $attributes ): void {
		$this->schrack_separate_attributes = array();

		foreach ( $attributes as $column_id => $definition ) {
			$decoded = self::schrack_decode_attribute_column_id( (string) $column_id );

			if ( null === $decoded || ! is_array( $definition ) ) {
				continue;
			}

			$this->schrack_separate_attributes[ $column_id ] = $decoded;
			$this->column_names[ $column_id ] = sprintf(
				/* translators: 1: readable attribute label, 2: stable WooCommerce attribute name. */
				__( 'Atribut: %1$s [%2$s]', 'schrack-woocommerce-sync' ),
				$decoded['label'],
				$decoded['name']
			);
		}
	}

	/**
	 * Reads one parent/simple/variation attribute and returns its visible values.
	 */
	private function schrack_separate_attribute_value( WC_Product $product, string $name, bool $taxonomy ): string {
		$attributes = $product->get_attributes();
		$attribute  = $attributes[ $name ] ?? $attributes[ sanitize_title( $name ) ] ?? null;

		if ( null === $attribute ) {
			foreach ( $attributes as $candidate_name => $candidate ) {
				$candidate_object_name = $candidate instanceof WC_Product_Attribute ? $candidate->get_name() : (string) $candidate_name;

				if ( $candidate_object_name === $name || sanitize_title( $candidate_object_name ) === sanitize_title( $name ) ) {
					$attribute = $candidate;
					break;
				}
			}
		}

		if ( $attribute instanceof WC_Product_Attribute ) {
			$values = array();

			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() as $term ) {
					if ( $term instanceof WP_Term ) {
						$values[] = html_entity_decode( (string) $term->name, ENT_QUOTES );
					}
				}
			} else {
				$values = array_map( 'strval', $attribute->get_options() );
			}

			return $this->schrack_implode_attribute_values( $values );
		}

		if ( ! is_scalar( $attribute ) || '' === (string) $attribute ) {
			return '';
		}

		$value = (string) $attribute;

		if ( $taxonomy ) {
			$term = get_term_by( 'slug', $value, $name );

			if ( $term instanceof WP_Term ) {
				$value = (string) $term->name;
			}
		}

		return $this->schrack_implode_attribute_values( array( $value ) );
	}

	/**
	 * Escapes literal backslashes as well as commas so a value ending in a
	 * backslash cannot consume the separator before the following value.
	 *
	 * @param array<int,mixed> $values Attribute values.
	 */
	private function schrack_implode_attribute_values( array $values ): string {
		$escaped = array();

		foreach ( $values as $value ) {
			$value     = is_scalar( $value ) ? html_entity_decode( (string) $value, ENT_QUOTES ) : '';
			$escaped[] = str_replace( array( '\\', ',' ), array( '\\\\', '\\,' ), $value );
		}

		return implode( ', ', $escaped );
	}

	/**
	 * Avoids customer/B2B price filters during an administrative backup.
	 */
	protected function get_column_value_sale_price( $product ) {
		return $this->schrack_fixed_localized_price( $product->get_sale_price( 'edit' ) );
	}

	/**
	 * Avoids customer/B2B price filters during an administrative backup.
	 */
	protected function get_column_value_regular_price( $product ) {
		return $this->schrack_fixed_localized_price( $product->get_regular_price( 'edit' ) );
	}

	/**
	 * Exports a readable supplier name without exposing its internal meta key.
	 */
	protected function get_column_value_supplier_source( $product ): string {
		$source = sanitize_key( (string) $product->get_meta( '_schrack_catalog_source', true, 'edit' ) );

		return match ( $source ) {
			'schrack'    => 'Schrack',
			'telesystem' => 'Telesystem',
			default      => $source,
		};
	}

	/**
	 * Exports the normalized supplier purchase price as a localized number.
	 */
	protected function get_column_value_supplier_purchase_price( $product ): string {
		return $this->schrack_fixed_localized_price( $product->get_meta( '_schrack_purchase_price', true, 'edit' ) );
	}

	/**
	 * Exports the supplier's original feed price before unit conversion.
	 */
	protected function get_column_value_supplier_purchase_price_raw( $product ): string {
		return $this->schrack_fixed_localized_price( $product->get_meta( '_schrack_purchase_price_raw', true, 'edit' ) );
	}

	/**
	 * Exports Telesystem's first supplier price as a localized number.
	 */
	protected function get_column_value_telesystem_price_1( $product ): string {
		return $this->schrack_fixed_localized_price( $product->get_meta( '_telesystem_price_1', true, 'edit' ) );
	}

	/**
	 * Exports Telesystem's second supplier price as a localized number.
	 */
	protected function get_column_value_telesystem_price_2( $product ): string {
		return $this->schrack_fixed_localized_price( $product->get_meta( '_telesystem_price_2', true, 'edit' ) );
	}

	/**
	 * Keeps the shop decimal separator and always exports WooCommerce's configured
	 * number of decimals, including zero-decimal source values such as 786.00.
	 */
	private function schrack_fixed_localized_price( mixed $price ): string {
		if ( '' === $price || null === $price ) {
			return '';
		}

		$decimals = max( 0, wc_get_price_decimals() );
		$decimal  = wc_format_decimal( $price, $decimals, false );

		return '' === $decimal ? '' : wc_format_localized_price( $decimal );
	}

	/**
	 * Exports either every scalar meta field or only the explicitly selected
	 * supplier/custom keys while retaining WooCommerce's importable header form.
	 *
	 * @param WC_Product         $product Product object.
	 * @param array<string,mixed> $row Row data passed by reference.
	 */
	protected function prepare_meta_for_export( $product, &$row ) {
		if ( null === $this->schrack_meta_keys_to_export ) {
			parent::prepare_meta_for_export( $product, $row );
			return;
		}

		if ( ! $this->enable_meta_export || empty( $this->schrack_meta_keys_to_export ) ) {
			return;
		}

		$meta_keys_to_skip = apply_filters( 'woocommerce_product_export_skip_meta_keys', array(), $product );

		foreach ( $product->get_meta_data() as $meta ) {
			if (
				! isset( $this->schrack_meta_keys_to_export[ $meta->key ] ) ||
				in_array( $meta->key, $meta_keys_to_skip, true )
			) {
				continue;
			}

			$meta_value = apply_filters( 'woocommerce_product_export_meta_value', $meta->value, $meta, $product, $row );

			if ( ! is_scalar( $meta_value ) ) {
				continue;
			}

			$column_key                         = 'meta:' . esc_attr( $meta->key );
			$this->column_names[ $column_key ] = sprintf( __( 'Meta: %s', 'woocommerce' ), $meta->key );
			$row[ $column_key ]                = $meta_value;
		}
	}
}

class Schrack_Product_Exporter {
	public const STATUS_KEY = 'product_export';
	public const STATUS_OPTION = 'schrack_wc_product_export_status';
	public const HOOK       = 'schrack_wc_sync_product_export_batch';

	private const GROUP            = 'schrack-wc-sync';
	private const BATCH_SIZE       = 1000;
	private const BATCH_TIME_BUDGET = 25.0;
	private const EXPORT_DIRECTORY = 'schrack-private-exports';
	private const FILE_MAX_AGE     = 7 * DAY_IN_SECONDS;
	private const FINALIZE_COPY_BUDGET = 256 * 1024 * 1024;
	private const FINALIZE_CHUNK_SIZE  = 256 * 1024;
	private const FINALIZE_TIME_BUDGET = 20.0;
	private const STRUCTURED_META_PREFIX = 'schrack-wc-json:v1:';
	private const ESCAPED_STRING_PREFIX  = 'schrack-wc-string:v1:';
	private const COLUMN_META_CACHE_KEY  = 'schrack_wc_export_supplier_meta_keys_v1';
	private const ATTRIBUTE_SCAN_BATCH_SIZE = 1000;
	private const MAX_SEPARATE_ATTRIBUTE_COLUMNS = 1000;
	private const MAX_CUSTOM_COLUMNS = 1000;

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
	 * Returns selectable WooCommerce and supplier columns for the admin builder.
	 * Supplier keys are cached because the export page refreshes while jobs run.
	 *
	 * @return array{standard:array<string,string>,supplier:array<string,string>,supplier_meta:array<string,string>}
	 */
	public function column_catalog(): array {
		global $wpdb;

		$catalog  = $this->default_column_catalog();
		$standard = $catalog['standard'];
		$supplier = $catalog['supplier'];

		$meta_keys = get_transient( self::COLUMN_META_CACHE_KEY );

		if ( ! is_array( $meta_keys ) ) {
			$sql       = $wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
				WHERE meta_key LIKE %s OR meta_key LIKE %s
				ORDER BY meta_key ASC LIMIT 500",
				$wpdb->esc_like( '_schrack_' ) . '%',
				$wpdb->esc_like( '_telesystem_' ) . '%'
			);
			$meta_keys = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$meta_keys = is_array( $meta_keys ) ? $meta_keys : array();
			set_transient( self::COLUMN_META_CACHE_KEY, $meta_keys, HOUR_IN_SECONDS );
		}

		$meta_keys = array_merge( $this->known_supplier_meta_keys(), $meta_keys );
		$meta_keys = array_values( array_unique( array_filter( array_map( 'strval', $meta_keys ) ) ) );
		$meta_keys = array_values( array_diff( $meta_keys, $this->transient_meta_keys(), $this->readable_supplier_meta_keys() ) );
		sort( $meta_keys, SORT_NATURAL | SORT_FLAG_CASE );

		$supplier_meta = array();

		foreach ( $meta_keys as $meta_key ) {
			if ( $this->is_valid_meta_key( $meta_key ) ) {
				$supplier_meta[ 'meta:' . $meta_key ] = sprintf( __( 'Meta: %s', 'woocommerce' ), $meta_key );
			}
		}

		return array(
			'standard'      => $standard,
			'supplier'      => $supplier,
			'supplier_meta' => $supplier_meta,
		);
	}

	/**
	 * Returns the safe, database-independent header-builder catalog.
	 *
	 * The admin page must not instantiate WooCommerce's complete CSV exporter
	 * merely to render a form. Its constructor is extension-sensitive and can
	 * fail before the export button is displayed on some WooCommerce versions.
	 * Actual exports still use WooCommerce's official exporter and labels.
	 *
	 * @return array{standard:array<string,string>,supplier:array<string,string>,supplier_meta:array<string,string>}
	 */
	public function default_column_catalog(): array {
		return array(
			'standard' => array(
				'id'                 => __( 'ID', 'woocommerce' ),
				'type'               => __( 'Type', 'woocommerce' ),
				'sku'                => __( 'SKU', 'woocommerce' ),
				'global_unique_id'   => __( 'GTIN, UPC, EAN, or ISBN', 'woocommerce' ),
				'name'               => __( 'Name', 'woocommerce' ),
				'published'          => __( 'Published', 'woocommerce' ),
				'featured'           => __( 'Is featured?', 'woocommerce' ),
				'catalog_visibility' => __( 'Visibility in catalog', 'woocommerce' ),
				'short_description'  => __( 'Short description', 'woocommerce' ),
				'description'        => __( 'Description', 'woocommerce' ),
				'date_on_sale_from'  => __( 'Date sale price starts', 'woocommerce' ),
				'date_on_sale_to'    => __( 'Date sale price ends', 'woocommerce' ),
				'tax_status'         => __( 'Tax status', 'woocommerce' ),
				'tax_class'          => __( 'Tax class', 'woocommerce' ),
				'stock_status'       => __( 'In stock?', 'woocommerce' ),
				'stock'              => __( 'Stock', 'woocommerce' ),
				'low_stock_amount'   => __( 'Low stock amount', 'woocommerce' ),
				'backorders'         => __( 'Backorders allowed?', 'woocommerce' ),
				'sold_individually'  => __( 'Sold individually?', 'woocommerce' ),
				'weight'             => __( 'Weight', 'woocommerce' ),
				'length'             => __( 'Length', 'woocommerce' ),
				'width'              => __( 'Width', 'woocommerce' ),
				'height'             => __( 'Height', 'woocommerce' ),
				'reviews_allowed'    => __( 'Allow customer reviews?', 'woocommerce' ),
				'purchase_note'      => __( 'Purchase note', 'woocommerce' ),
				'sale_price'         => __( 'Sale price', 'woocommerce' ),
				'regular_price'      => __( 'Regular price', 'woocommerce' ),
				'category_ids'       => __( 'Categories', 'woocommerce' ),
				'tag_ids'            => __( 'Tags', 'woocommerce' ),
				'shipping_class_id'  => __( 'Shipping class', 'woocommerce' ),
				'images'             => __( 'Images', 'woocommerce' ),
				'download_limit'     => __( 'Download limit', 'woocommerce' ),
				'download_expiry'    => __( 'Download expiry days', 'woocommerce' ),
				'parent_id'          => __( 'Parent', 'woocommerce' ),
				'grouped_products'   => __( 'Grouped products', 'woocommerce' ),
				'upsell_ids'         => __( 'Upsells', 'woocommerce' ),
				'cross_sell_ids'     => __( 'Cross-sells', 'woocommerce' ),
				'product_url'        => __( 'External URL', 'woocommerce' ),
				'button_text'        => __( 'Button text', 'woocommerce' ),
				'menu_order'         => __( 'Position', 'woocommerce' ),
			),
			'supplier'      => Schrack_WC_Product_CSV_Exporter::schrack_supplier_column_names(),
			'supplier_meta' => array(),
		);
	}

	/**
	 * Creates a WooCommerce product backup and queues its first batch.
	 *
	 * @param array<string,mixed> $filters Optional export filters.
	 * @param array<string,mixed> $column_config Header builder configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function queue( array $filters = array(), array $column_config = array() ): array {
		$current = $this->status();
		$import  = get_option( Schrack_Product_Importer::STATUS_OPTION, null );
		$category_import = ( new Schrack_Category_CSV_Importer( $this->settings, $this->logger ) )->active_import();

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

		if ( null !== $category_import ) {
			return array(
				'state'   => 'error',
				'message' => __( 'A category import is running. Wait for it to finish before exporting products.', 'schrack-woocommerce-sync' ),
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

		$basename  = sprintf( 'schrack-products-export-%1$s-%2$s', gmdate( 'Y-m-d-His' ), str_replace( '-', '', $export_id ) );
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
			$filters       = $this->normalize_filters( $filters );
			$snapshot      = $this->snapshot( $filters );
			$column_config = $this->normalize_column_config( $column_config, $snapshot['total'] > 0 );
			$adapter       = $this->new_adapter( $column_config );
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
				'includes_meta'       => 'full' === $column_config['mode'] || ! empty( $column_config['meta_keys'] ) ? 'yes' : 'no',
				'includes_variations' => in_array( $filters['product_type'], array( 'all', 'variation' ), true ) ? 'yes' : 'no',
				'filters'              => $filters,
				'column_config'        => $column_config,
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
			'Queued WooCommerce product CSV export.',
			null,
			array(
				'export_id' => $export_id,
				'total'     => $snapshot['total'],
				'filters'   => $filters,
				'columns'   => $column_config,
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
				'WooCommerce product CSV export failed.',
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
	 * Excludes short-lived bookkeeping and metadata already represented by
	 * readable supplier columns from a restore file.
	 *
	 * @param array<int,string> $keys Existing skipped meta keys.
	 * @return array<int,string>
	 */
	public function skip_transient_meta_keys( array $keys ): array {
		return array_values( array_unique( array_merge( $keys, $this->transient_meta_keys(), $this->readable_supplier_meta_keys() ) ) );
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

		// Re-evaluate this inside the worker because cPanel web and cron PHP can
		// load different php.ini files. It also upgrades an already-running job
		// from an older, much smaller saved batch size after plugin deployment.
		$batch_size                = max( 1, min( self::BATCH_SIZE, Schrack_Memory_Guard::export_batch_size() ) );
		$status['state']            = 'running';
		$status['batch_size']       = $batch_size;
		$status['last_progress_at'] = time();
		$this->save_status( $status );

		$ids        = $this->product_ids(
			absint( $status['last_id'] ?? 0 ),
			absint( $status['max_id'] ?? 0 ),
			$batch_size,
			isset( $status['filters'] ) && is_array( $status['filters'] ) ? $status['filters'] : array()
		);

		if ( empty( $ids ) ) {
			$this->finish_export( $status );
			return;
		}

		$column_config = isset( $status['column_config'] ) && is_array( $status['column_config'] )
			? $status['column_config']
			: array( 'mode' => 'full' );
		$adapter       = $this->new_adapter( $column_config );
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
		$time_yielded    = false;
		$started_at      = microtime( true );
		$cache_chunk     = Schrack_Memory_Guard::export_cache_chunk_size();
		add_filter( 'woocommerce_product_export_meta_value', array( $this, 'encode_structured_meta' ), 10, 1 );
		add_filter( 'woocommerce_product_export_skip_meta_keys', array( $this, 'skip_transient_meta_keys' ), 20, 1 );

		try {
			foreach ( $ids as $index => $product_id ) {
				if ( $attempted > 0 && Schrack_Memory_Guard::is_pressure_high() ) {
					$memory_yielded = true;
					break;
				}

				if ( $attempted > 0 && microtime( true ) - $started_at >= self::BATCH_TIME_BUDGET ) {
					$time_yielded = true;
					break;
				}

				if ( 0 === $attempted % $cache_chunk ) {
					if ( $attempted > 0 ) {
						Schrack_Memory_Guard::release_runtime_memory();
					}

					$this->prime_product_caches( array_slice( $ids, (int) $index, $cache_chunk ) );
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
		$status['time_yields']      = absint( $status['time_yields'] ?? 0 ) + ( $time_yielded ? 1 : 0 );
		clearstatcache( true, $rows_path );
		$status['work_bytes']       = (int) filesize( $rows_path );
		$status['last_progress_at'] = time();
		$is_complete                = absint( $status['last_id'] ) >= absint( $status['max_id'] ?? 0 ) || ( ! $memory_yielded && ! $time_yielded && $attempted === count( $ids ) && count( $ids ) < $batch_size );

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
	 * Returns short-lived metadata that must never enter a restore file.
	 *
	 * @return array<int,string>
	 */
	private function transient_meta_keys(): array {
		return array(
			'_original_id',
			'_schrack_product_import_job',
			'_schrack_image_sync_claim',
			'_schrack_image_sync_claimed_at',
		);
	}

	/**
	 * Returns meta keys exported through named supplier columns instead.
	 *
	 * @return array<int,string>
	 */
	private function readable_supplier_meta_keys(): array {
		return array_values( Schrack_WC_Product_CSV_Exporter::schrack_supplier_column_meta_map() );
	}

	/**
	 * Supplies useful builder choices even before the first supplier sync.
	 * Database discovery adds every other currently used supplier key.
	 *
	 * @return array<int,string>
	 */
	private function known_supplier_meta_keys(): array {
		return array(
			'_schrack_catalog_source',
			'_schrack_catalog_status',
			'_schrack_item_number',
			'_schrack_ean',
			'_schrack_manufacturer',
			'_schrack_supplier',
			'_schrack_product_line',
			'_schrack_purchase_price',
			'_schrack_purchase_price_raw',
			'_schrack_vat_rate',
			'_schrack_stock_breakdown',
			'_schrack_last_price_sync',
			'_schrack_last_stock_sync',
			'_schrack_package_quantity',
			'_schrack_price_unit',
			'_schrack_unit',
			'_schrack_technical_attributes',
			'_schrack_documents',
			'_schrack_image_url',
			'_schrack_raw_feed_data',
			'_telesystem_catalog_status',
			'_telesystem_item_number',
			'_telesystem_ean',
			'_telesystem_supplier',
			'_telesystem_price_1',
			'_telesystem_price_2',
			'_telesystem_price_source',
			'_telesystem_vat_rate',
			'_telesystem_stock_text',
			'_telesystem_special_offer',
			'_telesystem_weight_grams',
			'_telesystem_warranty_months',
			'_telesystem_image_urls',
			'_telesystem_last_catalog_sync',
			'_telesystem_last_price_sync',
			'_telesystem_last_stock_sync',
		);
	}

	/**
	 * Scans the attributes actually assigned to products without loading product
	 * objects. Serialized rows are read with keyset pagination to stay safe on
	 * large, 2 GB cPanel catalogs.
	 *
	 * @return array<string,array{name:string,label:string,taxonomy:bool}>
	 */
	private function discover_separate_attribute_columns(): array {
		global $wpdb;

		$registered_labels = array();

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$name  = isset( $taxonomy->attribute_name ) ? wc_attribute_taxonomy_name( (string) $taxonomy->attribute_name ) : '';
				$label = isset( $taxonomy->attribute_label ) ? sanitize_text_field( (string) $taxonomy->attribute_label ) : '';

				if ( '' !== $name ) {
					$registered_labels[ $name ] = '' !== $label ? $label : $name;
				}
			}
		}

		$found        = array();
		$last_meta_id = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
					WHERE meta_key = %s AND meta_id > %d
					ORDER BY meta_id ASC LIMIT %d",
					'_product_attributes',
					$last_meta_id,
					self::ATTRIBUTE_SCAN_BATCH_SIZE
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				throw new RuntimeException( __( 'A product attribute names could not be scanned for the separate-column export.', 'schrack-woocommerce-sync' ) );
			}

			foreach ( $rows as $row ) {
				$last_meta_id = max( $last_meta_id, absint( $row['meta_id'] ?? 0 ) );
				$attributes   = maybe_unserialize( $row['meta_value'] ?? '' );

				if ( ! is_array( $attributes ) ) {
					continue;
				}

				foreach ( $attributes as $attribute_key => $attribute ) {
					$attribute = is_array( $attribute ) ? $attribute : array();
					$name      = trim( (string) ( $attribute['name'] ?? $attribute_key ) );
					$taxonomy  = ! empty( $attribute['is_taxonomy'] ) || str_starts_with( $name, 'pa_' );
					$label     = $registered_labels[ $name ] ?? ( $taxonomy ? wc_attribute_label( $name ) : $name );
					$this->add_discovered_attribute( $found, $name, $label, $taxonomy );
				}
			}

			if ( count( $found ) > self::MAX_SEPARATE_ATTRIBUTE_COLUMNS ) {
				throw new RuntimeException( __( 'More than 1,000 product attribute names were found. Narrow the catalog before using separate attribute columns.', 'schrack-woocommerce-sync' ) );
			}

			$has_more = count( $rows ) === self::ATTRIBUTE_SCAN_BATCH_SIZE;
			unset( $rows );
		} while ( $has_more );

		$variation_meta_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
				WHERE meta_key LIKE %s ORDER BY meta_key ASC LIMIT %d",
				$wpdb->esc_like( 'attribute_' ) . '%',
				self::MAX_SEPARATE_ATTRIBUTE_COLUMNS + 1
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $variation_meta_keys ) || '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( __( 'The variation attribute names could not be scanned for the separate-column export.', 'schrack-woocommerce-sync' ) );
		}

		foreach ( $variation_meta_keys as $meta_key ) {
			$name     = substr( (string) $meta_key, strlen( 'attribute_' ) );
			$taxonomy = str_starts_with( $name, 'pa_' );
			$label    = $registered_labels[ $name ] ?? ( $taxonomy ? wc_attribute_label( $name ) : $name );
			$this->add_discovered_attribute( $found, $name, $label, $taxonomy );
		}

		if ( count( $found ) > self::MAX_SEPARATE_ATTRIBUTE_COLUMNS ) {
			throw new RuntimeException( __( 'More than 1,000 product attribute names were found. Narrow the catalog before using separate attribute columns.', 'schrack-woocommerce-sync' ) );
		}

		uasort(
			$found,
			static fn ( array $left, array $right ): int => strnatcasecmp( $left['label'], $right['label'] ) ?: strnatcasecmp( $left['name'], $right['name'] )
		);

		$columns = array();

		foreach ( $found as $definition ) {
			$column_id             = Schrack_WC_Product_CSV_Exporter::schrack_attribute_column_id( $definition['name'], $definition['label'], $definition['taxonomy'] );
			$columns[ $column_id ] = $definition;
		}

		return $columns;
	}

	/**
	 * Adds one valid attribute definition while merging duplicate case/slugs.
	 *
	 * @param array<string,array{name:string,label:string,taxonomy:bool}> $found Definitions by normalized key (by reference).
	 */
	private function add_discovered_attribute( array &$found, string $name, string $label, bool $taxonomy ): void {
		$name  = trim( sanitize_text_field( $name ) );
		$label = trim( sanitize_text_field( wp_strip_all_tags( $label ) ) );

		if ( '' === $name || '' === $label || strlen( $name ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return;
		}

		$key = ( $taxonomy ? 'taxonomy:' : 'custom:' ) . strtolower( sanitize_title( $name ) );

		if ( ! isset( $found[ $key ] ) ) {
			$found[ $key ] = array(
				'name'     => $name,
				'label'    => html_entity_decode( $label, ENT_QUOTES ),
				'taxonomy' => $taxonomy,
			);
		}
	}

	/**
	 * Validates a WordPress metadata key accepted by the custom header builder.
	 */
	private function is_valid_meta_key( string $meta_key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,191}$/D', $meta_key );
	}

	/**
	 * Sanitizes selected header columns and builds their stable ordered labels.
	 *
	 * @param array<string,mixed> $config Raw header configuration.
	 * @return array<string,mixed>
	 */
	private function normalize_column_config( array $config, bool $discover_attributes = true ): array {
		$mode           = sanitize_key( (string) ( $config['mode'] ?? 'full' ) );
		$attribute_mode = sanitize_key( (string) ( $config['attribute_mode'] ?? '' ) );

		if ( ! in_array( $attribute_mode, array( 'grouped', 'separate', 'none' ), true ) ) {
			$attribute_mode = array_key_exists( 'include_attributes', $config ) && empty( $config['include_attributes'] ) ? 'none' : 'grouped';
		}

		$attribute_columns = 'separate' === $attribute_mode && $discover_attributes ? $this->discover_separate_attribute_columns() : array();

		if ( 'custom' !== $mode ) {
			return array(
				'mode'               => 'full',
				'columns'            => array(),
				'column_names'       => array(),
				'meta_keys'          => array(),
				'attribute_mode'     => $attribute_mode,
				'attribute_columns'  => $attribute_columns,
				'include_attributes' => 'none' !== $attribute_mode,
				'include_downloads'  => true,
			);
		}

		$catalog          = $this->column_catalog();
		$standard_columns = array_merge( $catalog['standard'], $catalog['supplier'] );
		$candidates       = isset( $config['columns'] ) && is_array( $config['columns'] ) ? $config['columns'] : array();
		$extra_meta_keys  = isset( $config['extra_meta_keys'] ) && is_array( $config['extra_meta_keys'] ) ? $config['extra_meta_keys'] : array();
		$legacy_meta_map  = array_flip( Schrack_WC_Product_CSV_Exporter::schrack_supplier_column_meta_map() );

		foreach ( $extra_meta_keys as $meta_key ) {
			$meta_key = trim( sanitize_text_field( (string) $meta_key ) );

			if ( 0 === stripos( $meta_key, 'meta:' ) ) {
				$meta_key = trim( substr( $meta_key, 5 ) );
			}

			if ( '' !== $meta_key ) {
				$candidates[] = 'meta:' . $meta_key;
			}
		}

		$column_names = array();
		$meta_keys    = array();

		foreach ( $candidates as $candidate ) {
			$column_id = trim( sanitize_text_field( (string) $candidate ) );

			if ( str_starts_with( $column_id, 'meta:' ) ) {
				$legacy_meta_key = substr( $column_id, 5 );

				if ( isset( $legacy_meta_map[ $legacy_meta_key ] ) ) {
					$column_id = $legacy_meta_map[ $legacy_meta_key ];
				}
			}

			if ( '' === $column_id || isset( $column_names[ $column_id ] ) ) {
				continue;
			}

			if ( isset( $standard_columns[ $column_id ] ) ) {
				$column_names[ $column_id ] = $standard_columns[ $column_id ];
			} elseif ( str_starts_with( $column_id, 'meta:' ) ) {
				$meta_key = substr( $column_id, 5 );

				if ( ! $this->is_valid_meta_key( $meta_key ) || in_array( $meta_key, $this->transient_meta_keys(), true ) ) {
					continue;
				}

				$column_names[ $column_id ] = sprintf( __( 'Meta: %s', 'woocommerce' ), $meta_key );
				$meta_keys[]                = $meta_key;
			}

			if ( count( $column_names ) > self::MAX_CUSTOM_COLUMNS ) {
				throw new RuntimeException( __( 'The custom CSV header cannot contain more than 1,000 fixed columns.', 'schrack-woocommerce-sync' ) );
			}
		}

		if ( empty( $column_names ) ) {
			throw new RuntimeException( __( 'Choose at least one column for the custom CSV header.', 'schrack-woocommerce-sync' ) );
		}

		return array(
			'mode'               => 'custom',
			'columns'            => array_keys( $column_names ),
			'column_names'       => $column_names,
			'meta_keys'          => array_values( array_unique( $meta_keys ) ),
			'attribute_mode'     => $attribute_mode,
			'attribute_columns'  => $attribute_columns,
			'include_attributes' => 'none' !== $attribute_mode,
			'include_downloads'  => ! empty( $config['include_downloads'] ),
		);
	}

	/**
	 * Returns an adapter configured for a complete or ordered custom header.
	 *
	 * @param array<string,mixed> $column_config Normalized header configuration.
	 */
	private function new_adapter( array $column_config = array() ): Schrack_WC_Product_CSV_Exporter {
		$adapter           = new Schrack_WC_Product_CSV_Exporter();
		$attribute_mode    = sanitize_key( (string) ( $column_config['attribute_mode'] ?? ( ! empty( $column_config['include_attributes'] ) ? 'grouped' : 'none' ) ) );
		$attribute_columns = isset( $column_config['attribute_columns'] ) && is_array( $column_config['attribute_columns'] )
			? $column_config['attribute_columns']
			: array();

		if ( 'custom' !== (string) ( $column_config['mode'] ?? 'full' ) ) {
			if ( 'grouped' !== $attribute_mode ) {
				$columns   = array_keys( $adapter->get_default_column_names() );
				$columns[] = 'meta';
				$columns[] = 'downloads';
				$adapter->set_columns_to_export( array_values( array_unique( $columns ) ) );
			}

			$adapter->enable_meta_export( true );
			$adapter->schrack_set_meta_keys( null );
			$adapter->schrack_set_separate_attributes( 'separate' === $attribute_mode ? $attribute_columns : array() );
			return $adapter;
		}

		$column_names = isset( $column_config['column_names'] ) && is_array( $column_config['column_names'] )
			? $column_config['column_names']
			: array();
		$meta_keys    = isset( $column_config['meta_keys'] ) && is_array( $column_config['meta_keys'] )
			? $column_config['meta_keys']
			: array();
		$columns      = array( 'meta' );

		foreach ( array_keys( $column_names ) as $column_id ) {
			if ( ! str_starts_with( (string) $column_id, 'meta:' ) ) {
				$columns[] = (string) $column_id;
			}
		}

		if ( 'grouped' === $attribute_mode ) {
			$columns[] = 'attributes';
		}

		if ( ! empty( $column_config['include_downloads'] ) ) {
			$columns[] = 'downloads';
		}

		$adapter->set_column_names( $column_names );
		$adapter->set_columns_to_export( array_values( array_unique( $columns ) ) );
		$adapter->enable_meta_export( ! empty( $meta_keys ) );
		$adapter->schrack_set_meta_keys( $meta_keys );
		$adapter->schrack_set_separate_attributes( 'separate' === $attribute_mode ? $attribute_columns : array() );

		return $adapter;
	}

	/**
	 * Sanitizes filters and expands a category once for all background batches.
	 *
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,mixed>
	 */
	private function normalize_filters( array $filters ): array {
		$status       = sanitize_key( (string) ( $filters['status'] ?? 'all' ) );
		$product_type = sanitize_key( (string) ( $filters['product_type'] ?? 'all' ) );
		$source       = sanitize_key( (string) ( $filters['source'] ?? 'all' ) );
		$stock_status = sanitize_key( (string) ( $filters['stock_status'] ?? 'all' ) );
		$category_id  = absint( $filters['category_id'] ?? 0 );
		$search       = sanitize_text_field( (string) ( $filters['search'] ?? '' ) );

		if ( ! in_array( $status, array( 'all', 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			$status = 'all';
		}

		if ( ! in_array( $product_type, array( 'all', 'product', 'simple', 'variable', 'grouped', 'external', 'variation' ), true ) ) {
			$product_type = 'all';
		}

		if ( ! in_array( $source, array( 'all', 'schrack', 'telesystem', 'other' ), true ) ) {
			$source = 'all';
		}

		if ( ! in_array( $stock_status, array( 'all', 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$stock_status = 'all';
		}

		$search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );

		$category_ids = isset( $filters['category_ids'] ) && is_array( $filters['category_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $filters['category_ids'] ) ) ) )
			: array();

		if ( $category_id > 0 && empty( $category_ids ) ) {
			$term = get_term( $category_id, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				$category_ids = array( $category_id );
				$children     = get_term_children( $category_id, 'product_cat' );

				if ( ! is_wp_error( $children ) ) {
					$category_ids = array_values( array_unique( array_merge( $category_ids, array_map( 'absint', $children ) ) ) );
				}
			} else {
				$category_id = 0;
			}
		}

		if ( 0 === $category_id ) {
			$category_ids = array();
		}

		return array(
			'status'       => $status,
			'product_type' => $product_type,
			'category_id'  => $category_id,
			'category_ids' => $category_ids,
			'source'       => $source,
			'stock_status' => $stock_status,
			'search'       => $search,
		);
	}

	/**
	 * Builds the shared snapshot/batch WHERE clause without broad SQL joins.
	 * EXISTS predicates prevent duplicate rows when metadata repeats.
	 *
	 * @param array<string,mixed> $raw_filters Export filters.
	 * @return array{where:string,args:array<int,mixed>}
	 */
	private function product_filter_sql( array $raw_filters ): array {
		global $wpdb;

		$filters = $this->normalize_filters( $raw_filters );
		$clauses = array( "p.post_status NOT IN ('trash', 'auto-draft', 'importing')" );
		$args    = array();

		if ( 'variation' === $filters['product_type'] ) {
			$clauses[] = "p.post_type = 'product_variation'";
		} elseif ( 'product' === $filters['product_type'] ) {
			$clauses[] = "p.post_type = 'product'";
		} elseif ( 'all' === $filters['product_type'] ) {
			$clauses[] = "p.post_type IN ('product', 'product_variation')";
		} else {
			$clauses[] = "p.post_type = 'product'";

			if ( 'simple' === $filters['product_type'] ) {
				$clauses[] = "(
					EXISTS (
						SELECT 1 FROM {$wpdb->term_relationships} type_tr
						INNER JOIN {$wpdb->term_taxonomy} type_tt ON type_tt.term_taxonomy_id = type_tr.term_taxonomy_id
						INNER JOIN {$wpdb->terms} type_t ON type_t.term_id = type_tt.term_id
						WHERE type_tr.object_id = p.ID AND type_tt.taxonomy = 'product_type' AND type_t.slug = %s
					)
					OR NOT EXISTS (
						SELECT 1 FROM {$wpdb->term_relationships} type_any_tr
						INNER JOIN {$wpdb->term_taxonomy} type_any_tt ON type_any_tt.term_taxonomy_id = type_any_tr.term_taxonomy_id
						WHERE type_any_tr.object_id = p.ID AND type_any_tt.taxonomy = 'product_type'
					)
				)";
			} else {
				$clauses[] = "EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} type_tr
					INNER JOIN {$wpdb->term_taxonomy} type_tt ON type_tt.term_taxonomy_id = type_tr.term_taxonomy_id
					INNER JOIN {$wpdb->terms} type_t ON type_t.term_id = type_tt.term_id
					WHERE type_tr.object_id = p.ID AND type_tt.taxonomy = 'product_type' AND type_t.slug = %s
				)";
			}

			$args[] = $filters['product_type'];
		}

		if ( 'all' !== $filters['status'] ) {
			$clauses[] = 'p.post_status = %s';
			$args[]    = $filters['status'];
		}

		if ( ! empty( $filters['category_ids'] ) ) {
			$category_placeholders = implode( ', ', array_fill( 0, count( $filters['category_ids'] ), '%d' ) );
			$clauses[]             = "EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} category_tr
				INNER JOIN {$wpdb->term_taxonomy} category_tt ON category_tt.term_taxonomy_id = category_tr.term_taxonomy_id
				WHERE category_tr.object_id IN (p.ID, p.post_parent)
				AND category_tt.taxonomy = 'product_cat'
				AND category_tt.term_id IN ({$category_placeholders})
			)";
			$args                  = array_merge( $args, $filters['category_ids'] );
		}

		if ( in_array( $filters['source'], array( 'schrack', 'telesystem' ), true ) ) {
			$clauses[] = "(
				EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} source_pm
					WHERE source_pm.post_id = p.ID AND source_pm.meta_key = '_schrack_catalog_source' AND source_pm.meta_value = %s
				)
				OR (
					p.post_type = 'product_variation'
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} source_own_pm
						WHERE source_own_pm.post_id = p.ID AND source_own_pm.meta_key = '_schrack_catalog_source'
					)
					AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} source_parent_pm
						WHERE source_parent_pm.post_id = p.post_parent AND source_parent_pm.meta_key = '_schrack_catalog_source' AND source_parent_pm.meta_value = %s
					)
				)
			)";
			$args[]    = $filters['source'];
			$args[]    = $filters['source'];
		} elseif ( 'other' === $filters['source'] ) {
			$clauses[] = "(
				NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} source_own_known_pm
					WHERE source_own_known_pm.post_id = p.ID
					AND source_own_known_pm.meta_key = '_schrack_catalog_source'
					AND source_own_known_pm.meta_value IN ('schrack', 'telesystem')
				)
				AND (
					p.post_type <> 'product_variation'
					OR EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} source_own_any_pm
						WHERE source_own_any_pm.post_id = p.ID
						AND source_own_any_pm.meta_key = '_schrack_catalog_source'
					)
					OR NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} source_parent_known_pm
						WHERE source_parent_known_pm.post_id = p.post_parent
						AND source_parent_known_pm.meta_key = '_schrack_catalog_source'
						AND source_parent_known_pm.meta_value IN ('schrack', 'telesystem')
					)
				)
			)";
		}

		if ( 'all' !== $filters['stock_status'] ) {
			$clauses[] = "(
				EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} stock_pm
					WHERE stock_pm.post_id = p.ID AND stock_pm.meta_key = '_stock_status' AND stock_pm.meta_value = %s
				)
				OR (
					p.post_type = 'product_variation'
					AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} stock_own_pm
						WHERE stock_own_pm.post_id = p.ID AND stock_own_pm.meta_key = '_stock_status'
					)
					AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} stock_parent_pm
						WHERE stock_parent_pm.post_id = p.post_parent AND stock_parent_pm.meta_key = '_stock_status' AND stock_parent_pm.meta_value = %s
					)
				)
			)";
			$args[]    = $filters['stock_status'];
			$args[]    = $filters['stock_status'];
		}

		if ( '' !== $filters['search'] ) {
			$like              = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$search_conditions = array(
				'p.post_title LIKE %s',
				"EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} sku_pm
					WHERE sku_pm.post_id = p.ID AND sku_pm.meta_key = '_sku' AND sku_pm.meta_value LIKE %s
				)",
				"(
					p.post_type = 'product_variation'
					AND EXISTS (
						SELECT 1 FROM {$wpdb->posts} parent_p
						WHERE parent_p.ID = p.post_parent
						AND (
							parent_p.post_title LIKE %s
							OR EXISTS (
								SELECT 1 FROM {$wpdb->postmeta} parent_sku_pm
								WHERE parent_sku_pm.post_id = parent_p.ID AND parent_sku_pm.meta_key = '_sku' AND parent_sku_pm.meta_value LIKE %s
							)
						)
					)
				)",
			);
			$args[]            = $like;
			$args[]            = $like;
			$args[]            = $like;
			$args[]            = $like;

			if ( ctype_digit( $filters['search'] ) ) {
				$search_conditions[] = 'p.ID = %d';
				$search_conditions[] = 'p.post_parent = %d';
				$args[]              = absint( $filters['search'] );
				$args[]              = absint( $filters['search'] );
			}

			$clauses[] = '(' . implode( ' OR ', $search_conditions ) . ')';
		}

		return array(
			'where' => implode( ' AND ', $clauses ),
			'args'  => $args,
		);
	}

	/**
	 * Returns a stable count and highest eligible product ID.
	 *
	 * @param array<string,mixed> $filters Export filters.
	 * @return array{total:int,max_id:int}
	 */
	private function snapshot( array $filters ): array {
		global $wpdb;

		$filter_sql = $this->product_filter_sql( $filters );
		$sql        = "SELECT COUNT(*) AS total, COALESCE(MAX(p.ID), 0) AS max_id FROM {$wpdb->posts} p WHERE {$filter_sql['where']}";

		if ( ! empty( $filter_sql['args'] ) ) {
			$sql = $wpdb->prepare( $sql, $filter_sql['args'] );
		}

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
	private function product_ids( int $last_id, int $max_id, int $limit, array $filters ): array {
		global $wpdb;

		$filter_sql = $this->product_filter_sql( $filters );
		$args       = array_merge(
			$filter_sql['args'],
			array( max( 0, $last_id ), max( 0, $max_id ), max( 1, $limit ) )
		);
		$sql        = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			WHERE {$filter_sql['where']}
			AND p.ID > %d AND p.ID <= %d
			ORDER BY p.ID ASC LIMIT %d",
			$args
		);
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( __( 'The next product export batch could not be read from the database.', 'schrack-woocommerce-sync' ) );
		}

		return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * Primes posts, metadata and terms in a small bounded group to avoid the
	 * per-product query pattern without retaining the entire export batch.
	 *
	 * @param array<int,int> $product_ids Product IDs to prime.
	 */
	private function prime_product_caches( array $product_ids ): void {
		$product_ids = array_values( array_filter( array_map( 'absint', $product_ids ) ) );

		if ( empty( $product_ids ) ) {
			return;
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $product_ids, true, true );
			return;
		}

		update_meta_cache( 'post', $product_ids );
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
			$queued = absint( as_enqueue_async_action( self::HOOK, array( $export_id ), self::GROUP ) ) > 0;

			if ( $queued && class_exists( 'Schrack_Cron' ) ) {
				Schrack_Cron::dispatch_queue_runner_ping();
			}

			return $queued;
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
