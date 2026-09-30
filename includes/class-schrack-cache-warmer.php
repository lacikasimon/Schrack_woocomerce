<?php
/** Bounded anonymous page warming, with durable progress and a WP admin interface. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schrack_Cache_Warmer {
	public const OPTION = 'schrack_cache_warmer';
	public const STATE = 'schrack_cache_warmer_state';
	public const TICK = 'schrack_cache_warm_tick';
	public const CYCLE = 'schrack_cache_warm_cycle';
	public const REWARM = 'schrack_cache_rewarm';
	public const LIMIT = 100;
	public const BATCH_REQUESTS = 50;
	public const BATCH_COLD_PAGES = 5;
	public const BATCH_SECONDS = 15;
	public const CATALOG_BATCH = 100;

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
			<p>Preîncălzește lista prioritară și, opțional, toate produsele publice în stoc, în loturi de cel mult 50 de cereri succesive, cu cel mult 5 pagini fără HIT pe lot. Produsele fără stoc și cele în precomandă sunt omise, inclusiv din lista prioritară. Bugetul de 15 secunde este verificat între cereri; o cerere poate dura cel mult 20 de secunde. Pagina principală și magazinul au prioritate. Nu trimite cookie-uri. Rulează în fundal și după închiderea acestei pagini.</p>
			<p>Necesită cache de pagină activ și WP-Cron funcțional. Pentru pornire regulată folosește un cron al găzduirii. Un catalog mare poate necesita ore sau zile; starea se păstrează între loturi. Paginile personalizate nu sunt preîncălzite.</p>
			<form id="schrack-cache-form">
				<p><label><input type="checkbox" name="enabled" <?php checked( $config['enabled'] ); ?>> Preîncălzire automată la fiecare oră și după golirea cache-ului de pagini</label></p>
				<p><label><input type="checkbox" name="discover" <?php checked( $config['discover'] ); ?>> Preîncălzește toate produsele publice în stoc și adaugă 24 de categorii principale</label></p>
				<p><label for="schrack-cache-urls">URL-uri publice: pagina principală, magazin, categorii sau produse publicate (unul pe rând)</label></p>
				<textarea id="schrack-cache-urls" name="urls" rows="9" class="large-text code" maxlength="30000"><?php echo esc_textarea( implode( "\n", $config['urls'] ) ); ?></textarea>
				<p><button type="submit" class="button button-primary">Salvează</button> <button type="button" class="button" data-command="start">Pornește acum</button> <button type="button" class="button" data-command="stop">Oprește și dezactivează automatizarea</button></p>
			</form>
			<p id="schrack-cache-message" role="status" aria-live="polite"></p>
			<h2>Starea preîncălzirii</h2><p id="schrack-cache-status" role="status">Se încarcă…</p>
			<table class="widefat striped"><thead><tr><th>URL</th><th>HTTP</th><th>Cache / verificare</th><th>Durată totală</th></tr></thead><tbody id="schrack-cache-results"></tbody></table>
			<h2>Măsurare PHP / bază de date</h2>
			<p>O singură cerere anonimă fără cache. Rezultatul este privat; nu se păstrează SQL, cookie-uri sau date de clienți.</p>
			<label for="schrack-profile-url">Pagina de măsurat</label> <select id="schrack-profile-url"><?php foreach ( $config['urls'] as $url ) { ?><option value="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $url ); ?></option><?php } ?></select>
			<button type="button" class="button" data-command="profile">Măsoară</button>
			<button type="button" class="button" data-command="profile_cold">Măsoară selecțiile la rece</button>
			<p>Testul la rece ocolește și cache-ul selecțiilor de produse, imagini și al agregatelor de catalog, fără să golească memoria cache a magazinului.</p>
			<pre id="schrack-profile-result" style="white-space:pre-wrap" aria-live="polite"></pre>
		</div>
		<?php
	}

	public function config(): array {
		$config = get_option( self::OPTION, array() );
		return array( 'enabled' => ! empty( $config['enabled'] ), 'discover' => ! empty( $config['discover'] ), 'urls' => array_slice( (array) ( $config['urls'] ?? array( home_url( '/' ) ) ), 0, self::LIMIT ) );
	}

	/** A bounded queue from WordPress APIs; manual selections retain priority. */
	public function queue_urls(): array {
		$config = $this->config();
		$candidates = array( home_url( '/' ) );
		$shop_id = (int) wc_get_page_id( 'shop' );
		if ( $shop_id > 0 ) { $candidates[] = get_permalink( $shop_id ); }
		$candidates = array_merge( $candidates, $config['urls'] );
		if ( $config['discover'] ) {
			$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 24 ) );
			foreach ( is_array( $categories ) ? $categories : array() as $term ) {
				$url = get_term_link( $term );
				if ( ! is_wp_error( $url ) ) { $candidates[] = $url; }
			}
		}
		$urls = array();
		foreach ( array_unique( $candidates ) as $url ) {
			if ( is_string( $url ) && $this->public_url( $url, true ) ) { $urls[] = $url; }
			if ( count( $urls ) >= self::LIMIT + 26 ) { break; }
		}
		return $urls;
	}

	/** Reject aliases, query actions, credentials, external/private and hidden pages. */
	public function public_url( string $url, bool $in_stock_only = false ): string {
		$url = trim( $url );
		$p = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $p ) || ! in_array( $p['scheme'] ?? '', array( 'http', 'https' ), true )
			|| ( $p['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' ) || ( $p['host'] ?? '' ) !== ( $home['host'] ?? '' )
			|| ( $p['port'] ?? null ) !== ( $home['port'] ?? null )
			|| isset( $p['query'] ) || isset( $p['fragment'] ) || isset( $p['user'] ) || isset( $p['pass'] )
			|| preg_match( '/[\\\\\x00-\x20]/', $url ) || ! wp_http_validate_url( $url ) ) { return ''; }
		if ( $url === home_url( '/' ) ) { return $url; }
		// WooCommerce's shop is a product archive, so url_to_postid() can return
		// zero even though its configured published page has this exact permalink.
		$shop_id = (int) wc_get_page_id( 'shop' );
		if ( $shop_id > 0 && get_permalink( $shop_id ) === $url ) {
			$shop = get_post( $shop_id );
			return $shop && 'page' === $shop->post_type && 'publish' === $shop->post_status && '' === $shop->post_password ? $url : '';
		}
		$id = url_to_postid( $url );
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) { return ''; }
			if ( 'product' === $post->post_type ) {
				$product = wc_get_product( $id );
				if ( ! $product || ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true ) ) { return ''; }
				if ( $in_stock_only && 'instock' !== $product->get_stock_status() ) { return ''; }
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
		$urls = $this->queue_urls();
		if ( ! $urls ) { return; }
		update_option( self::STATE, array( 'status' => 'running', 'urls' => $urls, 'cursor' => 0, 'results' => array(), 'failures' => 0, 'started' => time(), 'updated' => time(), 'phase' => 'priority', 'catalog' => $this->config()['discover'], 'product_after' => 0, 'processed' => 0, 'products_processed' => 0, 'products_scanned' => 0, 'confirmed' => 0, 'unconfirmed' => 0 ), false );
		$this->schedule_tick( 10 );
	}

	public function cycle(): void {
		if ( ! $this->config()['enabled'] ) { return; }
		$this->locked( function (): void { $this->start(); } );
	}

	/** Coalesce import purge bursts, restarting priority pages within a five-minute cooldown. */
	public function after_purge(): void {
		if ( ! $this->config()['enabled'] ) { return; }
		// Scheduling does not mutate the worker cursor and must survive a busy lock.
		$state = get_option( self::STATE, array() );
		if ( ! wp_next_scheduled( self::REWARM ) ) {
			wp_schedule_single_event( max( time() + 60, (int) ( $state['priority_refreshed'] ?? $state['started'] ?? 0 ) + 300 ), self::REWARM );
		}
	}

	/** Remember purges during an active run, including URLs visited before the purge. */
	public function rewarm(): void {
		if ( ! $this->config()['enabled'] ) { return; }
		$result = $this->locked( function () {
			$state = get_option( self::STATE, array() );
			if ( 'running' === ( $state['status'] ?? '' ) ) {
				if ( empty( $state['catalog'] ) ) { return false; }
				$state['priority_pending'] = $this->queue_urls();
				$state['repeat_catalog'] = true;
				$state['priority_refreshed'] = time();
				update_option( self::STATE, $state, false );
				return true;
			}
			$this->start();
			return true;
		} );
		if ( true !== $result && ! wp_next_scheduled( self::REWARM ) ) { wp_schedule_single_event( time() + 120, self::REWARM ); }
	}

	/** Scan published in-stock product IDs in durable keyset pages. */
	private function next_urls( array &$state ): void {
		if ( isset( $state['verify_index'] ) || (int) $state['cursor'] < count( $state['urls'] ) ) { return; }
		if ( isset( $state['resume'] ) ) {
			foreach ( $state['resume'] as $key => $value ) { $state[ $key ] = $value; }
			unset( $state['resume'] );
			if ( (int) $state['cursor'] < count( $state['urls'] ) ) { return; }
		}
		if ( empty( $state['catalog'] ) ) { $state['status'] = 'complete'; return; }
		global $wpdb;
		$lookup = $wpdb->wc_product_meta_lookup ?? $wpdb->prefix . 'wc_product_meta_lookup';
		$state['phase'] = 'products';
		// Bound empty/hidden pages too: continue in the next scheduled job if needed.
		for ( $page = 0; $page < 3; ++$page ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'
				AND post_password = '' AND ID > %d
				AND EXISTS (SELECT 1 FROM {$lookup} AS warm_stock
					WHERE warm_stock.product_id = {$wpdb->posts}.ID AND warm_stock.stock_status = 'instock')
				ORDER BY ID ASC LIMIT %d",
				(int) $state['product_after'], self::CATALOG_BATCH
			) );
			if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) { $state['status'] = 'error'; return; }
			if ( ! $ids ) {
				if ( ! empty( $state['repeat_catalog'] ) ) {
					$state['product_after'] = 0; unset( $state['repeat_catalog'] ); continue;
				}
				$state['status'] = 'complete'; return;
			}
			$state['product_after'] = max( array_map( 'intval', $ids ) );
			$state['products_scanned'] = (int) $state['products_scanned'] + count( $ids );
			$state['urls'] = array(); $state['cursor'] = 0;
			foreach ( $ids as $id ) {
				$url = get_permalink( (int) $id );
				if ( is_string( $url ) && $this->public_url( $url, true ) ) { $state['urls'][] = $url; }
			}
			if ( $state['urls'] ) { return; }
		}
	}

	public function tick(): void {
		$result = $this->locked( function (): void {
			$state = get_option( self::STATE, array() );
			if ( 'running' !== ( $state['status'] ?? '' ) ) { return; }
			$this->schedule_tick( 180 ); // Watchdog survives process death.
			$batch_start = microtime( true );
			$cold_pages = 0;
			if ( ! empty( $state['priority_pending'] ) && ! isset( $state['verify_index'] ) ) {
				if ( ! isset( $state['resume'] ) ) { $state['resume'] = array_intersect_key( $state, array_flip( array( 'urls', 'cursor', 'phase' ) ) ); }
				$state['urls'] = $state['priority_pending']; $state['cursor'] = 0; $state['phase'] = 'priority_refresh';
				unset( $state['priority_pending'] );
			}
			for ( $request = 0; $request < self::BATCH_REQUESTS; ++$request ) {
				$state['attempts'] = (int) ( $state['attempts'] ?? 0 ) + 1;
				if ( $state['attempts'] > 3 ) { $state['status'] = 'error'; break; }
				update_option( self::STATE, $state, false );
				$this->next_urls( $state );
				if ( 'running' !== $state['status'] || ( ! isset( $state['verify_index'] ) && ! $state['urls'] ) ) { $state['attempts'] = 0; break; }
				update_option( self::STATE, $state, false );
				$verifying = isset( $state['verify_index'] );
				$index = $verifying ? (int) $state['verify_index'] : (int) $state['cursor'];
				$raw_url = $verifying ? ( $state['verify_url'] ?? $state['urls'][ $index ] ?? '' ) : ( $state['urls'][ $index ] ?? '' );
				$url = $this->public_url( (string) $raw_url, true );
				if ( ! $url ) {
					// Publication, visibility or stock can change after the queue was saved.
					// Skip without an HTTP request or an error that stops the catalogue run.
					if ( $verifying ) {
						$state['results'][ $index ]['cache'] = 'OMIS';
						$state['results'][ $index ]['verified'] = false;
						$state['unconfirmed'] = (int) ( $state['unconfirmed'] ?? 0 ) + 1;
						unset( $state['verify_index'], $state['verify_url'] );
					} else { ++$state['cursor']; }
					$state['attempts'] = 0; $state['updated'] = time();
					update_option( self::STATE, $state, false );
					if ( microtime( true ) - $batch_start >= self::BATCH_SECONDS ) { break; }
					continue;
				}
				$start = microtime( true );
				$response = wp_safe_remote_get( $url, array(
					'timeout' => 20, 'redirection' => 0, 'cookies' => array(), 'limit_response_size' => 2097152,
					'user-agent' => 'Mozilla/5.0 (compatible; SchrackCacheWarm/1.1)',
				) );
				$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
				$cache = is_wp_error( $response ) ? '' : strtolower( (string) wp_remote_retrieve_header( $response, 'x-litespeed-cache' ) );
				$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
				if ( $verifying ) {
					$state['results'][ $index ]['verified'] = 200 === $code && 'hit' === $cache;
					$state['results'][ $index ]['verify_http'] = $code;
					$state['results'][ $index ]['verify_ms'] = $ms;
					$key = $state['results'][ $index ]['verified'] ? 'confirmed' : 'unconfirmed';
					$state[ $key ] = (int) ( $state[ $key ] ?? 0 ) + 1;
					unset( $state['verify_index'], $state['verify_url'] ); // Exactly one confirmation request.
				} else {
					if ( 'hit' !== $cache ) { ++$cold_pages; }
					$state['results'][] = array( 'url' => $raw_url, 'http' => $code,
						'cache' => in_array( $cache, array( 'hit', 'miss' ), true ) ? strtoupper( $cache ) : 'NECONFIRMAT',
						'ms' => $ms, 'verified' => 200 === $code && 'hit' === $cache ? true : null,
					);
					$state['cursor']++;
					$state['processed'] = (int) ( $state['processed'] ?? 0 ) + 1;
					if ( 200 !== $code || 'miss' !== $cache ) {
						$key = 200 === $code && 'hit' === $cache ? 'confirmed' : 'unconfirmed';
						$state[ $key ] = (int) ( $state[ $key ] ?? 0 ) + 1;
					}
					if ( 'products' === ( $state['phase'] ?? '' ) ) { ++$state['products_processed']; }
					if ( 200 === $code && 'miss' === $cache ) {
						$state['verify_index'] = count( $state['results'] ) - 1; $state['verify_url'] = $raw_url;
					}
				}
				$state['failures'] = 200 === $code ? 0 : (int) $state['failures'] + 1;
				$state['attempts'] = 0;
				$state['updated'] = time();
				if ( $state['failures'] >= 3 ) { $state['status'] = 'error'; }
				elseif ( $state['cursor'] >= count( $state['urls'] ) && ! isset( $state['verify_index'] ) && empty( $state['catalog'] ) ) { $state['status'] = 'complete'; }
				if ( count( $state['results'] ) > 100 ) {
					$state['results'] = array_slice( $state['results'], -100 );
					if ( isset( $state['verify_index'] ) ) { $state['verify_index'] = 99; }
				}
				update_option( self::STATE, $state, false );
				// Never issue concurrent requests. Slow/error responses end this batch.
				if ( 'running' !== $state['status'] || 200 !== $code || $ms >= 5000 || microtime( true ) - $batch_start >= self::BATCH_SECONDS
					|| ( $cold_pages >= self::BATCH_COLD_PAGES && ! isset( $state['verify_index'] ) ) ) { break; }
			}
			update_option( self::STATE, $state, false );
			wp_clear_scheduled_hook( self::TICK );
			if ( 'running' === $state['status'] ) { $this->schedule_tick( $state['failures'] ? 300 : 60 ); }
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
				if ( ! is_string( $raw ) || strlen( $raw ) > 30000 ) { return new WP_Error( 'urls', 'Lista este prea lungă.' ); }
				$urls = array_values( array_unique( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) ) );
				if ( ! $urls || count( $urls ) > self::LIMIT ) { return new WP_Error( 'urls', 'Introdu între 1 și 100 de URL-uri.' ); }
				foreach ( $urls as $url ) { if ( ! $this->public_url( $url ) ) { return new WP_Error( 'urls', 'URL nepermis sau necanonic: ' . $url ); } }
				$enabled = '1' === ( $_POST['enabled'] ?? '' );
				update_option( self::OPTION, array( 'enabled' => $enabled, 'discover' => '1' === ( $_POST['discover'] ?? '' ), 'urls' => $urls ), false );
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
			} elseif ( in_array( $command, array( 'profile', 'profile_cold' ), true ) ) {
				$url = wp_unslash( $_POST['url'] ?? '' );
				if ( ! is_string( $url ) || ! in_array( $url, $this->config()['urls'], true ) || ! $this->public_url( $url ) ) { return new WP_Error( 'url', 'Alege o pagină salvată și publică.' ); }
				if ( get_transient( 'schrack_profile_cooldown' ) ) { return new WP_Error( 'busy', 'Așteaptă un minut între măsurări.' ); }
				set_transient( 'schrack_profile_cooldown', 1, 60 );
				return array( 'measurement' => Schrack_Page_Profile::measure( $url, 'profile_cold' === $command ) );
			} else { return new WP_Error( 'command', 'Comandă necunoscută.' ); }
			return $this->status();
		} );
		if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message(), 400 ); }
		wp_send_json_success( $result );
	}
}
