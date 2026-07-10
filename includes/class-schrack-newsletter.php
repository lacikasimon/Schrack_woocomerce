<?php
/**
 * Newsletter subscriptions for registration, checkout, customer accounts and admin.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Newsletter {
	public const FIELD_NAME = 'schrack_newsletter_subscribe';

	private const ACCOUNT_PRESENT   = 'schrack_newsletter_account_present';
	private const CAPABILITY        = 'manage_woocommerce';
	private const CHECKOUT_PRESENT  = 'schrack_newsletter_checkout_present';
	private const DB_VERSION        = '1.0.0';
	private const DB_VERSION_OPTION = 'schrack_newsletter_db_version';
	private const META_STATUS       = '_schrack_newsletter_subscribed';
	private const META_SOURCE       = '_schrack_newsletter_source';
	private const META_UPDATED_AT   = '_schrack_newsletter_updated_at';
	private const PAGE_SLUG         = 'schrack-sync-newsletter';

	/**
	 * Registers newsletter hooks.
	 */
	public function init(): void {
		self::maybe_install_table();

		add_action( 'woocommerce_after_order_notes', array( $this, 'render_checkout_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_checkout_subscription' ), 20, 2 );
		add_action( 'woocommerce_register_form', array( $this, 'render_woocommerce_registration_field' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'save_woocommerce_registration_subscription' ), 20 );
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_woocommerce_account_field' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_woocommerce_account_subscription' ), 20 );
		add_action( 'profile_update', array( $this, 'sync_subscribed_user_profile' ), 20, 2 );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_schrack_wc_sync_export_newsletter', array( $this, 'export_subscribers' ) );
	}

	/**
	 * Creates or updates the newsletter table.
	 */
	public static function install_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			source varchar(50) NOT NULL DEFAULT '',
			subscribed_at datetime NULL,
			unsubscribed_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$installed_table = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);

		if ( $table_name === $installed_table ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Installs schema changes after a plugin update without requiring reactivation.
	 */
	private static function maybe_install_table(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install_table();
		}
	}

	/**
	 * Renders the optional checkout opt-in.
	 */
	public function render_checkout_field(): void {
		if ( ! function_exists( 'woocommerce_form_field' ) ) {
			return;
		}

		$checked = is_user_logged_in() && self::is_user_subscribed( get_current_user_id() );

		echo '<div class="schrack-newsletter-checkout"><h3>' . esc_html__( 'Noutati si oferte', 'schrack-woocommerce-sync' ) . '</h3>';
		echo '<input type="hidden" name="' . esc_attr( self::CHECKOUT_PRESENT ) . '" value="yes">';
		woocommerce_form_field(
			self::FIELD_NAME,
			array(
				'type'        => 'checkbox',
				'class'       => array( 'form-row-wide', 'schrack-newsletter-checkout__field' ),
				'label_class' => array( 'woocommerce-form__label', 'woocommerce-form__label-for-checkbox', 'checkbox' ),
				'input_class' => array( 'woocommerce-form__input', 'woocommerce-form__input-checkbox', 'input-checkbox' ),
				'label'       => __( 'Doresc sa primesc noutati si oferte prin email.', 'schrack-woocommerce-sync' ),
				'required'    => false,
			),
			$checked ? 1 : 0
		);
		echo '<p class="schrack-newsletter-checkout__description">' . esc_html__( 'Preferinta poate fi schimbata oricand din contul tau.', 'schrack-woocommerce-sync' ) . '</p></div>';
	}

	/**
	 * Saves a checkout opt-in for registered customers and guests.
	 *
	 * @param WC_Order            $order Checkout order.
	 * @param array<string,mixed> $data  Checkout data.
	 */
	public function save_checkout_subscription( WC_Order $order, array $data ): void {
		if ( ! self::checkout_field_was_present() ) {
			return;
		}

		$subscribed = self::posted_opt_in();
		$order->update_meta_data( '_schrack_newsletter_subscribed', $subscribed ? 'yes' : 'no' );
		$user_id = absint( $order->get_customer_id() );

		if ( ! $subscribed ) {
			if ( $user_id > 0 && self::is_user_subscribed( $user_id ) ) {
				self::set_user_subscription( $user_id, false, 'checkout' );
			}

			return;
		}

		$email = sanitize_email( (string) $order->get_billing_email() );

		if ( ! is_email( $email ) && isset( $data['billing_email'] ) ) {
			$email = sanitize_email( (string) $data['billing_email'] );
		}

		if ( ! is_email( $email ) ) {
			return;
		}

		self::save_subscription(
			$email,
			$user_id,
			(string) ( $order->get_billing_first_name() ?: ( $data['billing_first_name'] ?? '' ) ),
			(string) ( $order->get_billing_last_name() ?: ( $data['billing_last_name'] ?? '' ) ),
			true,
			'checkout'
		);

		if ( $user_id > 0 ) {
			self::update_user_subscription_meta( $user_id, true, 'checkout' );
		}
	}

	/**
	 * Renders the opt-in in the default WooCommerce registration form.
	 */
	public function render_woocommerce_registration_field(): void {
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide schrack-newsletter-registration">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input class="woocommerce-form__input woocommerce-form__input-checkbox" type="checkbox" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="yes">
				<span><?php esc_html_e( 'Doresc sa primesc noutati si oferte prin email.', 'schrack-woocommerce-sync' ); ?></span>
			</label>
		</p>
		<?php
	}

	/**
	 * Saves the default WooCommerce registration opt-in.
	 */
	public function save_woocommerce_registration_subscription( int $customer_id ): void {
		if ( self::posted_opt_in() ) {
			self::set_user_subscription( $customer_id, true, 'registration' );
		}
	}

	/**
	 * Renders the preference in the default WooCommerce account-details form.
	 */
	public function render_woocommerce_account_field(): void {
		$subscribed = self::is_user_subscribed( get_current_user_id() );
		?>
		<fieldset class="schrack-newsletter-account">
			<legend><?php esc_html_e( 'Newsletter', 'schrack-woocommerce-sync' ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( self::ACCOUNT_PRESENT ); ?>" value="yes">
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
					<input class="woocommerce-form__input woocommerce-form__input-checkbox" type="checkbox" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="yes" <?php checked( $subscribed ); ?>>
					<span><?php esc_html_e( 'Doresc sa primesc noutati si oferte prin email.', 'schrack-woocommerce-sync' ); ?></span>
				</label>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Saves the preference from the default WooCommerce account-details form.
	 */
	public function save_woocommerce_account_subscription( int $user_id ): void {
		if ( ! isset( $_POST[ self::ACCOUNT_PRESENT ] ) || ! is_scalar( $_POST[ self::ACCOUNT_PRESENT ] ) ) {
			return;
		}

		$present = sanitize_text_field( wp_unslash( (string) $_POST[ self::ACCOUNT_PRESENT ] ) );

		if ( 'yes' === $present ) {
			self::set_user_subscription( $user_id, self::posted_opt_in(), 'account' );
		}
	}

	/**
	 * Returns whether a registered user is subscribed.
	 */
	public static function is_user_subscribed( int $user_id ): bool {
		return $user_id > 0 && 'yes' === (string) get_user_meta( $user_id, self::META_STATUS, true );
	}

	/**
	 * Updates a registered user's newsletter preference.
	 */
	public static function set_user_subscription( int $user_id, bool $subscribed, string $source = 'account' ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
			return false;
		}

		$source = sanitize_key( $source );
		self::update_user_subscription_meta( $user_id, $subscribed, $source );

		if ( ! $subscribed ) {
			self::unsubscribe_user_rows( $user_id );
		}

		return self::save_subscription(
			(string) $user->user_email,
			$user_id,
			(string) $user->first_name,
			(string) $user->last_name,
			$subscribed,
			$source
		);
	}

	/**
	 * Keeps names and a changed account email current in the subscriber list.
	 */
	public function sync_subscribed_user_profile( int $user_id, WP_User $old_user_data ): void {
		if ( ! self::is_user_subscribed( $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'first_name' => sanitize_text_field( (string) $user->first_name ),
				'last_name'  => sanitize_text_field( (string) $user->last_name ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( strtolower( (string) $old_user_data->user_email ) !== strtolower( (string) $user->user_email ) ) {
			$wpdb->delete(
				self::table_name(),
				array(
					'email'   => sanitize_email( (string) $old_user_data->user_email ),
					'user_id' => $user_id,
				),
				array( '%s', '%d' )
			);
			self::set_user_subscription( $user_id, true, 'account_update' );
		}
	}

	/**
	 * Registers the newsletter subscriber admin page.
	 */
	public function register_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Abonati newsletter', 'schrack-woocommerce-sync' ),
			__( 'Abonati newsletter', 'schrack-woocommerce-sync' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Renders the searchable, paginated subscriber list.
	 */
	public function render_admin_page(): void {
		$this->assert_can_manage();

		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 50;
		$result   = $this->subscriber_rows( $search, $page, $per_page );
		$rows     = $result['rows'];
		$total    = $result['total'];

		include SCHRACK_WC_SYNC_PATH . 'templates/admin-newsletter.php';
	}

	/**
	 * Exports current subscribers to a UTF-8 CSV file.
	 */
	public function export_subscribers(): void {
		$this->assert_can_manage();
		check_admin_referer( 'schrack_wc_sync_export_newsletter' );

		global $wpdb;
		$table_name = self::table_name();
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT email, first_name, last_name, user_id, source, subscribed_at FROM {$table_name} WHERE status = %s ORDER BY subscribed_at DESC, id DESC",
				'subscribed'
			),
			ARRAY_A
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="schrack-newsletter-subscribers-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Fisierul CSV nu a putut fi creat.', 'schrack-woocommerce-sync' ) );
		}

		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array( 'email', 'first_name', 'last_name', 'user_id', 'source', 'subscribed_at' ), ',', '"', '\\' );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			fputcsv( $output, array_values( $row ), ',', '"', '\\' );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Returns subscribed rows and the filtered total.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	private function subscriber_rows( string $search, int $page, int $per_page ): array {
		global $wpdb;

		$table_name = self::table_name();
		$where      = 'status = %s';
		$params     = array( 'subscribed' );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE {$where}", ...$params );
		$total     = absint( $wpdb->get_var( $count_sql ) );
		$offset    = ( max( 1, $page ) - 1 ) * $per_page;
		$list_sql  = $wpdb->prepare(
			"SELECT id, email, first_name, last_name, user_id, source, subscribed_at, updated_at FROM {$table_name} WHERE {$where} ORDER BY subscribed_at DESC, id DESC LIMIT %d OFFSET %d",
			...array_merge( $params, array( $per_page, $offset ) )
		);
		$rows = $wpdb->get_results( $list_sql, ARRAY_A );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Inserts or updates one email subscription.
	 */
	private static function save_subscription( string $email, int $user_id, string $first_name, string $last_name, bool $subscribed, string $source ): bool {
		global $wpdb;

		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return false;
		}

		$table_name      = self::table_name();
		$now             = current_time( 'mysql', true );
		$status          = $subscribed ? 'subscribed' : 'unsubscribed';
		$date_values     = $subscribed ? '%s, NULL' : 'NULL, %s';
		$sql             = $wpdb->prepare(
			"INSERT INTO {$table_name}
				(email, user_id, first_name, last_name, status, source, subscribed_at, unsubscribed_at, updated_at)
			VALUES (%s, %d, %s, %s, %s, %s, {$date_values}, %s)
			ON DUPLICATE KEY UPDATE
				user_id = VALUES(user_id),
				first_name = VALUES(first_name),
				last_name = VALUES(last_name),
				source = VALUES(source),
				subscribed_at = IF(VALUES(status) = 'subscribed', IF(status = 'subscribed' AND subscribed_at IS NOT NULL, subscribed_at, VALUES(subscribed_at)), subscribed_at),
				unsubscribed_at = VALUES(unsubscribed_at),
				status = VALUES(status),
				updated_at = VALUES(updated_at)",
			$email,
			max( 0, $user_id ),
			sanitize_text_field( $first_name ),
			sanitize_text_field( $last_name ),
			$status,
			sanitize_key( $source ),
			$now,
			$now
		);

		return false !== $wpdb->query( $sql );
	}

	/**
	 * Marks every address attached to a user as unsubscribed.
	 */
	private static function unsubscribe_user_rows( int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . " SET status = 'unsubscribed', unsubscribed_at = %s, updated_at = %s WHERE user_id = %d AND status = 'subscribed'",
				$now,
				$now,
				$user_id
			)
		);
	}

	/**
	 * Updates the user-meta mirror used by the account and checkout forms.
	 */
	private static function update_user_subscription_meta( int $user_id, bool $subscribed, string $source ): void {
		update_user_meta( $user_id, self::META_STATUS, $subscribed ? 'yes' : 'no' );
		update_user_meta( $user_id, self::META_SOURCE, sanitize_key( $source ) );
		update_user_meta( $user_id, self::META_UPDATED_AT, current_time( 'mysql', true ) );
	}

	/**
	 * Returns whether the newsletter checkbox was checked in the current request.
	 */
	private static function posted_opt_in(): bool {
		if ( ! isset( $_POST[ self::FIELD_NAME ] ) || ! is_scalar( $_POST[ self::FIELD_NAME ] ) ) {
			return false;
		}

		$value = sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_NAME ] ) );

		return in_array( $value, array( '1', 'yes', 'on' ), true );
	}

	/**
	 * Distinguishes the classic checkout field from checkouts that do not render it.
	 */
	private static function checkout_field_was_present(): bool {
		return isset( $_POST[ self::CHECKOUT_PRESENT ] )
			&& is_scalar( $_POST[ self::CHECKOUT_PRESENT ] )
			&& 'yes' === sanitize_text_field( wp_unslash( (string) $_POST[ self::CHECKOUT_PRESENT ] ) );
	}

	/**
	 * Returns the plugin-specific subscriber table name.
	 */
	private static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'schrack_newsletter_subscribers';
	}

	/**
	 * Capability guard for newsletter administration.
	 */
	private function assert_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nu ai permisiunea de a administra abonatii newsletter.', 'schrack-woocommerce-sync' ) );
		}
	}
}
