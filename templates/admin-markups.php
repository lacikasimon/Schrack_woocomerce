<?php
/**
 * Category markups template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack Category Markups', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'markups' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="schrack_wc_sync_save_markups">
		<?php wp_nonce_field( 'schrack_wc_sync_markups' ); ?>

		<table class="widefat striped schrack-markups-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'WooCommerce category', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Markup %', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Minimum margin', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Rounding', 'schrack-woocommerce-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $terms ) ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( 'No WooCommerce product categories found.', 'schrack-woocommerce-sync' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $terms as $term ) : ?>
						<?php
						$rule      = isset( $rules[ $term->term_id ] ) ? wp_parse_args( $rules[ $term->term_id ], array( 'markup' => '', 'min_margin' => '', 'rounding' => 'none' ) ) : array( 'markup' => '', 'min_margin' => '', 'rounding' => 'none' );
						$depth     = count( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
						$term_name = str_repeat( '- ', $depth ) . $term->name;
						?>
						<tr>
							<td><strong><?php echo esc_html( $term_name ); ?></strong></td>
							<td><input type="number" step="0.01" min="0" max="500" name="schrack_markups[<?php echo esc_attr( $term->term_id ); ?>][markup]" value="<?php echo esc_attr( $rule['markup'] ); ?>"></td>
							<td><input type="number" step="0.01" min="0" name="schrack_markups[<?php echo esc_attr( $term->term_id ); ?>][min_margin]" value="<?php echo esc_attr( $rule['min_margin'] ); ?>"></td>
							<td>
								<select name="schrack_markups[<?php echo esc_attr( $term->term_id ); ?>][rounding]">
									<option value="none" <?php selected( $rule['rounding'], 'none' ); ?>><?php esc_html_e( 'None', 'schrack-woocommerce-sync' ); ?></option>
									<option value="ending_99" <?php selected( $rule['rounding'], 'ending_99' ); ?>><?php esc_html_e( 'Round to .99', 'schrack-woocommerce-sync' ); ?></option>
									<option value="integer_ron" <?php selected( $rule['rounding'], 'integer_ron' ); ?>><?php esc_html_e( 'Round to whole RON', 'schrack-woocommerce-sync' ); ?></option>
									<option value="five_ron" <?php selected( $rule['rounding'], 'five_ron' ); ?>><?php esc_html_e( 'Round to 5 RON', 'schrack-woocommerce-sync' ); ?></option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php submit_button( __( 'Save markups', 'schrack-woocommerce-sync' ) ); ?>
	</form>
</div>
