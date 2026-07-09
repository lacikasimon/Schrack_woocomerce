<?php
/**
 * Raw feed debug template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$debug_export  = isset( $debug_export ) && is_array( $debug_export ) ? $debug_export : array();
$export_state  = (string) ( $debug_export['state'] ?? '' );
$is_active     = in_array( $export_state, array( 'queued', 'running' ), true );
$started_at    = absint( $debug_export['started_at'] ?? 0 );
$running_for   = $started_at > 0 ? ( time() - $started_at ) : 0;
$is_stale      = $is_active && $running_for >= 10 * MINUTE_IN_SECONDS;
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Product furnizor importer Debug', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'debug' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<div class="schrack-panel">
		<h2><?php esc_html_e( 'Raw feed sample', 'schrack-woocommerce-sync' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Queues a background export directly from the selected feed without importing or caching anything, so a large sample never times out the request. Each row is captured as the raw feed columns alongside the technical_attributes the current mapping would extract from it, so category/filter attribute handling can be tuned against real data. There is no artificial row cap; the export only stops early if it gets close to the server memory or time limit, in which case it is flagged as stopped early.', 'schrack-woocommerce-sync' ); ?>
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
				<input type="number" id="schrack-debug-limit" name="debug_limit" value="10" min="1" step="1" class="small-text">
			</label>
			<button type="button" class="button" id="schrack-debug-limit-max"><?php esc_html_e( 'Full export (all rows)', 'schrack-woocommerce-sync' ); ?></button>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Queue export', 'schrack-woocommerce-sync' ); ?></button>
		</form>
	</div>

	<?php if ( ! empty( $debug_export ) && 'idle' !== $export_state ) : ?>
		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Export status', 'schrack-woocommerce-sync' ); ?></h2>
			<?php if ( $is_stale ) : ?>
				<p class="schrack-status-pill is-error">
					<?php
					printf(
						/* translators: %s: minutes running. */
						esc_html__( 'This export has been running for over %s minutes without finishing and has likely crashed (usually a memory or time limit on the server). Reset it and try a smaller row count, or check the PHP error log.', 'schrack-woocommerce-sync' ),
						esc_html( (string) floor( $running_for / MINUTE_IN_SECONDS ) )
					);
					?>
				</p>
			<?php endif; ?>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'State', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php
							$state_labels = array(
								'queued'  => __( 'Queued', 'schrack-woocommerce-sync' ),
								'running' => __( 'Running', 'schrack-woocommerce-sync' ),
								'done'    => __( 'Ready', 'schrack-woocommerce-sync' ),
								'error'   => __( 'Error', 'schrack-woocommerce-sync' ),
							);
							$state_classes = array(
								'queued'  => 'is-warning',
								'running' => 'is-warning',
								'done'    => 'is-ok',
								'error'   => 'is-error',
							);
							?>
							<span class="schrack-status-pill <?php echo esc_attr( $is_stale ? 'is-error' : ( $state_classes[ $export_state ] ?? 'is-warning' ) ); ?>">
								<?php echo esc_html( $state_labels[ $export_state ] ?? ucfirst( $export_state ) ); ?>
							</span>
							<?php if ( $is_active && $started_at > 0 ) : ?>
								<span class="schrack-sync-meta">
									<?php
									printf(
										/* translators: %s: relative time. */
										esc_html__( 'running for %s', 'schrack-woocommerce-sync' ),
										esc_html( human_time_diff( $started_at, time() ) )
									);
									?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Source', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( (string) ( $debug_export['source'] ?? '-' ) ); ?></td>
					</tr>
					<?php if ( 'done' === $export_state ) : ?>
						<tr>
							<th><?php esc_html_e( 'Rows', 'schrack-woocommerce-sync' ); ?></th>
							<td>
								<?php echo esc_html( (string) absint( $debug_export['rows'] ?? 0 ) ); ?>
								<?php if ( 'yes' === (string) ( $debug_export['capped_early'] ?? 'no' ) ) : ?>
									<span class="schrack-sync-meta"><?php esc_html_e( 'stopped early to stay within memory/time limits', 'schrack-woocommerce-sync' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'File', 'schrack-woocommerce-sync' ); ?></th>
							<td>
								<?php echo esc_html( (string) ( $debug_export['file_name'] ?? '' ) ); ?>
								(<?php echo esc_html( size_format( (int) ( $debug_export['bytes'] ?? 0 ) ) ); ?>)
							</td>
						</tr>
						<tr>
							<th></th>
							<td>
								<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=schrack_wc_sync_debug_download' ), 'schrack_wc_sync_debug_download' ) ); ?>">
									<?php esc_html_e( 'Download export', 'schrack-woocommerce-sync' ); ?>
								</a>
							</td>
						</tr>
					<?php elseif ( 'error' === $export_state ) : ?>
						<tr>
							<th><?php esc_html_e( 'Message', 'schrack-woocommerce-sync' ); ?></th>
							<td><?php echo esc_html( (string) ( $debug_export['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="schrack_wc_sync_debug_reset">
				<?php wp_nonce_field( 'schrack_wc_sync_debug_reset' ); ?>
				<button type="submit" class="button<?php echo $is_stale ? ' button-primary' : ''; ?>"><?php esc_html_e( 'Reset export status', 'schrack-woocommerce-sync' ); ?></button>
			</form>
		</div>
	<?php endif; ?>
</div>
<script>
( function () {
	var limitInput = document.getElementById( 'schrack-debug-limit' );
	var maxButton   = document.getElementById( 'schrack-debug-limit-max' );

	if ( maxButton && limitInput ) {
		maxButton.addEventListener( 'click', function () {
			// A very large sentinel, not a real cap: the export itself only
			// stops early if it nears the server's memory/time limit.
			limitInput.value = 100000000;
		} );
	}

	<?php if ( $is_active && ! $is_stale ) : ?>
	window.setTimeout( function () {
		window.location.reload();
	}, 5000 );
	<?php endif; ?>
} )();
</script>
