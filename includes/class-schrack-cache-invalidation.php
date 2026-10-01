<?php
/** Preserve technical caches when only catalog taxonomy content changes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_Cache_Invalidation {
	private const DEPLOYED_VERSION = 'schrack_frontend_cache_version';
	private bool $purged = false;
	private static bool $active = false;

	public static function is_active(): bool { return self::$active; }

	public function init(): void {
		add_action( 'init', array( $this, 'configure' ), -100 );
		add_action( 'wp_loaded', array( $this, 'deployed_version_changed' ) );
	}

	/** Git deployments bypass WordPress upgrader hooks; refresh public HTML once. */
	public function deployed_version_changed(): void {
		if ( ! defined( 'SCHRACK_WC_SYNC_VERSION' )
			|| ! defined( 'LiteSpeed\\Core::VER' ) || '7.9.1' !== constant( 'LiteSpeed\\Core::VER' )
			|| ! has_action( 'litespeed_purge', 'LiteSpeed\\Purge::add' )
			|| ! apply_filters( 'schrack_wc_sync_purge_deployed_html', true )
			|| SCHRACK_WC_SYNC_VERSION === get_option( self::DEPLOYED_VERSION ) ) { return; }
		global $wpdb;
		$name = 'schrack_deploy_' . md5( $wpdb->options );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { return; }
		try {
			// Re-read after acquiring the connection lock; another request may have
			// completed this release while our request was starting.
			wp_cache_delete( self::DEPLOYED_VERSION, 'options' );
			if ( SCHRACK_WC_SYNC_VERSION === get_option( self::DEPLOYED_VERSION ) ) { return; }
			do_action( 'litespeed_purge', '*' );
			do_action( 'schrack_catalog_pages_purged' );
			update_option( self::DEPLOYED_VERSION, SCHRACK_WC_SYNC_VERSION, false );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	public function configure(): void {
		// This callback contract was inspected against 7.9.1; updates fail open.
		if ( ! defined( 'LiteSpeed\\Core::VER' ) || '7.9.1' !== constant( 'LiteSpeed\\Core::VER' )
			|| ! has_action( 'litespeed_purge', 'LiteSpeed\\Purge::add' )
			|| ! apply_filters( 'schrack_wc_sync_preserve_catalog_technical_cache', true )
			|| apply_filters( 'litespeed_conf', 'cdn-cloudflare' ) ) { return; }
		foreach ( array( 'create_term', 'edit_terms', 'delete_term' ) as $hook ) {
			$priority = has_action( $hook, 'LiteSpeed\\Purge::purge_all' );
			if ( false !== $priority ) {
				remove_action( $hook, 'LiteSpeed\\Purge::purge_all', $priority );
				add_action( $hook, array( $this, 'term_changed' ), $priority, 3 );
				self::$active = true;
			}
		}
	}

	public function term_changed( $term_id, $second = '', $third = '' ): void {
		$taxonomy = 'edit_terms' === current_filter() ? $second : $third;
		if ( ! is_string( $taxonomy ) || ( 'product_cat' !== $taxonomy && ! str_starts_with( $taxonomy, 'pa_' ) ) ) {
			do_action( 'litespeed_purge_all' );
			return;
		}
		if ( $this->purged ) { return; }
		$this->purged = true;
		// Same public HTML invalidation as LSCWP's purge_all_lscache(), via its API.
		// WordPress/WooCommerce retain their native term/object invalidation.
		do_action( 'litespeed_purge', '*' );
		do_action( 'schrack_catalog_pages_purged' );
	}
}
