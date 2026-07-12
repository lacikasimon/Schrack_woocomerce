<?php
/**
 * Product edit screen additions: supplier sidebar box and raw feed data box.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Product_Admin_Fields {
	/**
	 * Registers hooks.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_supplier_price_field' ) );
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_manual_price_field' ), 20 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_manual_price' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_manual_price_filter' ), 20, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply_manual_price_filter' ) );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_manual_price_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_manual_price_column' ), 20, 2 );
	}

	/**
	 * Adds the supplier sidebar box and the raw feed data box to the product edit screen.
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'schrack_supplier_info',
			__( 'Beszállító', 'schrack-woocommerce-sync' ),
			array( $this, 'render_supplier_box' ),
			'product',
			'side',
			'high'
		);

		add_meta_box(
			'schrack_raw_feed_data',
			__( 'Nyers beszállítói adatok (feed)', 'schrack-woocommerce-sync' ),
			array( $this, 'render_raw_feed_box' ),
			'product',
			'normal',
			'low'
		);
	}

	/**
	 * Renders the sidebar box showing which supplier a product was synced from.
	 */
	public function render_supplier_box( WP_Post $post ): void {
		$product_id = $post->ID;
		$source     = get_post_meta( $product_id, '_schrack_catalog_source', true );
		$source     = is_string( $source ) && '' !== $source ? $source : '';

		$fields = array();

		if ( '' === $source ) {
			$fields[] = array(
				'label' => __( 'Forrás', 'schrack-woocommerce-sync' ),
				'value' => __( 'Nincs beszállítói adat (manuálisan létrehozott termék)', 'schrack-woocommerce-sync' ),
			);

			include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-supplier-box.php';
			return;
		}

		$item_number_key  = 'schrack' === $source ? '_schrack_item_number' : '_' . $source . '_item_number';
		$ean_key          = 'schrack' === $source ? '_schrack_ean' : '_' . $source . '_ean';
		$manufacturer_key = 'schrack' === $source ? '_schrack_manufacturer' : '_' . $source . '_manufacturer';

		$fields[] = array(
			'label' => __( 'Forrás', 'schrack-woocommerce-sync' ),
			'value' => $this->source_label( $source ),
		);

		$supplier = get_post_meta( $product_id, '_schrack_supplier', true );
		if ( is_string( $supplier ) && '' !== $supplier ) {
			$fields[] = array(
				'label' => __( 'Beszállító', 'schrack-woocommerce-sync' ),
				'value' => $supplier,
			);
		}

		$item_number = get_post_meta( $product_id, $item_number_key, true );
		if ( is_string( $item_number ) && '' !== $item_number ) {
			$fields[] = array(
				'label' => __( 'Cikkszám a beszállítónál', 'schrack-woocommerce-sync' ),
				'value' => $item_number,
			);
		}

		$manufacturer = get_post_meta( $product_id, $manufacturer_key, true );
		if ( is_string( $manufacturer ) && '' !== $manufacturer ) {
			$fields[] = array(
				'label' => __( 'Gyártó', 'schrack-woocommerce-sync' ),
				'value' => $manufacturer,
			);
		}

		$ean = get_post_meta( $product_id, $ean_key, true );
		if ( is_string( $ean ) && '' !== $ean ) {
			$fields[] = array(
				'label' => __( 'EAN', 'schrack-woocommerce-sync' ),
				'value' => $ean,
			);
		}

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-supplier-box.php';
	}

	/**
	 * Shows the synced supplier purchase price alongside the Regular/Sale price
	 * fields, so the markup applied to reach the storefront price is visible
	 * without leaving the product edit screen. Read-only: the value is only
	 * ever written by the price/catalog sync, never by editing this field.
	 */
	public function render_supplier_price_field(): void {
		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$purchase_price = get_post_meta( $post->ID, '_schrack_purchase_price', true );

		if ( '' === $purchase_price || ! is_numeric( $purchase_price ) ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'                => 'schrack_purchase_price_display',
				'label'             => __( 'Preț furnizor', 'schrack-woocommerce-sync' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value'             => wc_format_localized_price( (float) $purchase_price ),
				'data_type'         => 'price',
				'custom_attributes' => array( 'readonly' => 'readonly' ),
				'desc_tip'          => true,
				'description'       => __( 'A beszállítói beszerzési ár, automatikusan szinkronizálva. Itt nem szerkeszthető.', 'schrack-woocommerce-sync' ),
			)
		);
	}

	/**
	 * Renders the protected manual storefront price and its automatic-price state.
	 */
	public function render_manual_price_field(): void {
		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$manual_price    = get_post_meta( $post->ID, Schrack_Manual_Price::META_PRICE, true );
		$automatic_price = get_post_meta( $post->ID, Schrack_Manual_Price::META_AUTOMATIC, true );
		$status          = sanitize_key( (string) get_post_meta( $post->ID, Schrack_Manual_Price::META_STATUS, true ) );
		$previous_price  = get_post_meta( $post->ID, Schrack_Manual_Price::META_PREVIOUS, true );

		woocommerce_wp_text_input(
			array(
				'id'          => Schrack_Manual_Price::META_PRICE,
				'label'       => __( 'Kézi eladási ár', 'schrack-woocommerce-sync' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value'       => is_numeric( $manual_price ) ? wc_format_localized_price( (float) $manual_price ) : '',
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Amíg a beszállítói adatokból számított automatikus eladási ár nem magasabb, ez az ár marad aktív. Az érték törlésével visszaáll az automatikus ár.', 'schrack-woocommerce-sync' ),
			)
		);

		if ( is_numeric( $automatic_price ) ) {
			woocommerce_wp_text_input(
				array(
					'id'                => 'schrack_automatic_price_display',
					'label'             => __( 'Automatikus eladási ár', 'schrack-woocommerce-sync' ) . ' (' . get_woocommerce_currency_symbol() . ')',
					'value'             => wc_format_localized_price( (float) $automatic_price ),
					'data_type'         => 'price',
					'custom_attributes' => array( 'readonly' => 'readonly' ),
					'desc_tip'          => true,
					'description'       => __( 'A legutóbbi beszállítói szinkron alapján számított eladási ár. Itt nem szerkeszthető.', 'schrack-woocommerce-sync' ),
				)
			);
		}

		if ( 'overridden' === $status && is_numeric( $previous_price ) ) {
			echo '<p class="form-field"><label>' . esc_html__( 'Kézi ár státusza', 'schrack-woocommerce-sync' ) . '</label><span class="description">';
			echo esc_html(
				sprintf(
					/* translators: %s: previous manual price. */
					__( 'A beszállítói ár felülírta a korábbi %s kézi árat. Új kézi ár megadásával ismét aktiválható.', 'schrack-woocommerce-sync' ),
					wp_strip_all_tags( wc_price( (float) $previous_price ) )
				)
			);
			echo '</span></p>';
		}
	}

	/**
	 * Saves or clears the protected manual price from the WooCommerce product editor.
	 */
	public function save_manual_price( WC_Product $product ): void {
		if ( ! isset( $_POST[ Schrack_Manual_Price::META_PRICE ] ) ) {
			return;
		}

		$posted = trim( (string) wp_unslash( $_POST[ Schrack_Manual_Price::META_PRICE ] ) );
		$current = $product->get_meta( Schrack_Manual_Price::META_PRICE, true );

		if ( '' === $posted ) {
			if ( is_scalar( $current ) && '' !== trim( (string) $current ) ) {
				Schrack_Manual_Price::clear_product_price( $product );
			}

			return;
		}

		$manual_price = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $posted ) : str_replace( ',', '.', $posted );

		if ( ! is_numeric( $manual_price ) || (float) $manual_price <= 0.0 ) {
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				WC_Admin_Meta_Boxes::add_error( __( 'A kézi eladási árnak nullánál nagyobb számnak kell lennie.', 'schrack-woocommerce-sync' ) );
			}

			return;
		}

		$result = Schrack_Manual_Price::set_product_price( $product, (float) $manual_price );

		if ( $result['manual_overridden'] && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( __( 'A kézi ár nem aktiválható, mert a jelenlegi automatikus beszállítói ár magasabb.', 'schrack-woocommerce-sync' ) );
		}
	}

	/**
	 * Adds a manual-price state dropdown to the WooCommerce product list.
	 */
	public function render_manual_price_filter( string $post_type, string $which = 'top' ): void {
		unset( $which );

		if ( 'product' !== $post_type ) {
			return;
		}

		$selected = isset( $_GET['schrack_manual_price_filter'] )
			? sanitize_key( wp_unslash( (string) $_GET['schrack_manual_price_filter'] ) )
			: '';
		?>
		<label class="screen-reader-text" for="schrack-manual-price-filter"><?php esc_html_e( 'Szűrés kézi ár szerint', 'schrack-woocommerce-sync' ); ?></label>
		<select id="schrack-manual-price-filter" name="schrack_manual_price_filter">
			<option value=""><?php esc_html_e( 'Minden árkezelés', 'schrack-woocommerce-sync' ); ?></option>
			<option value="manual" <?php selected( $selected, 'manual' ); ?>><?php esc_html_e( 'Minden kézi áras termék', 'schrack-woocommerce-sync' ); ?></option>
			<option value="active" <?php selected( $selected, 'active' ); ?>><?php esc_html_e( 'Aktív kézi ár', 'schrack-woocommerce-sync' ); ?></option>
			<option value="overridden" <?php selected( $selected, 'overridden' ); ?>><?php esc_html_e( 'Beszállítói ár felülírta', 'schrack-woocommerce-sync' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Applies the selected manual-price state to the main admin product query.
	 */
	public function apply_manual_price_filter( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}

		$filter = isset( $_GET['schrack_manual_price_filter'] )
			? sanitize_key( wp_unslash( (string) $_GET['schrack_manual_price_filter'] ) )
			: '';

		if ( ! in_array( $filter, array( 'manual', 'active', 'overridden' ), true ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();

		if ( 'active' === $filter ) {
			$meta_query[] = array(
				'key'     => Schrack_Manual_Price::META_PRICE,
				'compare' => 'EXISTS',
			);
		} elseif ( 'overridden' === $filter ) {
			$meta_query[] = array(
				'key'     => Schrack_Manual_Price::META_STATUS,
				'value'   => 'overridden',
				'compare' => '=',
			);
		} else {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => Schrack_Manual_Price::META_PRICE,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => Schrack_Manual_Price::META_STATUS,
					'value'   => 'overridden',
					'compare' => '=',
				),
			);
		}

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Adds a compact manual-price status column to the product list.
	 *
	 * @param array<string,string> $columns Product columns.
	 * @return array<string,string>
	 */
	public function add_manual_price_column( array $columns ): array {
		$output = array();

		foreach ( $columns as $key => $label ) {
			$output[ $key ] = $label;

			if ( 'price' === $key ) {
				$output['schrack_manual_price'] = __( 'Kézi ár', 'schrack-woocommerce-sync' );
			}
		}

		return $output;
	}

	/**
	 * Renders the manual-price status in the product list.
	 */
	public function render_manual_price_column( string $column, int $product_id ): void {
		if ( 'schrack_manual_price' !== $column ) {
			return;
		}

		$status         = sanitize_key( (string) get_post_meta( $product_id, Schrack_Manual_Price::META_STATUS, true ) );
		$manual_price   = get_post_meta( $product_id, Schrack_Manual_Price::META_PRICE, true );
		$previous_price = get_post_meta( $product_id, Schrack_Manual_Price::META_PREVIOUS, true );

		if ( 'active' === $status && is_numeric( $manual_price ) ) {
			echo '<strong style="color:#1d6f42">' . esc_html__( 'Aktív', 'schrack-woocommerce-sync' ) . '</strong><br>';
			echo wp_kses_post( wc_price( (float) $manual_price ) );
			return;
		}

		if ( 'overridden' === $status ) {
			echo '<strong style="color:#a15c00">' . esc_html__( 'Felülírva', 'schrack-woocommerce-sync' ) . '</strong>';
			if ( is_numeric( $previous_price ) ) {
				echo '<br><del>' . wp_kses_post( wc_price( (float) $previous_price ) ) . '</del>';
			}
			return;
		}

		echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Nincs kézi ár', 'schrack-woocommerce-sync' ) . '</span>';
	}

	/**
	 * Renders the raw, unfiltered feed data table for the product.
	 */
	public function render_raw_feed_box( WP_Post $post ): void {
		$raw_json = get_post_meta( $post->ID, '_schrack_raw_feed_data', true );
		$raw_data = is_string( $raw_json ) ? json_decode( $raw_json, true ) : null;
		$raw_data = is_array( $raw_data ) ? $raw_data : array();

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-product-raw-feed-box.php';
	}

	/**
	 * Returns a readable source label matching Schrack_Product_Mapper::catalog_source_label().
	 */
	private function source_label( string $source ): string {
		return match ( sanitize_key( $source ) ) {
			'telesystem' => 'Telesystem',
			'schrack'    => 'Schrack',
			default      => ucwords( str_replace( array( '-', '_' ), ' ', sanitize_key( $source ) ) ),
		};
	}
}
