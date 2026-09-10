<?php
/**
 * One-off, dry-run-first consolidation of global attributes with equal labels.
 *
 * @package SchrackWooCommerceSync
 */

defined( 'ABSPATH' ) || ( defined( 'WP_CLI' ) && WP_CLI ) || exit;

class Schrack_Attribute_Merger {

	public const REGISTRY_OPTION = 'schrack_wc_sync_merged_attributes';
	private array $groups = array();
	private array $taxonomy_groups = array();
	private int $batch_size;
	private $report = null;

	/** Keep punctuation, units and accents meaningful; normalize only case/space. */
	public static function label_key( string $label ): string {
		$label = html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$label = trim( preg_replace( '/[\s\x{00a0}]+/u', ' ', $label ) ?? $label );
		return mb_strtolower( $label, 'UTF-8' );
	}

	/** Term spelling may differ only in case; accents, spacing and punctuation remain meaningful. */
	private static function value_key( string $value ): string {
		return mb_strtolower( $value, 'UTF-8' );
	}

	private static function comparable_values( array $values ): array {
		$values = array_values( array_unique( array_map( array( self::class, 'value_key' ), $values ) ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	/** Definitions come from the DB directly: WooCommerce's cache hides duplicate slugs. */
	public function __construct( array $definitions = array(), int $batch_size = 200 ) {
		$this->batch_size = max( 1, min( 1000, $batch_size ) );
		$labels_by_taxonomy = array();
		foreach ( $definitions as $definition ) {
			$definition = (array) $definition;
			$key = self::label_key( (string) $definition['attribute_label'] );
			if ( '' === $key ) {
				continue;
			}
			$taxonomy = wc_attribute_taxonomy_name( $definition['attribute_name'] );
			if ( isset( $labels_by_taxonomy[ $taxonomy ] ) && $labels_by_taxonomy[ $taxonomy ] !== $key ) {
				throw new RuntimeException( "Conflicting labels for the same taxonomy: {$taxonomy}. Repair this definition first." );
			}
			$labels_by_taxonomy[ $taxonomy ] = $key;
			$this->groups[ $key ][] = $definition;
		}
		foreach ( $this->groups as $key => &$group ) {
			if ( count( $group ) < 2 ) {
				unset( $this->groups[ $key ] );
				continue;
			}
			// Same ordering as discover_separate_attribute_columns() in the exporter.
			usort( $group, static fn ( array $a, array $b ): int =>
				strnatcasecmp( $a['attribute_label'], $b['attribute_label'] )
				?: strnatcasecmp( $a['attribute_name'], $b['attribute_name'] )
				?: (int) $a['attribute_id'] <=> (int) $b['attribute_id']
			);
			foreach ( $group as $definition ) {
				$this->taxonomy_groups[ wc_attribute_taxonomy_name( $definition['attribute_name'] ) ] = $key;
			}
		}
		unset( $group );
	}

	public function groups(): array {
		return $this->groups;
	}

	/** Small, reusable operations for the resumable admin worker. No WP-CLI calls. */
	public function validate_admin_environment(): void {
		global $wpdb;
		foreach ( $this->groups as $group ) {
			foreach ( $group as $definition ) {
				$taxonomy = wc_attribute_taxonomy_name( $definition['attribute_name'] );
				if ( ! taxonomy_exists( $taxonomy ) || $definition['attribute_type'] !== $group[0]['attribute_type'] ) {
					throw new RuntimeException( "Atribut incompatibil sau neînregistrat: {$taxonomy}." );
				}
				$names = array( 'attribute_' . $taxonomy, 'attribute_' . sanitize_title( $definition['attribute_label'] ) );
				$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) LIMIT 1", ...$names ) );
				self::check_db();
				if ( $id ) {
					throw new RuntimeException( "Atribut folosit de variații: {$taxonomy}, produs #{$id}." );
				}
			}
		}
		foreach ( $this->source_taxonomies() as $source => $target ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent <> 0 LIMIT 1", $source ) );
			self::check_db();
			if ( $id ) { throw new RuntimeException( "Valori ierarhice: {$source}, #{$id}." ); }
		}
		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta ) as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			self::check_db();
			if ( 'innodb' !== strtolower( (string) $engine ) ) { throw new RuntimeException( "Tabelul {$table} trebuie să folosească InnoDB." ); }
		}
	}

