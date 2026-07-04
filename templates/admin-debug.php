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
	<?php if ( ! empty( $notice['data'] ) ) : ?>
		<p class="schrack-inline-actions">
			<button type="button" class="button" id="schrack-debug-copy"><?php esc_html_e( 'Copy to clipboard', 'schrack-woocommerce-sync' ); ?></button>
			<button type="button" class="button" id="schrack-debug-download"><?php esc_html_e( 'Download as JSON', 'schrack-woocommerce-sync' ); ?></button>
			<span id="schrack-debug-copy-status" class="description"></span>
		</p>
	<?php endif; ?>

	<div class="schrack-panel">
		<h2><?php esc_html_e( 'Raw feed sample', 'schrack-woocommerce-sync' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Fetches a sample directly from the selected feed without importing or caching anything. Each row is shown as the raw feed columns alongside the technical_attributes the current mapping would extract from it, so category/filter attribute handling can be tuned against real data. Rows are capped at 5000 to keep a single fetch fast and light on memory; that already covers most/all category diversity in the catalog, but is not a guaranteed full dump of every SKU.', 'schrack-woocommerce-sync' ); ?>
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
				<input type="number" id="schrack-debug-limit" name="debug_limit" value="10" min="1" max="5000" step="1" class="small-text">
			</label>
			<button type="button" class="button" id="schrack-debug-limit-max"><?php esc_html_e( 'Full export (5000)', 'schrack-woocommerce-sync' ); ?></button>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Fetch raw sample', 'schrack-woocommerce-sync' ); ?></button>
		</form>
	</div>
</div>
<script>
( function () {
	var limitInput = document.getElementById( 'schrack-debug-limit' );
	var maxButton   = document.getElementById( 'schrack-debug-limit-max' );

	if ( maxButton && limitInput ) {
		maxButton.addEventListener( 'click', function () {
			limitInput.value = limitInput.max;
		} );
	}
} )();
</script>
<?php if ( ! empty( $notice['data'] ) ) : ?>
<script>
( function () {
	var copyButton     = document.getElementById( 'schrack-debug-copy' );
	var downloadButton = document.getElementById( 'schrack-debug-download' );
	var status         = document.getElementById( 'schrack-debug-copy-status' );
	var pre            = document.querySelector( '.schrack-debug-output pre' );

	if ( ! pre ) {
		return;
	}

	if ( copyButton ) {
		copyButton.addEventListener( 'click', function () {
			if ( ! navigator.clipboard ) {
				status.textContent = <?php echo wp_json_encode( __( 'Clipboard access is unavailable; select the text below and copy manually.', 'schrack-woocommerce-sync' ) ); ?>;
				return;
			}

			navigator.clipboard.writeText( pre.textContent ).then(
				function () {
					status.textContent = <?php echo wp_json_encode( __( 'Copied.', 'schrack-woocommerce-sync' ) ); ?>;
				},
				function () {
					status.textContent = <?php echo wp_json_encode( __( 'Could not copy automatically; select the text below and copy manually.', 'schrack-woocommerce-sync' ) ); ?>;
				}
			);
		} );
	}

	if ( downloadButton ) {
		downloadButton.addEventListener( 'click', function () {
			var blob = new Blob( [ pre.textContent ], { type: 'application/json' } );
			var url  = URL.createObjectURL( blob );
			var link = document.createElement( 'a' );

			link.href = url;
			link.download = 'schrack-debug-' + Date.now() + '.json';
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			URL.revokeObjectURL( url );
		} );
	}
} )();
</script>
<?php endif; ?>
