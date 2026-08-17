<?php
/**
 * Complete WooCommerce product export/import screen.
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
	'status'       => 'all',
	'product_type' => 'all',
	'category_id'  => 0,
	'source'       => 'all',
	'stock_status' => 'all',
	'search'       => '',
);
$export_filters = isset( $product_export['filters'] ) && is_array( $product_export['filters'] )
	? array_merge( $export_filter_defaults, $product_export['filters'] )
	: $export_filter_defaults;
$export_filters['status']       = sanitize_key( (string) $export_filters['status'] );
$export_filters['product_type'] = sanitize_key( (string) $export_filters['product_type'] );
$export_filters['category_id']  = absint( $export_filters['category_id'] );
$export_filters['source']       = sanitize_key( (string) $export_filters['source'] );
$export_filters['stock_status'] = sanitize_key( (string) $export_filters['stock_status'] );
$export_filters['search']       = sanitize_text_field( (string) $export_filters['search'] );

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
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'WooCommerce termék és kategória export / import', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'export' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<div class="schrack-panel">
		<h2><?php esc_html_e( '1. WooCommerce termékmentés exportálása', 'schrack-woocommerce-sync' ); ?></h2>
		<p>
			<?php esc_html_e( 'A fájl a WooCommerce hivatalos termék-CSV sémáját használja. Szűrés nélkül tartalmazza az összes nem törölt terméket és variációt; minden kiválasztott rekordnál exportálja az attribútumokat, kategóriákat, címkéket, képeket, letöltéseket, kapcsolatokat és egyedi metaadatokat.', 'schrack-woocommerce-sync' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'A furnizor és a beszállítói árak külön, olvasható oszlopokba kerülnek. A további Schrack/Telesystem műszaki adatok, dokumentumok és a teljes nyers feed visszaállítható Meta oszlopként maradnak.', 'schrack-woocommerce-sync' ); ?>
		</p>
		<p class="description"><?php esc_html_e( 'Ez termékkatalógus-mentés: rendeléseket, vásárlókat és termékértékeléseket nem exportál.', 'schrack-woocommerce-sync' ); ?></p>
		<p class="description"><?php esc_html_e( 'A feldolgozás automatikusan memóriakímélő, időkorlátos batch-méretet választ, kis csoportokban előkészíti és üríti a futásidejű cache-t, és 70% PHP memóriahasználatnál biztonságosan átadja a folytatást a következő háttérfolyamatnak.', 'schrack-woocommerce-sync' ); ?></p>
		<p class="description"><strong><?php esc_html_e( 'Lemezhely: a véglegesítés alatt a munkafájl és a kész CSV egyszerre létezik, ezért legyen legalább a várható CSV méretének kétszerese szabadon.', 'schrack-woocommerce-sync' ); ?></strong></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_product_export_start">
			<?php wp_nonce_field( 'schrack_wc_sync_product_export_start' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="export-status"><?php esc_html_e( 'Termékállapot', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-status" name="export_status" <?php disabled( $transfer_active ); ?>>
							<?php foreach ( $export_status_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-product-type"><?php esc_html_e( 'Terméktípus', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-product-type" name="export_product_type" <?php disabled( $transfer_active ); ?>>
							<?php foreach ( $export_type_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['product_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-category-id"><?php esc_html_e( 'Termékkategória', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_categories(
							array(
								'taxonomy'          => 'product_cat',
								'name'              => 'export_category_id',
								'id'                => 'export-category-id',
								'selected'          => $export_filters['category_id'],
								'show_option_all'   => __( 'Minden kategória', 'schrack-woocommerce-sync' ),
								'option_none_value' => '0',
								'hierarchical'      => true,
								'hide_empty'        => false,
								'value_field'       => 'term_id',
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'A kiválasztott kategória az összes alkategóriáját is tartalmazza. A hozzá tartozó variációk is bekerülnek.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-source"><?php esc_html_e( 'Furnizor / forrás', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-source" name="export_source" <?php disabled( $transfer_active ); ?>>
							<?php foreach ( $export_source_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-stock-status"><?php esc_html_e( 'Készletállapot', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-stock-status" name="export_stock_status" <?php disabled( $transfer_active ); ?>>
							<?php foreach ( $export_stock_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['stock_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-search"><?php esc_html_e( 'Név, SKU vagy ID', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<input id="export-search" type="search" class="regular-text" name="export_search" value="<?php echo esc_attr( $export_filters['search'] ); ?>" maxlength="100" <?php disabled( $transfer_active ); ?>>
						<p class="description"><?php esc_html_e( 'Részleges egyezést keres a termék és a variáció saját, illetve szülő nevében és SKU-jában.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'CSV fejléc és oszlopok', 'schrack-woocommerce-sync' ); ?></th>
					<td>
						<fieldset
							class="schrack-export-columns"
							data-export-columns
							data-label-up="<?php esc_attr_e( 'Feljebb', 'schrack-woocommerce-sync' ); ?>"
							data-label-down="<?php esc_attr_e( 'Lejjebb', 'schrack-woocommerce-sync' ); ?>"
							data-label-remove="<?php esc_attr_e( 'Eltávolítás', 'schrack-woocommerce-sync' ); ?>"
							data-empty-message="<?php esc_attr_e( 'Az egyedi fejléchez válassz legalább egy oszlopot.', 'schrack-woocommerce-sync' ); ?>"
						>
							<label class="schrack-export-columns__mode">
								<input type="radio" name="export_column_mode" value="full" <?php checked( $export_column_mode, 'full' ); ?> <?php disabled( $transfer_active ); ?>>
								<strong><?php esc_html_e( 'Teljes mentési fejléc', 'schrack-woocommerce-sync' ); ?></strong>
								<span><?php esc_html_e( 'Minden WooCommerce oszlop, olvasható furnizorár, attribútum, letöltés és minden további visszaállítható Meta mező.', 'schrack-woocommerce-sync' ); ?></span>
							</label>
							<label class="schrack-export-columns__mode">
								<input type="radio" name="export_column_mode" value="custom" <?php checked( $export_column_mode, 'custom' ); ?> <?php disabled( $transfer_active ); ?>>
								<strong><?php esc_html_e( 'Egyedi fejléc összeállítása', 'schrack-woocommerce-sync' ); ?></strong>
								<span><?php esc_html_e( 'Csak a kiválasztott mezők kerülnek a CSV-be, az itt megadott sorrendben.', 'schrack-woocommerce-sync' ); ?></span>
							</label>

							<div class="schrack-export-columns__extras">
								<label for="export-attribute-mode"><strong><?php esc_html_e( 'Attribútumok elrendezése', 'schrack-woocommerce-sync' ); ?></strong></label>
								<select id="export-attribute-mode" name="export_attribute_mode" <?php disabled( $transfer_active ); ?>>
									<option value="grouped" <?php selected( $export_attribute_mode, 'grouped' ); ?>><?php esc_html_e( 'WooCommerce csoportok: Attribútum 1 név / érték', 'schrack-woocommerce-sync' ); ?></option>
									<option value="separate" <?php selected( $export_attribute_mode, 'separate' ); ?>><?php esc_html_e( 'Minden attribútum külön oszlopban (pl. VPE)', 'schrack-woocommerce-sync' ); ?></option>
									<option value="none" <?php selected( $export_attribute_mode, 'none' ); ?>><?php esc_html_e( 'Attribútumok kihagyása', 'schrack-woocommerce-sync' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'A külön oszlopos mód az export indításakor végigolvassa a tényleges attribútumneveket. Minden termék ugyanazokat az oszlopokat kapja; a hiányzó értékek helyén üres cella marad.', 'schrack-woocommerce-sync' ); ?></p>
							</div>

							<div class="schrack-export-columns__builder" data-export-column-builder<?php if ( 'custom' !== $export_column_mode ) : ?> hidden<?php endif; ?>>
								<p class="description"><?php esc_html_e( 'A fejlécnevek a hivatalos WooCommerce formátumban maradnak, így az elkészült CSV automatikusan visszaimportálható.', 'schrack-woocommerce-sync' ); ?></p>
								<p class="description"><strong><?php esc_html_e( 'Biztonságos frissítő visszaimporthoz az ID vagy SKU oszlopot hagyd a fejlécben; variációkhoz a Típus és Szülő oszlop is ajánlott.', 'schrack-woocommerce-sync' ); ?></strong></p>
								<div class="schrack-export-columns__grid">
									<section class="schrack-export-columns__available">
										<h4><?php esc_html_e( 'Elérhető mezők', 'schrack-woocommerce-sync' ); ?></h4>
										<label class="screen-reader-text" for="export-column-search"><?php esc_html_e( 'Oszlop keresése', 'schrack-woocommerce-sync' ); ?></label>
										<input id="export-column-search" type="search" class="regular-text" placeholder="<?php esc_attr_e( 'Oszlop vagy Meta kulcs keresése…', 'schrack-woocommerce-sync' ); ?>" data-export-column-search>
										<select multiple size="16" data-export-column-available aria-label="<?php esc_attr_e( 'Elérhető export oszlopok', 'schrack-woocommerce-sync' ); ?>">
											<optgroup label="<?php esc_attr_e( 'WooCommerce mezők', 'schrack-woocommerce-sync' ); ?>">
												<?php foreach ( $export_standard_columns as $column_id => $column_label ) : ?>
													<option value="<?php echo esc_attr( $column_id ); ?>" data-label="<?php echo esc_attr( $column_label ); ?>" <?php disabled( in_array( $column_id, $export_selected_columns, true ) ); ?>><?php echo esc_html( $column_label . ' — ' . $column_id ); ?></option>
												<?php endforeach; ?>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'Olvasható furnizor mezők', 'schrack-woocommerce-sync' ); ?>">
												<?php foreach ( $export_readable_supplier_columns as $column_id => $column_label ) : ?>
													<option value="<?php echo esc_attr( $column_id ); ?>" data-label="<?php echo esc_attr( $column_label ); ?>" <?php disabled( in_array( $column_id, $export_selected_columns, true ) ); ?>><?php echo esc_html( $column_label ); ?></option>
												<?php endforeach; ?>
											</optgroup>
											<optgroup label="<?php esc_attr_e( 'Schrack / Telesystem Meta mezők', 'schrack-woocommerce-sync' ); ?>">
												<?php foreach ( $export_supplier_columns as $column_id => $column_label ) : ?>
													<option value="<?php echo esc_attr( $column_id ); ?>" data-label="<?php echo esc_attr( $column_label ); ?>" <?php disabled( in_array( $column_id, $export_selected_columns, true ) ); ?>><?php echo esc_html( $column_label ); ?></option>
												<?php endforeach; ?>
											</optgroup>
										</select>
										<p><button type="button" class="button" data-export-column-add <?php disabled( $transfer_active ); ?>><?php esc_html_e( 'Kijelölt mezők hozzáadása →', 'schrack-woocommerce-sync' ); ?></button></p>
									</section>

									<section class="schrack-export-columns__selected">
										<div class="schrack-export-columns__selected-header">
											<h4><?php esc_html_e( 'Kiválasztott fejléc sorrendje', 'schrack-woocommerce-sync' ); ?> (<span data-export-column-count><?php echo esc_html( number_format_i18n( count( $export_selected_columns ) ) ); ?></span>)</h4>
											<div class="schrack-export-columns__presets">
												<button type="button" class="button button-small" data-export-column-preset="<?php echo esc_attr( wp_json_encode( $export_minimal_columns ) ); ?>"><?php esc_html_e( 'Alap', 'schrack-woocommerce-sync' ); ?></button>
												<button type="button" class="button button-small" data-export-column-preset="<?php echo esc_attr( wp_json_encode( $export_recommended_columns ) ); ?>"><?php esc_html_e( 'Ajánlott furnizor', 'schrack-woocommerce-sync' ); ?></button>
												<button type="button" class="button button-small" data-export-column-preset="<?php echo esc_attr( wp_json_encode( array_keys( $export_standard_columns ) ) ); ?>"><?php esc_html_e( 'Minden Woo mező', 'schrack-woocommerce-sync' ); ?></button>
												<button type="button" class="button button-small" data-export-column-preset="<?php echo esc_attr( wp_json_encode( $export_supplier_preset ) ); ?>"><?php esc_html_e( 'Minden furnizor mező', 'schrack-woocommerce-sync' ); ?></button>
											</div>
										</div>
										<ol class="schrack-export-columns__list" data-export-column-selected>
											<?php foreach ( $export_selected_columns as $column_id ) : ?>
												<?php $column_label = $export_all_columns[ $column_id ] ?? $column_id; ?>
												<li data-export-column-item data-column-id="<?php echo esc_attr( $column_id ); ?>">
													<span class="schrack-export-columns__item-label"><strong><?php echo esc_html( $column_label ); ?></strong><code><?php echo esc_html( $column_id ); ?></code></span>
													<span class="schrack-export-columns__item-actions">
														<button type="button" class="button button-small" data-export-column-action="up" aria-label="<?php esc_attr_e( 'Feljebb', 'schrack-woocommerce-sync' ); ?>">↑</button>
														<button type="button" class="button button-small" data-export-column-action="down" aria-label="<?php esc_attr_e( 'Lejjebb', 'schrack-woocommerce-sync' ); ?>">↓</button>
														<button type="button" class="button button-small" data-export-column-action="remove" aria-label="<?php esc_attr_e( 'Eltávolítás', 'schrack-woocommerce-sync' ); ?>">×</button>
													</span>
													<input type="hidden" name="export_columns[]" value="<?php echo esc_attr( $column_id ); ?>">
												</li>
											<?php endforeach; ?>
										</ol>
									</section>
								</div>

								<div class="schrack-export-columns__extras">
									<label><input type="checkbox" name="export_include_downloads" value="1" <?php checked( $export_include_downloads ); ?> <?php disabled( $transfer_active ); ?>> <?php esc_html_e( 'Dinamikus letöltési oszlopok hozzáadása a fejléc végéhez', 'schrack-woocommerce-sync' ); ?></label>
									<label for="export-extra-meta-keys"><strong><?php esc_html_e( 'További Meta kulcsok', 'schrack-woocommerce-sync' ); ?></strong></label>
									<textarea id="export-extra-meta-keys" name="export_extra_meta_keys" rows="3" class="large-text code" placeholder="_sajat_meta_kulcs&#10;_masik_meta_kulcs" <?php disabled( $transfer_active ); ?>></textarea>
									<p class="description"><?php esc_html_e( 'Soronként egy kulcs. Az itt megadott Meta oszlopok a kiválasztott fejléc végére kerülnek.', 'schrack-woocommerce-sync' ); ?></p>
								</div>
							</div>
						</fieldset>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary" <?php disabled( $transfer_active ); ?>><?php esc_html_e( 'Háttér-export indítása', 'schrack-woocommerce-sync' ); ?></button>
				<?php if ( $export_active ) : ?>
					<span class="description"><?php esc_html_e( 'Egy export már fut.', 'schrack-woocommerce-sync' ); ?></span>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<div class="schrack-panel">
		<div class="schrack-panel-header">
			<h2><?php esc_html_e( 'Export állapota', 'schrack-woocommerce-sync' ); ?></h2>
			<?php if ( $export_active && ! $export_stale ) : ?>
				<span class="schrack-auto-refresh"><?php esc_html_e( 'Automatikus frissítés 5 másodpercenként', 'schrack-woocommerce-sync' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $export_stale ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Az export több mint 10 perce nem haladt. Ellenőrizd az Action Scheduler és PHP naplót, majd szükség esetén állítsd le.', 'schrack-woocommerce-sync' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Állapot', 'schrack-woocommerce-sync' ); ?></th>
					<td><span class="schrack-status-pill <?php echo esc_attr( $export_stale ? 'is-error' : ( $state_classes[ $export_state ] ?? 'is-warning' ) ); ?>"><?php echo esc_html( $state_labels[ $export_state ] ?? ucfirst( $export_state ) ); ?></span></td>
				</tr>
				<?php if ( 'idle' !== $export_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Alkalmazott szűrők', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( empty( $export_filter_summary ) ? __( 'Nincs — minden termék és variáció', 'schrack-woocommerce-sync' ) : implode( ' · ', $export_filter_summary ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CSV fejléc', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( $export_header_summary ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Előrehaladás', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<div class="schrack-progress-cell">
								<progress class="schrack-progress-bar" value="<?php echo esc_attr( (string) $export_displayed ); ?>" max="<?php echo esc_attr( (string) max( 1, $export_total ) ); ?>"></progress>
								<span class="schrack-progress-text">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: processed rows, 2: total rows, 3: percentage. */
											__( '%1$s / %2$s termék és variáció (%3$s%%)', 'schrack-woocommerce-sync' ),
											number_format_i18n( $export_processed ),
											number_format_i18n( $export_total ),
											number_format_i18n( $export_percent )
										)
									);
									?>
								</span>
							</div>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CSV sorok / hibák', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( absint( $product_export['rows'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_export['errors'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Memóriavédelem', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: PHP memory limit, 2: products per batch. */
									__( 'Aktív — PHP limit: %1$s, batch: %2$s termék', 'schrack-woocommerce-sync' ),
									$export_memory_mb > 0 ? number_format_i18n( $export_memory_mb ) . ' MB' : __( 'ismeretlen/korlátlan', 'schrack-woocommerce-sync' ),
									number_format_i18n( $export_batch )
								)
							);
							?>
						</td>
					</tr>
					<?php if ( 'finalizing' === $export_state ) : ?>
						<tr>
							<th><?php esc_html_e( 'CSV összeállítása', 'schrack-woocommerce-sync' ); ?></th>
							<td><?php echo esc_html( size_format( $finalize_position ) . ' / ' . size_format( $finalize_total ) . ' (' . number_format_i18n( $finalize_percent ) . '%)' ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $product_export['message'] ) ) : ?>
					<tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $product_export['message'] ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( 'done' === $export_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Kész fájl', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( (string) ( $product_export['file_name'] ?? '' ) ); ?> (<?php echo esc_html( size_format( (int) ( $product_export['bytes'] ?? 0 ) ) ); ?>)</td>
					</tr>
					<tr>
						<th></th>
						<td>
							<?php
							$download_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'    => 'schrack_wc_sync_product_export_download',
										'export_id' => $export_id,
									),
									admin_url( 'admin-post.php' )
								),
								'schrack_wc_sync_product_export_download'
							);
							?>
							<a class="button button-primary" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'CSV letöltése', 'schrack-woocommerce-sync' ); ?></a>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( 'idle' !== $export_state ) : ?>
			<?php if ( $export_stale || 'error' === $export_state ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
					<input type="hidden" name="action" value="schrack_wc_sync_product_export_resume">
					<?php wp_nonce_field( 'schrack_wc_sync_product_export_resume' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Export folytatása az ellenőrzőponttól', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
				<input type="hidden" name="action" value="schrack_wc_sync_product_export_reset">
				<?php wp_nonce_field( 'schrack_wc_sync_product_export_reset' ); ?>
				<button type="submit" class="button schrack-stop-button"><?php echo esc_html( $export_active ? __( 'Export leállítása és törlése', 'schrack-woocommerce-sync' ) : __( 'Exportfájl és állapot törlése', 'schrack-woocommerce-sync' ) ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<div class="schrack-panel">
		<h2><?php esc_html_e( '2. Termékmentés visszaimportálása', 'schrack-woocommerce-sync' ); ?></h2>
		<p><?php esc_html_e( 'Töltsd fel az előző lépésben létrehozott CSV-t. Az import a háttérben fut, ezért a böngészőt be lehet zárni.', 'schrack-woocommerce-sync' ); ?></p>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'Import előtt készíts adatbázis-mentést, és az import idejére állítsd le a furnizor-katalógus szinkront.', 'schrack-woocommerce-sync' ); ?></strong></p>
		</div>

		<?php if ( 'done' === $export_state && '' !== $export_id ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'A fenti kész export közvetlenül is visszaimportálható, így a WordPress feltöltési méretkorlátja nem számít.', 'schrack-woocommerce-sync' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="schrack_wc_sync_product_import_export_file">
					<input type="hidden" name="export_id" value="<?php echo esc_attr( $export_id ); ?>">
					<?php wp_nonce_field( 'schrack_wc_sync_product_import_export_file' ); ?>
					<label><input type="radio" name="product_import_mode" value="update" checked <?php disabled( $transfer_active ); ?>> <?php esc_html_e( 'Meglévő termékek frissítése', 'schrack-woocommerce-sync' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="product_import_mode" value="create" <?php disabled( $transfer_active ); ?>> <?php esc_html_e( 'Létrehozás új áruházban', 'schrack-woocommerce-sync' ); ?></label>
					&nbsp;&nbsp;
					<button type="submit" class="button button-primary" <?php disabled( $transfer_active ); ?>><?php esc_html_e( 'Kész export közvetlen importálása', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_product_import_start">
			<?php wp_nonce_field( 'schrack_wc_sync_product_import_start' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="product-import-csv"><?php esc_html_e( 'WooCommerce termék CSV', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<input id="product-import-csv" type="file" name="product_import_csv" accept=".csv,.txt,text/csv,text/plain" required <?php disabled( $transfer_active ); ?>>
						<p class="description"><?php echo esc_html( sprintf( __( 'Maximális feltöltési méret: %s.', 'schrack-woocommerce-sync' ), size_format( (int) apply_filters( 'import_upload_size_limit', wp_max_upload_size() ) ) ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Import mód', 'schrack-woocommerce-sync' ); ?></th>
					<td>
						<label><input type="radio" name="product_import_mode" value="update" checked <?php disabled( $transfer_active ); ?>> <strong><?php esc_html_e( 'Meglévő termékek frissítése', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Ugyanabba az áruházba történő visszaállításhoz. ID vagy SKU alapján frissít; a nem létező sorokat kihagyja.', 'schrack-woocommerce-sync' ); ?></p>
						<br>
						<label><input type="radio" name="product_import_mode" value="create" <?php disabled( $transfer_active ); ?>> <strong><?php esc_html_e( 'Létrehozás üres vagy új áruházban', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Új termékeket és variációkat hoz létre. A már létező ID/SKU sorokat biztonságból kihagyja.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit"><button type="submit" class="button button-primary" <?php disabled( $transfer_active ); ?>><?php esc_html_e( 'Háttér-import indítása', 'schrack-woocommerce-sync' ); ?></button></p>
		</form>
	</div>

	<div class="schrack-panel">
		<div class="schrack-panel-header">
			<h2><?php esc_html_e( 'Import állapota', 'schrack-woocommerce-sync' ); ?></h2>
			<?php if ( $import_active && ! $import_stale ) : ?><span class="schrack-auto-refresh"><?php esc_html_e( 'Automatikus frissítés 5 másodpercenként', 'schrack-woocommerce-sync' ); ?></span><?php endif; ?>
		</div>

		<?php if ( $import_stale ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Az import több mint 30 perce nem jelzett előrehaladást. Nagy képek letöltése lassú lehet; ellenőrizd az Action Scheduler és PHP naplót.', 'schrack-woocommerce-sync' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr><th><?php esc_html_e( 'Állapot', 'schrack-woocommerce-sync' ); ?></th><td><span class="schrack-status-pill <?php echo esc_attr( $import_stale ? 'is-error' : ( $state_classes[ $import_state ] ?? 'is-warning' ) ); ?>"><?php echo esc_html( $state_labels[ $import_state ] ?? ucfirst( $import_state ) ); ?></span></td></tr>
				<?php if ( 'idle' !== $import_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Előrehaladás', 'schrack-woocommerce-sync' ); ?></th>
						<td><div class="schrack-progress-cell"><progress class="schrack-progress-bar" value="<?php echo esc_attr( (string) $import_percent ); ?>" max="100"></progress><span class="schrack-progress-text"><?php echo esc_html( number_format_i18n( $import_percent ) . '%' ); ?></span></div></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Importált / variáció / frissített', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( absint( $product_import['imported'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_import['imported_variations'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_import['updated'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Hibás / kihagyott', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( absint( $product_import['failed'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_import['skipped'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr><th><?php esc_html_e( 'Import mód', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( 'yes' === (string) ( $product_import['update_existing'] ?? 'no' ) ? __( 'Meglévő termékek frissítése', 'schrack-woocommerce-sync' ) : __( 'Új termékek létrehozása', 'schrack-woocommerce-sync' ) ); ?></td></tr>
					<tr>
						<th><?php esc_html_e( 'Memóriavédelem', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: PHP memory limit, 2: CSV rows per batch. */
									__( 'Aktív — PHP limit: %1$s, batch: %2$s CSV sor', 'schrack-woocommerce-sync' ),
									$import_memory_mb > 0 ? number_format_i18n( $import_memory_mb ) . ' MB' : __( 'ismeretlen/korlátlan', 'schrack-woocommerce-sync' ),
									number_format_i18n( $import_batch )
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $product_import['message'] ) ) : ?><tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $product_import['message'] ) ); ?></td></tr><?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $warnings ) ) : ?>
			<h3><?php esc_html_e( 'Első hibák és kihagyások', 'schrack-woocommerce-sync' ); ?></h3>
			<ul class="ul-disc">
				<?php foreach ( $warnings as $warning ) : ?><li><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $warning ) ); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( 'idle' !== $import_state ) : ?>
			<?php if ( $import_stale || 'error' === $import_state ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
					<input type="hidden" name="action" value="schrack_wc_sync_product_import_resume">
					<?php wp_nonce_field( 'schrack_wc_sync_product_import_resume' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import folytatása az utolsó pozíciótól', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
				<input type="hidden" name="action" value="schrack_wc_sync_product_import_reset">
				<?php wp_nonce_field( 'schrack_wc_sync_product_import_reset' ); ?>
				<button type="submit" class="button schrack-stop-button"><?php echo esc_html( $import_active ? __( 'Import leállítása és fájl törlése', 'schrack-woocommerce-sync' ) : __( 'Importállapot törlése', 'schrack-woocommerce-sync' ) ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<div class="schrack-panel">
		<div class="schrack-panel-header">
			<div>
				<h2><?php esc_html_e( '3. Termékkategóriák exportálása és visszaimportálása', 'schrack-woocommerce-sync' ); ?></h2>
				<p><?php esc_html_e( 'Külön CSV-mentés a teljes product_cat hierarchiáról. Tartalmazza a nevet, slugot, szülőútvonalat, leírást, megjelenítési módot, sorrendet, kategóriaképet és minden további kategória Meta mezőt.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="schrack_wc_sync_export_categories">
				<?php wp_nonce_field( 'schrack_wc_sync_categories_csv' ); ?>
				<button type="submit" class="button button-secondary" <?php disabled( $transfer_active ); ?>><?php esc_html_e( 'Kategória CSV letöltése', 'schrack-woocommerce-sync' ); ?></button>
			</form>
		</div>

		<p class="description"><?php esc_html_e( 'Új áruházban a hierarchia az útvonalak alapján újra létrejön. A kategóriaképet először URL alapján keresi a Médiatárban, és ha hiányzik, megpróbálja letölteni. A Meta oszlopok tömbös és ismétlődő értékei visszaállítható formában maradnak.', 'schrack-woocommerce-sync' ); ?></p>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_import_categories">
			<input type="hidden" name="category_csv_return" value="schrack-sync-export">
			<?php wp_nonce_field( 'schrack_wc_sync_categories_csv' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="category-import-csv"><?php esc_html_e( 'Kategória CSV', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<input id="category-import-csv" type="file" name="schrack_categories_csv" accept=".csv,.txt,text/csv,text/plain" required <?php disabled( $product_transfer_active || $category_live ); ?>>
						<p class="description"><?php esc_html_e( 'A frissítés elsődlegesen termék-kategória ID, slug és teljes útvonal alapján azonosít. A szülők mindig a gyermekek előtt állnak az exportban.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kategóriaimport mód', 'schrack-woocommerce-sync' ); ?></th>
					<td>
						<label><input type="radio" name="category_import_mode" value="update" checked <?php disabled( $product_transfer_active || $category_live ); ?>> <strong><?php esc_html_e( 'Ugyanez az áruház — frissítés ID alapján', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'A meglévő kategóriákat az exportált term ID alapján frissíti; ha az ID nem található, útvonalat és slugot használ.', 'schrack-woocommerce-sync' ); ?></p>
						<br>
						<label><input type="radio" name="category_import_mode" value="create" <?php disabled( $product_transfer_active || $category_live ); ?>> <strong><?php esc_html_e( 'Új áruház — visszaállítás útvonal/slug alapján', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Nem bízik a másik adatbázisból származó számszerű ID-kben, így nem ír felül azonos ID-jű, de más kategóriát.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit"><button type="submit" class="button button-primary" <?php disabled( $product_transfer_active || $category_live ); ?>><?php esc_html_e( 'Háttér-kategóriaimport indítása', 'schrack-woocommerce-sync' ); ?></button></p>
		</form>

		<div class="schrack-panel-header">
			<h3><?php esc_html_e( 'Kategóriaimport állapota', 'schrack-woocommerce-sync' ); ?></h3>
			<?php if ( $category_live ) : ?><span class="schrack-auto-refresh"><?php esc_html_e( 'Automatikus frissítés 5 másodpercenként', 'schrack-woocommerce-sync' ); ?></span><?php endif; ?>
		</div>

		<?php if ( $category_stale ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'A kategóriaimport több mint 30 perce nem haladt. Folytatható az utolsó mentett byte-pozíciótól.', 'schrack-woocommerce-sync' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr><th><?php esc_html_e( 'Állapot', 'schrack-woocommerce-sync' ); ?></th><td><span class="schrack-status-pill <?php echo esc_attr( $category_stale ? 'is-error' : ( $state_classes[ $category_state ] ?? 'is-warning' ) ); ?>"><?php echo esc_html( $state_labels[ $category_state ] ?? ucfirst( $category_state ) ); ?></span></td></tr>
				<?php if ( 'idle' !== $category_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Előrehaladás', 'schrack-woocommerce-sync' ); ?></th>
						<td><div class="schrack-progress-cell"><progress class="schrack-progress-bar" value="<?php echo esc_attr( (string) $category_percent ); ?>" max="100"></progress><span class="schrack-progress-text"><?php echo esc_html( number_format_i18n( $category_processed ) . ' / ' . number_format_i18n( $category_total ) . ' (' . number_format_i18n( $category_percent ) . '%)' ); ?></span></div></td>
					</tr>
					<tr><th><?php esc_html_e( 'Létrehozott / frissített / kihagyott', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $category_import['created'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $category_import['updated'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $category_import['skipped'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Import mód', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( 'no' === (string) ( $category_import['update_existing'] ?? 'yes' ) ? __( 'Új áruház: útvonal/slug', 'schrack-woocommerce-sync' ) : __( 'Ugyanez az áruház: ID-frissítés', 'schrack-woocommerce-sync' ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( ! empty( $category_import['message'] ) ) : ?><tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $category_import['message'] ) ); ?></td></tr><?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $category_warnings ) ) : ?>
			<h4><?php esc_html_e( 'Első kategóriaimport-figyelmeztetések', 'schrack-woocommerce-sync' ); ?></h4>
			<ul class="ul-disc"><?php foreach ( array_slice( $category_warnings, 0, 10 ) as $warning ) : ?><li><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $warning ) ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>

		<?php if ( 'idle' !== $category_state ) : ?>
			<?php if ( $category_stale || 'error' === $category_state ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
					<input type="hidden" name="action" value="schrack_wc_sync_category_import_resume">
					<?php wp_nonce_field( 'schrack_wc_sync_category_import_resume' ); ?>
					<button type="submit" class="button button-primary" <?php disabled( $product_transfer_active ); ?>><?php esc_html_e( 'Kategóriaimport folytatása', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
				<input type="hidden" name="action" value="schrack_wc_sync_category_import_reset">
				<?php wp_nonce_field( 'schrack_wc_sync_category_import_reset' ); ?>
				<button type="submit" class="button schrack-stop-button"><?php echo esc_html( $category_live ? __( 'Kategóriaimport leállítása és törlése', 'schrack-woocommerce-sync' ) : __( 'Kategóriaimport állapotának törlése', 'schrack-woocommerce-sync' ) ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>

<?php if ( $should_refresh ) : ?>
	<script>
	window.setTimeout( function () {
		window.location.reload();
	}, 5000 );
	</script>
<?php endif; ?>
