<?php
/**
 * Customer return requests for WooCommerce orders.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Return_Manager {
	public const ACTION       = 'schrack_wc_sync_submit_return';
	public const NONCE_ACTION = 'schrack_wc_sync_return';

	private const META_KEY                = '_schrack_return_requests';
	private const NOTICE_QUERY_ARG        = 'schrack_return_notice';
	private const NOTICE_TRANSIENT_PREFIX = 'schrack_return_notice_';
	private const RATE_TRANSIENT_PREFIX   = 'schrack_return_rate_';
	private const RETURN_WINDOW_DAYS      = 14;

	/**
	 * Registers return hooks.
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submission' ) );
		add_shortcode( 'schrack_return_form', array( $this, 'return_shortcode' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 20 );

		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_account_order_action' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_native_order_panel' ), 20 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin_order_data' ) );

		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_admin_return_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_admin_return_column' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_admin_return_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_admin_return_column' ), 20, 2 );
	}

	/**
	 * Registers the central return-request screen below WooCommerce.
	 */
	public function register_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Cereri de retur', 'schrack-woocommerce-sync' ),
			__( 'Retururi', 'schrack-woocommerce-sync' ),
			'manage_woocommerce',
			'schrack-sync-returns',
			array( $this, 'render_admin_returns_page' )
		);
	}

	/**
	 * Renders a paginated overview of all orders with return requests.
	 */
	public function render_admin_returns_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nu ai permisiunea de a vedea cererile de retur.', 'schrack-woocommerce-sync' ) );
		}

		$page     = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
		$per_page = max( 10, min( 100, (int) apply_filters( 'schrack_wc_admin_returns_per_page', 20 ) ) );
		$orders   = array();
		$total    = 0;
		$pages    = 0;
		$error    = '';

		if ( function_exists( 'wc_get_orders' ) ) {
			try {
				$result = wc_get_orders(
					array(
						'limit'      => $per_page,
						'page'       => $page,
						'paginate'   => true,
						'orderby'    => 'date',
						'order'      => 'DESC',
						'meta_query' => array(
							array(
								'key'     => self::META_KEY,
								'compare' => 'EXISTS',
							),
						),
					)
				);

				if ( is_object( $result ) && isset( $result->orders ) ) {
					$orders = is_array( $result->orders ) ? $result->orders : array();
					$total  = isset( $result->total ) ? absint( $result->total ) : count( $orders );
					$pages  = isset( $result->max_num_pages ) ? absint( $result->max_num_pages ) : 1;
				} elseif ( is_array( $result ) ) {
					$orders = $result;
					$total  = count( $orders );
					$pages  = 1;
				}
			} catch ( Throwable $exception ) {
				$error = $exception->getMessage();
			}
		}

		?>
		<div class="wrap schrack-sync-admin schrack-returns-admin">
			<h1><?php esc_html_e( 'Cereri de retur', 'schrack-woocommerce-sync' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Toate solicitarile de retur trimise din contul clientului sau din formularul pentru comenzi fara cont.', 'schrack-woocommerce-sync' ); ?></p>

			<div class="schrack-returns-admin__summary">
				<div>
					<span><?php esc_html_e( 'Cereri gasite', 'schrack-woocommerce-sync' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
				</div>
				<a class="button" href="<?php echo esc_url( $this->admin_orders_url() ); ?>"><?php esc_html_e( 'Vezi toate comenzile', 'schrack-woocommerce-sync' ); ?></a>
			</div>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( __( 'Cererile de retur nu au putut fi incarcate: %s', 'schrack-woocommerce-sync' ), $error ) ); ?></p></div>
			<?php elseif ( empty( $orders ) ) : ?>
				<div class="schrack-panel schrack-returns-admin__empty">
					<h2><?php esc_html_e( 'Nu exista cereri de retur', 'schrack-woocommerce-sync' ); ?></h2>
					<p><?php esc_html_e( 'Cererile noi vor aparea automat aici dupa trimiterea formularului.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
			<?php else : ?>
				<div class="schrack-returns-admin__table-wrap">
					<table class="widefat fixed striped schrack-returns-admin__table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Retur', 'schrack-woocommerce-sync' ); ?></th>
								<th><?php esc_html_e( 'Comanda', 'schrack-woocommerce-sync' ); ?></th>
								<th><?php esc_html_e( 'Client', 'schrack-woocommerce-sync' ); ?></th>
								<th><?php esc_html_e( 'Produse', 'schrack-woocommerce-sync' ); ?></th>
								<th><?php esc_html_e( 'Motiv', 'schrack-woocommerce-sync' ); ?></th>
								<th><?php esc_html_e( 'Status', 'schrack-woocommerce-sync' ); ?></th>
								<th><?php esc_html_e( 'Actiuni', 'schrack-woocommerce-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $orders as $order ) : ?>
								<?php if ( $order instanceof WC_Order ) : ?>
									<?php foreach ( $this->requests( $order ) as $request ) : ?>
										<?php echo $this->admin_return_row( $order, $request ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php endforeach; ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%', menu_page_url( 'schrack-sync-returns', false ) ),
										'format'    => '',
										'current'   => $page,
										'total'     => $pages,
										'prev_text' => __( '‹ Anterior', 'schrack-woocommerce-sync' ),
										'next_text' => __( 'Urmator ›', 'schrack-woocommerce-sync' ),
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders one request row on the central admin screen.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	private function admin_return_row( WC_Order $order, array $request ): string {
		$created_at   = $this->format_gmt_date( (string) ( $request['created_at'] ?? '' ) );
		$customer     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$company      = trim( (string) $order->get_billing_company() );
		$email        = sanitize_email( (string) ( $request['email'] ?? $order->get_billing_email() ) );
		$phone        = sanitize_text_field( (string) ( $request['phone'] ?? $order->get_billing_phone() ) );
		$status       = sanitize_key( (string) ( $request['status'] ?? 'requested' ) );
		$status_class = 'is-' . sanitize_html_class( $status );
		$source       = 'account' === ( $request['source'] ?? '' ) ? __( 'Cont client', 'schrack-woocommerce-sync' ) : __( 'Formular vizitator', 'schrack-woocommerce-sync' );
		$order_url    = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
		$products     = $this->request_product_lines( $request );
		$message      = trim( (string) ( $request['message'] ?? '' ) );

		ob_start();
		?>
		<tr>
			<td data-label="<?php esc_attr_e( 'Retur', 'schrack-woocommerce-sync' ); ?>">
				<strong><?php echo esc_html( (string) ( $request['id'] ?? '-' ) ); ?></strong>
				<small><?php echo esc_html( $created_at ); ?></small>
				<small><?php echo esc_html( $source ); ?></small>
			</td>
			<td data-label="<?php esc_attr_e( 'Comanda', 'schrack-woocommerce-sync' ); ?>">
				<a href="<?php echo esc_url( $order_url ); ?>"><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></a>
				<small><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></small>
				<small><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></small>
			</td>
			<td data-label="<?php esc_attr_e( 'Client', 'schrack-woocommerce-sync' ); ?>">
				<strong><?php echo esc_html( '' !== $company ? $company : ( '' !== $customer ? $customer : __( 'Client fara nume', 'schrack-woocommerce-sync' ) ) ); ?></strong>
				<?php if ( '' !== $email ) : ?><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a><?php endif; ?>
				<?php if ( '' !== $phone ) : ?><small><?php echo esc_html( $phone ); ?></small><?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Produse', 'schrack-woocommerce-sync' ); ?>">
				<?php if ( empty( $products ) ) : ?>
					<span>—</span>
				<?php else : ?>
					<ul>
						<?php foreach ( $products as $product_line ) : ?>
							<li><?php echo esc_html( $product_line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Motiv', 'schrack-woocommerce-sync' ); ?>">
				<strong><?php echo esc_html( (string) ( $request['reason_label'] ?? '-' ) ); ?></strong>
				<?php if ( '' !== $message ) : ?><small><?php echo nl2br( esc_html( $message ) ); ?></small><?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Status', 'schrack-woocommerce-sync' ); ?>">
				<span class="schrack-returns-admin__status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $this->request_status_label( $status ) ); ?></span>
			</td>
			<td data-label="<?php esc_attr_e( 'Actiuni', 'schrack-woocommerce-sync' ); ?>">
				<a class="button button-primary" href="<?php echo esc_url( $order_url ); ?>"><?php esc_html_e( 'Deschide comanda', 'schrack-woocommerce-sync' ); ?></a>
			</td>
		</tr>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Returns readable product lines for an admin return row.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<int,string>
	 */
	private function request_product_lines( array $request ): array {
		$lines = array();
		$items = isset( $request['items'] ) && is_array( $request['items'] ) ? $request['items'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$quantity = max( 0, (int) ( $item['quantity'] ?? 0 ) );
			$name     = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$sku      = sanitize_text_field( (string) ( $item['sku'] ?? '' ) );

			if ( '' !== $name ) {
				$lines[] = sprintf( '%d × %s%s', $quantity, $name, '' !== $sku ? ' (SKU: ' . $sku . ')' : '' );
			}
		}

		if ( empty( $lines ) && ! empty( $request['guest_description'] ) ) {
			$description = sanitize_textarea_field( (string) $request['guest_description'] );
			$lines       = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $description ) ?: array() ) ) );
		}

		return $lines;
	}

	/**
	 * Returns the main WooCommerce order-list URL.
	 */
	private function admin_orders_url(): string {
		return admin_url( 'admin.php?page=wc-orders' );
	}

	/**
	 * Renders the guest return form.
	 */
	public function render_guest_form( string $redirect = '' ): string {
		$redirect = '' !== $redirect ? $redirect : $this->current_url();

		ob_start();
		?>
		<div class="schrack-account__panel schrack-account__panel--wide schrack-account__return-panel" id="schrack-guest-return">
			<?php echo $this->render_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="schrack-account__panel-head">
				<div>
					<span class="schrack-account__tag"><?php esc_html_e( 'Retur in 14 zile', 'schrack-woocommerce-sync' ); ?></span>
					<h3><?php esc_html_e( 'Retur pentru comanda fara cont', 'schrack-woocommerce-sync' ); ?></h3>
					<p><?php esc_html_e( 'Completeaza numarul comenzii si emailul folosit la cumparare. Produsele pot fi returnate in maximum 14 zile de la finalizarea comenzii.', 'schrack-woocommerce-sync' ); ?></p>
				</div>
			</div>

			<form class="schrack-account__return-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="return_mode" value="guest">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
				<input class="schrack-account__website" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
				<?php wp_nonce_field( self::NONCE_ACTION, 'schrack_return_nonce' ); ?>

				<div class="schrack-account__register-grid">
					<?php echo $this->input_field( 'order_number', __( 'Numar comanda', 'schrack-woocommerce-sync' ), 'text', true, 'off', '#' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->input_field( 'billing_email', __( 'Emailul comenzii', 'schrack-woocommerce-sync' ), 'email', true, 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->input_field( 'phone', __( 'Telefon', 'schrack-woocommerce-sync' ), 'tel', false, 'tel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->reason_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->textarea_field( 'products_description', __( 'Produse si cantitati returnate', 'schrack-woocommerce-sync' ), true, __( 'Exemplu: 2 x produs ABC, SKU 123', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->textarea_field( 'return_message', __( 'Detalii suplimentare', 'schrack-woocommerce-sync' ), false, __( 'Descrie pe scurt motivul sau starea produsului.', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<label class="schrack-account__terms">
					<input type="checkbox" name="return_terms" value="yes" required>
					<span><?php esc_html_e( 'Confirm ca datele sunt corecte si ca produsele indicate apartin comenzii.', 'schrack-woocommerce-sync' ); ?></span>
				</label>

				<button class="schrack-account__button" type="submit"><?php esc_html_e( 'Trimite cererea de retur', 'schrack-woocommerce-sync' ); ?></button>
			</form>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the return panel for one authenticated customer's order.
	 */
	public function render_order_return_panel( WC_Order $order, string $redirect = '' ): string {
		$redirect    = '' !== $redirect ? $redirect : $this->current_url();
		$eligibility = $this->get_eligibility( $order );
		$request     = $this->latest_request( $order );

		ob_start();
		?>
		<section class="schrack-account__return-panel" id="schrack-order-return">
			<?php echo $this->render_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="schrack-account__return-head">
				<div>
					<span class="schrack-account__tag"><?php esc_html_e( 'Retur produse', 'schrack-woocommerce-sync' ); ?></span>
					<h4><?php esc_html_e( 'Solicita returul produselor', 'schrack-woocommerce-sync' ); ?></h4>
				</div>
				<?php if ( '' !== $eligibility['deadline'] ) : ?>
					<span class="schrack-account__return-deadline">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: return deadline. */
								__( 'Termen: %s', 'schrack-woocommerce-sync' ),
								$eligibility['deadline']
							)
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( is_array( $request ) ) : ?>
				<?php echo $this->render_request_status( $request ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php elseif ( ! $eligibility['eligible'] ) : ?>
				<div class="schrack-account__return-message is-unavailable">
					<strong><?php esc_html_e( 'Retur indisponibil', 'schrack-woocommerce-sync' ); ?></strong>
					<p><?php echo esc_html( $eligibility['message'] ); ?></p>
				</div>
			<?php else : ?>
				<p class="schrack-account__return-intro">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: remaining days. */
							_n( 'Mai ai %d zi pentru trimiterea cererii.', 'Mai ai %d zile pentru trimiterea cererii.', $eligibility['days_remaining'], 'schrack-woocommerce-sync' ),
							$eligibility['days_remaining']
						)
					);
					?>
				</p>

				<form class="schrack-account__return-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="return_mode" value="account">
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
					<input class="schrack-account__website" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
					<?php wp_nonce_field( self::NONCE_ACTION, 'schrack_return_nonce' ); ?>

					<div class="schrack-account__return-items">
						<div class="schrack-account__return-items-head">
							<span><?php esc_html_e( 'Produs', 'schrack-woocommerce-sync' ); ?></span>
							<span><?php esc_html_e( 'Cantitate de returnat', 'schrack-woocommerce-sync' ); ?></span>
						</div>
						<?php foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) : ?>
							<?php
							$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
							$sku     = $product instanceof WC_Product ? $product->get_sku() : '';
							$maximum = max( 0, (int) $item->get_quantity() );
							?>
							<label class="schrack-account__return-item">
								<span>
									<strong><?php echo esc_html( $item->get_name() ); ?></strong>
									<small><?php echo esc_html( '' !== $sku ? 'SKU: ' . $sku : __( 'Fara SKU', 'schrack-woocommerce-sync' ) ); ?></small>
								</span>
								<input type="number" name="return_items[<?php echo esc_attr( (string) $item_id ); ?>]" value="0" min="0" max="<?php echo esc_attr( (string) $maximum ); ?>" step="1" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( __( 'Cantitate pentru %s', 'schrack-woocommerce-sync' ), $item->get_name() ) ); ?>">
							</label>
						<?php endforeach; ?>
					</div>

					<div class="schrack-account__register-grid">
						<?php echo $this->reason_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo $this->input_field( 'phone', __( 'Telefon de contact', 'schrack-woocommerce-sync' ), 'tel', false, 'tel', '', $order->get_billing_phone() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo $this->textarea_field( 'return_message', __( 'Detalii suplimentare', 'schrack-woocommerce-sync' ), false, __( 'Descrie pe scurt motivul sau starea produsului.', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>

					<label class="schrack-account__terms">
						<input type="checkbox" name="return_terms" value="yes" required>
						<span><?php esc_html_e( 'Confirm produsele si cantitatile selectate pentru retur.', 'schrack-woocommerce-sync' ); ?></span>
					</label>

					<button class="schrack-account__button" type="submit"><?php esc_html_e( 'Trimite cererea de retur', 'schrack-woocommerce-sync' ); ?></button>
				</form>
			<?php endif; ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handles both account and guest return submissions.
	 */
	public function handle_submission(): void {
		$redirect = $this->posted_url( 'redirect_to', $this->account_url() );
		$nonce    = $this->posted_text( 'schrack_return_nonce' );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Sesiunea formularului a expirat. Te rugam sa incerci din nou.', 'schrack-woocommerce-sync' ) );
		}

		if ( '' !== $this->posted_text( 'website' ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Cererea nu a putut fi trimisa.', 'schrack-woocommerce-sync' ) );
		}

		$mode = $this->posted_text( 'return_mode' );

		if ( ! in_array( $mode, array( 'account', 'guest' ), true ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Tip de cerere necunoscut.', 'schrack-woocommerce-sync' ) );
		}

		if ( 'yes' !== $this->posted_text( 'return_terms' ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Confirma datele cererii de retur.', 'schrack-woocommerce-sync' ) );
		}

		$reason  = $this->posted_text( 'return_reason' );
		$reasons = $this->return_reasons();

		if ( ! isset( $reasons[ $reason ] ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Selecteaza motivul returului.', 'schrack-woocommerce-sync' ) );
		}

		$order = null;
		$items = array();
		$email = '';
		$guest_description = '';

		if ( 'account' === $mode ) {
			if ( ! is_user_logged_in() ) {
				$this->redirect_with_notice( $redirect, 'error', __( 'Trebuie sa fii autentificat pentru acest retur.', 'schrack-woocommerce-sync' ) );
			}

			$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $this->posted_text( 'order_id' ) ) ) : null;

			if ( ! $order instanceof WC_Order || (int) $order->get_user_id() !== get_current_user_id() ) {
				$this->redirect_with_notice( $redirect, 'error', __( 'Comanda nu a fost gasita in contul tau.', 'schrack-woocommerce-sync' ) );
			}

			$items = $this->validated_account_items( $order );

			if ( empty( $items ) ) {
				$this->redirect_with_notice( $redirect, 'error', __( 'Alege cel putin un produs si o cantitate pentru retur.', 'schrack-woocommerce-sync' ) );
			}

			$email = sanitize_email( $order->get_billing_email() );
		} else {
			$email = sanitize_email( $this->posted_text( 'billing_email' ) );

			if ( $this->guest_rate_limited() ) {
				$this->redirect_with_notice( $redirect, 'error', __( 'Au fost trimise prea multe cereri. Te rugam sa incerci din nou mai tarziu.', 'schrack-woocommerce-sync' ) );
			}

			$order = $this->find_guest_order( $this->posted_text( 'order_number' ), $email );

			if ( ! $order instanceof WC_Order || ! is_email( $email ) || strtolower( $email ) !== strtolower( sanitize_email( $order->get_billing_email() ) ) ) {
				$this->redirect_with_notice( $redirect, 'error', __( 'Nu am putut valida comanda si adresa de email. Verifica datele introduse.', 'schrack-woocommerce-sync' ) );
			}

			$guest_description = $this->posted_textarea( 'products_description' );

			if ( '' === $guest_description ) {
				$this->redirect_with_notice( $redirect, 'error', __( 'Completeaza produsele si cantitatile pe care vrei sa le returnezi.', 'schrack-woocommerce-sync' ) );
			}
		}

		$eligibility = $this->get_eligibility( $order );

		if ( ! $eligibility['eligible'] ) {
			$this->redirect_with_notice( $redirect, 'error', $eligibility['message'] );
		}

		$request = array(
			'id'                 => $this->request_id( $order ),
			'status'             => 'requested',
			'created_at'         => current_time( 'mysql', true ),
			'source'             => $mode,
			'customer_user_id'   => 'account' === $mode ? get_current_user_id() : 0,
			'email'              => $email,
			'phone'              => $this->posted_text( 'phone' ),
			'reason'             => $reason,
			'reason_label'       => $reasons[ $reason ],
			'message'            => $this->posted_textarea( 'return_message' ),
			'items'              => $items,
			'guest_description'  => $guest_description,
			'return_deadline'    => $eligibility['deadline'],
		);

		$requests   = $this->requests( $order );
		$requests[] = $request;

		$order->update_meta_data( self::META_KEY, $requests );
		$order->save();
		$order->add_order_note( $this->order_note( $request ) );

		$this->send_notifications( $order, $request );

		$this->redirect_with_notice( $redirect, 'success', __( 'Cererea de retur a fost inregistrata. Te vom contacta cu pasii urmatori.', 'schrack-woocommerce-sync' ) );
	}

	/**
	 * Returns eligibility data for an order.
	 *
	 * @return array{eligible:bool,message:string,deadline:string,days_remaining:int}
	 */
	public function get_eligibility( WC_Order $order ): array {
		$window_days = max( 1, (int) apply_filters( 'schrack_wc_return_window_days', self::RETURN_WINDOW_DAYS, $order ) );
		$date        = $order->get_date_completed();

		if ( ! $date instanceof WC_DateTime ) {
			$date = $order->get_date_created();
		}

		$reference_timestamp = $date instanceof WC_DateTime ? $date->getTimestamp() : 0;
		$reference_timestamp = (int) apply_filters( 'schrack_wc_return_reference_timestamp', $reference_timestamp, $order );
		$deadline_timestamp  = $reference_timestamp > 0 ? $reference_timestamp + ( $window_days * DAY_IN_SECONDS ) : 0;
		$deadline            = $deadline_timestamp > 0 ? wp_date( get_option( 'date_format' ), $deadline_timestamp ) : '';
		$days_remaining      = $deadline_timestamp > 0 ? max( 0, (int) ceil( ( $deadline_timestamp - time() ) / DAY_IN_SECONDS ) ) : 0;
		$existing_request    = $this->latest_request( $order );

		if ( is_array( $existing_request ) ) {
			return array(
				'eligible'       => false,
				'message'        => __( 'Pentru aceasta comanda exista deja o cerere de retur.', 'schrack-woocommerce-sync' ),
				'deadline'       => $deadline,
				'days_remaining' => $days_remaining,
			);
		}

		$allowed_statuses = (array) apply_filters( 'schrack_wc_return_eligible_statuses', array( 'processing', 'completed' ), $order );
		$allowed_statuses = array_map( 'sanitize_key', $allowed_statuses );

		if ( ! in_array( $order->get_status(), $allowed_statuses, true ) ) {
			return array(
				'eligible'       => false,
				'message'        => __( 'Returul poate fi solicitat pentru o comanda procesata sau finalizata.', 'schrack-woocommerce-sync' ),
				'deadline'       => $deadline,
				'days_remaining' => $days_remaining,
			);
		}

		if ( 0 === $deadline_timestamp || time() > $deadline_timestamp ) {
			return array(
				'eligible'       => false,
				'message'        => __( 'Perioada de 14 zile pentru returul acestei comenzi a expirat.', 'schrack-woocommerce-sync' ),
				'deadline'       => $deadline,
				'days_remaining' => 0,
			);
		}

		return array(
			'eligible'       => true,
			'message'        => '',
			'deadline'       => $deadline,
			'days_remaining' => $days_remaining,
		);
	}

	/**
	 * Returns the most recent request stored on an order.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest_request( WC_Order $order ): ?array {
		$requests = $this->requests( $order );

		if ( empty( $requests ) ) {
			return null;
		}

		$request = end( $requests );

		return is_array( $request ) ? $request : null;
	}

	/**
	 * Shortcode for a standalone guest return page.
	 */
	public function return_shortcode( array|string $atts = array() ): string {
		unset( $atts );

		if ( wp_style_is( 'schrack-wc-account', 'registered' ) ) {
			wp_enqueue_style( 'schrack-wc-account' );
		}

		ob_start();
		?>
		<section class="schrack-account schrack-account--returns">
			<div class="schrack-account__inner">
				<?php if ( is_user_logged_in() ) : ?>
					<div class="schrack-account__panel schrack-account__panel--wide">
						<h3><?php esc_html_e( 'Retur din contul tau', 'schrack-woocommerce-sync' ); ?></h3>
						<p><?php esc_html_e( 'Deschide comanda din lista de comenzi si selecteaza produsele pe care vrei sa le returnezi.', 'schrack-woocommerce-sync' ); ?></p>
						<a class="schrack-account__button" href="<?php echo esc_url( $this->orders_url() ); ?>"><?php esc_html_e( 'Vezi comenzile mele', 'schrack-woocommerce-sync' ); ?></a>
					</div>
				<?php else : ?>
					<?php echo $this->render_guest_form( $this->current_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Adds a return action to the native WooCommerce orders table.
	 *
	 * @param array<string,array<string,string>> $actions Existing actions.
	 * @return array<string,array<string,string>>
	 */
	public function add_account_order_action( array $actions, WC_Order $order ): array {
		$eligibility = $this->get_eligibility( $order );
		$request     = $this->latest_request( $order );

		if ( $eligibility['eligible'] || is_array( $request ) ) {
			$actions['schrack-return'] = array(
				'url'  => $order->get_view_order_url() . '#schrack-order-return',
				'name' => is_array( $request ) ? __( 'Vezi returul', 'schrack-woocommerce-sync' ) : __( 'Solicita retur', 'schrack-woocommerce-sync' ),
			);
		}

		return $actions;
	}

	/**
	 * Renders the panel after the native WooCommerce order table.
	 */
	public function render_native_order_panel( WC_Order $order ): void {
		if ( is_admin() || ! is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		if ( function_exists( 'is_account_page' ) && ! is_account_page() ) {
			return;
		}

		if ( wp_style_is( 'schrack-wc-account', 'registered' ) ) {
			wp_enqueue_style( 'schrack-wc-account' );
		}

		echo '<div class="schrack-account schrack-account--native-return">';
		echo $this->render_order_return_panel( $order, $order->get_view_order_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Displays return information in the WooCommerce order edit screen.
	 */
	public function render_admin_order_data( WC_Order $order ): void {
		$requests = $this->requests( $order );

		if ( empty( $requests ) ) {
			return;
		}

		?>
		<div class="order_data_column schrack-admin-return-data">
			<h4><?php esc_html_e( 'Cerere de retur', 'schrack-woocommerce-sync' ); ?></h4>
			<?php foreach ( $requests as $request ) : ?>
				<?php
				$created_at = isset( $request['created_at'] ) ? (string) $request['created_at'] : '';
				$items      = isset( $request['items'] ) && is_array( $request['items'] ) ? $request['items'] : array();
				?>
				<div style="border-left:4px solid #b32d2e;margin:0 0 14px;padding:2px 0 2px 12px;">
					<p><strong><?php echo esc_html( (string) ( $request['id'] ?? __( 'Retur', 'schrack-woocommerce-sync' ) ) ); ?></strong><br>
						<?php echo esc_html( $this->request_status_label( (string) ( $request['status'] ?? 'requested' ) ) ); ?> · <?php echo esc_html( $this->format_gmt_date( $created_at ) ); ?></p>
					<p><strong><?php esc_html_e( 'Motiv:', 'schrack-woocommerce-sync' ); ?></strong> <?php echo esc_html( (string) ( $request['reason_label'] ?? '-' ) ); ?><br>
						<strong><?php esc_html_e( 'Email:', 'schrack-woocommerce-sync' ); ?></strong> <?php echo esc_html( (string) ( $request['email'] ?? '-' ) ); ?><br>
						<strong><?php esc_html_e( 'Telefon:', 'schrack-woocommerce-sync' ); ?></strong> <?php echo esc_html( (string) ( $request['phone'] ?? '-' ) ); ?></p>

					<?php if ( ! empty( $items ) ) : ?>
						<ul style="margin:0 0 10px 18px;">
							<?php foreach ( $items as $item ) : ?>
								<li><?php echo esc_html( (string) ( $item['quantity'] ?? 0 ) . ' × ' . (string) ( $item['name'] ?? '' ) . ( ! empty( $item['sku'] ) ? ' (SKU: ' . (string) $item['sku'] . ')' : '' ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php elseif ( ! empty( $request['guest_description'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Produse:', 'schrack-woocommerce-sync' ); ?></strong><br><?php echo nl2br( esc_html( (string) $request['guest_description'] ) ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $request['message'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Detalii:', 'schrack-woocommerce-sync' ); ?></strong><br><?php echo nl2br( esc_html( (string) $request['message'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Adds the return column to legacy and HPOS order lists.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_admin_return_column( array $columns ): array {
		$result   = array();
		$inserted = false;

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$result['schrack_return'] = __( 'Retur', 'schrack-woocommerce-sync' );
				$inserted = true;
			}
		}

		if ( ! $inserted ) {
			$result['schrack_return'] = __( 'Retur', 'schrack-woocommerce-sync' );
		}

		return $result;
	}

	/**
	 * Renders the return column for legacy order storage.
	 */
	public function render_legacy_admin_return_column( string $column, int $post_id ): void {
		if ( 'schrack_return' !== $column || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( $order instanceof WC_Order ) {
			$this->render_admin_return_column_value( $order );
		}
	}

	/**
	 * Renders the return column for HPOS order storage.
	 */
	public function render_hpos_admin_return_column( string $column, mixed $order ): void {
		if ( 'schrack_return' !== $column ) {
			return;
		}

		if ( ! $order instanceof WC_Order && is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $order ) );
		}

		if ( $order instanceof WC_Order ) {
			$this->render_admin_return_column_value( $order );
		}
	}

	/**
	 * Returns all valid request records from order meta.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function requests( WC_Order $order ): array {
		$value = $order->get_meta( self::META_KEY, true );

		if ( ! is_array( $value ) ) {
			return array();
		}

		if ( isset( $value['id'] ) ) {
			$value = array( $value );
		}

		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Validates item quantities posted by an authenticated customer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function validated_account_items( WC_Order $order ): array {
		$posted = isset( $_POST['return_items'] ) && is_array( $_POST['return_items'] ) ? wp_unslash( $_POST['return_items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$items  = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$quantity = isset( $posted[ $item_id ] ) ? absint( $posted[ $item_id ] ) : 0;
			$maximum  = max( 0, (int) $item->get_quantity() );

			if ( $quantity < 1 || $quantity > $maximum ) {
				continue;
			}

			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$items[] = array(
				'order_item_id' => (int) $item_id,
				'product_id'    => $product instanceof WC_Product ? (int) $product->get_id() : 0,
				'name'          => sanitize_text_field( $item->get_name() ),
				'sku'           => $product instanceof WC_Product ? sanitize_text_field( $product->get_sku() ) : '',
				'quantity'      => $quantity,
			);
		}

		return $items;
	}

	/**
	 * Finds a guest order by public order number plus billing email.
	 */
	private function find_guest_order( string $order_number, string $email ): ?WC_Order {
		$order_number = ltrim( trim( $order_number ), "# \t\n\r\0\x0B" );

		if ( '' === $order_number || ! is_email( $email ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		if ( ctype_digit( $order_number ) ) {
			$order = wc_get_order( absint( $order_number ) );

			if ( $order instanceof WC_Order && ( (string) $order->get_id() === $order_number || (string) $order->get_order_number() === $order_number ) ) {
				return $order;
			}
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 50,
				'orderby'       => 'date',
				'order'         => 'DESC',
			)
		);

		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof WC_Order && (string) $order->get_order_number() === $order_number ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Rate limits guest lookup/submission attempts by IP.
	 */
	private function guest_rate_limited(): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = self::RATE_TRANSIENT_PREFIX . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$count = absint( get_transient( $key ) );

		if ( $count >= 5 ) {
			return true;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * Creates a readable unique request ID.
	 */
	private function request_id( WC_Order $order ): string {
		$random = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		$random = strtoupper( substr( preg_replace( '/[^a-zA-Z0-9]/', '', $random ) ?: 'RETURN', 0, 8 ) );

		return sprintf( 'RET-%d-%s', $order->get_id(), $random );
	}

	/**
	 * Formats a return request as an internal order note.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	private function order_note( array $request ): string {
		$lines = array(
			__( 'Cerere de retur primita.', 'schrack-woocommerce-sync' ),
			'ID: ' . (string) $request['id'],
			__( 'Sursa:', 'schrack-woocommerce-sync' ) . ' ' . ( 'account' === $request['source'] ? __( 'cont client', 'schrack-woocommerce-sync' ) : __( 'formular vizitator', 'schrack-woocommerce-sync' ) ),
			__( 'Motiv:', 'schrack-woocommerce-sync' ) . ' ' . (string) $request['reason_label'],
		);

		if ( ! empty( $request['items'] ) && is_array( $request['items'] ) ) {
			foreach ( $request['items'] as $item ) {
				$lines[] = sprintf( '%d x %s%s', (int) $item['quantity'], (string) $item['name'], '' !== (string) $item['sku'] ? ' (SKU: ' . (string) $item['sku'] . ')' : '' );
			}
		} elseif ( '' !== (string) $request['guest_description'] ) {
			$lines[] = __( 'Produse:', 'schrack-woocommerce-sync' ) . ' ' . (string) $request['guest_description'];
		}

		if ( '' !== (string) $request['message'] ) {
			$lines[] = __( 'Detalii:', 'schrack-woocommerce-sync' ) . ' ' . (string) $request['message'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Sends admin and customer email notifications.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	private function send_notifications( WC_Order $order, array $request ): void {
		$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		$customer_email = sanitize_email( (string) $request['email'] );
		$subject = sprintf(
			/* translators: %s: order number. */
			__( 'Cerere de retur pentru comanda #%s', 'schrack-woocommerce-sync' ),
			$order->get_order_number()
		);
		$message = $this->order_note( $request );

		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			$message .= "\n" . __( 'Comanda:', 'schrack-woocommerce-sync' ) . ' ' . $order->get_edit_order_url();
		}

		if ( is_email( $admin_email ) ) {
			wp_mail( $admin_email, $subject, $message );
		}

		if ( is_email( $customer_email ) ) {
			$customer_subject = sprintf(
				/* translators: %s: order number. */
				__( 'Am inregistrat returul pentru comanda #%s', 'schrack-woocommerce-sync' ),
				$order->get_order_number()
			);
			$customer_message = implode(
				"\n",
				array(
					__( 'Cererea ta de retur a fost inregistrata.', 'schrack-woocommerce-sync' ),
					'ID: ' . (string) $request['id'],
					__( 'Echipa magazinului te va contacta cu pasii urmatori.', 'schrack-woocommerce-sync' ),
				)
			);
			wp_mail( $customer_email, $customer_subject, $customer_message );
		}
	}

	/**
	 * Renders a saved request to the customer.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	private function render_request_status( array $request ): string {
		$created_at = isset( $request['created_at'] ) ? $this->format_gmt_date( (string) $request['created_at'] ) : '';

		ob_start();
		?>
		<div class="schrack-account__return-message is-requested">
			<strong><?php echo esc_html( $this->request_status_label( (string) ( $request['status'] ?? 'requested' ) ) ); ?></strong>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: request ID, 2: request date. */
						__( 'Cererea %1$s a fost inregistrata la %2$s. Te vom contacta cu pasii urmatori.', 'schrack-woocommerce-sync' ),
						(string) ( $request['id'] ?? '-' ),
						$created_at
					)
				);
				?>
			</p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a value in the admin order-list return column.
	 */
	private function render_admin_return_column_value( WC_Order $order ): void {
		$request = $this->latest_request( $order );

		if ( ! is_array( $request ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		$created_at = isset( $request['created_at'] ) ? $this->format_gmt_date( (string) $request['created_at'], true ) : '';
		printf(
			'<mark class="order-status status-on-hold"><span>%1$s</span></mark><br><small>%2$s</small>',
			esc_html( $this->request_status_label( (string) ( $request['status'] ?? 'requested' ) ) ),
			esc_html( $created_at )
		);
	}

	/**
	 * Renders the return notice stored for the current redirect.
	 */
	private function render_notice(): string {
		$key = isset( $_GET[ self::NOTICE_QUERY_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::NOTICE_QUERY_ARG ] ) ) : '';

		if ( '' === $key ) {
			return '';
		}

		$notice = get_transient( self::NOTICE_TRANSIENT_PREFIX . $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return '';
		}

		delete_transient( self::NOTICE_TRANSIENT_PREFIX . $key );
		$type = 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error';

		return sprintf(
			'<div class="schrack-account__notice is-%1$s" role="status">%2$s</div>',
			esc_attr( $type ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Redirects with a short-lived return notice.
	 */
	private function redirect_with_notice( string $redirect, string $type, string $message ): never {
		$key = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'return_', true );
		$key = sanitize_key( $key );

		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . $key,
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => sanitize_text_field( $message ),
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY_ARG, $key, $redirect ) );
		exit;
	}

	/**
	 * Return reason options.
	 *
	 * @return array<string,string>
	 */
	private function return_reasons(): array {
		return array(
			'changed_mind'    => __( 'M-am razgandit', 'schrack-woocommerce-sync' ),
			'wrong_product'   => __( 'Produs comandat sau livrat gresit', 'schrack-woocommerce-sync' ),
			'defective'       => __( 'Produs defect sau deteriorat', 'schrack-woocommerce-sync' ),
			'not_as_expected' => __( 'Produsul nu corespunde asteptarilor', 'schrack-woocommerce-sync' ),
			'other'           => __( 'Alt motiv', 'schrack-woocommerce-sync' ),
		);
	}

	/**
	 * Renders the return reason select.
	 */
	private function reason_field(): string {
		ob_start();
		?>
		<label class="schrack-account__field">
			<span><?php esc_html_e( 'Motivul returului', 'schrack-woocommerce-sync' ); ?> *</span>
			<select name="return_reason" required>
				<option value=""><?php esc_html_e( 'Alege motivul', 'schrack-woocommerce-sync' ); ?></option>
				<?php foreach ( $this->return_reasons() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders an input field using account styles.
	 */
	private function input_field( string $name, string $label, string $type, bool $required, string $autocomplete, string $placeholder = '', string $value = '' ): string {
		return sprintf(
			'<label class="schrack-account__field"><span>%1$s%2$s</span><input type="%3$s" name="%4$s" value="%5$s" autocomplete="%6$s" placeholder="%7$s"%8$s></label>',
			esc_html( $label ),
			$required ? ' *' : '',
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $autocomplete ),
			esc_attr( $placeholder ),
			$required ? ' required' : ''
		);
	}

	/**
	 * Renders a textarea field using account styles.
	 */
	private function textarea_field( string $name, string $label, bool $required, string $placeholder ): string {
		return sprintf(
			'<label class="schrack-account__field schrack-account__field--wide"><span>%1$s%2$s</span><textarea name="%3$s" rows="4" placeholder="%4$s"%5$s></textarea></label>',
			esc_html( $label ),
			$required ? ' *' : '',
			esc_attr( $name ),
			esc_attr( $placeholder ),
			$required ? ' required' : ''
		);
	}

	/**
	 * Returns a translated request status.
	 */
	private function request_status_label( string $status ): string {
		return match ( sanitize_key( $status ) ) {
			'approved'  => __( 'Retur aprobat', 'schrack-woocommerce-sync' ),
			'rejected'  => __( 'Retur respins', 'schrack-woocommerce-sync' ),
			'received'  => __( 'Produse receptionate', 'schrack-woocommerce-sync' ),
			'refunded'  => __( 'Suma rambursata', 'schrack-woocommerce-sync' ),
			default     => __( 'Retur solicitat', 'schrack-woocommerce-sync' ),
		};
	}

	/**
	 * Formats a stored GMT timestamp in the site's timezone.
	 */
	private function format_gmt_date( string $value, bool $date_only = false ): string {
		if ( '' === $value ) {
			return '-';
		}

		$local = get_date_from_gmt( $value, $date_only ? 'Y-m-d' : get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		return '' !== $local ? $local : $value;
	}

	/**
	 * Reads sanitized single-line POST text.
	 */
	private function posted_text( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * Reads sanitized multiline POST text.
	 */
	private function posted_textarea( string $key ): string {
		$value = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 2000 ) : substr( $value, 0, 2000 );
	}

	/**
	 * Reads a safe redirect URL from POST.
	 */
	private function posted_url( string $key, string $fallback ): string {
		$value = isset( $_POST[ $key ] ) ? esc_url_raw( wp_unslash( (string) $_POST[ $key ] ) ) : '';

		return '' !== $value ? wp_validate_redirect( $value, $fallback ) : $fallback;
	}

	/**
	 * Returns the current URL without an old return notice.
	 */
	private function current_url(): string {
		$scheme      = is_ssl() ? 'https://' : 'http://';
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		$url         = esc_url_raw( $scheme . $host . $request_uri );

		return remove_query_arg( self::NOTICE_QUERY_ARG, $url );
	}

	/**
	 * Returns the configured account URL.
	 */
	private function account_url(): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Returns the native orders URL.
	 */
	private function orders_url(): string {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return wc_get_account_endpoint_url( 'orders' );
		}

		return $this->account_url();
	}
}
