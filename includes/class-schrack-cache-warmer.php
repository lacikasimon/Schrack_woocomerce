<?php
/** Bounded anonymous page warming, with durable progress and a WP admin interface. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_Cache_Warmer {
	public const OPTION = 'schrack_cache_warmer';
	public const STATE = 'schrack_cache_warmer_state';
	public const TICK = 'schrack_cache_warm_tick';
	public const CYCLE = 'schrack_cache_warm_cycle';
	public const REWARM = 'schrack_cache_rewarm';
	public const LIMIT = 20;

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_schrack_cache_warmer', array( $this, 'ajax' ) );
		add_action( 'admin_init', array( $this, 'ensure_schedule' ) );
		add_action( self::CYCLE, array( $this, 'cycle' ) );
		add_action( self::TICK, array( $this, 'tick' ) );
		add_action( self::REWARM, array( $this, 'rewarm' ) );
		add_action( 'litespeed_purged_all_lscache', array( $this, 'after_purge' ) );
		add_action( 'schrack_catalog_pages_purged', array( $this, 'after_purge' ) );
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', 'Performanță magazin', 'Performanță magazin', 'manage_woocommerce', 'schrack-performance', array( $this, 'page' ) );
	}

	public function assets( string $hook ): void {
		if ( 'woocommerce_page_schrack-performance' !== $hook ) { return; }
		wp_enqueue_script( 'schrack-cache-warmer', SCHRACK_WC_SYNC_URL . 'assets/admin-cache-warmer.js', array(), SCHRACK_WC_SYNC_VERSION, true );
		wp_localize_script( 'schrack-cache-warmer', 'schrackCacheWarmer', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'schrack_cache_warmer' ) ) );
	}

	public function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$config = $this->config();
		?>
		<div class="wrap" id="schrack-cache-warmer">
			<h1>Performanță magazin</h1>
			<?php if ( Schrack_Cache_Invalidation::is_active() ) { ?><p><strong>Protecția cache-ului tehnic este activă:</strong> modificările de categorii și atribute invalidează paginile, păstrând cache-ul CSS/JS, Redis și OPcache.</p><?php } ?>
			<p>Preîncălzește maximum 20 de pagini publice, câte una pe minut, fără cookie-uri. Rulează în fundal și după închiderea acestei pagini.</p>
			<p>Necesită cache de pagină activ și WP-Cron funcțional. Pentru ore exacte folosește un cron al găzduirii. Nu accelerează paginile personalizate sau paginile care nu sunt în listă.</p>
			<form id="schrack-cache-form">
				<p><label><input type="checkbox" name="enabled" <?php checked( $config['enabled'] ); ?>> Preîncălzire automată la fiecare oră și după golirea cache-ului de pagini</label></p>
				<p><label for="schrack-cache-urls">URL-uri publice: pagina principală, magazin, categorii sau produse publicate (unul pe rând)</label></p>
				<textarea id="schrack-cache-urls" name="urls" rows="9" class="large-text code" maxlength="20000"><?php echo esc_textarea( implode( "\n", $config['urls'] ) ); ?></textarea>
				<p><button type="submit" class="button button-primary">Salvează</button> <button type="button" class="button" data-command="start">Pornește acum</button> <button type="button" class="button" data-command="stop">Oprește și dezactivează automatizarea</button></p>
			</form>
			<p id="schrack-cache-message" role="status" aria-live="polite"></p>
			<h2>Starea preîncălzirii</h2><p id="schrack-cache-status" role="status">Se încarcă…</p>
			<table class="widefat striped"><thead><tr><th>URL</th><th>HTTP</th><th>Cache</th><th>Durată totală</th></tr></thead><tbody id="schrack-cache-results"></tbody></table>
			<h2>Măsurare PHP / bază de date</h2>
			<p>O singură cerere anonimă fără cache. Rezultatul este privat; nu se păstrează SQL, cookie-uri sau date de clienți.</p>
			<label for="schrack-profile-url">Pagina de măsurat</label> <select id="schrack-profile-url"><?php foreach ( $config['urls'] as $url ) { ?><option value="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $url ); ?></option><?php } ?></select>
			<button type="button" class="button" data-command="profile">Măsoară</button>
			<pre id="schrack-profile-result" style="white-space:pre-wrap" aria-live="polite"></pre>
		</div>
		<?php
	}

	public function config(): array {
		$config = get_option( self::OPTION, array() );
		return array( 'enabled' => ! empty( $config['enabled'] ), 'urls' => array_slice( (array) ( $config['urls'] ?? array( home_url( '/' ) ) ), 0, self::LIMIT ) );
	}

	/** Reject aliases, query actions, credentials, external/private and hidden pages. */
	public function public_url( string $url ): string {
		$url = trim( $url );
		$p = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $p ) || ! in_array( $p['scheme'] ?? '', array( 'http', 'https' ), true )
			|| ( $p['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' ) || ( $p['host'] ?? '' ) !== ( $home['host'] ?? '' )
			|| ( $p['port'] ?? null ) !== ( $home['port'] ?? null )
			|| isset( $p['query'] ) || isset( $p['fragment'] ) || isset( $p['user'] ) || isset( $p['pass'] )
			|| preg_match( '/[\\\\\x00-\x20]/', $url ) || ! wp_http_validate_url( $url ) ) { return ''; }
		if ( $url === home_url( '/' ) ) { return $url; }
		$id = url_to_postid( $url );
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) { return ''; }
			if ( 'product' === $post->post_type ) {
				$product = wc_get_product( $id );
				if ( ! $product || ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog' ), true ) ) { return ''; }
			} elseif ( $id !== wc_get_page_id( 'shop' ) ) { return ''; }
			return get_permalink( $id ) === $url ? $url : '';
		}
		$slug = rawurldecode( basename( untrailingslashit( $p['path'] ?? '' ) ) );
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		return $term && get_term_link( $term ) === $url ? $url : '';
	}

	/** Connection-level lock is released on process death and shared by UI/workers. */
	private function locked( callable $callback ) {
		global $wpdb;
		$name = 'schrack_warm_' . md5( $wpdb->options );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
			return new WP_Error( 'busy', 'O cerere este în curs. Reîncearcă peste câteva secunde.' );
		}
		try { return $callback(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}

	public function ensure_schedule(): void {
		if ( $this->config()['enabled'] && ! wp_next_scheduled( self::CYCLE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CYCLE );
		}
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::CYCLE );
		wp_clear_scheduled_hook( self::TICK );
		wp_clear_scheduled_hook( self::REWARM );
	}

	private function schedule_tick( int $delay ): void {
		wp_clear_scheduled_hook( self::TICK );
		wp_schedule_single_event( time() + $delay, self::TICK );
	}

	private function start(): void {
		$state = get_option( self::STATE, array() );
		if ( 'running' === ( $state['status'] ?? '' ) ) {
			if ( ! wp_next_scheduled( self::TICK ) ) { $this->schedule_tick( 60 ); }
			return;
		}
		$urls = $this->config()['urls'];
		if ( ! $urls ) { return; }
		update_option( self::STATE, array( 'status' => 'running', 'urls' => $urls, 'cursor' => 0, 'results' => array(), 'failures' => 0, 'started' => time(), 'updated' => time() ), false );
		$this->schedule_tick( 10 );
	}

	public function cycle(): void {
		if ( ! $this->config()['enabled'] ) { return; }
		$this->locked( function (): void { $this->start(); } );
	}

	/** Coalesce import purge bursts: no new warmup more often than every 15 minutes. */
	public function after_purge(): void {
		if ( ! $this->config()['enabled'] ) { return; }
		$this->locked( function (): void {
			$state = get_option( self::STATE, array() );
			if ( ! wp_next_scheduled( self::REWARM ) ) {
				wp_schedule_single_event( max( time() + 120, (int) ( $state['started'] ?? 0 ) + 900 ), self::REWARM );
			}
		} );
	}

	/** Remember purges during an active run, including URLs visited before the purge. */
	public function rewarm(): void {
		if ( ! $this->config()['enabled'] ) { return; }
		$result = $this->locked( function () {
			$state = get_option( self::STATE, array() );
			if ( 'running' === ( $state['status'] ?? '' ) ) { return false; }
			$this->start();
			return true;
		} );
		if ( true !== $result && ! wp_next_scheduled( self::REWARM ) ) { wp_schedule_single_event( time() + 120, self::REWARM ); }
	}

	public function tick(): void {
		$result = $this->locked( function (): void {
			$state = get_option( self::STATE, array() );
			if ( 'running' !== ( $state['status'] ?? '' ) ) { return; }
			// A watchdog survives timeouts/fatal errors; ordinary completion replaces it.
			$this->schedule_tick( 180 );
			$index = (int) $state['cursor'];
			$state['attempts'] = (int) ( $state['attempts'] ?? 0 ) + 1;
			if ( $state['attempts'] > 3 ) {
				$state['status'] = 'error';
				update_option( self::STATE, $state, false );
				wp_clear_scheduled_hook( self::TICK );
				return;
			}
			update_option( self::STATE, $state, false );
			$url = $this->public_url( (string) ( $state['urls'][ $index ] ?? '' ) );
			$start = microtime( true );
			$response = $url ? wp_safe_remote_get( $url, array(
				'timeout' => 20, 'redirection' => 0, 'cookies' => array(), 'limit_response_size' => 2097152,
				'user-agent' => 'Mozilla/5.0 (compatible; SchrackCacheWarm/1.0)',
			) ) : new WP_Error( 'not_public', 'Pagina nu mai este publică.' );
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$cache = is_wp_error( $response ) ? '' : strtolower( (string) wp_remote_retrieve_header( $response, 'x-litespeed-cache' ) );
			$state['results'][] = array( 'url' => $state['urls'][ $index ], 'http' => $code,
				'cache' => in_array( $cache, array( 'hit', 'miss' ), true ) ? strtoupper( $cache ) : 'NECONFIRMAT',
				'ms' => round( ( microtime( true ) - $start ) * 1000 ),
			);
			$state['failures'] = 200 === $code ? 0 : $state['failures'] + 1;
			$state['cursor']++;
			$state['attempts'] = 0;
			$state['updated'] = time();
			if ( $state['failures'] >= 3 ) { $state['status'] = 'error'; }
			elseif ( $state['cursor'] >= count( $state['urls'] ) ) { $state['status'] = 'complete'; }
			update_option( self::STATE, $state, false );
			wp_clear_scheduled_hook( self::TICK );
			if ( 'running' === $state['status'] ) { $this->schedule_tick( 200 === $code ? 60 : 300 ); }
		} );
		if ( is_wp_error( $result ) && ! wp_next_scheduled( self::TICK ) ) { wp_schedule_single_event( time() + 180, self::TICK ); }
	}

	public function status(): array {
		return array( 'state' => get_option( self::STATE, array( 'status' => 'idle' ) ), 'config' => $this->config(), 'next' => wp_next_scheduled( self::TICK ), 'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	public function ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Acces interzis.', 403 ); }
		check_ajax_referer( 'schrack_cache_warmer', 'nonce' );
		$command = sanitize_key( wp_unslash( $_POST['command'] ?? '' ) );
		if ( 'status' === $command ) { wp_send_json_success( $this->status() ); }
		$result = $this->locked( function () use ( $command ) {
			if ( 'save' === $command ) {
				$raw = wp_unslash( $_POST['urls'] ?? '' );
				if ( ! is_string( $raw ) || strlen( $raw ) > 20000 ) { return new WP_Error( 'urls', 'Lista este prea lungă.' ); }
				$urls = array_values( array_unique( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) ) );
				if ( ! $urls || count( $urls ) > self::LIMIT ) { return new WP_Error( 'urls', 'Introdu între 1 și 20 de URL-uri.' ); }
				foreach ( $urls as $url ) { if ( ! $this->public_url( $url ) ) { return new WP_Error( 'urls', 'URL nepermis sau necanonic: ' . $url ); } }
				$enabled = '1' === ( $_POST['enabled'] ?? '' );
				update_option( self::OPTION, array( 'enabled' => $enabled, 'urls' => $urls ), false );
				self::clear_schedule();
				update_option( self::STATE, array( 'status' => 'idle' ), false );
				if ( $enabled ) { $this->ensure_schedule(); $this->start(); }
			} elseif ( 'start' === $command ) {
				$this->start();
			} elseif ( 'stop' === $command ) {
				$config = $this->config(); $config['enabled'] = false;
				update_option( self::OPTION, $config, false );
				self::clear_schedule();
				$state = get_option( self::STATE, array() ); $state['status'] = 'stopped';
				update_option( self::STATE, $state, false );
			} elseif ( 'profile' === $command ) {
				$url = wp_unslash( $_POST['url'] ?? '' );
				if ( ! is_string( $url ) || ! in_array( $url, $this->config()['urls'], true ) || ! $this->public_url( $url ) ) { return new WP_Error( 'url', 'Alege o pagină salvată și publică.' ); }
				if ( get_transient( 'schrack_profile_cooldown' ) ) { return new WP_Error( 'busy', 'Așteaptă un minut între măsurări.' ); }
				set_transient( 'schrack_profile_cooldown', 1, 60 );
				return array( 'measurement' => Schrack_Page_Profile::measure( $url ) );
			} else { return new WP_Error( 'command', 'Comandă necunoscută.' ); }
			return $this->status();
		} );
		if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message(), 400 ); }
		wp_send_json_success( $result );
	}
}
