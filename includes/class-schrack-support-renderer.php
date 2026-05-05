<?php
/**
 * Floating support chat renderer matching the Syshub support widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Support_Renderer {
	/**
	 * Renders the floating support widget.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		$settings    = $this->sanitize_settings( $settings );
		$instance_id = '' !== $instance_id ? 'schrack-support-' . sanitize_html_class( $instance_id ) : wp_unique_id( 'schrack-support-' );
		$panel_id    = $instance_id . '-panel';
		$style       = sprintf(
			'--schrack-support-accent:%1$s;--schrack-support-deep:%2$s;--schrack-support-action:%3$s;--schrack-support-bottom:%4$dpx;--schrack-support-right:%5$dpx;--schrack-support-radius:%6$dpx;',
			esc_attr( $settings['accent_color'] ),
			esc_attr( $settings['deep_color'] ),
			esc_attr( $settings['action_color'] ),
			(int) $settings['bottom_offset'],
			(int) $settings['right_offset'],
			(int) $settings['radius']
		);

		wp_enqueue_style( 'schrack-wc-support' );
		wp_enqueue_script( 'schrack-wc-support' );

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="schrack-support"
			style="<?php echo esc_attr( $style ); ?>"
			data-schrack-support
		>
			<section id="<?php echo esc_attr( $panel_id ); ?>" class="schrack-support__panel" aria-label="<?php echo esc_attr( $settings['panel_title'] ); ?>" hidden data-support-panel>
				<div class="schrack-support__header">
					<div>
						<h2><?php echo esc_html( $settings['panel_title'] ); ?></h2>
						<?php if ( '' !== $settings['panel_text'] ) : ?>
							<p><?php echo esc_html( $settings['panel_text'] ); ?></p>
						<?php endif; ?>
					</div>
					<button class="schrack-support__close" type="button" aria-label="<?php esc_attr_e( 'Inchide suportul', 'schrack-woocommerce-sync' ); ?>" data-support-close>
						<?php echo $this->icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
				</div>

				<div class="schrack-support__body">
					<a class="schrack-support__action is-whatsapp" href="<?php echo esc_url( $this->whatsapp_url( $settings ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo $this->icon( 'message' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $settings['whatsapp_label'] ); ?></span>
					</a>

					<a class="schrack-support__action is-phone" href="<?php echo esc_url( 'tel:' . $settings['phone_tel'], array( 'tel' ) ); ?>">
						<?php echo $this->icon( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( sprintf( __( 'Suna la %s', 'schrack-woocommerce-sync' ), $settings['phone_display'] ) ); ?></span>
					</a>

					<?php if ( '' !== $settings['contact_url'] ) : ?>
						<a class="schrack-support__action is-contact" href="<?php echo esc_url( $settings['contact_url'] ); ?>" data-support-close>
							<?php echo $this->icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php echo esc_html( $settings['contact_label'] ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			</section>

			<button
				class="schrack-support__toggle"
				type="button"
				aria-label="<?php esc_attr_e( 'Deschide suport client', 'schrack-woocommerce-sync' ); ?>"
				aria-controls="<?php echo esc_attr( $panel_id ); ?>"
				aria-expanded="false"
				data-support-toggle
			>
				<span class="schrack-support__toggle-open" aria-hidden="true"><?php echo $this->icon( 'message' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="schrack-support__toggle-close" aria-hidden="true"><?php echo $this->icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</button>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Normalizes widget settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,string|int>
	 */
	private function sanitize_settings( array $settings ): array {
		$defaults = array(
			'panel_title'      => __( 'Suport client', 'schrack-woocommerce-sync' ),
			'panel_text'       => __( 'Raspundem la intrebari despre produse, oferte si comenzi.', 'schrack-woocommerce-sync' ),
			'whatsapp_label'   => __( 'Chat WhatsApp', 'schrack-woocommerce-sync' ),
			'whatsapp_message' => __( 'Buna ziua, doresc informatii despre produse sau oferta.', 'schrack-woocommerce-sync' ),
			'contact_label'    => __( 'Trimite o cerere', 'schrack-woocommerce-sync' ),
			'contact_url'      => 'https://syshub.ro/contact#formular-contact',
			'phone_display'    => '0749 235 958',
			'phone_tel'        => '+40749235958',
			'accent_color'     => '#1e40af',
			'deep_color'       => '#172554',
			'action_color'     => '#16a34a',
			'bottom_offset'    => 24,
			'right_offset'     => 16,
			'radius'           => 8,
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'panel_title', 'panel_text', 'whatsapp_label', 'whatsapp_message', 'contact_label', 'phone_display', 'phone_tel' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		$settings['contact_url']   = esc_url_raw( (string) $settings['contact_url'] );
		$settings['accent_color']  = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['deep_color']    = sanitize_hex_color( (string) $settings['deep_color'] ) ?: $defaults['deep_color'];
		$settings['action_color']  = sanitize_hex_color( (string) $settings['action_color'] ) ?: $defaults['action_color'];
		$settings['bottom_offset'] = max( 12, min( 220, absint( $settings['bottom_offset'] ) ) );
		$settings['right_offset']  = max( 8, min( 80, absint( $settings['right_offset'] ) ) );
		$settings['radius']        = max( 0, min( 8, absint( $settings['radius'] ) ) );

		return $settings;
	}

	/**
	 * Builds the WhatsApp deeplink.
	 *
	 * @param array<string,string|int> $settings Sanitized settings.
	 */
	private function whatsapp_url( array $settings ): string {
		$number = preg_replace( '/\D+/', '', (string) $settings['phone_tel'] );

		if ( '' === $number ) {
			return 'https://wa.me/';
		}

		$message = rawurlencode( (string) $settings['whatsapp_message'] );

		return 'https://wa.me/' . $number . ( '' !== $message ? '?text=' . $message : '' );
	}

	/**
	 * Returns inline SVG icons.
	 */
	private function icon( string $name ): string {
		$icons = array(
			'message' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.7 8.7 0 0 1-3.8-.9L3 20l1.1-4.8a8.2 8.2 0 0 1-.9-3.7 8.4 8.4 0 0 1 17.8 0Z"/><path d="M8 10.5h8M8 14h5"/></svg>',
			'phone'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg>',
			'send'    => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m22 2-7 20-4-9-9-4 20-7Z"/><path d="M22 2 11 13"/></svg>',
			'close'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
		);

		return $icons[ $name ] ?? '';
	}
}
