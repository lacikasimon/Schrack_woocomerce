<?php
/**
 * Header product search renderer.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Header_Search_Renderer {
	public const AJAX_ACTION  = 'schrack_wc_header_search';
	public const NONCE_ACTION = 'schrack_wc_header_search';

	/**
	 * Renders the header search module.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="schrack-header-search"><p>' . esc_html__( 'WooCommerce este necesar pentru cautarea produselor.', 'schrack-woocommerce-sync' ) . '</p></div>';
		}

		$settings   = $this->sanitize_settings( $settings );
		$instance_id = '' !== $instance_id ? $instance_id : wp_unique_id( 'schrack-header-search-' );
		$style      = sprintf(
			'--schrack-header-search-accent:%1$s;--schrack-header-search-action:%2$s;--schrack-header-search-radius:%3$dpx;--schrack-header-search-width:%4$dpx;',
			esc_attr( $settings['accent_color'] ),
			esc_attr( $settings['action_color'] ),
			(int) $settings['radius'],
			(int) $settings['max_width']
		);
		$config     = array(
			'min_chars'    => $settings['min_chars'],
			'max_results'  => $settings['max_results'],
			'show_images'  => $settings['show_images'] ? 'yes' : 'no',
			'show_price'   => $settings['show_price'] ? 'yes' : 'no',
			'show_sku'     => $settings['show_sku'] ? 'yes' : 'no',
			'show_stock'   => $settings['show_stock'] ? 'yes' : 'no',
		);
		$classes    = array( 'schrack-header-search' );

		if ( ! $settings['show_images'] ) {
			$classes[] = 'has-no-images';
		}

		if ( ! $settings['show_price'] && ! $settings['show_stock'] ) {
			$classes[] = 'has-no-meta';
		}

		wp_enqueue_style( 'schrack-wc-header-search' );
		wp_enqueue_script( 'schrack-wc-header-search' );

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
			data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
		>
			<form class="schrack-header-search__form" role="search" method="get" action="<?php echo esc_url( $this->product_search_url() ); ?>">
				<label class="schrack-header-search__label" for="<?php echo esc_attr( $instance_id . '-input' ); ?>">
					<?php esc_html_e( 'Cauta produse', 'schrack-woocommerce-sync' ); ?>
				</label>
				<input
					id="<?php echo esc_attr( $instance_id . '-input' ); ?>"
					class="schrack-header-search__input"
					type="search"
					name="s"
					placeholder="<?php echo esc_attr( $settings['placeholder'] ); ?>"
					autocomplete="off"
					data-header-search-input
					aria-autocomplete="list"
					aria-expanded="false"
				>
				<input type="hidden" name="post_type" value="product">
				<button class="schrack-header-search__button" type="submit">
					<?php echo esc_html( $settings['button_text'] ); ?>
				</button>
			</form>
			<div class="schrack-header-search__results" data-header-search-results hidden></div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders AJAX search results.
	 *
	 * @param array<string,mixed> $settings Public widget settings.
	 * @return array<string,mixed>
	 */
	public function render_results( string $search, array $settings ): array {
		$settings = $this->sanitize_settings( $settings );
		$search   = sanitize_text_field( $search );
		$length   = function_exists( 'mb_strlen' ) ? mb_strlen( trim( $search ) ) : strlen( trim( $search ) );

		if ( '' === trim( $search ) || $length < (int) $settings['min_chars'] ) {
			return array(
				'html' => $this->empty_message(
					sprintf(
						/* translators: %d: minimum search length. */
						__( 'Introdu cel putin %d caractere.', 'schrack-woocommerce-sync' ),
						(int) $settings['min_chars']
					)
				),
				'count' => 0,
			);
		}

		$query = $this->query_products( $search, $settings );

		ob_start();
		?>
		<div class="schrack-header-search__panel" role="listbox">
			<?php if ( ! $query->have_posts() ) : ?>
				<?php echo $this->empty_message( __( 'Nu s-au gasit produse.', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<div class="schrack-header-search__items">
					<?php foreach ( $query->posts as $post ) : ?>
						<?php
						$product = wc_get_product( $post );
						if ( ! $product instanceof WC_Product ) {
							continue;
						}
						?>
						<?php echo $this->result_item( $product, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
				<a class="schrack-header-search__all" href="<?php echo esc_url( $this->product_search_url( $search ) ); ?>">
					<?php esc_html_e( 'Vezi toate rezultatele', 'schrack-woocommerce-sync' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php

		return array(
			'html' => (string) ob_get_clean(),
			'count' => count( $query->posts ),
		);
	}

	/**
	 * Filters the WP query join clause for header search.
	 */
	public function query_join( string $join, WP_Query $query ): string {
		global $wpdb;

		if ( ! $query->get( 'schrack_header_search' ) ) {
			return $join;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$join        .= " LEFT JOIN {$lookup_table} AS schrack_header_lookup ON ({$wpdb->posts}.ID = schrack_header_lookup.product_id)";
		$join        .= " LEFT JOIN {$wpdb->postmeta} AS schrack_header_item_meta ON ({$wpdb->posts}.ID = schrack_header_item_meta.post_id AND schrack_header_item_meta.meta_key = '_schrack_item_number')";
		$join        .= " LEFT JOIN {$wpdb->postmeta} AS schrack_header_ean_meta ON ({$wpdb->posts}.ID = schrack_header_ean_meta.post_id AND schrack_header_ean_meta.meta_key = '_schrack_ean')";

		return $join;
	}

	/**
	 * Filters the WP query where clause for title, text, SKU and Schrack codes.
	 */
	public function query_where( string $where, WP_Query $query ): string {
		global $wpdb;

		if ( ! $query->get( 'schrack_header_search' ) ) {
			return $where;
		}

		$search = trim( (string) $query->get( 'schrack_header_search_term' ) );

		if ( '' === $search ) {
			return $where;
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$where .= $wpdb->prepare(
			" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR schrack_header_lookup.sku LIKE %s OR schrack_header_item_meta.meta_value LIKE %s OR schrack_header_ean_meta.meta_value LIKE %s)",
			$like,
			$like,
			$like,
			$like,
			$like,
			$like
		);

		return $where;
	}

	/**
	 * Keeps joined results unique.
	 */
	public function query_distinct( string $distinct, WP_Query $query ): string {
		if ( ! $query->get( 'schrack_header_search' ) ) {
			return $distinct;
		}

		return 'DISTINCT';
	}

	/**
	 * Runs the product query.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function query_products( string $search, array $settings ): WP_Query {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'ignore_sticky_posts'    => true,
			'posts_per_page'         => (int) $settings['max_results'],
			'no_found_rows'          => true,
			'cache_results'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'schrack_header_search'      => true,
			'schrack_header_search_term' => $search,
		);

		$visibility_terms = function_exists( 'wc_get_product_visibility_term_ids' ) ? wc_get_product_visibility_term_ids() : array();

		if ( ! empty( $visibility_terms['exclude-from-catalog'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'term_taxonomy_id',
					'terms'    => array( (int) $visibility_terms['exclude-from-catalog'] ),
					'operator' => 'NOT IN',
				),
			);
		}

		add_filter( 'posts_join', array( $this, 'query_join' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'query_where' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'query_distinct' ), 10, 2 );

		$query = new WP_Query( $args );

		remove_filter( 'posts_join', array( $this, 'query_join' ), 10 );
		remove_filter( 'posts_where', array( $this, 'query_where' ), 10 );
		remove_filter( 'posts_distinct', array( $this, 'query_distinct' ), 10 );

		return $query;
	}

	/**
	 * Renders one result item.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function result_item( WC_Product $product, array $settings ): string {
		$item_number = $this->meta_text( $product, '_schrack_item_number' );
		$sku         = $product->get_sku();
		$code        = '' !== $item_number ? $item_number : $sku;

		ob_start();
		?>
		<a class="schrack-header-search__item" href="<?php echo esc_url( $product->get_permalink() ); ?>" role="option">
			<?php if ( $settings['show_images'] ) : ?>
				<span class="schrack-header-search__image">
					<?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			<?php endif; ?>
			<span class="schrack-header-search__body">
				<span class="schrack-header-search__title"><?php echo esc_html( $product->get_name() ); ?></span>
				<?php if ( $settings['show_sku'] && '' !== $code ) : ?>
					<span class="schrack-header-search__sku"><?php echo esc_html( $code ); ?></span>
				<?php endif; ?>
			</span>
			<?php if ( $settings['show_price'] || $settings['show_stock'] ) : ?>
				<span class="schrack-header-search__meta">
					<?php if ( $settings['show_price'] ) : ?>
						<span class="schrack-header-search__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					<?php endif; ?>
					<?php if ( $settings['show_stock'] ) : ?>
						<span class="schrack-header-search__stock <?php echo $product->is_in_stock() ? 'is-in-stock' : 'is-out-of-stock'; ?>">
							<?php echo $product->is_in_stock() ? esc_html__( 'In stoc', 'schrack-woocommerce-sync' ) : esc_html__( 'Stoc epuizat', 'schrack-woocommerce-sync' ); ?>
						</span>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</a>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders an empty message.
	 */
	private function empty_message( string $message ): string {
		return '<div class="schrack-header-search__empty">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Returns product search URL.
	 */
	private function product_search_url( string $search = '' ): string {
		$shop_url = get_permalink( wc_get_page_id( 'shop' ) );

		if ( ! is_string( $shop_url ) || '' === $shop_url ) {
			$shop_url = home_url( '/' );
		}

		if ( '' === $search ) {
			return $shop_url;
		}

		return add_query_arg(
			array(
				's'         => $search,
				'post_type' => 'product',
			),
			$shop_url
		);
	}

	/**
	 * Sanitizes renderer settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private function sanitize_settings( array $settings ): array {
		return array(
			'placeholder'  => sanitize_text_field( (string) ( $settings['placeholder'] ?? __( 'Cauta produse...', 'schrack-woocommerce-sync' ) ) ),
			'button_text'  => sanitize_text_field( (string) ( $settings['button_text'] ?? __( 'Cauta', 'schrack-woocommerce-sync' ) ) ),
			'min_chars'    => max( 1, min( 5, absint( $settings['min_chars'] ?? 2 ) ) ),
			'max_results'  => max( 3, min( 12, absint( $settings['max_results'] ?? 6 ) ) ),
			'max_width'    => max( 240, min( 720, $this->slider_size( $settings['max_width'] ?? 460, 240, 720 ) ) ),
			'show_images'  => $this->truthy( $settings['show_images'] ?? 'yes' ),
			'show_price'   => $this->truthy( $settings['show_price'] ?? 'yes' ),
			'show_sku'     => $this->truthy( $settings['show_sku'] ?? 'yes' ),
			'show_stock'   => $this->truthy( $settings['show_stock'] ?? 'yes' ),
			'accent_color' => sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#135e96' ) ) ?: '#135e96',
			'action_color' => sanitize_hex_color( (string) ( $settings['action_color'] ?? '#b32d2e' ) ) ?: '#b32d2e',
			'radius'       => $this->slider_size( $settings['radius'] ?? 8, 0, 12 ),
		);
	}

	/**
	 * Returns sanitized product meta text.
	 */
	private function meta_text( WC_Product $product, string $key ): string {
		$value = $product->get_meta( $key, true );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Sanitizes Elementor slider values.
	 */
	private function slider_size( mixed $value, int $min, int $max ): int {
		if ( is_array( $value ) && isset( $value['size'] ) ) {
			$value = $value['size'];
		}

		return max( $min, min( $max, absint( $value ) ) );
	}

	/**
	 * Returns whether a setting is enabled.
	 */
	private function truthy( mixed $value ): bool {
		return in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true );
	}
}
