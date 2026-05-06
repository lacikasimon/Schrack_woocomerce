<?php
/**
 * B2B customers template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_options = array(
	'pending'  => __( 'In verificare', 'schrack-woocommerce-sync' ),
	'approved' => __( 'Aprobat', 'schrack-woocommerce-sync' ),
	'rejected' => __( 'Respins', 'schrack-woocommerce-sync' ),
	'disabled' => __( 'Dezactivat', 'schrack-woocommerce-sync' ),
);

$status_filter_options = array_merge(
	array( '' => __( 'Toate statusurile', 'schrack-woocommerce-sync' ) ),
	$status_options
);
$total_customers       = absint( $summary['total'] ?? 0 );
$pending_customers     = absint( $summary['pending'] ?? 0 );
$approved_customers    = absint( $summary['approved'] ?? 0 );
$discounted_customers  = absint( $summary['discounted'] ?? 0 );
$inactive_customers    = absint( $summary['rejected'] ?? 0 ) + absint( $summary['disabled'] ?? 0 );
$percent_for           = static function ( int $value ) use ( $total_customers ): float {
	return 0 < $total_customers ? round( ( $value / $total_customers ) * 100, 1 ) : 0.0;
};
$dashboard_cards       = array(
	array(
		'class' => 'is-warning',
		'label' => __( 'Cereri in verificare', 'schrack-woocommerce-sync' ),
		'value' => $pending_customers,
		'meta'  => sprintf(
			/* translators: %s: percentage. */
			__( '%s%% din conturile gasite necesita verificare.', 'schrack-woocommerce-sync' ),
			(string) $percent_for( $pending_customers )
		),
		'pct'   => $percent_for( $pending_customers ),
	),
	array(
		'class' => 'is-ok',
		'label' => __( 'Clienti aprobati', 'schrack-woocommerce-sync' ),
		'value' => $approved_customers,
		'meta'  => sprintf(
			/* translators: %s: percentage. */
			__( '%s%% validati pentru preturi si discounturi B2B.', 'schrack-woocommerce-sync' ),
			(string) $percent_for( $approved_customers )
		),
		'pct'   => $percent_for( $approved_customers ),
	),
	array(
		'class' => 'is-info',
		'label' => __( 'Discount configurat', 'schrack-woocommerce-sync' ),
		'value' => $discounted_customers,
		'meta'  => __( 'Clienti cu procent de discount salvat.', 'schrack-woocommerce-sync' ),
		'pct'   => $percent_for( $discounted_customers ),
	),
	array(
		'class' => 'is-error',
		'label' => __( 'Respinsi sau dezactivati', 'schrack-woocommerce-sync' ),
		'value' => $inactive_customers,
		'meta'  => __( 'Conturi care nu primesc beneficii B2B.', 'schrack-woocommerce-sync' ),
		'pct'   => $percent_for( $inactive_customers ),
	),
	array(
		'class' => 'is-primary',
		'label' => __( 'Total clienti B2B', 'schrack-woocommerce-sync' ),
		'value' => $total_customers,
		'meta'  => __( 'Ultimele conturi cu tip B2B sau date fiscale.', 'schrack-woocommerce-sync' ),
		'pct'   => 0 < $total_customers ? 100 : 0,
	),
);
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Clienti B2B', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'b2b' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<section class="schrack-dashboard schrack-b2b-dashboard" aria-label="<?php esc_attr_e( 'Clienti B2B', 'schrack-woocommerce-sync' ); ?>">
		<div class="schrack-dashboard__header">
			<div>
				<h2><?php esc_html_e( 'Clienti B2B', 'schrack-woocommerce-sync' ); ?></h2>
				<p><?php esc_html_e( 'Verificare conturi, status comercial si discounturi pentru clientii B2B.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
			<span><?php echo esc_html( current_time( 'mysql' ) ); ?></span>
		</div>

		<div class="schrack-dashboard__grid">
			<?php foreach ( $dashboard_cards as $card ) : ?>
				<?php $pct = max( 0, min( 100, (float) $card['pct'] ) ); ?>
				<div class="schrack-dashboard-card <?php echo esc_attr( (string) $card['class'] ); ?>">
					<span class="schrack-dashboard-card__label"><?php echo esc_html( (string) $card['label'] ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( absint( $card['value'] ) ) ); ?></strong>
					<span class="schrack-dashboard-card__meta"><?php echo esc_html( (string) $card['meta'] ); ?></span>
					<span class="schrack-dashboard-card__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) $pct ); ?>%;"></span></span>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<?php if ( empty( $customers ) ) : ?>
		<section class="schrack-panel">
			<h2><?php esc_html_e( 'Nu exista clienti B2B', 'schrack-woocommerce-sync' ); ?></h2>
			<p><?php esc_html_e( 'Cand un client trimite formularul B2B sau are date fiscale in cont, va aparea aici pentru verificare si setarea discountului.', 'schrack-woocommerce-sync' ); ?></p>
		</section>
	<?php else : ?>
		<form class="schrack-b2b-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_save_b2b_customers">
			<?php wp_nonce_field( 'schrack_wc_sync_b2b_customers' ); ?>

			<div class="schrack-panel-header">
				<div>
					<h2><?php esc_html_e( 'Verificare si discount clienti', 'schrack-woocommerce-sync' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Discountul se aplica automat in magazin doar cand clientul este logat, are tip cont B2B si status Aprobat.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Salveaza clienti B2B', 'schrack-woocommerce-sync' ); ?></button>
			</div>

			<div class="schrack-toolbar schrack-b2b-toolbar" data-b2b-filters>
				<label class="schrack-b2b-filter-field schrack-b2b-filter-field--search" for="schrack_b2b_search">
					<span><?php esc_html_e( 'Cautare', 'schrack-woocommerce-sync' ); ?></span>
					<input id="schrack_b2b_search" type="search" placeholder="<?php esc_attr_e( 'Cauta client, firma, email sau CUI', 'schrack-woocommerce-sync' ); ?>" data-b2b-search>
				</label>

				<label class="schrack-b2b-filter-field" for="schrack_b2b_status_filter">
					<span><?php esc_html_e( 'Status', 'schrack-woocommerce-sync' ); ?></span>
					<select id="schrack_b2b_status_filter" data-b2b-status-filter>
						<?php foreach ( $status_filter_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<button type="button" class="button" data-b2b-clear-filters><?php esc_html_e( 'Reseteaza filtrele', 'schrack-woocommerce-sync' ); ?></button>
				<span class="schrack-b2b-filter-count">
					<strong data-b2b-visible-count><?php echo esc_html( (string) $total_customers ); ?></strong>
					<?php
					printf(
						/* translators: %d: total customers. */
						esc_html__( 'din %d clienti', 'schrack-woocommerce-sync' ),
						$total_customers
					);
					?>
				</span>
			</div>

			<table class="widefat striped schrack-b2b-table" data-b2b-table>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Client', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Firma', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Verificare', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Discount', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Cerere', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Profil', 'schrack-woocommerce-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $customers as $customer ) : ?>
						<?php
						$user_id         = absint( $customer['user_id'] ?? 0 );
						$status          = sanitize_key( (string) ( $customer['status'] ?? 'pending' ) );
						$status_class    = match ( $status ) {
							'approved' => 'is-ok',
							'pending'  => 'is-warning',
							default    => 'is-error',
						};
						$customer_search = implode(
							' ',
							array(
								(string) ( $customer['name'] ?? '' ),
								(string) ( $customer['email'] ?? '' ),
								(string) ( $customer['company'] ?? '' ),
								(string) ( $customer['cui'] ?? '' ),
								(string) ( $customer['registration_number'] ?? '' ),
								(string) ( $customer['status_label'] ?? '' ),
							)
						);
						?>
						<tr class="schrack-b2b-row is-<?php echo esc_attr( $status ); ?>" data-b2b-row data-b2b-status="<?php echo esc_attr( $status ); ?>" data-b2b-search="<?php echo esc_attr( $customer_search ); ?>">
							<td>
								<strong><?php echo esc_html( (string) $customer['name'] ); ?></strong>
								<span><?php echo esc_html( (string) $customer['email'] ); ?></span>
							</td>
							<td>
								<div class="schrack-b2b-field-stack">
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Companie', 'schrack-woocommerce-sync' ); ?></span>
										<input type="text" name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][company]" value="<?php echo esc_attr( (string) $customer['company'] ); ?>" placeholder="<?php esc_attr_e( 'Companie', 'schrack-woocommerce-sync' ); ?>">
									</label>
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'CUI / Cod fiscal', 'schrack-woocommerce-sync' ); ?></span>
										<input type="text" name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][cui]" value="<?php echo esc_attr( (string) $customer['cui'] ); ?>" placeholder="<?php esc_attr_e( 'CUI / Cod fiscal', 'schrack-woocommerce-sync' ); ?>">
									</label>
									<label>
										<span class="screen-reader-text"><?php esc_html_e( 'Nr. Registrul Comertului', 'schrack-woocommerce-sync' ); ?></span>
										<input type="text" name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][registration_number]" value="<?php echo esc_attr( (string) $customer['registration_number'] ); ?>" placeholder="<?php esc_attr_e( 'Nr. Registrul Comertului', 'schrack-woocommerce-sync' ); ?>">
									</label>
								</div>
							</td>
							<td>
								<div class="schrack-b2b-field-stack">
									<select name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][account_type]">
										<option value="customer" <?php selected( (string) $customer['account_type'], 'customer' ); ?>><?php esc_html_e( 'Client standard', 'schrack-woocommerce-sync' ); ?></option>
										<option value="b2b" <?php selected( (string) $customer['account_type'], 'b2b' ); ?>><?php esc_html_e( 'B2B', 'schrack-woocommerce-sync' ); ?></option>
									</select>
									<select name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][status]">
										<?php foreach ( $status_options as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<span class="schrack-status-pill <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( (string) $customer['status_label'] ); ?></span>
								</div>
							</td>
							<td>
								<label class="schrack-b2b-discount">
									<input type="number" min="0" max="100" step="0.01" name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][discount_percent]" value="<?php echo esc_attr( (string) $customer['discount_display'] ); ?>">
									<span>%</span>
								</label>
							</td>
							<td>
								<span><?php esc_html_e( 'Primit:', 'schrack-woocommerce-sync' ); ?> <?php echo esc_html( '' !== (string) $customer['requested_at'] ? (string) $customer['requested_at'] : '-' ); ?></span>
								<span><?php esc_html_e( 'Aprobat:', 'schrack-woocommerce-sync' ); ?> <?php echo esc_html( '' !== (string) $customer['approved_at'] ? (string) $customer['approved_at'] : '-' ); ?></span>
								<span><?php esc_html_e( 'Inregistrat:', 'schrack-woocommerce-sync' ); ?> <?php echo esc_html( (string) $customer['registered'] ); ?></span>
							</td>
							<td>
								<a class="button" href="<?php echo esc_url( (string) $customer['edit_url'] ); ?>"><?php esc_html_e( 'Deschide', 'schrack-woocommerce-sync' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr class="schrack-b2b-empty-row" data-b2b-empty-row hidden>
						<td colspan="6"><?php esc_html_e( 'Nu exista clienti pentru filtrele selectate.', 'schrack-woocommerce-sync' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Salveaza clienti B2B', 'schrack-woocommerce-sync' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
