<?php
/**
 * Sync dashboard counters.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sync_dashboard = isset( $sync_dashboard ) && is_array( $sync_dashboard ) ? $sync_dashboard : array();
$imported_total = absint( $sync_dashboard['imported_products'] ?? 0 );
$cards          = array(
	array(
		'class' => 'is-primary',
		'label' => __( 'Imported products', 'schrack-woocommerce-sync' ),
		'value' => $imported_total,
		'meta'  => __( 'Schrack products created in WooCommerce', 'schrack-woocommerce-sync' ),
		'pct'   => $imported_total > 0 ? 100 : 0,
	),
	array(
		'class' => 'is-ok',
		'label' => __( 'Images synchronized', 'schrack-woocommerce-sync' ),
		'value' => absint( $sync_dashboard['image_synced_products'] ?? 0 ),
		'meta'  => sprintf(
			/* translators: 1: percentage, 2: products with image URL. */
			__( '%1$s%% of imported products. URLs stored: %2$s', 'schrack-woocommerce-sync' ),
			(string) ( $sync_dashboard['image_synced_pct'] ?? 0 ),
			number_format_i18n( absint( $sync_dashboard['image_url_products'] ?? 0 ) )
		),
		'pct'   => (float) ( $sync_dashboard['image_synced_pct'] ?? 0 ),
	),
	array(
		'class' => 'is-warning',
		'label' => __( 'Image URL only', 'schrack-woocommerce-sync' ),
		'value' => absint( $sync_dashboard['image_url_only_products'] ?? 0 ),
		'meta'  => sprintf(
			/* translators: 1: percentage, 2: products without image URL. */
			__( '%1$s%% waiting for media import. Missing URL: %2$s', 'schrack-woocommerce-sync' ),
			(string) ( $sync_dashboard['image_url_only_pct'] ?? 0 ),
			number_format_i18n( absint( $sync_dashboard['image_missing_url_products'] ?? 0 ) )
		),
		'pct'   => (float) ( $sync_dashboard['image_url_only_pct'] ?? 0 ),
	),
	array(
		'class' => 'is-info',
		'label' => __( 'Prices synchronized', 'schrack-woocommerce-sync' ),
		'value' => absint( $sync_dashboard['price_synced_products'] ?? 0 ),
		'meta'  => sprintf(
			/* translators: %s: percentage. */
			__( '%s%% of imported products have a price sync timestamp', 'schrack-woocommerce-sync' ),
			(string) ( $sync_dashboard['price_synced_pct'] ?? 0 )
		),
		'pct'   => (float) ( $sync_dashboard['price_synced_pct'] ?? 0 ),
	),
	array(
		'class' => 'is-stock',
		'label' => __( 'Stock synchronized', 'schrack-woocommerce-sync' ),
		'value' => absint( $sync_dashboard['stock_synced_products'] ?? 0 ),
		'meta'  => sprintf(
			/* translators: %s: percentage. */
			__( '%s%% of imported products have a stock sync timestamp', 'schrack-woocommerce-sync' ),
			(string) ( $sync_dashboard['stock_synced_pct'] ?? 0 )
		),
		'pct'   => (float) ( $sync_dashboard['stock_synced_pct'] ?? 0 ),
	),
);
?>
<section class="schrack-dashboard" aria-label="<?php esc_attr_e( 'Sync Dashboard', 'schrack-woocommerce-sync' ); ?>">
	<div class="schrack-dashboard__header">
		<div>
			<h2><?php esc_html_e( 'Sync Dashboard', 'schrack-woocommerce-sync' ); ?></h2>
			<p><?php esc_html_e( 'Current coverage across imported Schrack products.', 'schrack-woocommerce-sync' ); ?></p>
		</div>
		<span><?php echo esc_html( (string) ( $sync_dashboard['calculated_at'] ?? '' ) ); ?></span>
	</div>

	<?php if ( '' !== (string) ( $sync_dashboard['query_error'] ?? '' ) ) : ?>
		<p class="schrack-dashboard__error"><?php echo esc_html( (string) $sync_dashboard['query_error'] ); ?></p>
	<?php endif; ?>

	<div class="schrack-dashboard__grid">
		<?php foreach ( $cards as $card ) : ?>
			<?php $pct = max( 0, min( 100, (float) $card['pct'] ) ); ?>
			<div class="schrack-dashboard-card <?php echo esc_attr( (string) $card['class'] ); ?>">
				<span class="schrack-dashboard-card__label"><?php echo esc_html( (string) $card['label'] ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( absint( $card['value'] ) ) ); ?></strong>
				<span class="schrack-dashboard-card__meta"><?php echo esc_html( (string) $card['meta'] ); ?></span>
				<span class="schrack-dashboard-card__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) $pct ); ?>%;"></span></span>
			</div>
		<?php endforeach; ?>
	</div>
</section>
