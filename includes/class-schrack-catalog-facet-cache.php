<?php
/** Short-lived public catalogue aggregates with mutation-driven invalidation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_Catalog_Facet_Cache {
	private const GENERATION = 'schrack_catalog_facet_generation';

	public function init(): void {
		foreach ( array( 'woocommerce_delete_product_transients', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product', 'woocommerce_update_product_variation', 'clean_term_cache', 'woocommerce_attribute_added', 'woocommerce_attribute_updated', 'woocommerce_attribute_deleted' ) as $hook ) {
			add_action( $hook, array( self::class, 'invalidate' ), PHP_INT_MAX, 0 );
		}
		add_action( 'clean_post_cache', array( $this, 'post_changed' ), PHP_INT_MAX, 2 );
		add_action( 'set_object_terms', array( $this, 'terms_changed' ), PHP_INT_MAX, 4 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'meta_changed' ), PHP_INT_MAX, 3 );
		}
		add_action( 'update_option_schrack_wc_sync_dynamic_attributes', array( self::class, 'invalidate' ), PHP_INT_MAX, 0 );
	}

	public static function invalidate(): void {
		// A UUID also distinguishes consecutive changes within the same second.
		update_option( self::GENERATION, wp_generate_uuid4(), false );
	}

	public function post_changed( int $id, $post ): void {
		if ( in_array( $post->post_type ?? '', array( 'product', 'product_variation' ), true ) ) { self::invalidate(); }
	}

	public function terms_changed( $id, $terms, $tt_ids, string $taxonomy ): void {
		if ( 'product_cat' === $taxonomy || str_starts_with( $taxonomy, 'pa_' ) ) { self::invalidate(); }
	}

	public function meta_changed( $meta_id, $object_id, string $key ): void {
		if ( in_array( $key, array( '_stock_status', '_schrack_manufacturer', '_schrack_product_line' ), true ) ) { self::invalidate(); }
	}

	/** Cache only public counts/options, never prices, customer data or product HTML. */
	public static function remember( string $scope, callable $compute ): array {
		if ( is_admin() || wp_doing_ajax()
			|| ( class_exists( 'Schrack_Page_Profile' ) && Schrack_Page_Profile::cold_selections() )
			|| ! apply_filters( 'schrack_wc_sync_catalog_facet_cache', true ) ) {
			return $compute();
		}
		$generation = get_option( self::GENERATION, '' );
		if ( '' === $generation ) {
			add_option( self::GENERATION, wp_generate_uuid4(), '', false );
			$generation = get_option( self::GENERATION, '' );
		}
		$key = 'schrack_facets_' . md5( SCHRACK_WC_SYNC_VERSION . '|' . get_locale() . '|' . $scope );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ( $cached['generation'] ?? null ) === $generation && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
			return $cached['data'];
		}
		$data = $compute();
		// A concurrent catalogue edit must never publish this older snapshot.
		wp_cache_delete( self::GENERATION, 'options' );
		if ( $generation === get_option( self::GENERATION, '' ) ) {
			set_transient( $key, array( 'generation' => $generation, 'data' => $data ), 120 );
		}
		return $data;
	}
}
