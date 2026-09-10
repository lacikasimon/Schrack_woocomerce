<?php
/**
 * Shared category links for the homepage navigation and header menu.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Navigation {
	/**
	 * Returns the service and promotion categories, including empty categories.
	 *
	 * @return array<int,array{slug:string,label:string,href:string,image:string,term_id:int}>
	 */
	public static function additional_items(): array {
		$items = array(
			array(
				'slug'  => 'servicii',
				'label' => __( 'Servicii', 'schrack-woocommerce-sync' ),
				'image' => SCHRACK_WC_SYNC_URL . 'assets/shop-hero-technician.webp',
			),
			array(
				'slug'  => 'promotii',
				'label' => __( 'Promoții', 'schrack-woocommerce-sync' ),
				'image' => SCHRACK_WC_SYNC_URL . 'assets/home-category-banners/accesorii-3.webp',
			),
		);

		foreach ( $items as &$item ) {
			$item['href']    = add_query_arg( 'product_cat', $item['slug'], home_url( '/' ) );
			$item['term_id'] = 0;
			$term            = taxonomy_exists( 'product_cat' ) ? get_term_by( 'slug', $item['slug'], 'product_cat' ) : false;

			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$item['term_id'] = (int) $term->term_id;
			$link            = get_term_link( $term, 'product_cat' );

			if ( ! is_wp_error( $link ) && '' !== (string) $link ) {
				$item['href'] = (string) $link;
			}

			$thumbnail_id = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
			$image_url    = $thumbnail_id > 0 ? wp_get_attachment_image_url( $thumbnail_id, 'medium_large' ) : false;

			if ( $image_url ) {
				$item['image'] = $image_url;
			}
		}
		unset( $item );

		return $items;
	}
}
