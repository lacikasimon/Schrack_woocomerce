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

						<?php if ( '' !== $settings['support_text'] ) : ?>
							<p class="schrack-home__support"><?php echo esc_html( $settings['support_text'] ); ?></p>
						<?php endif; ?>

						<?php echo $this->hero_benefits(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

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

				<?php if ( 'yes' === $settings['show_project_paths'] ) : ?>
					<?php echo $this->project_paths( $terms, $settings['shop_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_shop_bridge'] ) : ?>
					<?php echo $this->shop_bridge( $terms, $settings['shop_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_solution_spotlight'] ) : ?>
					<?php echo $this->solution_spotlight( $featured_terms, $settings['show_counts'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

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

				<?php if ( 'yes' === $settings['show_process'] ) : ?>
					<?php echo $this->process_steps(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_references'] ) : ?>
					<?php echo $this->project_references(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_final_cta'] ) : ?>
					<?php echo $this->final_cta( $settings['shop_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
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
			'title'                   => __( 'Magazin tehnic pentru proiecte electrice, fotovoltaice si securitate', 'schrack-woocommerce-sync' ),
			'subtitle'                => __( 'Alege produse pentru instalatii electrice, sisteme fotovoltaice, CCTV, detectie la efractie si mentenanta, cu repere clare pentru proiecte civile si industriale.', 'schrack-woocommerce-sync' ),
			'support_text'            => __( 'Syshub aduce contextul de proiectare, executie si documentatie; magazinul te ajuta sa pornesti rapid din categoriile potrivite, de la lista de materiale pana la ofertare.', 'schrack-woocommerce-sync' ),
			'company_meta'            => __( 'Satu Mare - CUI RO 38322763', 'schrack-woocommerce-sync' ),
			'button_text'             => __( 'Vezi catalogul de produse', 'schrack-woocommerce-sync' ),
			'shop_url'                => $this->default_shop_url(),
			'category_limit'          => 220,
			'featured_category_count' => 6,
			'show_counts'              => 'yes',
			'show_services'            => 'yes',
			'show_project_paths'       => 'yes',
			'show_shop_bridge'         => 'yes',
			'show_solution_spotlight'  => 'yes',
			'show_featured_categories' => 'yes',
			'show_process'             => 'yes',
			'show_references'          => 'yes',
			'show_final_cta'           => 'yes',
			'accent_color'             => '#135e96',
			'action_color'             => '#b32d2e',
			'max_width'                => 1180,
			'radius'                   => 8,
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'eyebrow', 'title', 'subtitle', 'support_text', 'company_meta', 'button_text' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_counts', 'show_services', 'show_project_paths', 'show_shop_bridge', 'show_solution_spotlight', 'show_featured_categories', 'show_process', 'show_references', 'show_final_cta' ) as $key ) {
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
	 * Renders short hero benefit descriptions.
	 */
	private function hero_benefits(): string {
		$benefits = array(
			array(
				'label' => __( 'Shop pe proiect', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Pornesti din tipul lucrarii si ajungi rapid la categoriile de produse relevante.', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( 'Ofertare clara', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Serviciile Syshub pun accent pe capitole, optiuni, termene si pasi tehnici explicati.', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( 'Executie si suport', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Produse, documentatie, receptie si mentenanta gandite pentru exploatare fara surprize.', 'schrack-woocommerce-sync' ),
			),
		);

		ob_start();
		?>
		<div class="schrack-home__benefits" aria-label="<?php esc_attr_e( 'Avantaje catalog', 'schrack-woocommerce-sync' ); ?>">
			<?php foreach ( $benefits as $benefit ) : ?>
				<div class="schrack-home__benefit">
					<strong><?php echo esc_html( $benefit['label'] ); ?></strong>
					<span><?php echo esc_html( $benefit['text'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
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
					<figcaption>
						<strong><?php echo esc_html( $term->name ); ?></strong>
						<span><?php echo esc_html( sprintf( __( '%d produse in catalog', 'schrack-woocommerce-sync' ), (int) $term->count ) ); ?></span>
					</figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders project-entry cards that keep the next step commerce-oriented.
	 *
	 * @param array<int,WP_Term> $terms Category terms.
	 */
	private function project_paths( array $terms, string $shop_url ): string {
		$paths = array(
			array(
				'label'       => __( 'Rezidential', 'schrack-woocommerce-sync' ),
				'title'       => __( 'Casa, apartament sau ansamblu locativ', 'schrack-woocommerce-sync' ),
				'text'        => __( 'Produse pentru distributie, iluminat, prize, circuite dedicate, CCTV si alarma, cu selectie rapida pentru lucrari curate.', 'schrack-woocommerce-sync' ),
				'service_url' => 'https://syshub.ro/servicii/instalatii-electrice',
				'keywords'    => array( 'electric', 'tablouri', 'tablou', 'prize', 'iluminat', 'cabluri', 'sigurante', 'cctv', 'alarma' ),
				'variant'     => 'home',
			),
			array(
				'label'       => __( 'Comercial', 'schrack-woocommerce-sync' ),
				'title'       => __( 'Magazine, birouri si spatii de servicii', 'schrack-woocommerce-sync' ),
				'text'        => __( 'Distributie electrica, iluminat, supraveghere video si detectie la efractie adaptate fluxului de clienti.', 'schrack-woocommerce-sync' ),
				'service_url' => 'https://syshub.ro/servicii/securitate-cctv',
				'keywords'    => array( 'cctv', 'camera', 'nvr', 'iluminat', 'electric', 'senzor', 'acces', 'alarma' ),
				'variant'     => 'retail',
			),
			array(
				'label'       => __( 'Industrial', 'schrack-woocommerce-sync' ),
				'title'       => __( 'Hale, linii tehnologice si tablouri de distributie', 'schrack-woocommerce-sync' ),
				'text'        => __( 'Componente pentru instalatii de forta, extinderi de retea, protectii si mentenanta planificata.', 'schrack-woocommerce-sync' ),
				'service_url' => 'https://syshub.ro/servicii/consultanta',
				'keywords'    => array( 'industrial', 'tablouri', 'tablou', 'distributie', 'protectii', 'cabluri', 'bransament', 'retea' ),
				'variant'     => 'industrial',
			),
			array(
				'label'       => __( 'Energie', 'schrack-woocommerce-sync' ),
				'title'       => __( 'Sistem fotovoltaic on-grid, off-grid sau hibrid', 'schrack-woocommerce-sync' ),
				'text'        => __( 'Repere pentru panouri, invertor, protectii, cablare, monitorizare si integrare in consumul existent.', 'schrack-woocommerce-sync' ),
				'service_url' => 'https://syshub.ro/servicii/fotovoltaice',
				'keywords'    => array( 'fotovoltaic', 'solar', 'panou', 'invertor', 'pv', 'baterii', 'monitorizare' ),
				'variant'     => 'solar',
			),
			array(
				'label'       => __( 'Securitate', 'schrack-woocommerce-sync' ),
				'title'       => __( 'CCTV, detectie la efractie si control acces', 'schrack-woocommerce-sync' ),
				'text'        => __( 'Camere, NVR, stocare, senzori, sirene si accesorii pentru obiective cu nevoi diferite de protectie.', 'schrack-woocommerce-sync' ),
				'service_url' => 'https://syshub.ro/servicii/detectie-efractie',
				'keywords'    => array( 'cctv', 'camera', 'camere', 'nvr', 'supraveghere', 'efractie', 'senzor', 'sirena', 'acces' ),
				'variant'     => 'security',
			),
			array(
				'label'       => __( 'Service', 'schrack-woocommerce-sync' ),
				'title'       => __( 'Mentenanta, interventii si modernizari', 'schrack-woocommerce-sync' ),
				'text'        => __( 'Produse utile pentru verificari, inlocuiri, upgrade-uri si interventii programate in instalatii existente.', 'schrack-woocommerce-sync' ),
				'service_url' => 'https://syshub.ro/servicii/mentenanta',
				'keywords'    => array( 'mentenanta', 'service', 'verificare', 'protectii', 'sigurante', 'consumabile', 'cabluri' ),
				'variant'     => 'service',
			),
		);

		ob_start();
		?>
		<div class="schrack-home__pathways">
			<div class="schrack-home__section-head is-wide">
				<div>
					<span><?php esc_html_e( 'Alege proiectul, apoi produsele', 'schrack-woocommerce-sync' ); ?></span>
					<p><?php esc_html_e( 'Home page-ul ramane orientat spre magazin: fiecare scenariu duce spre categorii relevante, dar pastreaza contextul Syshub de proiectare, executie si mentenanta.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
			</div>

			<div class="schrack-home__path-grid">
				<?php foreach ( $paths as $path ) : ?>
					<?php
					$matched_terms = $this->matched_terms( $terms, $path['keywords'], 3 );
					$product_url   = $this->term_collection_url( $matched_terms, $shop_url );
					?>
					<article class="schrack-home__path-card is-<?php echo esc_attr( $path['variant'] ); ?>">
						<div class="schrack-home__path-icon" aria-hidden="true"></div>
						<div class="schrack-home__path-copy">
							<small><?php echo esc_html( $path['label'] ); ?></small>
							<strong><?php echo esc_html( $path['title'] ); ?></strong>
							<p><?php echo esc_html( $path['text'] ); ?></p>
						</div>
						<?php if ( ! empty( $matched_terms ) ) : ?>
							<div class="schrack-home__path-tags">
								<?php foreach ( $matched_terms as $term ) : ?>
									<?php $link = get_term_link( $term ); ?>
									<a href="<?php echo esc_url( is_wp_error( $link ) ? $shop_url : $link ); ?>"><?php echo esc_html( $term->name ); ?></a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<div class="schrack-home__path-actions">
							<a class="schrack-home__mini-button" href="<?php echo esc_url( $product_url ); ?>"><?php esc_html_e( 'Vezi produse', 'schrack-woocommerce-sync' ); ?></a>
							<a class="schrack-home__text-link" href="<?php echo esc_url( $path['service_url'] ); ?>"><?php esc_html_e( 'Serviciu Syshub', 'schrack-woocommerce-sync' ); ?></a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a service-to-product bridge for shop-oriented visitors.
	 *
	 * @param array<int,WP_Term> $terms Category terms.
	 */
	private function shop_bridge( array $terms, string $shop_url ): string {
		ob_start();
		?>
		<div class="schrack-home__commerce">
			<div class="schrack-home__commerce-intro">
				<small><?php esc_html_e( 'Syshub x magazin', 'schrack-woocommerce-sync' ); ?></small>
				<strong><?php esc_html_e( 'Din serviciu in cosul de produse', 'schrack-woocommerce-sync' ); ?></strong>
				<p><?php esc_html_e( 'Daca stii ce lucrare ai, poti sari direct la familiile de produse. Daca ai nevoie de verificare, fiecare traseu ramane legat de pagina de serviciu Syshub.', 'schrack-woocommerce-sync' ); ?></p>
			</div>

			<div class="schrack-home__commerce-list">
				<?php foreach ( $this->services() as $service ) : ?>
					<?php
					$matched_terms = $this->matched_terms( $terms, $service['keywords'], 4 );
					$product_url   = $this->term_collection_url( $matched_terms, $shop_url );
					?>
					<article class="schrack-home__commerce-row is-<?php echo esc_attr( $service['variant'] ); ?>">
						<div>
							<small><?php echo esc_html( $service['area'] ); ?></small>
							<strong><?php echo esc_html( $service['name'] ); ?></strong>
							<span><?php echo esc_html( $service['text'] ); ?></span>
						</div>
						<div class="schrack-home__commerce-tags">
							<?php if ( ! empty( $matched_terms ) ) : ?>
								<?php foreach ( $matched_terms as $term ) : ?>
									<?php $link = get_term_link( $term ); ?>
									<a href="<?php echo esc_url( is_wp_error( $link ) ? $shop_url : $link ); ?>"><?php echo esc_html( $term->name ); ?></a>
								<?php endforeach; ?>
							<?php else : ?>
								<a href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Catalog produse', 'schrack-woocommerce-sync' ); ?></a>
							<?php endif; ?>
						</div>
						<a class="schrack-home__mini-button" href="<?php echo esc_url( $product_url ); ?>"><?php esc_html_e( 'Vezi produse', 'schrack-woocommerce-sync' ); ?></a>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a richer image-led category story.
	 *
	 * @param array<int,WP_Term> $terms Featured terms.
	 */
	private function solution_spotlight( array $terms, string $show_counts ): string {
		if ( empty( $terms ) ) {
			return '';
		}

		$labels = array(
			__( 'Pentru proiectare', 'schrack-woocommerce-sync' ),
			__( 'Pentru santier', 'schrack-woocommerce-sync' ),
			__( 'Pentru mentenanta', 'schrack-woocommerce-sync' ),
		);

		ob_start();
		?>
		<div class="schrack-home__solutions">
			<div class="schrack-home__section-head is-wide">
				<div>
					<span><?php esc_html_e( 'Solutii complete pentru proiecte moderne', 'schrack-woocommerce-sync' ); ?></span>
					<p><?php esc_html_e( 'O selectie vizuala din catalog, gandita pentru echipe care aleg rapid produse, compara zone de instalare si pornesc direct catre categoria potrivita.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
			</div>

			<div class="schrack-home__solution-grid">
				<?php foreach ( array_slice( $terms, 0, 3 ) as $index => $term ) : ?>
					<?php $link = get_term_link( $term ); ?>
					<a class="schrack-home__solution-card" href="<?php echo esc_url( is_wp_error( $link ) ? '#' : $link ); ?>">
						<span class="schrack-home__solution-media">
							<?php echo $this->term_image( $term, 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="schrack-home__solution-badge"><?php echo esc_html( $labels[ $index ] ?? $labels[0] ); ?></span>
						</span>
						<span class="schrack-home__solution-copy">
							<strong><?php echo esc_html( $term->name ); ?></strong>
							<span><?php echo esc_html( $this->term_marketing_text( $term, $index ) ); ?></span>
							<?php if ( 'yes' === $show_counts ) : ?>
								<em><?php echo esc_html( sprintf( __( '%d produse disponibile', 'schrack-woocommerce-sync' ), (int) $term->count ) ); ?></em>
							<?php endif; ?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
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
		$services = $this->services();

		ob_start();
		?>
		<div class="schrack-home__section-head">
			<div>
				<span><?php esc_html_e( 'Servicii Gene Sys Security', 'schrack-woocommerce-sync' ); ?></span>
				<p><?php esc_html_e( 'Cele sase directii Syshub completeaza magazinul: produse potrivite, executie tehnica, documentatie si suport dupa punerea in functiune.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
		</div>

		<div class="schrack-home__service-grid">
			<?php foreach ( $services as $service ) : ?>
				<a class="schrack-home__service-card is-<?php echo esc_attr( $service['variant'] ); ?>" href="<?php echo esc_url( $service['url'] ); ?>">
					<span class="schrack-home__service-visual <?php echo '' === $service['image'] ? 'is-image-missing' : ''; ?>">
						<?php if ( '' !== $service['image'] ) : ?>
							<img src="<?php echo esc_url( $service['image'] ); ?>" alt="<?php echo esc_attr( $service['name'] ); ?>" loading="lazy">
						<?php endif; ?>
					</span>
					<div class="schrack-home__service-copy">
						<small><?php echo esc_html( $service['area'] ); ?></small>
						<strong><?php echo esc_html( $service['name'] ); ?></strong>
						<p><?php echo esc_html( $service['text'] ); ?></p>
						<ul class="schrack-home__service-points">
							<?php foreach ( $service['points'] as $point ) : ?>
								<li><?php echo esc_html( $point ); ?></li>
							<?php endforeach; ?>
						</ul>
						<span class="schrack-home__service-link"><?php esc_html_e( 'Vezi serviciul', 'schrack-woocommerce-sync' ); ?></span>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns Syshub service definitions used across homepage modules.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function services(): array {
		return array(
			array(
				'area'    => __( 'Instalatii', 'schrack-woocommerce-sync' ),
				'name'    => __( 'Instalatii electrice', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Distributie, tablouri, iluminat, prize, circuite dedicate, verificari si documentatie pentru receptie.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/instalatii-electrice',
				'image'   => 'https://syshub.ro/assets/electrical-installation-MhzlRr5w.jpg',
				'variant' => 'electric',
				'keywords' => array( 'electric', 'tablouri', 'tablou', 'sigurante', 'prize', 'iluminat', 'cabluri', 'distributie' ),
				'points'  => array(
					__( 'Planificare pe zone si consumatori', 'schrack-woocommerce-sync' ),
					__( 'Executie pentru cladiri civile si industriale', 'schrack-woocommerce-sync' ),
				),
			),
			array(
				'area'    => __( 'Energie verde', 'schrack-woocommerce-sync' ),
				'name'    => __( 'Sisteme fotovoltaice', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Sisteme on-grid, off-grid si hibride, analiza consum, dimensionare, monitorizare si mentenanta.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/fotovoltaice',
				'image'   => 'https://syshub.ro/assets/photovoltaic-panels-cA7PyFUI.jpg',
				'variant' => 'solar',
				'keywords' => array( 'fotovoltaic', 'solar', 'panou', 'invertor', 'pv', 'baterii', 'monitorizare', 'protectii' ),
				'points'  => array(
					__( 'Dimensionare dupa profilul de consum', 'schrack-woocommerce-sync' ),
					__( 'Monitorizare si mentenanta dupa instalare', 'schrack-woocommerce-sync' ),
				),
			),
			array(
				'area'    => __( 'Supraveghere', 'schrack-woocommerce-sync' ),
				'name'    => __( 'Securitate si CCTV', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Plan camere, cablare, NVR, stocare, acces remote, detectie la efractie si upgrade-uri.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/securitate-cctv',
				'image'   => 'https://syshub.ro/assets/security-cctv-B6nSZA5n.jpg',
				'variant' => 'security',
				'keywords' => array( 'cctv', 'camera', 'camere', 'nvr', 'supraveghere', 'video', 'stocare', 'acces' ),
				'points'  => array(
					__( 'Supraveghere video si control acces', 'schrack-woocommerce-sync' ),
					__( 'Configurare pentru acces remote securizat', 'schrack-woocommerce-sync' ),
				),
			),
			array(
				'area'    => __( 'Alarmare', 'schrack-woocommerce-sync' ),
				'name'    => __( 'Detectie la efractie', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Senzori, centrale, protectie perimetrala si interioara, sirene, notificari si documentatie pentru obiective reglementate.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/detectie-efractie',
				'image'   => '',
				'variant' => 'alarm',
				'keywords' => array( 'efractie', 'alarma', 'senzor', 'sirena', 'centrala', 'detector', 'perimetru' ),
				'points'  => array(
					__( 'Senzori si zone configurate dupa obiectiv', 'schrack-woocommerce-sync' ),
					__( 'Documentatie pentru sisteme de securitate', 'schrack-woocommerce-sync' ),
				),
			),
			array(
				'area'    => __( 'Service', 'schrack-woocommerce-sync' ),
				'name'    => __( 'Mentenanta tehnica', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Contracte preventive sau interventii la cerere pentru instalatii electrice, fotovoltaice si echipamente de securitate.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/mentenanta',
				'image'   => '',
				'variant' => 'maintenance',
				'keywords' => array( 'mentenanta', 'service', 'verificare', 'sigurante', 'protectii', 'consumabile', 'cabluri' ),
				'points'  => array(
					__( 'Vizite preventive si rapoarte tehnice', 'schrack-woocommerce-sync' ),
					__( 'Upgrade-uri si inlocuiri planificate', 'schrack-woocommerce-sync' ),
				),
			),
			array(
				'area'    => __( 'Consultanta', 'schrack-woocommerce-sync' ),
				'name'    => __( 'Consultanta si infrastructura electrica', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Audit, recomandari tehnice, bransamente, extinderi de retea, optimizare consum si suport pentru avize.', 'schrack-woocommerce-sync' ),
				'url'     => 'https://syshub.ro/servicii/consultanta',
				'image'   => '',
				'variant' => 'consulting',
				'keywords' => array( 'audit', 'bransament', 'retea', 'documentatie', 'masurare', 'protectie', 'cabluri' ),
				'points'  => array(
					__( 'Analiza cerinte, incarcare si consum', 'schrack-woocommerce-sync' ),
					__( 'Suport pentru avize si variante tehnice', 'schrack-woocommerce-sync' ),
				),
			),
		);
	}

	/**
	 * Renders the Syshub delivery process as a concise commercial journey.
	 */
	private function process_steps(): string {
		$steps = array(
			array(
				'label' => __( '01', 'schrack-woocommerce-sync' ),
				'title' => __( 'Analiza cerinte', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Se clarifica tipul obiectivului, consumatorii, riscurile, documentatia existenta si lista initiala de materiale.', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( '02', 'schrack-woocommerce-sync' ),
				'title' => __( 'Selectie produse', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Vizitatorul ajunge in catalog pe categoriile potrivite: electrice, fotovoltaice, CCTV, efractie sau mentenanta.', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( '03', 'schrack-woocommerce-sync' ),
				'title' => __( 'Oferta pe capitole', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Oferta poate separa materiale, variante tehnice, manopera, termene si pasi pentru avize sau racordari.', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( '04', 'schrack-woocommerce-sync' ),
				'title' => __( 'Executie si probe', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Montaj, configurare, testare, instruire utilizatori si predare documentatie pentru receptie.', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( '05', 'schrack-woocommerce-sync' ),
				'title' => __( 'Mentenanta', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Pentru obiective critice, traseul continua cu vizite preventive, interventii si recomandari de modernizare.', 'schrack-woocommerce-sync' ),
			),
		);

		ob_start();
		?>
		<div class="schrack-home__process">
			<div class="schrack-home__section-head is-wide">
				<div>
					<span><?php esc_html_e( 'Flux clar: produs, oferta, executie, receptie', 'schrack-woocommerce-sync' ); ?></span>
					<p><?php esc_html_e( 'Designul nou pune magazinul in mijlocul procesului Syshub: categoriile devin primul pas practic catre ofertare si implementare.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
			</div>

			<div class="schrack-home__process-track">
				<?php foreach ( $steps as $step ) : ?>
					<article class="schrack-home__process-step">
						<small><?php echo esc_html( $step['label'] ); ?></small>
						<strong><?php echo esc_html( $step['title'] ); ?></strong>
						<p><?php echo esc_html( $step['text'] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders proof points based on the public Syshub positioning.
	 */
	private function project_references(): string {
		$references = array(
			array(
				'type'  => __( 'Rezidential', 'schrack-woocommerce-sync' ),
				'title' => __( 'Instalatii pentru 150 de apartamente', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Instalatii electrice complete, iluminat inteligent si infrastructura moderna pentru ansamblu locativ.', 'schrack-woocommerce-sync' ),
			),
			array(
				'type'  => __( 'Industrial', 'schrack-woocommerce-sync' ),
				'title' => __( 'Tablouri si distributie pentru productie', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Proiectare si executie pentru instalatii industriale, tablouri de joasa si medie tensiune.', 'schrack-woocommerce-sync' ),
			),
			array(
				'type'  => __( 'CCTV', 'schrack-woocommerce-sync' ),
				'title' => __( 'Sistem video cu 120 camere 4K', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Supraveghere video, analiza inteligenta si stocare extinsa pentru obiective cu cerinte ridicate.', 'schrack-woocommerce-sync' ),
			),
			array(
				'type'  => __( 'Retail', 'schrack-woocommerce-sync' ),
				'title' => __( 'Mentenanta pentru 15 magazine', 'schrack-woocommerce-sync' ),
				'text'  => __( 'Service preventiv si corectiv pentru retele de locatii comerciale, cu interventii planificate.', 'schrack-woocommerce-sync' ),
			),
		);

		ob_start();
		?>
		<div class="schrack-home__references">
			<div class="schrack-home__section-head is-wide">
				<div>
					<span><?php esc_html_e( 'Repere care dau incredere in magazin', 'schrack-woocommerce-sync' ); ?></span>
					<p><?php esc_html_e( 'Vizitatorul vede ca produsele sunt sustinute de experienta reala in instalatii, securitate si mentenanta, nu doar de o lista de categorii.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
			</div>

			<div class="schrack-home__reference-grid">
				<?php foreach ( $references as $reference ) : ?>
					<article class="schrack-home__reference-card">
						<small><?php echo esc_html( $reference['type'] ); ?></small>
						<strong><?php echo esc_html( $reference['title'] ); ?></strong>
						<p><?php echo esc_html( $reference['text'] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a closing call to action.
	 */
	private function final_cta( string $shop_url ): string {
		ob_start();
		?>
		<div class="schrack-home__final">
			<div>
				<small><?php esc_html_e( 'Ai lista de materiale sau doar proiectul?', 'schrack-woocommerce-sync' ); ?></small>
				<strong><?php esc_html_e( 'Porneste din catalog sau cere o oferta tehnica Syshub', 'schrack-woocommerce-sync' ); ?></strong>
				<p><?php esc_html_e( 'Pentru lucrari simple, intra direct in magazin. Pentru proiecte cu avize, dimensionare, receptie sau mentenanta, trimite detaliile catre echipa Syshub.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
			<div class="schrack-home__final-actions">
				<a class="schrack-home__button" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Vezi catalogul', 'schrack-woocommerce-sync' ); ?></a>
				<a class="schrack-home__ghost-button" href="https://syshub.ro/contact"><?php esc_html_e( 'Cere oferta', 'schrack-woocommerce-sync' ); ?></a>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Finds catalog terms that match a project/service keyword group.
	 *
	 * @param array<int,WP_Term> $terms Category terms.
	 * @param array<int,string>  $keywords Search tokens.
	 * @return array<int,WP_Term>
	 */
	private function matched_terms( array $terms, array $keywords, int $limit ): array {
		if ( empty( $terms ) || empty( $keywords ) || $limit <= 0 ) {
			return array();
		}

		$tokens = array_map(
			static fn( string $keyword ): string => strtolower( remove_accents( $keyword ) ),
			$keywords
		);

		$scored = array();

		foreach ( $terms as $term ) {
			$haystack = strtolower(
				remove_accents(
					trim( $term->name . ' ' . $term->slug . ' ' . wp_strip_all_tags( $term->description ) )
				)
			);
			$score = 0;

			foreach ( $tokens as $token ) {
				if ( '' !== $token && false !== strpos( $haystack, $token ) ) {
					$score++;
				}
			}

			if ( $score > 0 ) {
				$scored[] = array(
					'term'  => $term,
					'score' => $score,
					'count' => (int) $term->count,
				);
			}
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				$score = (int) $b['score'] <=> (int) $a['score'];

				if ( 0 !== $score ) {
					return $score;
				}

				return (int) $b['count'] <=> (int) $a['count'];
			}
		);

		return array_map(
			static fn( array $item ): WP_Term => $item['term'],
			array_slice( $scored, 0, $limit )
		);
	}

	/**
	 * Returns the first matched category URL or the shop fallback.
	 *
	 * @param array<int,WP_Term> $terms Matched terms.
	 */
	private function term_collection_url( array $terms, string $shop_url ): string {
		if ( empty( $terms ) ) {
			return $shop_url;
		}

		$link = get_term_link( $terms[0] );

		return is_wp_error( $link ) ? $shop_url : (string) $link;
	}

	/**
	 * Builds concise fallback copy for category cards.
	 */
	private function term_marketing_text( WP_Term $term, int $index ): string {
		if ( '' !== $term->description ) {
			return wp_trim_words( wp_strip_all_tags( $term->description ), 24, '...' );
		}

		$fallbacks = array(
			__( 'Produse selectate pentru tablouri, distributie si instalatii care trebuie sa ramana usor de verificat in exploatare.', 'schrack-woocommerce-sync' ),
			__( 'Repere utile pentru executie rapida, interventii curate si completarea listelor de materiale pentru santier.', 'schrack-woocommerce-sync' ),
			__( 'Componente potrivite pentru modernizari, mentenanta si extinderea instalatiilor existente fara cautari inutile.', 'schrack-woocommerce-sync' ),
		);

		return $fallbacks[ $index % count( $fallbacks ) ];
	}

	/**
	 * Returns company facts from the public Syshub profile.
	 *
	 * @return array<int,array{label:string,value:string}>
	 */
	private function facts(): array {
		return array(
			array(
				'label' => __( 'Arie', 'schrack-woocommerce-sync' ),
				'value' => __( 'Satu Mare si proiecte in Romania', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( 'Domenii', 'schrack-woocommerce-sync' ),
				'value' => __( 'Electric, fotovoltaic, securitate', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( 'Documentatie', 'schrack-woocommerce-sync' ),
				'value' => __( 'Oferta, receptie, mentenanta', 'schrack-woocommerce-sync' ),
			),
			array(
				'label' => __( 'Contact', 'schrack-woocommerce-sync' ),
				'value' => '+40 749 235 958',
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
