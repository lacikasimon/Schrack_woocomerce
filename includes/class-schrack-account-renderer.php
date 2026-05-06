<?php
/**
 * Frontend account/login portal for Elementor and shortcode usage.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Account_Renderer {
	public const ACTION       = 'schrack_wc_sync_login_user';
	public const NONCE_ACTION = 'schrack_wc_sync_login';

	private const NOTICE_QUERY_ARG        = 'schrack_account_notice';
	private const NOTICE_TRANSIENT_PREFIX = 'schrack_account_notice_';

	/**
	 * Renders the account portal.
	 *
	 * @param array<string,mixed> $settings Widget/shortcode settings.
	 */
	public function render( array $settings, string $widget_id = 'account' ): string {
		if ( wp_style_is( 'schrack-wc-account', 'registered' ) ) {
			wp_enqueue_style( 'schrack-wc-account' );
		}

		$settings = $this->normalize_settings( $settings );

		ob_start();
		?>
		<section
			class="schrack-account"
			style="<?php echo esc_attr( $this->style_vars( $settings ) ); ?>"
			data-widget-id="<?php echo esc_attr( $widget_id ); ?>"
		>
			<div class="schrack-account__inner">
				<?php echo $this->header( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->render_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( is_user_logged_in() ) : ?>
					<?php echo $this->account_dashboard( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<?php echo $this->login_portal( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handles custom login form submissions.
	 */
	public function handle_login(): void {
		$redirect = $this->posted_url( 'redirect_to', $this->account_url() );
		$nonce    = isset( $_POST['schrack_login_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['schrack_login_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Sesiunea formularului a expirat. Te rugam sa incerci din nou.', 'schrack-woocommerce-sync' ) );
		}

		if ( is_user_logged_in() ) {
			$this->redirect_with_notice( $this->posted_url( 'success_redirect', $redirect ), 'success', __( 'Esti deja autentificat.', 'schrack-woocommerce-sync' ) );
		}

		if ( '' !== $this->posted_text( 'website' ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Cererea nu a putut fi trimisa.', 'schrack-woocommerce-sync' ) );
		}

		$username = $this->posted_text( 'username' );
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$remember = 'yes' === $this->posted_text( 'rememberme' );

		if ( '' === $username || '' === $password ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Completeaza emailul si parola pentru autentificare.', 'schrack-woocommerce-sync' ) );
		}

		if ( is_email( $username ) ) {
			$user_by_email = get_user_by( 'email', $username );

			if ( $user_by_email instanceof WP_User ) {
				$username = $user_by_email->user_login;
			}
		}

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Datele de autentificare nu sunt corecte.', 'schrack-woocommerce-sync' ) );
		}

		wp_set_current_user( (int) $user->ID );

		$target = $this->posted_url( 'success_redirect', $this->account_url() );
		$this->redirect_with_notice( $target, 'success', __( 'Autentificare reusita.', 'schrack-woocommerce-sync' ) );
	}

	/**
	 * Normalizes display settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,string>
	 */
	private function normalize_settings( array $settings ): array {
		$defaults = array(
			'eyebrow'               => __( 'Cont Syshub', 'schrack-woocommerce-sync' ),
			'title'                 => __( 'Autentificare si cont client', 'schrack-woocommerce-sync' ),
			'subtitle'              => __( 'Acces rapid la comenzi, facturare si cereri B2B pentru proiecte tehnice.', 'schrack-woocommerce-sync' ),
			'login_title'           => __( 'Intra in cont', 'schrack-woocommerce-sync' ),
			'login_subtitle'        => __( 'Foloseste emailul contului pentru istoric comenzi si date de facturare.', 'schrack-woocommerce-sync' ),
			'b2b_title'             => __( 'Acces B2B pentru firme', 'schrack-woocommerce-sync' ),
			'b2b_text'              => __( 'Clientii B2B pot trimite datele companiei pentru verificare, conditii comerciale si suport pe proiect.', 'schrack-woocommerce-sync' ),
			'b2b_button_text'       => __( 'Solicita cont B2B', 'schrack-woocommerce-sync' ),
			'customer_button_text'  => __( 'Creeaza cont client', 'schrack-woocommerce-sync' ),
			'register_title'        => __( 'Nu ai cont?', 'schrack-woocommerce-sync' ),
			'success_redirect'      => '',
			'customer_register_url' => '',
			'b2b_register_url'      => '',
			'shop_url'              => '',
			'support_url'           => '',
			'show_b2b_panel'        => 'yes',
			'show_recent_orders'    => 'yes',
			'accent_color'          => '#135e96',
			'action_color'          => '#b32d2e',
			'max_width'             => '1120',
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'eyebrow', 'title', 'subtitle', 'login_title', 'login_subtitle', 'b2b_title', 'b2b_text', 'b2b_button_text', 'customer_button_text', 'register_title' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'success_redirect', 'customer_register_url', 'b2b_register_url', 'shop_url', 'support_url' ) as $key ) {
			$settings[ $key ] = esc_url_raw( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_b2b_panel', 'show_recent_orders' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		$settings['accent_color'] = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['action_color'] = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['max_width']    = (string) max( 360, min( 1320, absint( $settings['max_width'] ) ) );

		return $settings;
	}

	/**
	 * Returns safe CSS custom properties.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function style_vars( array $settings ): string {
		return sprintf(
			'--schrack-account-accent:%s;--schrack-account-action:%s;--schrack-account-width:%spx;',
			$settings['accent_color'],
			$settings['action_color'],
			$settings['max_width']
		);
	}

	/**
	 * Renders the shared header.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function header( array $settings ): string {
		ob_start();
		?>
		<div class="schrack-account__head">
			<div>
				<?php if ( '' !== $settings['eyebrow'] ) : ?>
					<div class="schrack-account__eyebrow"><?php echo esc_html( $settings['eyebrow'] ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $settings['title'] ) : ?>
					<h2 class="schrack-account__title"><?php echo esc_html( $settings['title'] ); ?></h2>
				<?php endif; ?>
				<?php if ( '' !== $settings['subtitle'] ) : ?>
					<p class="schrack-account__subtitle"><?php echo esc_html( $settings['subtitle'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders login and B2B entry panels for guests.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function login_portal( array $settings ): string {
		$success_redirect = '' !== $settings['success_redirect'] ? $settings['success_redirect'] : $this->current_url();

		ob_start();
		?>
		<div class="schrack-account__guest-grid">
			<div class="schrack-account__panel schrack-account__panel--login">
				<h3><?php echo esc_html( $settings['login_title'] ); ?></h3>
				<p><?php echo esc_html( $settings['login_subtitle'] ); ?></p>

				<form class="schrack-account__login-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->current_url() ); ?>">
					<input type="hidden" name="success_redirect" value="<?php echo esc_url( $success_redirect ); ?>">
					<input class="schrack-account__website" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
					<?php wp_nonce_field( self::NONCE_ACTION, 'schrack_login_nonce' ); ?>

					<?php echo $this->field( 'username', __( 'Email sau utilizator', 'schrack-woocommerce-sync' ), 'text', true, 'username' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->field( 'password', __( 'Parola', 'schrack-woocommerce-sync' ), 'password', true, 'current-password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<div class="schrack-account__form-row">
						<label class="schrack-account__remember">
							<input type="checkbox" name="rememberme" value="yes">
							<span><?php esc_html_e( 'Pastreaza-ma autentificat', 'schrack-woocommerce-sync' ); ?></span>
						</label>
						<a href="<?php echo esc_url( $this->lost_password_url() ); ?>"><?php esc_html_e( 'Ai uitat parola?', 'schrack-woocommerce-sync' ); ?></a>
					</div>

					<button class="schrack-account__button" type="submit"><?php esc_html_e( 'Autentifica-te', 'schrack-woocommerce-sync' ); ?></button>
				</form>

				<?php if ( '' !== $settings['customer_register_url'] ) : ?>
					<p class="schrack-account__small-link">
						<?php esc_html_e( 'Nu ai cont?', 'schrack-woocommerce-sync' ); ?>
						<a href="<?php echo esc_url( $settings['customer_register_url'] ); ?>"><?php echo esc_html( $settings['customer_button_text'] ); ?></a>
					</p>
				<?php endif; ?>
			</div>

			<?php echo $this->registration_portal( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders direct B2C and B2B registration choices for guests.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function registration_portal( array $settings ): string {
		ob_start();
		?>
		<div class="schrack-account__panel schrack-account__panel--register">
			<span class="schrack-account__tag"><?php esc_html_e( 'Inregistrare', 'schrack-woocommerce-sync' ); ?></span>
			<h3><?php echo esc_html( $settings['register_title'] ); ?></h3>

			<div class="schrack-account__register-options">
				<details class="schrack-account__register-option" open>
					<summary>
						<span><?php esc_html_e( 'Client B2C', 'schrack-woocommerce-sync' ); ?></span>
						<small><?php esc_html_e( 'Comenzi rapide si istoric in cont.', 'schrack-woocommerce-sync' ); ?></small>
					</summary>
					<?php echo $this->registration_form( 'customer', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</details>

				<?php if ( 'yes' === $settings['show_b2b_panel'] ) : ?>
					<details class="schrack-account__register-option">
						<summary>
							<span><?php esc_html_e( 'Companie B2B', 'schrack-woocommerce-sync' ); ?></span>
							<small><?php esc_html_e( 'Validare firma si acces comercial.', 'schrack-woocommerce-sync' ); ?></small>
						</summary>
						<p><?php echo esc_html( $settings['b2b_text'] ); ?></p>
						<ul class="schrack-account__checks">
							<li><?php esc_html_e( 'Date de companie si facturare pastrate in cont.', 'schrack-woocommerce-sync' ); ?></li>
							<li><?php esc_html_e( 'Cerere verificata manual inainte de activare.', 'schrack-woocommerce-sync' ); ?></li>
							<li><?php esc_html_e( 'Istoric comenzi si suport pentru proiecte recurente.', 'schrack-woocommerce-sync' ); ?></li>
						</ul>
						<?php echo $this->registration_form( 'b2b', $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</details>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a compact registration form that posts to the existing registration handler.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function registration_form( string $mode, array $settings ): string {
		$mode       = 'b2b' === $mode ? 'b2b' : 'customer';
		$button     = 'b2b' === $mode ? $settings['b2b_button_text'] : $settings['customer_button_text'];
		$redirect   = $this->current_url();
		$auto_login = 'customer' === $mode ? 'yes' : 'no';

		ob_start();
		?>
		<form class="schrack-account__register-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Schrack_Registration_Renderer::ACTION ); ?>">
			<input type="hidden" name="registration_mode" value="<?php echo esc_attr( $mode ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
			<input type="hidden" name="success_redirect" value="<?php echo esc_url( $redirect ); ?>">
			<input type="hidden" name="terms_required" value="yes">
			<input type="hidden" name="auto_login" value="<?php echo esc_attr( $auto_login ); ?>">
			<input class="schrack-account__website" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
			<?php wp_nonce_field( Schrack_Registration_Renderer::NONCE_ACTION, 'schrack_register_nonce' ); ?>

			<div class="schrack-account__register-grid">
				<?php echo $this->field( 'first_name', __( 'Prenume', 'schrack-woocommerce-sync' ), 'text', true, 'given-name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->field( 'last_name', __( 'Nume', 'schrack-woocommerce-sync' ), 'text', true, 'family-name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->field( 'email', __( 'Email', 'schrack-woocommerce-sync' ), 'email', true, 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->field( 'phone', __( 'Telefon', 'schrack-woocommerce-sync' ), 'tel', 'b2b' === $mode, 'tel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->field( 'password', __( 'Parola', 'schrack-woocommerce-sync' ), 'password', true, 'new-password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->field( 'password_confirm', __( 'Confirma parola', 'schrack-woocommerce-sync' ), 'password', true, 'new-password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( 'b2b' === $mode ) : ?>
					<?php echo $this->field( 'company_name', __( 'Companie', 'schrack-woocommerce-sync' ), 'text', true, 'organization', 'schrack-account__field--wide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->field( 'cui', __( 'CUI / Cod fiscal', 'schrack-woocommerce-sync' ), 'text', true, 'off' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->field( 'registration_number', __( 'Nr. Registrul Comertului', 'schrack-woocommerce-sync' ), 'text', false, 'off' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->field( 'fiscal_address', __( 'Adresa de facturare', 'schrack-woocommerce-sync' ), 'text', true, 'street-address', 'schrack-account__field--wide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->field( 'city', __( 'Oras', 'schrack-woocommerce-sync' ), 'text', true, 'address-level2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->field( 'county', __( 'Judet', 'schrack-woocommerce-sync' ), 'text', false, 'address-level1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->field( 'postal_code', __( 'Cod postal', 'schrack-woocommerce-sync' ), 'text', false, 'postal-code' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>

			<label class="schrack-account__terms">
				<input type="checkbox" name="terms" value="yes" required>
				<span><?php esc_html_e( 'Sunt de acord cu politica magazinului.', 'schrack-woocommerce-sync' ); ?></span>
			</label>

			<button class="schrack-account__button" type="submit"><?php echo esc_html( $button ); ?></button>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders account dashboard for authenticated users.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function account_dashboard( array $settings ): string {
		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User || 0 === (int) $user->ID ) {
			return '';
		}

		$user_id       = (int) $user->ID;
		$display_name  = '' !== trim( $user->display_name ) ? $user->display_name : $user->user_email;
		$account_type  = $this->account_type( $user_id );
		$b2b_status    = $this->b2b_status( $user_id, $account_type );
		$status_config = $this->b2b_status_config( $b2b_status, $account_type );

		ob_start();
		?>
		<div class="schrack-account__dashboard">
			<div class="schrack-account__welcome">
				<div>
					<span class="schrack-account__eyebrow"><?php esc_html_e( 'Bine ai revenit', 'schrack-woocommerce-sync' ); ?></span>
					<h3><?php echo esc_html( $display_name ); ?></h3>
					<p><?php echo esc_html( $status_config['description'] ); ?></p>
				</div>
				<div class="schrack-account__welcome-actions">
					<span class="schrack-account__status <?php echo esc_attr( $status_config['class'] ); ?>"><?php echo esc_html( $status_config['label'] ); ?></span>
					<a class="schrack-account__ghost-button" href="<?php echo esc_url( $this->logout_url() ); ?>"><?php esc_html_e( 'Deconectare', 'schrack-woocommerce-sync' ); ?></a>
				</div>
			</div>

			<div class="schrack-account__summary">
				<?php echo $this->summary_card( __( 'Comenzi', 'schrack-woocommerce-sync' ), (string) $this->customer_order_count( $user_id ), __( 'Total comenzi in cont', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->summary_card( __( 'Tip cont', 'schrack-woocommerce-sync' ), 'b2b' === $account_type ? __( 'B2B', 'schrack-woocommerce-sync' ) : __( 'Client', 'schrack-woocommerce-sync' ), __( 'Profil comercial', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->summary_card( __( 'Companie', 'schrack-woocommerce-sync' ), $this->billing_company_label( $user_id ), __( 'Date de facturare', 'schrack-woocommerce-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="schrack-account__content-grid">
				<?php if ( 'yes' === $settings['show_b2b_panel'] ) : ?>
					<?php echo $this->b2b_panel( $user_id, $account_type, $status_config, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php echo $this->account_links_panel( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( 'yes' === $settings['show_recent_orders'] ) : ?>
					<?php echo $this->recent_orders_panel( $user_id, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php echo $this->billing_panel( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a compact summary card.
	 */
	private function summary_card( string $label, string $value, string $meta ): string {
		return sprintf(
			'<div class="schrack-account__summary-card"><span>%1$s</span><strong>%2$s</strong><small>%3$s</small></div>',
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $meta )
		);
	}

	/**
	 * Renders B2B details or upgrade CTA.
	 *
	 * @param array<string,string> $status_config Status display config.
	 * @param array<string,string> $settings Settings.
	 */
	private function b2b_panel( int $user_id, string $account_type, array $status_config, array $settings ): string {
		$is_b2b = 'b2b' === $account_type;
		$company = $this->user_meta( $user_id, '_schrack_b2b_company_name' );
		$cui     = $this->user_meta( $user_id, '_schrack_b2b_cui' );
		$reg     = $this->user_meta( $user_id, '_schrack_b2b_registration_number' );

		if ( '' === $company ) {
			$company = $this->user_meta( $user_id, 'billing_company' );
		}

		ob_start();
		?>
		<div class="schrack-account__panel">
			<h3><?php esc_html_e( 'Profil B2B', 'schrack-woocommerce-sync' ); ?></h3>
			<p><?php echo esc_html( $status_config['description'] ); ?></p>

			<?php if ( $is_b2b ) : ?>
				<dl class="schrack-account__details">
					<?php echo $this->detail_row( __( 'Status', 'schrack-woocommerce-sync' ), $status_config['label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->detail_row( __( 'Companie', 'schrack-woocommerce-sync' ), $company ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->detail_row( __( 'CUI / Cod fiscal', 'schrack-woocommerce-sync' ), $cui ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->detail_row( __( 'Reg. Com.', 'schrack-woocommerce-sync' ), $reg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</dl>
			<?php elseif ( '' !== $settings['b2b_register_url'] ) : ?>
				<a class="schrack-account__button is-secondary" href="<?php echo esc_url( $settings['b2b_register_url'] ); ?>"><?php echo esc_html( $settings['b2b_button_text'] ); ?></a>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders account quick links.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function account_links_panel( array $settings ): string {
		$links = array(
			array( 'label' => __( 'Comenzile mele', 'schrack-woocommerce-sync' ), 'url' => $this->account_endpoint_url( 'orders' ) ),
			array( 'label' => __( 'Adrese facturare/livrare', 'schrack-woocommerce-sync' ), 'url' => $this->account_endpoint_url( 'edit-address' ) ),
			array( 'label' => __( 'Detalii cont', 'schrack-woocommerce-sync' ), 'url' => $this->account_endpoint_url( 'edit-account' ) ),
			array( 'label' => __( 'Cosul meu', 'schrack-woocommerce-sync' ), 'url' => $this->cart_url() ),
		);

		if ( '' !== $settings['shop_url'] ) {
			$links[] = array( 'label' => __( 'Continua cumparaturile', 'schrack-woocommerce-sync' ), 'url' => $settings['shop_url'] );
		}

		if ( '' !== $settings['support_url'] ) {
			$links[] = array( 'label' => __( 'Suport proiect', 'schrack-woocommerce-sync' ), 'url' => $settings['support_url'] );
		}

		ob_start();
		?>
		<div class="schrack-account__panel">
			<h3><?php esc_html_e( 'Actiuni rapide', 'schrack-woocommerce-sync' ); ?></h3>
			<div class="schrack-account__link-list">
				<?php foreach ( $links as $link ) : ?>
					<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders recent WooCommerce orders.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function recent_orders_panel( int $user_id, array $settings ): string {
		$orders = $this->recent_orders( $user_id );

		ob_start();
		?>
		<div class="schrack-account__panel schrack-account__panel--wide">
			<div class="schrack-account__panel-head">
				<h3><?php esc_html_e( 'Comenzi recente', 'schrack-woocommerce-sync' ); ?></h3>
				<a href="<?php echo esc_url( $this->account_endpoint_url( 'orders' ) ); ?>"><?php esc_html_e( 'Vezi toate', 'schrack-woocommerce-sync' ); ?></a>
			</div>

			<?php if ( empty( $orders ) ) : ?>
				<div class="schrack-account__empty">
					<p><?php esc_html_e( 'Nu exista inca ordine in acest cont.', 'schrack-woocommerce-sync' ); ?></p>
					<?php if ( '' !== $settings['shop_url'] ) : ?>
						<a class="schrack-account__button" href="<?php echo esc_url( $settings['shop_url'] ); ?>"><?php esc_html_e( 'Vezi catalogul', 'schrack-woocommerce-sync' ); ?></a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="schrack-account__orders">
					<?php foreach ( $orders as $order ) : ?>
						<?php if ( $order instanceof WC_Order ) : ?>
							<?php $order_date = $order->get_date_created(); ?>
							<a class="schrack-account__order" href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
								<span>#<?php echo esc_html( $order->get_order_number() ); ?></span>
								<small><?php echo esc_html( $order_date ? wc_format_datetime( $order_date ) : '-' ); ?></small>
								<em><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></em>
								<strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong>
							</a>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders billing snapshot.
	 */
	private function billing_panel( int $user_id ): string {
		$rows = array(
			__( 'Nume', 'schrack-woocommerce-sync' )     => trim( $this->user_meta( $user_id, 'billing_first_name' ) . ' ' . $this->user_meta( $user_id, 'billing_last_name' ) ),
			__( 'Email', 'schrack-woocommerce-sync' )    => $this->user_meta( $user_id, 'billing_email' ),
			__( 'Telefon', 'schrack-woocommerce-sync' )  => $this->user_meta( $user_id, 'billing_phone' ),
			__( 'Oras', 'schrack-woocommerce-sync' )     => $this->user_meta( $user_id, 'billing_city' ),
			__( 'Judet', 'schrack-woocommerce-sync' )    => $this->user_meta( $user_id, 'billing_state' ),
		);

		ob_start();
		?>
		<div class="schrack-account__panel">
			<div class="schrack-account__panel-head">
				<h3><?php esc_html_e( 'Date facturare', 'schrack-woocommerce-sync' ); ?></h3>
				<a href="<?php echo esc_url( $this->account_endpoint_url( 'edit-address' ) ); ?>"><?php esc_html_e( 'Editeaza', 'schrack-woocommerce-sync' ); ?></a>
			</div>
			<dl class="schrack-account__details">
				<?php foreach ( $rows as $label => $value ) : ?>
					<?php echo $this->detail_row( (string) $label, (string) $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</dl>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders one definition-list row.
	 */
	private function detail_row( string $label, string $value ): string {
		$value = '' !== trim( $value ) ? $value : __( 'Necompletat', 'schrack-woocommerce-sync' );

		return sprintf(
			'<div><dt>%1$s</dt><dd>%2$s</dd></div>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Renders one login input field.
	 */
	private function field( string $name, string $label, string $type, bool $required, string $autocomplete, string $class = '' ): string {
		ob_start();
		?>
		<label class="schrack-account__field <?php echo esc_attr( $class ); ?>">
			<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
				<?php echo $required ? 'required' : ''; ?>
			>
		</label>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders notice from transient.
	 */
	private function render_notice(): string {
		$notice = $this->notice_from_query( self::NOTICE_QUERY_ARG, self::NOTICE_TRANSIENT_PREFIX );

		if ( '' === $notice ) {
			$notice = $this->notice_from_query( 'schrack_register_notice', 'schrack_register_notice_' );
		}

		return $notice;
	}

	/**
	 * Renders one transient notice from a query argument.
	 */
	private function notice_from_query( string $query_arg, string $transient_prefix ): string {
		$key = isset( $_GET[ $query_arg ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $query_arg ] ) ) : '';

		if ( '' === $key ) {
			return '';
		}

		$notice = get_transient( $transient_prefix . $key );
		delete_transient( $transient_prefix . $key );

		if ( ! is_array( $notice ) ) {
			return '';
		}

		$type    = 'success' === (string) ( $notice['type'] ?? '' ) ? 'success' : 'error';
		$message = sanitize_text_field( (string) ( $notice['message'] ?? '' ) );

		if ( '' === $message ) {
			return '';
		}

		return sprintf(
			'<div class="schrack-account__notice is-%1$s">%2$s</div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Returns account type from user meta.
	 */
	private function account_type( int $user_id ): string {
		$type = sanitize_key( $this->user_meta( $user_id, '_schrack_account_type' ) );

		if ( 'b2b' === $type ) {
			return 'b2b';
		}

		if ( '' !== $this->user_meta( $user_id, '_schrack_b2b_cui' ) || '' !== $this->user_meta( $user_id, 'billing_vat_number' ) ) {
			return 'b2b';
		}

		return 'customer';
	}

	/**
	 * Returns normalized B2B status.
	 */
	private function b2b_status( int $user_id, string $account_type ): string {
		if ( 'b2b' !== $account_type ) {
			return 'standard';
		}

		$status = sanitize_key( $this->user_meta( $user_id, '_schrack_b2b_status' ) );

		return '' !== $status ? $status : 'pending';
	}

	/**
	 * Returns status label, class and description.
	 *
	 * @return array{label:string,class:string,description:string}
	 */
	private function b2b_status_config( string $status, string $account_type ): array {
		if ( 'b2b' !== $account_type ) {
			return array(
				'label'       => __( 'Client standard', 'schrack-woocommerce-sync' ),
				'class'       => 'is-standard',
				'description' => __( 'Poti comanda ca persoana fizica sau poti solicita ulterior validare B2B.', 'schrack-woocommerce-sync' ),
			);
		}

		return match ( $status ) {
			'approved', 'active' => array(
				'label'       => __( 'B2B activ', 'schrack-woocommerce-sync' ),
				'class'       => 'is-approved',
				'description' => __( 'Contul companiei este validat pentru fluxul B2B Syshub.', 'schrack-woocommerce-sync' ),
			),
			'rejected', 'disabled' => array(
				'label'       => __( 'B2B neactiv', 'schrack-woocommerce-sync' ),
				'class'       => 'is-rejected',
				'description' => __( 'Cererea B2B necesita clarificari. Contacteaza echipa pentru detalii.', 'schrack-woocommerce-sync' ),
			),
			default => array(
				'label'       => __( 'B2B in verificare', 'schrack-woocommerce-sync' ),
				'class'       => 'is-pending',
				'description' => __( 'Cererea B2B este inregistrata si asteapta validarea datelor companiei.', 'schrack-woocommerce-sync' ),
			),
		};
	}

	/**
	 * Returns recent WooCommerce orders.
	 *
	 * @return array<int,WC_Order>
	 */
	private function recent_orders( int $user_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 4,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Returns customer order count.
	 */
	private function customer_order_count( int $user_id ): int {
		if ( function_exists( 'wc_get_customer_order_count' ) ) {
			return absint( wc_get_customer_order_count( $user_id ) );
		}

		return count( $this->recent_orders( $user_id ) );
	}

	/**
	 * Returns billing company label.
	 */
	private function billing_company_label( int $user_id ): string {
		$company = $this->user_meta( $user_id, 'billing_company' );

		return '' !== $company ? $company : __( 'Necompletat', 'schrack-woocommerce-sync' );
	}

	/**
	 * Reads one user meta value.
	 */
	private function user_meta( int $user_id, string $key ): string {
		$value = get_user_meta( $user_id, $key, true );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Returns the WooCommerce account URL.
	 */
	private function account_url(): string {
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

		return is_string( $account_url ) && '' !== $account_url ? $account_url : wp_login_url( $this->current_url() );
	}

	/**
	 * Returns a WooCommerce account endpoint URL.
	 */
	private function account_endpoint_url( string $endpoint ): string {
		return function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( $endpoint )
			: $this->account_url();
	}

	/**
	 * Returns cart URL.
	 */
	private function cart_url(): string {
		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';

		return is_string( $cart_url ) && '' !== $cart_url ? $cart_url : home_url( '/' );
	}

	/**
	 * Returns lost password URL.
	 */
	private function lost_password_url(): string {
		if ( function_exists( 'wc_lostpassword_url' ) ) {
			return wc_lostpassword_url();
		}

		return wp_lostpassword_url( $this->current_url() );
	}

	/**
	 * Returns logout URL.
	 */
	private function logout_url(): string {
		return function_exists( 'wc_logout_url' )
			? wc_logout_url( $this->current_url() )
			: wp_logout_url( $this->current_url() );
	}

	/**
	 * Redirects back with a short-lived notice.
	 */
	private function redirect_with_notice( string $redirect, string $type, string $message ): never {
		$key = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'notice_', true );
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
	 * Reads text from POST.
	 */
	private function posted_text( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * Reads and validates posted URLs.
	 */
	private function posted_url( string $key, string $fallback ): string {
		$value = isset( $_POST[ $key ] ) ? esc_url_raw( wp_unslash( (string) $_POST[ $key ] ) ) : '';

		if ( '' === $value ) {
			return $fallback;
		}

		return wp_validate_redirect( $value, $fallback );
	}

	/**
	 * Returns current request URL without notice parameters.
	 */
	private function current_url(): string {
		$scheme      = is_ssl() ? 'https://' : 'http://';
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		$url         = esc_url_raw( $scheme . $host . $request_uri );

		return remove_query_arg( array( self::NOTICE_QUERY_ARG, 'schrack_register_notice' ), $url );
	}
}
