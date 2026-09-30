<?php
/** Preserve primary WooCommerce categories when consolidating SEO in SiteSEO. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Schrack_SEO_Compatibility {
	private const BACKUP = 'schrack_seo_primary_backup';
	private const TARGET = '_siteseo_robots_primary_cat';
	private const SOURCE = '_yoast_wpseo_primary_product_cat';
	public function merge(): array {
		global $wpdb;
		if ( ! defined( 'SITESEO_VERSION' ) || '1.4.1' !== SITESEO_VERSION ) { throw new RuntimeException( 'Compatibilitatea este verificată pentru SiteSEO 1.4.1.' ); }
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value<>'' LIMIT 101", self::SOURCE ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Metadatele SEO nu pot fi citite.' ); }
		if ( count( $ids ) > 100 ) { throw new RuntimeException( 'Mai mult de 100 de categorii primare: este necesară migrare în fundal.' ); }
		$backup = get_option( self::BACKUP, array() ); $result = array( 'imported' => 0, 'preserved' => 0, 'invalid' => 0 );
		foreach ( $ids as $id ) {
			$id = (int) $id; $category = (int) get_post_meta( $id, self::SOURCE, true );
			$old = get_post_meta( $id, self::TARGET, true );
			if ( '' !== (string) $old ) { ++$result['preserved']; continue; }
			if ( 'product' !== get_post_type( $id ) || $category < 1 || ! has_term( $category, 'product_cat', $id ) ) { ++$result['invalid']; continue; }
			$backup[ $id ] = array( 'existed' => metadata_exists( 'post', $id, self::TARGET ), 'old' => $old, 'imported' => (string) $category );
			update_option( self::BACKUP, $backup, false );
			wp_cache_delete( self::BACKUP, 'options' );
			if ( get_option( self::BACKUP, array() ) !== $backup ) { throw new RuntimeException( 'Copia metadatelor nu poate fi confirmată.' ); }
			// Recheck after the backup; never overwrite an explicit SiteSEO selection.
			if ( '' !== (string) get_post_meta( $id, self::TARGET, true ) ) { ++$result['preserved']; continue; }
			if ( false === update_post_meta( $id, self::TARGET, (string) $category ) ) { throw new RuntimeException( 'Categoria primară nu poate fi importată.' ); }
			++$result['imported'];
		}
		return $result;
	}
	public function restore(): array {
		$result = array( 'restored' => 0, 'preserved_edits' => 0 );
		foreach ( get_option( self::BACKUP, array() ) as $id => $row ) {
			if ( (string) get_post_meta( $id, self::TARGET, true ) !== $row['imported'] ) { ++$result['preserved_edits']; continue; }
			$changed = $row['existed'] ? update_post_meta( $id, self::TARGET, $row['old'], $row['imported'] ) : delete_post_meta( $id, self::TARGET, $row['imported'] );
			if ( false === $changed ) { throw new RuntimeException( 'Restaurarea metadatelor nu poate fi confirmată.' ); }
			++$result['restored'];
		}
		return $result;
	}
}
