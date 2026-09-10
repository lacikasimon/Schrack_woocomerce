<?php
/** SSH-free, checkpointed admin workflow for duplicate attribute consolidation. */
defined( 'ABSPATH' ) || exit;

class Schrack_Attribute_Merge_Job {
	public const OPTION = 'schrack_wc_attribute_merge_job';
	public const HOOK = 'schrack_wc_attribute_merge_step';
	public const PAGE = 'schrack-sync-attributes';
	private const HISTORY = 'schrack_wc_attribute_merge_backups';
	private const GROUP = 'schrack-attribute-merge';
	private const LIMIT = 50;

	public function init(): void {
		add_action( self::HOOK, array( $this, 'work' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_schrack_attribute_merge', array( $this, 'handle_action' ) );
		add_action( 'admin_post_schrack_attribute_merge_download', array( $this, 'download' ) );
		add_action( 'wp_ajax_schrack_attribute_merge_tick', array( $this, 'ajax_tick' ) );
	}

	public static function status(): array {
		// Import workers can live for minutes; do not reuse a pre-merge option cache.
		wp_cache_delete( self::OPTION, 'options' );
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function blocks_imports(): bool {
		$state = self::status();
		return 'apply' === ( $state['mode'] ?? '' ) && in_array( $state['state'] ?? '', array( 'running', 'paused', 'error' ), true );
	}

	private static function lock_name(): string {
		global $wpdb;
		return 'schrack-attribute-merge-' . md5( DB_NAME . $wpdb->prefix );
	}

	private function locked( callable $callback ) {
		global $wpdb;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', self::lock_name() ) ) ) { return null; }
		try { return $callback(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) ); }
	}

	private function definitions(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id", ARRAY_A );
		$this->check_db();
		return $rows;
	}

	/** UI transitions are serialized with workers, so stale tabs cannot replace a job. */
	public function transition( string $operation, string $job_id = '' ): void {
		$result = $this->locked( function () use ( $operation, $job_id ): bool {
			$state = self::status();
			if ( 'preview' === $operation ) {
				if ( 'running' === ( $state['state'] ?? '' ) || ( self::blocks_imports() && ! empty( $state['mutation_started'] ) ) ) { throw new RuntimeException( 'Un proces este deja activ. Reia procesul existent.' ); }
				if ( ! empty( $state['backup_complete'] ) ) {
					$history = (array) get_option( self::HISTORY, array() );
					$history[ $state['id'] ] = array_intersect_key( $state, array_flip( array( 'id', 'backup', 'backup_bytes', 'backup_complete', 'started_at' ) ) );
					update_option( self::HISTORY, array_slice( $history, -10, null, true ), false );
				}
				$definitions = $this->definitions();
				$merger = new Schrack_Attribute_Merger( $definitions );
				$state = array(
					'id' => str_replace( '-', '', wp_generate_uuid4() ), 'mode' => 'preview', 'state' => 'running', 'phase' => 'validate',
					'definitions' => $definitions, 'groups' => array_values( $merger->groups() ),
					'cursor' => 0, 'index' => 0, 'scanned' => 0, 'products' => 0, 'conflicts' => 0, 'samples' => array(),
					'started_at' => time(), 'message' => '', 'mutation_started' => false,
				);
			} else {
				if ( ! $job_id || ! hash_equals( (string) ( $state['id'] ?? '' ), $job_id ) ) { throw new RuntimeException( 'Pagina este expirată. Reîncarcă pagina.' ); }
				if ( 'apply' === $operation ) {
					if ( 'ready' !== $state['state'] || ! $state['groups'] ) { throw new RuntimeException( 'Rulează mai întâi previzualizarea.' ); }
					if ( $this->definitions() !== $state['definitions'] ) { throw new RuntimeException( 'Atributele s-au schimbat. Rulează o previzualizare nouă.' ); }
					$this->assert_no_csv_transfer();
					$state = array_merge( $state, array( 'mode' => 'apply', 'state' => 'running', 'phase' => 'settle', 'cursor' => 0, 'index' => 0, 'scanned' => 0, 'products' => 0, 'conflicts' => 0, 'samples' => array(), 'not_before' => time() + 15 ) );
				} elseif ( 'pause' === $operation ) {
					if ( 'running' !== $state['state'] ) { throw new RuntimeException( 'Procesul nu rulează.' ); }
					$state['state'] = 'paused';
				} elseif ( 'resume' === $operation ) {
					if ( ! in_array( $state['state'], array( 'error', 'running', 'paused' ), true ) ) { throw new RuntimeException( 'Acest proces nu poate fi reluat.' ); }
					$state['state'] = 'running';
					if ( 'apply' === $state['mode'] ) { $this->assert_no_csv_transfer(); }
				} else { throw new RuntimeException( 'Acțiune necunoscută.' ); }
			}
			$state['message'] = '';
			$this->save( $state );
			if ( 'running' === $state['state'] ) { $this->schedule( $state['id'], 'settle' === $state['phase'] ? 5 : 0 ); }
			return true;
		} );
		if ( null === $result ) { throw new RuntimeException( 'Un lot este în curs. Încearcă din nou în câteva secunde.' ); }
	}

	private function assert_no_csv_transfer(): void {
		$sync_status = get_option( Schrack_Settings::STATUS_OPTION_NAME, array() );
		if ( in_array( $sync_status['category_import']['state'] ?? '', array( 'queued', 'running' ), true ) ) { throw new RuntimeException( 'Așteaptă finalizarea importului CSV de categorii.' ); }
		foreach ( array( Schrack_Product_Importer::STATUS_OPTION, Schrack_Product_Exporter::STATUS_OPTION ) as $option ) {
			$status = get_option( $option, array() );
			if ( in_array( $status['state'] ?? '', array( 'queued', 'running', 'finalizing' ), true ) ) { throw new RuntimeException( 'Așteaptă finalizarea importului/exportului CSV din pagina Export/import produse.' ); }
		}
	}

	/** Every tick is bounded; the persisted cursor is the sole source of progress. */
	public function work( string $job_id ): void {
		$this->locked( function () use ( $job_id ): void {
			$state = self::status();
			if ( ( $state['id'] ?? '' ) !== $job_id || 'running' !== ( $state['state'] ?? '' ) ) { return; }
			// A watchdog survives a PHP fatal/timeout before this batch can enqueue its successor.
			if ( ! wp_next_scheduled( self::HOOK, array( $job_id ) ) ) { wp_schedule_single_event( time() + 60, self::HOOK, array( $job_id ) ); }
			$deadline = microtime( true ) + 4;
			try {
				do {
					$merger = new Schrack_Attribute_Merger( $state['definitions'] );
					$this->step( $state, $merger );
					$this->save( $state );
				} while ( 'running' === $state['state'] && 'settle' !== $state['phase'] && microtime( true ) < $deadline );
			} catch ( Throwable $error ) {
				// Reload the durable checkpoint; any partially appended backup tail is retried.
				$state = self::status();
				$state['state'] = 'error';
				$state['message'] = $error->getMessage();
				$this->save( $state );
			}
			if ( 'running' === $state['state'] ) { $this->schedule( $job_id, 'settle' === $state['phase'] ? 5 : 0 ); }
		} );
	}

	private function step( array &$state, Schrack_Attribute_Merger $merger ): void {
		global $wpdb;
		$phase = $state['phase'];
		if ( ! empty( $state['mutation_started'] ) ) { $this->assert_backup( $state ); }
		if ( 'settle' === $phase ) {
			if ( time() < $state['not_before'] ) { return; }
			// Let catalog workers already inside a batch finish before taking the snapshot.
			foreach ( array( 'schrack-wc-sync', 'schrack-edoc' ) as $group ) {
				if ( function_exists( 'as_get_scheduled_actions' ) && as_get_scheduled_actions( array( 'group' => $group, 'status' => 'in-progress', 'per_page' => 1 ), 'ids' ) ) { return; }
			}
			$this->assert_no_csv_transfer();
			$state['phase'] = 'validate';
		} elseif ( 'validate' === $phase ) {
			if ( ! $state['groups'] ) { $state['state'] = 'ready'; return; }
			if ( $this->definitions() !== $state['definitions'] ) { throw new RuntimeException( 'Lista atributelor s-a schimbat. Este necesară o previzualizare nouă.' ); }
			$merger->validate_admin_environment();
			$state['phase'] = 'meta';
		} elseif ( in_array( $phase, array( 'meta', 'products', 'verify_meta' ), true ) ) {
			$this->meta_step( $state, $merger );
		} elseif ( in_array( $phase, array( 'relations', 'verify_relations' ), true ) ) {
			$this->relationship_step( $state, $merger );
		} elseif ( 'backup' === $phase ) {
			$this->backup_step( $state );
		} elseif ( 'terms' === $phase || 'delete_terms' === $phase ) {
			$this->term_step( $state, $merger );
		} elseif ( 'registry' === $phase ) {
			$merger->validate_admin_environment();
			$merger->admin_save_registry();
			$state['phase'] = 'delete_terms'; $state['index'] = 0;
		} elseif ( 'delete_definitions' === $phase ) {
			$deletions = array();
			foreach ( $state['groups'] as $group ) { $deletions = array_merge( $deletions, array_slice( $group, 1 ) ); }
			foreach ( array_slice( $deletions, $state['index'], 20 ) as $definition ) {
				// Idempotent if a worker died after deleting the row, before checkpointing.
				$result = $wpdb->delete( $wpdb->prefix . 'woocommerce_attribute_taxonomies', array( 'attribute_id' => $definition['attribute_id'] ), array( '%d' ) );
				$this->check_db();
				if ( false === $result ) { throw new RuntimeException( 'Ștergerea definiției a eșuat.' ); }
				++$state['index'];
			}
			delete_transient( 'wc_attribute_taxonomies' );
			WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
			if ( $state['index'] >= count( $deletions ) ) {
				wp_schedule_single_event( time(), 'woocommerce_flush_rewrite_rules' );
				$state['state'] = 'complete'; $state['phase'] = 'complete';
			}
		}
	}

	private function meta_step( array &$state, Schrack_Attribute_Merger $merger ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_product_attributes','_default_attributes') AND meta_id > %d ORDER BY meta_id LIMIT %d", $state['cursor'], self::LIMIT ), ARRAY_A );
		$this->check_db();
		foreach ( $rows as $row ) {
			$plan = $merger->admin_plan_row( $row );
			if ( 'products' === $state['phase'] ) {
				$merger->admin_apply_row( $row, $plan );
			} elseif ( $plan && $plan['decisions'] ) {
				if ( 'verify_meta' === $state['phase'] ) { throw new RuntimeException( 'Au rămas atribute nemigrate la produsul #' . $row['post_id'] ); }
				++$state['products'];
				foreach ( $plan['decisions'] as $decision ) {
					if ( $decision['conflict'] ) {
						++$state['conflicts'];
						if ( count( $state['samples'] ) < 100 ) { $state['samples'][] = array_merge( array( 'product_id' => (int) $row['post_id'] ), $decision ); }
					}
				}
			}
			$state['cursor'] = (int) $row['meta_id'];
			++$state['scanned'];
		}
		if ( count( $rows ) < self::LIMIT ) {
			$state['phase'] = array( 'meta' => 'relations', 'products' => 'verify_meta', 'verify_meta' => 'verify_relations' )[ $state['phase'] ];
			$state['cursor'] = 0; $state['index'] = 0;
		}
	}

	private function relationship_step( array &$state, Schrack_Attribute_Merger $merger ): void {
		global $wpdb;
		$sources = array_keys( $merger->admin_sources() );
		if ( $state['index'] >= count( $sources ) ) {
			if ( 'verify_relations' === $state['phase'] ) { $state['phase'] = 'registry'; }
			elseif ( 'preview' === $state['mode'] ) { $state['state'] = 'ready'; }
			else { $state['phase'] = 'backup'; }
			$state['cursor'] = 0; $state['index'] = 0;
			return;
		}
		$source = $sources[ $state['index'] ];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT r.object_id FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE t.taxonomy=%s AND r.object_id > %d ORDER BY r.object_id LIMIT %d", $source, $state['cursor'], self::LIMIT ) );
		$this->check_db();
		foreach ( $ids as $id ) {
			$matched = false;
			foreach ( (array) get_post_meta( $id, '_product_attributes', true ) as $attribute ) {
				$matched = $matched || ( is_array( $attribute ) && ! empty( $attribute['is_taxonomy'] ) && $source === ( $attribute['name'] ?? '' ) );
			}
			if ( 'verify_relations' === $state['phase'] || ! $matched || 'product' !== get_post_type( $id ) ) { throw new RuntimeException( "Referință nemigrată sau orfană: {$source}, produs #{$id}." ); }
			$state['cursor'] = (int) $id;
		}
		if ( count( $ids ) < self::LIMIT ) { ++$state['index']; $state['cursor'] = 0; }
	}

	private function term_step( array &$state, Schrack_Attribute_Merger $merger ): void {
		global $wpdb;
		$this->assert_backup( $state );
		if ( empty( $state['mutation_started'] ) ) {
			if ( $this->definitions() !== $state['definitions'] ) { throw new RuntimeException( 'Atributele s-au schimbat în timpul pregătirii.' ); }
			$state['mutation_started'] = true;
			$this->save( $state ); // Durable before the first catalog write.
		}
		$sources = $merger->admin_sources();
		$names = array_keys( $sources );
		if ( $state['index'] >= count( $names ) ) {
			$state['phase'] = 'terms' === $state['phase'] ? 'products' : 'delete_definitions';
			$state['cursor'] = 0; $state['index'] = 0;
			return;
		}
		$source = $names[ $state['index'] ];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s AND term_id > %d ORDER BY term_id LIMIT 20", $source, 'terms' === $state['phase'] ? $state['cursor'] : 0 ) );
		$this->check_db();
		foreach ( $ids as $id ) {
			if ( 'terms' === $state['phase'] ) {
				$term = get_term( (int) $id, $source );
				if ( ! $term || is_wp_error( $term ) ) { throw new RuntimeException( 'Valoare de atribut indisponibilă.' ); }
				$merger->admin_copy_term( $sources[ $source ], $term );
				$state['cursor'] = (int) $id;
			} else {
				$result = wp_delete_term( (int) $id, $source );
				if ( is_wp_error( $result ) || false === $result ) { throw new RuntimeException( 'Ștergerea valorii vechi a eșuat.' ); }
			}
		}
		if ( count( $ids ) < 20 ) { ++$state['index']; $state['cursor'] = 0; }
	}

	/** SQL snapshot of attribute data and the shared taxonomy tables; no shell/mysql client. */
	private function backup_tables(): array {
		global $wpdb;
		$tables = array(
			array( $wpdb->prefix . 'woocommerce_attribute_taxonomies', '1=1', array( 'attribute_id' ) ),
			array( $wpdb->terms, '1=1', array( 'term_id' ) ),
			array( $wpdb->term_taxonomy, '1=1', array( 'term_taxonomy_id' ) ),
			array( $wpdb->term_relationships, '1=1', array( 'object_id', 'term_taxonomy_id' ) ),
			array( $wpdb->termmeta, '1=1', array( 'meta_id' ) ),
			array( $wpdb->postmeta, "meta_key='_product_attributes'", array( 'meta_id' ) ),
			array( $wpdb->options, "option_name IN ('schrack_wc_sync_merged_attributes','schrack_wc_sync_dynamic_attributes')", array( 'option_id' ) ),
		);
		$table = $wpdb->prefix . 'wc_product_attributes_lookup';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$this->check_db();
		if ( $exists ) { $tables[] = array( $table, '1=1', array( 'product_or_parent_id', 'term_id', 'product_id', 'taxonomy' ) ); }
		return $tables;
	}

	private function backup_step( array &$state ): void {
		global $wpdb;
		if ( empty( $state['backup'] ) ) {
			$state['backup'] = $this->private_file( $state['id'] );
			$state['backup_bytes'] = 0; $state['backup_key'] = array();
			$this->save( $state );
		}
		$this->validate_private_file( $state['backup'], $state['id'] );
		$file = fopen( $state['backup'], 'c+b' );
		if ( ! $file ) { throw new RuntimeException( 'Nu se poate deschide fișierul de siguranță.' ); }
		try {
			if ( fstat( $file )['size'] < $state['backup_bytes'] ) { throw new RuntimeException( 'Copia de siguranță este incompletă pe disc. Rulează o previzualizare nouă.' ); }
			if ( ! ftruncate( $file, $state['backup_bytes'] ) || 0 !== fseek( $file, $state['backup_bytes'] ) ) { throw new RuntimeException( 'Nu se poate relua copia de siguranță.' ); }
			if ( 0 === $state['backup_bytes'] ) {
				$this->write( $file, "-- Schrack attribute migration backup. Includes shared category/tag taxonomy tables.\n-- Restore in phpMyAdmin with product/taxonomy editing and imports paused.\n-- Does not replace orders, users, prices or stock.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n" );
			}
			$tables = $this->backup_tables();
			if ( $state['index'] >= count( $tables ) ) {
				// Restoring invalidates the old worker ID so it cannot resume on restored data.
				$this->write( $file, 'DELETE FROM ' . $this->identifier( $wpdb->options ) . " WHERE option_name='" . self::OPTION . "';\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n" );
				$state['backup_complete'] = true; $state['phase'] = 'terms'; $state['index'] = 0; $state['cursor'] = 0;
			} else {
				list( $table, $scope, $keys ) = $tables[ $state['index'] ];
				$where = $scope;
				if ( $state['backup_key'] ) {
					$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
					$where .= $wpdb->prepare( ' AND (' . implode( ',', array_map( array( $this, 'identifier' ), $keys ) ) . ") > ({$placeholders})", ...$state['backup_key'] );
				} else {
					$this->write( $file, 'DELETE FROM ' . $this->identifier( $table ) . " WHERE {$scope};\n" );
				}
				$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->identifier( $table ) . " WHERE {$where} ORDER BY " . implode( ',', array_map( array( $this, 'identifier' ), $keys ) ) . ' LIMIT ' . self::LIMIT, ARRAY_A );
				$this->check_db();
				foreach ( $rows as $row ) {
					$values = array_map( static fn ( $value ): string => null === $value ? 'NULL' : "UNHEX('" . bin2hex( (string) $value ) . "')", array_values( $row ) );
					$this->write( $file, 'INSERT INTO ' . $this->identifier( $table ) . ' (' . implode( ',', array_map( array( $this, 'identifier' ), array_keys( $row ) ) ) . ') VALUES (' . implode( ',', $values ) . ");\n" );
					$state['backup_key'] = array_map( static fn ( string $key ) => $row[ $key ], $keys );
				}
				if ( count( $rows ) < self::LIMIT ) { ++$state['index']; $state['backup_key'] = array(); }
			}
			if ( ! fflush( $file ) ) { throw new RuntimeException( 'Scrierea copiei de siguranță a eșuat.' ); }
			$state['backup_bytes'] = ftell( $file );
		} finally { fclose( $file ); }
	}

	private function identifier( string $name ): string { return '`' . str_replace( '`', '``', $name ) . '`'; }
	private function write( $file, string $text ): void {
		$length = strlen( $text ); $offset = 0;
		while ( $offset < $length ) {
			$written = fwrite( $file, substr( $text, $offset ) );
			if ( ! $written ) { throw new RuntimeException( 'Spațiu insuficient sau eroare la scrierea copiei de siguranță.' ); }
			$offset += $written;
		}
	}

	private function outside_web_root( string $directory ): bool {
		foreach ( array( realpath( ABSPATH ), realpath( $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH ) ) as $root ) {
			if ( $root && ( $directory === $root || str_starts_with( $directory, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) ) { return false; }
		}
		return true;
	}

	private function private_file( string $id ): string {
		foreach ( array( dirname( rtrim( ABSPATH, '/\\' ) ), sys_get_temp_dir() ) as $base ) {
			$base = realpath( $base );
			if ( ! $base || ! $this->outside_web_root( $base ) || ! is_writable( $base ) ) { continue; }
			$directory = $base . '/schrack-attribute-backup-' . $id;
			if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700 ) ) { continue; }
			if ( is_link( $directory ) || ! chmod( $directory, 0700 ) ) { continue; }
			$file = $directory . '/' . $id . '.sql';
			$handle = fopen( $file, 'x' );
			if ( ! $handle ) { continue; }
			fclose( $handle );
			if ( ! chmod( $file, 0600 ) ) { throw new RuntimeException( 'Protecția copiei de siguranță a eșuat.' ); }
			return $file;
		}
		throw new RuntimeException( 'Nu există un director privat inscriptibil în afara webroot. Cere găzduirii acces PHP la directorul temporar.' );
	}

