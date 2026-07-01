<?php
/**
 * Modern Elementor product page renderer.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Product_Page_Renderer {
	/**
	 * Frontend image loader.
	 *
	 * @var Schrack_Frontend_Image_Loader|null
	 */
	private ?Schrack_Frontend_Image_Loader $image_loader = null;

	/**
	 * Renders the product page module.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="schrack-product-page"><p>' . esc_html__( 'WooCommerce este necesar pentru afisarea produsului.', 'schrack-woocommerce-sync' ) . '</p></div>';
		}

		$settings = $this->sanitize_settings( $settings );
		$product  = $this->product_for_settings( $settings );

		wp_enqueue_style( 'schrack-wc-product-page' );

		if ( ! $product instanceof WC_Product ) {
			return '<div class="schrack-product-page"><div class="schrack-product-page__empty">' . esc_html__( 'Nu s-a gasit produsul pentru acest modul.', 'schrack-woocommerce-sync' ) . '</div></div>';
		}

		if ( $product->is_type( 'variable' ) ) {
			wp_enqueue_script( 'wc-add-to-cart-variation' );
		}

		if ( $settings['show_gallery'] ) {
			$product = $this->frontend_image_loader()->ensure_product_image( $product, 1 );
		}

		$product_id = $product->get_id();
		$classes    = array( 'schrack-product-page' );

		if ( ! $settings['show_gallery'] ) {
			$classes[] = 'has-no-gallery';
		}

		$style = sprintf(
			'--schrack-page-accent:%1$s;--schrack-page-action:%2$s;--schrack-page-radius:%3$dpx;',
			esc_attr( $settings['accent_color'] ),
			esc_attr( $settings['action_color'] ),
			(int) $settings['radius']
		);

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( '' !== $instance_id ? 'schrack-product-page-' . $instance_id : 'schrack-product-page-' . $product_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
		>
			<div class="schrack-product-page__layout">
				<?php if ( $settings['show_gallery'] ) : ?>
					<?php echo $this->gallery( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<div class="schrack-product-page__summary">
					<?php if ( $settings['show_categories'] ) : ?>
						<?php echo $this->category_chips( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>

					<div class="schrack-product-page__headline">
						<h1><?php echo esc_html( $product->get_name() ); ?></h1>
					</div>

					<div class="schrack-product-page__commerce">
						<?php echo $this->price_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $settings['show_stock'] ) : ?>
							<?php echo $this->stock_badge( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>

					<?php if ( $settings['show_short_description'] ) : ?>
						<?php echo $this->short_description( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>

					<?php echo $this->meta_grid( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<?php if ( $settings['show_cart'] ) : ?>
						<div class="schrack-product-page__buybox">
							<?php echo $this->cart_area( $product, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $settings['show_specs'] ) : ?>
				<?php echo $this->specifications( $product, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders product image gallery.
	 */
	private function gallery( WC_Product $product ): string {
		$image_ids = array();
		$image_id  = (int) $product->get_image_id();

		if ( $image_id > 0 ) {
			$image_ids[] = $image_id;
		}

		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$gallery_id = (int) $gallery_id;

			if ( $gallery_id > 0 && ! in_array( $gallery_id, $image_ids, true ) ) {
				$image_ids[] = $gallery_id;
			}
		}

		ob_start();
		?>
		<div class="schrack-product-page__media">
			<div class="schrack-product-page__main-image">
				<?php
				$remote_image = $this->frontend_image_loader()->remote_product_image_html(
					$product,
					'woocommerce_single',
					array(
						'loading' => 'eager',
					)
				);

				if ( '' !== $remote_image ) {
					echo $remote_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} elseif ( empty( $image_ids ) ) {
					echo wc_placeholder_img( 'woocommerce_single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo wp_get_attachment_image(
						$image_ids[0],
						'large',
						false,
						array(
							'loading' => 'eager',
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<?php if ( '' === $remote_image && count( $image_ids ) > 1 ) : ?>
				<div class="schrack-product-page__thumbs">
					<?php foreach ( array_slice( $image_ids, 0, 6 ) as $thumb_id ) : ?>
						<div class="schrack-product-page__thumb">
							<?php echo wp_get_attachment_image( $thumb_id, 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders product categories as chips.
	 */
	private function category_chips( WC_Product $product ): string {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="schrack-product-page__chips" aria-label="<?php esc_attr_e( 'Categorii produs', 'schrack-woocommerce-sync' ); ?>">
			<?php foreach ( array_slice( $terms, 0, 4 ) as $term ) : ?>
				<?php $link = get_term_link( $term ); ?>
				<?php if ( ! is_wp_error( $link ) ) : ?>
					<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $term->name ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the product price with TVA context.
	 */
	private function price_html( WC_Product $product ): string {
		$price_html = $product->get_price_html();

		if ( '' === trim( wp_strip_all_tags( $price_html ) ) ) {
			return '';
		}

		return sprintf(
			'<div class="schrack-product-page__price"><span class="schrack-product-page__price-value">%1$s</span><br class="schrack-product-page__price-break"><small class="schrack-product-page__price-tax-label">%2$s</small></div>',
			wp_kses_post( $price_html ),
			esc_html__( 'Pret cu TVA', 'schrack-woocommerce-sync' )
		);
	}

	/**
	 * Renders stock badge.
	 */
	private function stock_badge( WC_Product $product ): string {
		$is_in_stock = $product->is_in_stock();
		$quantity    = $product->managing_stock() ? $product->get_stock_quantity() : null;
		$text        = $is_in_stock ? __( 'In stoc', 'schrack-woocommerce-sync' ) : __( 'Stoc epuizat', 'schrack-woocommerce-sync' );

		if ( $is_in_stock && null !== $quantity ) {
			$text = sprintf(
				/* translators: %d: stock quantity. */
				__( 'In stoc: %d buc.', 'schrack-woocommerce-sync' ),
				(int) $quantity
			);
		}

		return sprintf(
			'<div class="schrack-product-page__stock %1$s">%2$s</div>',
			$is_in_stock ? 'is-in-stock' : 'is-out-of-stock',
			esc_html( $text )
		);
	}

	/**
	 * Renders product short description.
	 */
	private function short_description( WC_Product $product ): string {
		$description = $product->get_short_description();

		if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 36 );
		}

		if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
			return '';
		}

		return '<div class="schrack-product-page__excerpt">' . wp_kses_post( wpautop( $description ) ) . '</div>';
	}

	/**
	 * Renders compact metadata grid.
	 */
	private function meta_grid( WC_Product $product ): string {
		$items = array_filter(
			array(
				$this->meta_item( __( 'EAN', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'ean', '_schrack_ean' ) ),
				$this->meta_item( __( 'Producator', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'manufacturer', '_schrack_manufacturer' ) ),
				$this->meta_item( __( 'Unitate', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'unit', '_schrack_unit' ) ),
				$this->meta_item( __( 'Status catalog', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'catalog_status', '_schrack_catalog_status' ) ),
			)
		);

		if ( empty( $items ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="schrack-product-page__meta-grid">
			<?php foreach ( $items as $item ) : ?>
				<div>
					<span><?php echo esc_html( $item['label'] ); ?></span>
					<strong><?php echo esc_html( $item['value'] ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders add-to-cart area.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function cart_area( WC_Product $product, array $settings ): string {
		if ( ! $product->is_purchasable() ) {
			return '<div class="schrack-product-page__cart-note">' . esc_html__( 'Produsul nu este disponibil pentru comanda online.', 'schrack-woocommerce-sync' ) . '</div>';
		}

		if ( ! $product->is_in_stock() ) {
			return '<div class="schrack-product-page__cart-note">' . esc_html__( 'Produsul este momentan fara stoc.', 'schrack-woocommerce-sync' ) . '</div>';
		}

		if ( $product->is_type( 'simple' ) ) {
			ob_start();
			?>
			<form class="cart schrack-product-page__cart-form" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">
				<?php
				if ( ! $product->is_sold_individually() ) {
					woocommerce_quantity_input(
						array(
							'min_value'   => apply_filters( 'woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product ),
							'max_value'   => apply_filters( 'woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product ),
							'input_value' => $product->get_min_purchase_quantity(),
						),
						$product
					);
				}
				?>
				<button type="submit" name="add-to-cart" value="<?php echo esc_attr( (string) $product->get_id() ); ?>" class="single_add_to_cart_button button alt schrack-product-page__cart-button">
					<?php echo esc_html( $settings['cart_button_text'] ); ?>
				</button>
			</form>
			<?php

			return (string) ob_get_clean();
		}

		$previous_product = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $product;

		ob_start();
		echo '<div class="schrack-product-page__wc-cart">';
		woocommerce_template_single_add_to_cart();
		echo '</div>';
		$html = (string) ob_get_clean();

		$GLOBALS['product'] = $previous_product;

		return $html;
	}

	/**
	 * Renders specifications panel.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function specifications( WC_Product $product, array $settings ): string {
		$stock_html = wc_get_stock_html( $product );
		$items = array_filter(
			array(
				$this->meta_item( __( 'Categorii', 'schrack-woocommerce-sync' ), $this->term_names( $product, 'product_cat' ) ),
				$this->meta_item( __( 'Etichete', 'schrack-woocommerce-sync' ), $this->term_names( $product, 'product_tag' ) ),
				$this->meta_item( __( 'EAN', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'ean', '_schrack_ean' ) ),
				$this->meta_item( __( 'Producator', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'manufacturer', '_schrack_manufacturer' ) ),
				$this->meta_item( __( 'Unitate', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'unit', '_schrack_unit' ) ),
				$this->meta_item( __( 'Status catalog', 'schrack-woocommerce-sync' ), $this->source_meta_text( $product, 'catalog_status', '_schrack_catalog_status' ) ),
				$this->meta_item( __( 'Greutate', 'schrack-woocommerce-sync' ), $this->product_weight( $product ) ),
				$this->meta_item( __( 'Dimensiuni', 'schrack-woocommerce-sync' ), $this->product_dimensions( $product ) ),
				$this->meta_item( __( 'Disponibilitate', 'schrack-woocommerce-sync' ), '' !== $stock_html ? wp_strip_all_tags( $stock_html ) : '' ),
			)
		);

		$items = array_merge( $items, $this->product_attribute_items( $product ) );

		if ( $settings['show_technical_attributes'] ) {
			$items = array_merge( $items, $this->technical_attributes( $product, (int) $settings['technical_limit'] ) );
		}

		$items = $this->unique_meta_items( $items );

		if ( empty( $items ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="schrack-product-page__specs">
			<div class="schrack-product-page__specs-head">
				<h2><?php esc_html_e( 'Detalii produs', 'schrack-woocommerce-sync' ); ?></h2>
				<span><?php echo esc_html( sprintf( '%d', count( $items ) ) ); ?></span>
			</div>
			<div class="schrack-product-page__spec-grid">
				<?php foreach ( $items as $item ) : ?>
					<div>
						<span><?php echo esc_html( $item['label'] ); ?></span>
						<strong><?php echo $this->spec_value_html( $item['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns comma-separated product term names.
	 */
	private function term_names( WC_Product $product, string $taxonomy ): string {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$terms = get_the_terms( $product->get_id(), $taxonomy );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Returns formatted product weight when available.
	 */
	private function product_weight( WC_Product $product ): string {
		$weight = $product->get_weight();

		if ( '' === $weight ) {
			return '';
		}

		return function_exists( 'wc_format_weight' ) ? wp_strip_all_tags( wc_format_weight( $weight ) ) : (string) $weight;
	}

	/**
	 * Returns formatted product dimensions when available.
	 */
	private function product_dimensions( WC_Product $product ): string {
		$dimensions = $product->get_dimensions( false );

		if ( empty( array_filter( $dimensions ) ) ) {
			return '';
		}

		return function_exists( 'wc_format_dimensions' ) ? wp_strip_all_tags( wc_format_dimensions( $dimensions ) ) : implode( ' x ', array_filter( $dimensions ) );
	}

	/**
	 * Returns visible WooCommerce product attributes.
	 *
	 * @return array<int,array{label:string,value:string}>
	 */
	private function product_attribute_items( WC_Product $product ): array {
		$items = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}

			$label = wc_attribute_label( $attribute->get_name(), $product );
			$value = '';

			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms(
					$product->get_id(),
					$attribute->get_name(),
					array(
						'fields' => 'names',
					)
				);

				$value = is_array( $terms ) ? implode( ', ', $terms ) : '';
			} else {
				$value = implode( ', ', array_map( 'wc_clean', $attribute->get_options() ) );
			}

			$item = $this->meta_item( $label, $value );

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Returns decoded technical attributes.
	 *
	 * @return array<int,array{label:string,value:string}>
	 */
	private function technical_attributes( WC_Product $product, int $limit ): array {
		$source = $this->catalog_source( $product );
		$raw    = $product->get_meta( 'schrack' === $source ? '_schrack_technical_attributes' : '_' . $source . '_technical_attributes', true );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$items = array();

		foreach ( $decoded as $key => $value ) {
			if ( $limit > 0 && count( $items ) >= $limit ) {
				break;
			}

			$item = $this->technical_attribute_item( $key, $value );

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Returns the catalog source stored on the product.
	 */
	private function catalog_source( WC_Product $product ): string {
		$source = sanitize_key( (string) $product->get_meta( '_schrack_catalog_source', true ) );

		return '' !== $source ? $source : 'schrack';
	}

	/**
	 * Reads source-specific metadata, falling back to the legacy Schrack meta key.
	 */
	private function source_meta_text( WC_Product $product, string $suffix, string $fallback_key ): string {
		$source = $this->catalog_source( $product );

		if ( 'schrack' !== $source ) {
			$value = $this->meta_text( $product, '_' . $source . '_' . sanitize_key( $suffix ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return $this->meta_text( $product, $fallback_key );
	}

	/**
	 * Removes repeated label/value pairs while preserving order.
	 *
	 * @param array<int,array{label:string,value:string}> $items Metadata items.
	 * @return array<int,array{label:string,value:string}>
	 */
	private function unique_meta_items( array $items ): array {
		$unique = array();
		$seen   = array();

		foreach ( $items as $item ) {
			$key = strtolower( $item['label'] . ':' . $item['value'] );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[] = $item;
		}

		return $unique;
	}

	/**
	 * Renders a specification value, turning standalone URLs into links.
	 */
	private function spec_value_html( string $value ): string {
		$value = trim( $value );

		if ( $this->is_standalone_url( $value ) ) {
			return sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $value ),
				esc_html( $value )
			);
		}

		return esc_html( $value );
	}

	/**
	 * Returns whether a value is a single URL.
	 */
	private function is_standalone_url( string $value ): bool {
		if ( '' === $value || preg_match( '/\s/', $value ) ) {
			return false;
		}

		return (bool) wp_http_validate_url( $value );
	}

	/**
	 * Normalizes one technical attribute.
	 *
	 * @return array{label:string,value:string}|null
	 */
	private function technical_attribute_item( int|string $key, mixed $value ): ?array {
		$label = is_string( $key ) ? $key : '';
		$text  = '';

		if ( is_array( $value ) ) {
			$label = sanitize_text_field( (string) ( $value['label'] ?? $value['name'] ?? $label ) );
			$text  = $this->array_text_value( $value['value'] ?? $value['text'] ?? $value );
		} elseif ( is_scalar( $value ) ) {
			$text = sanitize_text_field( (string) $value );
		}

		$label = sanitize_text_field( '' !== $label ? $label : __( 'Atribut', 'schrack-woocommerce-sync' ) );
		$text  = sanitize_text_field( $text );

		if ( '' === $text || ! $this->is_public_spec_label( $label ) ) {
			return null;
		}

		return $this->meta_item( $label, $text );
	}

	/**
	 * Keeps import, sync, commercial, and internal labels out of public specs.
	 */
	private function is_public_spec_label( string $label ): bool {
		if ( function_exists( 'remove_accents' ) ) {
			$label = remove_accents( $label );
		}

		$key = strtolower( $label );
		$key = preg_replace( '/[^a-z0-9]+/', '', $key );

		if ( null === $key || '' === $key ) {
			return false;
		}

		$blocked = array(
			'cache',
			'cost',
			'cursor',
			'discount',
			'downloadurl',
			'endpoint',
			'grossprice',
			'imageerror',
			'imagestatus',
			'import',
			'imported',
			'internal',
			'lastsync',
			'lastupdate',
			'lastupdated',
			'netprice',
			'password',
			'pretnet',
			'pret',
			'price',
			'private',
			'purchaseprice',
			'purchasingprice',
			'rabatt',
			'resulttype',
			'secret',
			'session',
			'soap',
			'stockbreakdown',
			'sync',
			'token',
			'warehouse',
			'wsdl',
		);

		foreach ( $blocked as $blocked_key ) {
			if ( str_contains( $key, $blocked_key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Converts nested scalar arrays to display text.
	 */
	private function array_text_value( mixed $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$parts = array();

		foreach ( $value as $part ) {
			if ( is_scalar( $part ) && '' !== trim( (string) $part ) ) {
				$parts[] = trim( (string) $part );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Resolves product from widget settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function product_for_settings( array $settings ): ?WC_Product {
		if ( 'custom' === $settings['product_source'] ) {
			$lookup = trim( (string) $settings['product_lookup'] );

			if ( '' === $lookup ) {
				return null;
			}

			$product_id = ctype_digit( $lookup ) ? absint( $lookup ) : 0;
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

			if ( ! $product instanceof WC_Product ) {
				$product_id = wc_get_product_id_by_sku( $lookup );
				$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
			}

			return $product instanceof WC_Product ? $product : null;
		}

		global $product;

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		$product_id = get_queried_object_id();

		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			$product_id = get_the_ID();
		}

		$current_product = $product_id > 0 ? wc_get_product( $product_id ) : null;

		return $current_product instanceof WC_Product ? $current_product : null;
	}

	/**
	 * Sanitizes renderer settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private function sanitize_settings( array $settings ): array {
		$source = sanitize_key( (string) ( $settings['product_source'] ?? 'current' ) );

		if ( ! in_array( $source, array( 'current', 'custom' ), true ) ) {
			$source = 'current';
		}

		return array(
			'product_source'           => $source,
			'product_lookup'           => sanitize_text_field( (string) ( $settings['product_lookup'] ?? '' ) ),
			'show_gallery'             => $this->truthy( $settings['show_gallery'] ?? 'yes' ),
			'show_categories'          => $this->truthy( $settings['show_categories'] ?? 'yes' ),
			'show_short_description'   => $this->truthy( $settings['show_short_description'] ?? 'yes' ),
			'show_stock'               => $this->truthy( $settings['show_stock'] ?? 'yes' ),
			'show_cart'                => $this->truthy( $settings['show_cart'] ?? 'yes' ),
			'show_specs'               => $this->truthy( $settings['show_specs'] ?? 'yes' ),
			'show_technical_attributes' => $this->truthy( $settings['show_technical_attributes'] ?? 'yes' ),
			'technical_limit'          => max( 0, min( 250, absint( $settings['technical_limit'] ?? 0 ) ) ),
			'cart_button_text'         => sanitize_text_field( (string) ( $settings['cart_button_text'] ?? __( 'Adauga in cos', 'schrack-woocommerce-sync' ) ) ),
			'accent_color'             => sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#135e96' ) ) ?: '#135e96',
			'action_color'             => sanitize_hex_color( (string) ( $settings['action_color'] ?? '#b32d2e' ) ) ?: '#b32d2e',
			'radius'                   => $this->slider_size( $settings['radius'] ?? 8, 0, 12 ),
		);
	}

	/**
	 * Returns sanitized text meta value.
	 */
	private function meta_text( WC_Product $product, string $key ): string {
		$value = $product->get_meta( $key, true );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Creates a metadata item.
	 *
	 * @return array{label:string,value:string}|null
	 */
	private function meta_item( string $label, mixed $value ): ?array {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return null;
		}

		return array(
			'label' => sanitize_text_field( $label ),
			'value' => sanitize_text_field( $value ),
		);
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
	 * Returns the on-demand frontend image loader.
	 */
	private function frontend_image_loader(): Schrack_Frontend_Image_Loader {
		if ( $this->image_loader instanceof Schrack_Frontend_Image_Loader ) {
			return $this->image_loader;
		}

		$plugin   = class_exists( 'Schrack_Plugin' ) ? Schrack_Plugin::instance() : null;
		$settings = $plugin instanceof Schrack_Plugin && $plugin->settings() instanceof Schrack_Settings ? $plugin->settings() : new Schrack_Settings();
		$logger   = $plugin instanceof Schrack_Plugin && $plugin->logger() instanceof Schrack_Logger ? $plugin->logger() : new Schrack_Logger( $settings );

		$this->image_loader = new Schrack_Frontend_Image_Loader( $settings, $logger );

		return $this->image_loader;
	}

	/**
	 * Returns whether a setting is enabled.
	 */
	private function truthy( mixed $value ): bool {
		return in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true );
	}
}
