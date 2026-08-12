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
$transfer_active = $export_active || $import_active;

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
$should_refresh = ( $export_active && ! $export_stale ) || ( $import_active && ! $import_stale );
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Teljes WooCommerce termék export / import', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'export' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<div class="schrack-panel">
		<h2><?php esc_html_e( '1. Teljes termékmentés exportálása', 'schrack-woocommerce-sync' ); ?></h2>
		<p>
			<?php esc_html_e( 'A fájl a WooCommerce hivatalos termék-CSV sémáját használja. Tartalmazza az összes nem törölt terméket és variációt, attribútumot, kategóriát, címkét, képet, letöltést, kapcsolt terméket és minden egyedi metaadatot.', 'schrack-woocommerce-sync' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'A Schrack és Telesystem furnizor mezők, a műszaki adatok, dokumentumok és a teljes nyers feed is Meta oszlopokként kerülnek a mentésbe. A tömbös adatok visszaállítható, jelölt formátumot kapnak.', 'schrack-woocommerce-sync' ); ?>
		</p>
		<p class="description"><?php esc_html_e( 'Ez termékkatalógus-mentés: rendeléseket, vásárlókat és termékértékeléseket nem exportál.', 'schrack-woocommerce-sync' ); ?></p>
		<p class="description"><?php esc_html_e( 'A feldolgozás automatikusan memóriakímélő, időkorlátos batch-méretet választ, kis csoportokban előkészíti és üríti a futásidejű cache-t, és 70% PHP memóriahasználatnál biztonságosan átadja a folytatást a következő háttérfolyamatnak.', 'schrack-woocommerce-sync' ); ?></p>
		<p class="description"><strong><?php esc_html_e( 'Lemezhely: a véglegesítés alatt a munkafájl és a kész CSV egyszerre létezik, ezért legyen legalább a várható CSV méretének kétszerese szabadon.', 'schrack-woocommerce-sync' ); ?></strong></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_product_export_start">
			<?php wp_nonce_field( 'schrack_wc_sync_product_export_start' ); ?>
			<p class="submit">
				<button type="submit" class="button button-primary" <?php disabled( $transfer_active ); ?>><?php esc_html_e( 'Teljes háttér-export indítása', 'schrack-woocommerce-sync' ); ?></button>
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
					<tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( (string) $product_export['message'] ); ?></td></tr>
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
							<a class="button button-primary" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Teljes CSV letöltése', 'schrack-woocommerce-sync' ); ?></a>
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
					<label><input type="radio" name="product_import_mode" value="update" checked <?php disabled( $import_active ); ?>> <?php esc_html_e( 'Meglévő termékek frissítése', 'schrack-woocommerce-sync' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="product_import_mode" value="create" <?php disabled( $import_active ); ?>> <?php esc_html_e( 'Létrehozás új áruházban', 'schrack-woocommerce-sync' ); ?></label>
					&nbsp;&nbsp;
					<button type="submit" class="button button-primary" <?php disabled( $import_active ); ?>><?php esc_html_e( 'Kész export közvetlen importálása', 'schrack-woocommerce-sync' ); ?></button>
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
				<?php if ( ! empty( $product_import['message'] ) ) : ?><tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( (string) $product_import['message'] ); ?></td></tr><?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $warnings ) ) : ?>
			<h3><?php esc_html_e( 'Első hibák és kihagyások', 'schrack-woocommerce-sync' ); ?></h3>
			<ul class="ul-disc">
				<?php foreach ( $warnings as $warning ) : ?><li><?php echo esc_html( (string) $warning ); ?></li><?php endforeach; ?>
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
</div>

<?php if ( $should_refresh ) : ?>
	<script>
	window.setTimeout( function () {
		window.location.reload();
	}, 5000 );
	</script>
<?php endif; ?>
