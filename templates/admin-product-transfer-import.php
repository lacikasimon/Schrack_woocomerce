<?php
/** Product transfer status fragment. @package SchrackWooCommerceSync */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
		<div class="schrack-panel-header">
			<h2><?php esc_html_e( 'Import állapota', 'schrack-woocommerce-sync' ); ?></h2>
			<?php if ( $import_active && ! $import_stale ) : ?><span class="schrack-auto-refresh"><?php esc_html_e( 'Automatikus frissítés 5 másodpercenként', 'schrack-woocommerce-sync' ); ?></span><?php endif; ?>
		</div>

		<?php if ( $import_stale ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Az import több mint 30 perce nem jelzett előrehaladást. Nagy képek letöltése lassú lehet; ellenőrizd az Action Scheduler és PHP naplót.', 'schrack-woocommerce-sync' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr><th><?php esc_html_e( 'Állapot', 'schrack-woocommerce-sync' ); ?></th><td><span class="schrack-status-pill <?php echo esc_attr( $import_stale ? 'is-error' : ( $state_classes[ $import_state ] ?? 'is-warning' ) ); ?>"><?php echo esc_html( $state_labels[ $import_state ] ?? ucfirst( $import_state ) ); ?></span></td></tr>
				<?php if ( 'idle' !== $import_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Előrehaladás', 'schrack-woocommerce-sync' ); ?></th>
						<td><div class="schrack-progress-cell"><progress class="schrack-progress-bar" value="<?php echo esc_attr( (string) $import_percent ); ?>" max="100"></progress><span class="schrack-progress-text"><?php echo esc_html( number_format_i18n( $import_percent ) . '%' ); ?></span></div></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Importált / variáció / frissített', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( absint( $product_import['imported'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_import['imported_variations'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_import['updated'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Hibás / kihagyott', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( absint( $product_import['failed'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_import['skipped'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr><th><?php esc_html_e( 'Import mód', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( 'yes' === (string) ( $product_import['update_existing'] ?? 'no' ) ? __( 'Meglévő termékek frissítése', 'schrack-woocommerce-sync' ) : __( 'Új termékek létrehozása', 'schrack-woocommerce-sync' ) ); ?></td></tr>
					<tr>
						<th><?php esc_html_e( 'Memóriavédelem', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: PHP memory limit, 2: CSV rows per batch. */
									__( 'Aktív — PHP limit: %1$s, batch: %2$s CSV sor', 'schrack-woocommerce-sync' ),
									$import_memory_mb > 0 ? number_format_i18n( $import_memory_mb ) . ' MB' : __( 'ismeretlen/korlátlan', 'schrack-woocommerce-sync' ),
									number_format_i18n( $import_batch )
								)
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $product_import['message'] ) ) : ?><tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $product_import['message'] ) ); ?></td></tr><?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $warnings ) ) : ?>
			<h3><?php esc_html_e( 'Első hibák és kihagyások', 'schrack-woocommerce-sync' ); ?></h3>
			<ul class="ul-disc">
				<?php foreach ( $warnings as $warning ) : ?><li><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $warning ) ); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( 'idle' !== $import_state ) : ?>
			<?php if ( $import_stale || 'error' === $import_state ) : ?>
				<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
					<input type="hidden" name="action" value="schrack_wc_sync_product_import_resume">
					<?php wp_nonce_field( 'schrack_wc_sync_product_import_resume' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import folytatása az utolsó pozíciótól', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			<?php endif; ?>
			<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
				<input type="hidden" name="action" value="schrack_wc_sync_product_import_reset">
				<?php wp_nonce_field( 'schrack_wc_sync_product_import_reset' ); ?>
				<button type="submit" class="button schrack-stop-button"><?php echo esc_html( $import_active ? __( 'Import leállítása és fájl törlése', 'schrack-woocommerce-sync' ) : __( 'Importállapot törlése', 'schrack-woocommerce-sync' ) ); ?></button>
			</form>
		<?php endif; ?>
