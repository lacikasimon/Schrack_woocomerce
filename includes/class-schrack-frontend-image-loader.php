<?php
/**
 * Frontend product image fallback and background import queueing.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Frontend_Image_Loader {
	public const BACKGROUND_HOOK = 'schrack_wc_sync_frontend_image';

	private const IMAGE_META_KEY     = '_schrack_image_url';
	private const QUEUED_AT_META_KEY = '_schrack_frontend_image_queued_at';
	private const ACTION_GROUP       = 'schrack-wc-sync';

	/**
	 * Per-request background queue attempts.
	 *
	 * @var int
	 */
	private static int $attempts = 0;

	/**
	 * Products already queued during this request.
	 *
	 * @var array<int,bool>
	 */
	private static array $queued_products = array();

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
	 * Registers WooCommerce image fallback and background import hooks.
	 */
	public function init(): void {
		add_action( 'woocommerce_before_single_product', array( $this, 'ensure_current_product_image' ), 5 );
		add_action( self::BACKGROUND_HOOK, array( $this, 'download_background_product_image' ), 10, 1 );
		add_filter( 'woocommerce_product_get_image', array( $this, 'remote_product_image_filter' ), 10, 6 );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'remote_single_product_image_filter' ), 10, 2 );
	}

	/**
	 * Queues the queried WooCommerce product image import before templates render.
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

		$this->ensure_product_image( $product, 1 );
	}

	/**
	 * Queues a background image import when a product has a Schrack image URL but no featured image.
	 */
	public function ensure_product_image( WC_Product $product, int $request_limit = 1 ): WC_Product {
		if ( ! $this->is_image_import_enabled() ) {
			return $product;
		}

		$request_limit = max( 0, (int) apply_filters( 'schrack_wc_sync_frontend_image_import_limit', $request_limit, $product ) );

		if ( $request_limit <= 0 || self::$attempts >= $request_limit ) {
			return $product;
		}

		if ( $this->product_has_local_image( $product ) ) {
			return $product;
		}

		$image_url = $this->product_remote_image_url( $product );

		if ( '' === $image_url ) {
			return $product;
		}

		++self::$attempts;
		$this->queue_background_image_import( $product );

		return $product;
	}

	/**
	 * Replaces WooCommerce placeholders with the stored Schrack image URL.
	 */
	public function remote_product_image_filter( mixed $image, mixed $product, mixed $size = 'woocommerce_thumbnail', mixed $attr = array(), mixed $placeholder = true, mixed $original_image = null ): string {
		$image = is_string( $image ) ? $image : '';

		if ( ! $product instanceof WC_Product ) {
			return $image;
		}

		if ( $this->product_has_local_image( $product ) ) {
			return $image;
		}

		if ( '' !== trim( $image ) && ! $this->looks_like_placeholder_image( $image ) && '' === $this->product_remote_image_url( $product ) ) {
			return $image;
		}

		$remote_image = $this->remote_product_image_html( $product, $size, is_array( $attr ) ? $attr : array() );

		return '' !== $remote_image ? $remote_image : $image;
	}

	/**
	 * Replaces the default single-product placeholder gallery image with the stored Schrack image URL.
	 */
	public function remote_single_product_image_filter( mixed $html, mixed $post_thumbnail_id ): string {
		$html = is_string( $html ) ? $html : '';

		global $product;

		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		$thumbnail_id     = absint( $post_thumbnail_id );
		$product_image_id = (int) $product->get_image_id();

		if ( $thumbnail_id > 0 && $thumbnail_id !== $product_image_id ) {
			return $html;
		}

		if ( $this->product_has_local_image( $product ) ) {
			return $html;
		}

		$image_url = $this->product_remote_image_url( $product );

		if ( '' === $image_url ) {
			return $html;
		}

		$image = $this->remote_product_image_html(
			$product,
			'woocommerce_single',
			array(
				'class'   => 'wp-post-image',
				'loading' => 'eager',
			)
		);

		if ( '' === $image ) {
			return $html;
		}

		return '<div class="woocommerce-product-gallery__image schrack-product-gallery__image--remote"><a href="' . esc_url( $image_url ) . '">' . $image . '</a></div>';
	}

	/**
	 * Builds a remote product image tag for products whose image is not imported yet.
	 *
	 * @param mixed               $size WooCommerce image size.
	 * @param array<string,mixed> $attr Image attributes.
	 */
	public function remote_product_image_html( WC_Product $product, mixed $size = 'woocommerce_thumbnail', array $attr = array() ): string {
		if ( $this->product_has_local_image( $product ) ) {
			return '';
		}

		$image_url = $this->product_remote_image_url( $product );

		if ( '' === $image_url ) {
			return '';
		}

		if ( $this->is_image_import_enabled() ) {
			$this->queue_background_image_import( $product );
		}

		$attributes = $this->remote_image_attributes( $product, $size, $attr, $image_url );

		return '<img ' . $this->image_attributes_html( $attributes ) . ' />';
	}

	/**
	 * Imports a queued frontend image in the background.
	 */
	public function download_background_product_image( mixed $product_id ): void {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return;
		}

		if ( 'yes' !== $this->settings->get( 'image_import_enabled', 'yes' ) ) {
			delete_post_meta( $product_id, self::QUEUED_AT_META_KEY );
			return;
		}

		if ( ! in_array( get_post_type( $product_id ), array( 'product', 'product_variation' ), true ) ) {
			delete_post_meta( $product_id, self::QUEUED_AT_META_KEY );
			return;
		}

		add_filter( 'schrack_wc_sync_image_download_timeout', array( $this, 'background_download_timeout' ), 10, 3 );

		try {
			$result = $this->mapper->import_product_image_with_result( $product_id );
		} finally {
			remove_filter( 'schrack_wc_sync_image_download_timeout', array( $this, 'background_download_timeout' ), 10 );
		}

		delete_post_meta( $product_id, self::QUEUED_AT_META_KEY );

		$status        = (string) ( $result['status'] ?? '' );
		$attachment_id = absint( $result['attachment_id'] ?? 0 );

		$this->logger->info(
			'images',
			'Processed queued frontend Schrack product image import.',
			$this->product_sku_for_log( $product_id ),
			array(
				'product_id'    => $product_id,
				'status'        => $status,
				'attachment_id' => $attachment_id,
				'image_url'     => (string) ( $result['image_url'] ?? '' ),
				'error'         => (string) ( $result['error'] ?? '' ),
			)
		);
	}

	/**
	 * Keeps background on-demand downloads from occupying a worker for too long.
	 */
	public function background_download_timeout( mixed $timeout = null, string $image_url = '', mixed $product = null ): int {
		$default_timeout = max( 5, min( 60, (int) $this->settings->get( 'image_download_timeout', 15 ) ) );

		return min( $default_timeout, 20 );
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
	 * Queues a non-blocking background image import for a product.
	 */
	private function queue_background_image_import( WC_Product $product ): void {
		$product_id = (int) $product->get_id();

		if ( $product_id <= 0 || ! $this->is_image_import_enabled() ) {
			return;
		}

		if ( isset( self::$queued_products[ $product_id ] ) || $this->product_has_local_image( $product ) || $this->is_recent_failed_attempt( $product ) ) {
			return;
		}

		if ( '' === $this->product_remote_image_url( $product ) || $this->has_recent_background_queue( $product_id ) ) {
			self::$queued_products[ $product_id ] = true;
			return;
		}

		$args = array( $product_id );

		if ( $this->has_scheduled_background_action( $args ) ) {
			update_post_meta( $product_id, self::QUEUED_AT_META_KEY, time() );
			self::$queued_products[ $product_id ] = true;
			return;
		}

		$queued       = false;
		$action_id    = 0;
		$queue_runner = '';

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id    = absint( as_enqueue_async_action( self::BACKGROUND_HOOK, $args, self::ACTION_GROUP ) );
			$queued       = $action_id > 0;
			$queue_runner = 'action_scheduler_async';
		}

		if ( ! $queued && function_exists( 'as_schedule_single_action' ) ) {
			$action_id    = absint( as_schedule_single_action( time() + 1, self::BACKGROUND_HOOK, $args, self::ACTION_GROUP ) );
			$queued       = $action_id > 0;
			$queue_runner = 'action_scheduler_single';
		}

		if ( ! $queued ) {
			$queued       = false !== wp_schedule_single_event( time() + 5, self::BACKGROUND_HOOK, $args );
			$queue_runner = 'wp_cron';
		}

		if ( ! $queued ) {
			$this->logger->warning(
				'images',
				'Could not queue frontend Schrack product image import.',
				$product->get_sku(),
				array(
					'product_id'   => $product_id,
					'image_url'    => $this->product_remote_image_url( $product ),
					'queue_runner' => $queue_runner,
				)
			);
			return;
		}

		update_post_meta( $product_id, self::QUEUED_AT_META_KEY, time() );
		self::$queued_products[ $product_id ] = true;

		$this->logger->debug(
			'images',
			'Queued frontend Schrack product image import.',
			$product->get_sku(),
			array(
				'product_id'   => $product_id,
				'image_url'    => $this->product_remote_image_url( $product ),
				'queue_runner' => $queue_runner,
				'action_id'    => $action_id,
			)
		);
	}

	/**
	 * Returns whether a background import was recently queued for this product.
	 */
	private function has_recent_background_queue( int $product_id ): bool {
		$queued_at = absint( get_post_meta( $product_id, self::QUEUED_AT_META_KEY, true ) );

		return $queued_at > 0 && $queued_at >= time() - 30 * MINUTE_IN_SECONDS;
	}

	/**
	 * Returns whether an exact background import action is already scheduled.
	 *
	 * @param array<int,mixed> $args Hook arguments.
	 */
	private function has_scheduled_background_action( array $args ): bool {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::BACKGROUND_HOOK, $args, self::ACTION_GROUP );

			return false !== $next && null !== $next;
		}

		return false !== wp_next_scheduled( self::BACKGROUND_HOOK, $args );
	}

	/**
	 * Returns the stored Schrack remote image URL for a product.
	 */
	private function product_remote_image_url( WC_Product $product ): string {
		return $this->normalize_image_url( (string) $product->get_meta( self::IMAGE_META_KEY, true ) );
	}

	/**
	 * Returns whether remote images should be downloaded in the background.
	 */
	private function is_image_import_enabled(): bool {
		return 'yes' === (string) $this->settings->get( 'image_import_enabled', 'yes' );
	}

	/**
	 * Returns whether the product already has a valid local image for its current Schrack URL.
	 */
	private function product_has_local_image( WC_Product $product ): bool {
		$image_id = (int) $product->get_image_id();

		if ( $image_id <= 0 || 'attachment' !== get_post_type( $image_id ) ) {
			return false;
		}

		$is_valid_image = function_exists( 'wp_attachment_is_image' )
			? wp_attachment_is_image( $image_id )
			: '' !== (string) wp_get_attachment_url( $image_id );

		if ( ! $is_valid_image ) {
			return false;
		}

		$image_url    = $this->product_remote_image_url( $product );
		$imported_url = $this->normalize_image_url( (string) $product->get_meta( '_schrack_imported_image_url', true ) );

		return '' === $image_url || '' === $imported_url || $imported_url === $image_url;
	}

	/**
	 * Builds sanitized attributes for a remote product image tag.
	 *
	 * @param mixed               $size WooCommerce image size.
	 * @param array<string,mixed> $attr Incoming image attributes.
	 * @return array<string,mixed>
	 */
	private function remote_image_attributes( WC_Product $product, mixed $size, array $attr, string $image_url ): array {
		unset( $attr['src'], $attr['srcset'], $attr['sizes'] );

		$attr['src']      = $image_url;
		$attr['alt']      = isset( $attr['alt'] ) ? sanitize_text_field( (string) $attr['alt'] ) : wp_strip_all_tags( $product->get_name() );
		$attr['class']    = $this->remote_image_class( $size, (string) ( $attr['class'] ?? '' ) );
		$attr['decoding'] = (string) ( $attr['decoding'] ?? 'async' );

		if ( empty( $attr['loading'] ) ) {
			$attr['loading'] = 'lazy';
		}

		return $attr;
	}

	/**
	 * Builds classes similar to WooCommerce attachment image output.
	 */
	private function remote_image_class( mixed $size, string $incoming_class = '' ): string {
		$size_label = is_array( $size )
			? implode( 'x', array_filter( array_map( 'absint', $size ) ) )
			: sanitize_html_class( (string) $size );

		$classes = array_filter( preg_split( '/\s+/', trim( $incoming_class ) ) ?: array() );
		$classes[] = 'schrack-remote-product-image';

		if ( '' !== $size_label ) {
			$classes[] = 'attachment-' . $size_label;
			$classes[] = 'size-' . $size_label;
		}

		return implode( ' ', array_values( array_unique( array_filter( $classes ) ) ) );
	}

	/**
	 * Serializes image attributes.
	 *
	 * @param array<string,mixed> $attributes Image attributes.
	 */
	private function image_attributes_html( array $attributes ): string {
		$html = array();

		foreach ( $attributes as $name => $value ) {
			$name = is_string( $name ) ? strtolower( trim( $name ) ) : '';

			if ( '' === $name || ! preg_match( '/^[a-z_:][a-z0-9_:.:-]*$/', $name ) || null === $value || false === $value ) {
				continue;
			}

			if ( true === $value ) {
				$html[] = esc_attr( $name );
				continue;
			}

			$value = is_scalar( $value ) ? (string) $value : '';

			if ( '' === $value ) {
				continue;
			}

			$html[] = esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		return implode( ' ', $html );
	}

	/**
	 * Detects WooCommerce placeholder image markup.
	 */
	private function looks_like_placeholder_image( string $image ): bool {
		return str_contains( $image, 'woocommerce-placeholder' )
			|| str_contains( $image, 'woocommerce-product-gallery__image--placeholder' )
			|| str_contains( $image, 'placeholder.png' )
			|| str_contains( $image, 'wc-placeholder' );
	}

	/**
	 * Reads a product SKU for logs without relying on frontend globals.
	 */
	private function product_sku_for_log( int $product_id ): ?string {
		$sku = sanitize_text_field( (string) get_post_meta( $product_id, '_sku', true ) );

		if ( '' === $sku ) {
			$sku = sanitize_text_field( (string) get_post_meta( $product_id, '_schrack_item_number', true ) );
		}

		return '' !== $sku ? $sku : null;
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

		$image_url = esc_url_raw( $image_url );

		if ( preg_match( '/^http:\/\//i', $image_url ) ) {
			$image_url = 'https://' . substr( $image_url, 7 );
		}

		return $image_url;
	}
}
