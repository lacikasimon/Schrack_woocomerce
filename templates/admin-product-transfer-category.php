<?php
/** Category import status fragment. @package SchrackWooCommerceSync */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
		<div class="schrack-panel-header">
			<h3><?php esc_html_e( 'Kategóriaimport állapota', 'schrack-woocommerce-sync' ); ?></h3>
			<?php if ( $category_live ) : ?><span class="schrack-auto-refresh"><?php esc_html_e( 'Automatikus frissítés 5 másodpercenként', 'schrack-woocommerce-sync' ); ?></span><?php endif; ?>
		</div>

		<?php if ( $category_stale ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'A kategóriaimport több mint 30 perce nem haladt. Folytatható az utolsó mentett byte-pozíciótól.', 'schrack-woocommerce-sync' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr><th><?php esc_html_e( 'Állapot', 'schrack-woocommerce-sync' ); ?></th><td><span class="schrack-status-pill <?php echo esc_attr( $category_stale ? 'is-error' : ( $state_classes[ $category_state ] ?? 'is-warning' ) ); ?>"><?php echo esc_html( $state_labels[ $category_state ] ?? ucfirst( $category_state ) ); ?></span></td></tr>
				<?php if ( 'idle' !== $category_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Előrehaladás', 'schrack-woocommerce-sync' ); ?></th>
						<td><div class="schrack-progress-cell"><progress class="schrack-progress-bar" value="<?php echo esc_attr( (string) $category_percent ); ?>" max="100"></progress><span class="schrack-progress-text"><?php echo esc_html( number_format_i18n( $category_processed ) . ' / ' . number_format_i18n( $category_total ) . ' (' . number_format_i18n( $category_percent ) . '%)' ); ?></span></div></td>
					</tr>
					<tr><th><?php esc_html_e( 'Létrehozott / frissített / kihagyott', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $category_import['created'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $category_import['updated'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $category_import['skipped'] ?? 0 ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Import mód', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( 'no' === (string) ( $category_import['update_existing'] ?? 'yes' ) ? __( 'Új áruház: útvonal/slug', 'schrack-woocommerce-sync' ) : __( 'Ugyanez az áruház: ID-frissítés', 'schrack-woocommerce-sync' ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( ! empty( $category_import['message'] ) ) : ?><tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $category_import['message'] ) ); ?></td></tr><?php endif; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $category_warnings ) ) : ?>
			<h4><?php esc_html_e( 'Első kategóriaimport-figyelmeztetések', 'schrack-woocommerce-sync' ); ?></h4>
			<ul class="ul-disc"><?php foreach ( array_slice( $category_warnings, 0, 10 ) as $warning ) : ?><li><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $warning ) ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>

		<?php if ( 'idle' !== $category_state ) : ?>
			<?php if ( $category_stale || 'error' === $category_state ) : ?>
				<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
					<input type="hidden" name="action" value="schrack_wc_sync_category_import_resume">
					<?php wp_nonce_field( 'schrack_wc_sync_category_import_resume' ); ?>
					<button type="submit" class="button button-primary" data-transfer-product-lock <?php disabled( $product_transfer_active ); ?>><?php esc_html_e( 'Kategóriaimport folytatása', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			<?php endif; ?>
			<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
				<input type="hidden" name="action" value="schrack_wc_sync_category_import_reset">
				<?php wp_nonce_field( 'schrack_wc_sync_category_import_reset' ); ?>
				<button type="submit" class="button schrack-stop-button"><?php echo esc_html( $category_live ? __( 'Kategóriaimport leállítása és törlése', 'schrack-woocommerce-sync' ) : __( 'Kategóriaimport állapotának törlése', 'schrack-woocommerce-sync' ) ); ?></button>
			</form>
		<?php endif; ?>
