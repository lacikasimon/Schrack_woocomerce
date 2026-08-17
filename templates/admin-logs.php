<?php
/**
 * Logs template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$log_level_labels = array(
	'debug'   => __( 'Debug', 'schrack-woocommerce-sync' ),
	'info'    => __( 'Info', 'schrack-woocommerce-sync' ),
	'warning' => __( 'Warning', 'schrack-woocommerce-sync' ),
	'error'   => __( 'Error', 'schrack-woocommerce-sync' ),
);
$log_operation_labels = array(
	'catalog'            => __( 'Catalog', 'schrack-woocommerce-sync' ),
	'telesystem'         => __( 'Telesystem', 'schrack-woocommerce-sync' ),
	'telesystem_catalog' => __( 'Telesystem catalog queue', 'schrack-woocommerce-sync' ),
	'price'              => __( 'Price', 'schrack-woocommerce-sync' ),
	'stock'              => __( 'Stock', 'schrack-woocommerce-sync' ),
	'images'             => __( 'Images', 'schrack-woocommerce-sync' ),
	'export'             => __( 'Product export', 'schrack-woocommerce-sync' ),
	'import'             => __( 'Product import', 'schrack-woocommerce-sync' ),
	'category_import'    => __( 'Category CSV import', 'schrack-woocommerce-sync' ),
	'soap'               => __( 'SOAP', 'schrack-woocommerce-sync' ),
	'debug'              => __( 'Debug', 'schrack-woocommerce-sync' ),
	'admin'              => __( 'Admin', 'schrack-woocommerce-sync' ),
);
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Product furnizor importer Logs', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'logs' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<div class="schrack-toolbar">
		<form method="get" class="schrack-inline-actions">
			<input type="hidden" name="page" value="schrack-sync-logs">
			<select name="level">
				<option value=""><?php esc_html_e( 'All levels', 'schrack-woocommerce-sync' ); ?></option>
				<option value="debug" <?php selected( $args['level'], 'debug' ); ?>><?php esc_html_e( 'Debug', 'schrack-woocommerce-sync' ); ?></option>
				<option value="info" <?php selected( $args['level'], 'info' ); ?>><?php esc_html_e( 'Info', 'schrack-woocommerce-sync' ); ?></option>
				<option value="warning" <?php selected( $args['level'], 'warning' ); ?>><?php esc_html_e( 'Warning', 'schrack-woocommerce-sync' ); ?></option>
				<option value="error" <?php selected( $args['level'], 'error' ); ?>><?php esc_html_e( 'Error', 'schrack-woocommerce-sync' ); ?></option>
			</select>
			<select name="operation">
				<option value=""><?php esc_html_e( 'All operations', 'schrack-woocommerce-sync' ); ?></option>
				<option value="catalog" <?php selected( $args['operation'], 'catalog' ); ?>><?php esc_html_e( 'Catalog', 'schrack-woocommerce-sync' ); ?></option>
				<option value="telesystem" <?php selected( $args['operation'], 'telesystem' ); ?>><?php esc_html_e( 'Telesystem', 'schrack-woocommerce-sync' ); ?></option>
				<option value="telesystem_catalog" <?php selected( $args['operation'], 'telesystem_catalog' ); ?>><?php esc_html_e( 'Telesystem catalog queue', 'schrack-woocommerce-sync' ); ?></option>
				<option value="price" <?php selected( $args['operation'], 'price' ); ?>><?php esc_html_e( 'Price', 'schrack-woocommerce-sync' ); ?></option>
				<option value="stock" <?php selected( $args['operation'], 'stock' ); ?>><?php esc_html_e( 'Stock', 'schrack-woocommerce-sync' ); ?></option>
				<option value="images" <?php selected( $args['operation'], 'images' ); ?>><?php esc_html_e( 'Images', 'schrack-woocommerce-sync' ); ?></option>
				<option value="export" <?php selected( $args['operation'], 'export' ); ?>><?php esc_html_e( 'Product export', 'schrack-woocommerce-sync' ); ?></option>
				<option value="import" <?php selected( $args['operation'], 'import' ); ?>><?php esc_html_e( 'Product import', 'schrack-woocommerce-sync' ); ?></option>
				<option value="soap" <?php selected( $args['operation'], 'soap' ); ?>><?php esc_html_e( 'SOAP', 'schrack-woocommerce-sync' ); ?></option>
				<option value="admin" <?php selected( $args['operation'], 'admin' ); ?>><?php esc_html_e( 'Admin', 'schrack-woocommerce-sync' ); ?></option>
			</select>
			<input type="text" name="sku" value="<?php echo esc_attr( $args['sku'] ); ?>" placeholder="<?php esc_attr_e( 'SKU', 'schrack-woocommerce-sync' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'schrack-woocommerce-sync' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_clear_logs">
			<?php wp_nonce_field( 'schrack_wc_sync_clear_logs' ); ?>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Clear logs', 'schrack-woocommerce-sync' ); ?></button>
		</form>
	</div>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time', 'schrack-woocommerce-sync' ); ?></th>
				<th><?php esc_html_e( 'Level', 'schrack-woocommerce-sync' ); ?></th>
				<th><?php esc_html_e( 'Operation', 'schrack-woocommerce-sync' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'schrack-woocommerce-sync' ); ?></th>
				<th><?php esc_html_e( 'Message', 'schrack-woocommerce-sync' ); ?></th>
				<th><?php esc_html_e( 'Context', 'schrack-woocommerce-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No log entries found.', 'schrack-woocommerce-sync' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log->created_at ); ?></td>
						<td><span class="schrack-log-level schrack-log-level-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( $log_level_labels[ $log->level ] ?? $log->level ); ?></span></td>
						<td><?php echo esc_html( $log_operation_labels[ $log->operation ] ?? $log->operation ); ?></td>
						<td><?php echo esc_html( (string) $log->sku ); ?></td>
						<td><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $log->message ) ); ?></td>
						<td><code><?php echo esc_html( (string) $log->context ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
