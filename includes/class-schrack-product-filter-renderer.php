<?php
/**
 * Frontend product filter renderer.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Product_Filter_Renderer {
	public const AJAX_ACTION = 'schrack_wc_filter_products';
	public const NONCE_ACTION = 'schrack_wc_product_filter';

	/**
	 * Renders the full filter widget shell and the initial product results.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="schrack-product-filter"><p>' . esc_html__( 'WooCommerce is required for this product filter.', 'schrack-woocommerce-sync' ) . '</p></div>';
		}

		wp_enqueue_style( 'schrack-wc-product-filter' );
		wp_enqueue_script( 'schrack-wc-product-filter' );

		$settings    = $this->sanitize_settings( $settings );
		$instance_id = '' !== $instance_id ? sanitize_html_class( $instance_id ) : 'schrack-products-' . wp_rand( 1000, 999999 );
		$filters     = array(
			'category' => $settings['default_category'],
			'orderby'  => $settings['default_orderby'],
			'paged'    => 1,
		);
		$results     = $this->render_results( $settings, $filters );
		$config      = $this->public_settings( $settings );
		$categories  = $this->product_categories();
		$style       = $this->inline_style( $settings );

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="schrack-product-filter"
			style="<?php echo esc_attr( $style ); ?>"
			data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
		>
			<form class="schrack-product-filter__form" method="get">
				<div class="schrack-product-filter__controls">
					<?php if ( $settings['show_search'] ) : ?>
						<label class="schrack-product-filter__field">
							<span><?php esc_html_e( 'Search products', 'schrack-woocommerce-sync' ); ?></span>
							<input type="search" name="search" placeholder="<?php esc_attr_e( 'Name, SKU, item number', 'schrack-woocommerce-sync' ); ?>">
						</label>
					<?php endif; ?>

					<?php if ( $settings['show_category_filter'] ) : ?>
						<?php if ( $settings['show_category_search'] ) : ?>
							<label class="schrack-product-filter__field">
								<span><?php esc_html_e( 'Search categories', 'schrack-woocommerce-sync' ); ?></span>
								<input type="search" name="category_search" data-category-search placeholder="<?php esc_attr_e( 'Type a category name', 'schrack-woocommerce-sync' ); ?>">
							</label>
						<?php endif; ?>

						<label class="schrack-product-filter__field">
							<span><?php esc_html_e( 'Category', 'schrack-woocommerce-sync' ); ?></span>
							<select name="category" data-category-select>
								<option value=""><?php esc_html_e( 'All categories', 'schrack-woocommerce-sync' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option
										value="<?php echo esc_attr( (string) $category['id'] ); ?>"
										data-filter-label="<?php echo esc_attr( strtolower( remove_accents( $category['name'] ) ) ); ?>"
										<?php selected( $settings['default_category'], $category['id'] ); ?>
									>
										<?php echo esc_html( $category['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>

					<?php if ( $settings['show_price_filter'] ) : ?>
						<label class="schrack-product-filter__field schrack-product-filter__field--price">
							<span><?php esc_html_e( 'Min price', 'schrack-woocommerce-sync' ); ?></span>
							<input type="number" name="min_price" min="0" step="0.01" inputmode="decimal" placeholder="0">
						</label>
						<label class="schrack-product-filter__field schrack-product-filter__field--price">
							<span><?php esc_html_e( 'Max price', 'schrack-woocommerce-sync' ); ?></span>
							<input type="number" name="max_price" min="0" step="0.01" inputmode="decimal">
						</label>
					<?php endif; ?>

					<?php if ( $settings['show_sort'] ) : ?>
						<label class="schrack-product-filter__field">
							<span><?php esc_html_e( 'Sort by', 'schrack-woocommerce-sync' ); ?></span>
							<select name="orderby">
								<?php foreach ( $this->orderby_options() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['default_orderby'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
				</div>

				<div class="schrack-product-filter__actions">
					<button type="submit" class="schrack-product-filter__button">
						<?php echo esc_html( $settings['button_text'] ); ?>
					</button>
					<button type="button" class="schrack-product-filter__reset" data-filter-reset>
						<?php echo esc_html( $settings['reset_text'] ); ?>
					</button>
				</div>
			</form>

			<div class="schrack-product-filter__results" aria-live="polite">
				<?php echo $results['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders only the product result section.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @param array<string,mixed> $filters Frontend filters.
	 * @return array<string,mixed>
	 */
	public function render_results( array $settings, array $filters ): array {
		$settings = $this->sanitize_settings( $settings );
		$filters  = $this->sanitize_filters( $filters );
		$query    = $this->query_products( $settings, $filters );
		$posts    = $this->visible_posts( $query, $settings );
		$has_more = $this->has_more_results( $query, $settings, $filters );
		$summary  = $this->result_summary( $query, $settings, $filters, count( $posts ), $has_more );

		ob_start();
		?>
		<div class="schrack-product-filter__summary">
			<?php echo esc_html( $summary ); ?>
		</div>

		<?php if ( ! empty( $posts ) ) : ?>
			<div class="schrack-product-filter__grid" style="<?php echo esc_attr( '--schrack-filter-columns:' . $settings['columns'] ); ?>">
				<?php
				foreach ( $posts as $post ) :
					$product_id = $post instanceof WP_Post ? (int) $post->ID : absint( $post );
					$product    = wc_get_product( $product_id );

					if ( $product instanceof WC_Product ) {
						echo $this->product_card( $product, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				endforeach;
				?>
			</div>
			<?php echo $this->pagination( $query, $filters['paged'], $settings, $has_more ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php else : ?>
			<div class="schrack-product-filter__empty">
				<strong><?php echo esc_html( $this->empty_title( $settings, $filters ) ); ?></strong>
				<span><?php echo esc_html( $this->empty_message( $settings, $filters ) ); ?></span>
			</div>
		<?php endif; ?>
		<?php

		return array(
			'html'        => (string) ob_get_clean(),
			'summary'     => $summary,
			'page'        => $filters['paged'],
			'has_more'    => $has_more ? 'yes' : 'no',
			'total_pages' => $this->uses_fast_load_more( $settings ) ? null : max( 1, (int) $query->max_num_pages ),
			'total'       => $this->uses_fast_load_more( $settings ) ? null : (int) $query->found_posts,
		);
	}

	/**
	 * Returns safe public widget settings for AJAX requests.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function public_settings( array $settings ): array {
		$settings = $this->sanitize_settings( $settings );

		return array(
			'products_per_page'   => $settings['products_per_page'],
			'columns'             => $settings['columns'],
			'default_category'    => $settings['default_category'],
			'default_orderby'     => $settings['default_orderby'],
			'pagination_mode'     => $settings['pagination_mode'],
			'exact_totals'        => $settings['exact_totals'] ? 'yes' : 'no',
			'min_search_chars'    => $settings['min_search_chars'],
			'show_images'         => $settings['show_images'] ? 'yes' : 'no',
			'show_categories'     => $settings['show_categories'] ? 'yes' : 'no',
			'show_excerpt'        => $settings['show_excerpt'] ? 'yes' : 'no',
			'show_stock'          => $settings['show_stock'] ? 'yes' : 'no',
			'show_add_to_cart'    => $settings['show_add_to_cart'] ? 'yes' : 'no',
			'hide_out_of_stock'   => $settings['hide_out_of_stock'] ? 'yes' : 'no',
			'button_text'         => $settings['button_text'],
			'load_more_text'      => $settings['load_more_text'],
			'details_button_text' => $settings['details_button_text'],
		);
	}

	/**
	 * Filters the WP query join clause for product search and WooCommerce lookup values.
	 */
	public function query_join( string $join, WP_Query $query ): string {
		global $wpdb;

		if ( ! $this->uses_lookup_join( $query ) && ! $this->uses_item_number_join( $query ) ) {
			return $join;
		}

		if ( $this->uses_lookup_join( $query ) ) {
			$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
			$join        .= " LEFT JOIN {$lookup_table} AS schrack_filter_lookup ON ({$wpdb->posts}.ID = schrack_filter_lookup.product_id)";
		}

		if ( $this->uses_item_number_join( $query ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} AS schrack_filter_item_meta ON ({$wpdb->posts}.ID = schrack_filter_item_meta.post_id AND schrack_filter_item_meta.meta_key = '_schrack_item_number')";
		}

		return $join;
	}

	/**
	 * Filters the WP query where clause for product text/SKU search and catalog filters.
	 */
	public function query_where( string $where, WP_Query $query ): string {
		global $wpdb;

		$search = trim( (string) $query->get( 'schrack_product_filter_search' ) );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$where .= $wpdb->prepare(
				" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR schrack_filter_lookup.sku LIKE %s OR schrack_filter_item_meta.meta_value LIKE %s)",
				$like,
				$like,
				$like,
				$like,
				$like
			);
		}

		$min_price = $query->get( 'schrack_product_filter_min_price' );
		$max_price = $query->get( 'schrack_product_filter_max_price' );

		if ( is_numeric( $min_price ) ) {
			$where .= $wpdb->prepare( ' AND schrack_filter_lookup.max_price >= %f', (float) $min_price );
		}

		if ( is_numeric( $max_price ) ) {
			$where .= $wpdb->prepare( ' AND schrack_filter_lookup.min_price <= %f', (float) $max_price );
		}

		if ( $query->get( 'schrack_product_filter_hide_out_of_stock' ) ) {
			$where .= " AND schrack_filter_lookup.stock_status <> 'outofstock'";
		}

		return $where;
	}

	/**
	 * Sorts lookup-based product result sets efficiently.
	 */
	public function query_orderby( string $orderby, WP_Query $query ): string {
		global $wpdb;

		$filter_orderby = (string) $query->get( 'schrack_product_filter_orderby' );

		return match ( $filter_orderby ) {
			'price'      => 'schrack_filter_lookup.min_price ASC, ' . $wpdb->posts . '.post_title ASC',
			'price-desc' => 'schrack_filter_lookup.min_price DESC, ' . $wpdb->posts . '.post_title ASC',
			'popularity' => 'schrack_filter_lookup.total_sales DESC, ' . $wpdb->posts . '.post_title ASC',
			default      => $orderby,
		};
	}

	/**
	 * Keeps lookup and item-number joins from duplicating products.
	 */
	public function query_distinct( string $distinct, WP_Query $query ): string {
		if ( ! $this->uses_lookup_join( $query ) && ! $this->uses_item_number_join( $query ) ) {
			return $distinct;
		}

		return 'DISTINCT';
	}

	/**
	 * Returns whether the WooCommerce product lookup table is needed.
	 */
	private function uses_lookup_join( WP_Query $query ): bool {
		return '' !== (string) $query->get( 'schrack_product_filter_search' )
			|| is_numeric( $query->get( 'schrack_product_filter_min_price' ) )
			|| is_numeric( $query->get( 'schrack_product_filter_max_price' ) )
			|| (bool) $query->get( 'schrack_product_filter_hide_out_of_stock' )
			|| in_array( (string) $query->get( 'schrack_product_filter_orderby' ), array( 'price', 'price-desc', 'popularity' ), true );
	}

	/**
	 * Returns whether the Schrack item-number postmeta join is needed.
	 */
	private function uses_item_number_join( WP_Query $query ): bool {
		return '' !== (string) $query->get( 'schrack_product_filter_search' );
	}

	/**
	 * Queries WooCommerce products for the supplied filters.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function query_products( array $settings, array $filters ): WP_Query {
		$fast_load_more = $this->uses_fast_load_more( $settings );
		$search         = $this->search_is_too_short( $settings, $filters ) ? '' : $filters['search'];

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'ignore_sticky_posts'    => true,
			'posts_per_page'         => $settings['products_per_page'] + ( $fast_load_more ? 1 : 0 ),
			'paged'                  => $filters['paged'],
			'no_found_rows'          => $fast_load_more,
			'cache_results'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => $settings['show_categories'],
			'schrack_product_filter_search' => $search,
			'schrack_product_filter_min_price' => $filters['min_price'],
			'schrack_product_filter_max_price' => $filters['max_price'],
			'schrack_product_filter_hide_out_of_stock' => $settings['hide_out_of_stock'] || 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ),
			'schrack_product_filter_orderby' => $filters['orderby'],
		);

		$tax_query  = array( 'relation' => 'AND' );
		$meta_query = array( 'relation' => 'AND' );

		$category_ids = $this->category_filter_ids( $filters );

		if ( $this->search_is_too_short( $settings, $filters ) ) {
			$args['post__in'] = array( 0 );
		} elseif ( ! empty( $category_ids ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $category_ids,
				'operator' => 'IN',
			);
		} elseif ( '' !== $filters['category_search'] ) {
			$args['post__in'] = array( 0 );
		}

		$visibility_terms = function_exists( 'wc_get_product_visibility_term_ids' ) ? wc_get_product_visibility_term_ids() : array();

		if ( ! empty( $visibility_terms['exclude-from-catalog'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( (int) $visibility_terms['exclude-from-catalog'] ),
				'operator' => 'NOT IN',
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query;
		}

		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query;
		}

		$args = $this->apply_orderby( $args, $filters['orderby'] );

		add_filter( 'posts_join', array( $this, 'query_join' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'query_where' ), 10, 2 );
		add_filter( 'posts_orderby', array( $this, 'query_orderby' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'query_distinct' ), 10, 2 );

		$query = new WP_Query( $args );

		remove_filter( 'posts_join', array( $this, 'query_join' ), 10 );
		remove_filter( 'posts_where', array( $this, 'query_where' ), 10 );
		remove_filter( 'posts_orderby', array( $this, 'query_orderby' ), 10 );
		remove_filter( 'posts_distinct', array( $this, 'query_distinct' ), 10 );

		return $query;
	}

	/**
	 * Applies the selected product ordering.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return array<string,mixed>
	 */
	private function apply_orderby( array $args, string $orderby ): array {
		switch ( $orderby ) {
			case 'price':
				$args['orderby']  = 'none';
				$args['order']    = 'ASC';
				break;

			case 'price-desc':
				$args['orderby']  = 'none';
				$args['order']    = 'DESC';
				break;

			case 'date':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;

			case 'popularity':
				$args['orderby']  = 'none';
				$args['order']    = 'DESC';
				break;

			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;

			default:
				$args['orderby'] = array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				);
				break;
		}

		return $args;
	}

	/**
	 * Returns matching category IDs from selected category or category search.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<int,int>
	 */
	private function category_filter_ids( array $filters ): array {
		if ( $filters['category'] > 0 ) {
			return array( (int) $filters['category'] );
		}

		if ( '' === $filters['category_search'] || ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'name__like' => $filters['category_search'],
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'absint', $terms ) );
	}

	/**
	 * Renders a product card.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function product_card( WC_Product $product, array $settings ): string {
		$product_id = (int) $product->get_id();
		$permalink  = get_permalink( $product_id );
		$sku        = $product->get_sku();
		$image      = $settings['show_images'] ? $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) : '';
		$cart_class = 'schrack-product-card__cart button add_to_cart_button';

		if ( $product->supports( 'ajax_add_to_cart' ) ) {
			$cart_class .= ' ajax_add_to_cart';
		}

		ob_start();
		?>
		<article class="schrack-product-card">
			<?php if ( $settings['show_images'] ) : ?>
				<a class="schrack-product-card__image" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( $product->get_name() ); ?>">
					<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>

			<div class="schrack-product-card__body">
				<?php if ( '' !== $sku ) : ?>
					<div class="schrack-product-card__sku"><?php echo esc_html( $sku ); ?></div>
				<?php endif; ?>

				<h3 class="schrack-product-card__title">
					<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
				</h3>

				<?php if ( $settings['show_categories'] ) : ?>
					<div class="schrack-product-card__categories">
						<?php echo wp_kses_post( wc_get_product_category_list( $product_id, ', ' ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $settings['show_excerpt'] ) : ?>
					<div class="schrack-product-card__excerpt">
						<?php echo esc_html( wp_trim_words( $product->get_short_description() ?: $product->get_description(), 18 ) ); ?>
					</div>
				<?php endif; ?>

				<div class="schrack-product-card__meta">
					<div class="schrack-product-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
					<?php if ( $settings['show_stock'] ) : ?>
						<span class="schrack-product-card__stock <?php echo $product->is_in_stock() ? 'is-in-stock' : 'is-out-of-stock'; ?>">
							<?php echo $product->is_in_stock() ? esc_html__( 'In stock', 'schrack-woocommerce-sync' ) : esc_html__( 'Out of stock', 'schrack-woocommerce-sync' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="schrack-product-card__actions">
				<a class="schrack-product-card__details" href="<?php echo esc_url( $permalink ); ?>">
					<?php echo esc_html( $settings['details_button_text'] ); ?>
				</a>
				<?php if ( $settings['show_add_to_cart'] && $product->is_purchasable() && $product->is_in_stock() ) : ?>
					<a
						href="<?php echo esc_url( $product->add_to_cart_url() ); ?>"
						data-quantity="1"
						data-product_id="<?php echo esc_attr( (string) $product_id ); ?>"
						data-product_sku="<?php echo esc_attr( $sku ); ?>"
						class="<?php echo esc_attr( $cart_class ); ?>"
						rel="nofollow"
					>
						<?php echo esc_html( $product->add_to_cart_text() ); ?>
					</a>
				<?php endif; ?>
			</div>
		</article>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders result pagination.
	 */
	private function pagination( WP_Query $query, int $current_page, array $settings, bool $has_more ): string {
		if ( 'load_more' === $settings['pagination_mode'] ) {
			if ( ! $has_more ) {
				return '';
			}

			ob_start();
			?>
			<nav class="schrack-product-filter__pagination" aria-label="<?php esc_attr_e( 'Product pagination', 'schrack-woocommerce-sync' ); ?>">
				<button type="button" data-page="<?php echo esc_attr( (string) ( $current_page + 1 ) ); ?>" data-load-more="yes">
					<?php echo esc_html( $settings['load_more_text'] ); ?>
				</button>
			</nav>
			<?php

			return (string) ob_get_clean();
		}

		$total_pages = max( 1, (int) $query->max_num_pages );

		if ( $total_pages <= 1 ) {
			return '';
		}

		$start = max( 1, $current_page - 2 );
		$end   = min( $total_pages, $current_page + 2 );

		ob_start();
		?>
		<nav class="schrack-product-filter__pagination" aria-label="<?php esc_attr_e( 'Product pagination', 'schrack-woocommerce-sync' ); ?>">
			<button type="button" data-page="<?php echo esc_attr( (string) max( 1, $current_page - 1 ) ); ?>" <?php disabled( $current_page <= 1 ); ?>>
				<?php esc_html_e( 'Previous', 'schrack-woocommerce-sync' ); ?>
			</button>
			<?php for ( $page = $start; $page <= $end; ++$page ) : ?>
				<button type="button" data-page="<?php echo esc_attr( (string) $page ); ?>" <?php echo $page === $current_page ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( (string) $page ); ?>
				</button>
			<?php endfor; ?>
			<button type="button" data-page="<?php echo esc_attr( (string) min( $total_pages, $current_page + 1 ) ); ?>" <?php disabled( $current_page >= $total_pages ); ?>>
				<?php esc_html_e( 'Next', 'schrack-woocommerce-sync' ); ?>
			</button>
		</nav>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Builds a compact result summary.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function result_summary( WP_Query $query, array $settings, array $filters, int $visible_count, bool $has_more ): string {
		if ( $this->search_is_too_short( $settings, $filters ) ) {
			return sprintf(
				/* translators: %d: minimum search length. */
				__( 'Type at least %d characters to search products.', 'schrack-woocommerce-sync' ),
				(int) $settings['min_search_chars']
			);
		}

		if ( 0 === $visible_count ) {
			return __( 'No matching products.', 'schrack-woocommerce-sync' );
		}

		$total = (int) $query->found_posts;

		if ( 'load_more' === $settings['pagination_mode'] ) {
			$to = ( ( $filters['paged'] - 1 ) * $settings['products_per_page'] ) + $visible_count;

			if ( ! $this->uses_fast_load_more( $settings ) ) {
				return sprintf(
					/* translators: 1: visible product count, 2: total product count. */
					__( 'Showing 1-%1$d of %2$d products.', 'schrack-woocommerce-sync' ),
					$to,
					$total
				);
			}

			return $has_more
				? sprintf(
					/* translators: %d: visible product count. */
					__( 'Showing 1-%d products. More results are available.', 'schrack-woocommerce-sync' ),
					$to
				)
				: sprintf(
					/* translators: %d: visible product count. */
					__( 'Showing %d products.', 'schrack-woocommerce-sync' ),
					$to
				);
		}

		$from = ( ( $filters['paged'] - 1 ) * $settings['products_per_page'] ) + 1;
		$to   = min( $total, $from + $visible_count - 1 );

		return sprintf(
			/* translators: 1: first product index, 2: last product index, 3: total product count. */
			__( 'Showing %1$d-%2$d of %3$d products.', 'schrack-woocommerce-sync' ),
			$from,
			$to,
			$total
		);
	}

	/**
	 * Returns the posts that should be rendered from the current query.
	 *
	 * @return array<int,WP_Post>
	 */
	private function visible_posts( WP_Query $query, array $settings ): array {
		$posts = is_array( $query->posts ) ? $query->posts : array();

		if ( $this->uses_fast_load_more( $settings ) ) {
			$posts = array_slice( $posts, 0, (int) $settings['products_per_page'] );
		}

		return array_values( array_filter( $posts, static fn ( mixed $post ): bool => $post instanceof WP_Post ) );
	}

	/**
	 * Returns whether another result page is available.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function has_more_results( WP_Query $query, array $settings, array $filters ): bool {
		if ( $this->uses_fast_load_more( $settings ) ) {
			return count( is_array( $query->posts ) ? $query->posts : array() ) > (int) $settings['products_per_page'];
		}

		return $filters['paged'] < max( 1, (int) $query->max_num_pages );
	}

	/**
	 * Returns whether the widget should avoid expensive total counts.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function uses_fast_load_more( array $settings ): bool {
		return 'load_more' === (string) ( $settings['pagination_mode'] ?? 'load_more' ) && empty( $settings['exact_totals'] );
	}

	/**
	 * Returns whether product search should wait for more characters.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function search_is_too_short( array $settings, array $filters ): bool {
		$search = trim( (string) ( $filters['search'] ?? '' ) );

		return '' !== $search && strlen( $search ) < (int) $settings['min_search_chars'];
	}

	/**
	 * Returns the empty state heading.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function empty_title( array $settings, array $filters ): string {
		if ( $this->search_is_too_short( $settings, $filters ) ) {
			return __( 'Search term is too short.', 'schrack-woocommerce-sync' );
		}

		return __( 'No products found.', 'schrack-woocommerce-sync' );
	}

	/**
	 * Returns the empty state message.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function empty_message( array $settings, array $filters ): string {
		if ( $this->search_is_too_short( $settings, $filters ) ) {
			return sprintf(
				/* translators: %d: minimum search length. */
				__( 'Type at least %d characters before product search runs on the large catalog.', 'schrack-woocommerce-sync' ),
				(int) $settings['min_search_chars']
			);
		}

		return __( 'Try another category, search term, or price range.', 'schrack-woocommerce-sync' );
	}

	/**
	 * Returns product categories for the filter dropdown.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function product_categories(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			static function ( WP_Term $term ): array {
				return array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
				);
			},
			$terms
		);
	}

	/**
	 * Returns available sorting options.
	 *
	 * @return array<string,string>
	 */
	private function orderby_options(): array {
		return array(
			'menu_order' => __( 'Default', 'schrack-woocommerce-sync' ),
			'title'      => __( 'Name A-Z', 'schrack-woocommerce-sync' ),
			'price'      => __( 'Price low to high', 'schrack-woocommerce-sync' ),
			'price-desc' => __( 'Price high to low', 'schrack-woocommerce-sync' ),
			'date'       => __( 'Newest', 'schrack-woocommerce-sync' ),
			'popularity' => __( 'Popularity', 'schrack-woocommerce-sync' ),
		);
	}

	/**
	 * Sanitizes renderer settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private function sanitize_settings( array $settings ): array {
		$columns           = max( 1, min( 5, absint( $settings['columns'] ?? 3 ) ) );
		$products_per_page = max( 1, min( 60, absint( $settings['products_per_page'] ?? 12 ) ) );
		$orderby           = sanitize_key( (string) ( $settings['default_orderby'] ?? 'menu_order' ) );
		$pagination_mode   = sanitize_key( (string) ( $settings['pagination_mode'] ?? 'load_more' ) );

		if ( ! array_key_exists( $orderby, $this->orderby_options() ) ) {
			$orderby = 'menu_order';
		}

		if ( ! in_array( $pagination_mode, array( 'load_more', 'numbered' ), true ) ) {
			$pagination_mode = 'load_more';
		}

		return array(
			'products_per_page'   => $products_per_page,
			'columns'             => $columns,
			'default_category'    => absint( $settings['default_category'] ?? 0 ),
			'default_orderby'     => $orderby,
			'pagination_mode'     => $pagination_mode,
			'exact_totals'        => $this->truthy( $settings['exact_totals'] ?? 'no' ),
			'min_search_chars'    => max( 1, min( 5, absint( $settings['min_search_chars'] ?? 2 ) ) ),
			'show_search'         => $this->truthy( $settings['show_search'] ?? 'yes' ),
			'show_category_filter'=> $this->truthy( $settings['show_category_filter'] ?? 'yes' ),
			'show_category_search'=> $this->truthy( $settings['show_category_search'] ?? 'yes' ),
			'show_price_filter'   => $this->truthy( $settings['show_price_filter'] ?? 'yes' ),
			'show_sort'           => $this->truthy( $settings['show_sort'] ?? 'yes' ),
			'show_images'         => $this->truthy( $settings['show_images'] ?? 'yes' ),
			'show_categories'     => $this->truthy( $settings['show_categories'] ?? 'yes' ),
			'show_excerpt'        => $this->truthy( $settings['show_excerpt'] ?? 'yes' ),
			'show_stock'          => $this->truthy( $settings['show_stock'] ?? 'yes' ),
			'show_add_to_cart'    => $this->truthy( $settings['show_add_to_cart'] ?? 'yes' ),
			'hide_out_of_stock'   => $this->truthy( $settings['hide_out_of_stock'] ?? 'no' ),
			'button_text'         => sanitize_text_field( (string) ( $settings['button_text'] ?? __( 'Apply filters', 'schrack-woocommerce-sync' ) ) ),
			'reset_text'          => sanitize_text_field( (string) ( $settings['reset_text'] ?? __( 'Reset', 'schrack-woocommerce-sync' ) ) ),
			'load_more_text'      => sanitize_text_field( (string) ( $settings['load_more_text'] ?? __( 'Load more', 'schrack-woocommerce-sync' ) ) ),
			'details_button_text' => sanitize_text_field( (string) ( $settings['details_button_text'] ?? __( 'Details', 'schrack-woocommerce-sync' ) ) ),
			'accent_color'        => sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#135e96' ) ) ?: '#135e96',
			'action_color'        => sanitize_hex_color( (string) ( $settings['action_color'] ?? '#b32d2e' ) ) ?: '#b32d2e',
			'card_radius'         => $this->slider_size( $settings['card_radius'] ?? 8, 0, 8 ),
		);
	}

	/**
	 * Sanitizes frontend filter values.
	 *
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,mixed>
	 */
	private function sanitize_filters( array $filters ): array {
		$min_price = $this->decimal_or_null( $filters['min_price'] ?? null );
		$max_price = $this->decimal_or_null( $filters['max_price'] ?? null );

		if ( null !== $min_price && null !== $max_price && $min_price > $max_price ) {
			$tmp       = $min_price;
			$min_price = $max_price;
			$max_price = $tmp;
		}

		$orderby = sanitize_key( (string) ( $filters['orderby'] ?? 'menu_order' ) );

		if ( ! array_key_exists( $orderby, $this->orderby_options() ) ) {
			$orderby = 'menu_order';
		}

		return array(
			'search'          => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
			'category'        => absint( $filters['category'] ?? 0 ),
			'category_search' => sanitize_text_field( (string) ( $filters['category_search'] ?? '' ) ),
			'min_price'       => $min_price,
			'max_price'       => $max_price,
			'paged'           => max( 1, absint( $filters['paged'] ?? 1 ) ),
			'orderby'         => $orderby,
		);
	}

	/**
	 * Returns whether a mixed Elementor setting is enabled.
	 */
	private function truthy( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'true' === $value;
	}

	/**
	 * Sanitizes decimal filter values.
	 */
	private function decimal_or_null( mixed $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = str_replace( ',', '.', sanitize_text_field( (string) $value ) );

		return is_numeric( $value ) ? max( 0.0, (float) $value ) : null;
	}

	/**
	 * Returns Elementor slider size as an integer.
	 */
	private function slider_size( mixed $value, int $min, int $max ): int {
		if ( is_array( $value ) ) {
			$value = $value['size'] ?? $min;
		}

		return max( $min, min( $max, absint( $value ) ) );
	}

	/**
	 * Builds inline CSS variables for the widget instance.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function inline_style( array $settings ): string {
		return sprintf(
			'--schrack-filter-accent:%1$s;--schrack-filter-action:%2$s;--schrack-filter-card-radius:%3$dpx;--schrack-filter-columns:%4$d;',
			esc_attr( (string) $settings['accent_color'] ),
			esc_attr( (string) $settings['action_color'] ),
			absint( $settings['card_radius'] ),
			absint( $settings['columns'] )
		);
	}
}
