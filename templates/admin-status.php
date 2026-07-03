<?php
/**
 * Status template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack Status', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'status' ); ?>
	<?php $this->render_notice( $notice ); ?>
	<?php
	$woocommerce_active          = class_exists( 'WooCommerce' );
	$soap_available              = extension_loaded( 'soap' );
	$action_scheduler_available  = function_exists( 'as_enqueue_async_action' );
	$queue_status                = isset( $queue_status ) && is_array( $queue_status ) ? $queue_status : array();
	$stop_request                = isset( $stop_request ) && is_array( $stop_request ) ? $stop_request : null;
	$queue_state_labels          = array(
		'idle'    => __( 'Idle', 'schrack-woocommerce-sync' ),
		'queued'  => __( 'Queued', 'schrack-woocommerce-sync' ),
		'due'     => __( 'Due now', 'schrack-woocommerce-sync' ),
		'running' => __( 'Running', 'schrack-woocommerce-sync' ),
		'scheduled' => __( 'Scheduled', 'schrack-woocommerce-sync' ),
	);
	$queue_state_classes         = array(
		'idle'    => 'is-ok',
		'queued'  => 'is-warning',
		'due'     => 'is-warning',
		'running' => 'is-error',
		'scheduled' => 'is-ok',
	);
	$credential_fields           = array(
		'customer_number'  => __( 'Customer number', 'schrack-woocommerce-sync' ),
		'webshop_username' => __( 'Webshop username', 'schrack-woocommerce-sync' ),
		'webshop_password' => __( 'Webshop password', 'schrack-woocommerce-sync' ),
		'provider_code'    => __( 'Provider code', 'schrack-woocommerce-sync' ),
	);
	?>
	<?php include SCHRACK_WC_SYNC_PATH . 'templates/admin-sync-dashboard.php'; ?>

	<div class="schrack-grid">
		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Environment', 'schrack-woocommerce-sync' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'PHP version', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( PHP_VERSION ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WooCommerce', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<span class="schrack-status-pill <?php echo $woocommerce_active ? 'is-ok' : 'is-error'; ?>">
								<?php echo $woocommerce_active ? esc_html__( 'Active', 'schrack-woocommerce-sync' ) : esc_html__( 'Missing', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'SOAP extension', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<span class="schrack-status-pill <?php echo $soap_available ? 'is-ok' : 'is-error'; ?>">
								<?php echo $soap_available ? esc_html__( 'Available', 'schrack-woocommerce-sync' ) : esc_html__( 'Missing', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Action Scheduler', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<span class="schrack-status-pill <?php echo $action_scheduler_available ? 'is-ok' : 'is-warning'; ?>">
								<?php echo $action_scheduler_available ? esc_html__( 'Available', 'schrack-woocommerce-sync' ) : esc_html__( 'WP-Cron fallback', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Automatic sync', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php $automatic_sync_enabled = 'yes' === (string) ( $settings['automatic_sync_enabled'] ?? 'yes' ); ?>
							<span class="schrack-status-pill <?php echo $automatic_sync_enabled ? 'is-ok' : 'is-warning'; ?>">
								<?php echo $automatic_sync_enabled ? esc_html__( 'Enabled', 'schrack-woocommerce-sync' ) : esc_html__( 'Disabled', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Schrack sync', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php $schrack_enabled = 'yes' === (string) ( $settings['schrack_enabled'] ?? 'yes' ); ?>
							<span class="schrack-status-pill <?php echo $schrack_enabled ? 'is-ok' : 'is-warning'; ?>">
								<?php echo $schrack_enabled ? esc_html__( 'Enabled', 'schrack-woocommerce-sync' ) : esc_html__( 'Disabled', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Telesystem feed', 'schrack-woocommerce-sync' ); ?></th>
						<td>
							<?php $telesystem_enabled = 'yes' === (string) ( $settings['telesystem_enabled'] ?? 'yes' ); ?>
							<span class="schrack-status-pill <?php echo $telesystem_enabled ? 'is-ok' : 'is-warning'; ?>">
								<?php echo $telesystem_enabled ? esc_html__( 'Enabled', 'schrack-woocommerce-sync' ) : esc_html__( 'Disabled', 'schrack-woocommerce-sync' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Mode', 'schrack-woocommerce-sync' ); ?></th>
						<td><?php echo esc_html( strtoupper( (string) ( $settings['environment'] ?? '' ) ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php if ( ! $action_scheduler_available ) : ?>
				<p class="description">
					<?php esc_html_e( 'Action Scheduler is not available, so sync batches run through WP-Cron instead. WP-Cron only fires on incoming site visits, which can make sync noticeably slower on low-traffic stores. Activating WooCommerce (which bundles Action Scheduler) and/or pointing a real system cron at wp-cron.php is the single biggest speed-up available for this sync.', 'schrack-woocommerce-sync' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="schrack-panel">
			<h2><?php esc_html_e( 'Credentials', 'schrack-woocommerce-sync' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<?php foreach ( $credential_fields as $key => $label ) : ?>
						<?php $configured = '' !== (string) ( $settings[ $key ] ?? '' ); ?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td>
								<span class="schrack-status-pill <?php echo $configured ? 'is-ok' : 'is-error'; ?>">
									<?php echo esc_html( $this->configured_label( (string) ( $settings[ $key ] ?? '' ) ) ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<?php
	$queue_by_task = array();

	foreach ( $queue_status as $queue_row ) {
		$queue_by_task[ (string) ( $queue_row['task'] ?? '' ) ] = $queue_row;
	}

	// Maps the per-operation status keys (used in $status/get_status()) to the
	// queue_status() task keys, which use slightly different names (e.g. "price" vs "prices").
	$operation_task_map = array(
		'catalog'            => 'catalog',
		'telesystem_catalog' => 'telesystem_catalog',
		'price'              => 'prices',
		'stock'              => 'stock',
		'images'             => 'images',
	);

	$operation_labels = array(
		'catalog'            => __( 'Catalog', 'schrack-woocommerce-sync' ),
		'telesystem_catalog' => __( 'Telesystem catalog', 'schrack-woocommerce-sync' ),
		'price'              => __( 'Prices', 'schrack-woocommerce-sync' ),
		'stock'              => __( 'Stock', 'schrack-woocommerce-sync' ),
		'images'             => __( 'Images', 'schrack-woocommerce-sync' ),
	);

	$any_active = false;

	foreach ( $queue_status as $queue_row ) {
		if ( ! empty( $queue_row['is_active'] ) ) {
			$any_active = true;
			break;
		}
	}
	?>

	<div class="schrack-panel">
		<div class="schrack-panel-header">
			<h2><?php esc_html_e( 'Sync Progress', 'schrack-woocommerce-sync' ); ?></h2>
			<div class="schrack-auto-refresh">
				<label>
					<input type="checkbox" id="schrack-auto-refresh-toggle" <?php checked( $any_active ); ?>>
					<?php esc_html_e( 'Auto-refresh every 20s', 'schrack-woocommerce-sync' ); ?>
				</label>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="schrack_wc_sync_stop_syncs">
				<input type="hidden" name="redirect_page" value="schrack-sync-status">
				<?php wp_nonce_field( 'schrack_wc_sync_stop_syncs' ); ?>
				<button type="submit" class="button button-secondary schrack-stop-button"><?php esc_html_e( 'Stop syncs', 'schrack-woocommerce-sync' ); ?></button>
			</form>
		</div>
		<?php if ( null !== $stop_request ) : ?>
			<p><span class="schrack-status-pill is-warning"><?php esc_html_e( 'Stop requested', 'schrack-woocommerce-sync' ); ?></span> <?php echo esc_html( (string) ( $stop_request['requested_at'] ?? '' ) ); ?></p>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Operation', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'State', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'This run', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Last run', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Next check', 'schrack-woocommerce-sync' ); ?></th>
					<th><?php esc_html_e( 'Details', 'schrack-woocommerce-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $operation_task_map as $operation => $task ) : ?>
					<?php
					$row         = isset( $status[ $operation ] ) && is_array( $status[ $operation ] ) ? $status[ $operation ] : array();
					$queue_row   = $queue_by_task[ $task ] ?? array();
					$state       = (string) ( $queue_row['state'] ?? 'idle' );
					$state_text  = (string) ( $queue_state_labels[ $state ] ?? ucfirst( $state ) );
					$state_class = (string) ( $queue_state_classes[ $state ] ?? 'is-warning' );
					$pending     = absint( $queue_row['pending'] ?? 0 );
					$running     = absint( $queue_row['running'] ?? 0 );
					$next_run    = absint( $queue_row['next_run'] ?? 0 );

					$total  = absint( $row['total_items'] ?? ( $row['total_products'] ?? 0 ) );
					$cursor = absint( $row['cursor'] ?? 0 );
					$pct    = $total > 0 ? min( 100, (int) round( ( $cursor / $total ) * 100 ) ) : 0;

					$last_run_raw = (string) ( $row['last_run'] ?? '' );
					$last_run_ts  = '' !== $last_run_raw ? strtotime( $last_run_raw ) : false;

					$details = array();

					if ( 'catalog' === $operation ) {
						foreach ( array( 'image_urls_seen', 'image_urls_stored', 'image_urls_backfilled', 'image_url_meta_errors' ) as $detail_key ) {
							if ( isset( $row[ $detail_key ] ) ) {
								$details[] = ucwords( str_replace( '_', ' ', $detail_key ) ) . ': ' . absint( $row[ $detail_key ] );
							}
						}
					}

					if ( 'telesystem_catalog' === $operation ) {
						foreach ( array( 'prices_synced', 'stock_synced', 'image_urls_seen' ) as $detail_key ) {
							if ( isset( $row[ $detail_key ] ) ) {
								$details[] = ucwords( str_replace( '_', ' ', $detail_key ) ) . ': ' . absint( $row[ $detail_key ] );
							}
						}
					}

					if ( 'images' === $operation ) {
						foreach ( array( 'queued_products', 'workers_queued', 'imported', 'reused', 'attached', 'skipped' ) as $detail_key ) {
							if ( isset( $row[ $detail_key ] ) ) {
								$details[] = ucwords( str_replace( '_', ' ', $detail_key ) ) . ': ' . absint( $row[ $detail_key ] );
							}
						}
					}

					if ( isset( $row['memory_peak_mb'] ) ) {
						$memory_detail = sprintf(
							/* translators: %s: peak memory in MB. */
							__( 'Memory peak: %s MB', 'schrack-woocommerce-sync' ),
							(string) $row['memory_peak_mb']
						);

						if ( isset( $row['memory_limit_mb'] ) ) {
							$memory_detail .= ' / ' . (string) $row['memory_limit_mb'] . ' MB';
						}

						$details[] = $memory_detail;
					}

					foreach ( array( 'memory_safe_mode', 'rate_limited', 'queue_failed', 'waiting_workers', 'disabled' ) as $detail_key ) {
						if ( 'yes' === (string) ( $row[ $detail_key ] ?? 'no' ) ) {
							$details[] = ucwords( str_replace( '_', ' ', $detail_key ) ) . ': yes';
						}
					}
					?>
					<tr>
						<td><?php echo esc_html( (string) ( $operation_labels[ $operation ] ?? ucfirst( $operation ) ) ); ?></td>
						<td>
							<span class="schrack-status-pill <?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_text ); ?></span>
							<?php if ( $pending > 0 || $running > 0 ) : ?>
								<span class="schrack-sync-meta">
									<?php
									printf(
										/* translators: 1: running batch count, 2: pending batch count. */
										esc_html__( '%1$d running, %2$d pending', 'schrack-woocommerce-sync' ),
										$running,
										$pending
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $total > 0 ) : ?>
								<div class="schrack-progress-cell">
									<progress class="schrack-progress-bar" value="<?php echo esc_attr( (string) $cursor ); ?>" max="<?php echo esc_attr( (string) $total ); ?>"></progress>
									<span class="schrack-progress-text">
										<?php
										printf(
											/* translators: 1: cursor, 2: total items, 3: percentage. */
											esc_html__( '%1$s / %2$s (%3$s%%)', 'schrack-woocommerce-sync' ),
											esc_html( number_format_i18n( $cursor ) ),
											esc_html( number_format_i18n( $total ) ),
											esc_html( (string) $pct )
										);
										?>
									</span>
								</div>
							<?php else : ?>
								<?php esc_html_e( '-', 'schrack-woocommerce-sync' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php
							printf(
								/* translators: 1: processed count, 2: error count. */
								esc_html__( '%1$s processed, %2$s errors', 'schrack-woocommerce-sync' ),
								esc_html( (string) absint( $row['processed'] ?? 0 ) ),
								esc_html( (string) absint( $row['errors'] ?? 0 ) )
							);
							?>
							<?php if ( isset( $row['batches_processed'] ) ) : ?>
								<span class="schrack-sync-meta">
									<?php
									printf(
										/* translators: %s: batch count. */
										esc_html__( '%s batches this run', 'schrack-woocommerce-sync' ),
										esc_html( (string) absint( $row['batches_processed'] ) )
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( false !== $last_run_ts ) : ?>
								<span title="<?php echo esc_attr( $last_run_raw ); ?>">
									<?php
									printf(
										/* translators: %s: relative time. */
										esc_html__( '%s ago', 'schrack-woocommerce-sync' ),
										esc_html( human_time_diff( $last_run_ts, current_time( 'timestamp' ) ) )
									);
									?>
								</span>
							<?php else : ?>
								<?php esc_html_e( 'Never', 'schrack-woocommerce-sync' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $running > 0 ) : ?>
								<?php esc_html_e( 'Now', 'schrack-woocommerce-sync' ); ?>
							<?php elseif ( $next_run > 0 && $next_run <= time() ) : ?>
								<?php esc_html_e( 'Due now', 'schrack-woocommerce-sync' ); ?>
							<?php elseif ( $next_run > 0 ) : ?>
								<span title="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', $next_run ) ); ?>">
									<?php
									printf(
										/* translators: %s: relative time. */
										esc_html__( 'in %s', 'schrack-woocommerce-sync' ),
										esc_html( human_time_diff( time(), $next_run ) )
									);
									?>
								</span>
							<?php else : ?>
								<?php esc_html_e( '-', 'schrack-woocommerce-sync' ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo ! empty( $details ) ? esc_html( implode( ', ', $details ) ) : esc_html__( '-', 'schrack-woocommerce-sync' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $action_scheduler_available && $any_active ) : ?>
			<p class="description"><?php esc_html_e( 'Rows shown as "Queued" or "Scheduled" are waiting for the next WP-Cron tick (an incoming site visit), not running continuously. See the WP-Cron note above the Environment table.', 'schrack-woocommerce-sync' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<script>
( function () {
	var toggle = document.getElementById( 'schrack-auto-refresh-toggle' );

	if ( ! toggle ) {
		return;
	}

	var storageKey = 'schrackSyncAutoRefresh';
	var stored = window.sessionStorage ? sessionStorage.getItem( storageKey ) : null;

	if ( null !== stored ) {
		toggle.checked = '1' === stored;
	}

	toggle.addEventListener( 'change', function () {
		if ( window.sessionStorage ) {
			sessionStorage.setItem( storageKey, toggle.checked ? '1' : '0' );
		}
	} );

	if ( toggle.checked ) {
		window.setTimeout( function () {
			window.location.reload();
		}, 20000 );
	}
} )();
</script>
