<?php
/**
 * Frontend WooCommerce registration forms for Elementor widgets.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Registration_Renderer {
	public const ACTION       = 'schrack_wc_sync_register_user';
	public const NONCE_ACTION = 'schrack_wc_sync_register';

	private const NOTICE_QUERY_ARG      = 'schrack_register_notice';
	private const NOTICE_TRANSIENT_PREFIX = 'schrack_register_notice_';

	/**
	 * Renders a registration form.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $widget_id, string $mode = 'customer' ): string {
		$mode     = 'b2b' === $mode ? 'b2b' : 'customer';
		$settings = $this->normalize_settings( $settings, $mode );

		ob_start();
		?>
		<section
			class="schrack-register schrack-register--<?php echo esc_attr( $mode ); ?>"
			style="<?php echo esc_attr( $this->style_vars( $settings ) ); ?>"
			data-widget-id="<?php echo esc_attr( $widget_id ); ?>"
		>
			<div class="schrack-register__inner">
				<div class="schrack-register__head">
					<?php if ( '' !== $settings['eyebrow'] ) : ?>
						<div class="schrack-register__eyebrow"><?php echo esc_html( $settings['eyebrow'] ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== $settings['title'] ) : ?>
						<h2 class="schrack-register__title"><?php echo esc_html( $settings['title'] ); ?></h2>
					<?php endif; ?>
					<?php if ( '' !== $settings['subtitle'] ) : ?>
						<p class="schrack-register__subtitle"><?php echo esc_html( $settings['subtitle'] ); ?></p>
					<?php endif; ?>
				</div>

				<?php echo $this->render_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( is_user_logged_in() ) : ?>
					<?php echo $this->render_logged_in_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php elseif ( ! class_exists( 'WooCommerce' ) ) : ?>
					<div class="schrack-register__notice is-error"><?php esc_html_e( 'WooCommerce nu este disponibil.', 'schrack-woocommerce-sync' ); ?></div>
				<?php else : ?>
					<form class="schrack-register__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="registration_mode" value="<?php echo esc_attr( $mode ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->current_url() ); ?>">
						<input type="hidden" name="success_redirect" value="<?php echo esc_url( $settings['redirect_url'] ); ?>">
						<input type="hidden" name="terms_required" value="<?php echo esc_attr( $settings['require_terms'] ); ?>">
						<input type="hidden" name="auto_login" value="<?php echo esc_attr( $settings['auto_login'] ); ?>">
						<input class="schrack-register__website" type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
						<?php wp_nonce_field( self::NONCE_ACTION, 'schrack_register_nonce' ); ?>

						<div class="schrack-register__grid">
							<?php echo $this->field( 'first_name', __( 'Prenume', 'schrack-woocommerce-sync' ), 'text', true, 'given-name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->field( 'last_name', __( 'Nume', 'schrack-woocommerce-sync' ), 'text', true, 'family-name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->field( 'email', __( 'Email', 'schrack-woocommerce-sync' ), 'email', true, 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->field( 'phone', __( 'Telefon', 'schrack-woocommerce-sync' ), 'tel', 'b2b' === $mode, 'tel' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->field( 'password', __( 'Parola', 'schrack-woocommerce-sync' ), 'password', true, 'new-password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $this->field( 'password_confirm', __( 'Confirma parola', 'schrack-woocommerce-sync' ), 'password', true, 'new-password' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

							<?php if ( 'b2b' === $mode ) : ?>
								<?php echo $this->field( 'company_name', __( 'Companie', 'schrack-woocommerce-sync' ), 'text', true, 'organization', 'schrack-register__field--wide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $this->field( 'cui', __( 'CUI / Cod fiscal', 'schrack-woocommerce-sync' ), 'text', true, 'off' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $this->field( 'registration_number', __( 'Nr. Registrul Comertului', 'schrack-woocommerce-sync' ), 'text', false, 'off' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $this->field( 'fiscal_address', __( 'Adresa de facturare', 'schrack-woocommerce-sync' ), 'text', true, 'street-address', 'schrack-register__field--wide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $this->field( 'city', __( 'Oras', 'schrack-woocommerce-sync' ), 'text', true, 'address-level2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $this->field( 'county', __( 'Judet', 'schrack-woocommerce-sync' ), 'text', false, 'address-level1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $this->field( 'postal_code', __( 'Cod postal', 'schrack-woocommerce-sync' ), 'text', false, 'postal-code' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
						</div>

						<?php if ( 'yes' === $settings['require_terms'] ) : ?>
							<label class="schrack-register__terms">
								<input type="checkbox" name="terms" value="yes" required>
								<span>
									<?php echo esc_html( $settings['terms_text'] ); ?>
									<?php if ( '' !== $settings['terms_url'] ) : ?>
										<a href="<?php echo esc_url( $settings['terms_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Termeni si conditii', 'schrack-woocommerce-sync' ); ?></a>
									<?php endif; ?>
								</span>
							</label>
						<?php endif; ?>

						<label class="schrack-register__terms schrack-register__terms--newsletter">
							<input type="checkbox" name="<?php echo esc_attr( Schrack_Newsletter::FIELD_NAME ); ?>" value="yes">
							<span><?php esc_html_e( 'Doresc sa primesc noutati si oferte prin email.', 'schrack-woocommerce-sync' ); ?></span>
						</label>

						<button class="schrack-register__button" type="submit"><?php echo esc_html( $settings['button_text'] ); ?></button>

						<?php if ( 'yes' === $settings['show_login_link'] ) : ?>
							<p class="schrack-register__login">
								<?php esc_html_e( 'Ai deja cont?', 'schrack-woocommerce-sync' ); ?>
								<a href="<?php echo esc_url( $this->account_url() ); ?>"><?php esc_html_e( 'Autentifica-te', 'schrack-woocommerce-sync' ); ?></a>
							</p>
						<?php endif; ?>
					</form>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Handles registration form submissions.
	 */
	public function handle_registration(): void {
		$redirect = $this->posted_url( 'redirect_to', home_url( '/' ) );
		$nonce    = isset( $_POST['schrack_register_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['schrack_register_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Sesiunea formularului a expirat. Te rugam sa incerci din nou.', 'schrack-woocommerce-sync' ) );
		}

		if ( is_user_logged_in() ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Esti deja autentificat.', 'schrack-woocommerce-sync' ) );
		}

		if ( '' !== $this->posted_text( 'website' ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'Cererea nu a putut fi trimisa.', 'schrack-woocommerce-sync' ) );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->redirect_with_notice( $redirect, 'error', __( 'WooCommerce nu este disponibil.', 'schrack-woocommerce-sync' ) );
		}

		$mode     = 'b2b' === $this->posted_text( 'registration_mode' ) ? 'b2b' : 'customer';
		$data     = $this->posted_registration_data();
		$error    = $this->validate_registration_data( $data, $mode );

		if ( '' !== $error ) {
			$this->redirect_with_notice( $redirect, 'error', $error );
		}

		$user_id = $this->create_customer( $data );

		if ( is_wp_error( $user_id ) ) {
			$this->redirect_with_notice( $redirect, 'error', $user_id->get_error_message() );
		}

		$user_id = absint( $user_id );
		$this->store_customer_meta( $user_id, $data, $mode );

		if ( 'yes' === $this->posted_text( Schrack_Newsletter::FIELD_NAME ) ) {
			Schrack_Newsletter::set_user_subscription( $user_id, true, 'registration' );
		}

		if ( 'b2b' === $mode ) {
			$this->notify_b2b_request( $user_id, $data );
		}

		$success_redirect = $this->posted_url( 'success_redirect', '' );
		$target           = '' !== $success_redirect ? $success_redirect : $redirect;
		$message          = 'b2b' === $mode
			? __( 'Cererea B2B a fost trimisa. Te vom contacta dupa verificare.', 'schrack-woocommerce-sync' )
			: __( 'Contul a fost creat cu succes.', 'schrack-woocommerce-sync' );

		if ( 'customer' === $mode && 'yes' === $this->posted_text( 'auto_login' ) && function_exists( 'wc_set_customer_auth_cookie' ) ) {
			wc_set_customer_auth_cookie( $user_id );
		}

		$this->redirect_with_notice( $target, 'success', $message );
	}

	/**
	 * Normalizes widget settings.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,string>
	 */
	private function normalize_settings( array $settings, string $mode ): array {
		$defaults = array(
			'eyebrow'         => 'b2b' === $mode ? __( 'Cont companie', 'schrack-woocommerce-sync' ) : __( 'Cont client', 'schrack-woocommerce-sync' ),
			'title'           => 'b2b' === $mode ? __( 'Inregistrare B2B', 'schrack-woocommerce-sync' ) : __( 'Creeaza cont', 'schrack-woocommerce-sync' ),
			'subtitle'        => 'b2b' === $mode ? __( 'Trimite datele companiei pentru acces B2B si validare comerciala.', 'schrack-woocommerce-sync' ) : __( 'Creeaza rapid un cont pentru comenzi si istoric in magazin.', 'schrack-woocommerce-sync' ),
			'button_text'     => 'b2b' === $mode ? __( 'Trimite cererea B2B', 'schrack-woocommerce-sync' ) : __( 'Creeaza contul', 'schrack-woocommerce-sync' ),
			'redirect_url'    => '',
			'show_login_link' => 'yes',
			'require_terms'   => 'yes',
			'terms_text'      => __( 'Sunt de acord cu politica magazinului.', 'schrack-woocommerce-sync' ),
			'terms_url'       => '',
			'auto_login'      => 'customer' === $mode ? 'yes' : 'no',
			'accent_color'    => '#135e96',
			'action_color'    => '#b32d2e',
			'max_width'       => '760',
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'eyebrow', 'title', 'subtitle', 'button_text', 'terms_text' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_login_link', 'require_terms', 'auto_login' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		$settings['redirect_url'] = esc_url_raw( (string) $settings['redirect_url'] );
		$settings['terms_url']    = esc_url_raw( (string) $settings['terms_url'] );
		$settings['accent_color'] = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['action_color'] = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['max_width']    = (string) max( 320, min( 1040, absint( $settings['max_width'] ) ) );

		return $settings;
	}

	/**
	 * Returns safe CSS variables.
	 *
	 * @param array<string,string> $settings Settings.
	 */
	private function style_vars( array $settings ): string {
		return sprintf(
			'--schrack-register-accent:%s;--schrack-register-action:%s;--schrack-register-width:%spx;',
			$settings['accent_color'],
			$settings['action_color'],
			$settings['max_width']
		);
	}

	/**
	 * Renders one input field.
	 */
	private function field( string $name, string $label, string $type, bool $required, string $autocomplete, string $class = '' ): string {
		ob_start();
		?>
		<label class="schrack-register__field <?php echo esc_attr( $class ); ?>">
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
		$key = isset( $_GET[ self::NOTICE_QUERY_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::NOTICE_QUERY_ARG ] ) ) : '';

		if ( '' === $key ) {
			return '';
		}

		$notice = get_transient( self::NOTICE_TRANSIENT_PREFIX . $key );
		delete_transient( self::NOTICE_TRANSIENT_PREFIX . $key );

		if ( ! is_array( $notice ) ) {
			return '';
		}

		$type    = 'success' === (string) ( $notice['type'] ?? '' ) ? 'success' : 'error';
		$message = sanitize_text_field( (string) ( $notice['message'] ?? '' ) );

		if ( '' === $message ) {
			return '';
		}

		return sprintf(
			'<div class="schrack-register__notice is-%1$s">%2$s</div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Renders state for already logged-in users.
	 */
	private function render_logged_in_panel(): string {
		return sprintf(
			'<div class="schrack-register__notice is-success">%1$s <a href="%2$s">%3$s</a></div>',
			esc_html__( 'Esti deja autentificat.', 'schrack-woocommerce-sync' ),
			esc_url( $this->account_url() ),
			esc_html__( 'Mergi la contul meu', 'schrack-woocommerce-sync' )
		);
	}

	/**
	 * Returns the WooCommerce account URL.
	 */
	private function account_url(): string {
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

		return is_string( $account_url ) && '' !== $account_url ? $account_url : wp_login_url( $this->current_url() );
	}

	/**
	 * Reads and sanitizes posted registration data.
	 *
	 * @return array<string,string>
	 */
	private function posted_registration_data(): array {
		$keys = array(
			'first_name',
			'last_name',
			'email',
			'phone',
			'password',
			'password_confirm',
			'company_name',
			'cui',
			'registration_number',
			'fiscal_address',
			'city',
			'county',
			'postal_code',
		);
		$data = array();

		foreach ( $keys as $key ) {
			$value = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
			$data[ $key ] = in_array( $key, array( 'password', 'password_confirm' ), true )
				? (string) $value
				: sanitize_text_field( $value );
		}

		$data['email'] = sanitize_email( $data['email'] );

		return $data;
	}

	/**
	 * Validates posted data.
	 *
	 * @param array<string,string> $data Posted data.
	 */
	private function validate_registration_data( array $data, string $mode ): string {
		foreach ( array( 'first_name', 'last_name', 'email', 'password', 'password_confirm' ) as $key ) {
			if ( '' === trim( $data[ $key ] ?? '' ) ) {
				return __( 'Te rugam sa completezi toate campurile obligatorii.', 'schrack-woocommerce-sync' );
			}
		}

		if ( 'b2b' === $mode ) {
			foreach ( array( 'phone', 'company_name', 'cui', 'fiscal_address', 'city' ) as $key ) {
				if ( '' === trim( $data[ $key ] ?? '' ) ) {
					return __( 'Te rugam sa completezi toate campurile obligatorii pentru contul B2B.', 'schrack-woocommerce-sync' );
				}
			}
		}

		if ( ! is_email( $data['email'] ) ) {
			return __( 'Adresa de email nu este valida.', 'schrack-woocommerce-sync' );
		}

		if ( email_exists( $data['email'] ) ) {
			return __( 'Exista deja un cont cu aceasta adresa de email.', 'schrack-woocommerce-sync' );
		}

		if ( strlen( $data['password'] ) < 8 ) {
			return __( 'Parola trebuie sa aiba cel putin 8 caractere.', 'schrack-woocommerce-sync' );
		}

		if ( $data['password'] !== $data['password_confirm'] ) {
			return __( 'Parolele nu coincid.', 'schrack-woocommerce-sync' );
		}

		if ( 'yes' === $this->posted_text( 'terms_required' ) && 'yes' !== $this->posted_text( 'terms' ) ) {
			return __( 'Trebuie sa accepti termenii pentru a continua.', 'schrack-woocommerce-sync' );
		}

		return '';
	}

	/**
	 * Creates a WooCommerce customer.
	 *
	 * @param array<string,string> $data Posted data.
	 * @return int|WP_Error
	 */
	private function create_customer( array $data ): int|WP_Error {
		if ( function_exists( 'wc_create_new_customer' ) ) {
			return wc_create_new_customer(
				$data['email'],
				'',
				$data['password'],
				array(
					'first_name' => $data['first_name'],
					'last_name'  => $data['last_name'],
				)
			);
		}

		$user_id = wp_create_user( $data['email'], $data['password'], $data['email'] );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => $data['first_name'],
				'last_name'  => $data['last_name'],
			)
		);

		return (int) $user_id;
	}

	/**
	 * Stores WooCommerce billing and B2B metadata.
	 *
	 * @param array<string,string> $data Posted data.
	 */
	private function store_customer_meta( int $user_id, array $data, string $mode ): void {
		update_user_meta( $user_id, 'first_name', $data['first_name'] );
		update_user_meta( $user_id, 'last_name', $data['last_name'] );
		update_user_meta( $user_id, 'billing_first_name', $data['first_name'] );
		update_user_meta( $user_id, 'billing_last_name', $data['last_name'] );
		update_user_meta( $user_id, 'billing_email', $data['email'] );
		update_user_meta( $user_id, 'billing_phone', $data['phone'] );
		update_user_meta( $user_id, '_schrack_account_type', $mode );

		if ( 'b2b' !== $mode ) {
			return;
		}

		update_user_meta( $user_id, 'billing_company', $data['company_name'] );
		update_user_meta( $user_id, 'billing_address_1', $data['fiscal_address'] );
		update_user_meta( $user_id, 'billing_city', $data['city'] );
		update_user_meta( $user_id, 'billing_state', $data['county'] );
		update_user_meta( $user_id, 'billing_postcode', $data['postal_code'] );
		update_user_meta( $user_id, 'billing_country', 'RO' );
		update_user_meta( $user_id, '_schrack_b2b_status', 'pending' );
		update_user_meta( $user_id, '_schrack_b2b_company_name', $data['company_name'] );
		update_user_meta( $user_id, '_schrack_b2b_cui', $data['cui'] );
		update_user_meta( $user_id, '_schrack_b2b_registration_number', $data['registration_number'] );
		update_user_meta( $user_id, '_schrack_b2b_requested_at', current_time( 'mysql' ) );
		update_user_meta( $user_id, 'billing_vat_number', $data['cui'] );
	}

	/**
	 * Sends a lightweight admin notification for B2B requests.
	 *
	 * @param array<string,string> $data Posted data.
	 */
	private function notify_b2b_request( int $user_id, array $data ): void {
		$admin_email = get_option( 'admin_email' );

		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: company name. */
			__( 'Cerere noua B2B: %s', 'schrack-woocommerce-sync' ),
			$data['company_name']
		);
		$message = implode(
			"\n",
			array(
				'Cerere noua B2B',
				'User ID: ' . $user_id,
				'Companie: ' . $data['company_name'],
				'CUI: ' . $data['cui'],
				'Email: ' . $data['email'],
				'Telefon: ' . $data['phone'],
				'Oras: ' . $data['city'],
			)
		);

		wp_mail( $admin_email, $subject, $message );
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
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';

		if ( '' === $host ) {
			return home_url( '/' );
		}

		return esc_url_raw( remove_query_arg( self::NOTICE_QUERY_ARG, $scheme . $host . $request_uri ) );
	}
}
