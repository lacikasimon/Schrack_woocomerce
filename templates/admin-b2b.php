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
?>
<div class="wrap schrack-sync-admin">
	<h1><?php esc_html_e( 'Schrack B2B Customers', 'schrack-woocommerce-sync' ); ?></h1>
	<?php $this->render_tabs( 'b2b' ); ?>
	<?php $this->render_notice( $notice ); ?>

	<section class="schrack-panel">
		<div class="schrack-b2b-summary">
			<div class="schrack-b2b-summary__item">
				<strong><?php echo esc_html( (string) $summary['pending'] ); ?></strong>
				<span><?php esc_html_e( 'Cereri in verificare', 'schrack-woocommerce-sync' ); ?></span>
			</div>
			<div class="schrack-b2b-summary__item">
				<strong><?php echo esc_html( (string) $summary['approved'] ); ?></strong>
				<span><?php esc_html_e( 'Clienti aprobati', 'schrack-woocommerce-sync' ); ?></span>
			</div>
			<div class="schrack-b2b-summary__item">
				<strong><?php echo esc_html( (string) $summary['discounted'] ); ?></strong>
				<span><?php esc_html_e( 'Au discount setat', 'schrack-woocommerce-sync' ); ?></span>
			</div>
			<div class="schrack-b2b-summary__item">
				<strong><?php echo esc_html( (string) $summary['total'] ); ?></strong>
				<span><?php esc_html_e( 'Total conturi B2B gasite', 'schrack-woocommerce-sync' ); ?></span>
			</div>
		</div>
		<p class="description">
			<?php esc_html_e( 'Discountul se aplica automat in magazin doar cand clientul este logat, are tip cont B2B si status Aprobat.', 'schrack-woocommerce-sync' ); ?>
		</p>
	</section>

	<?php if ( empty( $customers ) ) : ?>
		<section class="schrack-panel">
			<h2><?php esc_html_e( 'Nu exista cereri B2B', 'schrack-woocommerce-sync' ); ?></h2>
			<p><?php esc_html_e( 'Cand un client trimite formularul B2B, cererea va aparea aici pentru verificare si setarea discountului.', 'schrack-woocommerce-sync' ); ?></p>
		</section>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_save_b2b_customers">
			<?php wp_nonce_field( 'schrack_wc_sync_b2b_customers' ); ?>

			<div class="schrack-panel-header">
				<div>
					<h2><?php esc_html_e( 'Verificare si discount clienti', 'schrack-woocommerce-sync' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Schimba statusul pe Aprobat si seteaza procentul de discount pentru fiecare firma validata.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Salveaza B2B', 'schrack-woocommerce-sync' ); ?></button>
			</div>

			<table class="widefat striped schrack-b2b-table">
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
						$user_id = absint( $customer['user_id'] ?? 0 );
						$status  = sanitize_key( (string) ( $customer['status'] ?? 'pending' ) );
						?>
						<tr class="schrack-b2b-row is-<?php echo esc_attr( $status ); ?>">
							<td>
								<strong><?php echo esc_html( (string) $customer['name'] ); ?></strong>
								<span><?php echo esc_html( (string) $customer['email'] ); ?></span>
							</td>
							<td>
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
							</td>
							<td>
								<select name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][account_type]">
									<option value="customer" <?php selected( (string) $customer['account_type'], 'customer' ); ?>><?php esc_html_e( 'Client standard', 'schrack-woocommerce-sync' ); ?></option>
									<option value="b2b" <?php selected( (string) $customer['account_type'], 'b2b' ); ?>><?php esc_html_e( 'B2B', 'schrack-woocommerce-sync' ); ?></option>
								</select>
								<select name="schrack_b2b_customers[<?php echo esc_attr( (string) $user_id ); ?>][status]">
									<?php foreach ( $status_options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="schrack-b2b-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( (string) $customer['status_label'] ); ?></span>
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
				</tbody>
			</table>

			<p class="submit">
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Salveaza B2B', 'schrack-woocommerce-sync' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
