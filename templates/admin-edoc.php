<?php
/** @var array $config */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tax_lines = array();
foreach ( $config['tax_map'] as $rate => $class ) { $tax_lines[] = $rate . '=' . $class; }
?>
<div class="wrap" data-testid="edoc-settings">
	<h1>Integrare eDoc ERP</h1>
	<p>eDoc este al treilea furnizor. Importă articolele selectate în Gestiune și transmite toate comenzile către ERP. Comenzile nu sunt trimise furnizorilor prin SOAP.</p>
	<?php if ( is_array( $notice ) ) : ?><div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div><?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="schrack_edoc_settings">
		<?php wp_nonce_field( 'schrack_edoc_settings' ); ?>
		<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row">Sincronizare eDoc</th><td><label><input data-testid="edoc-enabled" type="checkbox" name="edoc_enabled" value="1" <?php checked( $config['enabled'] ); ?>> Activă</label><p class="description">Catalog la 5 minute; comenzile în coadă imediat după salvare; reconciliere la 15 minute.</p></td></tr>
			<tr><th scope="row"><label for="edoc_url">URL ERP</label></th><td><input class="regular-text" id="edoc_url" data-testid="edoc-url" name="edoc_url" type="url" value="<?php echo esc_attr( $config['url'] ); ?>" placeholder="https://erp.exemplu.ro"><p class="description">Originea ERP, fără /api/webshop/v1.</p></td></tr>
			<tr><th scope="row"><label for="edoc_key_id">Identificator conexiune</label></th><td><input class="regular-text" id="edoc_key_id" data-testid="edoc-key" name="edoc_key_id" value="<?php echo esc_attr( $config['key_id'] ); ?>" autocomplete="off"></td></tr>
			<tr><th scope="row"><label for="edoc_secret">Secret partajat</label></th><td><input class="regular-text" id="edoc_secret" data-testid="edoc-secret" name="edoc_secret" type="password" value="" autocomplete="new-password"><p class="description"><?php echo $config['secret'] ? 'Secret configurat. Lăsați gol pentru a-l păstra.' : 'Copiați secretul afișat o singură dată la configurarea ERP.'; ?></p></td></tr>
			<tr><th scope="row"><label for="edoc_tax_map">Mapare TVA → clasă WooCommerce</label></th><td><textarea id="edoc_tax_map" data-testid="edoc-tax-map" name="edoc_tax_map" rows="5" class="large-text code" placeholder="21=&#10;11=redusa&#10;0=zero-rate"><?php echo esc_textarea( implode( "\n", $tax_lines ) ); ?></textarea><p class="description">O linie pentru fiecare cotă ERP. După „=” introduceți slug-ul clasei; gol înseamnă clasa Standard. Cota clasei pentru țara magazinului trebuie să corespundă. Fără mapare sau preț net pozitiv, produsul rămâne draft și indisponibil.</p></td></tr>
		</tbody></table>
		<?php submit_button( 'Salvează conexiunea', 'primary', 'submit', true, array( 'data-testid' => 'edoc-save' ) ); ?>
	</form>
	<h2>Sincronizare</h2>
	<p>Produsele noi sunt draft. Descrierile, imaginile și categoriile se completează în magazin. Disponibilitatea ERP nu rezervă și nu descarcă stoc.</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="schrack_edoc_action"><?php wp_nonce_field( 'schrack_edoc_action' ); ?>
		<button class="button" name="edoc_action" value="test" data-testid="edoc-test">Testează conexiunea</button>
		<button class="button" name="edoc_action" value="catalog" data-testid="edoc-catalog">Importă catalogul eDoc</button>
		<button class="button" name="edoc_action" value="history" data-testid="edoc-history">Retrimite întregul istoric</button>
		<button class="button" name="edoc_action" value="retry" data-testid="edoc-retry">Reia livrările eșuate</button>
	</form>
	<table class="widefat striped" style="margin-top:20px"><tbody>
		<tr><th>Comenzi în așteptare</th><td data-testid="edoc-pending"><?php echo (int) $pending; ?></td></tr>
		<tr><th>Ultima livrare reușită (UTC)</th><td><?php echo esc_html( get_option( 'schrack_edoc_last_delivery', '—' ) ); ?></td></tr>
		<tr><th>Import comenzi</th><td><?php echo esc_html( 'idle' === ( $scan['mode'] ?? '' ) ? 'Istoric parcurs; reconciliere periodică activă.' : 'În lucru, pagina ' . (int) ( $scan['page'] ?? 1 ) ); ?></td></tr>
		<tr><th>Catalog</th><td><?php echo esc_html( ( $status['last_run'] ?? '—' ) . ' · ' . (int) ( $status['processed'] ?? 0 ) . ' articole procesate; ' . (int) ( $status['blocked'] ?? 0 ) . ' blocate la validare' ); ?></td></tr>
		<?php if ( ! empty( $status['last_error'] ) ) : ?><tr><th>Eroare catalog</th><td><?php echo esc_html( $status['last_error'] ); ?></td></tr><?php endif; ?>
		<?php foreach ( array( 'schrack_edoc_capture_error', 'schrack_edoc_worker_error' ) as $error_key ) : if ( get_option( $error_key ) ) : ?><tr><th>Atenție</th><td><?php echo esc_html( get_option( $error_key ) ); ?></td></tr><?php endif; endforeach; ?>
	</tbody></table>
	<?php if ( $failed ) : ?><h2>Livrări care necesită reluare</h2><table class="widefat striped"><thead><tr><th>Comandă</th><th>Încercări</th><th>Mesaj</th></tr></thead><tbody><?php foreach ( $failed as $row ) : ?><tr><td><?php echo (int) $row['order_id']; ?></td><td><?php echo (int) $row['attempts']; ?></td><td><?php echo esc_html( $row['last_error'] ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
	<p class="description">Action Scheduler este preferat; WP-Cron este fallback. Pentru execuție regulată configurați cron-ul serverului să ruleze WordPress în fiecare minut.</p>
</div>
