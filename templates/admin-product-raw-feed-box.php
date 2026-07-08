<?php
/**
 * Product edit screen box: raw, unfiltered supplier feed data.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$raw_data = isset( $raw_data ) && is_array( $raw_data ) ? $raw_data : array();
?>
<div class="schrack-raw-feed-box">
	<?php if ( empty( $raw_data ) ) : ?>
		<p>
			<?php esc_html_e( 'Nincs elérhető nyers feed adat ehhez a termékhez (még nem szinkronizálták a beszállítói feedből).', 'schrack-woocommerce-sync' ); ?>
		</p>
	<?php else : ?>
		<div style="max-height: 480px; overflow-y: auto;">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mező', 'schrack-woocommerce-sync' ); ?></th>
						<th><?php esc_html_e( 'Érték', 'schrack-woocommerce-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $raw_data as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $entry['label'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['value'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>
