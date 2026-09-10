<?php
/** Product transfer status fragment. @package SchrackWooCommerceSync */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
		<div class="schrack-panel-header">
			<h2><?php esc_html_e( 'Export állapota', 'schrack-woocommerce-sync' ); ?></h2>
			<?php if ( $export_active && ! $export_stale ) : ?>
				<span class="schrack-auto-refresh"><?php esc_html_e( 'Automatikus frissítés 5 másodpercenként', 'schrack-woocommerce-sync' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $export_stale ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Az export több mint 10 perce nem haladt. Ellenőrizd az Action Scheduler és PHP naplót, majd szükség esetén állítsd le.', 'schrack-woocommerce-sync' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Állapot', 'schrack-woocommerce-sync' ); ?></th>
					<td><span class="schrack-status-pill <?php echo esc_attr( $export_stale ? 'is-error' : ( $state_classes[ $export_state ] ?? 'is-warning' ) ); ?>"><?php echo esc_html( $state_labels[ $export_state ] ?? ucfirst( $export_state ) ); ?></span></td>
				</tr>
				<?php if ( 'idle' !== $export_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Alkalmazott szűrők', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( empty( $export_filter_summary ) ? __( 'Nincs — minden termék és variáció', 'schrack-woocommerce-sync' ) : implode( ' · ', $export_filter_summary ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CSV fejléc', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( $export_header_summary ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Előrehaladás', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<div class="schrack-progress-cell">
								<progress class="schrack-progress-bar" value="<?php echo esc_attr( (string) $export_displayed ); ?>" max="<?php echo esc_attr( (string) max( 1, $export_total ) ); ?>"></progress>
								<span class="schrack-progress-text">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: processed rows, 2: total rows, 3: percentage. */
											__( '%1$s / %2$s termék és variáció (%3$s%%)', 'schrack-woocommerce-sync' ),
											number_format_i18n( $export_processed ),
											number_format_i18n( $export_total ),
											number_format_i18n( $export_percent )
										)
									);
									?>
								</span>
							</div>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'CSV sorok / hibák', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( absint( $product_export['rows'] ?? 0 ) ) . ' / ' . number_format_i18n( absint( $product_export['errors'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Memóriavédelem', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: PHP memory limit, 2: products per batch. */
									__( 'Aktív — PHP limit: %1$s, batch: %2$s termék', 'schrack-woocommerce-sync' ),
									$export_memory_mb > 0 ? number_format_i18n( $export_memory_mb ) . ' MB' : __( 'ismeretlen/korlátlan', 'schrack-woocommerce-sync' ),
									number_format_i18n( $export_batch )
								)
							);
							?>
						</td>
					</tr>
					<?php if ( 'finalizing' === $export_state ) : ?>
						<tr>
							<th><?php esc_html_e( 'CSV összeállítása', 'schrack-woocommerce-sync' ); ?></th>
							<td><?php echo esc_html( size_format( $finalize_position ) . ' / ' . size_format( $finalize_total ) . ' (' . number_format_i18n( $finalize_percent ) . '%)' ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $product_export['message'] ) ) : ?>
					<tr><th><?php esc_html_e( 'Üzenet', 'schrack-woocommerce-sync' ); ?></th><td><?php echo esc_html( schrack_wc_sync_romanian_text( (string) $product_export['message'] ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( 'done' === $export_state ) : ?>
					<tr>
						<th><?php esc_html_e( 'Kész fájl', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( (string) ( $product_export['file_name'] ?? '' ) ); ?> (<?php echo esc_html( size_format( (int) ( $product_export['bytes'] ?? 0 ) ) ); ?>)</td>
					</tr>
					<tr>
						<th></th>
						<td>
							<?php
							$download_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'    => 'schrack_wc_sync_product_export_download',
										'export_id' => $export_id,
									),
									admin_url( 'admin-post.php' )
								),
								'schrack_wc_sync_product_export_download'
							);
							?>
							<a class="button button-primary" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'CSV letöltése', 'schrack-woocommerce-sync' ); ?></a>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( 'idle' !== $export_state ) : ?>
			<?php if ( $export_stale || 'error' === $export_state ) : ?>
				<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
					<input type="hidden" name="action" value="schrack_wc_sync_product_export_resume">
					<?php wp_nonce_field( 'schrack_wc_sync_product_export_resume' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Export folytatása az ellenőrzőponttól', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			<?php endif; ?>
			<form data-transfer-action method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="schrack-inline-actions">
				<input type="hidden" name="action" value="schrack_wc_sync_product_export_reset">
				<?php wp_nonce_field( 'schrack_wc_sync_product_export_reset' ); ?>
				<button type="submit" class="button schrack-stop-button"><?php echo esc_html( $export_active ? __( 'Export leállítása és törlése', 'schrack-woocommerce-sync' ) : __( 'Exportfájl és állapot törlése', 'schrack-woocommerce-sync' ) ); ?></button>
			</form>
		<?php endif; ?>
