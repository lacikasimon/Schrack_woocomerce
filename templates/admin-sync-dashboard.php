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
$settings       = isset( $settings ) && is_array( $settings ) ? $settings : array();
$image_import_enabled = 'yes' === (string) ( $settings['image_import_enabled'] ?? 'yes' );
$source_dashboards = isset( $sync_dashboard['sources'] ) && is_array( $sync_dashboard['sources'] ) ? $sync_dashboard['sources'] : array();

if ( empty( $source_dashboards ) ) {
	$source_dashboards = array(
		'schrack' => array_merge(
			$sync_dashboard,
			array(
				'source' => 'schrack',
				'label'  => __( 'Schrack', 'schrack-woocommerce-sync' ),
			)
		),
	);
}

$source_cards = static function ( array $dashboard, string $source_label ) use ( $image_import_enabled ): array {
	$imported_total = absint( $dashboard['imported_products'] ?? 0 );

	return array(
		array(
			'class' => 'is-primary',
			'label' => __( 'Imported products', 'schrack-woocommerce-sync' ),
			'value' => $imported_total,
			'meta'  => sprintf(
				/* translators: %s: catalog source label. */
				__( '%s products created in WooCommerce', 'schrack-woocommerce-sync' ),
				$source_label
			),
			'pct'   => $imported_total > 0 ? 100 : 0,
		),
		array(
			'class' => 'is-ok',
			'label' => __( 'Images synchronized', 'schrack-woocommerce-sync' ),
			'value' => absint( $dashboard['image_synced_products'] ?? 0 ),
			'meta'  => sprintf(
				/* translators: 1: percentage, 2: products with image URL. */
				__( '%1$s%% of imported products. URLs stored: %2$s', 'schrack-woocommerce-sync' ),
				(string) ( $dashboard['image_synced_pct'] ?? 0 ),
				number_format_i18n( absint( $dashboard['image_url_products'] ?? 0 ) )
			),
			'pct'   => (float) ( $dashboard['image_synced_pct'] ?? 0 ),
		),
		array(
			'class' => 'is-warning',
			'label' => $image_import_enabled ? __( 'Image URL only', 'schrack-woocommerce-sync' ) : __( 'External image URLs', 'schrack-woocommerce-sync' ),
			'value' => absint( $dashboard['image_url_only_products'] ?? 0 ),
			'meta'  => $image_import_enabled
				? sprintf(
					/* translators: 1: percentage, 2: products without image URL. */
					__( '%1$s%% waiting for media import. Missing URL: %2$s', 'schrack-woocommerce-sync' ),
					(string) ( $dashboard['image_url_only_pct'] ?? 0 ),
					number_format_i18n( absint( $dashboard['image_missing_url_products'] ?? 0 ) )
				)
				: sprintf(
					/* translators: 1: percentage, 2: products without image URL. */
					__( '%1$s%% using stored external URLs. Missing URL: %2$s', 'schrack-woocommerce-sync' ),
					(string) ( $dashboard['image_url_only_pct'] ?? 0 ),
					number_format_i18n( absint( $dashboard['image_missing_url_products'] ?? 0 ) )
				),
			'pct'   => (float) ( $dashboard['image_url_only_pct'] ?? 0 ),
		),
		array(
			'class' => 'is-info',
			'label' => __( 'Prices synchronized', 'schrack-woocommerce-sync' ),
			'value' => absint( $dashboard['price_synced_products'] ?? 0 ),
			'meta'  => sprintf(
				/* translators: %s: percentage. */
				__( '%s%% of imported products have a price sync timestamp', 'schrack-woocommerce-sync' ),
				(string) ( $dashboard['price_synced_pct'] ?? 0 )
			),
			'pct'   => (float) ( $dashboard['price_synced_pct'] ?? 0 ),
		),
		array(
			'class' => 'is-stock',
			'label' => __( 'Stock synchronized', 'schrack-woocommerce-sync' ),
			'value' => absint( $dashboard['stock_synced_products'] ?? 0 ),
			'meta'  => sprintf(
				/* translators: %s: percentage. */
				__( '%s%% of imported products have a stock sync timestamp', 'schrack-woocommerce-sync' ),
				(string) ( $dashboard['stock_synced_pct'] ?? 0 )
			),
			'pct'   => (float) ( $dashboard['stock_synced_pct'] ?? 0 ),
		),
	);
};
?>
<section class="schrack-dashboard" aria-label="<?php esc_attr_e( 'Sync Dashboard', 'schrack-woocommerce-sync' ); ?>">
	<div class="schrack-dashboard__header">
		<div>
			<h2><?php esc_html_e( 'Sync Dashboard', 'schrack-woocommerce-sync' ); ?></h2>
			<p><?php esc_html_e( 'Current coverage split by catalog source.', 'schrack-woocommerce-sync' ); ?></p>
		</div>
		<span><?php echo esc_html( (string) ( $sync_dashboard['calculated_at'] ?? '' ) ); ?></span>
	</div>

	<?php if ( '' !== (string) ( $sync_dashboard['query_error'] ?? '' ) ) : ?>
		<p class="schrack-dashboard__error"><?php echo esc_html( (string) $sync_dashboard['query_error'] ); ?></p>
	<?php endif; ?>

	<?php foreach ( $source_dashboards as $source_key => $source_dashboard ) : ?>
		<?php
		$source_dashboard = is_array( $source_dashboard ) ? $source_dashboard : array();
		$source_label     = (string) ( $source_dashboard['label'] ?? '' );
		$source_label     = '' !== $source_label ? $source_label : ucfirst( (string) $source_key );
		$cards            = $source_cards( $source_dashboard, $source_label );
		?>
		<div class="schrack-dashboard__source">
			<div class="schrack-dashboard__source-header">
				<h3><?php echo esc_html( $source_label ); ?></h3>
				<span>
					<?php
					printf(
						/* translators: %s: imported product count. */
						esc_html__( '%s imported products', 'schrack-woocommerce-sync' ),
						esc_html( number_format_i18n( absint( $source_dashboard['imported_products'] ?? 0 ) ) )
					);
					?>
				</span>
			</div>

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
		</div>
	<?php endforeach; ?>
</section>
