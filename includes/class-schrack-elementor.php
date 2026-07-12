<?php
/**
 * Elementor integration for Schrack widgets.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor {
	/**
	 * Product filter renderer.
	 *
	 * @var Schrack_Product_Filter_Renderer
	 */
	private Schrack_Product_Filter_Renderer $renderer;

	/**
	 * Whether the widget was registered during this request.
	 *
	 * @var bool
	 */
	private bool $widgets_registered = false;

	/**
	 * Whether the main shop introduction has already been rendered.
	 */
	private bool $shop_intro_rendered = false;

	/**
	 * Constructor.
	 */
	public function __construct( ?Schrack_Product_Filter_Renderer $renderer = null ) {
		$this->renderer = $renderer ?: new Schrack_Product_Filter_Renderer();
	}

	/**
	 * Registers Elementor and AJAX hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_shop_archive_assets' ), 20 );
		add_action( 'elementor/theme/before_do_archive', array( $this, 'render_shop_archive_intro' ), 5 );
		add_action( 'woocommerce_before_main_content', array( $this, 'render_shop_archive_intro' ), 5 );
		add_action( 'pre_get_posts', array( $this, 'apply_shop_archive_search' ), 20 );
		add_filter( 'the_content', array( $this, 'prepend_shop_archive_intro' ), 5 );
		add_action( 'admin_post_' . Schrack_Registration_Renderer::ACTION, array( $this, 'handle_registration' ) );
		add_action( 'admin_post_nopriv_' . Schrack_Registration_Renderer::ACTION, array( $this, 'handle_registration' ) );
		add_action( 'admin_post_' . Schrack_Account_Renderer::ACTION, array( $this, 'handle_account_login' ) );
		add_action( 'admin_post_nopriv_' . Schrack_Account_Renderer::ACTION, array( $this, 'handle_account_login' ) );
		add_action( 'admin_post_' . Schrack_Account_Renderer::UPDATE_ACTION, array( $this, 'handle_account_update' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_legacy_widgets' ) );
		add_action( 'wp_ajax_' . Schrack_Product_Filter_Renderer::AJAX_ACTION, array( $this, 'ajax_filter_products' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Product_Filter_Renderer::AJAX_ACTION, array( $this, 'ajax_filter_products' ) );
		add_action( 'wp_ajax_' . Schrack_Product_Filter_Renderer::CATEGORY_AJAX_ACTION, array( $this, 'ajax_filter_categories' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Product_Filter_Renderer::CATEGORY_AJAX_ACTION, array( $this, 'ajax_filter_categories' ) );
		add_action( 'wp_ajax_' . Schrack_Header_Search_Renderer::AJAX_ACTION, array( $this, 'ajax_header_search' ) );
		add_action( 'wp_ajax_nopriv_' . Schrack_Header_Search_Renderer::AJAX_ACTION, array( $this, 'ajax_header_search' ) );
		add_filter( 'body_class', array( $this, 'shop_archive_body_class' ) );
		add_filter( 'woocommerce_get_loop_display_mode', array( $this, 'category_archive_display_mode' ), 20 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
		add_shortcode( 'schrack_account_page', array( $this, 'account_shortcode' ) );
	}

	/**
	 * Registers frontend assets used by the Elementor widget.
	 */
	public function register_assets(): void {
		wp_register_style(
			'schrack-wc-product-filter',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-products.css',
			array(),
			$this->asset_version( 'assets/elementor-products.css' )
		);

		wp_register_script(
			'schrack-wc-product-filter',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-products.js',
			array(),
			$this->asset_version( 'assets/elementor-products.js' ),
			$this->deferred_script_args()
		);

		wp_register_style(
			'schrack-wc-product-page',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-product-page.css',
			array(),
			SCHRACK_WC_SYNC_VERSION
		);

		wp_register_style(
			'schrack-wc-header-search',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header-search.css',
			array(),
			SCHRACK_WC_SYNC_VERSION
		);

		wp_register_script(
			'schrack-wc-header-search',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header-search.js',
			array(),
			SCHRACK_WC_SYNC_VERSION,
			$this->deferred_script_args()
		);

		wp_register_style(
			'schrack-wc-header',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header.css',
			array(),
			$this->asset_version( 'assets/elementor-header.css' )
		);

		wp_register_script(
			'schrack-wc-header',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-header.js',
			array(),
			$this->asset_version( 'assets/elementor-header.js' ),
			$this->deferred_script_args()
		);

		wp_register_style(
			'schrack-wc-registration',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-registration.css',
			array(),
			SCHRACK_WC_SYNC_VERSION
		);

		wp_register_style(
			'schrack-wc-account',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-account.css',
			array(),
			$this->asset_version( 'assets/elementor-account.css' )
		);

		wp_register_style(
			'schrack-wc-cart-checkout',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-cart-checkout.css',
			array(),
			$this->asset_version( 'assets/elementor-cart-checkout.css' )
		);

		wp_register_script(
			'schrack-wc-cart-checkout',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-cart-checkout.js',
			array(),
			$this->asset_version( 'assets/elementor-cart-checkout.js' ),
			$this->deferred_script_args()
		);

		wp_register_style(
			'schrack-wc-support',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-support.css',
			array(),
			$this->asset_version( 'assets/elementor-support.css' )
		);

		wp_register_script(
			'schrack-wc-support',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-support.js',
			array(),
			$this->asset_version( 'assets/elementor-support.js' ),
			$this->deferred_script_args()
		);

		wp_register_style(
			'schrack-wc-homepage',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-homepage.css',
			array(),
			$this->asset_version( 'assets/elementor-homepage.css' )
		);

		wp_register_style(
			'schrack-wc-footer',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-footer.css',
			array(),
			$this->asset_version( 'assets/elementor-footer.css' )
		);

		wp_register_style(
			'schrack-wc-funding',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-funding.css',
			array(),
			$this->asset_version( 'assets/elementor-funding.css' )
		);

		wp_register_script(
			'schrack-wc-homepage',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-homepage.js',
			array(),
			$this->asset_version( 'assets/elementor-homepage.js' ),
			$this->deferred_script_args()
		);

		wp_register_style(
			'schrack-wc-featured-categories',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-featured-categories.css',
			array(),
			$this->asset_version( 'assets/elementor-featured-categories.css' )
		);

		wp_register_script(
			'schrack-wc-featured-categories',
			SCHRACK_WC_SYNC_URL . 'assets/elementor-featured-categories.js',
			array(),
			$this->asset_version( 'assets/elementor-featured-categories.js' ),
			$this->deferred_script_args()
		);

		wp_register_style(
			'schrack-wc-shop-archive',
			SCHRACK_WC_SYNC_URL . 'assets/shop-archive.css',
			array(),
			$this->asset_version( 'assets/shop-archive.css' )
		);

		wp_register_script(
			'schrack-wc-shop-archive',
			SCHRACK_WC_SYNC_URL . 'assets/shop-archive.js',
			array(),
			$this->asset_version( 'assets/shop-archive.js' ),
			$this->deferred_script_args()
		);
	}

	/**
	 * Loads frontend scripts without blocking first paint.
	 *
	 * @return array{in_footer:bool,strategy:string}
	 */
	private function deferred_script_args(): array {
		return array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);
	}

	/**
	 * Returns a cache-busting asset version while developing Elementor modules.
	 */
	private function asset_version( string $relative_path ): string {
		$path = SCHRACK_WC_SYNC_PATH . ltrim( $relative_path, '/' );

		if ( is_readable( $path ) ) {
			return (string) filemtime( $path );
		}

		return SCHRACK_WC_SYNC_VERSION;
	}

	/**
	 * Enqueues the shop/archive polish layer for WooCommerce listing screens.
	 */
	public function enqueue_shop_archive_assets(): void {
		if ( ! $this->is_shop_archive_context() ) {
			return;
		}

		wp_enqueue_style( 'schrack-wc-shop-archive' );
		wp_enqueue_script( 'schrack-wc-shop-archive' );
	}

	/**
	 * Adds a body class used by the shop/archive polish layer.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public function shop_archive_body_class( array $classes ): array {
		if ( $this->is_shop_archive_context() ) {
			$classes[] = 'schrack-shop-archive-page';
		}

		if ( $this->is_main_shop_page() ) {
			$classes[] = 'schrack-shop-main-page';
		}

		if ( $this->current_product_category() instanceof WP_Term ) {
			$classes[] = 'schrack-shop-category-page';
		}

		if ( $this->is_shop_intro_context() ) {
			$classes[] = 'schrack-shop-has-intro';
		}

		return $classes;
	}

	/**
	 * Renders the shared catalog introduction on shop and product-category pages.
	 *
	 * The active theme remains responsible for the site header and footer. This
	 * block is attached to the WooCommerce content hook before the catalog
	 * wrapper so it can use the full available width without replacing either.
	 */
	public function render_shop_archive_intro(): void {
		if ( $this->shop_intro_rendered || ! $this->is_shop_intro_context() ) {
			return;
		}

		$this->shop_intro_rendered = true;

		$category       = $this->current_product_category();
		$is_category    = $category instanceof WP_Term;
		$shop_url       = get_permalink( wc_get_page_id( 'shop' ) );
		$shop_url       = is_string( $shop_url ) && '' !== $shop_url ? $shop_url : home_url( '/' );
		$search_action  = $is_category ? get_term_link( $category, 'product_cat' ) : $shop_url;
		$search_action  = is_wp_error( $search_action ) ? $shop_url : (string) $search_action;
		$search_value   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_count  = $is_category ? $this->shop_category_available_product_count( $category ) : $this->shop_available_product_count();
		$category_count = $is_category ? $this->shop_child_category_count( $category ) : $this->shop_category_count();
		$categories     = $is_category ? array() : $this->shop_root_categories();
		$description      = '';
		$all_products_url = '';

		if ( $is_category ) {
			$description = wp_trim_words( wp_strip_all_tags( term_description( (int) $category->term_id, 'product_cat' ) ), 28, '…' );
			$category_link = get_term_link( $category, 'product_cat' );

			if ( $category_count > 0 && ! is_wp_error( $category_link ) ) {
				$all_products_url = add_query_arg( 'include_descendants', 'yes', (string) $category_link ) . '#schrack-shop-catalog';
			}

			if ( '' === $description ) {
				$description = sprintf(
					/* translators: %s: product category name. */
					__( 'Descoperă gama profesională %s, selectată pentru instalații sigure și proiecte executate eficient.', 'schrack-woocommerce-sync' ),
					$category->name
				);
			}
		}

		$stats = array(
			array(
				'value' => number_format_i18n( $product_count ),
				'label' => $is_category ? __( 'Produse în stoc', 'schrack-woocommerce-sync' ) : __( 'Produse disponibile', 'schrack-woocommerce-sync' ),
			),
			array(
				'value' => number_format_i18n( $category_count ),
				'label' => $is_category ? __( 'Subcategorii', 'schrack-woocommerce-sync' ) : __( 'Categorii', 'schrack-woocommerce-sync' ),
			),
			array(
				'value' => 'B2B',
				'label' => __( 'Soluții pentru proiecte', 'schrack-woocommerce-sync' ),
			),
		);
		?>
		<div class="schrack-shop-redesign" data-shop-redesign>
			<section class="schrack-shop-hero<?php echo $is_category ? ' schrack-shop-hero--category' : ''; ?>" aria-labelledby="schrack-shop-hero-title">
				<div class="schrack-shop-hero__grid" aria-hidden="true"></div>
				<picture>
					<source srcset="<?php echo esc_url( SCHRACK_WC_SYNC_URL . 'assets/shop-hero-technician.webp' ); ?>" type="image/webp">
					<img
						class="schrack-shop-hero__worker"
						src="<?php echo esc_url( SCHRACK_WC_SYNC_URL . 'assets/shop-hero-technician.png' ); ?>"
						alt=""
						width="700"
						height="942"
						decoding="async"
						fetchpriority="high"
					>
				</picture>
				<div class="schrack-shop-hero__content">
					<p class="schrack-shop-hero__eyebrow">
						<span aria-hidden="true"></span>
						<?php
						if ( $is_category ) {
							echo esc_html(
								sprintf(
									/* translators: %s: available product count. */
									__( 'Categorie produse · %s produse în stoc', 'schrack-woocommerce-sync' ),
									number_format_i18n( $product_count )
								)
							);
						} elseif ( $product_count > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: %s: available product count. */
									__( 'Distribuitor electrotehnic · %s produse disponibile', 'schrack-woocommerce-sync' ),
									number_format_i18n( $product_count )
								)
							);
						} else {
							esc_html_e( 'Distribuitor electrotehnic pentru profesioniști', 'schrack-woocommerce-sync' );
						}
						?>
					</p>
					<h1 id="schrack-shop-hero-title">
						<?php if ( $is_category ) : ?>
							<span><?php echo esc_html( $category->name ); ?></span>
						<?php else : ?>
							<?php esc_html_e( 'Tot ce ai nevoie pentru instalații electrice', 'schrack-woocommerce-sync' ); ?>
							<span><?php esc_html_e( '— într-un singur loc', 'schrack-woocommerce-sync' ); ?></span>
						<?php endif; ?>
					</h1>
					<p class="schrack-shop-hero__lead">
						<?php if ( $is_category ) : ?>
							<?php echo esc_html( $description ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Cabluri, aparataj, iluminat, rețelistică și automatizări — din stoc pentru livrare rapidă sau pentru proiecte B2B la scară largă.', 'schrack-woocommerce-sync' ); ?>
						<?php endif; ?>
					</p>

					<form class="schrack-shop-hero__search" role="search" method="get" action="<?php echo esc_url( $search_action ); ?>">
						<label class="screen-reader-text" for="schrack-shop-hero-search">
							<?php esc_html_e( 'Caută în catalog', 'schrack-woocommerce-sync' ); ?>
						</label>
						<input
							id="schrack-shop-hero-search"
							type="search"
							name="search"
							value="<?php echo esc_attr( $search_value ); ?>"
							placeholder="<?php echo esc_attr( $is_category ? __( 'Caută în această categorie...', 'schrack-woocommerce-sync' ) : __( 'Caută după cod, EAN sau denumire...', 'schrack-woocommerce-sync' ) ); ?>"
						>
						<button type="submit"><?php esc_html_e( 'Caută', 'schrack-woocommerce-sync' ); ?></button>
					</form>

					<div class="schrack-shop-hero__stats" aria-label="<?php esc_attr_e( 'Catalog pe scurt', 'schrack-woocommerce-sync' ); ?>">
						<?php foreach ( $stats as $stat ) : ?>
							<div class="schrack-shop-hero__stat">
								<strong><?php echo esc_html( $stat['value'] ); ?></strong>
								<span><?php echo esc_html( $stat['label'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

					<?php if ( '' !== $all_products_url && ! $this->descendant_products_requested() ) : ?>
						<a class="schrack-shop-hero__all-products" href="<?php echo esc_url( $all_products_url ); ?>">
							<span>
								<strong><?php esc_html_e( 'Vezi toate produsele', 'schrack-woocommerce-sync' ); ?></strong>
								<small><?php esc_html_e( 'Inclusiv produsele din toate subcategoriile', 'schrack-woocommerce-sync' ); ?></small>
							</span>
							<i aria-hidden="true">&rarr;</i>
						</a>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( ! empty( $categories ) ) : ?>
				<section class="schrack-shop-categories" aria-labelledby="schrack-shop-categories-title">
					<h2 id="schrack-shop-categories-title" class="screen-reader-text"><?php esc_html_e( 'Categorii principale', 'schrack-woocommerce-sync' ); ?></h2>
					<div class="schrack-shop-categories__grid">
						<?php foreach ( $categories as $index => $category ) : ?>
							<?php $category_link = get_term_link( $category ); ?>
							<?php if ( is_wp_error( $category_link ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<a class="schrack-shop-category-card" data-tone="<?php echo esc_attr( (string) ( ( $index % 5 ) + 1 ) ); ?>" href="<?php echo esc_url( $category_link ); ?>">
								<span class="schrack-shop-category-card__initials" aria-hidden="true"><?php echo esc_html( $this->shop_category_initials( $category->name ) ); ?></span>
								<strong><?php echo esc_html( $category->name ); ?></strong>
								<span class="schrack-shop-category-card__meta">
									<span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: product count. */
												__( '%s produse', 'schrack-woocommerce-sync' ),
												number_format_i18n( (int) $category->count )
											)
										);
										?>
									</span>
									<span aria-hidden="true">&rarr;</span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
					<a class="schrack-shop-categories__all" href="#schrack-shop-catalog" data-shop-category-jump>
						<?php esc_html_e( 'Vezi toate categoriile', 'schrack-woocommerce-sync' ); ?>
						<span aria-hidden="true">&rarr;</span>
					</a>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prepends the shop introduction when a page-builder shop template does not
	 * execute the standard WooCommerce before-main-content hook.
	 */
	public function prepend_shop_archive_intro( string $content ): string {
		if ( $this->shop_intro_rendered || ! $this->is_main_shop_page() ) {
			return $content;
		}

		$queried_id = get_queried_object_id();
		$current_id = get_the_ID();

		if ( $queried_id <= 0 || $current_id !== $queried_id ) {
			return $content;
		}

		ob_start();
		$this->render_shop_archive_intro();
		$intro = (string) ob_get_clean();

		return $intro . $content;
	}

	/**
	 * Maps the shared `search` parameter to WooCommerce's native product query.
	 *
	 * Elementor shop pages use the same parameter in the custom filter widget;
	 * this fallback keeps the hero search functional on native product archives.
	 */
	public function apply_shop_archive_search( WP_Query $query ): void {
		$is_product_archive  = $query->is_post_type_archive( 'product' );
		$is_product_category = $query->is_tax( 'product_cat' );

		if ( is_admin() || ! $query->is_main_query() || ( ! $is_product_archive && ! $is_product_category ) ) {
			return;
		}

		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $search ) {
			return;
		}

		$query->set( 's', $search );
	}

	/**
	 * Translates common shop archive strings left by the active theme/widgets.
	 */
	public function translate_shop_archive_text( string $translation, string $text, string $domain ): string {
		if ( is_admin() ) {
			return $translation;
		}

		$map = array(
			'Out of stock' => 'Stoc epuizat',
			'Read more' => 'Detalii produs',
			'Add to cart' => 'Adauga in cos',
			'Select options' => 'Alege optiuni',
			'View cart' => 'Vezi cosul',
		);

		return $map[ $text ] ?? $translation;
	}

	/**
	 * Translates the shop page title when the page is rendered in the frontend.
	 */
	public function translate_shop_page_title( string $title, int $post_id = 0 ): string {
		if ( is_admin() || ! $this->is_shop_archive_context() ) {
			return $title;
		}

		if ( 'Shop' === trim( wp_strip_all_tags( $title ) ) ) {
			return __( 'Catalog produse', 'schrack-woocommerce-sync' );
		}

		return $title;
	}

	/**
	 * Translates the product category widget title.
	 *
	 * @param string       $title Widget title.
	 * @param mixed        $instance Widget instance.
	 * @param string|mixed $id_base Widget base id.
	 */
	public function translate_shop_widget_title( string $title, mixed $instance = array(), mixed $id_base = '' ): string {
		if ( is_admin() ) {
			return $title;
		}

		if ( 'Category' === trim( wp_strip_all_tags( $title ) ) ) {
			return __( 'Categorii', 'schrack-woocommerce-sync' );
		}

		return $title;
	}

	/**
	 * Translates WooCommerce archive page titles.
	 */
	public function translate_woocommerce_page_title( string $title ): string {
		if ( 'Shop' === trim( wp_strip_all_tags( $title ) ) ) {
			return __( 'Catalog produse', 'schrack-woocommerce-sync' );
		}

		return $title;
	}

	/**
	 * Translates stock availability labels.
	 */
	public function translate_availability_text( string $availability, WC_Product $product ): string {
		if ( ! $product->is_in_stock() ) {
			return __( 'Stoc epuizat', 'schrack-woocommerce-sync' );
		}

		return $availability;
	}

	/**
	 * Shows only child categories on non-leaf WooCommerce product category
	 * archives. Leaf categories keep the normal product grid.
	 */
	public function category_archive_display_mode( string $display_mode ): string {
		if ( $this->current_product_category_has_children() ) {
			return $this->descendant_products_requested() ? 'both' : 'subcategories';
		}

		return $display_mode;
	}

	/**
	 * Returns whether the shopper explicitly requested the complete product tree.
	 */
	private function descendant_products_requested(): bool {
		$raw_value = isset( $_GET['include_descendants'] ) ? $_GET['include_descendants'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! is_scalar( $raw_value ) ) {
			return false;
		}

		$value = sanitize_text_field( wp_unslash( (string) $raw_value ) );

		return in_array( strtolower( $value ), array( '1', 'yes', 'true' ), true );
	}

	/**
	 * Returns whether the current request is the main shop landing page.
	 */
	private function is_main_shop_page(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		if ( function_exists( 'is_page' ) && is_page( array( 'shop', 'shop-3' ) ) ) {
			return true;
		}

		$queried = get_queried_object();

		return is_object( $queried )
			&& isset( $queried->post_name )
			&& in_array( (string) $queried->post_name, array( 'shop', 'shop-3' ), true );
	}

	/**
	 * Returns the current product category, when the request is a category archive.
	 */
	private function current_product_category(): ?WP_Term {
		if ( is_admin() || ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return null;
		}

		$term = get_queried_object();

		return $term instanceof WP_Term && 'product_cat' === $term->taxonomy ? $term : null;
	}

	/**
	 * Returns whether the shared shop introduction belongs on this request.
	 */
	private function is_shop_intro_context(): bool {
		return $this->is_main_shop_page() || $this->current_product_category() instanceof WP_Term;
	}

	/**
	 * Counts products that can currently be shown in the catalog.
	 */
	private function shop_available_product_count(): int {
		$cached = get_transient( 'schrack_shop_available_product_count' );

		if ( false !== $cached ) {
			return max( 0, absint( $cached ) );
		}

		$count = 0;

		if ( function_exists( 'wc_get_products' ) ) {
			$result = wc_get_products(
				array(
					'limit'        => 1,
					'paginate'     => true,
					'return'       => 'ids',
					'status'       => 'publish',
					'stock_status' => 'instock',
				)
			);

			if ( is_object( $result ) && isset( $result->total ) ) {
				$count = absint( $result->total );
			}
		}

		if ( $count <= 0 ) {
			$post_counts = wp_count_posts( 'product' );
			$count       = is_object( $post_counts ) && isset( $post_counts->publish ) ? absint( $post_counts->publish ) : 0;
		}

		set_transient( 'schrack_shop_available_product_count', $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Counts strictly in-stock products in one product category.
	 */
	private function shop_category_available_product_count( WP_Term $category ): int {
		$cache_key = 'schrack_shop_category_stock_count_' . (int) $category->term_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return max( 0, absint( $cached ) );
		}

		$count = 0;

		if ( function_exists( 'wc_get_products' ) ) {
			$result = wc_get_products(
				array(
					'category'     => array( $category->slug ),
					'limit'        => 1,
					'paginate'     => true,
					'return'       => 'ids',
					'status'       => 'publish',
					'stock_status' => 'instock',
				)
			);

			if ( is_object( $result ) && isset( $result->total ) ) {
				$count = absint( $result->total );
			}
		}

		set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Counts direct, non-empty child categories for the category banner.
	 */
	private function shop_child_category_count( WP_Term $category ): int {
		$children = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'fields'     => 'ids',
				'parent'     => (int) $category->term_id,
			)
		);

		return is_array( $children ) ? count( $children ) : 0;
	}

	/**
	 * Counts non-empty product categories for the hero statistics.
	 */
	private function shop_category_count(): int {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}

		$cached = get_transient( 'schrack_shop_category_count' );

		if ( false !== $cached ) {
			return max( 0, absint( $cached ) );
		}

		$count = wp_count_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
			)
		);

		$count = is_wp_error( $count ) ? 0 : absint( $count );

		set_transient( 'schrack_shop_category_count', $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Returns the ten most useful root categories for the shop overview.
	 *
	 * @return array<int,WP_Term>
	 */
	private function shop_root_categories(): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 10,
				'pad_counts' => true,
				'parent'     => 0,
			)
		);

		if ( is_wp_error( $categories ) || ! is_array( $categories ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$categories,
				static fn ( mixed $category ): bool => $category instanceof WP_Term
			)
		);
	}

	/**
	 * Builds a compact two-letter marker for a category card.
	 */
	private function shop_category_initials( string $name ): string {
		$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( $name ) ) );
		$words = is_array( $words ) ? array_values( array_filter( $words ) ) : array();
		$words = array_values(
			array_filter(
				$words,
				static function ( string $word ): bool {
					$word = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word ) : strtolower( $word );

					return ! in_array( $word, array( 'si', 'și', 'de', 'din', 'pentru', 'cu' ), true );
				}
			)
		);

		if ( empty( $words ) ) {
			return 'SH';
		}

		$initials = '';

		foreach ( array_slice( $words, 0, 2 ) as $word ) {
			$initials .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initials ) : strtoupper( $initials );
	}

	/**
	 * Returns whether the current frontend request is a WooCommerce listing screen.
	 */
	private function is_shop_archive_context(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return true;
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return true;
		}

		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			return true;
		}

		if ( function_exists( 'is_page' ) && is_page( array( 'shop', 'shop-3' ) ) ) {
			return true;
		}

		$queried = get_queried_object();

		if ( is_object( $queried ) && isset( $queried->post_name ) ) {
			return in_array( (string) $queried->post_name, array( 'shop', 'shop-3' ), true );
		}

		return false;
	}

	/**
	 * Detects whether the current WooCommerce product category archive has a
	 * direct child category.
	 */
	private function current_product_category_has_children(): bool {
		if ( is_admin() || ! taxonomy_exists( 'product_cat' ) ) {
			return false;
		}

		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return false;
		}

		$term = get_queried_object();

		if ( ! $term instanceof WP_Term || 'product_cat' !== $term->taxonomy ) {
			return false;
		}

		$children = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				'parent'     => (int) $term->term_id,
			)
		);

		return is_array( $children ) && ! empty( $children );
	}

	/**
	 * Adds a Schrack widget category inside Elementor.
	 *
	 * @param mixed $elements_manager Elementor elements manager.
	 */
	public function register_elementor_category( mixed $elements_manager = null ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			'schrack',
			array(
				'title' => __( 'Schrack', 'schrack-woocommerce-sync' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	/**
	 * Registers widgets for current Elementor versions.
	 *
	 * @param mixed $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( mixed $widgets_manager = null ): void {
		if ( $this->widgets_registered || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-filter-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-search-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-page-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-registration-widgets.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-account-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-cart-checkout-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-pay-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-received-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-homepage-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-featured-categories-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-footer-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-funding-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-support-widget.php';

		$widgets = array(
			new Schrack_Elementor_Header_Widget(),
			new Schrack_Elementor_Homepage_Widget(),
			new Schrack_Elementor_Featured_Categories_Widget(),
			new Schrack_Elementor_Footer_Widget(),
			new Schrack_Elementor_Funding_Widget(),
			new Schrack_Elementor_Product_Filter_Widget(),
			new Schrack_Elementor_Header_Search_Widget(),
			new Schrack_Elementor_Product_Page_Widget(),
			new Schrack_Elementor_Customer_Register_Widget(),
			new Schrack_Elementor_B2B_Register_Widget(),
			new Schrack_Elementor_Account_Widget(),
			new Schrack_Elementor_Cart_Checkout_Widget(),
			new Schrack_Elementor_Order_Pay_Widget(),
			new Schrack_Elementor_Order_Received_Widget(),
			new Schrack_Elementor_Support_Widget(),
		);

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register' ) ) {
			foreach ( $widgets as $widget ) {
				$widgets_manager->register( $widget );
			}

			$this->widgets_registered = true;
		}
	}

	/**
	 * Registers widgets for older Elementor versions.
	 *
	 * @param mixed $widgets_manager Elementor widgets manager.
	 */
	public function register_legacy_widgets( mixed $widgets_manager = null ): void {
		if ( $this->widgets_registered || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-filter-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-header-search-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-product-page-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-registration-widgets.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-account-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-cart-checkout-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-pay-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-order-received-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-homepage-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-featured-categories-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-footer-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-funding-widget.php';
		require_once SCHRACK_WC_SYNC_PATH . 'includes/widgets/class-schrack-elementor-support-widget.php';

		if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( new Schrack_Elementor_Header_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Homepage_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Featured_Categories_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Footer_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Funding_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Product_Filter_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Header_Search_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Product_Page_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Customer_Register_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_B2B_Register_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Account_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Cart_Checkout_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Order_Pay_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Order_Received_Widget() );
			$widgets_manager->register_widget_type( new Schrack_Elementor_Support_Widget() );
			$this->widgets_registered = true;
		}
	}

	/**
	 * Refreshes header cart fragments after WooCommerce AJAX add-to-cart events.
	 *
	 * @param array<string,string> $fragments Existing fragments.
	 * @return array<string,string>
	 */
	public function cart_fragments( array $fragments ): array {
		$renderer = new Schrack_Header_Renderer();

		$fragments['span.schrack-header__cart-count'] = $renderer->cart_count_fragment();
		$fragments['span.schrack-header__cart-total'] = $renderer->cart_total_fragment();

		return $fragments;
	}

	/**
	 * Handles frontend registration form posts.
	 */
	public function handle_registration(): void {
		$renderer = new Schrack_Registration_Renderer();
		$renderer->handle_registration();
	}

	/**
	 * Handles frontend login form posts.
	 */
	public function handle_account_login(): void {
		$renderer = new Schrack_Account_Renderer();
		$renderer->handle_login();
	}

	/**
	 * Handles frontend account update form posts.
	 */
	public function handle_account_update(): void {
		$renderer = new Schrack_Account_Renderer();
		$renderer->handle_update();
	}

	/**
	 * Renders the account portal by shortcode.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function account_shortcode( array|string $atts = array() ): string {
		$settings = is_array( $atts ) ? $atts : array();
		$renderer = new Schrack_Account_Renderer();

		return $renderer->render( $settings, 'shortcode' );
	}

	/**
	 * Handles AJAX product filtering.
	 */
	public function ajax_filter_products(): void {
		check_ajax_referer( Schrack_Product_Filter_Renderer::NONCE_ACTION, 'nonce' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce nu este disponibil.', 'schrack-woocommerce-sync' ),
				),
				400
			);
		}

		$config_raw = isset( $_POST['config'] ) ? wp_unslash( (string) $_POST['config'] ) : '{}';
		$config     = json_decode( $config_raw, true );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$filters = array(
			'search'               => isset( $_POST['search'] ) ? wp_unslash( (string) $_POST['search'] ) : '',
			'category'             => isset( $_POST['category'] ) ? wp_unslash( (string) $_POST['category'] ) : '',
			'category_search'      => isset( $_POST['category_search'] ) ? wp_unslash( (string) $_POST['category_search'] ) : '',
			'min_price'            => isset( $_POST['min_price'] ) ? wp_unslash( (string) $_POST['min_price'] ) : '',
			'max_price'            => isset( $_POST['max_price'] ) ? wp_unslash( (string) $_POST['max_price'] ) : '',
			'include_out_of_stock' => isset( $_POST['stock_filter_present'] )
				? ( isset( $_POST['in_stock_only'] ) ? 'no' : 'yes' )
				: ( isset( $_POST['include_out_of_stock'] ) ? wp_unslash( (string) $_POST['include_out_of_stock'] ) : '' ),
			'include_descendants'  => isset( $_POST['include_descendants'] ) && is_scalar( $_POST['include_descendants'] )
				? wp_unslash( (string) $_POST['include_descendants'] )
				: 'no',
			'manufacturer'         => isset( $_POST['manufacturer'] ) ? wp_unslash( (string) $_POST['manufacturer'] ) : '',
			'product_line'         => isset( $_POST['product_line'] ) ? wp_unslash( (string) $_POST['product_line'] ) : '',
			'special_offer_only'   => isset( $_POST['special_offer_only'] ) ? wp_unslash( (string) $_POST['special_offer_only'] ) : '',
			'orderby'              => isset( $_POST['orderby'] ) ? wp_unslash( (string) $_POST['orderby'] ) : '',
			'paged'                => isset( $_POST['paged'] ) ? wp_unslash( (string) $_POST['paged'] ) : 1,
			'attributes'           => isset( $_POST['attr'] ) && is_array( $_POST['attr'] )
				? $this->renderer->attribute_filters_from_array( wp_unslash( $_POST['attr'] ) )
				: array(),
		);

		wp_send_json_success( $this->renderer->render_results( $config, $filters ) );
	}

	/**
	 * Handles AJAX category picker search.
	 */
	public function ajax_filter_categories(): void {
		check_ajax_referer( Schrack_Product_Filter_Renderer::NONCE_ACTION, 'nonce' );

		$search   = isset( $_POST['search'] ) ? wp_unslash( (string) $_POST['search'] ) : '';
		$selected = isset( $_POST['selected'] ) ? absint( wp_unslash( (string) $_POST['selected'] ) ) : 0;
		$limit    = isset( $_POST['limit'] ) ? absint( wp_unslash( (string) $_POST['limit'] ) ) : 30;

		wp_send_json_success( $this->renderer->render_category_results( $search, $selected, $limit ) );
	}

	/**
	 * Handles AJAX header product search.
	 */
	public function ajax_header_search(): void {
		check_ajax_referer( Schrack_Header_Search_Renderer::NONCE_ACTION, 'nonce' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WooCommerce nu este disponibil.', 'schrack-woocommerce-sync' ),
				),
				400
			);
		}

		$config_raw = isset( $_POST['config'] ) ? wp_unslash( (string) $_POST['config'] ) : '{}';
		$config     = json_decode( $config_raw, true );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$search   = isset( $_POST['search'] ) ? wp_unslash( (string) $_POST['search'] ) : '';
		$renderer = new Schrack_Header_Search_Renderer();

		wp_send_json_success( $renderer->render_results( $search, $config ) );
	}
}
