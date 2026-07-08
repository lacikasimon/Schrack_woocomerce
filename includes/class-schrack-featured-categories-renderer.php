<?php
/**
 * Elementor featured categories renderer: transparent category nav, promo banner, per-category product grids.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Featured_Categories_Renderer {
	/**
	 * Per-request category thumbnail cache.
	 *
	 * @var array<int,int>
	 */
	private array $term_thumbnail_ids = array();

	/**
	 * Renders the featured categories module.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="schrack-fcat"><p>' . esc_html__( 'WooCommerce este necesar pentru acest modul.', 'schrack-woocommerce-sync' ) . '</p></div>';
		}

		$settings = $this->sanitize_settings( $settings );
		$terms    = $this->main_categories( (int) $settings['category_limit'] );

		wp_enqueue_style( 'schrack-wc-featured-categories' );
		wp_enqueue_script( 'schrack-wc-featured-categories' );

		$style = sprintf(
			'--schrack-fcat-accent:%1$s;--schrack-fcat-action:%2$s;--schrack-fcat-radius:%3$dpx;--schrack-fcat-width:%4$dpx;--schrack-fcat-hero-height:%5$dpx;',
			esc_attr( $settings['accent_color'] ),
			esc_attr( $settings['action_color'] ),
			(int) $settings['radius'],
			(int) $settings['max_width'],
			(int) $settings['hero_height']
		);

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( '' !== $instance_id ? 'schrack-fcat-' . $instance_id : 'schrack-fcat' ); ?>"
			class="schrack-fcat"
			style="<?php echo esc_attr( $style ); ?>"
			data-schrack-fcat
		>
			<?php echo $this->category_nav( $terms, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->promo_banner( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->category_grids( $terms, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Normalizes widget settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private function sanitize_settings( array $settings ): array {
		$defaults = array(
			'category_limit'        => 10,
			'hero_background_image' => '',
			'hero_height'           => 420,
			'hero_overlay_opacity'  => 45,
			'promo_category_id'     => 0,
			'promo_eyebrow'         => '',
			'promo_title'           => '',
			'promo_subtitle'        => '',
			'promo_button_text'     => __( 'Vezi categoria', 'schrack-woocommerce-sync' ),
			'products_per_category' => 5,
			'grid_columns'          => 5,
			'products_orderby'      => 'date',
			'accent_color'          => '#135e96',
			'action_color'          => '#b32d2e',
			'radius'                => 8,
			'max_width'             => 1180,
		);

		$settings = wp_parse_args( $settings, $defaults );

		$settings['category_limit']        = max( 1, min( 12, (int) $settings['category_limit'] ) );
		$settings['hero_height']           = max( 220, min( 720, (int) $settings['hero_height'] ) );
		$settings['hero_overlay_opacity']  = max( 0, min( 100, (int) $settings['hero_overlay_opacity'] ) );
		$settings['promo_category_id']     = (int) $settings['promo_category_id'];
		$settings['products_per_category'] = max( 0, min( 10, (int) $settings['products_per_category'] ) );
		$settings['grid_columns']          = max( 2, min( 6, (int) $settings['grid_columns'] ) );
		$settings['radius']                = (int) $settings['radius'];
		$settings['max_width']             = (int) $settings['max_width'];

		if ( ! in_array( $settings['products_orderby'], array( 'date', 'popularity', 'price', 'title' ), true ) ) {
			$settings['products_orderby'] = 'date';
		}

		foreach ( array( 'hero_background_image', 'promo_eyebrow', 'promo_title', 'promo_subtitle', 'promo_button_text', 'accent_color', 'action_color' ) as $key ) {
			$settings[ $key ] = is_string( $settings[ $key ] ) ? trim( $settings[ $key ] ) : '';
		}

		return $settings;
	}

	/**
	 * Returns the automatic top-level product categories, ranked by product count.
	 *
	 * @return array<int,WP_Term>
	 */
	private function main_categories( int $limit ): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => $limit,
				'exclude'    => $this->uncategorized_term_id(),
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static fn( $term ): bool => $term instanceof WP_Term
			)
		);
	}

	/**
	 * Returns the WooCommerce default "Uncategorized" term id, if any.
	 *
	 * @return array<int,int>
	 */
	private function uncategorized_term_id(): array {
		$term = get_term_by( 'slug', 'uncategorized', 'product_cat' );

		return $term instanceof WP_Term ? array( (int) $term->term_id ) : array();
	}

	/**
	 * Renders the transparent category navigation over the hero background.
	 *
	 * @param array<int,WP_Term>  $terms Main category terms.
	 * @param array<string,mixed> $settings Widget settings.
	 */
	private function category_nav( array $terms, array $settings ): string {
		$hero_style = '';

		if ( '' !== $settings['hero_background_image'] ) {
			$hero_style = sprintf( 'background-image:url(%s);', esc_url( $settings['hero_background_image'] ) );
		}

		$overlay_style = sprintf( '--schrack-fcat-overlay-opacity:%s;', esc_attr( (string) ( $settings['hero_overlay_opacity'] / 100 ) ) );

		ob_start();
		?>
		<div class="schrack-fcat__hero" style="<?php echo esc_attr( $hero_style ); ?>" data-fcat-hero>
			<span class="schrack-fcat__hero-overlay" style="<?php echo esc_attr( $overlay_style ); ?>" aria-hidden="true"></span>

			<?php if ( ! empty( $terms ) ) : ?>
				<nav class="schrack-fcat__nav" aria-label="<?php esc_attr_e( 'Categorii principale', 'schrack-woocommerce-sync' ); ?>" data-fcat-nav>
					<ul class="schrack-fcat__nav-list">
						<?php foreach ( $terms as $term ) : ?>
							<li class="schrack-fcat__nav-item">
								<a class="schrack-fcat__nav-link" href="<?php echo esc_url( $this->term_link( $term ) ); ?>">
									<?php echo esc_html( $term->name ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the promotional banner for the selected category.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	private function promo_banner( array $settings ): string {
		$term_id = (int) $settings['promo_category_id'];

		if ( $term_id <= 0 ) {
			return '';
		}

		$term = get_term( $term_id, 'product_cat' );

		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		$title    = '' !== $settings['promo_title'] ? $settings['promo_title'] : $term->name;
		$subtitle = '' !== $settings['promo_subtitle'] ? $settings['promo_subtitle'] : wp_strip_all_tags( (string) $term->description );
		$url      = $this->term_link( $term );

		ob_start();
		?>
		<div class="schrack-fcat__promo">
			<div class="schrack-fcat__promo-media">
				<?php echo $this->term_image( $term, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div class="schrack-fcat__promo-copy">
				<?php if ( '' !== $settings['promo_eyebrow'] ) : ?>
					<span class="schrack-fcat__promo-eyebrow"><?php echo esc_html( $settings['promo_eyebrow'] ); ?></span>
				<?php endif; ?>
				<h2 class="schrack-fcat__promo-title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="schrack-fcat__promo-subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $url ) : ?>
					<a class="schrack-fcat__promo-button" href="<?php echo esc_url( $url ); ?>">
						<?php echo esc_html( $settings['promo_button_text'] ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders one product grid per main category.
	 *
	 * @param array<int,WP_Term>  $terms Main category terms.
	 * @param array<string,mixed> $settings Widget settings.
	 */
	private function category_grids( array $terms, array $settings ): string {
		if ( empty( $terms ) || (int) $settings['products_per_category'] <= 0 ) {
			return '';
		}

		$limit   = (int) $settings['products_per_category'];
		$orderby = (string) $settings['products_orderby'];
		$blocks  = array();

		foreach ( $terms as $term ) {
			$products = $this->category_products( $term, $limit, $orderby );

			if ( empty( $products ) ) {
				continue;
			}

			$blocks[] = $this->category_grid_block( $term, $products, $settings );
		}

		if ( empty( $blocks ) ) {
			return '';
		}

		return '<div class="schrack-fcat__grids">' . implode( '', $blocks ) . '</div>';
	}

	/**
	 * Renders a single category heading and its product grid.
	 *
	 * @param WP_Term              $term Category term.
	 * @param array<int,WC_Product> $products Products to display.
	 * @param array<string,mixed>  $settings Widget settings.
	 */
	private function category_grid_block( WP_Term $term, array $products, array $settings ): string {
		$style = sprintf( '--schrack-fcat-grid-columns:%d;', (int) $settings['grid_columns'] );

		ob_start();
		?>
		<div class="schrack-fcat__grid-block">
			<div class="schrack-fcat__grid-head">
				<h3><?php echo esc_html( $term->name ); ?></h3>
				<a class="schrack-fcat__grid-link" href="<?php echo esc_url( $this->term_link( $term ) ); ?>">
					<?php esc_html_e( 'Toate produsele', 'schrack-woocommerce-sync' ); ?>
				</a>
			</div>
			<div class="schrack-fcat__product-grid" style="<?php echo esc_attr( $style ); ?>">
				<?php foreach ( $products as $product ) : ?>
					<?php echo $this->product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns published, visible products for a category.
	 *
	 * @return array<int,WC_Product>
	 */
	private function category_products( WP_Term $term, int $limit, string $orderby ): array {
		if ( ! function_exists( 'wc_get_products' ) || $limit <= 0 ) {
			return array();
		}

		$args = array(
			'status'   => 'publish',
			'limit'    => $limit,
			'category' => array( $term->slug ),
			'return'   => 'objects',
		);

		switch ( $orderby ) {
			case 'popularity':
				$args['orderby'] = 'popularity';
				$args['order']   = 'DESC';
				break;
			case 'price':
				$args['orderby'] = 'price';
				$args['order']   = 'ASC';
				break;
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
		}

		$products = wc_get_products( $args );

		if ( ! is_array( $products ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$products,
				static fn( $product ): bool => $product instanceof WC_Product && $product->is_visible()
			)
		);
	}

	/**
	 * Renders a compact product card.
	 */
	private function product_card( WC_Product $product ): string {
		$link       = $product->get_permalink();
		$price_html = $product->get_price_html();

		ob_start();
		?>
		<article class="schrack-fcat__product-card">
			<a class="schrack-fcat__product-image" href="<?php echo esc_url( $link ); ?>">
				<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) ); ?>
			</a>
			<div class="schrack-fcat__product-body">
				<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
				<?php if ( '' !== $price_html ) : ?>
					<span class="schrack-fcat__product-price"><?php echo wp_kses_post( $price_html ); ?></span>
				<?php endif; ?>
				<a class="schrack-fcat__mini-button" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Vezi produs', 'schrack-woocommerce-sync' ); ?></a>
			</div>
		</article>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns the category archive URL, or an empty string on failure.
	 */
	private function term_link( WP_Term $term ): string {
		$link = get_term_link( $term, 'product_cat' );

		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/**
	 * Renders a category image: manual thumbnail, first product image, or a placeholder.
	 */
	private function term_image( WP_Term $term, string $size ): string {
		$term_id = (int) $term->term_id;

		if ( isset( $this->term_thumbnail_ids[ $term_id ] ) ) {
			$thumbnail_id = $this->term_thumbnail_ids[ $term_id ];
		} else {
			$thumbnail_id = absint( get_term_meta( $term_id, 'thumbnail_id', true ) );

			if ( 0 === $thumbnail_id ) {
				$thumbnail_id = $this->first_product_thumbnail_id( $term );
			}

			$this->term_thumbnail_ids[ $term_id ] = $thumbnail_id;
		}

		if ( $thumbnail_id > 0 ) {
			return wp_get_attachment_image(
				$thumbnail_id,
				$size,
				false,
				array(
					'loading' => 'lazy',
				)
			);
		}

		return wc_placeholder_img( $size );
	}

	/**
	 * Finds the first product thumbnail in a category without loading products.
	 */
	private function first_product_thumbnail_id( WP_Term $term ): int {
		$posts = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'EXISTS',
					),
				),
				'tax_query'              => array(
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => array( (int) $term->term_id ),
						'include_children' => true,
					),
				),
			)
		);

		if ( empty( $posts ) ) {
			return 0;
		}

		return absint( get_post_thumbnail_id( (int) $posts[0] ) );
	}
}
