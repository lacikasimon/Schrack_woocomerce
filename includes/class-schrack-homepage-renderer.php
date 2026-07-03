<?php
/**
 * Elementor homepage renderer for the Syshub technical catalog.
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
		$copy           = $this->homepage_copy();
		$terms          = $this->catalog_terms( (int) $settings['category_limit'] );
		$project_terms  = ! empty( $settings['project_category_ids'] ) ? $this->selected_terms( $settings['project_category_ids'], $terms ) : $terms;
		$category_terms = ! empty( $settings['featured_category_ids'] ) ? $this->selected_terms( $settings['featured_category_ids'], $terms ) : $terms;
		$product_terms  = ! empty( $settings['solution_category_ids'] ) ? $this->selected_terms( $settings['solution_category_ids'], $terms ) : $category_terms;

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

						<div class="schrack-home__actions">
							<a class="schrack-home__button" href="<?php echo esc_url( $settings['shop_url'] ); ?>">
								<?php echo esc_html( $settings['button_text'] ); ?>
							</a>
							<a class="schrack-home__ghost-button" href="<?php echo esc_url( $settings['consultation_url'] ); ?>">
								<?php echo esc_html( $settings['secondary_button_text'] ); ?>
							</a>
							<?php if ( '' !== $settings['company_meta'] ) : ?>
								<span class="schrack-home__meta"><?php echo esc_html( $settings['company_meta'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>

					<?php echo $this->hero_visual( $copy['hero_visual'], $settings, $terms, $settings['shop_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<?php echo $this->trust_band( $copy['trust'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( 'yes' === $settings['show_project_paths'] ) : ?>
					<?php echo $this->project_navigation( $copy['projects'], $project_terms, $settings, $settings['shop_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_featured_categories'] ) : ?>
					<?php echo $this->curated_categories_section( $copy['categories'], $category_terms, $settings['shop_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_solution_spotlight'] ) : ?>
					<?php echo $this->recommended_products_section( $copy['products'], $product_terms, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_services'] ) : ?>
					<?php echo $this->why_syshub( $copy['why'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_shop_bridge'] ) : ?>
					<?php echo $this->b2b_offer_block( $copy['b2b'], $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_final_cta'] ) : ?>
					<?php echo $this->closing_cta( $copy['final'], $settings['contact_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
	 * @return array<string,mixed>
	 */
	private function sanitize_settings( array $settings ): array {
		$defaults = array(
			'eyebrow'                 => __( 'Produse tehnice pentru proiecte electrice, securitate și fotovoltaice — cu suport tehnic Syshub.', 'schrack-woocommerce-sync' ),
			'title'                   => __( 'Tot ce ai nevoie pentru proiecte electrice, securitate și energie solară', 'schrack-woocommerce-sync' ),
			'subtitle'                => __( 'Alege produse tehnice potrivite pentru locuințe, spații comerciale și proiecte industriale — online, rapid și cu suport de specialitate.', 'schrack-woocommerce-sync' ),
			'support_text'            => '',
			'company_meta'            => '',
			'button_text'             => __( 'Vezi produsele', 'schrack-woocommerce-sync' ),
			'secondary_button_text'   => __( 'Cere consultanță', 'schrack-woocommerce-sync' ),
			'shop_url'                => $this->default_shop_url(),
			'consultation_url'        => 'https://syshub.ro/contact',
			'material_list_url'       => 'https://syshub.ro/contact',
			'offer_url'               => 'https://syshub.ro/contact',
			'contact_url'             => 'https://syshub.ro/contact',
			'category_limit'            => 220,
			'featured_category_count'   => 6,
			'recommended_product_limit' => 8,
			'hero_category_ids'       => array(),
			'solution_category_ids'   => array(),
			'featured_category_ids'   => array(),
			'tree_category_ids'       => array(),
			'project_category_ids'    => array(),
			'bridge_category_ids'     => array(),
			'hero_electric_category_id'       => 0,
			'hero_security_category_id'       => 0,
			'hero_solar_category_id'          => 0,
			'hero_automation_category_id'     => 0,
			'project_residential_category_id' => 0,
			'project_commercial_category_id'  => 0,
			'project_video_category_id'       => 0,
			'project_alarm_category_id'       => 0,
			'project_solar_category_id'       => 0,
			'project_automation_category_id'  => 0,
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
		$settings = $this->replace_legacy_default_copy( $settings, $defaults );

		foreach ( array( 'eyebrow', 'title', 'subtitle', 'support_text', 'company_meta', 'button_text', 'secondary_button_text' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_counts', 'show_services', 'show_project_paths', 'show_shop_bridge', 'show_solution_spotlight', 'show_featured_categories', 'show_process', 'show_references', 'show_final_cta' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		foreach ( array( 'hero_category_ids', 'solution_category_ids', 'featured_category_ids', 'tree_category_ids', 'project_category_ids', 'bridge_category_ids' ) as $key ) {
			$settings[ $key ] = $this->selected_term_ids( $settings[ $key ] ?? array() );
		}

		foreach ( $this->homepage_card_category_setting_keys() as $key ) {
			$settings[ $key ] = absint( $settings[ $key ] ?? 0 );
		}

		foreach ( array( 'shop_url', 'consultation_url', 'material_list_url', 'offer_url', 'contact_url' ) as $url_key ) {
			$settings[ $url_key ] = esc_url_raw( (string) $settings[ $url_key ] );
		}

		$settings['category_limit']            = max( 20, min( 600, absint( $settings['category_limit'] ) ) );
		$settings['featured_category_count']   = max( 0, min( 8, absint( $settings['featured_category_count'] ) ) );
		$settings['recommended_product_limit'] = max( 0, min( 12, absint( $settings['recommended_product_limit'] ) ) );
		$settings['accent_color']              = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['action_color']              = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['max_width']                 = max( 900, min( 1440, absint( $settings['max_width'] ) ) );
		$settings['radius']                    = max( 0, min( 8, absint( $settings['radius'] ) ) );

		if ( '' === $settings['shop_url'] ) {
			$settings['shop_url'] = $this->default_shop_url();
		}

		foreach ( array( 'consultation_url', 'material_list_url', 'offer_url', 'contact_url' ) as $url_key ) {
			if ( '' === $settings[ $url_key ] ) {
				$settings[ $url_key ] = 'https://syshub.ro/contact';
			}
		}

		return $settings;
	}

	/**
	 * Keeps already-saved Elementor widgets from freezing the old placeholder copy.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @param array<string,mixed> $defaults New defaults.
	 * @return array<string,mixed>
	 */
	private function replace_legacy_default_copy( array $settings, array $defaults ): array {
		$legacy_defaults = array(
			'eyebrow'      => array( 'GENE SYS SECURITY SRL' ),
			'title'        => array( 'Magazin tehnic pentru proiecte electrice, fotovoltaice si securitate' ),
			'subtitle'     => array( 'Alege produse pentru instalatii electrice, sisteme fotovoltaice, CCTV, detectie la efractie si mentenanta, cu repere clare pentru proiecte civile si industriale.' ),
			'support_text' => array( 'Syshub aduce contextul de proiectare, executie si documentatie; magazinul te ajuta sa pornesti rapid din categoriile potrivite, de la lista de materiale pana la ofertare.' ),
			'company_meta' => array( 'Satu Mare - CUI RO 38322763' ),
			'button_text'  => array( 'Vezi catalogul de produse', 'Vezi catalogul' ),
		);

		foreach ( $legacy_defaults as $key => $legacy_values ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}

			$current = trim( (string) $settings[ $key ] );

			if ( in_array( $current, $legacy_values, true ) ) {
				$settings[ $key ] = $defaults[ $key ] ?? '';
			}
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
	 * Returns selected terms for a specific homepage block, with automatic fallback.
	 *
	 * @param array<int,int> $selected_ids Selected product category IDs.
	 * @param array<int,WP_Term> $automatic_terms Fallback terms when no selection exists.
	 * @return array<int,WP_Term>
	 */
	private function terms_for_block( array $selected_ids, array $automatic_terms, int $limit = 0 ): array {
		if ( ! empty( $selected_ids ) ) {
			$selected_terms = $this->selected_terms( $selected_ids, $automatic_terms );

			if ( ! empty( $selected_terms ) ) {
				return $limit > 0 ? array_slice( $selected_terms, 0, $limit ) : $selected_terms;
			}
		}

		return $limit > 0 ? array_slice( $automatic_terms, 0, $limit ) : $automatic_terms;
	}

	/**
	 * Returns terms for the category tree, including descendants of selected parents.
	 *
	 * @param array<int,int>     $selected_ids Selected product category IDs.
	 * @param array<int,WP_Term> $automatic_terms Fallback terms when no selection exists.
	 * @return array<int,WP_Term>
	 */
	private function tree_terms_for_block( array $selected_ids, array $automatic_terms ): array {
		if ( empty( $selected_ids ) ) {
			return $automatic_terms;
		}

		$selected_terms = $this->selected_terms( $selected_ids, $automatic_terms );

		if ( empty( $selected_terms ) ) {
			return $automatic_terms;
		}

		$descendant_ids = array();

		foreach ( $selected_ids as $term_id ) {
			$children = get_term_children( $term_id, 'product_cat' );

			if ( is_wp_error( $children ) || ! is_array( $children ) ) {
				continue;
			}

			$descendant_ids = array_merge( $descendant_ids, $children );
		}

		return $this->merge_terms( $selected_terms, $this->selected_terms( $descendant_ids, $automatic_terms ) );
	}

	/**
	 * Returns selected terms in the configured order.
	 *
	 * @param array<int,int> $selected_ids Selected product category IDs.
	 * @param array<int,WP_Term> $available_terms Already loaded terms.
	 * @return array<int,WP_Term>
	 */
	private function selected_terms( array $selected_ids, array $available_terms ): array {
		$terms_by_id = array();

		foreach ( $available_terms as $term ) {
			$terms_by_id[ (int) $term->term_id ] = $term;
		}

		$missing_ids = array_values(
			array_filter(
				$selected_ids,
				static fn( int $term_id ): bool => ! isset( $terms_by_id[ $term_id ] )
			)
		);

		foreach ( $this->terms_by_ids( $missing_ids ) as $term ) {
			$terms_by_id[ (int) $term->term_id ] = $term;
		}

		$selected_terms = array();

		foreach ( $selected_ids as $term_id ) {
			if ( isset( $terms_by_id[ $term_id ] ) ) {
				$selected_terms[] = $terms_by_id[ $term_id ];
			}
		}

		return $selected_terms;
	}

	/**
	 * Loads category terms by ID.
	 *
	 * @param array<int,int> $ids Category IDs.
	 * @return array<int,WP_Term>
	 */
	private function terms_by_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) || ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => $ids,
				'orderby'    => 'include',
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
	 * Merges term lists without duplicates.
	 *
	 * @param array<int,WP_Term> $primary Primary terms.
	 * @param array<int,WP_Term> $extra Extra terms.
	 * @return array<int,WP_Term>
	 */
	private function merge_terms( array $primary, array $extra ): array {
		$merged = array();

		foreach ( array_merge( $primary, $extra ) as $term ) {
			$merged[ (int) $term->term_id ] = $term;
		}

		return array_values( $merged );
	}

	/**
	 * Normalizes Elementor select2 values to category IDs.
	 *
	 * @param mixed $value Raw Elementor value.
	 * @return array<int,int>
	 */
	private function selected_term_ids( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? array() : explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/**
	 * Returns individual homepage card category setting keys.
	 *
	 * @return array<int,string>
	 */
	private function homepage_card_category_setting_keys(): array {
		return array(
			'hero_electric_category_id',
			'hero_security_category_id',
			'hero_solar_category_id',
			'hero_automation_category_id',
			'project_residential_category_id',
			'project_commercial_category_id',
			'project_video_category_id',
			'project_alarm_category_id',
			'project_solar_category_id',
			'project_automation_category_id',
		);
	}

	/**
	 * Central homepage copy used by the Syshub technical catalog layout.
	 *
	 * @return array<string,mixed>
	 */
	private function homepage_copy(): array {
		return array(
			'hero_visual' => array(
				array(
					'title'   => __( 'Electric', 'schrack-woocommerce-sync' ),
					'text'    => __( 'Cabluri, tablouri, protecții, aparataj și accesorii de montaj.', 'schrack-woocommerce-sync' ),
					'variant' => 'electric',
					'category_slug' => 'cabluri-conductori-si-conectica',
					'keywords' => array( 'electric', 'cabluri', 'conductori', 'protectii', 'protecții', 'tablouri', 'tablou', 'aparataj', 'prize', 'montaj' ),
					'category_setting' => 'hero_electric_category_id',
				),
				array(
					'title'   => __( 'Securitate', 'schrack-woocommerce-sync' ),
					'text'    => __( 'CCTV, alarmare, control acces și infrastructură pentru clădiri.', 'schrack-woocommerce-sync' ),
					'variant' => 'security',
					'category_slug' => 'securitate-detectie-si-control-acces',
					'keywords' => array( 'securitate', 'cctv', 'camera', 'camere', 'nvr', 'alarma', 'alarmare', 'control acces', 'acces' ),
					'category_setting' => 'hero_security_category_id',
				),
				array(
					'title'   => __( 'Fotovoltaice', 'schrack-woocommerce-sync' ),
					'text'    => __( 'Componente pentru protecție, cablare, conectare și integrare solară.', 'schrack-woocommerce-sync' ),
					'variant' => 'solar',
					'category_slug' => 'energie-ups-si-fotovoltaice',
					'keywords' => array( 'fotovoltaic', 'solar', 'panou', 'invertor', 'invertoare', 'pv', 'conector', 'protectii', 'protecții', 'cabluri' ),
					'category_setting' => 'hero_solar_category_id',
				),
				array(
					'title'   => __( 'Automatizări', 'schrack-woocommerce-sync' ),
					'text'    => __( 'Distribuție, comandă și control pentru aplicații tehnice.', 'schrack-woocommerce-sync' ),
					'variant' => 'automation',
					'category_slug' => 'automatizari-control-si-masurare',
					'keywords' => array( 'automatizare', 'automatizari', 'comanda', 'comandă', 'control', 'distributie', 'distribuție', 'tablouri', 'tablou' ),
					'category_setting' => 'hero_automation_category_id',
				),
			),
			'trust'       => array(
				__( 'Produse pentru proiecte rezidențiale, comerciale și industriale', 'schrack-woocommerce-sync' ),
				__( 'Suport tehnic pentru alegerea componentelor potrivite', 'schrack-woocommerce-sync' ),
				__( 'Soluții pentru instalații electrice, CCTV, alarmare și energie solară', 'schrack-woocommerce-sync' ),
				__( 'Comandă online sau solicită ofertă personalizată', 'schrack-woocommerce-sync' ),
			),
			'projects'    => array(
				'title'    => __( 'Alege după tipul proiectului', 'schrack-woocommerce-sync' ),
				'subtitle' => __( 'Găsește mai ușor produsele potrivite pornind de la aplicația reală: locuințe, spații comerciale, hale industriale, sisteme de securitate sau instalații fotovoltaice.', 'schrack-woocommerce-sync' ),
				'cards'    => array(
					array(
						'title'    => __( 'Instalații electrice rezidențiale', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Produse pentru locuințe, apartamente și case: cabluri, protecții, tablouri, aparataj și accesorii de montaj.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'aparataj-terminal-prize-si-intrerupatoare',
						'keywords' => array( 'electric', 'cabluri', 'conductori', 'protectii', 'protecții', 'tablouri', 'tablou', 'aparataj', 'prize', 'montaj' ),
						'variant'  => 'home',
						'category_setting' => 'project_residential_category_id',
					),
					array(
						'title'    => __( 'Clădiri comerciale și birouri', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Soluții pentru spații cu consum mai mare, distribuție electrică, iluminat, rețelistică și securitate.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'iluminat-si-surse-de-lumina',
						'keywords' => array( 'comercial', 'birouri', 'distributie', 'distribuție', 'iluminat', 'retea', 'rețea', 'retelistica', 'securitate' ),
						'variant'  => 'retail',
						'category_setting' => 'project_commercial_category_id',
					),
					array(
						'title'    => __( 'Sisteme de supraveghere video', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Camere, NVR-uri, surse, cabluri și accesorii pentru proiecte CCTV complete.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'securitate-detectie-si-control-acces',
						'keywords' => array( 'cctv', 'camera', 'camere', 'nvr', 'supraveghere', 'video', 'surse', 'cabluri' ),
						'variant'  => 'security',
						'category_setting' => 'project_video_category_id',
					),
					array(
						'title'    => __( 'Sisteme de alarmare și control acces', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Echipamente pentru protecția clădirilor, detecție, avertizare și acces securizat.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'securitate-detectie-si-control-acces',
						'keywords' => array( 'alarma', 'alarmare', 'efractie', 'efracție', 'control acces', 'acces', 'senzor', 'sirena', 'detector' ),
						'variant'  => 'alarm',
						'category_setting' => 'project_alarm_category_id',
					),
					array(
						'title'    => __( 'Proiecte fotovoltaice', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Componente pentru instalații solare: protecții, cabluri, conectori, invertoare și accesorii dedicate.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'energie-ups-si-fotovoltaice',
						'keywords' => array( 'fotovoltaic', 'solar', 'panou', 'invertor', 'invertoare', 'pv', 'conector', 'protectii', 'protecții', 'cabluri' ),
						'variant'  => 'solar',
						'category_setting' => 'project_solar_category_id',
					),
					array(
						'title'    => __( 'Tablouri electrice și automatizări', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Componente pentru distribuție, protecție, comandă și automatizare în proiecte tehnice.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'tablouri-dulapuri-si-distributie',
						'keywords' => array( 'tablouri', 'tablou', 'automatizare', 'automatizari', 'comanda', 'comandă', 'distributie', 'protecție', 'protectii' ),
						'variant'  => 'industrial',
						'category_setting' => 'project_automation_category_id',
					),
				),
			),
			'categories'  => array(
				'title'    => __( 'Categorii principale de produse', 'schrack-woocommerce-sync' ),
				'subtitle' => __( 'Produsele sunt organizate pe domenii tehnice, pentru ca instalatorii, firmele și beneficiarii să poată găsi rapid componentele necesare.', 'schrack-woocommerce-sync' ),
				'cards'    => array(
					array(
						'title'    => __( 'Iluminat si surse de lumina', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Corpuri de iluminat, surse de lumina, sisteme LED si iluminat de siguranta.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'iluminat-si-surse-de-lumina',
						'keywords' => array( 'iluminat', 'surse de lumina', 'led', 'corpuri de iluminat' ),
					),
					array(
						'title'    => __( 'Cabluri, conductori si conectica', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Cabluri, conductori, cleme, papuci, presetupe si accesorii de conectare.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'cabluri-conductori-si-conectica',
						'keywords' => array( 'cabluri', 'cablu', 'conductori', 'conectica', 'cleme', 'papuci' ),
					),
					array(
						'title'    => __( 'Instalatii, trasee cabluri si scule', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Doze, tuburi, canale, tavi, fixare, scule si materiale auxiliare.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'instalatii-trasee-cabluri-si-scule',
						'keywords' => array( 'instalatii', 'trasee cabluri', 'doze', 'tuburi', 'canale', 'scule' ),
					),
					array(
						'title'    => __( 'Protectie electrica si comutatie', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Intreruptoare, sigurante, descarcatoare, separatoare si comutatoare de sarcina.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'protectie-electrica-si-comutatie',
						'keywords' => array( 'protectie electrica', 'comutatie', 'intrerupatoare', 'sigurante', 'descarcatoare' ),
					),
					array(
						'title'    => __( 'Tablouri, dulapuri si distributie', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Tablouri, dulapuri, cofrete, carcase, sisteme de bare si accesorii de distributie.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'tablouri-dulapuri-si-distributie',
						'keywords' => array( 'tablouri', 'dulapuri', 'distributie', 'cofrete', 'carcase' ),
					),
					array(
						'title'    => __( 'Aparataj terminal, prize si intrerupatoare', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Aparataj terminal, prize, intrerupatoare, rame, module si accesorii de montaj terminal.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'aparataj-terminal-prize-si-intrerupatoare',
						'keywords' => array( 'aparataj terminal', 'prize', 'intrerupatoare', 'rame', 'module' ),
					),
					array(
						'title'    => __( 'Automatizari, control si masurare', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Contactoare, relee, KNX, senzori, actionari, masurare, semnalizare si control industrial.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'automatizari-control-si-masurare',
						'keywords' => array( 'automatizari', 'control', 'masurare', 'knx', 'senzori', 'relee' ),
					),
					array(
						'title'    => __( 'Retelistica, date si telecomunicatii', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Cablare structurata, fibra optica, rack-uri, patching, SAT, telefonie si echipamente de retea.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'retelistica-date-si-telecomunicatii',
						'keywords' => array( 'retelistica', 'date', 'telecomunicatii', 'fibra optica', 'rack', 'patch' ),
					),
					array(
						'title'    => __( 'Securitate, detectie si control acces', 'schrack-woocommerce-sync' ),
						'text'     => __( 'Supraveghere video, detectie incendiu/efractie, interfoane, control acces si sisteme speciale.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'securitate-detectie-si-control-acces',
						'keywords' => array( 'securitate', 'detectie', 'control acces', 'supraveghere video', 'interfoane' ),
					),
					array(
						'title'    => __( 'Energie, UPS si fotovoltaice', 'schrack-woocommerce-sync' ),
						'text'     => __( 'UPS, baterii, PDU, e-mobility, management energie si sisteme fotovoltaice.', 'schrack-woocommerce-sync' ),
						'category_slug' => 'energie-ups-si-fotovoltaice',
						'keywords' => array( 'energie', 'ups', 'fotovoltaice', 'baterii', 'pdu', 'solar' ),
					),
				),
			),
			'products'    => array(
				'title'    => __( 'Produse recomandate pentru proiecte tehnice', 'schrack-woocommerce-sync' ),
				'subtitle' => __( 'Componente utilizate frecvent în instalații electrice, sisteme de securitate și proiecte de infrastructură tehnică.', 'schrack-woocommerce-sync' ),
			),
			'why'         => array(
				'title'   => __( 'De ce să alegi Syshub?', 'schrack-woocommerce-sync' ),
				'text'    => __( 'Syshub nu este doar un magazin online. Combinăm selecția de produse tehnice cu experiența din proiecte reale de instalații electrice, securitate și sisteme fotovoltaice. Te ajutăm să alegi componente compatibile, corect dimensionate și potrivite pentru aplicația ta.', 'schrack-woocommerce-sync' ),
				'columns' => array(
					array(
						'title' => __( 'Selecție tehnică', 'schrack-woocommerce-sync' ),
						'text'  => __( 'Produse alese pentru proiecte reale, nu doar listări generice de catalog.', 'schrack-woocommerce-sync' ),
					),
					array(
						'title' => __( 'Suport la alegere', 'schrack-woocommerce-sync' ),
						'text'  => __( 'Dacă nu ești sigur ce componentă se potrivește, poți cere recomandare tehnică.', 'schrack-woocommerce-sync' ),
					),
					array(
						'title' => __( 'Soluții complete', 'schrack-woocommerce-sync' ),
						'text'  => __( 'De la cabluri și protecții până la CCTV, alarmare, automatizări și fotovoltaice.', 'schrack-woocommerce-sync' ),
					),
				),
			),
			'b2b'         => array(
				'title'                 => __( 'Ai o listă de materiale sau un proiect complet?', 'schrack-woocommerce-sync' ),
				'text'                  => __( 'Trimite-ne necesarul tău, iar echipa Syshub te poate ajuta cu identificarea produselor, verificarea compatibilității și pregătirea unei oferte adaptate proiectului.', 'schrack-woocommerce-sync' ),
				'material_button_text'  => __( 'Trimite lista de materiale', 'schrack-woocommerce-sync' ),
				'offer_button_text'     => __( 'Solicită ofertă', 'schrack-woocommerce-sync' ),
			),
			'final'       => array(
				'title'       => __( 'Nu știi exact ce produs se potrivește?', 'schrack-woocommerce-sync' ),
				'text'        => __( 'Spune-ne ce vrei să instalezi sau trimite-ne lista de materiale. Te ajutăm să găsești soluția potrivită pentru proiectul tău.', 'schrack-woocommerce-sync' ),
				'button_text' => __( 'Contactează echipa Syshub', 'schrack-woocommerce-sync' ),
			),
		);
	}

	/**
	 * Renders a non-catalog hero panel with curated technical domains.
	 *
	 * @param array<int,array<string,mixed>> $cards Hero visual cards.
	 * @param array<string,mixed>            $settings Widget settings.
	 * @param array<int,WP_Term>             $terms Loaded product category terms.
	 */
	private function hero_visual( array $cards, array $settings, array $terms, string $shop_url ): string {
		ob_start();
		?>
		<div class="schrack-home__hero-panel" aria-label="<?php esc_attr_e( 'Domenii tehnice Syshub Shop', 'schrack-woocommerce-sync' ); ?>">
			<?php foreach ( $cards as $card ) : ?>
				<?php $url = $this->homepage_card_url( $settings, $card, $terms, $shop_url ); ?>
				<a class="schrack-home__hero-domain is-<?php echo esc_attr( $card['variant'] ); ?>" href="<?php echo esc_url( $url ); ?>">
					<span aria-hidden="true"></span>
					<strong><?php echo esc_html( $card['title'] ); ?></strong>
					<p><?php echo esc_html( $card['text'] ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the trust/benefit strip.
	 *
	 * @param array<int,string> $items Benefit text list.
	 */
	private function trust_band( array $items ): string {
		ob_start();
		?>
		<div class="schrack-home__trust" aria-label="<?php esc_attr_e( 'Avantaje Syshub Shop', 'schrack-woocommerce-sync' ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<div class="schrack-home__trust-item">
					<span aria-hidden="true"></span>
					<strong><?php echo esc_html( $item ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders curated project-based navigation.
	 *
	 * @param array<string,mixed> $copy Project section copy.
	 * @param array<int,WP_Term> $terms Loaded product category terms.
	 * @param array<string,mixed> $settings Widget settings.
	 */
	private function project_navigation( array $copy, array $terms, array $settings, string $shop_url ): string {
		ob_start();
		?>
		<div class="schrack-home__pathways">
			<div class="schrack-home__section-head is-wide">
				<div>
					<span><?php echo esc_html( $copy['title'] ); ?></span>
					<p><?php echo esc_html( $copy['subtitle'] ); ?></p>
				</div>
			</div>

			<div class="schrack-home__path-grid">
				<?php foreach ( $copy['cards'] as $card ) : ?>
					<?php $product_url = $this->homepage_card_url( $settings, $card, $terms, $shop_url ); ?>
					<article class="schrack-home__path-card is-<?php echo esc_attr( $card['variant'] ); ?>">
						<div class="schrack-home__path-head">
							<div class="schrack-home__path-icon" aria-hidden="true"></div>
							<div class="schrack-home__path-copy">
								<strong><?php echo esc_html( $card['title'] ); ?></strong>
							</div>
						</div>
						<p class="schrack-home__path-text"><?php echo esc_html( $card['text'] ); ?></p>
						<div class="schrack-home__path-actions">
							<a class="schrack-home__mini-button" href="<?php echo esc_url( $product_url ); ?>"><?php esc_html_e( 'Vezi produse', 'schrack-woocommerce-sync' ); ?></a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders human-curated categories instead of raw imported category names.
	 *
	 * @param array<string,mixed> $copy Category section copy.
	 * @param array<int,WP_Term> $terms Loaded product category terms.
	 */
	private function curated_categories_section( array $copy, array $terms, string $shop_url ): string {
		ob_start();
		?>
		<div class="schrack-home__curated">
			<div class="schrack-home__section-head is-wide">
				<div>
					<span><?php echo esc_html( $copy['title'] ); ?></span>
					<p><?php echo esc_html( $copy['subtitle'] ); ?></p>
				</div>
			</div>

			<div class="schrack-home__curated-grid">
				<?php foreach ( $copy['cards'] as $index => $card ) : ?>
					<?php $url = $this->category_card_url( $card, $terms, $shop_url ); ?>
					<a class="schrack-home__curated-card" href="<?php echo esc_url( $url ); ?>">
						<span class="schrack-home__curated-marker" aria-hidden="true"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
						<strong><?php echo esc_html( $card['title'] ); ?></strong>
						<span><?php echo esc_html( $card['text'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders recommended products for technical projects.
	 *
	 * @param array<string,string> $copy Product section copy.
	 * @param array<int,WP_Term>  $terms Loaded product category terms.
	 * @param array<string,mixed> $settings Widget settings.
	 */
	private function recommended_products_section( array $copy, array $terms, array $settings ): string {
		$products = $this->recommended_products( $terms, $settings );

		if ( empty( $products ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="schrack-home__recommended">
			<div class="schrack-home__section-head is-wide">
				<div>
					<span><?php echo esc_html( $copy['title'] ); ?></span>
					<p><?php echo esc_html( $copy['subtitle'] ); ?></p>
				</div>
			</div>

			<div class="schrack-home__product-grid">
				<?php foreach ( $products as $product ) : ?>
					<?php echo $this->product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns a small product set from curated technical categories.
	 *
	 * @param array<int,WP_Term>  $terms Loaded product category terms.
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<int,WC_Product>
	 */
	private function recommended_products( array $terms, array $settings ): array {
		if ( ! function_exists( 'wc_get_products' ) || (int) $settings['recommended_product_limit'] <= 0 ) {
			return array();
		}

		$args = array(
			'status'  => 'publish',
			'limit'   => (int) $settings['recommended_product_limit'],
			'orderby' => 'popularity',
			'order'   => 'DESC',
			'return'  => 'objects',
		);

		$slugs = $this->recommended_term_slugs( $terms );

		if ( ! empty( $slugs ) ) {
			$args['category'] = $slugs;
		}

		$products = wc_get_products( $args );

		if ( empty( $products ) && isset( $args['category'] ) ) {
			unset( $args['category'] );
			$products = wc_get_products( $args );
		}

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
	 * Builds product-category slugs from the curated category definitions.
	 *
	 * @param array<int,WP_Term> $terms Loaded product category terms.
	 * @return array<int,string>
	 */
	private function recommended_term_slugs( array $terms ): array {
		$copy  = $this->homepage_copy();
		$slugs = array();

		foreach ( $copy['categories']['cards'] as $card ) {
			$category_slug = isset( $card['category_slug'] ) ? sanitize_title( (string) $card['category_slug'] ) : '';

			if ( '' !== $category_slug ) {
				$slugs[] = $category_slug;
				continue;
			}

			$term = $this->best_term_for_keywords( $terms, $card['keywords'] );

			if ( $term instanceof WP_Term && '' !== $term->slug ) {
				$slugs[] = $term->slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Renders a compact product card.
	 */
	private function product_card( WC_Product $product ): string {
		$link       = $product->get_permalink();
		$price_html = $product->get_price_html();

		ob_start();
		?>
		<article class="schrack-home__product-card">
			<a class="schrack-home__product-image" href="<?php echo esc_url( $link ); ?>">
				<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) ); ?>
			</a>
			<div class="schrack-home__product-body">
				<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
				<?php if ( '' !== $price_html ) : ?>
					<span class="schrack-home__product-price"><?php echo wp_kses_post( $price_html ); ?></span>
				<?php endif; ?>
				<a class="schrack-home__mini-button" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Vezi produs', 'schrack-woocommerce-sync' ); ?></a>
			</div>
		</article>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the Syshub reasoning block.
	 *
	 * @param array<string,mixed> $copy Why Syshub copy.
	 */
	private function why_syshub( array $copy ): string {
		ob_start();
		?>
		<div class="schrack-home__why">
			<div class="schrack-home__why-copy">
				<span><?php echo esc_html( $copy['title'] ); ?></span>
				<p><?php echo esc_html( $copy['text'] ); ?></p>
			</div>
			<div class="schrack-home__why-grid">
				<?php foreach ( $copy['columns'] as $column ) : ?>
					<article class="schrack-home__why-card">
						<strong><?php echo esc_html( $column['title'] ); ?></strong>
						<p><?php echo esc_html( $column['text'] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the B2B material-list and offer CTA block.
	 *
	 * @param array<string,string> $copy B2B copy.
	 * @param array<string,mixed>  $settings Widget settings.
	 */
	private function b2b_offer_block( array $copy, array $settings ): string {
		ob_start();
		?>
		<div class="schrack-home__offer">
			<div>
				<span><?php echo esc_html( $copy['title'] ); ?></span>
				<p><?php echo esc_html( $copy['text'] ); ?></p>
			</div>
			<div class="schrack-home__offer-actions">
				<a class="schrack-home__button" href="<?php echo esc_url( $settings['material_list_url'] ); ?>"><?php echo esc_html( $copy['material_button_text'] ); ?></a>
				<a class="schrack-home__ghost-button" href="<?php echo esc_url( $settings['offer_url'] ); ?>"><?php echo esc_html( $copy['offer_button_text'] ); ?></a>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the closing consultation CTA.
	 *
	 * @param array<string,string> $copy Final CTA copy.
	 */
	private function closing_cta( array $copy, string $contact_url ): string {
		ob_start();
		?>
		<div class="schrack-home__final">
			<div>
				<strong><?php echo esc_html( $copy['title'] ); ?></strong>
				<p><?php echo esc_html( $copy['text'] ); ?></p>
			</div>
			<div class="schrack-home__final-actions">
				<a class="schrack-home__button" href="<?php echo esc_url( $contact_url ); ?>"><?php echo esc_html( $copy['button_text'] ); ?></a>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns the configured category URL for a homepage card, with keyword fallback.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @param array<string,mixed> $card Card copy and category mapping.
	 * @param array<int,WP_Term>  $terms Loaded product category terms.
	 */
	private function homepage_card_url( array $settings, array $card, array $terms, string $shop_url ): string {
		$setting_key = isset( $card['category_setting'] ) ? (string) $card['category_setting'] : '';
		$category_id = '' !== $setting_key ? absint( $settings[ $setting_key ] ?? 0 ) : 0;

		if ( $category_id > 0 ) {
			$url = $this->category_url_by_id( $category_id );

			if ( '' !== $url ) {
				return $url;
			}
		}

		$category_slug = isset( $card['category_slug'] ) ? sanitize_title( (string) $card['category_slug'] ) : '';

		if ( '' !== $category_slug ) {
			$url = $this->category_url_by_slug( $category_slug, $terms );

			if ( '' !== $url ) {
				return $url;
			}
		}

		$keywords = isset( $card['keywords'] ) && is_array( $card['keywords'] ) ? $card['keywords'] : array();

		return ! empty( $keywords ) ? $this->curated_term_url( $terms, $keywords, $shop_url ) : $shop_url;
	}

	/**
	 * Returns a product category URL by term ID.
	 */
	private function category_url_by_id( int $category_id ): string {
		if ( $category_id <= 0 || ! taxonomy_exists( 'product_cat' ) ) {
			return '';
		}

		$term = get_term( $category_id, 'product_cat' );

		if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
			return '';
		}

		$link = get_term_link( $term );

		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/**
	 * Returns a product category URL by slug.
	 *
	 * @param array<int,WP_Term> $terms Already loaded terms.
	 */
	private function category_url_by_slug( string $slug, array $terms = array() ): string {
		$term = $this->category_term_by_slug( $slug, $terms );

		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		$link = get_term_link( $term );

		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/**
	 * Returns a loaded or queried product category term by slug.
	 *
	 * @param array<int,WP_Term> $terms Already loaded terms.
	 */
	private function category_term_by_slug( string $slug, array $terms = array() ): ?WP_Term {
		$slug = sanitize_title( $slug );

		if ( '' === $slug || ! taxonomy_exists( 'product_cat' ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && $slug === $term->slug ) {
				return $term;
			}
		}

		$term = get_term_by( 'slug', $slug, 'product_cat' );

		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Returns the configured category URL for one curated category card.
	 *
	 * @param array<string,mixed> $card Card copy and category mapping.
	 * @param array<int,WP_Term>  $terms Loaded product category terms.
	 */
	private function category_card_url( array $card, array $terms, string $shop_url ): string {
		$category_slug = isset( $card['category_slug'] ) ? sanitize_title( (string) $card['category_slug'] ) : '';

		if ( '' !== $category_slug ) {
			$url = $this->category_url_by_slug( $category_slug, $terms );

			if ( '' !== $url ) {
				return $url;
			}
		}

		$keywords = isset( $card['keywords'] ) && is_array( $card['keywords'] ) ? $card['keywords'] : array();

		return ! empty( $keywords ) ? $this->curated_term_url( $terms, $keywords, $shop_url ) : $shop_url;
	}

	/**
	 * Returns the best matching category URL for a curated card.
	 *
	 * @param array<int,WP_Term> $terms Loaded product category terms.
	 * @param array<int,string>  $keywords Matching keywords.
	 */
	private function curated_term_url( array $terms, array $keywords, string $shop_url ): string {
		$term = $this->best_term_for_keywords( $terms, $keywords );

		if ( ! $term instanceof WP_Term ) {
			return $shop_url;
		}

		$link = get_term_link( $term );

		return is_wp_error( $link ) ? $shop_url : (string) $link;
	}

	/**
	 * Finds the strongest term match without exposing raw imported names in the UI.
	 *
	 * @param array<int,WP_Term> $terms Loaded product category terms.
	 * @param array<int,string>  $keywords Matching keywords.
	 */
	private function best_term_for_keywords( array $terms, array $keywords ): ?WP_Term {
		$matches = $this->matched_terms( $terms, $keywords, 1 );

		return $matches[0] ?? null;
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
				<?php $link = get_term_link( $term ); ?>
				<a class="schrack-home__visual-card <?php echo 0 === $index ? 'is-large' : ''; ?>" href="<?php echo esc_url( is_wp_error( $link ) ? '#' : $link ); ?>">
					<?php echo $this->term_image( $term, 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="schrack-home__visual-caption">
						<strong><?php echo esc_html( $term->name ); ?></strong>
						<span><?php echo esc_html( sprintf( __( '%d produse in catalog', 'schrack-woocommerce-sync' ), (int) $term->count ) ); ?></span>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders project-entry cards that keep the next step commerce-oriented.
	 *
	 * @param array<int,WP_Term> $terms Category terms.
	 * @param bool               $fallback_to_selected_terms Use the selected list when keyword matching is empty.
	 */
	private function project_paths( array $terms, string $shop_url, bool $fallback_to_selected_terms = false ): string {
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
					$matched_terms = $this->matched_terms( $terms, $path['keywords'], 3, $fallback_to_selected_terms );
					$product_url   = $this->term_collection_url( $matched_terms, $shop_url );
					?>
					<div class="schrack-home__path-card is-<?php echo esc_attr( $path['variant'] ); ?>" role="group" aria-label="<?php echo esc_attr( $path['label'] . ': ' . $path['title'] ); ?>">
						<div class="schrack-home__path-head">
							<div class="schrack-home__path-icon" aria-hidden="true"></div>
							<div class="schrack-home__path-copy">
								<small><?php echo esc_html( $path['label'] ); ?></small>
								<strong><?php echo esc_html( $path['title'] ); ?></strong>
							</div>
						</div>
						<p class="schrack-home__path-text"><?php echo esc_html( $path['text'] ); ?></p>
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
					</div>
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
	 * @param bool               $fallback_to_selected_terms Use the selected list when keyword matching is empty.
	 */
	private function shop_bridge( array $terms, string $shop_url, bool $fallback_to_selected_terms = false ): string {
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
					$matched_terms = $this->matched_terms( $terms, $service['keywords'], 4, $fallback_to_selected_terms );
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
				<span><?php esc_html_e( 'Categorii principale de produse', 'schrack-woocommerce-sync' ); ?></span>
				<p><?php esc_html_e( 'Porneste din domenii tehnice clare, fara categorii brute importate pe prima pagina.', 'schrack-woocommerce-sync' ); ?></p>
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
	 * @param bool               $fallback_to_terms Return the first terms when no keyword match exists.
	 * @return array<int,WP_Term>
	 */
	private function matched_terms( array $terms, array $keywords, int $limit, bool $fallback_to_terms = false ): array {
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

		$matched_terms = array_map(
			static fn( array $item ): WP_Term => $item['term'],
			array_slice( $scored, 0, $limit )
		);

		if ( empty( $matched_terms ) && $fallback_to_terms ) {
			return array_slice( $terms, 0, $limit );
		}

		return $matched_terms;
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
