<?php
/** Admin operations that do not require a shell on the hosting account. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Schrack_Performance_Tools {
	private const SNAPSHOT = 'schrack_performance_settings_snapshot';
	public function init(): void {
		if ( get_option( 'schrack_external_cron_verified', false ) && ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
		add_action( 'schrack_performance_tools', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_schrack_performance_tools', array( $this, 'ajax' ) );
	}
	public function assets( string $hook ): void {
		if ( 'woocommerce_page_schrack-performance' !== $hook ) { return; }
		wp_enqueue_script( 'schrack-performance-tools', SCHRACK_WC_SYNC_URL . 'assets/admin-performance-tools.js', array(), SCHRACK_WC_SYNC_VERSION, true );
		wp_localize_script( 'schrack-performance-tools', 'schrackPerformanceTools', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'schrack_performance_tools' ) ) );
	}
	public function render(): void {
		?>
		<section id="schrack-performance-tools">
			<h2>Răspuns rapid al serverului</h2>
			<p>Profilul reduce jurnalizarea la „info”, dezactivează diagnosticul detaliat și limitează importul la 2 lucrători de catalog și 1 de imagini. Setările anterioare pot fi restabilite. Nu schimbă prețurile, stocurile sau autentificarea furnizorilor.</p>
			<p><button type="button" class="button button-primary" data-operation="apply">Aplică profilul de performanță</button> <button type="button" class="button" data-operation="rollback">Restabilește setările anterioare</button> <button type="button" class="button" data-operation="index">Pornește / continuă indexul de căutare</button></p>
			<p>Indexul se construiește în fundal, în loturi scurte. Căutarea standard rămâne activă până când întregul catalog și modificările recente sunt indexate.</p>
			<p>După verificarea unui cron al găzduirii care accesează wp-cron.php cel puțin o dată pe minut: <button type="button" class="button" data-operation="cron_external">Cron hosting verificat: dezactivează pornirea la vizite</button> <button type="button" class="button" data-operation="cron_visits">Reactivează WP-Cron la vizite</button></p>
			<h3>Arhivă privată a jurnalelor</h3>
			<p>Arhivează reversibil mesajele debug/info mai vechi de 30 de zile și warning/error mai vechi de 90 de zile. Fiecare lot este comprimat, verificat și salvat în afara webroot înainte de eliminarea din tabel. Retenția se verifică zilnic după pornire. Arhiva nu este publică și nu este ștearsă automat. Oprirea sau restaurarea suspendă retenția automată. Tabelul păstrează ID-urile; nu se folosește TRUNCATE sau OPTIMIZE.</p>
			<p><button type="button" class="button" data-operation="archive">Pornește / continuă arhivarea</button> <button type="button" class="button" data-operation="archive_stop">Oprește arhivarea</button> <button type="button" class="button" data-operation="restore">Restabilește jurnalele din arhivă</button></p>
			<p id="schrack-performance-message" role="status" aria-live="polite"></p>
			<pre id="schrack-performance-state" style="white-space:pre-wrap" aria-live="polite">Se citește starea…</pre>
			<p><button type="button" class="button" data-operation="seo_audit">Verifică utilizarea metadatelor SEO</button></p>
			<pre id="schrack-seo-audit" style="white-space:pre-wrap" aria-live="polite"></pre>
		</section>
		<?php
	}
	private function settings_profile( bool $restore ): void {
		$keys = array( 'log_level', 'debug_enabled', 'catalog_parallel_workers', 'image_parallel_workers' );
		$current = get_option( Schrack_Settings::OPTION_NAME, array() );
		if ( $restore ) {
			$previous = get_option( self::SNAPSHOT, array() );
			if ( ! $previous ) { throw new RuntimeException( 'Nu există setări salvate pentru restaurare.' ); }
			$current = array_replace( $current, array_intersect_key( $previous, array_flip( $keys ) ) );
		} else {
			add_option( self::SNAPSHOT, array_intersect_key( $current + Schrack_Settings::defaults(), array_flip( $keys ) ), '', false );
			$current = array_replace( $current, array( 'log_level' => 'info', 'debug_enabled' => 'no', 'catalog_parallel_workers' => 2, 'image_parallel_workers' => 1 ) );
		}
		update_option( Schrack_Settings::OPTION_NAME, $current, false );
		update_option( 'schrack_background_profile', ! $restore, false );
	}
	private function status(): array {
		$options = get_option( Schrack_Settings::OPTION_NAME, array() );
		$index = get_option( Schrack_Search_Index::STATE, array() );
		if ( ! empty( $index['ready'] ) && ! Schrack_Search_Index::ready() ) { $index['status'] = 'running'; $index['ready'] = false; }
		return array( 'visit_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'settings' => array_intersect_key( $options, array_flip( array( 'log_level', 'debug_enabled', 'catalog_parallel_workers', 'image_parallel_workers' ) ) ), 'search_index' => $index, 'log_archive' => Schrack_Log_Archive::status() );
	}
	private function seo_audit(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,COUNT(*) AS entries,COUNT(DISTINCT post_id) AS posts FROM {$wpdb->postmeta} WHERE (meta_key LIKE %s OR meta_key LIKE %s) AND meta_value<>'' GROUP BY meta_key ORDER BY meta_key", $wpdb->esc_like( '_yoast_wpseo_' ) . '%', $wpdb->esc_like( '_siteseo_' ) . '%' ), ARRAY_A );
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,COUNT(*) AS entries FROM {$wpdb->termmeta} WHERE (meta_key LIKE %s OR meta_key LIKE %s) AND meta_value<>'' GROUP BY meta_key ORDER BY meta_key", $wpdb->esc_like( '_yoast_wpseo_' ) . '%', $wpdb->esc_like( '_siteseo_' ) . '%' ), ARRAY_A );
		$types = $wpdb->get_results( $wpdb->prepare( "SELECT post_type,post_status,COUNT(*) AS entries FROM {$wpdb->posts} WHERE post_type LIKE %s GROUP BY post_type,post_status", '%redirect%' ), ARRAY_A );
		$legacy = get_option( 'wpseo_taxonomy_meta', array() ); $legacy_count = 0;
		foreach ( (array) $legacy as $values ) { $legacy_count += is_array( $values ) ? count( $values ) : 0; }
		return array( 'post_metadata' => $rows, 'term_metadata' => $terms, 'redirects' => $types, 'yoast_legacy_terms' => $legacy_count, 'siteseo_options_keys' => array_keys( (array) get_option( 'siteseo_titles_option_name', array() ) ) );
	}
	public function ajax(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Acces refuzat.', 403 ); }
		check_ajax_referer( 'schrack_performance_tools', 'nonce' );
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? 'status' ) );
		try {
			switch ( $operation ) {
				case 'apply': $this->settings_profile( false ); ( new Schrack_Search_Index() )->start(); break;
				case 'rollback': $this->settings_profile( true ); break;
				case 'cron_external': update_option( 'schrack_external_cron_verified', true, false ); break;
				case 'cron_visits': update_option( 'schrack_external_cron_verified', false, false ); break;
				case 'index': ( new Schrack_Search_Index() )->start(); break;
				case 'archive': ( new Schrack_Log_Archive() )->start(); break;
				case 'archive_stop': ( new Schrack_Log_Archive() )->stop(); break;
				case 'restore': ( new Schrack_Log_Archive() )->start( true ); break;
				case 'seo_audit': wp_send_json_success( array( 'seo_audit' => $this->seo_audit() ) ); break;
				case 'status': break;
				default: throw new RuntimeException( 'Operațiune necunoscută.' );
			}
			wp_send_json_success( $this->status() );
		} catch ( Throwable $e ) { wp_send_json_error( $e->getMessage(), 400 ); }
	}
}
