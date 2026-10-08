<?php
/** Denormalized search documents. Partial builds never replace the live search. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_Search_Index {
	public const STATE = 'schrack_search_index_state';
	public const TICK = 'schrack_search_index_tick';
	private const KEYS = array( '_schrack_item_number' => 'schrack_item', '_schrack_ean' => 'schrack_ean', '_telesystem_item_number' => 'telesystem_item', '_telesystem_ean' => 'telesystem_ean', '_edoc_item_number' => 'edoc_item', '_edoc_ean' => 'edoc_ean' );
	private const FIELDS = array( 'title', 'excerpt', 'content', 'sku', 'schrack_item', 'schrack_ean', 'telesystem_item', 'telesystem_ean', 'edoc_item', 'edoc_ean' );

	public function init(): void {
		add_action( 'admin_init', array( $this, 'ensure_schedule' ) );
		add_action( self::TICK, array( $this, 'tick' ) );
		add_action( 'save_post_product', array( $this, 'dirty' ), 100, 1 );
		add_action( 'before_delete_post', array( $this, 'deleted' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'meta_changed' ), 100, 3 );
		}
		add_filter( 'posts_join', array( $this, 'archive_join' ), 20, 2 );
		add_filter( 'posts_where', array( $this, 'archive_where' ), 20, 2 );
	}

	public function ensure_schedule(): void {
		$state = get_option( self::STATE, array() );
		if ( 'running' === ( $state['status'] ?? '' ) ) { $this->schedule(); }
	}
	public static function clear_schedule(): void { wp_clear_scheduled_hook( self::TICK ); }

	public static function table(): string { global $wpdb; return $wpdb->prefix . 'schrack_search_documents'; }
	private static function dirty_table(): string { return self::table() . '_dirty'; }

	public static function ready(): bool {
		$state = get_option( self::STATE, array() );
		if ( empty( $state['ready'] ) || ! apply_filters( 'schrack_wc_sync_search_index', true ) ) { return false; }
		global $wpdb;
		$dirty = self::dirty_table();
		$pending = $wpdb->get_var( "SELECT product_id FROM {$dirty} LIMIT 1" );
		return null === $pending && ! $wpdb->last_error;
	}

	public static function join( string $alias = 'schrack_search_doc' ): string {
		global $wpdb;
		return ' INNER JOIN ' . self::table() . " AS {$alias} ON ({$wpdb->posts}.ID = {$alias}.product_id)";
	}

	/** Freeze one search plan per query: WordPress calls posts_where before posts_join. */
	public static function use_for_query( WP_Query $query ): bool {
		$indexed = $query->get( 'schrack_use_search_index' );
		if ( ! is_bool( $indexed ) ) {
			$indexed = self::ready();
			$query->set( 'schrack_use_search_index', $indexed );
		}
		return $indexed;
	}

	/** Fallback searches retain every field without multiplying rows through six meta joins. */
	public static function native_predicate( string $search ): string {
		global $wpdb;
		$lookup = $wpdb->wc_product_meta_lookup ?? $wpdb->prefix . 'wc_product_meta_lookup';
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$keys = "'" . implode( "','", array_keys( self::KEYS ) ) . "'";
		return $wpdb->prepare(
			"({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s
			OR EXISTS (SELECT 1 FROM {$lookup} AS schrack_search_sku WHERE schrack_search_sku.product_id = {$wpdb->posts}.ID AND schrack_search_sku.sku LIKE %s)
			OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS schrack_search_meta WHERE schrack_search_meta.post_id = {$wpdb->posts}.ID AND schrack_search_meta.meta_key IN ({$keys}) AND schrack_search_meta.meta_value LIKE %s))",
			array_fill( 0, 5, $like )
		);
	}

	/** Separate fields retain literal substring, punctuation, short codes and accents. */
	public static function predicate( string $search, string $alias = 'schrack_search_doc' ): string {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$clauses = array_map( static fn( $field ) => "{$alias}.{$field} LIKE %s", self::FIELDS );
		return $wpdb->prepare( '(' . implode( ' OR ', $clauses ) . ')', array_fill( 0, count( self::FIELDS ), $like ) );
	}

	/** Category names also identify products whose titles contain only a model code. */
	public static function query_predicate( string $search, WP_Query $query ): string {
		global $wpdb;
		$product = self::use_for_query( $query ) ? self::predicate( $search ) : self::native_predicate( $search );
		// Read current assignments/names so category edits require no index rebuild.
		// EXISTS preserves one result per product even when several categories match.
		$category = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$wpdb->term_relationships} AS schrack_search_category_rel
			INNER JOIN {$wpdb->term_taxonomy} AS schrack_search_category_tax ON (schrack_search_category_rel.term_taxonomy_id = schrack_search_category_tax.term_taxonomy_id)
			INNER JOIN {$wpdb->terms} AS schrack_search_category_term ON (schrack_search_category_tax.term_id = schrack_search_category_term.term_id)
			WHERE schrack_search_category_rel.object_id = {$wpdb->posts}.ID
			AND schrack_search_category_tax.taxonomy = 'product_cat' AND schrack_search_category_term.name LIKE %s)",
			'%' . $wpdb->esc_like( $search ) . '%'
		);
		return '(' . $product . ' OR ' . $category . ')';
	}

	public function archive_join( string $join, WP_Query $query ): string {
		return '' !== (string) $query->get( 'schrack_archive_index_search' ) && self::use_for_query( $query ) ? $join . self::join() : $join;
	}
	public function archive_where( string $where, WP_Query $query ): string {
		$search = (string) $query->get( 'schrack_archive_index_search' );
		if ( '' === $search ) { return $where; }
		return $where . ' AND ' . self::query_predicate( $search, $query );
	}

	public function start(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$collate = $wpdb->get_charset_collate();
		$columns = implode( ",\n", array_map( static fn( $field ) => "{$field} longtext NOT NULL", self::FIELDS ) );
		dbDelta( "CREATE TABLE {$table} (
product_id bigint(20) unsigned NOT NULL,
{$columns},
PRIMARY KEY  (product_id)
) {$collate};" );
		$dirty = self::dirty_table();
		dbDelta( "CREATE TABLE {$dirty} (
product_id bigint(20) unsigned NOT NULL,
revision bigint(20) unsigned NOT NULL DEFAULT 1,
PRIMARY KEY  (product_id)
) {$collate};" );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Crearea indexului de căutare a eșuat.' ); }
		$state = get_option( self::STATE, array() );
		if ( empty( $state ) || ! empty( $state['needs_rebuild'] ) ) {
			$upper = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'product'" );
			update_option( self::STATE, array( 'cursor' => 0, 'upper' => $upper, 'processed' => 0, 'ready' => false, 'status' => 'running' ), false );
		} elseif ( 'error' === ( $state['status'] ?? '' ) ) {
			$state['status'] = 'running';
			$state['ready'] = false;
			unset( $state['error'] );
			update_option( self::STATE, $state, false );
		}
		$this->schedule();
	}

	public function meta_changed( $meta_id, $id, string $key ): void {
		if ( '_sku' === $key || isset( self::KEYS[ $key ] ) ) { $this->dirty( (int) $id ); }
	}
	public function deleted( int $id, $post ): void {
		if ( 'product' === ( $post->post_type ?? '' ) ) { $this->dirty( $id ); }
	}
	public function dirty( int $id ): void {
		$state = get_option( self::STATE, array() );
		if ( ! $state || 'product' !== get_post_type( $id ) ) { return; }
		global $wpdb;
		// Revision fencing prevents a concurrent import from losing its dirty mark.
		$dirty = self::dirty_table();
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$dirty} (product_id,revision) VALUES (%d,1) ON DUPLICATE KEY UPDATE revision=revision+1", $id ) );
		// The dirty row itself fences readers; never overwrite a worker's cursor.
		if ( false === $result ) { update_option( self::STATE, array_merge( $state, array( 'ready' => false, 'status' => 'error', 'needs_rebuild' => true ) ), false ); }
		$this->schedule();
	}
	private function schedule(): void {
		if ( ! wp_next_scheduled( self::TICK ) ) { wp_schedule_single_event( time() + 15, self::TICK ); }
	}

	public function tick(): void {
		global $wpdb;
		$lock = 'schrack_search_' . md5( $wpdb->options );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { $this->schedule(); return; }
		try {
			$state = get_option( self::STATE, array() );
			if ( ! $state ) { return; }
			if ( ! empty( $state['needs_rebuild'] ) ) { throw new RuntimeException( 'Rebuild required.' ); }
			$dirty = self::dirty_table();
			$rows = $wpdb->get_results( "SELECT product_id,revision FROM {$dirty} ORDER BY product_id LIMIT 100", ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Indexul nu poate fi citit.' ); }
			$started = microtime( true );
			foreach ( $rows as $row ) {
				$this->write_document( (int) $row['product_id'] );
				if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM {$dirty} WHERE product_id=%d AND revision=%d", $row['product_id'], $row['revision'] ) ) ) { throw new RuntimeException( 'Dirty checkpoint failed.' ); }
				if ( microtime( true ) - $started > 5 ) { break; }
			}
			if ( microtime( true ) - $started < 5 && $state['cursor'] < $state['upper'] ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND ID>%d AND ID<=%d ORDER BY ID LIMIT 500", $state['cursor'], $state['upper'] ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Catalogul nu poate fi citit.' ); }
				if ( ! $ids ) { $state['cursor'] = $state['upper']; }
				if ( $ids ) {
					if ( function_exists( '_prime_post_caches' ) ) { _prime_post_caches( $ids, false, true ); }
					else { update_meta_cache( 'post', $ids ); }
				}
				foreach ( $ids as $id ) {
					$this->write_document( (int) $id );
					$state['cursor'] = (int) $id;
					++$state['processed'];
					if ( microtime( true ) - $started > 5 ) { break; }
				}
			}
			// Hold ready=false until all writes are caught up. No partial catalogue hits.
			$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dirty}" );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Indexul nu poate fi verificat.' ); }
			$state['ready'] = $state['cursor'] >= $state['upper'] && 0 === $remaining;
			$state['status'] = $state['ready'] ? 'complete' : 'running';
			unset( $state['error'] );
			update_option( self::STATE, $state, false );
			if ( ! $state['ready'] ) { $this->schedule(); }
		} catch ( Throwable $e ) {
			$state['ready'] = false; $state['status'] = 'error'; $state['error'] = 'Actualizarea indexului a eșuat; căutarea standard este activă.';
			update_option( self::STATE, $state, false );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	private function write_document( int $id ): void {
		global $wpdb;
		$post = get_post( $id );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Document read failed.' ); }
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			if ( false === $wpdb->delete( self::table(), array( 'product_id' => $id ), array( '%d' ) ) ) { throw new RuntimeException( 'Index write failed.' ); }
			return;
		}
		$data = array( 'product_id' => $id, 'title' => $post->post_title, 'excerpt' => $post->post_excerpt, 'content' => $post->post_content, 'sku' => (string) get_post_meta( $id, '_sku', true ) );
		foreach ( self::KEYS as $key => $field ) {
			$data[ $field ] = implode( "\x1e", array_map( 'strval', get_post_meta( $id, $key, false ) ) );
		}
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Metadata read failed.' ); }
		if ( false === $wpdb->replace( self::table(), $data, array_merge( array( '%d' ), array_fill( 0, count( self::FIELDS ), '%s' ) ) ) ) { throw new RuntimeException( 'Index write failed.' ); }
	}

	public static function fuzzy_ids( array $prefixes, int $limit ): array {
		global $wpdb;
		$parts = array_map( static fn( $prefix ) => self::predicate( $prefix ), $prefixes );
		if ( ! $parts ) { return array(); }
		$sql = "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts}" . self::join() . " WHERE {$wpdb->posts}.post_type='product' AND {$wpdb->posts}.post_status='publish' AND (" . implode( ' OR ', $parts ) . ") ORDER BY {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_title ASC LIMIT " . max( 1, $limit );
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}
}
