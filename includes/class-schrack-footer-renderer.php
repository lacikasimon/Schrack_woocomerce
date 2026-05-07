<?php
/**
 * Elementor footer renderer matching the Syshub footer.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Footer_Renderer {
	/**
	 * Renders the footer module.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		$settings = $this->sanitize_settings( $settings );

		wp_enqueue_style( 'schrack-wc-footer' );

		$style = sprintf(
			'--schrack-footer-accent:%1$s;--schrack-footer-deep:%2$s;--schrack-footer-radius:%3$dpx;--schrack-footer-width:%4$dpx;',
			esc_attr( $settings['accent_color'] ),
			esc_attr( $settings['deep_color'] ),
			(int) $settings['radius'],
			(int) $settings['max_width']
		);

		ob_start();
		?>
		<footer
			id="<?php echo esc_attr( '' !== $instance_id ? 'schrack-footer-' . $instance_id : 'schrack-footer' ); ?>"
			class="schrack-footer"
			style="<?php echo esc_attr( $style ); ?>"
		>
			<?php if ( '' !== $settings['top_message'] ) : ?>
				<div class="schrack-footer__top">
					<p><?php echo esc_html( $settings['top_message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="schrack-footer__inner">
				<div class="schrack-footer__grid">
					<div class="schrack-footer__brand">
						<a class="schrack-footer__logo-link" href="<?php echo esc_url( $settings['site_url'] ); ?>" aria-label="<?php echo esc_attr( $settings['company_name'] ); ?>">
							<img src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="<?php echo esc_attr( $settings['company_name'] ); ?>" loading="lazy">
							<span>
								<strong><?php echo esc_html( $settings['brand_name'] ); ?></strong>
								<small><?php echo esc_html( $settings['brand_suffix'] ); ?></small>
							</span>
						</a>

						<?php if ( '' !== $settings['brand_lead'] ) : ?>
							<p class="schrack-footer__lead"><?php echo esc_html( $settings['brand_lead'] ); ?></p>
						<?php endif; ?>

						<?php if ( '' !== $settings['brand_text'] ) : ?>
							<p><?php echo esc_html( $settings['brand_text'] ); ?></p>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_social'] ) : ?>
							<div class="schrack-footer__social">
								<h2><?php esc_html_e( 'Retele sociale', 'schrack-woocommerce-sync' ); ?></h2>
								<div>
									<?php foreach ( $this->social_links( $settings['site_url'], $settings['company_name'] ) as $link ) : ?>
										<a href="<?php echo esc_url( $link['href'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $link['label'] ); ?>">
											<span aria-hidden="true"><?php echo esc_html( $link['short'] ); ?></span>
											<?php echo esc_html( $link['label'] ); ?>
										</a>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<nav class="schrack-footer__links" aria-label="<?php esc_attr_e( 'Servicii', 'schrack-woocommerce-sync' ); ?>">
						<h2><?php esc_html_e( 'Servicii', 'schrack-woocommerce-sync' ); ?></h2>
						<ul>
							<?php foreach ( $this->service_links() as $link ) : ?>
								<li><a href="<?php echo esc_url( $link['href'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</nav>

					<nav class="schrack-footer__links" aria-label="<?php esc_attr_e( 'Companie', 'schrack-woocommerce-sync' ); ?>">
						<h2><?php esc_html_e( 'Companie', 'schrack-woocommerce-sync' ); ?></h2>
						<ul>
							<?php foreach ( $this->company_links() as $link ) : ?>
								<li><a href="<?php echo esc_url( $link['href'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</nav>

					<div class="schrack-footer__contact">
						<h2><?php esc_html_e( 'Contact', 'schrack-woocommerce-sync' ); ?></h2>
						<div class="schrack-footer__contact-list">
							<a href="<?php echo esc_url( 'tel:' . $settings['phone_tel'] ); ?>">
								<span aria-hidden="true"></span>
								<?php echo esc_html( $settings['phone_display'] ); ?>
							</a>
							<div>
								<span aria-hidden="true"></span>
								<p>
									<?php foreach ( $this->address_lines( $settings ) as $line ) : ?>
										<em><?php echo esc_html( $line ); ?></em>
									<?php endforeach; ?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<?php if ( 'yes' === $settings['show_eu_block'] || 'yes' === $settings['show_anpc'] || 'yes' === $settings['show_payments'] ) : ?>
					<div class="schrack-footer__compliance">
						<?php if ( 'yes' === $settings['show_eu_block'] ) : ?>
							<div class="schrack-footer__eu">
								<div class="schrack-footer__eu-logos" aria-label="<?php esc_attr_e( 'Logo-uri finantare europeana', 'schrack-woocommerce-sync' ); ?>">
									<?php foreach ( $this->eu_logos() as $logo ) : ?>
										<img src="<?php echo esc_url( $logo['src'] ); ?>" alt="<?php echo esc_attr( $logo['alt'] ); ?>" loading="lazy">
									<?php endforeach; ?>
								</div>
								<p>
									<?php esc_html_e( 'Pentru informatii detaliate despre celelalte programe cofinantate de Uniunea Europeana, va invitam sa vizitati', 'schrack-woocommerce-sync' ); ?>
									<a href="https://oportunitati-ue.gov.ro/" target="_blank" rel="noopener noreferrer">www.oportunitati-ue.gov.ro</a>.
								</p>
							</div>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_eu_block'] || 'yes' === $settings['show_anpc'] ) : ?>
							<div class="schrack-footer__service-tags" aria-label="<?php esc_attr_e( 'Servicii complete', 'schrack-woocommerce-sync' ); ?>">
								<h3><?php esc_html_e( 'Servicii complete:', 'schrack-woocommerce-sync' ); ?></h3>
								<div>
									<?php foreach ( array( __( 'Consultanta', 'schrack-woocommerce-sync' ), __( 'Proiectare', 'schrack-woocommerce-sync' ), __( 'Executie', 'schrack-woocommerce-sync' ), __( 'Mentenanta', 'schrack-woocommerce-sync' ) ) as $tag ) : ?>
										<span><?php echo esc_html( $tag ); ?></span>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_anpc'] ) : ?>
							<div class="schrack-footer__anpc">
								<h3><?php esc_html_e( 'ANPC', 'schrack-woocommerce-sync' ); ?></h3>
								<div>
									<?php foreach ( $this->anpc_links() as $link ) : ?>
										<a href="<?php echo esc_url( $link['href'] ); ?>" target="_blank" rel="nofollow noopener noreferrer" aria-label="<?php echo esc_attr( $link['label'] ); ?>">
											<img src="<?php echo esc_url( $link['src'] ); ?>" alt="<?php echo esc_attr( $link['label'] ); ?>" loading="lazy">
										</a>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_payments'] ) : ?>
							<div class="schrack-footer__payments" aria-label="<?php esc_attr_e( 'Metode de plata acceptate', 'schrack-woocommerce-sync' ); ?>">
								<img
									src="<?php echo esc_url( $this->payment_logo_src() ); ?>"
									alt="<?php esc_attr_e( 'NETOPIA Payments, Mastercard si Visa', 'schrack-woocommerce-sync' ); ?>"
									width="1852"
									height="349"
									loading="lazy"
								>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="schrack-footer__bottom">
					<p class="schrack-footer__legal">
						<?php echo esc_html( $settings['company_name'] ); ?> · CUI <?php echo esc_html( $settings['cui'] ); ?> · Reg. Com. <?php echo esc_html( $settings['reg_com'] ); ?> · <?php echo esc_html( $settings['cui_note'] ); ?> · EUID <?php echo esc_html( $settings['euid'] ); ?>
					</p>

					<div class="schrack-footer__bottom-row">
						<p><?php echo esc_html( sprintf( __( '© %1$d GENE SYS SECURITY SRL. Toate drepturile rezervate.', 'schrack-woocommerce-sync' ), (int) gmdate( 'Y' ) ) ); ?></p>
						<nav aria-label="<?php esc_attr_e( 'Linkuri legale', 'schrack-woocommerce-sync' ); ?>">
							<?php foreach ( $this->legal_links() as $link ) : ?>
								<a href="<?php echo esc_url( $link['href'] ); ?>" <?php echo $link['external'] ? 'target="_blank" rel="noopener noreferrer"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
									<?php echo esc_html( $link['label'] ); ?>
								</a>
							<?php endforeach; ?>
						</nav>
					</div>
				</div>
			</div>
		</footer>
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
			'top_message'   => __( 'Instalatii electrice · Fotovoltaice · Securitate — solutii integrate pentru proiectul tau', 'schrack-woocommerce-sync' ),
			'company_name'  => 'GENE SYS SECURITY SRL',
			'brand_name'    => 'GENE SYS SECURITY',
			'brand_suffix'  => 'SRL',
			'brand_lead'    => __( 'Proiectare, executie si mentenanta pentru instalatii electrice, fotovoltaice si sisteme de securitate.', 'schrack-woocommerce-sync' ),
			'brand_text'    => __( 'Lucram cu beneficiari privati, firme de constructii si administratori de patrimoniu — oferte clare, documentatie conforma si suport dupa receptie.', 'schrack-woocommerce-sync' ),
			'logo_url'      => 'https://syshub.ro/assets/genesys-logo-D16z0xlU.svg',
			'site_url'      => 'https://syshub.ro/',
			'phone_display' => '0749 235 958',
			'phone_tel'     => '+40749235958',
			'address_one'   => __( 'Judet Satu Mare, loc. Satu Mare', 'schrack-woocommerce-sync' ),
			'address_two'   => __( 'Str. Gheorghe Baritiu 88, cod postal 440135', 'schrack-woocommerce-sync' ),
			'cui'           => 'RO 38322763',
			'cui_note'      => __( 'Platitor de TVA (la facturare)', 'schrack-woocommerce-sync' ),
			'reg_com'       => 'J2017001105304',
			'euid'          => 'ROONRC.J2017001105304',
			'show_social'       => 'yes',
			'show_eu_block'     => 'yes',
			'show_anpc'         => 'yes',
			'show_payments'     => 'yes',
			'accent_color'      => '#1e40af',
			'deep_color'        => '#172554',
			'max_width'         => 1280,
			'radius'            => 8,
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'top_message', 'company_name', 'brand_name', 'brand_suffix', 'brand_lead', 'brand_text', 'phone_display', 'phone_tel', 'address_one', 'address_two', 'cui', 'cui_note', 'reg_com', 'euid' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'show_social', 'show_eu_block', 'show_anpc', 'show_payments' ) as $key ) {
			$settings[ $key ] = 'yes' === (string) $settings[ $key ] ? 'yes' : 'no';
		}

		$settings['logo_url']     = esc_url_raw( (string) $settings['logo_url'] );
		$settings['site_url']     = esc_url_raw( (string) $settings['site_url'] );
		$settings['accent_color'] = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['deep_color']   = sanitize_hex_color( (string) $settings['deep_color'] ) ?: $defaults['deep_color'];
		$settings['max_width']    = max( 960, min( 1440, absint( $settings['max_width'] ) ) );
		$settings['radius']       = max( 0, min( 8, absint( $settings['radius'] ) ) );

		if ( '' === $settings['logo_url'] ) {
			$settings['logo_url'] = $defaults['logo_url'];
		}

		if ( '' === $settings['site_url'] ) {
			$settings['site_url'] = $defaults['site_url'];
		}

		return $settings;
	}

	/**
	 * Returns footer service links.
	 *
	 * @return array<int,array{label:string,href:string}>
	 */
	private function service_links(): array {
		return array(
			array( 'label' => __( 'Instalatii electrice & proiectare', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/servicii/instalatii-electrice' ),
			array( 'label' => __( 'Sisteme fotovoltaice (on-grid / off-grid)', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/servicii/fotovoltaice' ),
			array( 'label' => __( 'Supraveghere video (CCTV)', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/servicii/securitate-cctv' ),
			array( 'label' => __( 'Detectie la efractie & alarmare', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/servicii/detectie-efractie' ),
			array( 'label' => __( 'Mentenanta tehnica', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/servicii/mentenanta' ),
			array( 'label' => __( 'Consultanta & infrastructura electrica', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/servicii/consultanta' ),
		);
	}

	/**
	 * Returns footer company links.
	 *
	 * @return array<int,array{label:string,href:string}>
	 */
	private function company_links(): array {
		return array(
			array( 'label' => __( 'Despre Noi', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#despre-noi' ),
			array( 'label' => __( 'De ce noi', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#de-ce-noi' ),
			array( 'label' => __( 'Servicii', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#servicii' ),
			array( 'label' => __( 'Sectoare', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#sectoare' ),
			array( 'label' => __( 'Proces', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#proces' ),
			array( 'label' => __( 'Certificari', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#certificari' ),
			array( 'label' => __( 'Experienta', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#experienta' ),
			array( 'label' => __( 'Intrebari', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/#intrebari' ),
			array( 'label' => __( 'Proiecte', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/proiecte' ),
			array( 'label' => __( 'Finantare UE', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/finantare-ue' ),
			array( 'label' => __( 'Contact', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/contact' ),
		);
	}

	/**
	 * Returns legal links.
	 *
	 * @return array<int,array{label:string,href:string,external:bool}>
	 */
	private function legal_links(): array {
		return array(
			array( 'label' => __( 'Politica cookie', 'schrack-woocommerce-sync' ), 'href' => 'https://syshub.ro/politica-cookie-uri', 'external' => false ),
			array( 'label' => 'ANPC', 'href' => 'https://anpc.ro/', 'external' => true ),
			array( 'label' => __( 'Solutionarea alternativa a litigiilor', 'schrack-woocommerce-sync' ), 'href' => 'https://anpc.ro/ce-este-sal/', 'external' => true ),
			array( 'label' => __( 'Solutionarea online a litigiilor', 'schrack-woocommerce-sync' ), 'href' => 'https://consumer-redress.ec.europa.eu/site-relocation_en', 'external' => true ),
		);
	}

	/**
	 * Returns the local NETOPIA payments logo URL.
	 */
	private function payment_logo_src(): string {
		return SCHRACK_WC_SYNC_URL . 'assets/netopia-payments-bg-white.png';
	}

	/**
	 * Returns ANPC image links.
	 *
	 * @return array<int,array{label:string,href:string,src:string}>
	 */
	private function anpc_links(): array {
		return array(
			array(
				'label' => __( 'ANPC - Autoritatea Nationala pentru Protectia Consumatorilor', 'schrack-woocommerce-sync' ),
				'href'  => 'https://anpc.ro/',
				'src'   => 'https://syshub.ro/assets/anpc-logo-C3lA3zda.svg',
			),
			array(
				'label' => __( 'Solutionarea alternativa a litigiilor', 'schrack-woocommerce-sync' ),
				'href'  => 'https://anpc.ro/ce-este-sal/',
				'src'   => 'https://syshub.ro/assets/anpc-sal-BLYQEtJZ.svg',
			),
			array(
				'label' => __( 'Solutionarea online a litigiilor', 'schrack-woocommerce-sync' ),
				'href'  => 'https://consumer-redress.ec.europa.eu/site-relocation_en',
				'src'   => 'https://syshub.ro/assets/anpc-sol-vgITSumg.svg',
			),
		);
	}

	/**
	 * Returns EU funding logos used by the Syshub footer.
	 *
	 * @return array<int,array{alt:string,src:string}>
	 */
	private function eu_logos(): array {
		return array(
			array(
				'alt' => __( 'Cofinantat de Uniunea Europeana', 'schrack-woocommerce-sync' ),
				'src' => 'https://syshub.ro/assets/uniunea-europeana-cofinantat-Subb6x4v.png',
			),
			array(
				'alt' => __( 'Guvernul Romaniei', 'schrack-woocommerce-sync' ),
				'src' => 'https://syshub.ro/assets/guvernul-romaniei-Yha6L_8V.png',
			),
			array(
				'alt' => 'REGIO Nord-Vest',
				'src' => 'https://syshub.ro/assets/regio-nord-vest-DcT-iwjj.png',
			),
			array(
				'alt' => __( 'Agentia de Dezvoltare Regionala Nord-Vest', 'schrack-woocommerce-sync' ),
				'src' => 'https://syshub.ro/assets/adr-nord-vest-bTPLxXrD.svg',
			),
		);
	}

	/**
	 * Returns share links used by the Syshub footer.
	 *
	 * @return array<int,array{label:string,href:string,short:string}>
	 */
	private function social_links( string $site_url, string $company_name ): array {
		return array(
			array(
				'label' => 'Facebook',
				'href'  => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $site_url ),
				'short' => 'f',
			),
			array(
				'label' => 'LinkedIn',
				'href'  => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $site_url ),
				'short' => 'in',
			),
			array(
				'label' => 'WhatsApp',
				'href'  => 'https://wa.me/?text=' . rawurlencode( $company_name . ' - ' . $site_url ),
				'short' => 'wa',
			),
		);
	}

	/**
	 * Returns address lines.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<int,string>
	 */
	private function address_lines( array $settings ): array {
		return array_values(
			array_filter(
				array(
					(string) $settings['address_one'],
					(string) $settings['address_two'],
				),
				static fn( string $line ): bool => '' !== $line
			)
		);
	}
}