	private function validate_private_file( string $path, string $id ): void {
		$real = realpath( $path );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) || ! $real || is_link( $path ) || basename( $real ) !== $id . '.sql' || basename( dirname( $real ) ) !== 'schrack-attribute-backup-' . $id || ! $this->outside_web_root( dirname( $real ) ) ) {
			throw new RuntimeException( 'Fișier de siguranță indisponibil sau invalid.' );
		}
	}

	private function assert_backup( array $state ): void {
		if ( empty( $state['backup_complete'] ) ) { throw new RuntimeException( 'Copia de siguranță nu este completă.' ); }
		$this->validate_private_file( $state['backup'], $state['id'] );
		clearstatcache( true, $state['backup'] );
		if ( filesize( $state['backup'] ) !== $state['backup_bytes'] ) { throw new RuntimeException( 'Dimensiunea copiei de siguranță nu corespunde.' ); }
	}

	private function save( array $state ): void {
		$state['updated_at'] = time();
		update_option( self::OPTION, $state, false );
		$this->check_db();
		if ( self::status() !== $state ) { throw new RuntimeException( 'Progresul nu a putut fi salvat.' ); }
	}

	private function check_db(): void {
		global $wpdb;
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Eroare în baza de date: ' . $wpdb->last_error ); }
	}

	private function schedule( string $id, int $delay = 0 ): void {
		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_enqueue_async_action' ) ) {
			$pending = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'group' => self::GROUP, 'status' => 'pending', 'args' => array( $id ), 'per_page' => 1 ), 'ids' );
			if ( ! $pending ) {
				if ( $delay && function_exists( 'as_schedule_single_action' ) ) { as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), self::GROUP ); }
				else { as_enqueue_async_action( self::HOOK, array( $id ), self::GROUP ); }
			}
		}
		if ( ! wp_next_scheduled( self::HOOK, array( $id ) ) ) { wp_schedule_single_event( time() + 10, self::HOOK, array( $id ) ); }
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', 'Unificare atribute', 'Unificare atribute', 'manage_woocommerce', self::PAGE, array( $this, 'render' ) );
	}

	private function authorize(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Nu ai permisiunea de a administra atributele.', '', array( 'response' => 403 ) ); }
	}

	public function handle_action(): void {
		$this->authorize(); check_admin_referer( 'schrack_attribute_merge' );
		try { $this->transition( sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) ), sanitize_key( wp_unslash( $_POST['job'] ?? '' ) ) ); }
		catch ( Throwable $error ) { set_transient( 'schrack_attribute_notice_' . get_current_user_id(), $error->getMessage(), 60 ); }
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) ); exit;
	}

	public function ajax_tick(): void {
		$this->authorize(); check_ajax_referer( 'schrack_attribute_merge', 'nonce' );
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		$job_id = sanitize_key( wp_unslash( $_POST['job'] ?? '' ) );
		try {
			if ( $operation ) { $this->transition( $operation, $job_id ); }
			elseif ( '0' !== ( $_POST['advance'] ?? '' ) ) { $this->work( $job_id ); }
		} catch ( Throwable $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 409 );
		}
		$state = self::status(); $view = $this->view( $state );
		if ( $view['view_key'] !== (string) ( $_POST['view_key'] ?? '' ) ) {
			// Update controls/notices/tables only at state transitions, without navigation.
			$notice = '';
			ob_start();
			include SCHRACK_WC_SYNC_PATH . 'templates/admin-attribute-merge.php';
			$view['html'] = ob_get_clean();
		}
		wp_send_json_success( $view );
	}

	public function download(): void {
		$this->authorize();
		$id = sanitize_key( wp_unslash( $_GET['job'] ?? '' ) );
		check_admin_referer( 'schrack_attribute_merge_download_' . $id );
		$state = self::status();
		if ( $id !== ( $state['id'] ?? '' ) ) { $state = get_option( self::HISTORY, array() )[ $id ] ?? array(); }
		try { $this->assert_backup( $state ); } catch ( Throwable $error ) { wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 404 ) ); }
		nocache_headers(); header( 'Content-Type: application/sql' ); header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="schrack-attributes-' . $id . '.sql"' );
		header( 'Content-Length: ' . $state['backup_bytes'] );
		$file = fopen( $state['backup'], 'rb' );
		if ( ! $file ) { wp_die( 'Fișier indisponibil.' ); }
		while ( ! feof( $file ) ) { echo fread( $file, 1024 * 1024 ); }
		fclose( $file ); exit;
	}

	public function view( array $state ): array {
		$labels = array( 'settle' => 'Se așteaptă oprirea importurilor active', 'validate' => 'Verificarea atributelor', 'meta' => 'Analiza produselor', 'relations' => 'Verificarea legăturilor', 'backup' => 'Crearea copiei de siguranță', 'terms' => 'Unificarea listelor de valori', 'products' => 'Actualizarea produselor', 'verify_meta' => 'Verificarea produselor actualizate', 'verify_relations' => 'Verificarea legăturilor rămase', 'registry' => 'Actualizarea filtrelor și importurilor', 'delete_terms' => 'Eliminarea valorilor din atributele vechi', 'delete_definitions' => 'Eliminarea atributelor duplicate', 'complete' => 'Unificare finalizată' );
		$view = array( 'id' => $state['id'] ?? '', 'state' => $state['state'] ?? 'idle', 'mode' => $state['mode'] ?? 'preview', 'phase' => $labels[ $state['phase'] ?? '' ] ?? '', 'scanned' => (int) ( $state['scanned'] ?? 0 ), 'products' => (int) ( $state['products'] ?? 0 ), 'conflicts' => (int) ( $state['conflicts'] ?? 0 ), 'groups' => count( $state['groups'] ?? array() ), 'message' => $state['message'] ?? '', 'backup_complete' => ! empty( $state['backup_complete'] ), 'updated_at' => (int) ( $state['updated_at'] ?? 0 ) );
		$view['view_key'] = implode( ':', array( $view['id'], $view['mode'], $view['state'], (int) $view['backup_complete'], $view['message'] ) );
		$view['progress'] = $view['id'] ? 'Înregistrări verificate: ' . number_format_i18n( $view['scanned'] ) . '.' : '';
		if ( 'backup' === ( $state['phase'] ?? '' ) ) {
			$sections = array( 'definițiile atributelor', 'listele de valori', 'atributele și categoriile', 'legăturile produselor', 'detaliile valorilor', 'atributele produselor', 'configurația filtrelor', 'indexul filtrelor' );
			$section = $sections[ $state['index'] ?? 0 ] ?? 'finalizarea copiei';
			$view['progress'] = 'Copie salvată: ' . size_format( (int) ( $state['backup_bytes'] ?? 0 ), 2 ) . '. Se salvează: ' . $section . '. Atributele nu au fost încă modificate.';
		}
		if ( $view['updated_at'] ) { $view['progress'] .= ' Ultimul progres salvat: ' . wp_date( 'H:i:s', $view['updated_at'] ) . '.'; }
		return $view;
	}

	public function render(): void {
		$this->authorize();
		$state = self::status(); $view = $this->view( $state );
		$notice = get_transient( 'schrack_attribute_notice_' . get_current_user_id() );
		delete_transient( 'schrack_attribute_notice_' . get_current_user_id() );
		wp_enqueue_script( 'schrack-attribute-merge', SCHRACK_WC_SYNC_URL . 'assets/attribute-merge.js', array(), SCHRACK_WC_SYNC_VERSION, true );
		wp_localize_script( 'schrack-attribute-merge', 'schrackAttributeMerge', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'schrack_attribute_merge' ), 'job' => $view['id'], 'state' => $view['state'], 'viewKey' => $view['view_key'] ) );
		include SCHRACK_WC_SYNC_PATH . 'templates/admin-attribute-merge.php';
	}
}