	public function admin_plan_row( array $row ): array {
		$raw = maybe_unserialize( $row['meta_value'] );
		if ( '_default_attributes' === $row['meta_key'] ) {
			foreach ( is_array( $raw ) ? array_keys( $raw ) : array() as $name ) {
				if ( isset( $this->taxonomy_groups[ $name ] ) || isset( $this->groups[ self::label_key( $name ) ] ) ) {
					throw new RuntimeException( 'Atribut implicit de variație la produsul #' . $row['post_id'] );
				}
			}
			return array();
		}
		if ( '' === $row['meta_value'] ) { return array(); }
		if ( ! is_array( $raw ) ) { throw new RuntimeException( 'Atribute invalide la produsul #' . $row['post_id'] ); }
		$id = (int) $row['post_id'];
		$plan = $this->plan_product( $raw, static function ( string $taxonomy ) use ( $id ): array {
			$values = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names', 'orderby' => 'term_id' ) );
			self::check_result( $values );
			return $values;
		} );
		if ( $plan['decisions'] && ( 'product' !== get_post_type( $id ) || count( get_post_meta( $id, '_product_attributes', false ) ) !== 1 ) ) {
			throw new RuntimeException( "Metadate duplicate sau obiect invalid: #{$id}." );
		}
		return $plan;
	}

	public function admin_apply_row( array $row, array $plan ): void {
		if ( $plan && $plan['decisions'] ) {
			$this->apply_product( (int) $row['post_id'], maybe_unserialize( $row['meta_value'] ), $plan );
		} elseif ( '_product_attributes' === $row['meta_key'] ) {
			foreach ( (array) maybe_unserialize( $row['meta_value'] ) as $attribute ) {
				if ( is_array( $attribute ) && isset( $this->taxonomy_groups[ $attribute['name'] ?? '' ] ) ) {
					$this->refresh_lookup( (int) $row['post_id'] );
					break;
				}
			}
		}
	}

