<?php
/**
 * Shared product transfer display state; no catalog scan or page rendering.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$product_export = isset( $product_export ) && is_array( $product_export ) ? $product_export : array();
$product_import = isset( $product_import ) && is_array( $product_import ) ? $product_import : array();

$export_state     = sanitize_key( (string) ( $product_export['state'] ?? 'idle' ) );
$export_active    = in_array( $export_state, array( 'queued', 'running', 'finalizing' ), true );
$export_total     = absint( $product_export['total'] ?? 0 );
$export_processed = min( $export_total, absint( $product_export['processed'] ?? 0 ) );
$export_displayed = 'done' === $export_state ? $export_total : $export_processed;
$export_percent   = 'done' === $export_state ? 100 : ( $export_total > 0 ? min( 99, (int) floor( ( $export_processed / $export_total ) * 100 ) ) : 0 );
$export_stale     = $export_active && absint( $product_export['last_progress_at'] ?? 0 ) > 0 && absint( $product_export['last_progress_at'] ) < time() - 10 * MINUTE_IN_SECONDS;
$export_id        = sanitize_key( (string) ( $product_export['export_id'] ?? '' ) );
$export_batch     = absint( $product_export['batch_size'] ?? 0 );
$export_memory_mb = isset( $product_export['memory_limit_mb'] ) ? (float) $product_export['memory_limit_mb'] : 0.0;
$finalize_position = absint( $product_export['finalize_position'] ?? 0 );
$finalize_total    = absint( $product_export['finalize_total_bytes'] ?? 0 );
$finalize_percent  = $finalize_total > 0 ? min( 100, (int) floor( ( $finalize_position / $finalize_total ) * 100 ) ) : 0;

$import_state   = sanitize_key( (string) ( $product_import['state'] ?? 'idle' ) );
$import_active  = in_array( $import_state, array( 'queued', 'running', 'finalizing' ), true );
$import_percent = min( 100, absint( $product_import['percentage'] ?? ( 'done' === $import_state ? 100 : 0 ) ) );
$import_stale   = $import_active && absint( $product_import['last_progress_at'] ?? 0 ) > 0 && absint( $product_import['last_progress_at'] ) < time() - 30 * MINUTE_IN_SECONDS;
$warnings       = isset( $product_import['warnings'] ) && is_array( $product_import['warnings'] ) ? $product_import['warnings'] : array();
$import_batch   = absint( $product_import['batch_size'] ?? 0 );
$import_memory_mb = isset( $product_import['memory_limit_mb'] ) ? (float) $product_import['memory_limit_mb'] : 0.0;

$category_import   = isset( $category_import ) && is_array( $category_import ) ? $category_import : array();
$category_state    = sanitize_key( (string) ( $category_import['state'] ?? 'idle' ) );
$category_active   = in_array( $category_state, array( 'queued', 'running' ), true );
$category_updated  = absint( $category_import['updated_at'] ?? $category_import['started_at'] ?? 0 );
$category_stale    = $category_active && $category_updated > 0 && $category_updated < time() - 30 * MINUTE_IN_SECONDS;
$category_live     = $category_active && ! $category_stale;
$category_total    = absint( $category_import['total_rows'] ?? 0 );
$category_processed = min( $category_total, absint( $category_import['processed'] ?? 0 ) );
$category_percent  = 'done' === $category_state ? 100 : ( $category_total > 0 ? min( 99, (int) floor( ( $category_processed / $category_total ) * 100 ) ) : 0 );
$category_warnings = isset( $category_import['warnings'] ) && is_array( $category_import['warnings'] ) ? $category_import['warnings'] : array();
$product_transfer_active = $export_active || $import_active;
$transfer_active = $product_transfer_active || $category_live;

$export_filter_defaults = array(
	'scope'        => 'all_products',
	'status'       => 'all',
	'product_type' => 'all',
	'category_id'  => 0,
	'source'       => 'all',
	'stock_status' => 'all',
	'search'       => '',
);
$export_saved_filters = isset( $product_export['filters'] ) && is_array( $product_export['filters'] )
	? $product_export['filters']
	: array();
$export_filters = ! empty( $export_saved_filters )
	? array_merge( $export_filter_defaults, $export_saved_filters )
	: $export_filter_defaults;
$export_filters['status']       = sanitize_key( (string) $export_filters['status'] );
$export_filters['product_type'] = sanitize_key( (string) $export_filters['product_type'] );
$export_filters['category_id']  = absint( $export_filters['category_id'] );
$export_filters['source']       = sanitize_key( (string) $export_filters['source'] );
$export_filters['stock_status'] = sanitize_key( (string) $export_filters['stock_status'] );
$export_filters['search']       = sanitize_text_field( (string) $export_filters['search'] );
$legacy_filter_active =
	'all' !== $export_filters['status'] ||
	'all' !== $export_filters['product_type'] ||
	$export_filters['category_id'] > 0 ||
	'all' !== $export_filters['source'] ||
	'all' !== $export_filters['stock_status'] ||
	'' !== $export_filters['search'];
$export_filters['scope'] = isset( $export_saved_filters['scope'] )
	? sanitize_key( (string) $export_saved_filters['scope'] )
	: ( $legacy_filter_active ? 'filtered' : 'all_products' );

if ( ! in_array( $export_filters['scope'], array( 'all_products', 'filtered' ), true ) ) {
	$export_filters['scope'] = 'all_products';
}

$export_scope_options = array(
	'all_products' => __( 'Összes WooCommerce-termék (importált és kézzel hozzáadott)', 'schrack-woocommerce-sync' ),
	'filtered'     => __( 'Szűrt termékek', 'schrack-woocommerce-sync' ),
);

$export_status_options = array(
	'all'     => __( 'Minden állapot', 'schrack-woocommerce-sync' ),
	'publish' => __( 'Közzétett', 'schrack-woocommerce-sync' ),
	'draft'   => __( 'Vázlat', 'schrack-woocommerce-sync' ),
	'pending' => __( 'Függőben', 'schrack-woocommerce-sync' ),
	'private' => __( 'Privát', 'schrack-woocommerce-sync' ),
	'future'  => __( 'Időzített', 'schrack-woocommerce-sync' ),
);
$export_type_options = array(
	'all'       => __( 'Minden típus és variáció', 'schrack-woocommerce-sync' ),
	'product'   => __( 'Minden szülőtermék, variációk nélkül', 'schrack-woocommerce-sync' ),
	'simple'    => __( 'Egyszerű termék', 'schrack-woocommerce-sync' ),
	'variable'  => __( 'Variálható termék', 'schrack-woocommerce-sync' ),
	'variation' => __( 'Csak variációk', 'schrack-woocommerce-sync' ),
	'grouped'   => __( 'Csoportosított termék', 'schrack-woocommerce-sync' ),
	'external'  => __( 'Külső/partner termék', 'schrack-woocommerce-sync' ),
);
$export_source_options = array(
	'all'        => __( 'Minden forrás', 'schrack-woocommerce-sync' ),
	'schrack'    => __( 'Schrack', 'schrack-woocommerce-sync' ),
	'telesystem' => __( 'Telesystem', 'schrack-woocommerce-sync' ),
	'edoc' => 'eDoc ERP',
	'other'      => __( 'Egyéb vagy nincs forrás', 'schrack-woocommerce-sync' ),
);
$export_stock_options = array(
	'all'         => __( 'Minden készletállapot', 'schrack-woocommerce-sync' ),
	'instock'     => __( 'Készleten', 'schrack-woocommerce-sync' ),
	'outofstock'  => __( 'Nincs készleten', 'schrack-woocommerce-sync' ),
	'onbackorder' => __( 'Utánrendelhető', 'schrack-woocommerce-sync' ),
);

$export_filter_summary = array();

if ( 'all' !== $export_filters['status'] ) {
	$export_filter_summary[] = $export_status_options[ $export_filters['status'] ] ?? $export_filters['status'];
}

if ( 'all' !== $export_filters['product_type'] ) {
	$export_filter_summary[] = $export_type_options[ $export_filters['product_type'] ] ?? $export_filters['product_type'];
}

if ( $export_filters['category_id'] > 0 ) {
	$export_category = get_term( $export_filters['category_id'], 'product_cat' );

	if ( $export_category instanceof WP_Term ) {
		$export_filter_summary[] = sprintf( __( 'Kategória: %s (alkategóriákkal)', 'schrack-woocommerce-sync' ), $export_category->name );
	}
}

if ( 'all' !== $export_filters['source'] ) {
	$export_filter_summary[] = $export_source_options[ $export_filters['source'] ] ?? $export_filters['source'];
}

if ( 'all' !== $export_filters['stock_status'] ) {
	$export_filter_summary[] = $export_stock_options[ $export_filters['stock_status'] ] ?? $export_filters['stock_status'];
}

if ( '' !== $export_filters['search'] ) {
	$export_filter_summary[] = sprintf( __( 'Keresés: %s', 'schrack-woocommerce-sync' ), $export_filters['search'] );
}

$export_column_catalog  = isset( $export_column_catalog ) && is_array( $export_column_catalog ) ? $export_column_catalog : array();
$export_standard_columns = isset( $export_column_catalog['standard'] ) && is_array( $export_column_catalog['standard'] ) ? $export_column_catalog['standard'] : array();
$export_readable_supplier_columns = isset( $export_column_catalog['supplier'] ) && is_array( $export_column_catalog['supplier'] ) ? $export_column_catalog['supplier'] : array();
$export_supplier_columns = isset( $export_column_catalog['supplier_meta'] ) && is_array( $export_column_catalog['supplier_meta'] ) ? $export_column_catalog['supplier_meta'] : array();
$export_column_config    = isset( $product_export['column_config'] ) && is_array( $product_export['column_config'] ) ? $product_export['column_config'] : array();
$export_column_mode      = 'custom' === sanitize_key( (string) ( $export_column_config['mode'] ?? 'full' ) ) ? 'custom' : 'full';
$export_all_columns      = array_merge( $export_standard_columns, $export_readable_supplier_columns, $export_supplier_columns );

$export_minimal_columns = array_values(
	array_intersect(
		array( 'id', 'type', 'sku', 'name', 'published', 'regular_price', 'sale_price', 'stock_status', 'stock', 'category_ids', 'images', 'parent_id' ),
		array_keys( $export_standard_columns )
	)
);
$export_recommended_columns = array_merge(
	$export_minimal_columns,
	array_keys( $export_readable_supplier_columns ),
	array_values(
		array_intersect(
			array(
				'meta:_schrack_item_number',
				'meta:_schrack_ean',
				'meta:_schrack_stock_breakdown',
				'meta:_schrack_unit',
				'meta:_telesystem_item_number',
				'meta:_telesystem_ean',
				'meta:_telesystem_stock_text',
			),
			array_keys( $export_supplier_columns )
		)
	)
);
$export_selected_columns = 'custom' === $export_column_mode && isset( $export_column_config['columns'] ) && is_array( $export_column_config['columns'] )
	? array_values( array_unique( array_map( 'strval', $export_column_config['columns'] ) ) )
	: $export_recommended_columns;
$export_legacy_supplier_columns = array(
	'meta:_schrack_catalog_source'     => 'supplier_source',
	'meta:_schrack_purchase_price'     => 'supplier_purchase_price',
	'meta:_schrack_purchase_price_raw' => 'supplier_purchase_price_raw',
	'meta:_telesystem_price_1'         => 'telesystem_price_1',
	'meta:_telesystem_price_2'         => 'telesystem_price_2',
);
$export_selected_columns = array_values(
	array_unique(
		array_map(
			static fn ( string $column_id ): string => $export_legacy_supplier_columns[ $column_id ] ?? $column_id,
			$export_selected_columns
		)
	)
);

foreach ( $export_selected_columns as $column_id ) {
	if ( ! isset( $export_all_columns[ $column_id ] ) && str_starts_with( $column_id, 'meta:' ) ) {
		$export_all_columns[ $column_id ] = sprintf( __( 'Meta: %s', 'woocommerce' ), substr( $column_id, 5 ) );
		$export_supplier_columns[ $column_id ] = $export_all_columns[ $column_id ];
	}
}

$export_include_attributes = 'custom' === $export_column_mode ? ! empty( $export_column_config['include_attributes'] ) : true;
$export_include_downloads  = 'custom' === $export_column_mode ? ! empty( $export_column_config['include_downloads'] ) : true;
$export_attribute_mode     = sanitize_key( (string) ( $export_column_config['attribute_mode'] ?? ( $export_include_attributes ? 'grouped' : 'none' ) ) );

if ( ! in_array( $export_attribute_mode, array( 'grouped', 'separate', 'none' ), true ) ) {
	$export_attribute_mode = 'grouped';
}

$export_attribute_column_count = isset( $export_column_config['attribute_columns'] ) && is_array( $export_column_config['attribute_columns'] )
	? count( $export_column_config['attribute_columns'] )
	: 0;
$export_supplier_preset    = array_values( array_unique( array_merge( $export_minimal_columns, array_keys( $export_readable_supplier_columns ), array_keys( $export_supplier_columns ) ) ) );
$export_header_summary     = 'custom' === (string) ( $export_column_config['mode'] ?? 'full' )
	? sprintf( __( 'Egyedi fejléc — %s rögzített oszlop', 'schrack-woocommerce-sync' ), number_format_i18n( count( $export_column_config['columns'] ?? array() ) ) )
	: __( 'Teljes WooCommerce fejléc, olvasható furnizorárak és minden további Meta mező', 'schrack-woocommerce-sync' );

if ( 'separate' === $export_attribute_mode ) {
	$export_header_summary .= ' · ' . sprintf(
		/* translators: %s: number of discovered separate attribute columns. */
		__( '%s külön attribútumoszlop', 'schrack-woocommerce-sync' ),
		number_format_i18n( $export_attribute_column_count )
	);
}

$state_labels = array(
	'idle'    => __( 'Nincs folyamat', 'schrack-woocommerce-sync' ),
	'queued'  => __( 'Sorba állítva', 'schrack-woocommerce-sync' ),
	'running' => __( 'Folyamatban', 'schrack-woocommerce-sync' ),
	'finalizing' => __( 'Befejezés', 'schrack-woocommerce-sync' ),
	'done'    => __( 'Elkészült', 'schrack-woocommerce-sync' ),
	'error'   => __( 'Hiba', 'schrack-woocommerce-sync' ),
);
$state_classes = array(
	'idle'    => 'is-warning',
	'queued'  => 'is-warning',
	'running' => 'is-warning',
	'finalizing' => 'is-warning',
	'done'    => 'is-ok',
	'error'   => 'is-error',
);
$should_refresh = ( $export_active && ! $export_stale ) || ( $import_active && ! $import_stale ) || $category_live;

