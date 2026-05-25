<?php
/**
 * Elementor header renderer.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Header_Renderer {
	/**
	 * Renders the header module.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		$settings    = $this->sanitize_settings( $settings );
		$instance_id = '' !== $instance_id ? 'schrack-header-' . sanitize_html_class( $instance_id ) : wp_unique_id( 'schrack-header-' );
		$panel_id    = $instance_id . '-menu';
		$classes     = array( 'schrack-header' );

		if ( 'yes' === $settings['is_sticky'] ) {
			$classes[] = 'is-sticky';
		}

		$style = sprintf(
			'--schrack-header-accent:%1$s;--schrack-header-action:%2$s;--schrack-header-radius:%3$dpx;--schrack-header-width:%4$dpx;',
			esc_attr( $settings['accent_color'] ),
			esc_attr( $settings['action_color'] ),
			(int) $settings['radius'],
			(int) $settings['max_width']
		);

		wp_enqueue_style( 'schrack-wc-header' );
		wp_enqueue_script( 'schrack-wc-header' );

		ob_start();
		?>
		<header
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
			data-schrack-header
		>
			<?php echo $this->eu_top_bar( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="schrack-header__inner">
				<a class="schrack-header__brand" href="<?php echo esc_url( $settings['site_url'] ); ?>" aria-label="<?php echo esc_attr( $settings['company_name'] ); ?>">
					<span class="schrack-header__logo" aria-hidden="true">
						<?php if ( '' !== $settings['logo_url'] ) : ?>
							<img src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" loading="eager">
						<?php else : ?>
							<span><?php echo esc_html( $this->brand_initials( $settings['brand_name'] ) ); ?></span>
						<?php endif; ?>
					</span>
					<?php if ( 'yes' === $settings['show_brand_text'] ) : ?>
						<span class="schrack-header__brand-text">
							<strong><?php echo esc_html( $settings['brand_name'] ); ?></strong>
							<?php if ( '' !== $settings['brand_suffix'] ) : ?>
								<small><?php echo esc_html( $settings['brand_suffix'] ); ?></small>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</a>

				<?php echo $this->search_module( $settings, $instance_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="schrack-header__actions">
					<?php echo $this->account_link( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->cart_link( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<button class="schrack-header__menu-toggle" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-header-menu-toggle>
						<span class="schrack-header__hamburger" aria-hidden="true">
							<span></span>
							<span></span>
							<span></span>
						</span>
						<span class="schrack-header__action-label"><?php echo esc_html( $settings['menu_label'] ); ?></span>
					</button>
				</div>
			</div>

			<div class="schrack-header__backdrop" hidden data-header-menu-close></div>

			<aside id="<?php echo esc_attr( $panel_id ); ?>" class="schrack-header__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $settings['menu_label'] ); ?>" hidden data-header-menu-panel>
				<div class="schrack-header__panel-head">
					<a class="schrack-header__panel-brand" href="<?php echo esc_url( $settings['site_url'] ); ?>">
						<span class="schrack-header__panel-logo" aria-hidden="true">
							<?php if ( '' !== $settings['logo_url'] ) : ?>
								<img src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="" loading="lazy">
							<?php else : ?>
								<span><?php echo esc_html( $this->brand_initials( $settings['brand_name'] ) ); ?></span>
							<?php endif; ?>
						</span>
						<span><?php echo esc_html( $settings['brand_name'] ); ?></span>
					</a>
					<button class="schrack-header__panel-close" type="button" aria-label="<?php esc_attr_e( 'Inchide meniul', 'schrack-woocommerce-sync' ); ?>" data-header-menu-close>
						<?php echo $this->icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
				</div>

				<nav class="schrack-header__menu" aria-label="<?php echo esc_attr( $settings['menu_label'] ); ?>">
					<?php echo $this->menu_html( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</nav>

				<div class="schrack-header__panel-actions">
					<?php echo $this->account_link( $settings, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->cart_link( $settings, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</aside>
		</header>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns the cart count fragment for WooCommerce AJAX refreshes.
	 */
	public function cart_count_fragment(): string {
		return '<span class="schrack-header__cart-count" data-schrack-header-cart-count>' . esc_html( (string) $this->cart_count() ) . '</span>';
	}

	/**
	 * Returns the cart total fragment for WooCommerce AJAX refreshes.
	 */
	public function cart_total_fragment(): string {
		return '<span class="schrack-header__cart-total" data-schrack-header-cart-total>' . wp_kses_post( $this->cart_total() ) . '</span>';
	}

	/**
	 * Normalizes widget settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,string|int>
	 */
	private function sanitize_settings( array $settings ): array {
		$defaults = array(
			'company_name'        => 'GENE SYS SECURITY SRL',
			'brand_name'          => 'GENE SYS SECURITY',
			'brand_suffix'        => 'SHOP',
			'logo_url'            => $this->default_logo_url(),
			'site_url'            => home_url( '/' ),
			'menu_id'             => 0,
			'show_brand_text'     => 'yes',
			'show_account'        => 'yes',
			'show_cart'           => 'yes',
			'show_cart_total'     => 'yes',
			'show_search'         => 'yes',
			'show_search_images'  => 'yes',
			'show_search_price'   => 'yes',
			'show_search_sku'     => 'yes',
			'show_search_stock'   => 'yes',
			'show_eu_logos'       => 'yes',
			'search_placeholder'  => __( 'Cauta produse, coduri, SKU...', 'schrack-woocommerce-sync' ),
			'search_button_text'  => __( 'Cauta', 'schrack-woocommerce-sync' ),
			'search_min_chars'    => 3,
			'search_max_results'  => 8,
			'search_enable_fuzzy' => 'yes',
			'search_fuzzy_pool'   => 120,
			'is_sticky'           => 'no',
			'cart_label'          => __( 'Cos', 'schrack-woocommerce-sync' ),
			'account_label'       => __( 'Contul meu', 'schrack-woocommerce-sync' ),
			'login_label'         => __( 'Autentificare', 'schrack-woocommerce-sync' ),
			'menu_label'          => __( 'Meniu', 'schrack-woocommerce-sync' ),
			'eu_link_url'         => 'https://oportunitati-ue.gov.ro/',
			'accent_color'        => '#135e96',
			'action_color'        => '#b32d2e',
			'max_width'           => 1280,
			'radius'              => 8,
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'company_name', 'brand_name', 'brand_suffix', 'cart_label', 'account_label', 'login_label', 'menu_label', 'search_placeholder', 'search_button_text' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_brand_text', 'show_account', 'show_cart', 'show_cart_total', 'show_search', 'show_search_images', 'show_search_price', 'show_search_sku', 'show_search_stock', 'show_eu_logos', 'search_enable_fuzzy', 'is_sticky' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		$settings['logo_url']           = esc_url_raw( (string) $settings['logo_url'] );
		$settings['site_url']           = esc_url_raw( (string) $settings['site_url'] );
		$settings['eu_link_url']        = esc_url_raw( (string) $settings['eu_link_url'] );
		$settings['menu_id']            = absint( $settings['menu_id'] );
		$settings['accent_color']       = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['action_color']       = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['max_width']          = $this->slider_size( $settings['max_width'], 960, 1440 );
		$settings['radius']             = $this->slider_size( $settings['radius'], 0, 8 );
		$settings['search_min_chars']   = max( 3, min( 5, absint( $settings['search_min_chars'] ) ) );
		$settings['search_max_results'] = max( 3, min( 12, absint( $settings['search_max_results'] ) ) );
		$settings['search_fuzzy_pool']  = max( 40, min( 240, absint( $settings['search_fuzzy_pool'] ) ) );

		if ( '' === $settings['site_url'] ) {
			$settings['site_url'] = home_url( '/' );
		}

		return $settings;
	}

	/**
	 * Renders the EU funding logo strip above the main header.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function eu_top_bar( array $settings ): string {
		if ( 'yes' !== $settings['show_eu_logos'] ) {
			return '';
		}

		ob_start();
		?>
		<div class="schrack-header__eu-top">
			<div class="schrack-header__eu-inner" aria-label="<?php esc_attr_e( 'Logo-uri finantare europeana', 'schrack-woocommerce-sync' ); ?>">
				<?php foreach ( $this->eu_logos() as $logo ) : ?>
					<?php if ( '' !== $settings['eu_link_url'] ) : ?>
						<a class="<?php echo esc_attr( 'schrack-header__eu-logo ' . $logo['class'] ); ?>" href="<?php echo esc_url( $settings['eu_link_url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $logo['alt'] ); ?>">
							<img src="<?php echo esc_url( $logo['src'] ); ?>" alt="<?php echo esc_attr( $logo['alt'] ); ?>" loading="eager">
						</a>
					<?php else : ?>
						<span class="<?php echo esc_attr( 'schrack-header__eu-logo ' . $logo['class'] ); ?>">
							<img src="<?php echo esc_url( $logo['src'] ); ?>" alt="<?php echo esc_attr( $logo['alt'] ); ?>" loading="eager">
						</span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the embedded product search.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function search_module( array $settings, string $instance_id ): string {
		if ( 'yes' !== $settings['show_search'] || ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		$renderer = new Schrack_Header_Search_Renderer();
		$search_settings = array(
			'placeholder'  => $settings['search_placeholder'],
			'button_text'  => $settings['search_button_text'],
			'min_chars'    => $settings['search_min_chars'],
			'max_results'  => $settings['search_max_results'],
			'max_width'    => 620,
			'show_images'  => $settings['show_search_images'],
			'show_price'   => $settings['show_search_price'],
			'show_sku'     => $settings['show_search_sku'],
			'show_stock'   => $settings['show_search_stock'],
			'enable_fuzzy' => $settings['search_enable_fuzzy'],
			'fuzzy_pool'   => $settings['search_fuzzy_pool'],
			'accent_color' => $settings['accent_color'],
			'action_color' => $settings['action_color'],
			'radius'       => $settings['radius'],
		);

		return '<div class="schrack-header__search">' . $renderer->render( $search_settings, $instance_id . '-search' ) . '</div>';
	}

	/**
	 * Renders an account action link.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function account_link( array $settings, bool $panel = false ): string {
		if ( 'yes' !== $settings['show_account'] ) {
			return '';
		}

		$label = is_user_logged_in() ? (string) $settings['account_label'] : (string) $settings['login_label'];
		$class = 'schrack-header__action schrack-header__account';

		if ( $panel ) {
			$class .= ' is-panel-action';
		}

		ob_start();
		?>
		<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $this->account_url() ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
			<?php echo $this->icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="schrack-header__action-label"><?php echo esc_html( $label ); ?></span>
		</a>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a cart action link.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function cart_link( array $settings, bool $panel = false ): string {
		if ( 'yes' !== $settings['show_cart'] ) {
			return '';
		}

		$class = 'schrack-header__action schrack-header__cart';

		if ( $panel ) {
			$class .= ' is-panel-action';
		}

		ob_start();
		?>
		<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $this->cart_url() ); ?>" aria-label="<?php echo esc_attr( $settings['cart_label'] ); ?>">
			<?php echo $this->icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="schrack-header__action-label"><?php echo esc_html( $settings['cart_label'] ); ?></span>
			<?php echo $this->cart_count_fragment(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( 'yes' === $settings['show_cart_total'] ) : ?>
				<?php echo $this->cart_total_fragment(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</a>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the selected WordPress menu or a compact fallback.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function menu_html( array $settings ): string {
		$menu = '';

		if ( (int) $settings['menu_id'] > 0 ) {
			$menu = wp_nav_menu(
				array(
					'container'      => false,
					'depth'          => 3,
					'echo'           => false,
					'fallback_cb'    => false,
					'item_spacing'   => 'discard',
					'menu'           => (int) $settings['menu_id'],
					'menu_class'     => 'schrack-header__menu-list',
					'theme_location' => '',
				)
			);
		}

		if ( is_string( $menu ) && '' !== trim( $menu ) ) {
			return $menu;
		}

		ob_start();
		?>
		<ul class="schrack-header__menu-list">
			<?php foreach ( $this->fallback_links() as $link ) : ?>
				<li><a href="<?php echo esc_url( $link['href'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns fallback links for sites that have not selected a menu yet.
	 *
	 * @return array<int,array{label:string,href:string}>
	 */
	private function fallback_links(): array {
		return array(
			array(
				'label' => __( 'Acasa', 'schrack-woocommerce-sync' ),
				'href'  => home_url( '/' ),
			),
			array(
				'label' => __( 'Magazin', 'schrack-woocommerce-sync' ),
				'href'  => $this->shop_url(),
			),
			array(
				'label' => __( 'Contul meu', 'schrack-woocommerce-sync' ),
				'href'  => $this->account_url(),
			),
			array(
				'label' => __( 'Cos', 'schrack-woocommerce-sync' ),
				'href'  => $this->cart_url(),
			),
			array(
				'label' => __( 'Termeni si conditii', 'schrack-woocommerce-sync' ),
				'href'  => $this->legal_url( 'terms' ),
			),
			array(
				'label' => __( 'Livrare si plata', 'schrack-woocommerce-sync' ),
				'href'  => $this->legal_url( 'delivery' ),
			),
			array(
				'label' => __( 'Retur si rambursare', 'schrack-woocommerce-sync' ),
				'href'  => $this->legal_url( 'returns' ),
			),
		);
	}

	/**
	 * Returns EU funding logo metadata.
	 *
	 * @return array<int,array{alt:string,class:string,src:string}>
	 */
	private function eu_logos(): array {
		return array(
			array(
				'alt'   => __( 'Cofinantat de Uniunea Europeana', 'schrack-woocommerce-sync' ),
				'class' => 'is-eu',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/uniunea-europeana-cofinantat.png',
			),
			array(
				'alt'   => __( 'Guvernul Romaniei', 'schrack-woocommerce-sync' ),
				'class' => 'is-government',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/guvernul-romaniei.png',
			),
			array(
				'alt'   => 'REGIO Nord-Vest',
				'class' => 'is-regio',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/regio-nord-vest.png',
			),
			array(
				'alt'   => __( 'Agentia de Dezvoltare Regionala Nord-Vest', 'schrack-woocommerce-sync' ),
				'class' => 'is-adr',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/adr-nord-vest.svg',
			),
		);
	}

	/**
	 * Returns the generated legal page URL.
	 */
	private function legal_url( string $type ): string {
		if ( class_exists( 'Schrack_Legal_Pages' ) ) {
			return Schrack_Legal_Pages::page_url( $type );
		}

		return home_url( '/' );
	}

	/**
	 * Returns the default logo URL from the theme or the Syshub fallback.
	 */
	private function default_logo_url(): string {
		$custom_logo_id = absint( get_theme_mod( 'custom_logo' ) );

		if ( $custom_logo_id > 0 ) {
			$logo = wp_get_attachment_image_url( $custom_logo_id, 'full' );

			if ( is_string( $logo ) && '' !== $logo ) {
				return $logo;
			}
		}

		return 'https://syshub.ro/assets/genesys-logo-D16z0xlU.svg';
	}

	/**
	 * Returns initials for the logo fallback.
	 */
	private function brand_initials( string $brand ): string {
		$words    = preg_split( '/\s+/', trim( $brand ) );
		$initials = '';

		if ( ! is_array( $words ) ) {
			return 'S';
		}

		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}

			$initials .= strtoupper( substr( $word, 0, 1 ) );

			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}

		return '' !== $initials ? $initials : 'S';
	}

	/**
	 * Returns the WooCommerce cart URL.
	 */
	private function cart_url(): string {
		if ( function_exists( 'wc_get_cart_url' ) ) {
			return wc_get_cart_url();
		}

		return home_url( '/cart/' );
	}

	/**
	 * Returns the WooCommerce account URL.
	 */
	private function account_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'myaccount' );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return is_user_logged_in() ? admin_url( 'profile.php' ) : wp_login_url();
	}

	/**
	 * Returns the WooCommerce shop URL.
	 */
	private function shop_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/shop/' );
	}

	/**
	 * Returns the current WooCommerce cart count.
	 */
	private function cart_count(): int {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;

		if ( is_object( $woocommerce ) && isset( $woocommerce->cart ) && $woocommerce->cart ) {
			return (int) $woocommerce->cart->get_cart_contents_count();
		}

		return 0;
	}

	/**
	 * Returns the current WooCommerce cart total HTML.
	 */
	private function cart_total(): string {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;

		if ( is_object( $woocommerce ) && isset( $woocommerce->cart ) && $woocommerce->cart ) {
			return (string) $woocommerce->cart->get_cart_total();
		}

		return '';
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
	 * Returns a small inline icon.
	 */
	private function icon( string $name ): string {
		$icons = array(
			'cart'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.4 6h15l-1.7 8.3a2 2 0 0 1-2 1.7H9.1a2 2 0 0 1-2-1.6L5.4 3H2.8"></path><circle cx="9.5" cy="20" r="1.5"></circle><circle cx="17.5" cy="20" r="1.5"></circle></svg>',
			'close' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>',
			'user'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg>',
		);

		return '<span class="schrack-header__icon">' . ( $icons[ $name ] ?? '' ) . '</span>';
	}
}