	public function admin_sources(): array { return $this->source_taxonomies(); }
	public function admin_copy_term( string $target, $term ): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); self::check_db();
		try {
			$this->ensure_term( $target, $term->name, $term );
			$wpdb->query( 'COMMIT' ); self::check_db();
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			// A retry must not see term-query/metadata cache entries from a rolled-back copy.
			wp_cache_flush();
			throw $error;
		}
	}
	public function admin_save_registry(): void { $this->save_registry(); }

	/**
	 * Pure planner. The callback supplies value names for one global attribute.
	 * "First" means the first populated export column, not the first term in a list.
	 * Existing multi-value lists, zero values and unrelated metadata stay intact.
	 */
	public function plan_product( array $attributes, callable $read_values ): array {
		$candidates = array();
		foreach ( $attributes as $key => $attribute ) {
			if ( ! is_array( $attribute ) || ! isset( $attribute['name'] ) ) {
				throw new RuntimeException( 'Malformed _product_attributes metadata.' );
			}
			$name = (string) $attribute['name'];
			$group_key = ! empty( $attribute['is_taxonomy'] )
				? ( $this->taxonomy_groups[ $name ] ?? '' ) : self::label_key( $name );
			if ( ! isset( $this->groups[ $group_key ] ) ) {
				continue;
			}
			if ( ! empty( $attribute['is_variation'] ) ) {
				throw new RuntimeException( "Variation attribute requires a separate migration: {$name}." );
			}
			$values = ! empty( $attribute['is_taxonomy'] ) ? $read_values( $name ) : wc_get_text_attributes( (string) ( $attribute['value'] ?? '' ) );
			$values = array_values( array_filter( array_map( 'strval', $values ), static fn ( string $value ): bool => '' !== trim( $value ) ) );
			$label = $name;
			foreach ( $this->groups[ $group_key ] as $definition ) {
				if ( $name === wc_attribute_taxonomy_name( $definition['attribute_name'] ) ) {
					$label = $definition['attribute_label'];
					break;
				}
			}
			$candidates[ $group_key ][] = array( 'key' => $key, 'attribute' => $attribute, 'values' => $values, 'label' => $label );
		}
		$result = $attributes;
		$assign = $remove = $decisions = array();
		foreach ( $candidates as $group_key => $entries ) {
			usort( $entries, static fn ( array $a, array $b ): int =>
				strnatcasecmp( $a['label'], $b['label'] ) ?: strnatcasecmp( $a['attribute']['name'], $b['attribute']['name'] )
			);
			$target = wc_attribute_taxonomy_name( $this->groups[ $group_key ][0]['attribute_name'] );
			$target_key = sanitize_title( $target );
			if ( 1 === count( $entries ) && $entries[0]['key'] === $target_key && $entries[0]['attribute']['name'] === $target && ! empty( $entries[0]['attribute']['is_taxonomy'] ) ) {
				continue;
			}
			$winner = $entries[0];
			$filled = array();
			foreach ( $entries as $entry ) {
				if ( $entry['values'] ) {
					$filled[] = $entry;
				}
				unset( $result[ $entry['key'] ] );
				if ( ! empty( $entry['attribute']['is_taxonomy'] ) && $target !== $entry['attribute']['name'] ) {
					$remove[] = $entry['attribute']['name'];
				}
			}
			if ( $filled ) {
				$winner = $filled[0];
			}
			$merged = $winner['attribute'];
			$merged['name'] = $target;
			$merged['value'] = '';
			$merged['is_taxonomy'] = 1;
			$merged['position'] = min( array_map( static fn ( array $entry ): int => (int) ( $entry['attribute']['position'] ?? 0 ), $entries ) );
			if ( isset( $result[ $target_key ] ) ) {
				throw new RuntimeException( "Unrelated attribute already occupies {$target_key}." );
			}
			$result[ $target_key ] = $merged;
			$assign[ $target ] = $winner['values'];
			$conflict = false;
			foreach ( $filled as $entry ) {
				$conflict = $conflict || self::comparable_values( $entry['values'] ) !== self::comparable_values( $winner['values'] );
			}
			$decisions[] = array(
				'label' => $this->groups[ $group_key ][0]['attribute_label'], 'target' => $target,
				'winner' => $winner['attribute']['name'], 'values' => $winner['values'], 'conflict' => $conflict,
				'sources' => array_map( static fn ( array $entry ): array => array( 'name' => $entry['attribute']['name'], 'values' => $entry['values'] ), $entries ),
			);
		}
		return array( 'attributes' => $result, 'assign' => $assign, 'remove' => array_values( array_unique( $remove ) ), 'decisions' => $decisions );
	}

	/**
	 * Merge equal global attribute labels. Default: read-only preview.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Apply the migration after a successful full preflight and database backup.
	 *
	 * [--backup=<path>]
	 * : Required with --apply. New absolute .sql path outside the web root.
	 *
	 * [--report=<path>]
	 * : New JSONL report path. With --apply defaults to <backup>.attributes.jsonl.
	 *
	 * [--batch-size=<number>]
	 * : Rows scanned per batch (1-1000). Default: 200.
	 */
	public static function command( array $args, array $options ): void {
		global $wpdb;
		$lock = 'schrack-attribute-merge-' . md5( DB_NAME . $wpdb->prefix );
		$locked = false;
		$merger = null;
		try {
			if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
				throw new RuntimeException( 'Activate WooCommerce first.' );
			}
			$unknown = array_diff( array_keys( $options ), array( 'apply', 'backup', 'report', 'batch-size' ) );
			if ( $unknown || $args ) {
				throw new RuntimeException( 'Unknown arguments: ' . implode( ', ', array_merge( $args, $unknown ) ) );
			}
			$apply = isset( $options['apply'] );
			if ( $apply && class_exists( 'Schrack_Attribute_Merge_Job' ) && Schrack_Attribute_Merge_Job::blocks_imports() ) {
				throw new RuntimeException( 'An admin attribute merge is active. Resume it from WooCommerce > Unificare atribute.' );
			}
			$batch = (string) ( $options['batch-size'] ?? '200' );
			if ( ! ctype_digit( $batch ) || (int) $batch < 1 || (int) $batch > 1000 ) {
				throw new RuntimeException( '--batch-size must be between 1 and 1000.' );
			}
			if ( $apply ) {
				self::validate_output_path( (string) ( $options['backup'] ?? '' ), true );
				$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
				if ( ! $locked ) {
					throw new RuntimeException( 'Another attribute merge is running, or MySQL advisory locks are unavailable.' );
				}
			}
			$definitions = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}woocommerce_attribute_taxonomies ORDER BY attribute_id ASC", ARRAY_A );
			self::check_db();
			$merger = new self( $definitions, (int) $batch );
			$report_path = (string) ( $options['report'] ?? ( $apply ? $options['backup'] . '.attributes.jsonl' : '' ) );
			if ( '' !== $report_path ) {
				self::validate_output_path( $report_path, false );
				$merger->report = fopen( $report_path, 'x' );
				if ( false === $merger->report || ! chmod( $report_path, 0600 ) ) {
					throw new RuntimeException( 'Cannot create a private report file.' );
				}
			}
			$merger->run( $apply, (string) ( $options['backup'] ?? '' ) );
		} catch ( Throwable $error ) {
			if ( $merger && is_resource( $merger->report ) ) {
				// Do not mask the original error if writing the report itself failed.
				fwrite( $merger->report, json_encode( array( 'event' => 'error', 'message' => $error->getMessage() ) ) . "\n" );
			}
			WP_CLI::error( $error->getMessage(), false );
			$failed = true;
		} finally {
			if ( $merger && is_resource( $merger->report ) ) {
				fclose( $merger->report );
			}
			if ( $locked ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
		if ( ! empty( $failed ) ) {
			WP_CLI::halt( 1 );
		}
	}

	private static function validate_output_path( string $path, bool $sql ): void {
		$directory = realpath( dirname( $path ) );
		$root = realpath( ABSPATH );
		if ( ! str_starts_with( $path, '/' ) || ! $directory || ! is_writable( $directory ) || file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'Use a new absolute file path in an existing writable directory: ' . $path );
		}
		if ( $root && ( $directory === $root || str_starts_with( $directory, $root . DIRECTORY_SEPARATOR ) ) ) {
			throw new RuntimeException( 'Store the backup/report outside the WordPress web root.' );
		}
		if ( $sql && ! str_ends_with( $path, '.sql' ) ) {
			throw new RuntimeException( '--backup must name a new .sql file.' );
		}
	}

	private function run( bool $apply, string $backup ): void {
		global $wpdb;
		WP_CLI::log( $apply ? 'APPLY: keep all catalog/CSV imports and product editing paused until completion.' : 'DRY RUN: no catalog data will be changed.' );
		foreach ( $this->groups as $group ) {
			$target = wc_attribute_taxonomy_name( $group[0]['attribute_name'] );
			WP_CLI::log( $group[0]['attribute_label'] . ': ' . implode( ', ', array_column( $group, 'attribute_name' ) ) . ' => ' . $target );
			$this->record( array( 'event' => 'group', 'target' => $target, 'definitions' => $group ) );
			foreach ( $group as $definition ) {
				$taxonomy = wc_attribute_taxonomy_name( $definition['attribute_name'] );
				if ( ! taxonomy_exists( $taxonomy ) || $definition['attribute_type'] !== $group[0]['attribute_type'] ) {
					throw new RuntimeException( "Unregistered or incompatible attribute: {$taxonomy}." );
				}
			}
		}
		if ( ! $this->groups ) {
			WP_CLI::success( 'No duplicate global attribute labels found.' );
			return;
		}
		$this->check_variation_references();
		foreach ( $this->source_taxonomies() as $source => $target ) {
			$nested = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent <> 0 LIMIT 1", $source ) );
			self::check_db();
			if ( $nested ) {
				throw new RuntimeException( "Hierarchical value terms require a separate migration: {$source}, term {$nested}." );
			}
		}
		$summary = $this->scan_products( false );
		$this->check_relationships( false );
		$this->record( array( 'event' => 'preflight', 'summary' => $summary ) );
		WP_CLI::log( sprintf( 'Groups: %d. Products to change: %d. Conflicting value groups: %d.', count( $this->groups ), $summary['products'], $summary['conflicts'] ) );
		if ( ! $apply ) {
			WP_CLI::success( 'Preview complete. Use --apply --backup=/absolute/private/path.sql to execute.' );
			return;
		}
		// Each product's metadata and relationships must commit together.
		foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta ) as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			self::check_db();
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				throw new RuntimeException( "InnoDB is required for per-product transactions: {$table}." );
			}
		}
		WP_CLI::log( 'Creating full database backup: ' . $backup );
		$result = WP_CLI::runcommand( 'db export ' . escapeshellarg( $backup ) . ' --single-transaction', array( 'return' => 'all', 'exit_error' => false ) );
		if ( 0 !== $result->return_code || ! is_file( $backup ) || 0 === filesize( $backup ) || ! chmod( $backup, 0600 ) ) {
			throw new RuntimeException( 'Database backup failed; migration has not started. ' . (string) $result->stderr );
		}
		$this->record( array( 'event' => 'backup', 'path' => $backup ) );
		$this->copy_terms();
		$summary = $this->scan_products( true );
		// A second full scan catches remaining references before any definition is deleted.
		$remaining = $this->scan_products( false );
		if ( $remaining['products'] ) {
			throw new RuntimeException( 'Old attribute metadata remains. Definitions were not deleted; inspect the report and rerun.' );
		}
		$this->check_variation_references();
		$this->check_relationships( true );
		$this->save_registry();
		$this->delete_sources();
		delete_transient( 'wc_attribute_taxonomies' );
		WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		wp_schedule_single_event( time(), 'woocommerce_flush_rewrite_rules' );
		$this->record( array( 'event' => 'complete', 'summary' => $summary ) );
		WP_CLI::success( sprintf( 'Merge complete. Updated products: %d. Backup: %s', $summary['products'], $backup ) );
	}

	/** Scan even drafts/trash; deleting a global definition must not strand them. */
	private function attribute_rows(): Generator {
		global $wpdb;
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_attributes' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				$last, $this->batch_size
			), ARRAY_A );
			self::check_db();
			foreach ( $rows as $row ) {
				$last = (int) $row['meta_id'];
				yield $row;
			}
			// Flush only this process's runtime cache, never the shared object cache.
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $rows ) === $this->batch_size );
	}

	private function scan_products( bool $apply ): array {
		global $wpdb;
		$summary = array( 'products' => 0, 'conflicts' => 0 );
		$seen = array();
		foreach ( $this->attribute_rows() as $row ) {
			$raw = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $raw ) ) {
				if ( '' === $row['meta_value'] ) {
					continue;
				}
				throw new RuntimeException( 'Invalid attribute metadata on post ' . $row['post_id'] );
			}
			$id = (int) $row['post_id'];
			$plan = $this->plan_product( $raw, function ( string $taxonomy ) use ( $id ): array {
				$values = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names', 'orderby' => 'term_id' ) );
				self::check_result( $values );
				return $values;
			} );
			if ( ! $plan['decisions'] ) {
				// After an interrupted run a product may already be migrated, while its
				// lookup update has not completed. Refresh those products on reruns too.
				if ( $apply ) {
					foreach ( $raw as $attribute ) {
						if ( ! empty( $attribute['is_taxonomy'] ) && isset( $this->taxonomy_groups[ $attribute['name'] ] ) ) {
							$this->refresh_lookup( $id );
							break;
						}
					}
				}
				continue;
			}
			if ( isset( $seen[ $id ] ) || count( get_post_meta( $id, '_product_attributes', false ) ) !== 1 ) {
				throw new RuntimeException( "Multiple _product_attributes metadata rows on product {$id}." );
			}
			$seen[ $id ] = true;
			if ( 'product' !== get_post_type( $id ) ) {
				throw new RuntimeException( "Affected attributes belong to a non-product post: {$id}." );
			}
			++$summary['products'];
			foreach ( $plan['decisions'] as $decision ) {
				$summary['conflicts'] += (int) $decision['conflict'];
				if ( $decision['conflict'] && ! $apply ) {
					WP_CLI::log( "Conflict #{$id} {$decision['label']}: keep {$decision['winner']} = " . wp_json_encode( $decision['values'], JSON_UNESCAPED_UNICODE ) );
				}
			}
			$this->record( array( 'event' => $apply ? 'product_before' : 'product_plan', 'id' => $id, 'decisions' => $plan['decisions'] ) );
			if ( $apply ) {
				$this->apply_product( $id, $raw, $plan );
				$this->record( array( 'event' => 'product_done', 'id' => $id ) );
			}
			if ( 0 === $summary['products'] % 200 ) {
				WP_CLI::log( ( $apply ? 'Updated: ' : 'Planned: ' ) . $summary['products'] );
			}
		}
		return $summary;
	}

	private function check_variation_references(): void {
		global $wpdb;
		$names = array_merge( array_keys( $this->taxonomy_groups ), array_map( static fn ( array $group ): string => sanitize_title( $group[0]['attribute_label'] ), $this->groups ) );
		foreach ( $names as $taxonomy ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", 'attribute_' . $taxonomy ) );
			self::check_db();
			if ( $id ) {
				throw new RuntimeException( "Variation reference on post {$id}: {$taxonomy}. No variation dimensions are merged automatically." );
			}
		}
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_default_attributes' AND meta_id > %d ORDER BY meta_id LIMIT %d", $last, $this->batch_size ), ARRAY_A );
			self::check_db();
			foreach ( $rows as $row ) {
				$last = (int) $row['meta_id'];
				$defaults = maybe_unserialize( $row['meta_value'] );
				foreach ( is_array( $defaults ) ? array_keys( $defaults ) : array() as $name ) {
					if ( isset( $this->taxonomy_groups[ $name ] ) || isset( $this->groups[ self::label_key( $name ) ] ) ) {
						throw new RuntimeException( 'Affected default variation attribute on product ' . $row['post_id'] );
					}
				}
			}
		} while ( count( $rows ) === $this->batch_size );
	}

	/** Do not delete relationships that were absent from product attribute metadata. */
	private function check_relationships( bool $must_be_empty ): void {
		global $wpdb;
		foreach ( $this->source_taxonomies() as $taxonomy => $target ) {
			$last = 0;
			do {
				$ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT r.object_id FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE t.taxonomy = %s AND r.object_id > %d ORDER BY r.object_id LIMIT %d",
					$taxonomy, $last, $this->batch_size
				) );
				self::check_db();
				foreach ( $ids as $id ) {
					$last = (int) $id;
					$raw = get_post_meta( $last, '_product_attributes', true );
					$matched = false;
					foreach ( is_array( $raw ) ? $raw : array() as $attribute ) {
						$matched = $matched || ( ! empty( $attribute['is_taxonomy'] ) && ( $attribute['name'] ?? '' ) === $taxonomy );
					}
					if ( $must_be_empty || ! $matched || 'product' !== get_post_type( $last ) ) {
						throw new RuntimeException( "Unmigrated/orphan relationship: post {$last}, {$taxonomy}. Source definitions were not deleted." );
					}
				}
			} while ( count( $ids ) === $this->batch_size );
		}
	}

	private function source_taxonomies(): array {
		$sources = array();
		foreach ( $this->groups as $group ) {
			$target = wc_attribute_taxonomy_name( $group[0]['attribute_name'] );
			foreach ( $group as $definition ) {
				$source = wc_attribute_taxonomy_name( $definition['attribute_name'] );
				if ( $source !== $target ) {
					$sources[ $source ] = $target;
				}
			}
		}
		return $sources;
	}

	/** Retain unused value terms too. Existing target descriptions/meta take precedence. */
	private function copy_terms(): void {
		foreach ( $this->source_taxonomies() as $source => $target ) {
			$offset = 0;
			do {
				$terms = get_terms( array( 'taxonomy' => $source, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC', 'number' => $this->batch_size, 'offset' => $offset ) );
				self::check_result( $terms );
				foreach ( $terms as $term ) {
					$id = $this->ensure_term( $target, $term->name, $term );
					$this->record( array( 'event' => 'term', 'source' => $source, 'source_id' => (int) $term->term_id, 'target' => $target, 'target_id' => $id ) );
				}
				$offset += count( $terms );
			} while ( count( $terms ) === $this->batch_size );
		}
	}

	private function ensure_term( string $taxonomy, string $name, $source = null ): int {
		$is_new = false;
		// WP_Term_Query sanitizes then unslashes name filters; protect literal backslashes.
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'name' => array( wp_slash( $name ) ), 'orderby' => 'term_id', 'order' => 'ASC', 'number' => 0 ) );
		self::check_result( $terms );
		$id = 0;
		foreach ( $terms as $term ) {
			if ( $name === $term->name ) { $id = (int) $term->term_id; break; }
			// A database collation can equate accents too. Only a case-only match
			// is a valid fallback; an exact spelling later in the results wins.
			if ( ! $id && self::value_key( $name ) === self::value_key( $term->name ) ) { $id = (int) $term->term_id; }
		}
		if ( ! $id ) {
			$args = $source ? array( 'description' => $source->description, 'slug' => $source->slug ) : array();
			$created = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) );
			self::check_result( $created );
			$id = (int) $created['term_id'];
			$is_new = true;
		}
		if ( $source ) {
			foreach ( get_term_meta( $source->term_id ) as $key => $values ) {
				if ( ! $is_new && metadata_exists( 'term', $id, $key ) ) {
					continue;
				}
				// WooCommerce's admin term-create hook inserts order=0. A newly copied
				// value must inherit source metadata instead of keeping generated defaults.
				if ( $is_new ) { delete_term_meta( $id, $key ); self::check_db(); }
				foreach ( $values as $value ) {
					self::check_result( add_term_meta( $id, $key, wp_slash( maybe_unserialize( $value ) ) ) );
				}
			}
		}
		return $id;
	}

	private function apply_product( int $id, array $before, array $plan ): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		self::check_db();
		try {
			// Detect changes since planning and lock the metadata until the commit.
			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_product_attributes' FOR UPDATE", $id ) );
			self::check_db();
			if ( maybe_unserialize( $stored ) !== $before ) {
				throw new RuntimeException( "Product {$id} changed during migration. Pause imports and editing, then rerun." );
			}
			foreach ( $plan['assign'] as $taxonomy => $values ) {
				$ids = array();
				foreach ( $values as $value ) {
					$ids[] = $this->ensure_term( $taxonomy, $value );
				}
				self::check_result( wp_set_object_terms( $id, array_values( array_unique( $ids ) ), $taxonomy, false ) );
			}
			update_post_meta( $id, '_product_attributes', wp_slash( $plan['attributes'] ) );
			self::check_db();
			foreach ( $plan['remove'] as $taxonomy ) {
				self::check_result( wp_set_object_terms( $id, array(), $taxonomy, false ) );
			}
			foreach ( $plan['assign'] as $taxonomy => $expected_values ) {
				$actual_values = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names' ) );
				self::check_result( $actual_values );
				if ( self::comparable_values( $expected_values ) !== self::comparable_values( $actual_values ) ) {
					throw new RuntimeException( "Term value verification failed for product {$id}, {$taxonomy}. Expected: " . wp_json_encode( $expected_values, JSON_UNESCAPED_UNICODE ) . '; found: ' . wp_json_encode( $actual_values, JSON_UNESCAPED_UNICODE ) . '.' );
				}
			}
			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_product_attributes'", $id ) );
			self::check_db();
			if ( maybe_unserialize( $stored ) !== $plan['attributes'] ) {
				throw new RuntimeException( "Attribute write verification failed for product {$id}." );
			}
			$wpdb->query( 'COMMIT' );
			self::check_db();
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			clean_post_cache( $id );
			clean_object_term_cache( $id, 'product' );
			throw $error;
		}
		clean_post_cache( $id );
		clean_object_term_cache( $id, 'product' );
		wc_delete_product_transients( $id );
		$this->refresh_lookup( $id );
	}

	private function refresh_lookup( int $id ): void {
		// Direct metadata updates bypass WC_Product::save(), so refresh the filter index.
		$class = '\\Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\LookupDataStore';
		if ( class_exists( $class ) ) {
			$direct = static fn () => 'yes';
			add_filter( 'pre_option_woocommerce_attribute_lookup_direct_updates', $direct );
			try {
				wc_get_container()->get( $class )->on_product_changed( $id );
				self::check_db();
			} finally {
				remove_filter( 'pre_option_woocommerce_attribute_lookup_direct_updates', $direct );
			}
		}
	}

	/** Save redirects before deleting definitions, including new columns with the same label. */
	private function save_registry(): void {
		$registry = get_option( self::REGISTRY_OPTION, array() );
		$registry = is_array( $registry ) ? $registry : array();
		$dynamic = get_option( 'schrack_wc_sync_dynamic_attributes', array() );
		$dynamic = is_array( $dynamic ) ? $dynamic : array();
		foreach ( $this->groups as $key => $group ) {
			$target = $group[0]['attribute_name'];
			$registry['labels'][ $key ] = $target;
			foreach ( $group as $definition ) {
				$slug = $definition['attribute_name'];
				$registry['slugs'][ $slug ] = $target;
				unset( $dynamic[ $slug ] );
			}
			$dynamic[ $target ] = $group[0]['attribute_label'];
		}
		// Flatten old redirects if a later run selected a different canonical taxonomy.
		foreach ( $registry['slugs'] as &$target ) {
			$seen = array();
			while ( isset( $registry['slugs'][ $target ] ) && $registry['slugs'][ $target ] !== $target && ! isset( $seen[ $target ] ) ) {
				$seen[ $target ] = true;
				$target = $registry['slugs'][ $target ];
			}
		}
		unset( $target );
		update_option( self::REGISTRY_OPTION, $registry, false );
		update_option( 'schrack_wc_sync_dynamic_attributes', $dynamic, false );
		self::check_db();
		if ( get_option( self::REGISTRY_OPTION ) !== $registry || get_option( 'schrack_wc_sync_dynamic_attributes' ) !== $dynamic ) {
			throw new RuntimeException( 'Could not persist the importer/filter redirects. Definitions were not deleted.' );
		}
	}

	private function delete_sources(): void {
		global $wpdb;
		foreach ( $this->source_taxonomies() as $source => $target ) {
			do {
				$terms = get_terms( array( 'taxonomy' => $source, 'hide_empty' => false, 'fields' => 'ids', 'number' => $this->batch_size ) );
				self::check_result( $terms );
				foreach ( $terms as $id ) {
					self::check_result( wp_delete_term( (int) $id, $source ) );
				}
			} while ( $terms );
		}
		foreach ( $this->groups as $group ) {
			foreach ( array_slice( $group, 1 ) as $definition ) {
				// Never call wc_delete_attribute() for duplicate DB rows with the SAME slug:
				// it would delete the surviving taxonomy's terms too. Terms are handled above.
				$result = $wpdb->delete( $wpdb->prefix . 'woocommerce_attribute_taxonomies', array( 'attribute_id' => (int) $definition['attribute_id'] ), array( '%d' ) );
				self::check_result( $result );
				if ( 1 !== $result ) {
					throw new RuntimeException( 'Attribute definition changed during deletion.' );
				}
				$this->record( array( 'event' => 'definition_deleted', 'definition' => $definition ) );
			}
		}
	}

	private function record( array $data ): void {
		if ( is_resource( $this->report ) ) {
			$line = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
			if ( strlen( $line ) !== fwrite( $this->report, $line ) || ! fflush( $this->report ) ) {
				throw new RuntimeException( 'Cannot write the migration report.' );
			}
		}
	}

	private static function check_db(): void {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'Database error: ' . $wpdb->last_error );
		}
	}

	private static function check_result( $result ): void {
		self::check_db();
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		if ( false === $result || null === $result ) {
			throw new RuntimeException( 'A WordPress attribute/term operation failed.' );
		}
	}
}
