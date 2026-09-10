<?php
/** WooCommerce admin: preview, run, resume and download without SSH. */
defined( 'ABSPATH' ) || exit;
$active = 'running' === $view['state'];
$form = static function ( string $operation, string $label, bool $primary = false ) use ( $view ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 8px 8px 0">
		<input type="hidden" name="action" value="schrack_attribute_merge">
		<input type="hidden" name="operation" value="<?php echo esc_attr( $operation ); ?>">
		<input type="hidden" name="job" value="<?php echo esc_attr( $view['id'] ); ?>">
		<?php wp_nonce_field( 'schrack_attribute_merge' ); ?>
		<button type="submit" class="button <?php echo $primary ? 'button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
$download_link = static function ( string $id ): string {
	return wp_nonce_url( add_query_arg( array( 'action' => 'schrack_attribute_merge_download', 'job' => $id ), admin_url( 'admin-post.php' ) ), 'schrack_attribute_merge_download_' . $id );
};
?>
<div class="wrap" style="max-width:1100px">
	<h1>Unificare atribute</h1>
	<p>Reunește atributele globale cu același nume. Pentru fiecare produs se păstrează prima valoare completată, în ordinea coloanelor din export. Valorile goale sunt ignorate; „0” rămâne o valoare validă.</p>
	<?php if ( $notice ) : ?><div class="notice notice-error"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
	<?php if ( 'error' === $view['state'] ) : ?>
		<div class="notice notice-error"><p><strong>Procesul s-a oprit.</strong> <?php echo esc_html( $view['message'] ); ?></p><p>Progresul este salvat. După rezolvarea cauzei, folosește „Reia procesarea”.</p></div>
	<?php endif; ?>
	<?php if ( 'paused' === $view['state'] ) : ?>
		<div class="notice notice-warning"><p>Procesul este oprit temporar. Poate fi reluat de la progresul salvat. Importurile rămân suspendate dacă unificarea a fost pornită.</p></div>
	<?php endif; ?>
	<?php if ( 'complete' === $view['state'] ) : ?>
		<div class="notice notice-success"><p>Unificarea s-a încheiat. Produsele și filtrele au fost actualizate. Copia de siguranță poate fi descărcată mai jos.</p></div>
	<?php elseif ( 'ready' === $view['state'] ) : ?>
		<div class="notice notice-info"><p><?php echo $view['groups'] ? 'Previzualizarea este gata. Verifică grupurile și diferențele de valori înainte de pornire.' : 'Nu s-au găsit atribute globale cu nume duplicate.'; ?></p></div>
	<?php endif; ?>
	<div class="card" style="max-width:none;padding:20px;margin:20px 0">
		<h2 style="margin-top:0"><?php echo $active ? 'Procesare în curs' : 'Analiză și unificare'; ?></h2>
		<p id="schrack-merge-phase" role="status" aria-live="polite"><?php echo esc_html( $view['phase'] ); ?></p>
		<dl style="display:flex;gap:40px;flex-wrap:wrap">
			<div><dt>Grupuri duplicate</dt><dd id="schrack-merge-groups" style="margin:8px 0;font-size:24px"><?php echo (int) $view['groups']; ?></dd></div>
			<div><dt><?php echo 'complete' === $view['state'] ? 'Produse actualizate' : 'Produse de actualizat'; ?></dt><dd id="schrack-merge-products" style="margin:8px 0;font-size:24px"><?php echo (int) $view['products']; ?></dd></div>
			<div><dt>Valori diferite</dt><dd id="schrack-merge-conflicts" style="margin:8px 0;font-size:24px"><?php echo (int) $view['conflicts']; ?></dd></div>
		</dl>
		<?php if ( $active ) : ?>
			<p>Procesarea continuă în fundal. Această pagină afișează automat progresul și ajută procesarea dacă programatorul găzduirii întârzie.</p>
			<p id="schrack-merge-connection" aria-live="polite"></p>
			<?php $form( 'pause', 'Oprește temporar' ); $form( 'resume', 'Reia procesarea' ); ?>
		<?php elseif ( in_array( $view['state'], array( 'error', 'paused' ), true ) ) : ?>
			<?php $form( 'resume', 'Reia procesarea', true ); ?>
		<?php endif; ?>
		<?php if ( ! $active && ( ! Schrack_Attribute_Merge_Job::blocks_imports() || empty( $state['mutation_started'] ) ) ) { $form( 'preview', 'Previzualizare', 'idle' === $view['state'] ); } ?>
		<?php if ( 'ready' === $view['state'] && $view['groups'] ) { $form( 'apply', 'Pornește unificarea', true ); } ?>
		<?php if ( $view['backup_complete'] ) : ?>
			<a class="button" href="<?php echo esc_url( $download_link( $view['id'] ) ); ?>">Descarcă copia de siguranță</a>
		<?php endif; ?>
	</div>
	<p><strong>La pornire:</strong> importurile furnizorilor sunt suspendate pe durata unificării. Așteaptă finalizarea transferurilor CSV și evită editarea produselor, atributelor și categoriilor până la finalizare. Înainte de orice modificare se creează automat o copie SQL a atributelor și a datelor de taxonomie asociate, inclusiv categoriile și etichetele. Comenzile, prețurile și stocurile nu fac parte din această copie.</p>
	<p>Fiecare produs este actualizat într-o tranzacție. Produsele cu atribute de variație afectate sunt semnalate înainte de modificări. O listă cu mai multe valori din prima coloană este păstrată integral.</p>
	<?php if ( ! empty( $state['groups'] ) ) : ?>
		<h2><?php echo 'complete' === $view['state'] ? 'Atribute unificate' : 'Atribute care vor fi unificate'; ?></h2>
		<table class="widefat striped"><thead><tr><th>Nume</th><th>Se păstrează</th><th>Se reunesc</th></tr></thead><tbody>
		<?php foreach ( $state['groups'] as $group ) : ?>
			<tr><td><?php echo esc_html( $group[0]['attribute_label'] ); ?></td><td><code><?php echo esc_html( 'pa_' . $group[0]['attribute_name'] ); ?></code></td><td style="overflow-wrap:anywhere"><?php echo esc_html( implode( ', ', array_map( static fn ( array $definition ): string => 'pa_' . $definition['attribute_name'], array_slice( $group, 1 ) ) ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>
	<?php if ( ! empty( $state['samples'] ) ) : ?>
		<h2>Diferențe de valori</h2><p>Se afișează cel mult 100 de diferențe. Valoarea din coloana „Se păstrează” va fi utilizată.</p>
		<table class="widefat striped"><thead><tr><th>Produs</th><th>Atribut</th><th>Se păstrează</th><th>Valori existente</th></tr></thead><tbody>
		<?php foreach ( $state['samples'] as $sample ) : ?>
			<tr><td><a href="<?php echo esc_url( get_edit_post_link( $sample['product_id'], 'raw' ) ); ?>">#<?php echo (int) $sample['product_id']; ?></a></td><td><?php echo esc_html( $sample['label'] ); ?></td><td><?php echo esc_html( implode( ' | ', $sample['values'] ) ); ?></td><td><?php foreach ( $sample['sources'] as $source ) { echo '<div>' . esc_html( $source['name'] . ': ' . implode( ' | ', $source['values'] ) ) . '</div>'; } ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endif; ?>
	<?php $history = (array) get_option( 'schrack_wc_attribute_merge_backups', array() ); if ( $history ) : ?>
		<h2>Copii de siguranță anterioare</h2><ul>
		<?php foreach ( array_reverse( $history, true ) as $id => $backup ) : ?>
			<li><a href="<?php echo esc_url( $download_link( $id ) ); ?>"><?php echo esc_html( wp_date( 'Y-m-d H:i', $backup['started_at'] ) ); ?> — Descarcă SQL</a></li>
		<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
