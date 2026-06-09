<?php
/**
 * Elementor renderer for the REGIO Nord-Vest funding page.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Funding_Renderer {
	/**
	 * Renders the funding page content.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 */
	public function render( array $settings, string $instance_id = '' ): string {
		$settings    = $this->sanitize_settings( $settings );
		$instance_id = '' !== $instance_id ? 'schrack-funding-' . sanitize_html_class( $instance_id ) : wp_unique_id( 'schrack-funding-' );
		$style       = sprintf(
			'--schrack-funding-accent:%1$s;--schrack-funding-radius:%2$dpx;--schrack-funding-width:%3$dpx;',
			esc_attr( $settings['accent_color'] ),
			(int) $settings['radius'],
			(int) $settings['max_width']
		);

		wp_enqueue_style( 'schrack-wc-funding' );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $instance_id ); ?>" class="schrack-funding" style="<?php echo esc_attr( $style ); ?>">
			<article class="schrack-funding__article">
				<header class="schrack-funding__header">
					<div class="schrack-funding__header-text">
						<?php if ( '' !== $settings['eyebrow'] ) : ?>
							<p class="schrack-funding__eyebrow"><?php echo esc_html( $settings['eyebrow'] ); ?></p>
						<?php endif; ?>
						<h1><?php echo esc_html( $settings['project_title'] ); ?></h1>
					</div>

					<?php if ( '' !== $settings['hero_image_url'] ) : ?>
						<figure class="schrack-funding__hero">
							<img src="<?php echo esc_url( $settings['hero_image_url'] ); ?>" alt="<?php echo esc_attr( $settings['hero_image_alt'] ); ?>" loading="eager">
						</figure>
					<?php endif; ?>
				</header>

				<section class="schrack-funding__section">
					<h2><?php esc_html_e( 'Descrierea proiectului', 'schrack-woocommerce-sync' ); ?></h2>
					<div class="schrack-funding__copy">
						<?php foreach ( $this->paragraphs( $settings['description_text'] ) as $paragraph ) : ?>
							<p><?php echo esc_html( $paragraph ); ?></p>
						<?php endforeach; ?>
					</div>

					<div class="schrack-funding__objectives">
						<div class="schrack-funding__objective-primary">
							<h3><?php esc_html_e( 'Obiectivul general', 'schrack-woocommerce-sync' ); ?></h3>
							<p><?php echo esc_html( $settings['objective_general'] ); ?></p>
						</div>
						<div class="schrack-funding__objective-list">
							<h3><?php esc_html_e( 'Obiective specifice', 'schrack-woocommerce-sync' ); ?></h3>
							<ul>
								<?php foreach ( $this->lines( $settings['objective_specific'] ) as $objective ) : ?>
									<li><span aria-hidden="true"></span><?php echo esc_html( $objective ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
				</section>

				<?php if ( ! empty( $settings['stages'] ) ) : ?>
					<section class="schrack-funding__section">
						<h2><?php esc_html_e( 'Stadiile implementării proiectului', 'schrack-woocommerce-sync' ); ?></h2>
						<div class="schrack-funding__timeline">
							<?php foreach ( $settings['stages'] as $stage ) : ?>
								<div class="schrack-funding__timeline-item">
									<time><?php echo esc_html( $stage['date'] ); ?></time>
									<div>
										<p><?php echo esc_html( $stage['title'] ); ?></p>
										<?php echo $this->links_html( $stage ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( ! empty( $settings['gallery'] ) ) : ?>
					<section class="schrack-funding__section">
						<h2><?php esc_html_e( 'Galerie foto', 'schrack-woocommerce-sync' ); ?></h2>
						<div class="schrack-funding__gallery">
							<?php foreach ( $settings['gallery'] as $photo ) : ?>
								<figure>
									<img src="<?php echo esc_url( $photo['src'] ); ?>" alt="<?php echo esc_attr( $photo['alt'] ); ?>" loading="lazy">
									<?php if ( '' !== $photo['caption'] ) : ?>
										<figcaption><?php echo esc_html( $photo['caption'] ); ?></figcaption>
									<?php endif; ?>
								</figure>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( '' !== $settings['video_url'] ) : ?>
					<section class="schrack-funding__section">
						<h2><?php esc_html_e( 'Galerie video', 'schrack-woocommerce-sync' ); ?></h2>
						<div class="schrack-funding__video">
							<video controls preload="metadata" <?php echo '' !== $settings['video_poster_url'] ? 'poster="' . esc_url( $settings['video_poster_url'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<source src="<?php echo esc_url( $settings['video_url'] ); ?>" type="<?php echo esc_attr( $this->video_type( $settings['video_url'] ) ); ?>">
								<?php esc_html_e( 'Browserul dumneavoastră nu poate reda clipul video.', 'schrack-woocommerce-sync' ); ?>
							</video>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( ! empty( $settings['communications'] ) ) : ?>
					<section class="schrack-funding__section">
						<h2><?php esc_html_e( 'Comunicate de presă', 'schrack-woocommerce-sync' ); ?></h2>
						<div class="schrack-funding__communications">
							<?php foreach ( $settings['communications'] as $communication ) : ?>
								<div class="schrack-funding__communication">
									<h3><?php echo esc_html( $communication['title'] ); ?></h3>
									<?php if ( '' !== $communication['body'] ) : ?>
										<p><?php echo esc_html( $communication['body'] ); ?></p>
									<?php endif; ?>
									<?php echo $this->links_html( $communication ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_oportunitati_note'] ) : ?>
					<section class="schrack-funding__oportunitati">
						<p>
							<?php esc_html_e( 'Pentru informații detaliate despre celelalte programe cofinanțate de Uniunea Europeană, vă invităm să vizitați', 'schrack-woocommerce-sync' ); ?>
							<a href="https://oportunitati-ue.gov.ro/" target="_blank" rel="noopener noreferrer">www.oportunitati-ue.gov.ro</a>.
						</p>
					</section>
				<?php endif; ?>

				<section class="schrack-funding__program-footer" aria-label="<?php esc_attr_e( 'Subsol obligatoriu Programul Regional Nord-Vest', 'schrack-woocommerce-sync' ); ?>">
					<p><?php esc_html_e( 'Investim în viitorul regiunii!', 'schrack-woocommerce-sync' ); ?></p>
					<div class="schrack-funding__county-band" aria-label="<?php esc_attr_e( 'Județele Regiunii de Dezvoltare Nord-Vest', 'schrack-woocommerce-sync' ); ?>">
						<?php foreach ( $this->county_band() as $county ) : ?>
							<span style="<?php echo esc_attr( 'background-color:' . $county['color'] ); ?>"><?php echo esc_html( $county['label'] ); ?></span>
						<?php endforeach; ?>
					</div>
					<div class="schrack-funding__program-links">
						<?php $regional_links = $this->regional_links(); ?>
						<?php foreach ( $regional_links as $index => $link ) : ?>
							<span>
								<a href="<?php echo esc_url( $link['href'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?></a>
								<?php if ( $index < count( $regional_links ) - 1 ) : ?>
									<em aria-hidden="true">|</em>
								<?php endif; ?>
							</span>
						<?php endforeach; ?>
					</div>
				</section>
			</article>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Normalizes widget settings.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private function sanitize_settings( array $settings ): array {
		$defaults = array(
			'eyebrow'                 => __( 'Proiect finanțat prin Programul Regional Nord-Vest 2021-2027', 'schrack-woocommerce-sync' ),
			'project_title'           => __( 'Investiții pentru digitalizarea societății GENE SYS SECURITY SRL, cod SMIS 334780', 'schrack-woocommerce-sync' ),
			'hero_image_url'          => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/electrical-engineer.jpg',
			'hero_image_alt'          => __( 'Specialist GENE SYS SECURITY care lucrează cu infrastructură tehnică și echipamente digitale', 'schrack-woocommerce-sync' ),
			'description_text'        => implode(
				"\n\n",
				array(
					__( 'Proiectul propus își va aduce contribuția în mod direct la atingerea obiectivului Priorității 1 - O regiune competitivă prin inovare, digitalizare și întreprinderi dinamice din cadrul Programului Regional Nord-Vest 2021-2027.', 'schrack-woocommerce-sync' ),
					__( 'Inițiativele propuse vor conduce la consolidarea culturii digitale în cadrul societății, la transformarea și îmbunătățirea experienței utilizatorilor și a clienților acesteia și la eficientizarea activităților derulate.', 'schrack-woocommerce-sync' ),
				)
			),
			'objective_general'       => __( 'Obiectivul general al proiectului este de a valorifica avantajele digitalizării în beneficiul companiei, prin realizarea unor investiții ce conduc la atingerea unui nivel de intensitate digitală ridicat în cadrul activității desfășurate de către societate, activitate circumscrisă codului CAEN 4321 - Lucrări de instalații electrice.', 'schrack-woocommerce-sync' ),
			'objective_specific'      => implode(
				"\n",
				array(
					__( 'realizarea unei investiții pentru adoptarea tehnologiilor și a instrumentelor digitale care conduce la inovarea modelului de afaceri, prin achiziția de echipamente și tehnologii necesare pentru transformarea digitală, inclusiv pentru derularea proceselor interne, interacțiunea cu clienții, distribuția serviciilor oferite și colectarea și analiza de date (laptop-uri, monitoare, telefoane mobile, soluție cloud privat, imprimantă multifuncțională, soluție de securitate cibernetică, program de gestiune completă a afacerii (ERP/CRM), robot software RPA);', 'schrack-woocommerce-sync' ),
					__( 'realizarea unei investiții pentru creșterea utilizării tehnologiei digitale de către societate în scopul creșterii vizibilității, prin crearea unui website adaptat activității de e-commerce și cu un grad ridicat de interactivitate, crearea unei prezențe active pe rețelele sociale și implementarea unei soluții pentru promovarea online.', 'schrack-woocommerce-sync' ),
				)
			),
			'video_url'               => SCHRACK_WC_SYNC_URL . 'assets/funding/videos/project-overview.mp4',
			'video_poster_url'        => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/security-cctv.jpg',
			'show_oportunitati_note'  => 'yes',
			'stages'                  => $this->default_stages(),
			'gallery'                 => $this->default_gallery(),
			'communications'          => $this->default_communications(),
			'accent_color'            => '#1e40af',
			'max_width'               => 1024,
			'radius'                  => 8,
		);

		$settings = wp_parse_args( $settings, $defaults );

		foreach ( array( 'eyebrow', 'project_title', 'hero_image_alt' ) as $key ) {
			$settings[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
		}

		foreach ( array( 'description_text', 'objective_general', 'objective_specific' ) as $key ) {
			$settings[ $key ] = sanitize_textarea_field( (string) $settings[ $key ] );
		}

		$settings['hero_image_url']         = esc_url_raw( $this->setting_url( $settings['hero_image_url'] ) );
		$settings['video_url']              = esc_url_raw( $this->setting_url( $settings['video_url'] ) );
		$settings['video_poster_url']       = esc_url_raw( $this->setting_url( $settings['video_poster_url'] ) );
		$settings['show_oportunitati_note'] = 'yes' === (string) $settings['show_oportunitati_note'] ? 'yes' : 'no';
		$settings['stages']                 = $this->sanitize_link_items( is_array( $settings['stages'] ) ? $settings['stages'] : $defaults['stages'] );
		$settings['gallery']                = $this->sanitize_gallery( is_array( $settings['gallery'] ) ? $settings['gallery'] : $defaults['gallery'] );
		$settings['communications']         = $this->sanitize_link_items( is_array( $settings['communications'] ) ? $settings['communications'] : $defaults['communications'], true );
		$settings['accent_color']           = sanitize_hex_color( (string) $settings['accent_color'] ) ?: $defaults['accent_color'];
		$settings['max_width']              = max( 760, min( 1280, absint( $settings['max_width'] ) ) );
		$settings['radius']                 = max( 0, min( 8, absint( $settings['radius'] ) ) );

		if ( '' === $settings['project_title'] ) {
			$settings['project_title'] = $defaults['project_title'];
		}

		return $settings;
	}

	/**
	 * Returns default implementation stages.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function default_stages(): array {
		return array(
			array(
				'date'            => '28.11.2025',
				'title'           => __( 'Semnare Contract de finanțare', 'schrack-woocommerce-sync' ),
				'href'            => '',
				'label'           => '',
				'secondary_href'  => '',
				'secondary_label' => '',
				'body'            => '',
			),
			array(
				'date'            => '29.12.2025',
				'title'           => __( 'Publicare comunicat de presă demarare proiect', 'schrack-woocommerce-sync' ),
				'href'            => SCHRACK_WC_SYNC_URL . 'assets/funding/docs/smis-334780-comunicat-demarare.pdf',
				'label'           => __( 'Descarcă comunicatul PDF', 'schrack-woocommerce-sync' ),
				'secondary_href'  => 'https://portalsm.ro/2025/12/comunicat-de-presa-gene-sys-security-srl/',
				'secondary_label' => __( 'Vezi comunicatul pe PresaSM', 'schrack-woocommerce-sync' ),
				'body'            => '',
			),
		);
	}

	/**
	 * Returns default gallery items.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function default_gallery(): array {
		return array(
			array(
				'src'     => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/technical-maintenance.jpg',
				'alt'     => __( 'Specialist tehnic care verifică echipamente digitale și infrastructură de mentenanță', 'schrack-woocommerce-sync' ),
				'caption' => __( 'Echipamente și procese digitale pentru activitatea tehnică', 'schrack-woocommerce-sync' ),
			),
			array(
				'src'     => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/security-cctv.jpg',
				'alt'     => __( 'Cameră de supraveghere video instalată pentru protecția unui obiectiv', 'schrack-woocommerce-sync' ),
				'caption' => __( 'Soluții de securitate și monitorizare video', 'schrack-woocommerce-sync' ),
			),
			array(
				'src'     => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/electrical-installation.jpg',
				'alt'     => __( 'Lucrări de instalații electrice într-un spațiu tehnic', 'schrack-woocommerce-sync' ),
				'caption' => __( 'Activități de instalații electrice și infrastructură', 'schrack-woocommerce-sync' ),
			),
		);
	}

	/**
	 * Returns default press communication items.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function default_communications(): array {
		return array(
			array(
				'title'           => __( 'Comunicat de lansare proiect', 'schrack-woocommerce-sync' ),
				'body'            => '',
				'href'            => SCHRACK_WC_SYNC_URL . 'assets/funding/docs/smis-334780-comunicat-demarare.pdf',
				'label'           => __( 'Descarcă comunicatul PDF', 'schrack-woocommerce-sync' ),
				'secondary_href'  => 'https://portalsm.ro/2025/12/comunicat-de-presa-gene-sys-security-srl/',
				'secondary_label' => __( 'Vezi comunicatul pe PresaSM', 'schrack-woocommerce-sync' ),
				'date'            => '',
			),
			array(
				'title'           => __( 'Comunicat de presă finalizare proiect', 'schrack-woocommerce-sync' ),
				'body'            => __( 'Va fi publicat la finalizarea proiectului.', 'schrack-woocommerce-sync' ),
				'href'            => '',
				'label'           => '',
				'secondary_href'  => '',
				'secondary_label' => '',
				'date'            => '',
			),
		);
	}

	/**
	 * Sanitizes timeline or communication items.
	 *
	 * @param array<int,mixed> $items Raw items.
	 * @return array<int,array<string,string>>
	 */
	private function sanitize_link_items( array $items, bool $allow_body = false ): array {
		$sanitized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item = wp_parse_args(
				$item,
				array(
					'date'            => '',
					'title'           => '',
					'body'            => '',
					'href'            => '',
					'label'           => '',
					'secondary_href'  => '',
					'secondary_label' => '',
				)
			);

			$title = sanitize_text_field( (string) $item['title'] );
			$body  = $allow_body ? sanitize_textarea_field( (string) $item['body'] ) : '';
			$date  = sanitize_text_field( (string) $item['date'] );

			if ( '' === $title && '' === $body && '' === $date ) {
				continue;
			}

			$sanitized[] = array(
				'date'            => $date,
				'title'           => $title,
				'body'            => $body,
				'href'            => esc_url_raw( $this->setting_url( $item['href'] ) ),
				'label'           => sanitize_text_field( (string) $item['label'] ),
				'secondary_href'  => esc_url_raw( $this->setting_url( $item['secondary_href'] ) ),
				'secondary_label' => sanitize_text_field( (string) $item['secondary_label'] ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitizes gallery items.
	 *
	 * @param array<int,mixed> $items Raw items.
	 * @return array<int,array{src:string,alt:string,caption:string}>
	 */
	private function sanitize_gallery( array $items ): array {
		$sanitized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item = wp_parse_args(
				$item,
				array(
					'src'     => '',
					'alt'     => '',
					'caption' => '',
				)
			);

			$src = esc_url_raw( $this->setting_url( $item['src'] ) );

			if ( '' === $src ) {
				continue;
			}

			$sanitized[] = array(
				'src'     => $src,
				'alt'     => sanitize_text_field( (string) $item['alt'] ),
				'caption' => sanitize_text_field( (string) $item['caption'] ),
			);
		}

		return $sanitized;
	}

	/**
	 * Returns URL value from Elementor URL or media controls.
	 */
	private function setting_url( mixed $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['url'] ?? '' );
		}

		return (string) $value;
	}

	/**
	 * Returns paragraphs from a textarea.
	 *
	 * @return array<int,string>
	 */
	private function paragraphs( string $text ): array {
		$parts = preg_split( '/\R{2,}/', trim( $text ) );

		return array_values(
			array_filter(
				is_array( $parts ) ? array_map( 'trim', $parts ) : array(),
				static fn( string $part ): bool => '' !== $part
			)
		);
	}

	/**
	 * Returns non-empty lines from a textarea.
	 *
	 * @return array<int,string>
	 */
	private function lines( string $text ): array {
		$parts = preg_split( '/\R/', trim( $text ) );

		return array_values(
			array_filter(
				is_array( $parts ) ? array_map( 'trim', $parts ) : array(),
				static fn( string $part ): bool => '' !== $part
			)
		);
	}

	/**
	 * Renders primary and secondary links for a card/timeline item.
	 *
	 * @param array<string,string> $item Sanitized item.
	 */
	private function links_html( array $item ): string {
		$links = array();

		if ( '' !== $item['href'] && '' !== $item['label'] ) {
			$links[] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $item['href'] ),
				esc_html( $item['label'] )
			);
		}

		if ( '' !== $item['secondary_href'] && '' !== $item['secondary_label'] ) {
			$links[] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $item['secondary_href'] ),
				esc_html( $item['secondary_label'] )
			);
		}

		if ( empty( $links ) ) {
			return '';
		}

		return '<div class="schrack-funding__links">' . implode( '', $links ) . '</div>';
	}

	/**
	 * Infers a simple video MIME type from URL extension.
	 */
	private function video_type( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$ext  = is_string( $path ) ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';

		return match ( $ext ) {
			'webm' => 'video/webm',
			'ogg', 'ogv' => 'video/ogg',
			default => 'video/mp4',
		};
	}

	/**
	 * Returns the mandatory county color band.
	 *
	 * @return array<int,array{label:string,color:string}>
	 */
	private function county_band(): array {
		return array(
			array( 'label' => 'BH', 'color' => '#84CDDD' ),
			array( 'label' => 'BN', 'color' => '#2EBBD5' ),
			array( 'label' => 'CJ', 'color' => '#188CB1' ),
			array( 'label' => 'MM', 'color' => '#196194' ),
			array( 'label' => 'SJ', 'color' => '#1E528F' ),
			array( 'label' => 'SM', 'color' => '#2A416F' ),
		);
	}

	/**
	 * Returns official program links.
	 *
	 * @return array<int,array{label:string,href:string}>
	 */
	private function regional_links(): array {
		return array(
			array( 'label' => 'www.regionordvest.ro', 'href' => 'https://regionordvest.ro/' ),
			array( 'label' => 'www.nord-vest.ro', 'href' => 'https://www.nord-vest.ro/' ),
		);
	}
}
