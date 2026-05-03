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
	public const CATEGORY_AJAX_ACTION = 'schrack_wc_filter_categories';
	public const NONCE_ACTION = 'schrack_wc_product_filter';

	/**
	 * Renders the full filter widget shell and the initial product results.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="schrack-product-filter"><p>' . esc_html__( 'WooCommerce este necesar pentru acest filtru de produse.', 'schrack-woocommerce-sync' ) . '</p></div>';
		}

		wp_enqueue_style( 'schrack-wc-product-filter' );
		wp_enqueue_script( 'schrack-wc-product-filter' );

		$settings    = $this->sanitize_settings( $settings );
		$settings    = $this->settings_with_request_category( $settings );
		$instance_id = '' !== $instance_id ? sanitize_html_class( $instance_id ) : 'schrack-products-' . wp_rand( 1000, 999999 );
		$filters     = array(
			'category' => $settings['default_category'],
			'orderby'  => $settings['default_orderby'],
			'paged'    => 1,
		);
		$results     = $this->render_results( $settings, $filters );
		$config      = $this->public_settings( $settings );
		$category    = $this->category_for_picker( $settings['default_category'] );
		$style       = $this->inline_style( $settings );

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="schrack-product-filter"
			style="<?php echo esc_attr( $style ); ?>"
			data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
			data-category-action="<?php echo esc_attr( self::CATEGORY_AJAX_ACTION ); ?>"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
		>
			<div class="schrack-product-filter__layout">
				<aside class="schrack-product-filter__sidebar">
					<form class="schrack-product-filter__form" method="get">
						<div class="schrack-product-filter__sidebar-head">
							<span><?php esc_html_e( 'Filtre', 'schrack-woocommerce-sync' ); ?></span>
						</div>

						<div class="schrack-product-filter__controls">
							<?php if ( $settings['show_search'] ) : ?>
							<label class="schrack-product-filter__field">
								<span><?php esc_html_e( 'Cauta produse', 'schrack-woocommerce-sync' ); ?></span>
								<input type="search" name="search" placeholder="<?php esc_attr_e( 'Nume, SKU, cod produs', 'schrack-woocommerce-sync' ); ?>">
							</label>
							<?php endif; ?>

							<?php if ( $settings['show_category_filter'] ) : ?>
							<div
								class="schrack-category-picker"
								data-category-picker
								data-default-category-id="<?php echo esc_attr( (string) $category['id'] ); ?>"
								data-default-category-label="<?php echo esc_attr( $category['label'] ); ?>"
							>
								<?php if ( $settings['show_category_search'] ) : ?>
									<label class="schrack-product-filter__field">
										<span><?php esc_html_e( 'Categorie', 'schrack-woocommerce-sync' ); ?></span>
										<input
											type="search"
											name="category_search"
											value="<?php echo esc_attr( $category['label'] ); ?>"
											data-category-search
											autocomplete="off"
											placeholder="<?php esc_attr_e( 'Cauta categorie', 'schrack-woocommerce-sync' ); ?>"
											aria-expanded="false"
										>
									</label>
								<?php endif; ?>
								<input type="hidden" name="category" value="<?php echo esc_attr( (string) $category['id'] ); ?>" data-category-id>
								<div class="schrack-category-picker__selected" data-category-selected <?php echo $category['id'] > 0 ? '' : 'hidden'; ?>>
									<span data-category-selected-label><?php echo esc_html( $category['label'] ); ?></span>
									<button type="button" data-category-clear aria-label="<?php esc_attr_e( 'Sterge categoria', 'schrack-woocommerce-sync' ); ?>">&times;</button>
								</div>
								<div class="schrack-category-picker__results" data-category-results role="tree" hidden></div>
							</div>
							<?php endif; ?>

							<?php if ( $settings['show_price_filter'] ) : ?>
							<div class="schrack-product-filter__price-row">
								<label class="schrack-product-filter__field schrack-product-filter__field--price">
									<span><?php esc_html_e( 'Pret minim', 'schrack-woocommerce-sync' ); ?></span>
									<input type="number" name="min_price" min="0" step="0.01" inputmode="decimal" placeholder="0">
								</label>
								<label class="schrack-product-filter__field schrack-product-filter__field--price">
									<span><?php esc_html_e( 'Pret maxim', 'schrack-woocommerce-sync' ); ?></span>
									<input type="number" name="max_price" min="0" step="0.01" inputmode="decimal">
								</label>
							</div>
							<?php endif; ?>

							<?php if ( $settings['show_sort'] ) : ?>
							<label class="schrack-product-filter__field">
								<span><?php esc_html_e( 'Sorteaza dupa', 'schrack-woocommerce-sync' ); ?></span>
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
				</aside>

				<section class="schrack-product-filter__content">
					<div class="schrack-product-filter__results" aria-live="polite">
						<?php echo $results['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</section>
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

		if ( empty( $filters['category'] ) && ! $settings['show_category_filter'] && $settings['default_category'] > 0 ) {
			$filters['category'] = (int) $settings['default_category'];
		}

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
			'products_per_page'        => $settings['products_per_page'],
			'columns'                  => $settings['columns'],
			'default_category'         => $settings['default_category'],
			'inherit_current_category' => $settings['inherit_current_category'] ? 'yes' : 'no',
			'default_orderby'          => $settings['default_orderby'],
			'pagination_mode'          => $settings['pagination_mode'],
			'pagination_granularity'   => $settings['pagination_granularity'],
			'exact_totals'             => $settings['exact_totals'] ? 'yes' : 'no',
			'min_search_chars'         => $settings['min_search_chars'],
			'category_results_limit'   => $settings['category_results_limit'],
			'show_images'              => $settings['show_images'] ? 'yes' : 'no',
			'show_categories'          => $settings['show_categories'] ? 'yes' : 'no',
			'show_excerpt'             => $settings['show_excerpt'] ? 'yes' : 'no',
			'show_stock'               => $settings['show_stock'] ? 'yes' : 'no',
			'show_add_to_cart'         => $settings['show_add_to_cart'] ? 'yes' : 'no',
			'hide_out_of_stock'        => $settings['hide_out_of_stock'] ? 'yes' : 'no',
			'button_text'              => $settings['button_text'],
			'load_more_text'           => $settings['load_more_text'],
			'details_button_text'      => $settings['details_button_text'],
		);
	}

	/**
	 * Applies the current product category archive as the widget default category.
	 *
	 * @param array<string,mixed> $settings Sanitized settings.
	 * @return array<string,mixed>
	 */
	private function settings_with_request_category( array $settings ): array {
		if ( empty( $settings['inherit_current_category'] ) ) {
			return $settings;
		}

		$current_category = $this->current_request_category_id();

		if ( $current_category > 0 ) {
			$settings['default_category'] = $current_category;
		}

		return $settings;
	}

	/**
	 * Returns the product category represented by the current request URL.
	 */
	private function current_request_category_id(): int {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}

		$queried = get_queried_object();

		if ( $queried instanceof WP_Term && 'product_cat' === $queried->taxonomy ) {
			return (int) $queried->term_id;
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term && 'product_cat' === $term->taxonomy ) {
				return (int) $term->term_id;
			}
		}

		return $this->category_id_from_request_path();
	}

	/**
	 * Falls back to the last URL path segment for category archive templates.
	 */
	private function category_id_from_request_path(): int {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$path        = trim( $path, '/' );

		if ( '' === $path ) {
			return 0;
		}

		$segments = array_reverse( array_values( array_filter( explode( '/', $path ) ) ) );

		foreach ( $segments as $segment ) {
			$slug = sanitize_title( rawurldecode( (string) $segment ) );

			if ( '' === $slug || 'page' === $slug || is_numeric( $slug ) ) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				return (int) $term->term_id;
			}
		}

		return 0;
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
							<?php echo $product->is_in_stock() ? esc_html__( 'In stoc', 'schrack-woocommerce-sync' ) : esc_html__( 'Stoc epuizat', 'schrack-woocommerce-sync' ); ?>
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
			<nav class="schrack-product-filter__pagination" aria-label="<?php esc_attr_e( 'Paginare produse', 'schrack-woocommerce-sync' ); ?>">
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

		$granularity = (int) $settings['pagination_granularity'];
		$side        = intdiv( $granularity - 1, 2 );
		$start       = max( 1, $current_page - $side );
		$end         = min( $total_pages, $start + $granularity - 1 );
		$start       = max( 1, $end - $granularity + 1 );

		ob_start();
		?>
		<nav class="schrack-product-filter__pagination" aria-label="<?php esc_attr_e( 'Paginare produse', 'schrack-woocommerce-sync' ); ?>">
			<button type="button" data-page="<?php echo esc_attr( (string) max( 1, $current_page - 1 ) ); ?>" <?php disabled( $current_page <= 1 ); ?>>
				<?php esc_html_e( 'Inapoi', 'schrack-woocommerce-sync' ); ?>
			</button>
			<?php for ( $page = $start; $page <= $end; ++$page ) : ?>
				<button type="button" data-page="<?php echo esc_attr( (string) $page ); ?>" <?php echo $page === $current_page ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( (string) $page ); ?>
				</button>
			<?php endfor; ?>
			<button type="button" data-page="<?php echo esc_attr( (string) min( $total_pages, $current_page + 1 ) ); ?>" <?php disabled( $current_page >= $total_pages ); ?>>
				<?php esc_html_e( 'Inainte', 'schrack-woocommerce-sync' ); ?>
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
				__( 'Introdu cel putin %d caractere pentru cautarea produselor.', 'schrack-woocommerce-sync' ),
				(int) $settings['min_search_chars']
			);
		}

		if ( 0 === $visible_count ) {
			return __( 'Nu exista produse potrivite.', 'schrack-woocommerce-sync' );
		}

		$total = (int) $query->found_posts;

		if ( 'load_more' === $settings['pagination_mode'] ) {
			$to = ( ( $filters['paged'] - 1 ) * $settings['products_per_page'] ) + $visible_count;

			if ( ! $this->uses_fast_load_more( $settings ) ) {
				return sprintf(
					/* translators: 1: visible product count, 2: total product count. */
					__( 'Se afiseaza 1-%1$d din %2$d produse.', 'schrack-woocommerce-sync' ),
					$to,
					$total
				);
			}

			return $has_more
				? sprintf(
					/* translators: %d: visible product count. */
					__( 'Se afiseaza 1-%d produse. Sunt disponibile mai multe rezultate.', 'schrack-woocommerce-sync' ),
					$to
				)
				: sprintf(
					/* translators: %d: visible product count. */
					__( 'Se afiseaza %d produse.', 'schrack-woocommerce-sync' ),
					$to
				);
		}

		$from = ( ( $filters['paged'] - 1 ) * $settings['products_per_page'] ) + 1;
		$to   = min( $total, $from + $visible_count - 1 );

		return sprintf(
			/* translators: 1: first product index, 2: last product index, 3: total product count. */
			__( 'Se afiseaza %1$d-%2$d din %3$d produse.', 'schrack-woocommerce-sync' ),
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
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $search ) : strlen( $search );

		return '' !== $search && $length < (int) $settings['min_search_chars'];
	}

	/**
	 * Returns the empty state heading.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function empty_title( array $settings, array $filters ): string {
		if ( $this->search_is_too_short( $settings, $filters ) ) {
			return __( 'Termenul de cautare este prea scurt.', 'schrack-woocommerce-sync' );
		}

		return __( 'Nu s-au gasit produse.', 'schrack-woocommerce-sync' );
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
				__( 'Introdu cel putin %d caractere inainte de cautarea in catalogul mare.', 'schrack-woocommerce-sync' ),
				(int) $settings['min_search_chars']
			);
		}

		return __( 'Incearca alta categorie, alt termen de cautare sau alt interval de pret.', 'schrack-woocommerce-sync' );
	}

	/**
	 * Renders async category picker results.
	 *
	 * @return array<string,mixed>
	 */
	public function render_category_results( string $search = '', int $selected = 0, int $limit = 30 ): array {
		$search = sanitize_text_field( $search );
		$limit  = max( 5, min( 80, $limit ) );
		$tree   = $this->category_tree_for_picker( $search, $limit );
		$nodes  = $tree['nodes'];

		ob_start();
		?>
		<div class="schrack-category-picker__list">
			<?php if ( empty( $nodes ) ) : ?>
				<div class="schrack-category-picker__empty">
					<?php esc_html_e( 'Nu s-au gasit categorii.', 'schrack-woocommerce-sync' ); ?>
				</div>
			<?php else : ?>
				<?php $this->render_category_tree_nodes( $nodes, $selected ); ?>
				<?php if ( $tree['limited'] ) : ?>
					<div class="schrack-category-picker__empty schrack-category-picker__empty--hint">
						<?php esc_html_e( 'Sunt afisate primele potriviri. Continua cautarea pentru rezultate mai exacte.', 'schrack-woocommerce-sync' ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php

		return array(
			'html'  => (string) ob_get_clean(),
			'count' => (int) $tree['count'],
		);
	}

	/**
	 * Renders category tree nodes.
	 *
	 * @param array<int,array<string,mixed>> $nodes Category nodes.
	 */
	private function render_category_tree_nodes( array $nodes, int $selected ): void {
		foreach ( $nodes as $node ) {
			$node_id      = (int) $node['id'];
			$has_children = ! empty( $node['children'] );
			$is_selected  = $selected === $node_id;
			$classes      = array( 'schrack-category-picker__node' );

			if ( $has_children ) {
				$classes[] = 'has-children';
			}

			if ( ! empty( $node['match'] ) ) {
				$classes[] = 'is-match';
			}

			?>
			<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<button
					type="button"
					class="schrack-category-picker__option <?php echo $is_selected ? 'is-selected' : ''; ?>"
					data-category-option
					data-category-id="<?php echo esc_attr( (string) $node_id ); ?>"
					data-category-label="<?php echo esc_attr( (string) $node['path'] ); ?>"
					style="<?php echo esc_attr( '--schrack-category-depth:' . (int) $node['depth'] ); ?>"
					role="treeitem"
					aria-level="<?php echo esc_attr( (string) ( (int) $node['depth'] + 1 ) ); ?>"
					aria-selected="<?php echo $is_selected ? 'true' : 'false'; ?>"
					<?php echo $has_children ? 'aria-expanded="true"' : ''; ?>
				>
					<span class="schrack-category-picker__branch" aria-hidden="true"></span>
					<span class="schrack-category-picker__name"><?php echo esc_html( (string) $node['name'] ); ?></span>
					<span class="schrack-category-picker__count"><?php echo esc_html( (string) $node['count'] ); ?></span>
					<span class="schrack-category-picker__path"><?php echo esc_html( (string) $node['path'] ); ?></span>
				</button>

				<?php if ( $has_children ) : ?>
					<div class="schrack-category-picker__children" role="group">
						<?php $this->render_category_tree_nodes( $node['children'], $selected ); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Returns matching categories as a tree for the async picker.
	 *
	 * @return array{nodes:array<int,array<string,mixed>>,count:int,limited:bool}
	 */
	private function category_tree_for_picker( string $search, int $limit ): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array(
				'nodes'   => array(),
				'count'   => 0,
				'limited' => false,
			);
		}

		$search    = trim( $search );
		$match_ids = array();
		$limited   = false;

		if ( '' !== $search ) {
			$matches = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
					'number'     => $limit + 1,
					'name__like' => $search,
				)
			);

			if ( is_wp_error( $matches ) || ! is_array( $matches ) || empty( $matches ) ) {
				return array(
					'nodes'   => array(),
					'count'   => 0,
					'limited' => false,
				);
			}

			if ( count( $matches ) > $limit ) {
				$limited = true;
				$matches = array_slice( $matches, 0, $limit );
			}

			$include_ids = array();

			foreach ( $matches as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$term_id              = (int) $term->term_id;
				$match_ids[ $term_id ] = true;
				$include_ids[ $term_id ] = $term_id;

				foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
					$include_ids[ (int) $ancestor_id ] = (int) $ancestor_id;
				}
			}

			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'include'    => array_values( $include_ids ),
				)
			);
		} else {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);
		}

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array(
				'nodes'   => array(),
				'count'   => 0,
				'limited' => false,
			);
		}

		$views = array();

		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$view            = $this->category_term_view( $term );
				$view['parent']   = (int) $term->parent;
				$view['match']    = isset( $match_ids[ (int) $view['id'] ] );
				$view['children'] = array();
				$views[ (int) $view['id'] ] = $view;
			}
		}

		$nodes = $this->category_tree_nodes_from_views( $views );

		return array(
			'nodes'   => $nodes,
			'count'   => $this->count_category_tree_nodes( $nodes ),
			'limited' => $limited,
		);
	}

	/**
	 * Builds nested category tree nodes from term views.
	 *
	 * @param array<int,array<string,mixed>> $views Category views keyed by term ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function category_tree_nodes_from_views( array $views ): array {
		$children_by_parent = array();

		foreach ( $views as $id => $view ) {
			$parent = (int) ( $view['parent'] ?? 0 );

			if ( $parent > 0 && ! isset( $views[ $parent ] ) ) {
				$parent = 0;
			}

			$children_by_parent[ $parent ][] = (int) $id;
		}

		foreach ( $children_by_parent as &$child_ids ) {
			usort(
				$child_ids,
				static fn ( int $a, int $b ): int => strnatcasecmp( (string) $views[ $a ]['name'], (string) $views[ $b ]['name'] )
			);
		}

		unset( $child_ids );

		$build = function ( int $parent ) use ( &$build, $children_by_parent, $views ): array {
			$nodes = array();

			foreach ( $children_by_parent[ $parent ] ?? array() as $id ) {
				$node             = $views[ $id ];
				$node['children'] = $build( (int) $id );
				$nodes[]          = $node;
			}

			return $nodes;
		};

		return $build( 0 );
	}

	/**
	 * Counts visible category tree nodes.
	 *
	 * @param array<int,array<string,mixed>> $nodes Category nodes.
	 */
	private function count_category_tree_nodes( array $nodes ): int {
		$count = 0;

		foreach ( $nodes as $node ) {
			++$count;
			$count += $this->count_category_tree_nodes( $node['children'] ?? array() );
		}

		return $count;
	}

	/**
	 * Returns the selected category display data.
	 *
	 * @return array{id:int,label:string}
	 */
	private function category_for_picker( int $category_id ): array {
		if ( $category_id <= 0 || ! taxonomy_exists( 'product_cat' ) ) {
			return array(
				'id'    => 0,
				'label' => '',
			);
		}

		$term = get_term( $category_id, 'product_cat' );

		if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
			return array(
				'id'    => 0,
				'label' => '',
			);
		}

		$view = $this->category_term_view( $term );

		return array(
			'id'    => (int) $view['id'],
			'label' => (string) $view['path'],
		);
	}

	/**
	 * Converts a product category term into picker display data.
	 *
	 * @return array{id:int,name:string,path:string,depth:int,count:int}
	 */
	private function category_term_view( WP_Term $term ): array {
		$ancestors = array_reverse( get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) );
		$parts     = array();

		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, 'product_cat' );

			if ( $ancestor instanceof WP_Term && ! is_wp_error( $ancestor ) ) {
				$parts[] = $ancestor->name;
			}
		}

		$parts[] = $term->name;

		return array(
			'id'    => (int) $term->term_id,
			'name'  => $term->name,
			'path'  => implode( ' / ', $parts ),
			'depth' => count( $ancestors ),
			'count' => (int) $term->count,
		);
	}

	/**
	 * Returns available sorting options.
	 *
	 * @return array<string,string>
	 */
	private function orderby_options(): array {
		return array(
			'menu_order' => __( 'Implicit', 'schrack-woocommerce-sync' ),
			'title'      => __( 'Nume A-Z', 'schrack-woocommerce-sync' ),
			'price'      => __( 'Pret crescator', 'schrack-woocommerce-sync' ),
			'price-desc' => __( 'Pret descrescator', 'schrack-woocommerce-sync' ),
			'date'       => __( 'Cele mai noi', 'schrack-woocommerce-sync' ),
			'popularity' => __( 'Popularitate', 'schrack-woocommerce-sync' ),
		);
	}

	/**
	 * Sanitizes renderer settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private function sanitize_settings( array $settings ): array {
		$columns                = max( 5, min( 6, absint( $settings['columns'] ?? 5 ) ) );
		$products_per_page      = max( 1, min( 60, absint( $settings['products_per_page'] ?? 12 ) ) );
		$orderby                = sanitize_key( (string) ( $settings['default_orderby'] ?? 'menu_order' ) );
		$pagination_mode        = sanitize_key( (string) ( $settings['pagination_mode'] ?? 'numbered' ) );
		$pagination_granularity = max( 3, min( 11, absint( $settings['pagination_granularity'] ?? 7 ) ) );

		if ( 0 === $pagination_granularity % 2 ) {
			++$pagination_granularity;
		}

		if ( ! array_key_exists( $orderby, $this->orderby_options() ) ) {
			$orderby = 'menu_order';
		}

		if ( ! in_array( $pagination_mode, array( 'load_more', 'numbered' ), true ) ) {
			$pagination_mode = 'numbered';
		}

		return array(
			'products_per_page'        => $products_per_page,
			'columns'                  => $columns,
			'default_category'         => absint( $settings['default_category'] ?? 0 ),
			'inherit_current_category' => $this->truthy( $settings['inherit_current_category'] ?? 'yes' ),
			'default_orderby'          => $orderby,
			'pagination_mode'          => $pagination_mode,
			'pagination_granularity'   => $pagination_granularity,
			'exact_totals'             => $this->truthy( $settings['exact_totals'] ?? 'no' ),
			'min_search_chars'         => max( 1, min( 5, absint( $settings['min_search_chars'] ?? 2 ) ) ),
			'category_results_limit'   => max( 10, min( 80, absint( $settings['category_results_limit'] ?? 30 ) ) ),
			'show_search'              => $this->truthy( $settings['show_search'] ?? 'yes' ),
			'show_category_filter'     => $this->truthy( $settings['show_category_filter'] ?? 'yes' ),
			'show_category_search'     => $this->truthy( $settings['show_category_search'] ?? 'yes' ),
			'show_price_filter'        => $this->truthy( $settings['show_price_filter'] ?? 'yes' ),
			'show_sort'                => $this->truthy( $settings['show_sort'] ?? 'yes' ),
			'show_images'              => $this->truthy( $settings['show_images'] ?? 'yes' ),
			'show_categories'          => $this->truthy( $settings['show_categories'] ?? 'yes' ),
			'show_excerpt'             => $this->truthy( $settings['show_excerpt'] ?? 'yes' ),
			'show_stock'               => $this->truthy( $settings['show_stock'] ?? 'yes' ),
			'show_add_to_cart'         => $this->truthy( $settings['show_add_to_cart'] ?? 'yes' ),
			'hide_out_of_stock'        => $this->truthy( $settings['hide_out_of_stock'] ?? 'no' ),
			'button_text'              => $this->localized_text_setting(
				$settings['button_text'] ?? '',
				__( 'Aplica filtrele', 'schrack-woocommerce-sync' ),
				array(
					'Apply filter'  => __( 'Aplica filtrul', 'schrack-woocommerce-sync' ),
					'Apply filters' => __( 'Aplica filtrele', 'schrack-woocommerce-sync' ),
					'Filter'        => __( 'Filtreaza', 'schrack-woocommerce-sync' ),
				)
			),
			'reset_text'               => $this->localized_text_setting(
				$settings['reset_text'] ?? '',
				__( 'Reseteaza', 'schrack-woocommerce-sync' ),
				array(
					'Clear'         => __( 'Sterge', 'schrack-woocommerce-sync' ),
					'Reset'         => __( 'Reseteaza', 'schrack-woocommerce-sync' ),
					'Reset filters' => __( 'Reseteaza filtrele', 'schrack-woocommerce-sync' ),
				)
			),
			'load_more_text'           => $this->localized_text_setting(
				$settings['load_more_text'] ?? '',
				__( 'Incarca mai multe', 'schrack-woocommerce-sync' ),
				array(
					'Load more'          => __( 'Incarca mai multe', 'schrack-woocommerce-sync' ),
					'Show more'          => __( 'Afiseaza mai multe', 'schrack-woocommerce-sync' ),
					'More products'      => __( 'Mai multe produse', 'schrack-woocommerce-sync' ),
					'Load more products' => __( 'Incarca mai multe produse', 'schrack-woocommerce-sync' ),
				)
			),
			'details_button_text'      => $this->localized_text_setting(
				$settings['details_button_text'] ?? '',
				__( 'Detalii', 'schrack-woocommerce-sync' ),
				array(
					'Details'      => __( 'Detalii', 'schrack-woocommerce-sync' ),
					'View details' => __( 'Vezi detalii', 'schrack-woocommerce-sync' ),
					'Read more'    => __( 'Citeste mai mult', 'schrack-woocommerce-sync' ),
				)
			),
			'accent_color'             => sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#135e96' ) ) ?: '#135e96',
			'action_color'             => sanitize_hex_color( (string) ( $settings['action_color'] ?? '#b32d2e' ) ) ?: '#b32d2e',
			'card_radius'              => $this->slider_size( $settings['card_radius'] ?? 8, 0, 8 ),
			'sidebar_width'            => $this->slider_size( $settings['sidebar_width'] ?? 300, 260, 420 ),
		);
	}

	/**
	 * Returns a localized text setting while upgrading older saved English defaults.
	 *
	 * @param array<string,string> $legacy Legacy source labels mapped to localized labels.
	 */
	private function localized_text_setting( mixed $value, string $fallback, array $legacy ): string {
		$text = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );

		if ( '' === $text ) {
			return $fallback;
		}

		$normalized = $this->normalize_label_key( $text );

		foreach ( $legacy as $source => $localized ) {
			if ( $normalized === $this->normalize_label_key( $source ) ) {
				return $localized;
			}
		}

		return $text;
	}

	/**
	 * Normalizes a UI label for legacy default matching.
	 */
	private function normalize_label_key( string $label ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $label ) ?? $label ) );
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
			'--schrack-filter-accent:%1$s;--schrack-filter-action:%2$s;--schrack-filter-card-radius:%3$dpx;--schrack-filter-columns:%4$d;--schrack-filter-sidebar-width:%5$dpx;',
			esc_attr( (string) $settings['accent_color'] ),
			esc_attr( (string) $settings['action_color'] ),
			absint( $settings['card_radius'] ),
			absint( $settings['columns'] ),
			absint( $settings['sidebar_width'] )
		);
	}
}
