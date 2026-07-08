<?php
/**
 * Background product-category CSV importer.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Category_CSV_Importer {
	public const STATUS_KEY       = 'category_import';
	public const DEFAULT_BATCH_SIZE = 100;
	public const DEFAULT_MAX_SECONDS = 20;
	private const WARNING_LIMIT   = 10;
	private const ACTIVE_TTL      = 30 * MINUTE_IN_SECONDS;

	/**
	 * Settings.
	 *
	 * @var Schrack_Settings
	 */
	private Schrack_Settings $settings;

	/**
	 * Logger.
	 *
	 * @var Schrack_Logger
	 */
	private Schrack_Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Copies an uploaded CSV into the uploads area and initializes import status.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function prepare_upload( string $tmp_name, string $original_name ): array|WP_Error {
		$active = $this->active_import();

		if ( null !== $active ) {
			return new WP_Error(
				'category_import_active',
				__( 'A category CSV import is already queued or running. Wait for it to finish before starting a new one.', 'schrack-woocommerce-sync' )
			);
		}

		$validation = $this->validate_csv_file( $tmp_name );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$dir = $this->import_dir();

		if ( '' === $dir ) {
			return new WP_Error( 'category_import_dir', __( 'Could not create the category import upload directory.', 'schrack-woocommerce-sync' ) );
		}

		$this->cleanup_old_import_files( $dir );

		$import_id = wp_generate_uuid4();
		$base_name = sanitize_file_name( pathinfo( '' !== $original_name ? $original_name : 'categories.csv', PATHINFO_FILENAME ) );

		if ( '' === $base_name ) {
			$base_name = 'categories';
		}

		$file_name = 'category-import-' . gmdate( 'Ymd-His' ) . '-' . $base_name . '-' . substr( $import_id, 0, 8 ) . '.csv';
		$target    = trailingslashit( $dir ) . $file_name;

		if ( ! copy( $tmp_name, $target ) ) {
			return new WP_Error( 'category_import_copy', __( 'Could not save the uploaded CSV for background import.', 'schrack-woocommerce-sync' ) );
		}

		$total_rows = $this->count_csv_rows( $target, (string) $validation['delimiter'] );
		$status     = array(
			'state'            => 'queued',
			'import_id'        => $import_id,
			'file'             => $target,
			'file_name'        => $file_name,
			'delimiter'        => (string) $validation['delimiter'],
			'total_rows'       => $total_rows,
			'line_number'      => 1,
			'processed'        => 0,
			'created'          => 0,
			'updated'          => 0,
			'skipped'          => 0,
			'errors'           => 0,
			'warnings'         => array(),
			'batch_count'      => 0,
			'batch_limit'      => self::DEFAULT_BATCH_SIZE,
			'completed_cycle'  => 'no',
			'started_at'       => time(),
			'updated_at'       => time(),
			'started_at_label' => current_time( 'mysql' ),
		);

		$this->settings->update_status( self::STATUS_KEY, $status );
		$this->logger->info( 'category_import', 'Queued category CSV import file.', null, $status );

		return $status;
	}

	/**
	 * Runs one background import batch.
	 *
	 * @return array<string,mixed>
	 */
	public function run_batch( string $import_id, int $batch_size = self::DEFAULT_BATCH_SIZE, int $max_seconds = self::DEFAULT_MAX_SECONDS ): array {
		$status = $this->status();

		if ( $import_id !== (string) ( $status['import_id'] ?? '' ) ) {
			$this->logger->warning(
				'category_import',
				'Skipped stale category CSV import worker for a non-active import ID.',
				null,
				array(
					'worker_import_id' => $import_id,
					'active_import_id' => (string) ( $status['import_id'] ?? '' ),
				)
			);

			return array_merge(
				$status,
				array(
					'completed_cycle' => 'yes',
					'stale_worker'    => 'yes',
				)
			);
		}

		if ( in_array( (string) ( $status['state'] ?? '' ), array( 'done', 'error' ), true ) ) {
			return $status;
		}

		$file = (string) ( $status['file'] ?? '' );

		if ( '' === $file || ! is_readable( $file ) ) {
			return $this->error_result( $import_id, __( 'Category import CSV file is missing or unreadable.', 'schrack-woocommerce-sync' ) );
		}

		$validation = $this->validate_csv_file( $file );

		if ( is_wp_error( $validation ) ) {
			return $this->error_result( $import_id, $validation->get_error_message() );
		}

		$handle = fopen( $file, 'r' );

		if ( false === $handle ) {
			return $this->error_result( $import_id, __( 'Category import CSV file could not be opened.', 'schrack-woocommerce-sync' ) );
		}

		$first_line = fgets( $handle );

		if ( false === $first_line ) {
			fclose( $handle );
			return $this->error_result( $import_id, __( 'Category import CSV file is empty.', 'schrack-woocommerce-sync' ) );
		}

		$delimiter  = (string) $validation['delimiter'];
		$header_map = (array) $validation['header_map'];
		$lookup     = $this->product_category_lookup();
		$line_number = 1;
		$last_line   = max( 1, absint( $status['line_number'] ?? 1 ) );
		$started_at  = time();
		$batch_size  = max( 1, $batch_size );
		$max_seconds = max( 5, $max_seconds );
		$batch_count = 0;
		$created     = absint( $status['created'] ?? 0 );
		$updated     = absint( $status['updated'] ?? 0 );
		$skipped     = absint( $status['skipped'] ?? 0 );
		$processed   = absint( $status['processed'] ?? 0 );
		$warnings    = isset( $status['warnings'] ) && is_array( $status['warnings'] ) ? $status['warnings'] : array();

		$this->settings->update_status(
			self::STATUS_KEY,
			array_merge(
				$status,
				array(
					'state'      => 'running',
					'updated_at' => time(),
				)
			)
		);

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			++$line_number;

			if ( $line_number <= $last_line ) {
				continue;
			}

			if ( $this->is_empty_csv_row( $row ) ) {
				continue;
			}

			$result = $this->import_row( $row, $header_map, $lookup );
			++$batch_count;
			++$processed;

			if ( 'created' === (string) ( $result['status'] ?? '' ) ) {
				++$created;
			} elseif ( 'updated' === (string) ( $result['status'] ?? '' ) ) {
				++$updated;
			} else {
				++$skipped;
			}

			if ( ! empty( $result['warning'] ) && count( $warnings ) < self::WARNING_LIMIT ) {
				$warnings[] = sprintf(
					/* translators: 1: CSV line number, 2: warning message. */
					__( 'Line %1$d: %2$s', 'schrack-woocommerce-sync' ),
					$line_number,
					(string) $result['warning']
				);
			}

			if ( ! empty( $result['term_id'] ) ) {
				$this->add_term_to_lookup( absint( $result['term_id'] ), $lookup );
			}

			if ( $batch_count >= $batch_size || time() - $started_at >= $max_seconds ) {
				break;
			}
		}

		$completed = feof( $handle );
		fclose( $handle );

		$result_status = array_merge(
			$status,
			array(
				'state'           => $completed ? 'done' : 'running',
				'import_id'       => $import_id,
				'line_number'     => $line_number,
				'processed'       => $processed,
				'created'         => $created,
				'updated'         => $updated,
				'skipped'         => $skipped,
				'errors'          => $skipped,
				'warnings'        => array_values( $warnings ),
				'batch_count'     => $batch_count,
				'batch_limit'     => $batch_size,
				'completed_cycle' => $completed ? 'yes' : 'no',
				'updated_at'      => time(),
				'updated_at_label'=> current_time( 'mysql' ),
			)
		);

		if ( $completed ) {
			$result_status['finished_at']       = time();
			$result_status['finished_at_label'] = current_time( 'mysql' );
		}

		$this->settings->update_status( self::STATUS_KEY, $result_status );
		$this->logger->info( 'category_import', $completed ? 'Finished category CSV import batch.' : 'Processed category CSV import batch.', null, $result_status );

		return $result_status;
	}

	/**
	 * Marks the active import as failed because a follow-up action could not be queued.
	 */
	public function mark_queue_failed( string $import_id ): void {
		$status = $this->status();

		if ( $import_id !== (string) ( $status['import_id'] ?? '' ) ) {
			return;
		}

		$this->settings->update_status(
			self::STATUS_KEY,
			array_merge(
				$status,
				array(
					'state'          => 'error',
					'completed_cycle'=> 'no',
					'queue_failed'   => 'yes',
					'message'        => __( 'Could not queue the next category import batch. Please check Action Scheduler/WP-Cron.', 'schrack-woocommerce-sync' ),
					'updated_at'     => time(),
				)
			)
		);
	}

	/**
	 * Returns the currently active import, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function active_import(): ?array {
		$status = $this->status();
		$state  = (string) ( $status['state'] ?? '' );

		if ( ! in_array( $state, array( 'queued', 'running' ), true ) ) {
			return null;
		}

		$updated_at = absint( $status['updated_at'] ?? $status['started_at'] ?? 0 );

		if ( $updated_at > 0 && $updated_at < time() - self::ACTIVE_TTL ) {
			return null;
		}

		return $status;
	}

	/**
	 * Returns current status row.
	 *
	 * @return array<string,mixed>
	 */
	private function status(): array {
		$status = $this->settings->get_status();
		$row    = isset( $status[ self::STATUS_KEY ] ) && is_array( $status[ self::STATUS_KEY ] ) ? $status[ self::STATUS_KEY ] : array();

		return $row;
	}

	/**
	 * Stores and returns an error result.
	 *
	 * @param array<string,mixed> $extra Extra status values.
	 * @return array<string,mixed>
	 */
	private function error_result( string $import_id, string $message, array $extra = array() ): array {
		$status = $this->status();
		$result = array_merge(
			$status,
			array(
				'state'           => 'error',
				'import_id'       => $import_id,
				'completed_cycle' => 'no',
				'message'         => $message,
				'errors'          => max( 1, absint( $status['errors'] ?? 0 ) ),
				'updated_at'      => time(),
				'updated_at_label'=> current_time( 'mysql' ),
			),
			$extra
		);

		$this->settings->update_status( self::STATUS_KEY, $result );
		$this->logger->error( 'category_import', 'Category CSV import failed.', null, $result );

		return $result;
	}

	/**
	 * Validates CSV header and returns parse metadata.
	 *
	 * @return array{delimiter:string,headers:array<int,string>,header_map:array<string,int>}|WP_Error
	 */
	private function validate_csv_file( string $path ): array|WP_Error {
		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			return new WP_Error( 'category_csv_open', __( 'CSV upload could not be opened.', 'schrack-woocommerce-sync' ) );
		}

		$first_line = fgets( $handle );
		fclose( $handle );

		if ( false === $first_line ) {
			return new WP_Error( 'category_csv_empty', __( 'CSV file is empty.', 'schrack-woocommerce-sync' ) );
		}

		$delimiter  = $this->detect_csv_delimiter( $first_line );
		$headers    = str_getcsv( $this->strip_utf8_bom( $first_line ), $delimiter, '"', '\\' );
		$header_map = $this->category_csv_header_map( $headers );

		if ( empty( array_intersect( array_keys( $header_map ), array( 'term_id', 'slug', 'path', 'name' ) ) ) ) {
			return new WP_Error( 'category_csv_headers', __( 'CSV must include term_id, slug, path, or name column.', 'schrack-woocommerce-sync' ) );
		}

		return array(
			'delimiter'  => $delimiter,
			'headers'    => $headers,
			'header_map' => $header_map,
		);
	}

	/**
	 * Counts non-empty CSV data rows.
	 */
	private function count_csv_rows( string $path, string $delimiter ): int {
		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			return 0;
		}

		fgets( $handle );
		$count = 0;

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			if ( ! $this->is_empty_csv_row( $row ) ) {
				++$count;
			}
		}

		fclose( $handle );

		return $count;
	}

	/**
	 * Imports one product category CSV row.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 * @param array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>} $lookup Category lookup.
	 * @return array{status:string,term_id?:int,warning?:string}
	 */
	private function import_row( array $row, array $header_map, array $lookup ): array {
		$path       = $this->csv_cell( $row, $header_map, 'path' );
		$path_parts = $this->product_category_path_parts( $path );
		$name       = $this->csv_cell( $row, $header_map, 'name' );
		$slug       = sanitize_title( $this->csv_cell( $row, $header_map, 'slug' ) );

		if ( '' === $name && ! empty( $path_parts ) ) {
			$name = (string) end( $path_parts );
		}

		if ( '' === $name && '' !== $slug ) {
			$name = ucwords( str_replace( '-', ' ', $slug ) );
		}

		$term_id = $this->resolve_category_csv_term_id( $row, $header_map, $lookup, $path_parts );

		if ( '' === $name && $term_id > 0 ) {
			$existing_term = get_term( $term_id, 'product_cat' );
			$name          = $existing_term instanceof WP_Term ? $existing_term->name : '';
		}

		if ( '' === $name ) {
			return array(
				'status'  => 'skipped',
				'warning' => __( 'missing category name or path.', 'schrack-woocommerce-sync' ),
			);
		}

		$parent_id = $this->category_csv_parent_id( $row, $header_map, $path_parts );
		$args      = array();

		if ( isset( $header_map['name'] ) || ! empty( $path_parts ) || 0 === $term_id ) {
			$args['name'] = $name;
		}

		if ( isset( $header_map['slug'] ) && '' !== $slug ) {
			$args['slug'] = $slug;
		}

		if ( isset( $header_map['description'] ) ) {
			$args['description'] = wp_kses_post( $this->csv_cell( $row, $header_map, 'description' ) );
		}

		if ( $parent_id > 0 && $parent_id !== $term_id ) {
			$args['parent'] = $parent_id;
		} elseif ( isset( $header_map['parent_id'] ) || isset( $header_map['parent_slug'] ) || isset( $header_map['parent_path'] ) || count( $path_parts ) > 1 ) {
			$args['parent'] = 0;
		}

		if ( $term_id > 0 ) {
			$result = empty( $args ) ? array( 'term_id' => $term_id ) : wp_update_term( $term_id, 'product_cat', $args );

			if ( is_wp_error( $result ) ) {
				return array(
					'status'  => 'skipped',
					'warning' => $result->get_error_message(),
				);
			}

			$this->update_category_csv_meta( $term_id, $row, $header_map );

			return array(
				'status'  => 'updated',
				'term_id' => $term_id,
			);
		}

		$result = wp_insert_term( $name, 'product_cat', $args );

		if ( is_wp_error( $result ) ) {
			$existing_id = absint( $result->get_error_data( 'term_exists' ) );

			if ( $existing_id > 0 ) {
				$this->update_category_csv_meta( $existing_id, $row, $header_map );

				return array(
					'status'  => 'updated',
					'term_id' => $existing_id,
					'warning' => __( 'existing category was updated instead of created.', 'schrack-woocommerce-sync' ),
				);
			}

			return array(
				'status'  => 'skipped',
				'warning' => $result->get_error_message(),
			);
		}

		$created_id = absint( $result['term_id'] ?? 0 );
		$this->update_category_csv_meta( $created_id, $row, $header_map );

		return array(
			'status'  => 'created',
			'term_id' => $created_id,
		);
	}

	/**
	 * Returns lookup maps for product categories.
	 *
	 * @return array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>}
	 */
	private function product_category_lookup(): array {
		$lookup = array(
			'ids'   => array(),
			'slugs' => array(),
			'paths' => array(),
		);

		$tree = $this->product_category_tree();

		foreach ( $tree['terms'] as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$term_id                         = (int) $term->term_id;
			$lookup['ids'][ $term_id ]      = true;
			$lookup['slugs'][ $term->slug ] = $term_id;
			$path_key                       = $this->category_path_key( (string) ( $tree['paths'][ $term_id ] ?? $term->name ) );

			if ( '' !== $path_key && ! isset( $lookup['paths'][ $path_key ] ) ) {
				$lookup['paths'][ $path_key ] = $term_id;
			}
		}

		return $lookup;
	}

	/**
	 * Adds a changed term to a lookup map.
	 *
	 * @param array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>} $lookup Category lookup.
	 */
	private function add_term_to_lookup( int $term_id, array &$lookup ): void {
		$term = get_term( $term_id, 'product_cat' );

		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$lookup['ids'][ $term_id ]      = true;
		$lookup['slugs'][ $term->slug ] = $term_id;
	}

	/**
	 * Returns WooCommerce product categories in tree order with display paths.
	 *
	 * @return array{terms:array<int,WP_Term>,paths:array<int,string>}
	 */
	private function product_category_tree(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array( 'terms' => array(), 'paths' => array() );
		}

		$terms_by_id     = array();
		$terms_by_parent = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$term_id                         = (int) $term->term_id;
			$parent_id                       = absint( $term->parent );
			$terms_by_id[ $term_id ]         = $term;
			$terms_by_parent[ $parent_id ]   = $terms_by_parent[ $parent_id ] ?? array();
			$terms_by_parent[ $parent_id ][] = $term;
		}

		foreach ( $terms_by_parent as $parent_id => $children ) {
			usort(
				$children,
				static function ( WP_Term $left, WP_Term $right ): int {
					return strnatcasecmp( $left->name, $right->name );
				}
			);
			$terms_by_parent[ $parent_id ] = $children;
		}

		$paths   = array();
		$ordered = array();
		$append  = static function ( int $parent_id, string $parent_path ) use ( &$append, &$ordered, &$paths, $terms_by_parent ): void {
			foreach ( $terms_by_parent[ $parent_id ] ?? array() as $term ) {
				$term_id           = (int) $term->term_id;
				$path              = '' === $parent_path ? $term->name : $parent_path . ' > ' . $term->name;
				$paths[ $term_id ] = $path;
				$ordered[]         = $term;

				$append( $term_id, $path );
			}
		};

		$append( 0, '' );

		foreach ( $terms_by_id as $term_id => $term ) {
			if ( isset( $paths[ $term_id ] ) ) {
				continue;
			}

			$paths[ $term_id ] = $term->name;
			$ordered[]         = $term;
			$append( $term_id, $term->name );
		}

		return array( 'terms' => $ordered, 'paths' => $paths );
	}

	/**
	 * Maps category CSV header labels to importer fields.
	 *
	 * @param array<int,string> $headers CSV headers.
	 * @return array<string,int>
	 */
	private function category_csv_header_map( array $headers ): array {
		$aliases = array(
			'term_id'      => array( 'termid', 'id', 'categoryid', 'categorytermid' ),
			'parent_id'    => array( 'parentid', 'parenttermid', 'parentcategoryid' ),
			'parent_slug'  => array( 'parentslug', 'parentcategoryslug' ),
			'parent_path'  => array( 'parentpath', 'parentcategorypath' ),
			'path'         => array( 'path', 'categorypath', 'productcatpath', 'categoriepath' ),
			'name'         => array( 'name', 'categoryname', 'nume', 'nev' ),
			'slug'         => array( 'slug', 'categoryslug', 'productcatslug' ),
			'description'  => array( 'description', 'desc', 'categorydescription', 'descriere' ),
			'display_type' => array( 'displaytype', 'display', 'woodisplaytype' ),
			'image_id'     => array( 'imageid', 'thumbnailid', 'thumbnail_id', 'imageattachmentid' ),
			'image_url'    => array( 'imageurl', 'thumbnailurl', 'thumbnail_url' ),
			'menu_order'   => array( 'menuorder', 'order', 'sortorder', 'position' ),
		);
		$map     = array();

		foreach ( $headers as $index => $header ) {
			$key = $this->csv_key( (string) $header );

			if ( '' === $key ) {
				continue;
			}

			foreach ( $aliases as $field => $field_aliases ) {
				if ( isset( $map[ $field ] ) || ! in_array( $key, $field_aliases, true ) ) {
					continue;
				}

				$map[ $field ] = (int) $index;
				break;
			}
		}

		return $map;
	}

	/**
	 * Resolves the target category for a CSV row.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 * @param array{ids:array<int,bool>,slugs:array<string,int>,paths:array<string,int>} $lookup Category lookup.
	 * @param array<int,string>      $path_parts Category path parts.
	 */
	private function resolve_category_csv_term_id( array $row, array $header_map, array $lookup, array $path_parts ): int {
		$term_id = absint( $this->csv_cell( $row, $header_map, 'term_id' ) );

		if ( $term_id > 0 && isset( $lookup['ids'][ $term_id ] ) ) {
			return $term_id;
		}

		$slug = sanitize_title( $this->csv_cell( $row, $header_map, 'slug' ) );

		if ( '' !== $slug ) {
			if ( isset( $lookup['slugs'][ $slug ] ) ) {
				return (int) $lookup['slugs'][ $slug ];
			}

			$term = get_term_by( 'slug', $slug, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				return (int) $term->term_id;
			}
		}

		if ( ! empty( $path_parts ) ) {
			$term_id = $this->find_product_category_path( $path_parts );

			if ( $term_id > 0 ) {
				return $term_id;
			}
		}

		$path_key = $this->category_path_key( $this->csv_cell( $row, $header_map, 'path' ) );

		return '' !== $path_key && isset( $lookup['paths'][ $path_key ] ) ? (int) $lookup['paths'][ $path_key ] : 0;
	}

	/**
	 * Resolves or creates the parent category for a CSV row.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 * @param array<int,string>      $path_parts Category path parts.
	 */
	private function category_csv_parent_id( array $row, array $header_map, array $path_parts ): int {
		if ( count( $path_parts ) > 1 ) {
			return $this->ensure_product_category_path( array_slice( $path_parts, 0, -1 ) );
		}

		$parent_path = $this->csv_cell( $row, $header_map, 'parent_path' );

		if ( '' !== $parent_path ) {
			return $this->ensure_product_category_path( $this->product_category_path_parts( $parent_path ) );
		}

		$parent_id = absint( $this->csv_cell( $row, $header_map, 'parent_id' ) );

		if ( $parent_id > 0 && get_term( $parent_id, 'product_cat' ) instanceof WP_Term ) {
			return $parent_id;
		}

		$parent_slug = sanitize_title( $this->csv_cell( $row, $header_map, 'parent_slug' ) );

		if ( '' !== $parent_slug ) {
			$parent = get_term_by( 'slug', $parent_slug, 'product_cat' );

			if ( $parent instanceof WP_Term ) {
				return (int) $parent->term_id;
			}
		}

		return 0;
	}

	/**
	 * Finds a product category by hierarchical path.
	 *
	 * @param array<int,string> $parts Category path parts.
	 */
	private function find_product_category_path( array $parts ): int {
		$parent_id = 0;
		$term_id   = 0;

		foreach ( $parts as $part ) {
			$term = $this->product_category_term_by_name( $part, $parent_id );

			if ( ! $term instanceof WP_Term ) {
				return 0;
			}

			$term_id   = (int) $term->term_id;
			$parent_id = $term_id;
		}

		return $term_id;
	}

	/**
	 * Ensures a hierarchical product category path exists.
	 *
	 * @param array<int,string> $parts Category path parts.
	 */
	private function ensure_product_category_path( array $parts ): int {
		$parent_id = 0;
		$term_id   = 0;

		foreach ( $parts as $part ) {
			$name = sanitize_text_field( $part );

			if ( '' === $name ) {
				continue;
			}

			$term = $this->product_category_term_by_name( $name, $parent_id );

			if ( $term instanceof WP_Term ) {
				$term_id   = (int) $term->term_id;
				$parent_id = $term_id;
				continue;
			}

			$result = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_id ) );

			if ( is_wp_error( $result ) ) {
				$existing_id = absint( $result->get_error_data( 'term_exists' ) );

				if ( $existing_id <= 0 ) {
					return 0;
				}

				$term_id = $existing_id;
			} else {
				$term_id = absint( $result['term_id'] ?? 0 );
			}

			$parent_id = $term_id;
		}

		return $term_id;
	}

	/**
	 * Finds one product category by name and parent.
	 */
	private function product_category_term_by_name( string $name, int $parent_id ): ?WP_Term {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'name'       => $name,
				'parent'     => $parent_id,
				'number'     => 1,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) || ! $terms[0] instanceof WP_Term ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Splits a category path into clean parts.
	 *
	 * @return array<int,string>
	 */
	private function product_category_path_parts( string $path ): array {
		if ( '' === trim( $path ) ) {
			return array();
		}

		$parts = preg_split( '/\s*(?:>|\/|\|)\s*/', $path );

		return array_values(
			array_filter(
				array_map(
					static fn ( mixed $part ): string => sanitize_text_field( is_scalar( $part ) ? (string) $part : '' ),
					is_array( $parts ) ? $parts : array()
				),
				static fn ( string $part ): bool => '' !== $part
			)
		);
	}

	/**
	 * Updates WooCommerce product category meta from CSV fields.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 */
	private function update_category_csv_meta( int $term_id, array $row, array $header_map ): void {
		if ( $term_id <= 0 ) {
			return;
		}

		if ( isset( $header_map['display_type'] ) ) {
			$display_type = $this->normalize_category_display_type( $this->csv_cell( $row, $header_map, 'display_type' ) );

			if ( '' === $display_type ) {
				delete_term_meta( $term_id, 'display_type' );
			} else {
				update_term_meta( $term_id, 'display_type', $display_type );
			}
		}

		if ( isset( $header_map['image_id'] ) || isset( $header_map['image_url'] ) ) {
			$image_id_value  = $this->csv_cell( $row, $header_map, 'image_id' );
			$image_url_value = $this->csv_cell( $row, $header_map, 'image_url' );
			$image_id        = absint( $image_id_value );

			if ( $image_id <= 0 ) {
				$image_id = '' !== $image_url_value && function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $image_url_value ) ) : 0;
			}

			if ( $image_id > 0 ) {
				update_term_meta( $term_id, 'thumbnail_id', $image_id );
			} elseif ( isset( $header_map['image_id'] ) && '' === $image_id_value && '' === $image_url_value ) {
				delete_term_meta( $term_id, 'thumbnail_id' );
			}
		}

		if ( isset( $header_map['menu_order'] ) ) {
			$order = $this->csv_cell( $row, $header_map, 'menu_order' );

			if ( '' === $order ) {
				delete_term_meta( $term_id, 'order' );
			} else {
				update_term_meta( $term_id, 'order', max( 0, (int) $order ) );
			}
		}
	}

	/**
	 * Normalizes WooCommerce category display type.
	 */
	private function normalize_category_display_type( string $display_type ): string {
		$key = sanitize_key( $display_type );

		return in_array( $key, array( 'products', 'subcategories', 'both' ), true ) ? $key : '';
	}

	/**
	 * Returns one CSV cell by mapped field.
	 *
	 * @param array<int,string|null> $row CSV row.
	 * @param array<string,int>      $header_map Header map.
	 */
	private function csv_cell( array $row, array $header_map, string $field ): string {
		if ( ! isset( $header_map[ $field ] ) ) {
			return '';
		}

		$index = (int) $header_map[ $field ];
		$value = $row[ $index ] ?? '';

		return trim( wp_check_invalid_utf8( is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * Checks whether a parsed CSV row is empty.
	 *
	 * @param array<int,string|null> $row CSV row.
	 */
	private function is_empty_csv_row( array $row ): bool {
		foreach ( $row as $value ) {
			if ( '' !== trim( is_scalar( $value ) ? (string) $value : '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Detects common CSV delimiters from the header line.
	 */
	private function detect_csv_delimiter( string $line ): string {
		$best_delimiter = ',';
		$best_columns   = 0;

		foreach ( array( ',', ';', "\t" ) as $delimiter ) {
			$columns = count( str_getcsv( $line, $delimiter, '"', '\\' ) );

			if ( $columns > $best_columns ) {
				$best_columns   = $columns;
				$best_delimiter = $delimiter;
			}
		}

		return $best_delimiter;
	}

	/**
	 * Removes UTF-8 BOM from a CSV header line.
	 */
	private function strip_utf8_bom( string $line ): string {
		return str_starts_with( $line, "\xEF\xBB\xBF" ) ? substr( $line, 3 ) : $line;
	}

	/**
	 * Builds a normalized CSV/key lookup token.
	 */
	private function csv_key( string $value ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}

		$value = strtolower( $value );

		return (string) preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	/**
	 * Normalizes category paths for CSV matching.
	 */
	private function category_path_key( string $path ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$path = remove_accents( $path );
		}

		$path = strtolower( $path );
		$path = preg_replace( '/\s*>\s*/', '>', $path );
		$path = preg_replace( '/\s+/', ' ', (string) $path );

		return trim( (string) $path );
	}

	/**
	 * Returns the import storage directory.
	 */
	private function import_dir(): string {
		$upload = wp_upload_dir( null, false );

		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( (string) $upload['basedir'] ) . 'schrack-wc-sync/category-import';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Removes old category import files.
	 */
	private function cleanup_old_import_files( string $dir ): void {
		$cutoff = time() - 2 * DAY_IN_SECONDS;

		foreach ( glob( trailingslashit( $dir ) . 'category-import-*.csv' ) ?: array() as $path ) {
			if ( is_file( $path ) && (int) filemtime( $path ) < $cutoff ) {
				wp_delete_file( $path );
			}
		}
	}
}
