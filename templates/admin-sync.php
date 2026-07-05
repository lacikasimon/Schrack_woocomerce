<?php
/**
 * Manual sync template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$queue_status = isset( $queue_status ) && is_array( $queue_status ) ? $queue_status : array();
$stop_request = isset( $stop_request ) && is_array( $stop_request ) ? $stop_request : null;
$settings     = isset( $settings ) && is_array( $settings ) ? $settings : array();
$image_import_enabled = 'yes' === (string) ( $settings['image_import_enabled'] ?? 'yes' );
$schrack_enabled      = 'yes' === (string) ( $settings['schrack_enabled'] ?? 'yes' );
$telesystem_enabled   = 'yes' === (string) ( $settings['telesystem_enabled'] ?? 'yes' );
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack Manual Sync', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'manual' ); ?>
	<?php $this->render_notice( $notice ); ?>
	<?php include SCHRACK_WC_SYNC_PATH . 'templates/admin-sync-dashboard.php'; ?>

	<div class="schrack-grid">
		<div class="schrack-panel">
			<div class="schrack-panel-header">
				<h2><?php esc_html_e( 'Active Queue', 'schrack-woocommerce-sync' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="schrack_wc_sync_stop_syncs">
					<input type="hidden" name="redirect_page" value="schrack-sync-manual">
					<?php wp_nonce_field( 'schrack_wc_sync_stop_syncs' ); ?>
					<button type="submit" class="button button-secondary schrack-stop-button"><?php esc_html_e( 'Stop syncs', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			</div>
			<?php if ( null !== $stop_request ) : ?>
				<p><span class="schrack-status-pill is-warning"><?php esc_html_e( 'Stop requested', 'schrack-woocommerce-sync' ); ?></span> <?php echo esc_html( (string) ( $stop_request['requested_at'] ?? '' ) ); ?></p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Operation', 'schrack-woocommerce-sync' ); ?></th>
						<th><?php esc_html_e( 'State', 'schrack-woocommerce-sync' ); ?></th>
						<th><?php esc_html_e( 'Pending', 'schrack-woocommerce-sync' ); ?></th>
						<th><?php esc_html_e( 'Running', 'schrack-woocommerce-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $queue_status as $row ) : ?>
						<?php
						$state       = (string) ( $row['state'] ?? 'idle' );
						$state_class = in_array( $state, array( 'running' ), true ) ? 'is-error' : ( in_array( $state, array( 'queued', 'due' ), true ) ? 'is-warning' : 'is-ok' );
						$state_label = match ( $state ) {
							'running' => __( 'Running', 'schrack-woocommerce-sync' ),
							'due'     => __( 'Due now', 'schrack-woocommerce-sync' ),
							'queued'  => __( 'Queued', 'schrack-woocommerce-sync' ),
							'scheduled' => __( 'Scheduled', 'schrack-woocommerce-sync' ),
							default   => __( 'Idle', 'schrack-woocommerce-sync' ),
						};
						?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></td>
							<td><span class="schrack-status-pill <?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_label ); ?></span></td>
							<td><?php echo esc_html( (string) absint( $row['pending'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) absint( $row['running'] ?? 0 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Batch Sync', 'schrack-woocommerce-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Each action below is scoped to one supplier (furnizor) so it is clear what runs against which feed. "Full sync" runs every enabled supplier and stage in sequence.', 'schrack-woocommerce-sync' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-sync-supplier-group">
				<input type="hidden" name="action" value="schrack_wc_sync_manual_sync">
				<?php wp_nonce_field( 'schrack_wc_sync_manual_sync' ); ?>
				<button type="submit" class="button button-primary schrack-sync-supplier-group__full" name="sync_task" value="full" <?php disabled( ! $schrack_enabled && ! $telesystem_enabled ); ?>>
					<?php esc_html_e( 'Full sync (toti furnizorii)', 'schrack-woocommerce-sync' ); ?>
				</button>

				<div class="schrack-sync-supplier">
					<h3>
						<?php esc_html_e( 'Furnizor: Schrack', 'schrack-woocommerce-sync' ); ?>
						<span class="schrack-status-pill <?php echo $schrack_enabled ? 'is-ok' : 'is-warning'; ?>">
							<?php echo $schrack_enabled ? esc_html__( 'Activ', 'schrack-woocommerce-sync' ) : esc_html__( 'Dezactivat', 'schrack-woocommerce-sync' ); ?>
						</span>
					</h3>
					<div class="schrack-button-grid">
						<button type="submit" class="button button-secondary" name="sync_task" value="catalog" <?php disabled( ! $schrack_enabled ); ?>><?php esc_html_e( 'Import catalog', 'schrack-woocommerce-sync' ); ?></button>
						<button type="submit" class="button button-secondary" name="sync_task" value="prices" <?php disabled( ! $schrack_enabled ); ?>><?php esc_html_e( 'Sync prices', 'schrack-woocommerce-sync' ); ?></button>
						<button type="submit" class="button button-secondary" name="sync_task" value="stock" <?php disabled( ! $schrack_enabled ); ?>><?php esc_html_e( 'Sync stock', 'schrack-woocommerce-sync' ); ?></button>
					</div>
					<?php if ( ! $schrack_enabled ) : ?>
						<p class="description"><?php esc_html_e( 'Schrack sync is disabled in settings.', 'schrack-woocommerce-sync' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="schrack-sync-supplier">
					<h3>
						<?php esc_html_e( 'Furnizor: Telesystem', 'schrack-woocommerce-sync' ); ?>
						<span class="schrack-status-pill <?php echo $telesystem_enabled ? 'is-ok' : 'is-warning'; ?>">
							<?php echo $telesystem_enabled ? esc_html__( 'Activ', 'schrack-woocommerce-sync' ) : esc_html__( 'Dezactivat', 'schrack-woocommerce-sync' ); ?>
						</span>
					</h3>
					<p class="description"><?php esc_html_e( 'Telesystem prices and stock come from the same CSV row as the catalog import, so there is only one action for this supplier.', 'schrack-woocommerce-sync' ); ?></p>
					<div class="schrack-button-grid">
						<button type="submit" class="button button-secondary" name="sync_task" value="telesystem_catalog" <?php disabled( ! $telesystem_enabled ); ?>><?php esc_html_e( 'Import Telesystem', 'schrack-woocommerce-sync' ); ?></button>
					</div>
					<?php if ( ! $telesystem_enabled ) : ?>
						<p class="description"><?php esc_html_e( 'Telesystem sync is disabled in settings.', 'schrack-woocommerce-sync' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="schrack-sync-supplier">
					<h3>
						<?php esc_html_e( 'Imagini (ambii furnizori)', 'schrack-woocommerce-sync' ); ?>
						<span class="schrack-status-pill <?php echo $image_import_enabled ? 'is-ok' : 'is-warning'; ?>">
							<?php echo $image_import_enabled ? esc_html__( 'Activ', 'schrack-woocommerce-sync' ) : esc_html__( 'Dezactivat', 'schrack-woocommerce-sync' ); ?>
						</span>
					</h3>
					<div class="schrack-button-grid">
						<button type="submit" class="button button-secondary" name="sync_task" value="images" <?php disabled( ! $image_import_enabled ); ?>><?php esc_html_e( 'Sync images', 'schrack-woocommerce-sync' ); ?></button>
					</div>
					<?php if ( ! $image_import_enabled ) : ?>
						<p class="description"><?php esc_html_e( 'Image sync is disabled; products without downloaded images use their stored external image URLs.', 'schrack-woocommerce-sync' ); ?></p>
					<?php endif; ?>
				</div>
			</form>
		</div>

		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Manual SKU Test', 'schrack-woocommerce-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="schrack_wc_sync_sku_action">
				<?php wp_nonce_field( 'schrack_wc_sync_sku_action' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="schrack_manual_sku"><?php esc_html_e( 'SKU / item number', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input class="regular-text" id="schrack_manual_sku" type="text" name="sku" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_name"><?php esc_html_e( 'Product name', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input class="regular-text" id="schrack_manual_name" type="text" name="product_name"></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_category"><?php esc_html_e( 'Category path', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input class="regular-text" id="schrack_manual_category" type="text" name="category_path" placeholder="Automatizalas > Kapcsolok"></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_purchase_price"><?php esc_html_e( 'Manual purchase price', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input id="schrack_manual_purchase_price" type="number" min="0" step="0.01" name="purchase_price"></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_stock"><?php esc_html_e( 'Manual stock quantity', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input id="schrack_manual_stock" type="number" min="0" step="0.01" name="stock_quantity"></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_ean"><?php esc_html_e( 'EAN', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input class="regular-text" id="schrack_manual_ean" type="text" name="ean"></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_manufacturer"><?php esc_html_e( 'Manufacturer', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input class="regular-text" id="schrack_manual_manufacturer" type="text" name="manufacturer"></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_unit"><?php esc_html_e( 'Unit', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><input class="small-text" id="schrack_manual_unit" type="text" name="unit"></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_short_description"><?php esc_html_e( 'Short description', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><textarea class="large-text" id="schrack_manual_short_description" name="short_description" rows="2"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="schrack_manual_description"><?php esc_html_e( 'Description', 'schrack-woocommerce-sync' ); ?></label></th>
						<td><textarea class="large-text" id="schrack_manual_description" name="description" rows="4"></textarea></td>
					</tr>
				</table>

				<p class="submit schrack-inline-actions">
					<button type="submit" class="button button-secondary" name="sku_task" value="fetch_price"><?php esc_html_e( 'Fetch price', 'schrack-woocommerce-sync' ); ?></button>
					<button type="submit" class="button button-secondary" name="sku_task" value="fetch_stock"><?php esc_html_e( 'Fetch stock', 'schrack-woocommerce-sync' ); ?></button>
					<button type="submit" class="button button-primary" name="sku_task" value="upsert_product"><?php esc_html_e( 'Create/update product', 'schrack-woocommerce-sync' ); ?></button>
				</p>
			</form>
		</div>
	</div>
</div>
