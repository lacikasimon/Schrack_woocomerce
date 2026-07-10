<?php
/**
 * Newsletter subscribers admin template.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$source_labels = array(
	'registration'   => __( 'Inregistrare', 'schrack-woocommerce-sync' ),
	'checkout'       => __( 'Finalizare comanda', 'schrack-woocommerce-sync' ),
	'account'        => __( 'Cont client', 'schrack-woocommerce-sync' ),
	'account_update' => __( 'Actualizare cont', 'schrack-woocommerce-sync' ),
);
?>
<div class="wrap schrack-sync-admin schrack-newsletter-admin">
	<h1><?php esc_html_e( 'Abonati newsletter', 'schrack-woocommerce-sync' ); ?></h1>

	<section class="schrack-dashboard" aria-label="<?php esc_attr_e( 'Sumar newsletter', 'schrack-woocommerce-sync' ); ?>">
		<div class="schrack-dashboard__header">
			<div>
				<h2><?php esc_html_e( 'Lista activa de abonati', 'schrack-woocommerce-sync' ); ?></h2>
				<p><?php esc_html_e( 'Include clientii inregistrati si persoanele care s-au abonat ca vizitatori la finalizarea comenzii.', 'schrack-woocommerce-sync' ); ?></p>
			</div>
		</div>
		<div class="schrack-dashboard__grid">
			<div class="schrack-dashboard-card is-primary">
				<span class="schrack-dashboard-card__label"><?php esc_html_e( 'Abonati activi', 'schrack-woocommerce-sync' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
				<span class="schrack-dashboard-card__meta"><?php esc_html_e( 'Adrese care au acordat consimtamantul.', 'schrack-woocommerce-sync' ); ?></span>
			</div>
		</div>
	</section>

	<div class="schrack-panel-header schrack-newsletter-admin__toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="schrack-sync-newsletter">
			<label class="screen-reader-text" for="schrack_newsletter_search"><?php esc_html_e( 'Cauta abonati', 'schrack-woocommerce-sync' ); ?></label>
			<input id="schrack_newsletter_search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email sau nume', 'schrack-woocommerce-sync' ); ?>">
			<button class="button" type="submit"><?php esc_html_e( 'Cauta', 'schrack-woocommerce-sync' ); ?></button>
			<?php if ( '' !== $search ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=schrack-sync-newsletter' ) ); ?>"><?php esc_html_e( 'Reseteaza', 'schrack-woocommerce-sync' ); ?></a>
			<?php endif; ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="schrack_wc_sync_export_newsletter">
			<?php wp_nonce_field( 'schrack_wc_sync_export_newsletter' ); ?>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Export CSV', 'schrack-woocommerce-sync' ); ?></button>
		</form>
	</div>

	<section class="schrack-panel">
		<?php if ( empty( $rows ) ) : ?>
			<h2><?php esc_html_e( 'Niciun abonat gasit', 'schrack-woocommerce-sync' ); ?></h2>
			<p><?php esc_html_e( 'Abonatii vor aparea aici dupa inscriere, finalizarea unei comenzi sau activarea optiunii din cont.', 'schrack-woocommerce-sync' ); ?></p>
		<?php else : ?>
			<table class="widefat striped schrack-newsletter-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Abonat', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Sursa', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Data abonarii', 'schrack-woocommerce-sync' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Cont', 'schrack-woocommerce-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$user_id = absint( $row['user_id'] ?? 0 );
						$name    = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
						$source  = sanitize_key( (string) ( $row['source'] ?? '' ) );
						$date    = sanitize_text_field( (string) ( $row['subscribed_at'] ?? '' ) );
						?>
						<tr>
							<td><strong><?php echo esc_html( '' !== $name ? $name : __( 'Vizitator', 'schrack-woocommerce-sync' ) ); ?></strong></td>
							<td><a href="mailto:<?php echo esc_attr( (string) $row['email'] ); ?>"><?php echo esc_html( (string) $row['email'] ); ?></a></td>
							<td><?php echo esc_html( $source_labels[ $source ] ?? ucfirst( str_replace( '_', ' ', $source ) ) ); ?></td>
							<td><?php echo esc_html( '' !== $date ? get_date_from_gmt( $date, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '-' ); ?></td>
							<td>
								<?php if ( $user_id > 0 && current_user_can( 'edit_user', $user_id ) ) : ?>
									<a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>"><?php echo esc_html( '#' . (string) $user_id ); ?></a>
								<?php else : ?>
									<?php esc_html_e( 'Fara cont', 'schrack-woocommerce-sync' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg(
								array(
									'page' => 'schrack-sync-newsletter',
									's'    => $search,
								),
								admin_url( 'admin.php' )
							) . '&paged=%#%',
							'format'    => '',
							'current'   => $page,
							'total'     => $total_pages,
							'prev_text' => __( '‹ Inapoi', 'schrack-woocommerce-sync' ),
							'next_text' => __( 'Inainte ›', 'schrack-woocommerce-sync' ),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
