<?php
/** Checkpointed media audit and admin-only previews. Originals are never replaced. */
defined( 'ABSPATH' ) || exit;

final class Schrack_Media_Maintenance {
	public const STATE = 'schrack_media_maintenance_state';
	public const HOOK = 'schrack_media_maintenance_step';
	public const PREVIEWS = '_schrack_media_previews';
	private const GROUP = 'schrack-media-maintenance';
	private array $preview_cache = array();

	public function init(): void {
		add_action( 'schrack_performance_tools', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_enqueue_media', array( $this, 'preview_assets' ) );
		add_action( 'wp_ajax_schrack_media_maintenance', array( $this, 'ajax' ) );
		add_action( self::HOOK, array( $this, 'work' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_attachment' ), 20, 3 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'list_image' ), 20, 4 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'list_attributes' ), 20, 3 );
	}

	public static function status(): array {
		wp_cache_delete( self::STATE, 'options' );
		$value = get_option( self::STATE, array() );
		return is_array( $value ) ? $value : array();
	}

	public function assets( string $hook ): void {
		if ( 'woocommerce_page_schrack-performance' !== $hook ) { return; }
		wp_enqueue_script( 'schrack-media-maintenance', SCHRACK_WC_SYNC_URL . 'assets/admin-media-maintenance.js', array(), SCHRACK_WC_SYNC_VERSION, true );
		wp_localize_script( 'schrack-media-maintenance', 'schrackMediaMaintenance', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'schrack_media_maintenance' ) ) );
	}

	public function preview_assets(): void {
		if ( ! is_admin() ) { return; }
		wp_enqueue_script( 'schrack-media-previews', SCHRACK_WC_SYNC_URL . 'assets/admin-media-previews.js', array( 'media-views' ), SCHRACK_WC_SYNC_VERSION, true );
		// The grid must not render an original before the preview adapter is installed.
		$scripts = wp_scripts();
		if ( isset( $scripts->registered['media-grid'] ) && ! in_array( 'schrack-media-previews', $scripts->registered['media-grid']->deps, true ) ) {
			$scripts->registered['media-grid']->deps[] = 'schrack-media-previews';
		}
	}

	public function render(): void {
		?>
		<section id="schrack-media-maintenance">
			<h2>Biblioteca Media — verificare și reparare</h2>
			<p>Verifică metadatele, fișierele și miniaturile. Repararea creează numai previzualizări pentru administrare, de cel mult 1024 px. Originalele, identificatorii și imaginile magazinului rămân păstrate. Nu se șterge niciun fișier Media.</p>
			<p>Raportul distinge sursele comune de fișierele identice și arată utilizările cunoscute. Pot exista utilizări în câmpuri personalizate, module, opțiuni sau pagini externe; absența unei utilizări cunoscute nu înseamnă că imaginea poate fi ștearsă.</p>
			<p><button type="button" class="button" data-media-operation="scan">Pornește verificarea</button> <button type="button" class="button button-primary" data-media-operation="repair">Repară previzualizările din raport</button> <button type="button" class="button" data-media-operation="pause">Pauză</button> <button type="button" class="button" data-media-operation="resume">Continuă</button></p>
			<p id="schrack-media-message" role="status" aria-live="polite"></p>
			<pre id="schrack-media-state" style="white-space:pre-wrap" aria-live="polite">Se citește starea…</pre>
			<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Imagine</th><th>Fișier / probleme</th><th>Duplicate posibile</th><th>Utilizări cunoscute</th><th>Reparare</th></tr></thead><tbody id="schrack-media-report"></tbody></table></div>
			<p><button type="button" class="button" id="schrack-media-previous">Pagina anterioară</button> <button type="button" class="button" id="schrack-media-next">Pagina următoare</button></p>
		</section>
		<?php
	}

	private function locked( callable $callback ) {
		global $wpdb;
		$name = 'schrack_media_' . md5( $wpdb->options );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) ) { return null; }
		try { return $callback(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}

	private function save( array $state ): void {
		update_option( self::STATE, $state, false );
		if ( self::status() !== $state ) { throw new RuntimeException( 'Starea nu poate fi salvată.' ); }
	}

	private function schedule( string $id ): void {
		$args = array( $id );
		if ( function_exists( 'as_schedule_single_action' ) && ! as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => $args, 'group' => self::GROUP, 'status' => 'pending', 'per_page' => 1 ), 'ids' ) ) {
			as_schedule_single_action( time() + 2, self::HOOK, $args, self::GROUP, true );
		}
		// Also survives a fatal error or a failed Action Scheduler enqueue.
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) { wp_schedule_single_event( time() + 60, self::HOOK, $args ); }
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( self::HOOK, null, self::GROUP ); }
	}

	private function directory( array $state, bool $create = false ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $state['id'] ?? '' ) ) { throw new RuntimeException( 'Raport invalid.' ); }
		$base = realpath( dirname( rtrim( ABSPATH, '/\\' ) ) );
		if ( ! $base ) { throw new RuntimeException( 'Directorul privat nu este disponibil.' ); }
		$path = $base . '/schrack-media-' . $state['id'];
		foreach ( array( ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH ) as $root ) {
			$root = realpath( $root );
			if ( $root && ( $path === $root || str_starts_with( $path, rtrim( $root, '/' ) . '/' ) ) ) { throw new RuntimeException( 'Raportul necesită un director privat în afara webroot.' ); }
		}
		if ( is_link( $path ) || ( $create && ! is_dir( $path ) && ! mkdir( $path, 0700 ) ) || ! is_dir( $path ) || ! chmod( $path, 0700 ) ) {
			throw new RuntimeException( 'Directorul privat nu poate fi folosit.' );
		}
		return $path;
	}

	private function write_json( string $path, array $value ): void {
		$data = wp_json_encode( $value, JSON_THROW_ON_ERROR );
		$free = disk_free_space( dirname( $path ) );
		if ( false === $free || $free < strlen( $data ) * 2 + 16 * 1024 * 1024 ) { throw new RuntimeException( 'Spațiu insuficient pentru raport sau copie de siguranță.' ); }
		if ( is_link( $path ) ) { throw new RuntimeException( 'Fișier privat invalid.' ); }
		$temp = tempnam( dirname( $path ), '.media-' );
		if ( false === $temp ) { throw new RuntimeException( 'Fișierul privat nu poate fi creat.' ); }
		try {
			if ( ! chmod( $temp, 0600 ) || strlen( $data ) !== file_put_contents( $temp, $data, LOCK_EX ) || ! rename( $temp, $path ) ) { throw new RuntimeException( 'Fișierul privat nu poate fi salvat.' ); }
		} finally { if ( is_file( $temp ) ) { unlink( $temp ); } }
	}

	private function read_json( string $path ): array {
		if ( is_link( $path ) ) { throw new RuntimeException( 'Fișier privat invalid.' ); }
		if ( ! is_file( $path ) ) { return array(); }
		if ( filesize( $path ) > 4 * 1024 * 1024 ) { throw new RuntimeException( 'Fișierul raportului este prea mare.' ); }
		$result = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $result ) ) { throw new RuntimeException( 'Raport invalid.' ); }
		return $result;
	}

	private function image_ids( int $after, int $upper, int $limit = 50 ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type='attachment' AND post_mime_type LIKE 'image/%%' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID LIMIT %d", $after, $upper, $limit ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Imaginile nu pot fi citite.' ); }
		return array_map( 'intval', $ids );
	}

	public function transition( string $operation, string $id = '' ): void {
		$result = $this->locked( function () use ( $operation, $id ): bool {
			global $wpdb;
			$state = self::status();
			if ( 'scan' === $operation ) {
				if ( in_array( $state['status'] ?? '', array( 'running', 'paused' ), true ) ) { throw new RuntimeException( 'Continuă sau încheie procesul existent.' ); }
				$upper = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='attachment'" );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Imaginile nu pot fi citite.' ); }
				$state = array( 'id' => str_replace( '-', '', wp_generate_uuid4() ), 'mode' => 'scan', 'status' => 'running', 'upper' => $upper, 'cursor' => 0, 'scanned' => 0, 'issues' => 0, 'source_copies' => 0, 'identical_copies' => 0, 'repaired' => 0, 'skipped' => 0, 'started_at' => time(), 'message' => '' );
				$this->directory( $state, true );
			} else {
				if ( ! $id || ! hash_equals( (string) ( $state['id'] ?? '' ), $id ) ) { throw new RuntimeException( 'Raportul s-a schimbat. Citește starea actuală.' ); }
				if ( 'repair' === $operation ) {
					if ( 'complete' !== $state['status'] || 'scan' !== $state['mode'] ) { throw new RuntimeException( 'Încheie mai întâi verificarea.' ); }
					$state = array_replace( $state, array( 'mode' => 'repair', 'status' => 'running', 'cursor' => 0, 'repaired' => 0, 'skipped' => 0 ) );
				} elseif ( 'pause' === $operation ) {
					if ( 'running' !== $state['status'] ) { throw new RuntimeException( 'Procesul nu rulează.' ); }
					$state['status'] = 'paused';
				} elseif ( 'resume' === $operation ) {
					if ( ! in_array( $state['status'], array( 'paused', 'error' ), true ) ) { throw new RuntimeException( 'Procesul nu poate fi reluat.' ); }
					$state['status'] = 'running';
				} else { throw new RuntimeException( 'Operațiune invalidă.' ); }
				$this->directory( $state );
			}
			$state['message'] = '';
			$this->save( $state );
			if ( 'running' === $state['status'] ) { $this->schedule( $state['id'] ); }
			return true;
		} );
		if ( null === $result ) { throw new RuntimeException( 'Un lot este în curs. Reîncearcă după actualizarea stării.' ); }
	}

	/** Each repair action handles at most one image; scan actions have a four-second budget. */
	public function work( string $id ): void {
		$this->locked( function () use ( $id ): bool {
			$state = self::status();
			if ( ( $state['id'] ?? '' ) !== $id || 'running' !== ( $state['status'] ?? '' ) ) { return false; }
			$this->schedule( $id );
			$deadline = microtime( true ) + 4;
			try {
				$directory = $this->directory( $state );
				foreach ( $this->image_ids( $state['cursor'], $state['upper'] ) as $attachment_id ) {
					$path = $directory . '/image-' . $attachment_id . '.json';
					if ( 'scan' === $state['mode'] ) {
						$row = $this->audit( $attachment_id );
						foreach ( array( 'source_hash' => 'source_copies', 'file_hash' => 'identical_copies' ) as $key => $counter ) {
							if ( $row[ $key ] ) {
								$index = $directory . '/' . $key . '-' . $row[ $key ] . '.json';
								$members = $this->read_json( $index );
								if ( array_diff( $members, array( $attachment_id ) ) ) { ++$state[ $counter ]; }
								$this->write_json( $index, array_values( array_unique( array_merge( $members, array( $attachment_id ) ) ) ) );
							}
						}
						$this->write_json( $path, $row );
						++$state['scanned'];
						if ( $row['issues'] ) { ++$state['issues']; }
					} else {
						$row = $this->read_json( $path );
						if ( ! $row ) { throw new RuntimeException( 'Raport incomplet. Pornește o verificare nouă.' ); }
						if ( $row['needs_repair'] ) {
							try { $this->repair( $attachment_id, $directory ); $row['repair'] = 'repaired'; ++$state['repaired']; }
							catch ( Throwable $error ) { $row['repair'] = 'skipped'; $row['repair_message'] = $error->getMessage(); ++$state['skipped']; }
							$this->write_json( $path, $row );
						}
					}
					$state['cursor'] = $attachment_id;
					$this->save( $state );
					if ( 'repair' === $state['mode'] && $row['needs_repair'] || microtime( true ) >= $deadline || Schrack_Memory_Guard::is_pressure_high() ) { break; }
				}
				if ( ! $this->image_ids( $state['cursor'], $state['upper'], 1 ) ) {
					$state['status'] = 'complete';
					self::clear_schedule();
				}
				$this->save( $state );
			} catch ( Throwable $error ) {
				$state = self::status();
				$state['status'] = 'error'; $state['message'] = $error->getMessage();
				$this->save( $state );
			}
			return true;
		} );
	}

	/** Only real local files under uploads are accepted, including metadata sub-size paths. */
	private function local_file( string $relative ): string {
		$uploads = wp_upload_dir( null, false );
		$root = empty( $uploads['error'] ) ? realpath( $uploads['basedir'] ?? '' ) : false;
		if ( ! $root || ! $relative || str_contains( $relative, '\\' ) || str_starts_with( $relative, '/' ) || preg_match( '~(?:^|/)\.\.?(?:/|$)~', $relative ) ) { return ''; }
		$path = realpath( $root . '/' . $relative );
		return $path && str_starts_with( $path, $root . '/' ) && is_file( $path ) && is_readable( $path ) ? $path : '';
	}

	private function dimensions( string $path ): array {
		if ( '' === $path ) { return array(); }
		$value = @getimagesize( $path );
		return is_array( $value ) && $value[0] > 0 && $value[1] > 0 ? $value : array();
	}

	private function variant( string $relative ): array {
		$path = $this->local_file( $relative );
		$size = $this->dimensions( $path );
		if ( ! $size || max( $size[0], $size[1] ) > 1024 || filesize( $path ) > 2 * 1024 * 1024 ) { return array(); }
		$uploads = wp_upload_dir( null, false );
		return array( 'url' => trailingslashit( $uploads['baseurl'] ) . $relative, 'width' => $size[0], 'height' => $size[1], 'orientation' => $size[1] > $size[0] ? 'portrait' : 'landscape' );
	}

	public function previews( int $id ): array {
		if ( isset( $this->preview_cache[ $id ] ) ) { return $this->preview_cache[ $id ]; }
		$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
		$original = $this->local_file( $relative );
		$custom = get_post_meta( $id, self::PREVIEWS, true );
		$variants = array();
		if ( is_array( $custom ) && ( $custom['attached'] ?? '' ) === $relative && $original && ( $custom['stamp'] ?? array() ) === array( filesize( $original ), filemtime( $original ) ) ) {
			foreach ( (array) ( $custom['sizes'] ?? array() ) as $size ) {
				if ( is_string( $size ) && $variant = $this->variant( $size ) ) { $variants[] = $variant; }
			}
		}
		$meta = wp_get_attachment_metadata( $id );
		foreach ( (array) ( is_array( $meta ) ? ( $meta['sizes'] ?? array() ) : array() ) as $size ) {
			if ( ! is_array( $size ) || ! is_string( $size['file'] ?? null ) || basename( $size['file'] ) !== $size['file'] || max( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) ) > 1024 ) { continue; }
			$dir = dirname( $relative );
			$variant = $this->variant( ( '.' === $dir ? '' : $dir . '/' ) . $size['file'] );
			if ( $variant ) { $variants[] = $variant; }
		}
		if ( $original && $variant = $this->variant( $relative ) ) { $variants[] = $variant; }
		usort( $variants, static fn( $a, $b ) => max( $a['width'], $a['height'] ) <=> max( $b['width'], $b['height'] ) );
		$placeholder = array( 'url' => SCHRACK_WC_SYNC_URL . 'assets/image-placeholder.svg', 'width' => 300, 'height' => 300, 'orientation' => 'landscape', 'placeholder' => true );
		$small = $placeholder;
		foreach ( $variants as $variant ) { $small = $variant; if ( max( $variant['width'], $variant['height'] ) >= 300 ) { break; } }
		return $this->preview_cache[ $id ] = array( 'small' => $small, 'detail' => $variants ? end( $variants ) : $placeholder );
	}

	public function prepare_attachment( array $response, $attachment, $meta ): array {
		if ( is_admin() && current_user_can( 'upload_files' ) && 'image' === ( $response['type'] ?? '' ) ) {
			$response['schrackMediaPreview'] = $this->previews( (int) $attachment->ID );
		}
		return $response;
	}

	private function is_media_list(): bool {
		return is_admin() && function_exists( 'get_current_screen' ) && 'upload' === ( get_current_screen()->id ?? '' );
	}

	public function list_image( $image, int $id, $size, bool $icon ) {
		if ( ! $this->is_media_list() || 'full' === $size || ! wp_attachment_is_image( $id ) ) { return $image; }
		$preview = $this->previews( $id )['small'];
		return array( $preview['url'], $preview['width'], $preview['height'], true );
	}

	public function list_attributes( array $attributes, $attachment, $size ): array {
		if ( $this->is_media_list() && 'full' !== $size ) { unset( $attributes['srcset'], $attributes['sizes'] ); }
		return $attributes;
	}

	/** Source attribution comes from stored importer data, never a guessed filename. */
	private function source_url( int $id ): string {
		global $wpdb;
		$stored = trim( (string) get_post_meta( $id, '_schrack_image_source_url', true ) );
		if ( $stored ) { return $stored; }
		$urls = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source.meta_value FROM {$wpdb->postmeta} source JOIN {$wpdb->postmeta} ref ON ref.post_id=source.post_id WHERE source.meta_key='_schrack_imported_image_url' AND ref.meta_key IN ('_schrack_image_attachment_id','_thumbnail_id') AND ref.meta_value=%s AND source.meta_value<>'' LIMIT 2", (string) $id ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Sursele imaginilor nu pot fi citite.' ); }
		return 1 === count( $urls ) ? trim( (string) $urls[0] ) : '';
	}

	private function references( int $id, string $relative ): array {
		global $wpdb;
		$uploads = wp_upload_dir( null, false );
		$content = '%wp-image-' . $id . '%';
		$local = $relative ? '%' . $wpdb->esc_like( $relative ) . '%' : '%__schrack_no_file__%';
		// Results are candidates, not a completeness or deletion guarantee.
		$sql = $wpdb->prepare( "SELECT DISTINCT p.ID AS id,p.post_type AS type,p.post_title AS title,'featured/gallery/import' AS context FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE (m.meta_key IN ('_thumbnail_id','_schrack_image_attachment_id') AND m.meta_value=%s) OR (m.meta_key='_product_image_gallery' AND FIND_IN_SET(%s,m.meta_value)) LIMIT 26", (string) $id, (string) $id );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = array_merge( $rows, $wpdb->get_results( $wpdb->prepare( "SELECT ID AS id,post_type AS type,post_title AS title,'content (candidate)' AS context FROM {$wpdb->posts} WHERE post_type<>'attachment' AND (post_content LIKE %s OR post_content LIKE %s OR post_content REGEXP %s) LIMIT 26", $content, $local, '"id"[[:space:]]*:[[:space:]]*' . $id . '([^0-9]|$)' ), ARRAY_A ) );
		$rows = array_merge( $rows, $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT p.ID AS id,p.post_type AS type,p.post_title AS title,'Elementor (candidate)' AS context FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE m.meta_key IN ('_elementor_data','_elementor_page_settings') AND (m.meta_value LIKE %s OR m.meta_value REGEXP %s OR m.meta_value LIKE %s) LIMIT 26", $local, '"id"[[:space:]]*:[[:space:]]*"?' . $id . '([^0-9]|$)', '%i:' . $id . ';%' ), ARRAY_A ) );
		$rows = array_merge( $rows, $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT t.term_id AS id,tt.taxonomy AS type,t.name AS title,'category' AS context FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id JOIN {$wpdb->termmeta} m ON m.term_id=t.term_id WHERE m.meta_key='thumbnail_id' AND m.meta_value=%s LIMIT 26", (string) $id ), ARRAY_A ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Utilizările nu pot fi citite.' ); }
		return array( 'known' => array_slice( $rows, 0, 100 ), 'unknown' => true, 'limited' => count( $rows ) > 100 || count( array_filter( $rows, static fn( $r ) => 'content (candidate)' === $r['context'] ) ) >= 26 );
	}

	private function audit( int $id ): array {
		$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
		$file = $this->local_file( $relative );
		$dimensions = $this->dimensions( $file );
		$meta = wp_get_attachment_metadata( $id );
		$preview = $this->previews( $id );
		$issues = array();
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) { $issues[] = 'Metadate lipsă/incomplete'; }
		if ( ! $file ) { $issues[] = 'Fișier lipsă sau inaccesibil'; }
		elseif ( ! $dimensions ) { $issues[] = 'Fișier cu antet de imagine invalid'; }
		if ( ! empty( $preview['small']['placeholder'] ) ) { $issues[] = 'Miniatură validă lipsă'; }
		if ( $dimensions && max( $dimensions[0], $dimensions[1] ) > 4096 ) { $issues[] = 'Original peste 4096 px'; }
		$source = $this->source_url( $id );
		$hash = $file && filesize( $file ) <= 64 * 1024 * 1024 ? hash_file( 'sha256', $file ) : '';
		if ( ! $hash ) { $issues[] = 'Hash neverificat'; }
		$needs = $file && $dimensions && ( ! empty( $preview['small']['placeholder'] ) || max( $preview['detail']['width'], $preview['detail']['height'] ) < min( 1024, max( $dimensions[0], $dimensions[1] ) ) );
		return array( 'id' => $id, 'title' => (string) get_post_field( 'post_title', $id ), 'file' => $relative, 'bytes' => $file ? filesize( $file ) : 0, 'dimensions' => $dimensions ? array( $dimensions[0], $dimensions[1] ) : array(), 'issues' => $issues, 'source_hash' => $source ? hash( 'sha256', $source ) : '', 'file_hash' => $hash ?: '', 'references' => $this->references( $id, $relative ), 'needs_repair' => (bool) $needs, 'repair' => $needs ? 'pending' : 'not_needed' );
	}

	/** Conservative allocation budget, without raising PHP's hosting limit. */
	private function can_decode( array $size ): bool {
		$limit = Schrack_Memory_Guard::limit_bytes() ?: 256 * 1024 * 1024;
		return max( $size[0], $size[1] ) <= 4096 && memory_get_usage( true ) + $size[0] * $size[1] * 12 + 32 * 1024 * 1024 < $limit * 0.8;
	}

	public function repair( int $id, string $directory ): void {
		$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
		$original = $this->local_file( $relative );
		$dimensions = $this->dimensions( $original );
		if ( ! $dimensions ) { throw new RuntimeException( 'Originalul lipsește sau nu poate fi citit.' ); }
		$stamp = array( filesize( $original ), filemtime( $original ) );
		$backup = $directory . '/backup-' . $id . '.json';
		if ( ! is_file( $backup ) ) { $this->write_json( $backup, array( 'id' => $id, 'attached_file' => $relative, 'attachment_metadata' => get_post_meta( $id, '_wp_attachment_metadata', false ), 'admin_previews' => get_post_meta( $id, self::PREVIEWS, false ) ) ); }
		if ( ( $this->read_json( $backup )['attached_file'] ?? '' ) !== $relative ) { throw new RuntimeException( 'Originalul s-a schimbat față de copia de siguranță.' ); }
		if ( ! function_exists( 'wp_get_image_editor' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
		$input = $original;
		$temporary = '';
		$generated = array();
		$published = false;
		try {
			if ( ! $this->can_decode( $dimensions ) ) {
				$source = Schrack_Product_Hero_Cache::source( $this->source_url( $id ) );
				if ( ! $source ) { throw new RuntimeException( 'Original prea mare / memorie insuficientă; sursă Schrack verificată indisponibilă.' ); }
				$temporary = wp_tempnam( 'schrack-media.jpg' );
				if ( ! $temporary ) { throw new RuntimeException( 'Fișier temporar indisponibil.' ); }
				$response = wp_safe_remote_get( $source, array( 'timeout' => 15, 'redirection' => 0, 'cookies' => array(), 'stream' => true, 'filename' => $temporary, 'limit_response_size' => 1048576 ) );
				$size = $this->dimensions( $temporary );
				if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! $size || 1190 !== $size[0] || 1330 !== $size[1] || IMAGETYPE_JPEG !== $size[2] || filesize( $temporary ) >= 1048576 ) { throw new RuntimeException( 'CDN indisponibil sau imagine CDN invalidă.' ); }
				if ( ! $this->can_decode( $size ) ) { throw new RuntimeException( 'Memorie insuficientă pentru previzualizare.' ); }
				$input = $temporary;
			}
			$uploads = wp_upload_dir( null, false );
			$root = realpath( $uploads['basedir'] );
			$target = $root . '/schrack-admin-previews';
			if ( is_link( $target ) || ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) || realpath( $target ) !== $target ) { throw new RuntimeException( 'Directorul previzualizărilor nu poate fi folosit.' ); }
			$key = hash( 'sha256', $relative . ':' . implode( ':', $stamp ) . ':' . wp_generate_uuid4() );
			$sizes = array();
			foreach ( array( 'thumbnail' => 150, 'medium' => 300, 'detail' => 1024 ) as $name => $edge ) {
				$editor = wp_get_image_editor( $input );
				if ( is_wp_error( $editor ) ) { throw new RuntimeException( 'Conversia imaginii a eșuat.' ); }
				$current_size = $editor->get_size();
				if ( max( $current_size['width'], $current_size['height'] ) > $edge && is_wp_error( $editor->resize( $edge, $edge, false ) ) ) { throw new RuntimeException( 'Redimensionarea imaginii a eșuat.' ); }
				if ( is_wp_error( $editor->set_quality( 80 ) ) ) { throw new RuntimeException( 'Calitatea imaginii nu poate fi setată.' ); }
				$path = $target . '/' . $id . '-' . $key . '-' . $edge . '.jpg';
				if ( is_link( $path ) ) { throw new RuntimeException( 'Fișier de previzualizare invalid.' ); }
				$generated[] = $path;
				$saved = $editor->save( $path, 'image/jpeg' );
				unset( $editor );
				$preview_relative = 'schrack-admin-previews/' . basename( $path );
				if ( is_wp_error( $saved ) || ( $saved['path'] ?? '' ) !== $path || ! $this->variant( $preview_relative ) ) { throw new RuntimeException( 'Previzualizarea nu poate fi verificată.' ); }
				$sizes[ $name ] = $preview_relative;
			}
			clearstatcache( true, $original );
			if ( (string) get_post_meta( $id, '_wp_attached_file', true ) !== $relative || array( filesize( $original ), filemtime( $original ) ) !== $stamp ) { throw new RuntimeException( 'Originalul s-a schimbat în timpul reparării.' ); }
			$value = array( 'attached' => $relative, 'stamp' => $stamp, 'sizes' => $sizes );
			update_post_meta( $id, self::PREVIEWS, $value );
			if ( get_post_meta( $id, self::PREVIEWS, true ) !== $value ) { throw new RuntimeException( 'Metadatele previzualizării nu pot fi salvate.' ); }
			unset( $this->preview_cache[ $id ] );
			$published = true;
		} finally {
			if ( $temporary && is_file( $temporary ) ) { unlink( $temporary ); }
			// Clean only unpublished files created by this attempt, never attachment originals.
			if ( ! $published ) { foreach ( $generated as $path ) { if ( is_file( $path ) && ! is_link( $path ) ) { unlink( $path ); } } }
		}
	}

	public function view( int $before = 0 ): array {
		global $wpdb;
		$state = self::status();
		if ( ! $state ) { return array( 'state' => array(), 'rows' => array(), 'next' => 0 ); }
		$directory = $this->directory( $state );
		$upper = 'scan' === $state['mode'] ? $state['cursor'] : $state['upper'];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID<=%d AND ID<%d AND post_type='attachment' AND post_mime_type LIKE 'image/%%' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID DESC LIMIT 21", $upper, $before ?: $upper + 1 ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Raportul nu poate fi citit.' ); }
		$rows = array();
		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			$row = $this->read_json( $directory . '/image-' . (int) $id . '.json' );
			if ( ! $row ) { continue; }
			foreach ( array( 'source_hash', 'file_hash' ) as $hash ) {
				$members = $row[ $hash ] ? $this->read_json( $directory . '/' . $hash . '-' . $row[ $hash ] . '.json' ) : array();
				$row[ $hash . '_matches' ] = array_slice( array_values( array_diff( $members, array( (int) $id ) ) ), 0, 25 );
			}
			$row['edit_url'] = get_edit_post_link( (int) $id, 'raw' );
			unset( $row['source_hash'], $row['file_hash'] );
			$rows[] = $row;
		}
		return array( 'state' => $state, 'rows' => $rows, 'next' => count( $ids ) > 20 ? (int) $ids[19] : 0 );
	}

	public function ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => 'Acces refuzat.' ), 403 ); }
		check_ajax_referer( 'schrack_media_maintenance', 'nonce' );
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? 'status' ) );
		try {
			if ( 'status' !== $operation ) { $this->transition( $operation, sanitize_key( wp_unslash( $_POST['job_id'] ?? '' ) ) ); }
			wp_send_json_success( $this->view( absint( $_POST['before'] ?? 0 ) ) );
		} catch ( Throwable $error ) { wp_send_json_error( array( 'message' => $error->getMessage() ), 400 ); }
	}
}
