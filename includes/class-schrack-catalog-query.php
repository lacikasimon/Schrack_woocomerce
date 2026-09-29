<?php
/** Scoped query optimizations for our homepage product selections. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_Catalog_Query {
	/** Keep WooCommerce filters and ordering, replacing only the simple stock meta scan. */
	public static function product_ids( array $args ): array {
		global $wpdb;
		if ( empty( $wpdb->wc_product_meta_lookup ) || get_option( 'woocommerce_product_lookup_table_is_generating', false )
			|| ! apply_filters( 'schrack_wc_sync_catalog_stock_lookup', true ) ) {
			return wc_get_products( $args );
		}
		// A request-local token isolates nested queries and other product widgets.
		static $sequence = 0;
		$token = ++$sequence;
		$args['schrack_catalog_selection'] = $token;
		$prepare = static function ( array $wp_args, array $wc_args ) use ( $token ): array {
			if ( ( $wc_args['schrack_catalog_selection'] ?? null ) !== $token
				|| 'product' !== ( $wp_args['post_type'] ?? '' ) || 'ids' !== ( $wp_args['fields'] ?? '' )
				|| ! empty( $wp_args['meta_key'] ) || ! empty( $wp_args['suppress_filters'] ) ) { return $wp_args; }
			$meta = $wp_args['meta_query'] ?? array();
			unset( $meta['relation'] );
			// Unknown/nested/third-party conditions retain the normal WooCommerce query.
			if ( 1 !== count( $meta ) || array( 'key' => '_stock_status', 'value' => 'instock', 'compare' => '=' ) !== reset( $meta ) ) { return $wp_args; }
			$wp_args['meta_query'] = array();
			$wp_args['schrack_catalog_stock_lookup'] = $token;
			return $wp_args;
		};
		$where = static function ( string $where, WP_Query $query ) use ( $token, $wpdb ): string {
			if ( $query->get( 'schrack_catalog_stock_lookup' ) !== $token ) { return $where; }
			// Product ID is the lookup table's primary key. EXISTS avoids duplicate rows.
			return $where . " AND EXISTS (SELECT 1 FROM {$wpdb->wc_product_meta_lookup} AS schrack_home_stock
				WHERE schrack_home_stock.product_id = {$wpdb->posts}.ID AND schrack_home_stock.stock_status = 'instock')";
		};
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $prepare, PHP_INT_MAX, 2 );
		add_filter( 'posts_where', $where, PHP_INT_MAX, 2 );
		try { return wc_get_products( $args ); }
		finally {
			remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $prepare, PHP_INT_MAX );
			remove_filter( 'posts_where', $where, PHP_INT_MAX );
		}
	}

	/** Prime WordPress data in a batch before constructing individual WooCommerce objects. */
	public static function prime( array $ids ): void {
		if ( $ids && function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $ids, true, true ); }
	}
}
