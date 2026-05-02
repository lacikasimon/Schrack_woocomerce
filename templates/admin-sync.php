<?php
/**
 * Manual sync template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack Manual Sync', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'manual' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<div class="schrack-grid">
		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Batch Sync', 'schrack-woocommerce-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-button-grid">
				<input type="hidden" name="action" value="schrack_wc_sync_manual_sync">
				<?php wp_nonce_field( 'schrack_wc_sync_manual_sync' ); ?>
				<button type="submit" class="button button-secondary" name="sync_task" value="catalog"><?php esc_html_e( 'Import catalog', 'schrack-woocommerce-sync' ); ?></button>
				<button type="submit" class="button button-secondary" name="sync_task" value="images"><?php esc_html_e( 'Sync images', 'schrack-woocommerce-sync' ); ?></button>
				<button type="submit" class="button button-secondary" name="sync_task" value="prices"><?php esc_html_e( 'Sync prices', 'schrack-woocommerce-sync' ); ?></button>
				<button type="submit" class="button button-secondary" name="sync_task" value="stock"><?php esc_html_e( 'Sync stock', 'schrack-woocommerce-sync' ); ?></button>
				<button type="submit" class="button button-primary" name="sync_task" value="full"><?php esc_html_e( 'Full sync', 'schrack-woocommerce-sync' ); ?></button>
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
