<?php
/**
 * Product edit screen sidebar box: which supplier this product came from.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fields = isset( $fields ) && is_array( $fields ) ? $fields : array();
?>
<div class="schrack-supplier-box">
	<ul class="schrack-supplier-box__list">
		<?php foreach ( $fields as $field ) : ?>
			<li>
				<strong><?php echo esc_html( $field['label'] ); ?>:</strong>
				<?php echo esc_html( $field['value'] ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
