<?php
/**
 * Category markups template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$default_rule = array( 'markup' => '', 'min_margin' => '', 'rounding' => 'none' );
$rule_for     = static function ( int $term_id ) use ( $rules, $default_rule ): array {
	return isset( $rules[ $term_id ] ) ? wp_parse_args( $rules[ $term_id ], $default_rule ) : $default_rule;
};
$is_configured_rule = static function ( array $rule ): bool {
	return '' !== (string) $rule['markup'] || '' !== (string) $rule['min_margin'] || 'none' !== (string) $rule['rounding'];
};

$terms_by_id     = array();
$terms_by_parent = array();

foreach ( $terms as $term ) {
	if ( ! $term instanceof WP_Term ) {
		continue;
	}

	$term_id                       = (int) $term->term_id;
	$parent_id                     = absint( $term->parent );
	$terms_by_id[ $term_id ]       = $term;
	$terms_by_parent[ $parent_id ] = $terms_by_parent[ $parent_id ] ?? array();
	$terms_by_parent[ $parent_id ][] = $term;
}

foreach ( $terms_by_parent as $parent_id => $children ) {
	usort(
		$children,
		static function ( WP_Term $left, WP_Term $right ): int {
			return strnatcasecmp( $left->name, $right->name );
		}
	);
	$terms_by_parent[ $parent_id ] = $children;
}

$term_depths   = array();
$term_paths    = array();
$ordered_terms = array();
$append_terms  = static function ( int $parent_id, int $depth, string $parent_path ) use ( &$append_terms, &$ordered_terms, &$term_depths, &$term_paths, $terms_by_parent ): void {
	foreach ( $terms_by_parent[ $parent_id ] ?? array() as $term ) {
		$term_id                  = (int) $term->term_id;
		$path                     = '' === $parent_path ? $term->name : $parent_path . ' > ' . $term->name;
		$term_depths[ $term_id ]  = $depth;
		$term_paths[ $term_id ]   = $path;
		$ordered_terms[]          = $term;

		$append_terms( $term_id, $depth + 1, $path );
	}
};

$append_terms( 0, 0, '' );

foreach ( $terms_by_id as $term_id => $term ) {
	if ( isset( $term_paths[ $term_id ] ) ) {
		continue;
	}

	$term_depths[ $term_id ] = 0;
	$term_paths[ $term_id ]  = $term->name;
	$ordered_terms[]         = $term;
	$append_terms( $term_id, 1, $term->name );
}

$render_bulk_category_nodes = static function ( int $parent_id ) use ( &$render_bulk_category_nodes, $terms_by_parent, $term_depths, $term_paths, $rule_for, $is_configured_rule ): void {
	if ( empty( $terms_by_parent[ $parent_id ] ) ) {
		return;
	}
	?>
	<ul class="schrack-bulk-tree__list" role="<?php echo esc_attr( 0 === $parent_id ? 'tree' : 'group' ); ?>">
		<?php foreach ( $terms_by_parent[ $parent_id ] as $term ) : ?>
			<?php
			$term_id       = (int) $term->term_id;
			$depth         = absint( $term_depths[ $term_id ] ?? 0 );
			$path          = (string) ( $term_paths[ $term_id ] ?? $term->name );
			$rule          = $rule_for( $term_id );
			$is_configured = $is_configured_rule( $rule );
			$checkbox_id   = 'schrack_bulk_category_' . $term_id;
			?>
			<li
				class="schrack-bulk-tree__node <?php echo $is_configured ? 'is-configured' : 'is-empty'; ?>"
				data-bulk-category-node
				data-term-id="<?php echo esc_attr( (string) $term_id ); ?>"
				data-bulk-category-search-text="<?php echo esc_attr( $path . ' ' . $term->slug ); ?>"
				role="treeitem"
			>
				<label class="schrack-bulk-tree__label" for="<?php echo esc_attr( $checkbox_id ); ?>" style="<?php echo esc_attr( '--schrack-category-depth:' . $depth ); ?>">
					<input
						id="<?php echo esc_attr( $checkbox_id ); ?>"
						type="checkbox"
						name="schrack_bulk[category_ids][]"
						value="<?php echo esc_attr( (string) $term_id ); ?>"
						data-bulk-category-checkbox
					>
					<span class="schrack-bulk-tree__name"><?php echo esc_html( $term->name ); ?></span>
					<span class="schrack-bulk-tree__meta"><?php echo esc_html( $path ); ?></span>
					<span class="schrack-bulk-tree__status"><?php echo esc_html( $is_configured ? __( 'Configured', 'schrack-woocommerce-sync' ) : __( 'Empty', 'schrack-woocommerce-sync' ) ); ?></span>
				</label>
				<?php $render_bulk_category_nodes( $term_id ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
};
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack Category Markups', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'markups' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="schrack_wc_sync_save_markups">
		<?php wp_nonce_field( 'schrack_wc_sync_markups' ); ?>

		<section class="schrack-markups-bulk" data-markups-bulk>
			<div class="schrack-markups-bulk__header">
				<h2><?php esc_html_e( 'Bulk category markup', 'schrack-woocommerce-sync' ); ?></h2>
				<p><?php esc_html_e( 'Select categories from the tree, choose the bulk values, then apply them to the rows below or apply and save immediately.', 'schrack-woocommerce-sync' ); ?></p>
			</div>

			<div class="schrack-markups-bulk__grid">
				<div class="schrack-markups-bulk__values">
					<label class="schrack-markups-bulk__field" for="schrack_bulk_markup">
						<span><?php esc_html_e( 'Markup %', 'schrack-woocommerce-sync' ); ?></span>
						<input id="schrack_bulk_markup" type="number" step="0.01" min="0" max="500" name="schrack_bulk[markup]" data-bulk-markup>
					</label>

					<label class="schrack-markups-bulk__field" for="schrack_bulk_rounding">
						<span><?php esc_html_e( 'Rounding', 'schrack-woocommerce-sync' ); ?></span>
						<select id="schrack_bulk_rounding" name="schrack_bulk[rounding]" data-bulk-rounding>
							<option value=""><?php esc_html_e( 'Leave rounding unchanged', 'schrack-woocommerce-sync' ); ?></option>
							<option value="none"><?php esc_html_e( 'None', 'schrack-woocommerce-sync' ); ?></option>
							<option value="ending_99"><?php esc_html_e( 'Round to .99', 'schrack-woocommerce-sync' ); ?></option>
							<option value="integer_ron"><?php esc_html_e( 'Round to whole RON', 'schrack-woocommerce-sync' ); ?></option>
							<option value="five_ron"><?php esc_html_e( 'Round to 5 RON', 'schrack-woocommerce-sync' ); ?></option>
						</select>
					</label>

					<fieldset class="schrack-markups-bulk__mode">
						<legend><?php esc_html_e( 'Bulk mode', 'schrack-woocommerce-sync' ); ?></legend>
						<label>
							<input type="radio" name="schrack_bulk[mode]" value="empty" checked data-bulk-mode>
							<?php esc_html_e( 'Only categories with no configured markup or rounding', 'schrack-woocommerce-sync' ); ?>
						</label>
						<label>
							<input type="radio" name="schrack_bulk[mode]" value="overwrite" data-bulk-mode>
							<?php esc_html_e( 'Overwrite selected categories', 'schrack-woocommerce-sync' ); ?>
						</label>
					</fieldset>

					<div class="schrack-markups-bulk__apply">
						<button type="button" class="button button-secondary" data-bulk-apply><?php esc_html_e( 'Apply to selected rows', 'schrack-woocommerce-sync' ); ?></button>
						<button type="submit" class="button" name="schrack_bulk_submit" value="1"><?php esc_html_e( 'Apply selected and save', 'schrack-woocommerce-sync' ); ?></button>
					</div>
					<p class="description" data-bulk-result></p>
				</div>

				<div class="schrack-markups-bulk__picker">
					<label class="schrack-markups-bulk__field" for="schrack_bulk_category_search">
						<span><?php esc_html_e( 'Search category tree', 'schrack-woocommerce-sync' ); ?></span>
						<input id="schrack_bulk_category_search" type="search" placeholder="<?php esc_attr_e( 'Search by category name or path', 'schrack-woocommerce-sync' ); ?>" data-bulk-category-search>
					</label>

					<div class="schrack-markups-bulk__tree-actions">
						<button type="button" class="button" data-bulk-select-visible><?php esc_html_e( 'Select visible', 'schrack-woocommerce-sync' ); ?></button>
						<button type="button" class="button" data-bulk-select-all><?php esc_html_e( 'Select all categories', 'schrack-woocommerce-sync' ); ?></button>
						<button type="button" class="button" data-bulk-clear><?php esc_html_e( 'Clear selection', 'schrack-woocommerce-sync' ); ?></button>
						<span class="schrack-markups-bulk__count">
							<span data-bulk-selected-count>0</span>
							<?php esc_html_e( 'selected', 'schrack-woocommerce-sync' ); ?>
						</span>
					</div>

					<div class="schrack-bulk-tree" data-bulk-category-tree>
						<?php if ( empty( $ordered_terms ) ) : ?>
							<p class="schrack-bulk-tree__empty"><?php esc_html_e( 'No WooCommerce product categories found.', 'schrack-woocommerce-sync' ); ?></p>
						<?php else : ?>
							<?php $render_bulk_category_nodes( 0 ); ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</section>

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
					<?php foreach ( $ordered_terms as $term ) : ?>
						<?php
						$term_id   = (int) $term->term_id;
						$rule      = $rule_for( $term_id );
						$depth     = absint( $term_depths[ $term_id ] ?? 0 );
						$term_name = str_repeat( '- ', $depth ) . $term->name;
						?>
						<tr data-markup-row data-term-id="<?php echo esc_attr( (string) $term_id ); ?>">
							<td><strong title="<?php echo esc_attr( (string) ( $term_paths[ $term_id ] ?? $term->name ) ); ?>"><?php echo esc_html( $term_name ); ?></strong></td>
							<td><input type="number" step="0.01" min="0" max="500" name="schrack_markups[<?php echo esc_attr( (string) $term_id ); ?>][markup]" value="<?php echo esc_attr( $rule['markup'] ); ?>" data-markup-field></td>
							<td><input type="number" step="0.01" min="0" name="schrack_markups[<?php echo esc_attr( (string) $term_id ); ?>][min_margin]" value="<?php echo esc_attr( $rule['min_margin'] ); ?>" data-min-margin-field></td>
							<td>
								<select name="schrack_markups[<?php echo esc_attr( (string) $term_id ); ?>][rounding]" data-rounding-field>
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
