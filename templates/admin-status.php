<?php
/**
 * Status template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack Status', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'status' ); ?>
	<?php $this->render_notice( $notice ); ?>
	<?php
	$woocommerce_active          = class_exists( 'WooCommerce' );
	$soap_available              = extension_loaded( 'soap' );
	$action_scheduler_available  = function_exists( 'as_enqueue_async_action' );
	$queue_status                = isset( $queue_status ) && is_array( $queue_status ) ? $queue_status : array();
	$stop_request                = isset( $stop_request ) && is_array( $stop_request ) ? $stop_request : null;
	$queue_state_labels          = array(
		'idle'    => __( 'Idle', 'schrack-woocommerce-sync' ),
		'queued'  => __( 'Queued', 'schrack-woocommerce-sync' ),
		'due'     => __( 'Due now', 'schrack-woocommerce-sync' ),
		'running' => __( 'Running', 'schrack-woocommerce-sync' ),
		'scheduled' => __( 'Scheduled', 'schrack-woocommerce-sync' ),
	);
	$queue_state_classes         = array(
		'idle'    => 'is-ok',
		'queued'  => 'is-warning',
		'due'     => 'is-warning',
		'running' => 'is-error',
		'scheduled' => 'is-ok',
	);
	$credential_fields           = array(
		'customer_number'  => __( 'Customer number', 'schrack-woocommerce-sync' ),
		'webshop_username' => __( 'Webshop username', 'schrack-woocommerce-sync' ),
		'webshop_password' => __( 'Webshop password', 'schrack-woocommerce-sync' ),
		'provider_code'    => __( 'Provider code', 'schrack-woocommerce-sync' ),
	);
	?>

	<div class="schrack-grid">
		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Environment', 'schrack-woocommerce-sync' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'PHP version', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( PHP_VERSION ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WooCommerce', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<span class="schrack-status-pill <?php echo $woocommerce_active ? 'is-ok' : 'is-error'; ?>">
								<?php echo $woocommerce_active ? esc_html__( 'Active', 'schrack-woocommerce-sync' ) : esc_html__( 'Missing', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'SOAP extension', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<span class="schrack-status-pill <?php echo $soap_available ? 'is-ok' : 'is-error'; ?>">
								<?php echo $soap_available ? esc_html__( 'Available', 'schrack-woocommerce-sync' ) : esc_html__( 'Missing', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Action Scheduler', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<span class="schrack-status-pill <?php echo $action_scheduler_available ? 'is-ok' : 'is-warning'; ?>">
								<?php echo $action_scheduler_available ? esc_html__( 'Available', 'schrack-woocommerce-sync' ) : esc_html__( 'WP-Cron fallback', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Mode', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( strtoupper( (string) ( $settings['environment'] ?? '' ) ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Credentials', 'schrack-woocommerce-sync' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( $credential_fields as $key => $label ) : ?>
						<?php $configured = '' !== (string) ( $settings[ $key ] ?? '' ); ?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td>
								<span class="schrack-status-pill <?php echo $configured ? 'is-ok' : 'is-error'; ?>">
									<?php echo esc_html( $this->configured_label( (string) ( $settings[ $key ] ?? '' ) ) ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="schrack-panel">
		<div class="schrack-panel-header">
			<h2><?php esc_html_e( 'Active Queue', 'schrack-woocommerce-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="schrack_wc_sync_stop_syncs">
				<input type="hidden" name="redirect_page" value="schrack-sync-status">
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
					<th><?php esc_html_e( 'Next run', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Hook', 'schrack-woocommerce-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $queue_status as $row ) : ?>
					<?php
					$state      = (string) ( $row['state'] ?? 'idle' );
					$next_run   = absint( $row['next_run'] ?? 0 );
					$state_text = (string) ( $queue_state_labels[ $state ] ?? ucfirst( $state ) );
					$state_class = (string) ( $queue_state_classes[ $state ] ?? 'is-warning' );
					?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></td>
						<td><span class="schrack-status-pill <?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_text ); ?></span></td>
						<td><?php echo esc_html( (string) absint( $row['pending'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) absint( $row['running'] ?? 0 ) ); ?></td>
						<td><?php echo $next_run > 0 ? esc_html( wp_date( 'Y-m-d H:i:s', $next_run ) ) : esc_html__( '-', 'schrack-woocommerce-sync' ); ?></td>
						<td><code><?php echo esc_html( (string) ( $row['hook'] ?? '' ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="schrack-panel">
		<h2><?php esc_html_e( 'Last Runs', 'schrack-woocommerce-sync' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Operation', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Last run', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Processed', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Batches', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Errors', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Details', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Batch cursor', 'schrack-woocommerce-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array( 'catalog', 'price', 'stock', 'images' ) as $operation ) : ?>
					<?php
					$row     = isset( $status[ $operation ] ) && is_array( $status[ $operation ] ) ? $status[ $operation ] : array();
					$details = array();

					if ( 'catalog' === $operation && isset( $row['image_urls_stored'] ) ) {
						$details[] = sprintf(
							/* translators: %d: image URL count. */
							__( 'Image URLs: %d', 'schrack-woocommerce-sync' ),
							absint( $row['image_urls_stored'] )
						);
					}

					if ( 'images' === $operation ) {
						foreach ( array( 'queued_products', 'workers_queued', 'imported', 'attached', 'skipped' ) as $detail_key ) {
							if ( isset( $row[ $detail_key ] ) ) {
								$details[] = ucwords( str_replace( '_', ' ', $detail_key ) ) . ': ' . absint( $row[ $detail_key ] );
							}
						}
					}
					?>
					<tr>
						<td><?php echo esc_html( ucfirst( $operation ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['last_run'] ?? '-' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['processed'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['batches_processed'] ?? '-' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['errors'] ?? 0 ) ); ?></td>
						<td><?php echo ! empty( $details ) ? esc_html( implode( ', ', $details ) ) : esc_html__( '-', 'schrack-woocommerce-sync' ); ?></td>
						<td>
							<?php
							$total = $row['total_items'] ?? ( $row['total_products'] ?? 0 );

							if ( ! empty( $total ) ) {
								printf(
									'%s / %s',
									esc_html( (string) ( $row['cursor'] ?? 0 ) ),
									esc_html( (string) $total )
								);
							} else {
								echo esc_html( '-' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
