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
			'show_stock'   => $settings['show_stock'] ? 'yes' : 'no',
			'enable_fuzzy' => $settings['enable_fuzzy'] ? 'yes' : 'no',
			'fuzzy_pool'   => $settings['fuzzy_pool'],
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
					<?php esc_html_e( 'Caută produse', 'schrack-woocommerce-sync' ); ?>
				</label>
				<span class="schrack-header-search__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" focusable="false">
						<circle cx="11" cy="11" r="7"></circle>
						<path d="m16.5 16.5 4 4"></path>
					</svg>
				</span>
				<input
					id="<?php echo esc_attr( $instance_id . '-input' ); ?>"
					class="schrack-header-search__input"
					type="search"
					name="search"
					placeholder="<?php echo esc_attr( $settings['placeholder'] ); ?>"
					autocomplete="off"
					data-header-search-input
					role="combobox"
					aria-autocomplete="list"
					aria-controls="<?php echo esc_attr( $instance_id . '-results' ); ?>"
					aria-expanded="false"
					aria-haspopup="listbox"
				>
				<button class="schrack-header-search__button" type="submit">
					<span class="schrack-header-search__button-text"><?php echo esc_html( $settings['button_text'] ); ?></span>
					<span class="schrack-header-search__spinner" aria-hidden="true"></span>
				</button>
			</form>
			<div
				id="<?php echo esc_attr( $instance_id . '-results' ); ?>"
				class="schrack-header-search__results"
				role="listbox"
				aria-label="<?php esc_attr_e( 'Rezultate cautare produse', 'schrack-woocommerce-sync' ); ?>"
				data-header-search-results
				hidden
			></div>
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

		$products = $this->search_products( $search, $settings );

		ob_start();
		?>
		<div class="schrack-header-search__panel">
			<?php if ( empty( $products ) ) : ?>
				<?php echo $this->empty_message( __( 'Nu s-au gasit produse.', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<div class="schrack-header-search__items">
					<?php foreach ( $products as $product ) : ?>
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
			'count' => count( $products ),
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
		$join        .= " LEFT JOIN {$wpdb->postmeta} AS telesystem_header_item_meta ON ({$wpdb->posts}.ID = telesystem_header_item_meta.post_id AND telesystem_header_item_meta.meta_key = '_telesystem_item_number')";
		$join        .= " LEFT JOIN {$wpdb->postmeta} AS telesystem_header_ean_meta ON ({$wpdb->posts}.ID = telesystem_header_ean_meta.post_id AND telesystem_header_ean_meta.meta_key = '_telesystem_ean')";

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
			" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR schrack_header_lookup.sku LIKE %s OR schrack_header_item_meta.meta_value LIKE %s OR schrack_header_ean_meta.meta_value LIKE %s OR telesystem_header_item_meta.meta_value LIKE %s OR telesystem_header_ean_meta.meta_value LIKE %s)",
			$like,
			$like,
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
	 * Searches products with exact matching first, then fuzzy-ranked suggestions.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<int,WC_Product>
	 */
	private function search_products( string $search, array $settings ): array {
		$limit       = (int) $settings['max_results'];
		$query       = $this->query_products( $search, $settings );
		$products    = $this->products_from_posts( $query->posts );
		$product_ids = array();

		foreach ( $products as $product ) {
			$product_ids[ $product->get_id() ] = true;
		}

		if ( count( $products ) >= $limit || ! $settings['enable_fuzzy'] ) {
			return array_slice( $products, 0, $limit );
		}

		foreach ( $this->fuzzy_products( $search, $settings, $product_ids ) as $product ) {
			$product_id = $product->get_id();

			if ( isset( $product_ids[ $product_id ] ) ) {
				continue;
			}

			$products[] = $product;
			$product_ids[ $product_id ] = true;

			if ( count( $products ) >= $limit ) {
				break;
			}
		}

		return $products;
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
	 * Converts queried posts to visible WooCommerce products.
	 *
	 * @param array<int,WP_Post|int> $posts Posts or IDs.
	 * @return array<int,WC_Product>
	 */
	private function products_from_posts( array $posts ): array {
		$products = array();

		foreach ( $posts as $post ) {
			$product = wc_get_product( $post );

			if ( $product instanceof WC_Product && $this->is_product_search_visible( $product ) ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Finds fuzzy-ranked products from a broader candidate pool.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<int,bool>    $excluded_ids Product IDs already returned by exact search.
	 * @return array<int,WC_Product>
	 */
	private function fuzzy_products( string $search, array $settings, array $excluded_ids ): array {
		$ranked = array();

		foreach ( $this->fuzzy_candidate_ids( $search, $settings ) as $product_id ) {
			if ( isset( $excluded_ids[ $product_id ] ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product || ! $this->is_product_search_visible( $product ) ) {
				continue;
			}

			$score = $this->fuzzy_score( $search, $product );

			if ( $score <= 0 ) {
				continue;
			}

			$ranked[] = array(
				'product' => $product,
				'score'   => $score,
				'name'    => $product->get_name(),
			);
		}

		usort(
			$ranked,
			static function ( array $left, array $right ): int {
				if ( $left['score'] === $right['score'] ) {
					return strcasecmp( (string) $left['name'], (string) $right['name'] );
				}

				return (int) $right['score'] <=> (int) $left['score'];
			}
		);

		return array_values(
			array_map(
				static fn( array $item ): WC_Product => $item['product'],
				array_slice( $ranked, 0, (int) $settings['max_results'] )
			)
		);
	}

	/**
	 * Loads fuzzy candidate product IDs with broad prefix matching.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<int,int>
	 */
	private function fuzzy_candidate_ids( string $search, array $settings ): array {
		global $wpdb;

		$prefixes = $this->fuzzy_prefixes( $search );

		if ( empty( $prefixes ) ) {
			return array();
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$where_parts  = array();
		$params       = array();

		foreach ( $prefixes as $prefix ) {
			$like = '%' . $wpdb->esc_like( $prefix ) . '%';
			$where_parts[] = "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR schrack_fuzzy_lookup.sku LIKE %s OR schrack_fuzzy_item_meta.meta_value LIKE %s OR schrack_fuzzy_ean_meta.meta_value LIKE %s OR telesystem_fuzzy_item_meta.meta_value LIKE %s OR telesystem_fuzzy_ean_meta.meta_value LIKE %s)";

			for ( $i = 0; $i < 8; ++$i ) {
				$params[] = $like;
			}
		}

		$params[] = (int) $settings['fuzzy_pool'];
		$sql      = "
			SELECT DISTINCT {$wpdb->posts}.ID
			FROM {$wpdb->posts}
			LEFT JOIN {$lookup_table} AS schrack_fuzzy_lookup ON ({$wpdb->posts}.ID = schrack_fuzzy_lookup.product_id)
			LEFT JOIN {$wpdb->postmeta} AS schrack_fuzzy_item_meta ON ({$wpdb->posts}.ID = schrack_fuzzy_item_meta.post_id AND schrack_fuzzy_item_meta.meta_key = '_schrack_item_number')
			LEFT JOIN {$wpdb->postmeta} AS schrack_fuzzy_ean_meta ON ({$wpdb->posts}.ID = schrack_fuzzy_ean_meta.post_id AND schrack_fuzzy_ean_meta.meta_key = '_schrack_ean')
			LEFT JOIN {$wpdb->postmeta} AS telesystem_fuzzy_item_meta ON ({$wpdb->posts}.ID = telesystem_fuzzy_item_meta.post_id AND telesystem_fuzzy_item_meta.meta_key = '_telesystem_item_number')
			LEFT JOIN {$wpdb->postmeta} AS telesystem_fuzzy_ean_meta ON ({$wpdb->posts}.ID = telesystem_fuzzy_ean_meta.post_id AND telesystem_fuzzy_ean_meta.meta_key = '_telesystem_ean')
			WHERE {$wpdb->posts}.post_type = 'product'
				AND {$wpdb->posts}.post_status = 'publish'
				AND (" . implode( ' OR ', $where_parts ) . ")
			ORDER BY {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_title ASC
			LIMIT %d
		";

		$prepared = $wpdb->prepare( $sql, $params );

		if ( ! is_string( $prepared ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $wpdb->get_col( $prepared ) ) ) );
	}

	/**
	 * Builds candidate prefixes for the fuzzy pool query.
	 *
	 * @return array<int,string>
	 */
	private function fuzzy_prefixes( string $search ): array {
		$prefixes = array();

		foreach ( $this->search_tokens( $search ) as $token ) {
			$length = strlen( $token );

			if ( $length >= 4 ) {
				$prefixes[] = substr( $token, 0, 4 );

				for ( $index = 0; $index <= $length - 3; ++$index ) {
					$prefixes[] = substr( $token, $index, 3 );
				}
			} elseif ( $length >= 3 ) {
				$prefixes[] = $token;
			}

			if ( $length >= 2 ) {
				$prefixes[] = $token;
			}
		}

		return array_slice( array_values( array_unique( array_filter( $prefixes ) ) ), 0, 8 );
	}

	/**
	 * Calculates a fuzzy score for one product.
	 */
	private function fuzzy_score( string $search, WC_Product $product ): int {
		$needle = $this->normalize_search_text( $search );
		$fields = $this->product_search_fields( $product );
		$haystack = $this->normalize_search_text( implode( ' ', $fields ) );

		if ( '' === $needle || '' === $haystack ) {
			return 0;
		}

		$position = strpos( $haystack, $needle );

		if ( false !== $position ) {
			return 900 - min( 250, (int) $position );
		}

		$needle_tokens = $this->search_tokens( $needle );
		$hay_tokens    = $this->search_tokens( $haystack );
		$score         = 0;

		foreach ( $needle_tokens as $needle_token ) {
			$best = 0;

			foreach ( $hay_tokens as $hay_token ) {
				$best = max( $best, $this->token_score( $needle_token, $hay_token ) );
			}

			$score += $best;
		}

		return $score;
	}

	/**
	 * Scores two normalized tokens.
	 */
	private function token_score( string $needle, string $candidate ): int {
		$needle_length    = strlen( $needle );
		$candidate_length = strlen( $candidate );

		if ( $needle === $candidate ) {
			return 260;
		}

		if ( $needle_length < 2 || $candidate_length < 2 ) {
			return 0;
		}

		if ( 0 === strpos( $candidate, $needle ) ) {
			return max( 130, 220 - abs( $candidate_length - $needle_length ) * 5 );
		}

		if ( false !== strpos( $candidate, $needle ) ) {
			return 180;
		}

		if ( $needle_length < 3 || $candidate_length < 3 ) {
			return 0;
		}

		$max_length = max( $needle_length, $candidate_length );
		$distance   = levenshtein( $needle, $candidate );
		$allowed    = max( 1, (int) floor( $max_length * 0.34 ) );

		if ( $distance <= $allowed ) {
			return max( 40, 160 - $distance * 22 + min( 35, $max_length ) );
		}

		similar_text( $needle, $candidate, $percent );

		if ( $percent >= 72 ) {
			return (int) $percent;
		}

		return 0;
	}

	/**
	 * Returns searchable product text fields.
	 *
	 * @return array<int,string>
	 */
	private function product_search_fields( WC_Product $product ): array {
		return array_filter(
			array(
				$product->get_name(),
				$product->get_sku(),
				$this->meta_text( $product, '_schrack_item_number' ),
				$this->meta_text( $product, '_schrack_ean' ),
				$this->meta_text( $product, '_telesystem_item_number' ),
				$this->meta_text( $product, '_telesystem_ean' ),
			),
			static fn( string $value ): bool => '' !== $value
		);
	}

	/**
	 * Returns whether a product should appear in search suggestions.
	 */
	private function is_product_search_visible( WC_Product $product ): bool {
		if ( method_exists( $product, 'get_status' ) && 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalizes text for fuzzy comparisons.
	 */
	private function normalize_search_text( string $text ): string {
		$text = wp_strip_all_tags( $text );

		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}

		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );

		return trim( is_string( $text ) ? $text : '' );
	}

	/**
	 * Splits normalized search text into unique tokens.
	 *
	 * @return array<int,string>
	 */
	private function search_tokens( string $text ): array {
		$text  = $this->normalize_search_text( $text );
		$parts = preg_split( '/\s+/', $text );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					$parts,
					static fn( string $part ): bool => strlen( $part ) >= 2
				)
			)
		);
	}

	/**
	 * Renders one result item.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function result_item( WC_Product $product, array $settings ): string {
		$stock_badge = Schrack_Stock_Label::badge( $product );

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
			</span>
			<?php if ( $settings['show_price'] || $settings['show_stock'] ) : ?>
				<span class="schrack-header-search__meta">
					<?php if ( $settings['show_price'] ) : ?>
						<span class="schrack-header-search__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					<?php endif; ?>
					<?php if ( $settings['show_stock'] ) : ?>
						<span class="schrack-header-search__stock <?php echo esc_attr( $stock_badge['class'] ); ?>">
							<?php echo esc_html( $stock_badge['text'] ); ?>
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
				'search' => $search,
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
			'placeholder'  => sanitize_text_field( (string) ( $settings['placeholder'] ?? __( 'Caută produse, coduri, categorii...', 'schrack-woocommerce-sync' ) ) ),
			'button_text'  => sanitize_text_field( (string) ( $settings['button_text'] ?? __( 'Caută', 'schrack-woocommerce-sync' ) ) ),
			'min_chars'    => max( 3, min( 5, absint( $settings['min_chars'] ?? 3 ) ) ),
			'max_results'  => max( 3, min( 12, absint( $settings['max_results'] ?? 6 ) ) ),
			'max_width'    => max( 240, min( 920, $this->slider_size( $settings['max_width'] ?? 820, 240, 920 ) ) ),
			'show_images'  => $this->truthy( $settings['show_images'] ?? 'yes' ),
			'show_price'   => $this->truthy( $settings['show_price'] ?? 'yes' ),
			'show_stock'   => $this->truthy( $settings['show_stock'] ?? 'yes' ),
			'enable_fuzzy' => $this->truthy( $settings['enable_fuzzy'] ?? 'yes' ),
			'fuzzy_pool'   => max( 40, min( 240, absint( $settings['fuzzy_pool'] ?? 120 ) ) ),
			'accent_color' => sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#102033' ) ) ?: '#102033',
			'action_color' => sanitize_hex_color( (string) ( $settings['action_color'] ?? '#102033' ) ) ?: '#102033',
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
