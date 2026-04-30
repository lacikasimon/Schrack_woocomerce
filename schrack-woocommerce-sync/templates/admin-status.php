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
		<h2><?php esc_html_e( 'Last Runs', 'schrack-woocommerce-sync' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Operation', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Last run', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Processed', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Errors', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Catalog cursor', 'schrack-woocommerce-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array( 'catalog', 'price', 'stock' ) as $operation ) : ?>
					<?php $row = isset( $status[ $operation ] ) && is_array( $status[ $operation ] ) ? $status[ $operation ] : array(); ?>
					<tr>
						<td><?php echo esc_html( ucfirst( $operation ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['last_run'] ?? '-' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['processed'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['errors'] ?? 0 ) ); ?></td>
						<td>
							<?php
							if ( 'catalog' === $operation && ! empty( $row['total_items'] ) ) {
								printf(
									'%s / %s',
									esc_html( (string) ( $row['cursor'] ?? 0 ) ),
									esc_html( (string) $row['total_items'] )
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
