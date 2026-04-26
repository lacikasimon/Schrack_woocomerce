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

	<div class="schrack-grid">
		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Environment', 'schrack-woocommerce-sync' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr><th><?php esc_html_e( 'PHP version', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
					<tr><th><?php esc_html_e( 'WooCommerce', 'schrack-woocommerce-sync' ); ?></th><td><?php echo class_exists( 'WooCommerce' ) ? esc_html__( 'Active', 'schrack-woocommerce-sync' ) : esc_html__( 'Missing', 'schrack-woocommerce-sync' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'SOAP extension', 'schrack-woocommerce-sync' ); ?></th><td><?php echo extension_loaded( 'soap' ) ? esc_html__( 'Available', 'schrack-woocommerce-sync' ) : esc_html__( 'Missing', 'schrack-woocommerce-sync' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Action Scheduler', 'schrack-woocommerce-sync' ); ?></th><td><?php echo function_exists( 'as_enqueue_async_action' ) ? esc_html__( 'Available', 'schrack-woocommerce-sync' ) : esc_html__( 'WP-Cron fallback', 'schrack-woocommerce-sync' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Mode', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( strtoupper( (string) $settings['environment'] ) ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Credentials', 'schrack-woocommerce-sync' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr><th><?php esc_html_e( 'Customer number', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( $this->configured_label( (string) $settings['customer_number'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Webshop username', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( $this->configured_label( (string) $settings['webshop_username'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Webshop password', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( $this->configured_label( (string) $settings['webshop_password'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Provider code', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( $this->configured_label( (string) $settings['provider_code'] ) ); ?></td></tr>
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
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
