<?php
/**
 * Raw feed debug template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack Debug', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'debug' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<div class="schrack-panel">
		<h2><?php esc_html_e( 'Raw feed sample', 'schrack-woocommerce-sync' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Fetches a small sample directly from the selected feed without importing or caching anything. Each row is shown as the raw feed columns alongside the technical_attributes the current mapping would extract from it, so category/filter attribute handling can be tuned against real data.', 'schrack-woocommerce-sync' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
			<input type="hidden" name="action" value="schrack_wc_sync_debug_fetch">
			<?php wp_nonce_field( 'schrack_wc_sync_debug_fetch' ); ?>
			<select name="debug_source">
				<option value="schrack_csv"><?php esc_html_e( 'Schrack catalog (CSV)', 'schrack-woocommerce-sync' ); ?></option>
				<option value="schrack_xml"><?php esc_html_e( 'Schrack catalog (XML)', 'schrack-woocommerce-sync' ); ?></option>
				<option value="telesystem"><?php esc_html_e( 'Telesystem feed (CSV)', 'schrack-woocommerce-sync' ); ?></option>
			</select>
			<label>
				<?php esc_html_e( 'Rows', 'schrack-woocommerce-sync' ); ?>
				<input type="number" name="debug_limit" value="10" min="1" max="50" step="1" class="small-text">
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Fetch raw sample', 'schrack-woocommerce-sync' ); ?></button>
		</form>
	</div>
</div>
