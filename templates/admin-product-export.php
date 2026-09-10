<?php
/**
 * Complete WooCommerce product export/import screen.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-export-context.php';
?>
<div class="wrap schrack-sync-admin" data-product-transfer data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-status-nonce="<?php echo esc_attr( wp_create_nonce( 'schrack_wc_sync_transfer_status' ) ); ?>" data-transfer-active="<?php echo $should_refresh ? '1' : '0'; ?>">
	<h1><?php esc_html_e( 'WooCommerce termék és kategória export / import', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'export' ); ?>
	<div data-transfer-notice role="status" aria-live="polite" <?php echo empty( $notice ) ? 'hidden' : ''; ?>><?php $this->render_notice( $notice ); ?></div>
	<div data-transfer-connection role="status" aria-live="polite" hidden></div>
	<p><button type="button" class="button" data-transfer-refresh><?php esc_html_e( 'Actualizează starea', 'schrack-woocommerce-sync' ); ?></button></p>

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

		<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<fieldset data-transfer-lock="all" <?php disabled( $transfer_active ); ?>>
			<input type="hidden" name="action" value="schrack_wc_sync_product_export_start">
			<?php wp_nonce_field( 'schrack_wc_sync_product_export_start' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="export-scope"><?php esc_html_e( 'Exportált termékek', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-scope" name="export_scope">
							<?php foreach ( $export_scope_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['scope'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Az összes termék opció a lenti szűrőket figyelmen kívül hagyja, és az importált termékek mellett a kézzel vagy más bővítménnyel létrehozott termékeket is exportálja.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-status"><?php esc_html_e( 'Termékállapot', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-status" name="export_status">
							<?php foreach ( $export_status_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-product-type"><?php esc_html_e( 'Terméktípus', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-product-type" name="export_product_type">
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
						<select id="export-source" name="export_source">
							<?php foreach ( $export_source_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['source'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-stock-status"><?php esc_html_e( 'Készletállapot', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<select id="export-stock-status" name="export_stock_status">
							<?php foreach ( $export_stock_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $export_filters['stock_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="export-search"><?php esc_html_e( 'Név, SKU vagy ID', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<input id="export-search" type="search" class="regular-text" name="export_search" value="<?php echo esc_attr( $export_filters['search'] ); ?>" maxlength="100">
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
								<input type="radio" name="export_column_mode" value="full" <?php checked( $export_column_mode, 'full' ); ?> >
								<strong><?php esc_html_e( 'Teljes mentési fejléc', 'schrack-woocommerce-sync' ); ?></strong>
								<span><?php esc_html_e( 'Minden WooCommerce oszlop, olvasható furnizorár, attribútum, letöltés és minden további visszaállítható Meta mező.', 'schrack-woocommerce-sync' ); ?></span>
							</label>
							<label class="schrack-export-columns__mode">
								<input type="radio" name="export_column_mode" value="custom" <?php checked( $export_column_mode, 'custom' ); ?> >
								<strong><?php esc_html_e( 'Egyedi fejléc összeállítása', 'schrack-woocommerce-sync' ); ?></strong>
								<span><?php esc_html_e( 'Csak a kiválasztott mezők kerülnek a CSV-be, az itt megadott sorrendben.', 'schrack-woocommerce-sync' ); ?></span>
							</label>

							<div class="schrack-export-columns__extras">
								<label for="export-attribute-mode"><strong><?php esc_html_e( 'Attribútumok elrendezése', 'schrack-woocommerce-sync' ); ?></strong></label>
								<select id="export-attribute-mode" name="export_attribute_mode">
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
										<p><button type="button" class="button" data-export-column-add ><?php esc_html_e( 'Kijelölt mezők hozzáadása →', 'schrack-woocommerce-sync' ); ?></button></p>
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
									<label><input type="checkbox" name="export_include_downloads" value="1" <?php checked( $export_include_downloads ); ?> > <?php esc_html_e( 'Dinamikus letöltési oszlopok hozzáadása a fejléc végéhez', 'schrack-woocommerce-sync' ); ?></label>
									<label for="export-extra-meta-keys"><strong><?php esc_html_e( 'További Meta kulcsok', 'schrack-woocommerce-sync' ); ?></strong></label>
									<textarea id="export-extra-meta-keys" name="export_extra_meta_keys" rows="3" class="large-text code" placeholder="_sajat_meta_kulcs&#10;_masik_meta_kulcs"></textarea>
									<p class="description"><?php esc_html_e( 'Soronként egy kulcs. Az itt megadott Meta oszlopok a kiválasztott fejléc végére kerülnek.', 'schrack-woocommerce-sync' ); ?></p>
								</div>
							</div>
						</fieldset>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Háttér-export indítása', 'schrack-woocommerce-sync' ); ?></button>
				<span class="description" data-transfer-export-running <?php echo $export_active ? '' : 'hidden'; ?>><?php esc_html_e( 'Egy export már fut.', 'schrack-woocommerce-sync' ); ?></span>
			</p>
			</fieldset>
		</form>
	</div>

	<div class="schrack-panel" data-transfer-fragment="export">
		<?php include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-transfer-export.php'; ?>
	</div>

	<div class="schrack-panel">
		<h2><?php esc_html_e( '2. Termékmentés visszaimportálása', 'schrack-woocommerce-sync' ); ?></h2>
		<p><?php esc_html_e( 'Töltsd fel az előző lépésben létrehozott CSV-t. Az import a háttérben fut, ezért a böngészőt be lehet zárni.', 'schrack-woocommerce-sync' ); ?></p>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'Import előtt készíts adatbázis-mentést, és az import idejére állítsd le a furnizor-katalógus szinkront.', 'schrack-woocommerce-sync' ); ?></strong></p>
		</div>

		<div data-transfer-completed-export <?php echo 'done' !== $export_state || '' === $export_id ? 'hidden' : ''; ?>>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'A fenti kész export közvetlenül is visszaimportálható, így a WordPress feltöltési méretkorlátja nem számít.', 'schrack-woocommerce-sync' ); ?></p>
				<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<fieldset data-transfer-lock="all" <?php disabled( $transfer_active ); ?>>
					<input type="hidden" name="action" value="schrack_wc_sync_product_import_export_file">
					<input type="hidden" name="export_id" value="<?php echo esc_attr( $export_id ); ?>">
					<?php wp_nonce_field( 'schrack_wc_sync_product_import_export_file' ); ?>
					<label><input type="radio" name="product_import_mode" value="update" checked> <?php esc_html_e( 'Meglévő termékek frissítése', 'schrack-woocommerce-sync' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="product_import_mode" value="create"> <?php esc_html_e( 'Létrehozás új áruházban', 'schrack-woocommerce-sync' ); ?></label>
					&nbsp;&nbsp;
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Kész export közvetlen importálása', 'schrack-woocommerce-sync' ); ?></button>
					</fieldset>
				</form>
			</div>
		</div>

		<form data-transfer-action method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<fieldset data-transfer-lock="all" <?php disabled( $transfer_active ); ?>>
			<input type="hidden" name="action" value="schrack_wc_sync_product_import_start">
			<?php wp_nonce_field( 'schrack_wc_sync_product_import_start' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="product-import-csv"><?php esc_html_e( 'WooCommerce termék CSV', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<input id="product-import-csv" type="file" name="product_import_csv" accept=".csv,.txt,text/csv,text/plain" required>
						<p class="description"><?php echo esc_html( sprintf( __( 'Maximális feltöltési méret: %s.', 'schrack-woocommerce-sync' ), size_format( (int) apply_filters( 'import_upload_size_limit', wp_max_upload_size() ) ) ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Import mód', 'schrack-woocommerce-sync' ); ?></th>
					<td>
						<label><input type="radio" name="product_import_mode" value="update" checked> <strong><?php esc_html_e( 'Meglévő termékek frissítése', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Ugyanabba az áruházba történő visszaállításhoz. ID vagy SKU alapján frissít; a nem létező sorokat kihagyja.', 'schrack-woocommerce-sync' ); ?></p>
						<br>
						<label><input type="radio" name="product_import_mode" value="create"> <strong><?php esc_html_e( 'Létrehozás üres vagy új áruházban', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Új termékeket és variációkat hoz létre. A már létező ID/SKU sorokat biztonságból kihagyja.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Háttér-import indítása', 'schrack-woocommerce-sync' ); ?></button></p>
			</fieldset>
		</form>
	</div>

	<div class="schrack-panel" data-transfer-fragment="import">
		<?php include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-transfer-import.php'; ?>
	</div>

	<div class="schrack-panel">
		<div class="schrack-panel-header">
			<div>
				<h2><?php esc_html_e( '3. Termékkategóriák exportálása és visszaimportálása', 'schrack-woocommerce-sync' ); ?></h2>
				<p><?php esc_html_e( 'Külön CSV-mentés a teljes product_cat hierarchiáról. Tartalmazza a nevet, slugot, szülőútvonalat, leírást, megjelenítési módot, sorrendet, kategóriaképet és minden további kategória Meta mezőt.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<fieldset data-transfer-lock="all" <?php disabled( $transfer_active ); ?>>
				<input type="hidden" name="action" value="schrack_wc_sync_export_categories">
				<?php wp_nonce_field( 'schrack_wc_sync_categories_csv' ); ?>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Kategória CSV letöltése', 'schrack-woocommerce-sync' ); ?></button>
				</fieldset>
			</form>
		</div>

		<p class="description"><?php esc_html_e( 'Új áruházban a hierarchia az útvonalak alapján újra létrejön. A kategóriaképet először URL alapján keresi a Médiatárban, és ha hiányzik, megpróbálja letölteni. A Meta oszlopok tömbös és ismétlődő értékei visszaállítható formában maradnak.', 'schrack-woocommerce-sync' ); ?></p>

		<form data-transfer-action method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<fieldset data-transfer-lock="all" <?php disabled( $transfer_active ); ?>>
			<input type="hidden" name="action" value="schrack_wc_sync_import_categories">
			<input type="hidden" name="category_csv_return" value="schrack-sync-export">
			<?php wp_nonce_field( 'schrack_wc_sync_categories_csv' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="category-import-csv"><?php esc_html_e( 'Kategória CSV', 'schrack-woocommerce-sync' ); ?></label></th>
					<td>
						<input id="category-import-csv" type="file" name="schrack_categories_csv" accept=".csv,.txt,text/csv,text/plain" required>
						<p class="description"><?php esc_html_e( 'A frissítés elsődlegesen termék-kategória ID, slug és teljes útvonal alapján azonosít. A szülők mindig a gyermekek előtt állnak az exportban.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kategóriaimport mód', 'schrack-woocommerce-sync' ); ?></th>
					<td>
						<label><input type="radio" name="category_import_mode" value="update" checked> <strong><?php esc_html_e( 'Ugyanez az áruház — frissítés ID alapján', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'A meglévő kategóriákat az exportált term ID alapján frissíti; ha az ID nem található, útvonalat és slugot használ.', 'schrack-woocommerce-sync' ); ?></p>
						<br>
						<label><input type="radio" name="category_import_mode" value="create"> <strong><?php esc_html_e( 'Új áruház — visszaállítás útvonal/slug alapján', 'schrack-woocommerce-sync' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Nem bízik a másik adatbázisból származó számszerű ID-kben, így nem ír felül azonos ID-jű, de más kategóriát.', 'schrack-woocommerce-sync' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Háttér-kategóriaimport indítása', 'schrack-woocommerce-sync' ); ?></button></p>
			</fieldset>
		</form>

		<div data-transfer-fragment="category">
			<?php include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-transfer-category.php'; ?>
		</div>
	</div>
</div>
