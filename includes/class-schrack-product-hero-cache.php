<?php
/** Background-only responsive WebP cache for published, available Schrack products. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Schrack_Product_Hero_Cache {
	public const HOOK = 'schrack_wc_sync_product_hero';
	private const META = '_schrack_product_hero_webp';
	private const QUEUED = '_schrack_product_hero_queued';
	private const FAILED = '_schrack_product_hero_failed';
	private const GROUP = 'schrack-product-heroes';
	private const DIRECTORY = '/schrack-frontend-cache/product-heroes';
	private static array $attempted = array();

	public function init(): void {
		add_action( self::HOOK, array( $this, 'generate' ) );
		add_action( 'schrack_wc_sync_prepare_product_hero', array( $this, 'queue' ) );
	}

	/** Only exact supplier originals map to the vendor's verified, bounded preset. */
	public static function source( string $url ): string {
		return preg_match( '~^https?://(?:image\.schrack\.com|image\.schrackcdn\.com)/foto/(f_[a-z0-9_-]+\.jpg)$~iD', $url, $m )
			? 'https://image.schrackcdn.com/1190x1330/' . $m[1] : '';
	}

	private static function eligible( WC_Product $product ): bool {
		return 'publish' === $product->get_status() && 'instock' === $product->get_stock_status()
			&& in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true )
			&& '' === (string) get_post_field( 'post_password', $product->get_id() )
			&& (bool) apply_filters( 'schrack_wc_sync_product_hero_cache', true, $product );
	}

	private static function key( string $url ): string {
		return hash( 'sha256', 'webp-v1-q80:' . self::source( $url ) );
	}

	/** Read only: the head preload and gallery share these exact candidates. */
	public static function attributes( WC_Product $product, string $url ): array {
		$meta = $product->get_meta( self::META, true );
		if ( ! is_array( $meta ) || '' === self::source( $url ) || ! self::eligible( $product )
			|| self::key( $url ) !== ( $meta['key'] ?? '' ) || ! is_array( $meta['sizes'] ?? null ) ) { return array(); }
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) { return array(); }
		$candidates = array();
		foreach ( array( 340, 680, 1190 ) as $width ) {
			$size = $meta['sizes'][ $width ] ?? null;
			if ( ! is_array( $size ) || (int) ( $size['width'] ?? 0 ) !== $width || (int) ( $size['height'] ?? 0 ) < 2 ) { return array(); }
			$file = '/' . $meta['key'] . '-' . $width . '.webp';
			if ( ! is_file( $uploads['basedir'] . self::DIRECTORY . $file ) ) { return array(); }
			$candidates[ $width ] = $uploads['baseurl'] . self::DIRECTORY . $file;
		}
		return array( 'src' => $candidates[340], 'srcset' => $candidates[340] . ' 340w, ' . $candidates[680] . ' 680w, ' . $candidates[1190] . ' 1190w',
			'width' => 340, 'height' => (int) $meta['sizes'][340]['height'] );
	}

	/** Scheduling never downloads or converts an image during an HTML request. */
	public function queue( WC_Product $product ): void {
		$id = $product->get_id();
		$url = (string) $product->get_meta( '_schrack_image_url', true );
		if ( isset( self::$attempted[ $id ] ) || ! self::eligible( $product ) || $product->get_image_id() > 0
			|| '' === self::source( $url ) || self::attributes( $product, $url ) ) { return; }
		self::$attempted[ $id ] = true;
		if ( (int) $product->get_meta( self::QUEUED, true ) > time() - 1800
			|| (int) $product->get_meta( self::FAILED, true ) > time() - HOUR_IN_SECONDS ) { return; }
		$args = array( $id );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) { return; }
		$queued = function_exists( 'as_enqueue_async_action' ) ? as_enqueue_async_action( self::HOOK, $args, self::GROUP, true ) : 0;
		if ( ! $queued && ! wp_next_scheduled( self::HOOK, $args ) ) { $queued = wp_schedule_single_event( time() + 5, self::HOOK, $args ); }
		if ( $queued ) { update_post_meta( $id, self::QUEUED, time() ); }
	}

	/** A trusted uploads child directory; symlinks cannot redirect image writes. */
	private function directory(): string {
		$uploads = wp_upload_dir( null, false );
		$root = empty( $uploads['error'] ) ? realpath( $uploads['basedir'] ?? '' ) : false;
		if ( ! $root ) { return ''; }
		$directory = $root;
		foreach ( array( 'schrack-frontend-cache', 'product-heroes' ) as $part ) {
			$directory .= '/' . $part;
			if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) ) { return ''; }
		}
		$resolved = realpath( $directory );
		return $resolved && str_starts_with( $resolved, $root . '/' ) ? $resolved : '';
	}

	/** One bounded JPEG request, then three proportional WebPs; original zoom stays intact. */
	public function generate( mixed $product_id ): void {
		$id = absint( $product_id );
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product || ! self::eligible( $product ) || $product->get_image_id() > 0 ) { delete_post_meta( $id, self::QUEUED ); return; }
		$url = (string) $product->get_meta( '_schrack_image_url', true );
		$source = self::source( $url );
		if ( '' === $source || self::attributes( $product, $url ) ) { delete_post_meta( $id, self::QUEUED ); return; }
		$directory = $this->directory();
		if ( '' === $directory ) { update_post_meta( $id, self::FAILED, time() ); delete_post_meta( $id, self::QUEUED ); return; }
		$input = tempnam( $directory, 'input-' );
		if ( false === $input ) { update_post_meta( $id, self::FAILED, time() ); delete_post_meta( $id, self::QUEUED ); return; }
		$temporary = array( $input );
		$complete = false;
		$quality = static fn( $value, $mime ) => 'image/webp' === $mime ? 80 : $value;
		add_filter( 'wp_editor_set_quality', $quality, 100, 2 );
		try {
			$response = wp_safe_remote_get( $source, array( 'timeout' => 15, 'redirection' => 0, 'cookies' => array(),
				'stream' => true, 'filename' => $input, 'limit_response_size' => 1048576 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return; }
			$dimensions = wp_getimagesize( $input );
			if ( ! is_array( $dimensions ) || 1190 !== $dimensions[0] || 1330 !== $dimensions[1]
				|| IMAGETYPE_JPEG !== $dimensions[2] || filesize( $input ) >= 1048576 ) { return; }
			$key = self::key( $url );
			$sizes = array();
			foreach ( array( 340, 680, 1190 ) as $width ) {
				$editor = wp_get_image_editor( $input );
				if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( 'image/webp' ) ) { return; }
				if ( is_wp_error( $editor->set_quality( 80 ) ) || ( $width < 1190 && is_wp_error( $editor->resize( $width, null, false ) ) ) ) { return; }
				$base = tempnam( $directory, 'webp-' );
				if ( false === $base ) { return; }
				$temporary[] = $base;
				$temp = $base . '.webp';
				$temporary[] = $temp;
				$saved = $editor->save( $temp, 'image/webp' );
				if ( is_wp_error( $saved ) || ( $saved['path'] ?? '' ) !== $temp || (int) ( $saved['width'] ?? 0 ) !== $width
					|| (int) ( $saved['height'] ?? 0 ) < 2 || ! is_file( $temp ) ) { return; }
				$webp = wp_getimagesize( $temp );
				if ( ! is_array( $webp ) || IMAGETYPE_WEBP !== $webp[2] || $width !== $webp[0]
					|| (int) $saved['height'] !== $webp[1] ) { return; }
				$target = $directory . '/' . $key . '-' . $width . '.webp';
				if ( is_link( $target ) || ! rename( $temp, $target ) ) { return; }
				chmod( $target, 0644 );
				$sizes[ $width ] = array( 'width' => $width, 'height' => (int) $saved['height'] );
			}
			// Publish metadata only after all variants exist and the source is still current.
			$current = wc_get_product( $id );
			if ( ! $current instanceof WC_Product || $url !== (string) $current->get_meta( '_schrack_image_url', true ) || ! self::eligible( $current ) ) { return; }
			update_post_meta( $id, self::META, array( 'key' => $key, 'sizes' => $sizes ) );
			delete_post_meta( $id, self::FAILED );
			$complete = true;
			do_action( 'litespeed_purge_post', $id );
		} finally {
			remove_filter( 'wp_editor_set_quality', $quality, 100 );
			delete_post_meta( $id, self::QUEUED );
			foreach ( $temporary as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
			if ( ! $complete ) { update_post_meta( $id, self::FAILED, time() ); }
		}
	}
}
