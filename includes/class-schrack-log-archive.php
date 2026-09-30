<?php
/** Durable, private, reversible log retention in small background batches. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Schrack_Log_Archive {
	public const STATE = 'schrack_log_archive_state';
	public const TICK = 'schrack_log_archive_tick';
	private const CYCLE = 'schrack_log_archive_cycle';
	public function init(): void {
		add_action( 'admin_init', array( $this, 'ensure_schedule' ) );
		add_action( self::TICK, array( $this, 'tick' ) );
		add_action( self::CYCLE, array( $this, 'cycle' ) );
	}
	public static function clear_schedule(): void { wp_clear_scheduled_hook( self::TICK ); wp_clear_scheduled_hook( self::CYCLE ); }
	public function ensure_schedule(): void {
		$state = get_option( self::STATE, array() );
		if ( ! empty( $state['enabled'] ) && ! wp_next_scheduled( self::CYCLE ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CYCLE ); }
		if ( 'running' === ( $state['status'] ?? '' ) ) { $this->schedule(); }
	}
	public function cycle(): void {
		$state = get_option( self::STATE, array() );
		if ( ! empty( $state['enabled'] ) && 'complete' === ( $state['status'] ?? '' ) ) { $this->start(); }
	}

	public function start( bool $restore = false ): void {
		$this->locked( function() use ( $restore ): void {
			global $wpdb;
			$state = get_option( self::STATE, array() );
			if ( $restore ) {
				if ( ! $state || 'archive' === ( $state['mode'] ?? '' ) && 'running' === ( $state['status'] ?? '' ) ) { throw new RuntimeException( 'Oprește arhivarea înainte de restaurare.' ); }
				$this->directory( $state );
				wp_clear_scheduled_hook( self::CYCLE );
				$state['enabled'] = false; $state['mode'] = 'restore'; $state['restore_cursor'] = ''; $state['restored'] = 0;
			} elseif ( ! $state ) {
				$id = str_replace( '-', '', wp_generate_uuid4() );
				$base = realpath( dirname( rtrim( ABSPATH, '/\\' ) ) );
				if ( ! $base || ! $this->outside( $base ) || ! is_writable( $base ) ) { throw new RuntimeException( 'Este necesar un director privat permanent, inscriptibil, în afara webroot.' ); }
				$directory = $base . '/schrack-log-archive-' . $id;
				if ( ! mkdir( $directory, 0700 ) || ! chmod( $directory, 0700 ) ) { throw new RuntimeException( 'Directorul privat nu poate fi creat.' ); }
				$table = Schrack_Logger::table_name();
				$engine = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $table ), ARRAY_A );
				if ( 'InnoDB' !== ( $engine['Engine'] ?? '' ) ) { throw new RuntimeException( 'Arhivarea reversibilă necesită tabel InnoDB.' ); }
				$upper = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Jurnalul nu poate fi citit.' ); }
				$state = array( 'id' => $id, 'directory' => $directory, 'upper' => $upper, 'cursor' => 0, 'archived' => 0, 'segments' => 0, 'mode' => 'archive', 'cutoff' => wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'errors_cutoff' => wp_date( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) );
			} elseif ( 'restore' === ( $state['mode'] ?? '' ) ) {
				throw new RuntimeException( 'Arhiva restaurată este păstrată. Nu se re-arhivează automat.' );
			}
			if ( ! $restore ) {
				$state['enabled'] = true;
				if ( 'complete' === ( $state['status'] ?? '' ) ) {
					$table = Schrack_Logger::table_name();
					$state['upper'] = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
					if ( $wpdb->last_error ) { throw new RuntimeException( 'Jurnalul nu poate fi citit.' ); }
					$state['cursor'] = 0; $state['cutoff'] = wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ); $state['errors_cutoff'] = wp_date( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
				}
				if ( ! wp_next_scheduled( self::CYCLE ) ) { wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CYCLE ); }
			}
			$state['status'] = 'running'; unset( $state['error'] );
			update_option( self::STATE, $state, false ); $this->schedule();
		} );
	}
	public function stop(): void {
		$this->locked( static function(): void {
			$state = get_option( self::STATE, array() );
			if ( ! $state ) { return; }
			$state['status'] = 'stopped'; $state['enabled'] = false; wp_clear_scheduled_hook( self::CYCLE ); update_option( self::STATE, $state, false ); wp_clear_scheduled_hook( self::TICK );
		} );
	}
	private function schedule(): void { if ( ! wp_next_scheduled( self::TICK ) ) { wp_schedule_single_event( time() + 30, self::TICK ); } }
	private function locked( callable $work ): void {
		global $wpdb; $name = 'schrack_archive_' . md5( $wpdb->options );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) ) ) { throw new RuntimeException( 'Un lot este în curs. Reîncearcă mai târziu.' ); }
		try { $work(); } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}
	private function outside( string $path ): bool {
		foreach ( array( ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH ) as $root ) {
			$root = realpath( $root );
			if ( $root && ( $path === $root || str_starts_with( $path, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) ) { return false; }
		}
		return true;
	}
	private function directory( array $state ): string {
		$path = $state['directory'] ?? ''; $real = realpath( $path );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $state['id'] ?? '' ) || ! $real || is_link( $path ) || basename( $real ) !== 'schrack-log-archive-' . $state['id'] || ! $this->outside( $real ) || ! chmod( $real, 0700 ) ) { throw new RuntimeException( 'Arhiva privată nu este disponibilă.' ); }
		return $real;
	}
	/** Authenticate each segment before any database mutation. */
	private function read( string $directory, string $name ): array {
		if ( ! preg_match( '/^batch-[0-9]+-[0-9]+\.json\.gz$/', $name ) ) { throw new RuntimeException( 'Segment invalid.' ); }
		$path = $directory . '/' . $name;
		if ( is_link( $path ) || is_link( $path . '.sha256' ) || ! is_file( $path ) ) { throw new RuntimeException( 'Segment indisponibil.' ); }
		$hash = trim( (string) file_get_contents( $path . '.sha256' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) { throw new RuntimeException( 'Verificarea arhivei a eșuat; datele nu sunt șterse.' ); }
		$rows = json_decode( gzdecode( file_get_contents( $path ) ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $rows ) || ! $rows || count( $rows ) > 500 ) { throw new RuntimeException( 'Segment invalid.' ); }
		$keys = array( 'id', 'created_at', 'level', 'operation', 'sku', 'message', 'context' );
		foreach ( $rows as $row ) { if ( ! is_array( $row ) || array_diff( $keys, array_keys( $row ) ) || array_diff( array_keys( $row ), $keys ) || (int) $row['id'] < 1 ) { throw new RuntimeException( 'Rând invalid în arhivă.' ); } }
		return $rows;
	}
	private function save_file( string $path, string $data ): void {
		$temp = $path . '.tmp';
		if ( is_link( $temp ) || is_link( $path ) ) { throw new RuntimeException( 'Fișier invalid.' ); }
		$handle = fopen( $temp, 'wb' );
		if ( ! $handle || ! chmod( $temp, 0600 ) ) { throw new RuntimeException( 'Arhiva nu poate fi scrisă.' ); }
		try {
			$offset = 0; $length = strlen( $data );
			while ( $offset < $length ) { $written = fwrite( $handle, substr( $data, $offset ) ); if ( ! $written ) { throw new RuntimeException( 'Scriere incompletă.' ); } $offset += $written; }
			if ( ! fflush( $handle ) || function_exists( 'fsync' ) && ! fsync( $handle ) ) { throw new RuntimeException( 'Arhiva nu poate fi confirmată.' ); }
		} finally { fclose( $handle ); }
		if ( ! rename( $temp, $path ) ) { throw new RuntimeException( 'Arhiva nu poate fi publicată în directorul privat.' ); }
	}
	public function tick(): void {
		try { $this->locked( function(): void {
			global $wpdb;
			$state = get_option( self::STATE, array() );
			if ( 'running' !== ( $state['status'] ?? '' ) ) { return; }
			$dir = $this->directory( $state ); $table = Schrack_Logger::table_name();
			if ( 'restore' === $state['mode'] ) {
				$files = array_map( 'basename', glob( $dir . '/batch-*.json.gz' ) ?: array() ); sort( $files, SORT_STRING );
				$next = null; foreach ( $files as $file ) { if ( strcmp( $file, $state['restore_cursor'] ) > 0 ) { $next = $file; break; } }
				if ( null === $next ) { $state['status'] = 'complete'; }
				else {
					$rows = $this->read( $dir, $next );
					foreach ( $rows as $row ) {
						$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $row['id'] ), ARRAY_A );
						if ( $wpdb->last_error ) { throw new RuntimeException( 'Restaurarea nu poate fi verificată.' ); }
						if ( $existing && $existing !== $row ) { throw new RuntimeException( 'ID de jurnal reutilizat; restaurarea s-a oprit fără suprascriere.' ); }
						if ( ! $existing && false === $wpdb->insert( $table, $row ) ) { throw new RuntimeException( 'Restaurarea a eșuat.' ); }
					}
					$state['restored'] += count( $rows ); $state['restore_cursor'] = $next;
				}
			} else {
				if ( empty( $state['pending'] ) ) {
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id>%d AND id<=%d AND ((level IN ('debug','info') AND created_at<%s) OR (level IN ('warning','error') AND created_at<%s)) ORDER BY id LIMIT 500", $state['cursor'], $state['upper'], $state['cutoff'], $state['errors_cutoff'] ), ARRAY_A );
					if ( $wpdb->last_error ) { throw new RuntimeException( 'Jurnalul nu poate fi citit.' ); }
					if ( ! $rows ) { $state['status'] = 'complete'; }
					else {
						$name = 'batch-' . $rows[0]['id'] . '-' . end( $rows )['id'] . '.json.gz';
						$data = gzencode( wp_json_encode( $rows, JSON_THROW_ON_ERROR ), 6 );
						$free = disk_free_space( $dir );
						if ( false === $free || $free < strlen( $data ) * 2 + 32 * 1024 * 1024 ) { throw new RuntimeException( 'Spațiu insuficient pentru arhivă; nu se șterg date.' ); }
						$this->save_file( $dir . '/' . $name, $data ); $this->save_file( $dir . '/' . $name . '.sha256', hash( 'sha256', $data ) );
						$state['pending'] = $name; update_option( self::STATE, $state, false );
						wp_cache_delete( self::STATE, 'options' );
						if ( ( get_option( self::STATE, array() )['pending'] ?? '' ) !== $name ) { throw new RuntimeException( 'Progresul arhivei nu poate fi salvat.' ); }
					}
				}
				if ( ! empty( $state['pending'] ) ) {
					$rows = $this->read( $dir, $state['pending'] );
					$ids = array_map( 'intval', array_column( $rows, 'id' ) );
					// Lock and compare original rows as well as the durable archive, then delete.
					$set = implode( ',', $ids );
					if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new RuntimeException( 'Tranzacția nu poate începe.' ); }
					try {
						$current = $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN ({$set}) FOR UPDATE", ARRAY_A );
						if ( $wpdb->last_error ) { throw new RuntimeException( 'Jurnalul nu poate fi verificat.' ); }
						$original = array_column( $rows, null, 'id' );
						foreach ( $current as $row ) { if ( $row !== $original[ $row['id'] ] ) { throw new RuntimeException( 'Jurnal modificat; arhivarea s-a oprit.' ); } }
						if ( false === $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$set})" ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Retenția jurnalului a eșuat.' ); }
					} catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); throw $e; }
					$state['cursor'] = max( $ids ); $state['archived'] += count( $rows ); ++$state['segments']; unset( $state['pending'] );
				}
			}
			update_option( self::STATE, $state, false ); if ( 'running' === $state['status'] ) { $this->schedule(); }
		} ); } catch ( Throwable $e ) {
			$state = get_option( self::STATE, array() );
			// Lock contention is transient, not a reason to overwrite another worker.
			if ( 'Un lot este în curs. Reîncearcă mai târziu.' === $e->getMessage() ) { $this->schedule(); return; }
			$state['status'] = 'error'; $state['error'] = $e->getMessage(); update_option( self::STATE, $state, false );
		}
	}
	public static function status(): array {
		$state = get_option( self::STATE, array() );
		return array_intersect_key( $state, array_flip( array( 'enabled', 'status', 'mode', 'archived', 'segments', 'restored', 'error' ) ) );
	}
}
