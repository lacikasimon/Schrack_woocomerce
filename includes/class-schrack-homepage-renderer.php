<?php
/**
 * Elementor homepage category explorer renderer.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Homepage_Renderer {
	/**
	 * Per-request category thumbnail cache.
	 *
	 * @var array<int,int>
	 */
	private array $term_thumbnail_ids = array();

	/**
	 * Renders the homepage module.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="schrack-home"><p>' . esc_html__( 'WooCommerce este necesar pentru acest modul.', 'schrack-woocommerce-sync' ) . '</p></div>';
		}

		$settings       = $this->sanitize_settings( $settings );
		$terms          = $this->catalog_terms( (int) $settings['category_limit'] );
		$featured_terms = $this->featured_terms( $terms, (int) $settings['featured_category_count'] );
		$tree           = $this->term_tree( $terms );

		wp_enqueue_style( 'schrack-wc-homepage' );
		wp_enqueue_script( 'schrack-wc-homepage' );

		$style = sprintf(
			'--schrack-home-accent:%1$s;--schrack-home-action:%2$s;--schrack-home-radius:%3$dpx;--schrack-home-width:%4$dpx;',
			esc_attr( $settings['accent_color'] ),
			esc_attr( $settings['action_color'] ),
			(int) $settings['radius'],
			(int) $settings['max_width']
		);

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( '' !== $instance_id ? 'schrack-home-' . $instance_id : 'schrack-home' ); ?>"
			class="schrack-home"
			style="<?php echo esc_attr( $style ); ?>"
			data-schrack-home
		>
			<div class="schrack-home__inner">
				<div class="schrack-home__hero">
					<div class="schrack-home__intro">
						<?php if ( '' !== $settings['eyebrow'] ) : ?>
							<div class="schrack-home__eyebrow"><?php echo esc_html( $settings['eyebrow'] ); ?></div>
						<?php endif; ?>

						<?php if ( '' !== $settings['title'] ) : ?>
							<h1 class="schrack-home__title"><?php echo esc_html( $settings['title'] ); ?></h1>
						<?php endif; ?>

						<?php if ( '' !== $settings['subtitle'] ) : ?>
							<p class="schrack-home__subtitle"><?php echo esc_html( $settings['subtitle'] ); ?></p>
						<?php endif; ?>

						<div class="schrack-home__actions">
							<a class="schrack-home__button" href="<?php echo esc_url( $settings['shop_url'] ); ?>">
								<?php echo esc_html( $settings['button_text'] ); ?>
							</a>
							<?php if ( '' !== $settings['company_meta'] ) : ?>
								<span class="schrack-home__meta"><?php echo esc_html( $settings['company_meta'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<?php echo $this->visual_gallery( $featured_terms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div class="schrack-home__facts" aria-label="<?php esc_attr_e( 'Informatii companie', 'schrack-woocommerce-sync' ); ?>">
					<?php foreach ( $this->facts() as $fact ) : ?>
						<div class="schrack-home__fact">
							<strong><?php echo esc_html( $fact['label'] ); ?></strong>
							<span><?php echo esc_html( $fact['value'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="schrack-home__catalog">
					<div class="schrack-home__tree-panel">
						<div class="schrack-home__panel-head">
							<span><?php esc_html_e( 'Exploreaza categoriile', 'schrack-woocommerce-sync' ); ?></span>
							<small><?php echo esc_html( sprintf( __( '%d categorii', 'schrack-woocommerce-sync' ), count( $terms ) ) ); ?></small>
						</div>

						<label class="schrack-home__search">
							<span><?php esc_html_e( 'Cauta in arbore', 'schrack-woocommerce-sync' ); ?></span>
							<input type="search" placeholder="<?php esc_attr_e( 'Nume categorie', 'schrack-woocommerce-sync' ); ?>" data-home-category-search>
						</label>

						<div class="schrack-home__tree" data-home-category-tree>
							<?php echo $this->render_tree( $tree, 0, 0, $settings['show_counts'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="schrack-home__tree-empty" data-home-category-empty hidden>
							<?php esc_html_e( 'Nu s-au gasit categorii pentru cautarea introdusa.', 'schrack-woocommerce-sync' ); ?>
						</div>
					</div>

					<div class="schrack-home__catalog-main">
						<?php if ( 'yes' === $settings['show_featured_categories'] ) : ?>
							<?php echo $this->featured_categories( $featured_terms, $settings['show_counts'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_services'] ) : ?>
							<?php echo $this->service_cards(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Normalizes widget settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,string|int>
	 */
	private function sanitize_settings( array $settings ): array {
		$defaults = array(
			'eyebrow'                 => __( 'GENE SYS SECURITY SRL', 'schrack-woocommerce-sync' ),
			'title'                   => __( 'Catalog tehnic pentru instalatii electrice si securitate', 'schrack-woocommerce-sync' ),
			'subtitle'                => __( 'Instalatii electrice, sisteme fotovoltaice, securitate si supraveghere pentru constructii civile si industriale.', 'schrack-woocommerce-sync' ),
			'company_meta'            => __( 'Satu Mare - CUI RO 38322763', 'schrack-woocommerce-sync' ),
			'button_text'             => __( 'Mergi la magazin', 'schrack-woocommerce-sync' ),
			'shop_url'                => $this->default_shop_url(),
			'category_limit'          => 220,
			'featured_category_count' => 6,
			'show_counts'              => 'yes',
			'show_services'            => 'yes',
			'show_featured_categories' => 'yes',
			'accent_color'             => '#135e96',
			'action_color'             => '#b32d2e',
			'max_width'                => 1180,
			'radius'                   => 8,
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'eyebrow', 'title', 'subtitle', 'company_meta', 'button_text' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_counts', 'show_services', 'show_featured_categories' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		$settings['shop_url']                = esc_url_raw( (string) $settings['shop_url'] );
		$settings['category_limit']          = max( 20, min( 600, absint( $settings['category_limit'] ) ) );
		$settings['featured_category_count'] = max( 0, min( 8, absint( $settings['featured_category_count'] ) ) );
		$settings['accent_color']            = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['action_color']            = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['max_width']               = max( 900, min( 1440, absint( $settings['max_width'] ) ) );
		$settings['radius']                  = max( 0, min( 8, absint( $settings['radius'] ) ) );

		if ( '' === $settings['shop_url'] ) {
			$settings['shop_url'] = $this->default_shop_url();
		}

		return $settings;
	}

	/**
	 * Returns the WooCommerce shop URL.
	 */
	private function default_shop_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop_url = wc_get_page_permalink( 'shop' );

			if ( is_string( $shop_url ) && '' !== $shop_url ) {
				return $shop_url;
			}
		}

		return home_url( '/shop/' );
	}

	/**
	 * Loads product category terms with a conservative cap for large stores.
	 *
	 * @return array<int,WP_Term>
	 */
	private function catalog_terms( int $limit ): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => $limit,
				'orderby'    => 'name',
				'order'      => 'ASC',
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
	 * Groups category terms by parent.
	 *
	 * @param array<int,WP_Term> $terms Category terms.
	 * @return array<int,array<int,WP_Term>>
	 */
	private function term_tree( array $terms ): array {
		$tree = array();
		$ids  = array();

		foreach ( $terms as $term ) {
			$ids[ (int) $term->term_id ] = true;
		}

		foreach ( $terms as $term ) {
			$parent = isset( $ids[ (int) $term->parent ] ) ? (int) $term->parent : 0;

			if ( ! isset( $tree[ $parent ] ) ) {
				$tree[ $parent ] = array();
			}

			$tree[ $parent ][] = $term;
		}

		return $tree;
	}

	/**
	 * Selects terms for the visual cards.
	 *
	 * @param array<int,WP_Term> $terms Category terms.
	 * @return array<int,WP_Term>
	 */
	private function featured_terms( array $terms, int $limit ): array {
		if ( 0 === $limit || empty( $terms ) ) {
			return array();
		}

		$candidates = array_values(
			array_filter(
				$terms,
				static fn( WP_Term $term ): bool => 0 === (int) $term->parent && (int) $term->count > 0
			)
		);

		if ( empty( $candidates ) ) {
			$candidates = array_values(
				array_filter(
					$terms,
					static fn( WP_Term $term ): bool => (int) $term->count > 0
				)
			);
		}

		if ( empty( $candidates ) ) {
			$candidates = $terms;
		}

		usort(
			$candidates,
			static function ( WP_Term $a, WP_Term $b ): int {
				return (int) $b->count <=> (int) $a->count;
			}
		);

		return array_slice( $candidates, 0, $limit );
	}

	/**
	 * Renders the hero product/category visual gallery.
	 *
	 * @param array<int,WP_Term> $terms Featured terms.
	 */
	private function visual_gallery( array $terms ): string {
		if ( empty( $terms ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="schrack-home__visual" aria-label="<?php esc_attr_e( 'Categorii recomandate', 'schrack-woocommerce-sync' ); ?>">
			<?php foreach ( array_slice( $terms, 0, 4 ) as $index => $term ) : ?>
				<figure class="schrack-home__visual-card <?php echo 0 === $index ? 'is-large' : ''; ?>">
					<?php echo $this->term_image( $term, 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<figcaption><?php echo esc_html( $term->name ); ?></figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the category tree.
	 *
	 * @param array<int,array<int,WP_Term>> $tree Category tree.
	 */
	private function render_tree( array $tree, int $parent_id, int $depth, string $show_counts, string $path = '' ): string {
		if ( empty( $tree[ $parent_id ] ) ) {
			if ( 0 === $depth ) {
				return '<div class="schrack-home__empty">' . esc_html__( 'Nu exista categorii de produse.', 'schrack-woocommerce-sync' ) . '</div>';
			}

			return '';
		}

		ob_start();
		?>
		<ul class="schrack-home__tree-list <?php echo 0 === $depth ? 'is-root' : ''; ?>">
			<?php foreach ( $tree[ $parent_id ] as $index => $term ) : ?>
				<?php
				$children    = ! empty( $tree[ (int) $term->term_id ] );
				$expanded    = 0 === $depth && $index < 2 && $children;
				$link        = get_term_link( $term );
				$term_path   = trim( $path . ' ' . $term->name );
				$search_text = sanitize_text_field( wp_strip_all_tags( $term_path . ' ' . $term->slug ) );
				?>
				<li
					class="schrack-home__tree-node <?php echo $children ? 'has-children' : ''; ?> <?php echo $expanded ? 'is-expanded' : ''; ?>"
					data-home-category-node
					data-search-text="<?php echo esc_attr( $search_text ); ?>"
				>
					<div class="schrack-home__tree-row" style="<?php echo esc_attr( '--schrack-home-depth:' . $depth . ';' ); ?>">
						<?php if ( $children ) : ?>
							<button
								class="schrack-home__tree-toggle"
								type="button"
								aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>"
								aria-label="<?php echo esc_attr( sprintf( __( 'Deschide categoria %s', 'schrack-woocommerce-sync' ), $term->name ) ); ?>"
								data-home-category-toggle
							></button>
						<?php else : ?>
							<span class="schrack-home__tree-spacer" aria-hidden="true"></span>
						<?php endif; ?>

						<?php if ( ! is_wp_error( $link ) ) : ?>
							<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $term->name ); ?></a>
						<?php else : ?>
							<span><?php echo esc_html( $term->name ); ?></span>
						<?php endif; ?>

						<?php if ( 'yes' === $show_counts ) : ?>
							<small><?php echo esc_html( (string) (int) $term->count ); ?></small>
						<?php endif; ?>
					</div>

					<?php if ( $children ) : ?>
						<div class="schrack-home__tree-children" <?php echo $expanded ? '' : 'hidden'; ?>>
							<?php echo $this->render_tree( $tree, (int) $term->term_id, $depth + 1, $show_counts, $term_path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders featured category cards.
	 *
	 * @param array<int,WP_Term> $terms Featured terms.
	 */
	private function featured_categories( array $terms, string $show_counts ): string {
		if ( empty( $terms ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="schrack-home__section-head">
			<div>
				<span><?php esc_html_e( 'Categorii populare', 'schrack-woocommerce-sync' ); ?></span>
				<p><?php esc_html_e( 'Porneste rapid din categoriile principale ale magazinului.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
		</div>

		<div class="schrack-home__category-grid">
			<?php foreach ( $terms as $term ) : ?>
				<?php $link = get_term_link( $term ); ?>
				<a class="schrack-home__category-card" href="<?php echo esc_url( is_wp_error( $link ) ? '#' : $link ); ?>">
					<div class="schrack-home__category-image">
						<?php echo $this->term_image( $term, 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<div class="schrack-home__category-body">
						<strong><?php echo esc_html( $term->name ); ?></strong>
						<?php if ( '' !== $term->description ) : ?>
							<span><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $term->description ), 14, '...' ) ); ?></span>
						<?php else : ?>
							<span><?php esc_html_e( 'Produse tehnice pentru proiecte electrice si industriale.', 'schrack-woocommerce-sync' ); ?></span>
						<?php endif; ?>
						<?php if ( 'yes' === $show_counts ) : ?>
							<em><?php echo esc_html( sprintf( __( '%d produse', 'schrack-woocommerce-sync' ), (int) $term->count ) ); ?></em>
						<?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders service cards based on the public Syshub company profile.
	 */
	private function service_cards(): string {
		$services = array(
			array(
				'name'    => __( 'Instalatii electrice', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Distributie, tablouri, iluminat, prize, circuite dedicate, verificari si documentatie pentru receptie.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/instalatii-electrice',
				'image'   => 'https://syshub.ro/assets/electrical-installation-MhzlRr5w.jpg',
				'variant' => 'electric',
			),
			array(
				'name'    => __( 'Sisteme fotovoltaice', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Sisteme on-grid, off-grid si hibride, analiza consum, dimensionare, monitorizare si mentenanta.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/fotovoltaice',
				'image'   => 'https://syshub.ro/assets/photovoltaic-panels-cA7PyFUI.jpg',
				'variant' => 'solar',
			),
			array(
				'name'    => __( 'Securitate si CCTV', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Plan camere, cablare, NVR, stocare, acces remote, detectie la efractie si upgrade-uri.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/securitate-cctv',
				'image'   => 'https://syshub.ro/assets/security-cctv-B6nSZA5n.jpg',
				'variant' => 'security',
			),
		);

		ob_start();
		?>
		<div class="schrack-home__section-head">
			<div>
				<span><?php esc_html_e( 'Servicii Gene Sys Security', 'schrack-woocommerce-sync' ); ?></span>
				<p><?php esc_html_e( 'Context rapid din pagina principala Syshub pentru vizitatorii magazinului.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
		</div>

		<div class="schrack-home__service-grid">
			<?php foreach ( $services as $service ) : ?>
				<a class="schrack-home__service-card is-<?php echo esc_attr( $service['variant'] ); ?>" href="<?php echo esc_url( $service['url'] ); ?>">
					<span class="schrack-home__service-visual">
						<img src="<?php echo esc_url( $service['image'] ); ?>" alt="<?php echo esc_attr( $service['name'] ); ?>" loading="lazy">
					</span>
					<strong><?php echo esc_html( $service['name'] ); ?></strong>
					<p><?php echo esc_html( $service['text'] ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns company facts from the public Syshub profile.
	 *
	 * @return array<int,array{label:string,value:string}>
	 */
	private function facts(): array {
		return array(
			array(
				'label' => __( 'Localitate', 'schrack-woocommerce-sync' ),
				'value' => __( 'Satu Mare, Romania', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( 'Companie', 'schrack-woocommerce-sync' ),
				'value' => __( 'GENE SYS SECURITY SRL', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( 'Telefon', 'schrack-woocommerce-sync' ),
				'value' => '+40 749 235 958',
			),
			array(
				'label' => __( 'Proiecte', 'schrack-woocommerce-sync' ),
				'value' => __( 'Civile si industriale', 'schrack-woocommerce-sync' ),
			),
		);
	}

	/**
	 * Returns an image for a category, with a cheap product-thumbnail fallback.
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
