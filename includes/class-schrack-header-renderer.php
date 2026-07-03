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
			<div class="schrack-header__inner">
				<a class="schrack-header__brand" href="<?php echo esc_url( $settings['site_url'] ); ?>" aria-label="<?php echo esc_attr( $settings['brand_name'] ); ?>">
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
					<?php echo $this->offer_link( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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

			<?php echo $this->desktop_navigation( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo $this->eu_top_bar( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="schrack-header__backdrop" hidden data-header-menu-close></div>

			<aside id="<?php echo esc_attr( $panel_id ); ?>" class="schrack-header__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $settings['menu_label'] ); ?>" hidden data-header-menu-panel>
				<div class="schrack-header__panel-head">
					<a class="schrack-header__panel-brand" href="<?php echo esc_url( $settings['site_url'] ); ?>" aria-label="<?php echo esc_attr( $settings['brand_name'] ); ?>">
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
					<?php echo $this->menu_html( $settings, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
			'brand_name'          => 'SysHUB',
			'brand_suffix'        => '',
			'logo_url'            => $this->default_logo_url(),
			'site_url'            => home_url( '/' ),
			'menu_id'             => 0,
			'show_brand_text'     => 'yes',
			'show_account'        => 'yes',
			'show_cart'           => 'yes',
			'show_cart_total'     => 'yes',
			'show_offer'          => 'yes',
			'show_search'         => 'yes',
			'show_search_images'  => 'yes',
			'show_search_price'   => 'yes',
			'show_search_stock'   => 'yes',
			'show_eu_logos'       => 'yes',
			'search_placeholder'  => __( 'Caută produse, coduri, categorii...', 'schrack-woocommerce-sync' ),
			'search_button_text'  => __( 'Caută', 'schrack-woocommerce-sync' ),
			'search_min_chars'    => 3,
			'search_max_results'  => 8,
			'search_enable_fuzzy' => 'yes',
			'search_fuzzy_pool'   => 120,
			'is_sticky'           => 'no',
			'cart_label'          => __( 'Coș', 'schrack-woocommerce-sync' ),
			'account_label'       => __( 'Cont', 'schrack-woocommerce-sync' ),
			'login_label'         => __( 'Cont', 'schrack-woocommerce-sync' ),
			'menu_label'          => __( 'Toate categoriile', 'schrack-woocommerce-sync' ),
			'offer_label'         => __( 'Cere ofertă', 'schrack-woocommerce-sync' ),
			'offer_url'           => home_url( '/contact/' ),
			'accent_color'        => '#102033',
			'action_color'        => '#f15a0a',
			'max_width'           => 1840,
			'radius'              => 8,
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'company_name', 'brand_name', 'brand_suffix', 'cart_label', 'account_label', 'login_label', 'menu_label', 'offer_label', 'search_placeholder', 'search_button_text' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		$settings = $this->normalize_brand_display( $settings );

		$legacy_labels = array(
			'search_placeholder' => array(
				'Cauta produse...' => $defaults['search_placeholder'],
			),
			'search_button_text' => array(
				'Cauta' => $defaults['search_button_text'],
			),
			'cart_label' => array(
				'Cos' => $defaults['cart_label'],
			),
			'account_label' => array(
				'Contul meu' => $defaults['account_label'],
			),
			'login_label' => array(
				'Autentificare' => $defaults['login_label'],
			),
			'menu_label' => array(
				'Meniu' => $defaults['menu_label'],
			),
		);

		foreach ( $legacy_labels as $key => $labels ) {
			if ( isset( $labels[ $settings[ $key ] ] ) ) {
				$settings[ $key ] = $labels[ $settings[ $key ] ];
			}
		}

		foreach ( array( 'show_brand_text', 'show_account', 'show_cart', 'show_cart_total', 'show_offer', 'show_search', 'show_search_images', 'show_search_price', 'show_search_stock', 'show_eu_logos', 'search_enable_fuzzy', 'is_sticky' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		$settings['logo_url']           = esc_url_raw( (string) $settings['logo_url'] );
		$settings['site_url']           = esc_url_raw( (string) $settings['site_url'] );
		$settings['offer_url']          = esc_url_raw( (string) $settings['offer_url'] );
		$settings['menu_id']            = absint( $settings['menu_id'] );
		$settings['accent_color']       = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['action_color']       = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['max_width']          = $this->slider_size( $settings['max_width'], 960, 1920 );
		$settings['radius']             = $this->slider_size( $settings['radius'], 0, 8 );
		$settings['search_min_chars']   = max( 3, min( 5, absint( $settings['search_min_chars'] ) ) );
		$settings['search_max_results'] = max( 3, min( 12, absint( $settings['search_max_results'] ) ) );
		$settings['search_fuzzy_pool']  = max( 40, min( 240, absint( $settings['search_fuzzy_pool'] ) ) );

		if ( '' === $settings['site_url'] ) {
			$settings['site_url'] = home_url( '/' );
		}

		if ( '' === $settings['offer_url'] ) {
			$settings['offer_url'] = home_url( '/contact/' );
		}

		return $settings;
	}

	/**
	 * Keeps older Elementor instances aligned with the current public brand name.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 * @return array<string,string|int>
	 */
	private function normalize_brand_display( array $settings ): array {
		$legacy_brand_names = array( 'GENE SYS SECURITY', 'GENE SYS SECURITY SRL', 'SYSHUB' );

		if ( in_array( strtoupper( (string) $settings['brand_name'] ), $legacy_brand_names, true ) ) {
			$settings['brand_name'] = 'SysHUB';
		}

		if ( 'syshub' === strtolower( (string) $settings['brand_name'] ) ) {
			$settings['brand_suffix'] = '';
		}

		return $settings;
	}

	/**
	 * Renders the desktop navigation row below the main header.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function desktop_navigation( array $settings ): string {
		ob_start();
		?>
		<nav class="schrack-header__nav" aria-label="<?php echo esc_attr( $settings['menu_label'] ); ?>">
			<div class="schrack-header__nav-inner">
				<div class="schrack-header__desktop-menu">
					<?php echo $this->menu_html( $settings, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</nav>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the EU funding logo strip below the main header.
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
					<a class="<?php echo esc_attr( 'schrack-header__eu-item ' . $logo['class'] ); ?>" href="<?php echo esc_url( $logo['href'] ); ?>" target="_blank" rel="noopener noreferrer">
						<img class="<?php echo esc_attr( 'schrack-header__eu-logo ' . $logo['class'] ); ?>" src="<?php echo esc_url( $logo['src'] ); ?>" alt="<?php echo esc_attr( $logo['alt'] ); ?>" loading="eager">
					</a>
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
			'max_width'    => 820,
			'show_images'  => $settings['show_search_images'],
			'show_price'   => $settings['show_search_price'],
			'show_stock'   => $settings['show_search_stock'],
			'enable_fuzzy' => $settings['search_enable_fuzzy'],
			'fuzzy_pool'   => $settings['search_fuzzy_pool'],
			'accent_color' => $settings['accent_color'],
			'action_color' => $settings['accent_color'],
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
	 * Renders the offer request action.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function offer_link( array $settings ): string {
		if ( 'yes' !== $settings['show_offer'] || '' === $settings['offer_url'] ) {
			return '';
		}

		ob_start();
		?>
		<a class="schrack-header__action schrack-header__offer" href="<?php echo esc_url( $settings['offer_url'] ); ?>">
			<span class="schrack-header__action-label"><?php echo esc_html( $settings['offer_label'] ); ?></span>
		</a>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the selected WordPress menu or a compact fallback.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function menu_html( array $settings, bool $desktop ): string {
		$nodes = array();

		if ( (int) $settings['menu_id'] > 0 ) {
			$nodes = $this->menu_nodes( (int) $settings['menu_id'] );
		}

		if ( empty( $nodes ) ) {
			$nodes = $this->fallback_links();
		}

		return $this->render_menu_nodes( $nodes, 0, $desktop );
	}

	/**
	 * Returns fallback links for sites that have not selected a menu yet.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fallback_links(): array {
		return array(
			array(
				'label'    => __( 'Toate categoriile', 'schrack-woocommerce-sync' ),
				'href'     => $this->shop_url(),
				'children' => array(),
			),
			array(
				'label'    => __( 'Electric', 'schrack-woocommerce-sync' ),
				'href'     => $this->product_category_url( 'electric', '/product-category/electric/' ),
				'children' => array(),
			),
			array(
				'label'    => __( 'Securitate', 'schrack-woocommerce-sync' ),
				'href'     => $this->product_category_url( 'securitate', '/product-category/securitate/' ),
				'children' => array(),
			),
			array(
				'label'    => __( 'Fotovoltaice', 'schrack-woocommerce-sync' ),
				'href'     => $this->product_category_url( 'fotovoltaice', '/product-category/fotovoltaice/' ),
				'children' => array(),
			),
			array(
				'label'    => __( 'Automatizări', 'schrack-woocommerce-sync' ),
				'href'     => $this->product_category_url( 'automatizari', '/product-category/automatizari/' ),
				'children' => array(),
			),
			array(
				'label'    => __( 'Rack & rețelistică', 'schrack-woocommerce-sync' ),
				'href'     => $this->product_category_url( 'rack-retelistica', '/product-category/rack-retelistica/' ),
				'children' => array(),
			),
			array(
				'label'    => __( 'B2B', 'schrack-woocommerce-sync' ),
				'href'     => home_url( '/b2b/' ),
				'children' => array(),
			),
			array(
				'label'    => __( 'Contact', 'schrack-woocommerce-sync' ),
				'href'     => home_url( '/contact/' ),
				'children' => array(),
			),
		);
	}

	/**
	 * Builds a menu tree from a selected WordPress menu.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function menu_nodes( int $menu_id ): array {
		if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}

		$items = wp_get_nav_menu_items(
			$menu_id,
			array(
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		$nodes_by_id        = array();
		$children_by_parent = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post || ! empty( $item->_invalid ) ) {
				continue;
			}

			$item_id   = (int) $item->ID;
			$parent_id = absint( $item->menu_item_parent );
			$classes   = is_array( $item->classes ) ? array_filter( array_map( 'sanitize_html_class', $item->classes ) ) : array();

			$nodes_by_id[ $item_id ] = array(
				'label'      => (string) $item->title,
				'href'       => '' !== (string) $item->url ? (string) $item->url : '#',
				'target'     => (string) $item->target,
				'rel'        => (string) $item->xfn,
				'title_attr' => (string) $item->attr_title,
				'classes'    => $classes,
				'children'   => array(),
			);

			$children_by_parent[ $parent_id ][] = $item_id;
		}

		$build = function ( int $parent_id, int $depth ) use ( &$build, $nodes_by_id, $children_by_parent ): array {
			if ( $depth >= 3 || empty( $children_by_parent[ $parent_id ] ) ) {
				return array();
			}

			$nodes = array();

			foreach ( $children_by_parent[ $parent_id ] as $child_id ) {
				if ( empty( $nodes_by_id[ $child_id ] ) ) {
					continue;
				}

				$node             = $nodes_by_id[ $child_id ];
				$node['children'] = $build( $child_id, $depth + 1 );
				$nodes[]          = $node;
			}

			return $nodes;
		};

		return $build( 0, 0 );
	}

	/**
	 * Renders a menu tree without duplicate WordPress menu item IDs.
	 *
	 * @param array<int,array<string,mixed>> $nodes Menu nodes.
	 */
	private function render_menu_nodes( array $nodes, int $depth, bool $desktop ): string {
		if ( empty( $nodes ) ) {
			return '';
		}

		$list_classes = array( 'schrack-header__menu-list' );

		if ( $depth > 0 ) {
			$list_classes[] = 'sub-menu';
		}

		ob_start();
		?>
		<ul class="<?php echo esc_attr( implode( ' ', $list_classes ) ); ?>">
			<?php foreach ( $nodes as $index => $node ) : ?>
				<?php
				$children     = is_array( $node['children'] ?? null ) ? $node['children'] : array();
				$item_classes = array( 'schrack-header__menu-item' );

				if ( ! empty( $node['classes'] ) && is_array( $node['classes'] ) ) {
					$item_classes = array_merge( $item_classes, $node['classes'] );
				}

				if ( ! empty( $children ) ) {
					$item_classes[] = 'menu-item-has-children';
				}

				if ( $desktop && 0 === $depth && 0 === $index ) {
					$item_classes[] = 'is-primary-trigger';
				}

				if ( $desktop && 0 === $depth && count( $nodes ) > 5 && $index >= count( $nodes ) - 2 ) {
					$item_classes[] = 'is-secondary';

					if ( $index === count( $nodes ) - 2 ) {
						$item_classes[] = 'is-secondary-start';
					}
				}
				?>
				<li class="<?php echo esc_attr( implode( ' ', array_unique( array_filter( $item_classes ) ) ) ); ?>">
					<a<?php echo $this->menu_link_attributes( $node, ! empty( $children ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo esc_html( (string) $node['label'] ); ?>
					</a>
					<?php echo $this->render_menu_nodes( $children, $depth + 1, $desktop ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns escaped attributes for a menu link.
	 *
	 * @param array<string,mixed> $node Menu node.
	 */
	private function menu_link_attributes( array $node, bool $has_children ): string {
		$target = (string) ( $node['target'] ?? '' );
		$rel    = trim( (string) ( $node['rel'] ?? '' ) );

		if ( '_blank' === $target && false === strpos( $rel, 'noopener' ) ) {
			$rel = trim( $rel . ' noopener noreferrer' );
		}

		$attributes = array(
			'href' => esc_url( (string) ( $node['href'] ?? '#' ) ),
		);

		if ( '' !== $target ) {
			$attributes['target'] = esc_attr( $target );
		}

		if ( '' !== $rel ) {
			$attributes['rel'] = esc_attr( $rel );
		}

		if ( '' !== (string) ( $node['title_attr'] ?? '' ) ) {
			$attributes['title'] = esc_attr( (string) $node['title_attr'] );
		}

		if ( $has_children ) {
			$attributes['aria-haspopup'] = 'true';
		}

		$output = '';

		foreach ( $attributes as $name => $value ) {
			$output .= sprintf( ' %s="%s"', esc_attr( $name ), $value );
		}

		return $output;
	}

	/**
	 * Returns EU funding logo metadata.
	 *
	 * @return array<int,array{alt:string,class:string,href:string,src:string}>
	 */
	private function eu_logos(): array {
		return array(
			array(
				'alt'   => __( 'Cofinantat de Uniunea Europeana', 'schrack-woocommerce-sync' ),
				'class' => 'is-eu',
				'href'  => 'https://european-union.europa.eu/',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/uniunea-europeana-cofinantat.png',
			),
			array(
				'alt'   => __( 'Guvernul Romaniei', 'schrack-woocommerce-sync' ),
				'class' => 'is-government',
				'href'  => 'https://www.gov.ro/',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/guvernul-romaniei.png',
			),
			array(
				'alt'   => 'REGIO Nord-Vest',
				'class' => 'is-regio',
				'href'  => 'https://regionordvest.ro/',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/regio-nord-vest.png',
			),
			array(
				'alt'   => __( 'Agentia de Dezvoltare Regionala Nord-Vest', 'schrack-woocommerce-sync' ),
				'class' => 'is-adr',
				'href'  => 'https://www.nord-vest.ro/',
				'src'   => SCHRACK_WC_SYNC_URL . 'assets/eu-logos/adr-nord-vest.svg',
			),
		);
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
	 * Returns a product category URL with a stable fallback path.
	 */
	private function product_category_url( string $slug, string $fallback_path ): string {
		if ( taxonomy_exists( 'product_cat' ) ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				$link = get_term_link( $term );

				if ( is_string( $link ) ) {
					return $link;
				}
			}
		}

		return home_url( $fallback_path );
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
