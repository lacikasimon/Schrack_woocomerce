<?php
/**
 * On-demand frontend product image import.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Frontend_Image_Loader {
	/**
	 * Per-request import attempts.
	 *
	 * @var int
	 */
	private static int $attempts = 0;

	/**
	 * Settings service.
	 *
	 * @var Schrack_Settings
	 */
	private Schrack_Settings $settings;

	/**
	 * Logger service.
	 *
	 * @var Schrack_Logger
	 */
	private Schrack_Logger $logger;

	/**
	 * Product mapper.
	 *
	 * @var Schrack_Product_Mapper
	 */
	private Schrack_Product_Mapper $mapper;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, ?Schrack_Product_Mapper $mapper = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->mapper   = $mapper ?: new Schrack_Product_Mapper( $settings, $logger );
	}

	/**
	 * Registers generic WooCommerce product-page hydration.
	 */
	public function init(): void {
		add_action( 'woocommerce_before_single_product', array( $this, 'ensure_current_product_image' ), 5 );
	}

	/**
	 * Ensures the queried WooCommerce product has its Schrack image before templates render.
	 */
	public function ensure_current_product_image(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product_id = get_queried_object_id();
			$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		}

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$fresh_product = $this->ensure_product_image( $product, 1 );

		if ( $fresh_product instanceof WC_Product ) {
			$GLOBALS['product'] = $fresh_product;
		}
	}

	/**
	 * Imports or reuses a product image when the product has a Schrack image URL but no featured image.
	 */
	public function ensure_product_image( WC_Product $product, int $request_limit = 1 ): WC_Product {
		$request_limit = max( 0, (int) apply_filters( 'schrack_wc_sync_frontend_image_import_limit', $request_limit, $product ) );

		if ( $request_limit <= 0 || self::$attempts >= $request_limit ) {
			return $product;
		}

		if ( 'yes' !== $this->settings->get( 'image_import_enabled', 'yes' ) ) {
			return $product;
		}

		if ( (int) $product->get_image_id() > 0 ) {
			return $product;
		}

		$image_url = $this->normalize_image_url( (string) $product->get_meta( '_schrack_image_url', true ) );

		if ( '' === $image_url || $this->is_recent_failed_attempt( $product ) ) {
			return $product;
		}

		++self::$attempts;

		add_filter( 'schrack_wc_sync_image_download_timeout', array( $this, 'frontend_download_timeout' ), 10, 3 );

		try {
			$result = $this->mapper->import_product_image_with_result( (int) $product->get_id() );
		} finally {
			remove_filter( 'schrack_wc_sync_image_download_timeout', array( $this, 'frontend_download_timeout' ), 10 );
		}

		$status        = (string) ( $result['status'] ?? '' );
		$attachment_id = absint( $result['attachment_id'] ?? 0 );

		if ( $attachment_id <= 0 || in_array( $status, array( 'failed', 'missing_url', 'missing_product', 'skipped_disabled' ), true ) ) {
			return $product;
		}

		$fresh_product = wc_get_product( (int) $product->get_id() );

		if ( $fresh_product instanceof WC_Product ) {
			$this->logger->info(
				'images',
				'Loaded Schrack product image on demand during frontend rendering.',
				$fresh_product->get_sku(),
				array(
					'product_id'    => (int) $fresh_product->get_id(),
					'status'        => $status,
					'attachment_id' => $attachment_id,
				)
			);

			return $fresh_product;
		}

		return $product;
	}

	/**
	 * Keeps frontend requests from waiting too long on slow remote image downloads.
	 */
	public function frontend_download_timeout( mixed $timeout = null, string $image_url = '', mixed $product = null ): int {
		return 12;
	}

	/**
	 * Avoids repeated frontend download attempts immediately after a failed image import.
	 */
	private function is_recent_failed_attempt( WC_Product $product ): bool {
		if ( 'failed' !== (string) $product->get_meta( '_schrack_image_status', true ) ) {
			return false;
		}

		$last_attempt = absint( $product->get_meta( '_schrack_last_image_attempt_ts', true ) );

		if ( $last_attempt <= 0 ) {
			return false;
		}

		$cooldown = max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, (int) $this->settings->get( 'image_retry_cooldown', HOUR_IN_SECONDS ) ) );

		return $last_attempt >= time() - $cooldown;
	}

	/**
	 * Normalizes a stored image URL enough to decide whether an import is possible.
	 */
	private function normalize_image_url( string $image_url ): string {
		$image_url = html_entity_decode( trim( $image_url ), ENT_QUOTES );

		if ( '' === $image_url ) {
			return '';
		}

		if ( preg_match( '/\bsrc=[\'"]([^\'"]+)[\'"]/i', $image_url, $matches ) ) {
			$image_url = $matches[1];
		} elseif ( preg_match( '/https?:\/\/[^\s,;"\'<>|]+/i', $image_url, $matches ) ) {
			$image_url = $matches[0];
		} elseif ( str_starts_with( $image_url, '//' ) ) {
			$image_url = 'https:' . $image_url;
		}

		return esc_url_raw( $image_url );
	}
}
