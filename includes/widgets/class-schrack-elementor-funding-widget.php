<?php
/**
 * Elementor funding page widget.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Elementor_Funding_Widget extends \Elementor\Widget_Base {
	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'schrack_funding';
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		return __( 'Finantare UE Syshub', 'schrack-woocommerce-sync' );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-document-file';
	}

	/**
	 * Elementor categories.
	 *
	 * @return array<int,string>
	 */
	public function get_categories(): array {
		return array( 'schrack', 'theme-elements' );
	}

	/**
	 * Frontend style handles.
	 *
	 * @return array<int,string>
	 */
	public function get_style_depends(): array {
		return array( 'schrack-wc-funding' );
	}

	/**
	 * Registers Elementor controls.
	 */
	protected function register_controls(): void {
		$this->register_project_controls();
		$this->register_content_controls();
		$this->register_stage_controls();
		$this->register_gallery_controls();
		$this->register_video_controls();
		$this->register_communication_controls();
		$this->register_style_controls();
	}

	/**
	 * Registers project header controls.
	 */
	private function register_project_controls(): void {
		$this->start_controls_section(
			'section_project',
			array(
				'label' => __( 'Proiect', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'eyebrow',
			array(
				'label'       => __( 'Supratitlu', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Proiect finanțat prin Programul Regional Nord-Vest 2021-2027', 'schrack-woocommerce-sync' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'project_title',
			array(
				'label'       => __( 'Titlu proiect', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( 'Investiții pentru digitalizarea societății GENE SYS SECURITY SRL, cod SMIS 334780', 'schrack-woocommerce-sync' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'hero_image_url',
			array(
				'label'   => __( 'Imagine principala', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::MEDIA,
				'default' => array(
					'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/electrical-engineer.jpg',
				),
			)
		);

		$this->add_control(
			'hero_image_alt',
			array(
				'label'       => __( 'Alt imagine principala', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Specialist GENE SYS SECURITY care lucrează cu infrastructură tehnică și echipamente digitale', 'schrack-woocommerce-sync' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers description and objective controls.
	 */
	private function register_content_controls(): void {
		$this->start_controls_section(
			'section_description',
			array(
				'label' => __( 'Descriere si obiective', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'description_text',
			array(
				'label'   => __( 'Descriere proiect', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 8,
				'default' => implode(
					"\n\n",
					array(
						__( 'Proiectul propus își va aduce contribuția în mod direct la atingerea obiectivului Priorității 1 - O regiune competitivă prin inovare, digitalizare și întreprinderi dinamice din cadrul Programului Regional Nord-Vest 2021-2027.', 'schrack-woocommerce-sync' ),
						__( 'Inițiativele propuse vor conduce la consolidarea culturii digitale în cadrul societății, la transformarea și îmbunătățirea experienței utilizatorilor și a clienților acesteia și la eficientizarea activităților derulate.', 'schrack-woocommerce-sync' ),
					)
				),
			)
		);

		$this->add_control(
			'objective_general',
			array(
				'label'   => __( 'Obiectiv general', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 5,
				'default' => __( 'Obiectivul general al proiectului este de a valorifica avantajele digitalizării în beneficiul companiei, prin realizarea unor investiții ce conduc la atingerea unui nivel de intensitate digitală ridicat în cadrul activității desfășurate de către societate, activitate circumscrisă codului CAEN 4321 - Lucrări de instalații electrice.', 'schrack-woocommerce-sync' ),
			)
		);

		$this->add_control(
			'objective_specific',
			array(
				'label'       => __( 'Obiective specifice', 'schrack-woocommerce-sync' ),
				'description' => __( 'Cate un obiectiv pe linie.', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 8,
				'default'     => implode(
					"\n",
					array(
						__( 'realizarea unei investiții pentru adoptarea tehnologiilor și a instrumentelor digitale care conduce la inovarea modelului de afaceri, prin achiziția de echipamente și tehnologii necesare pentru transformarea digitală, inclusiv pentru derularea proceselor interne, interacțiunea cu clienții, distribuția serviciilor oferite și colectarea și analiza de date (laptop-uri, monitoare, telefoane mobile, soluție cloud privat, imprimantă multifuncțională, soluție de securitate cibernetică, program de gestiune completă a afacerii (ERP/CRM), robot software RPA);', 'schrack-woocommerce-sync' ),
						__( 'realizarea unei investiții pentru creșterea utilizării tehnologiei digitale de către societate în scopul creșterii vizibilității, prin crearea unui website adaptat activității de e-commerce și cu un grad ridicat de interactivitate, crearea unei prezențe active pe rețelele sociale și implementarea unei soluții pentru promovarea online.', 'schrack-woocommerce-sync' ),
					)
				),
			)
		);

		$this->add_control(
			'show_oportunitati_note',
			array(
				'label'        => __( 'Afiseaza nota oportunitati-ue', 'schrack-woocommerce-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Da', 'schrack-woocommerce-sync' ),
				'label_off'    => __( 'Nu', 'schrack-woocommerce-sync' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers implementation stage controls.
	 */
	private function register_stage_controls(): void {
		$repeater = new \Elementor\Repeater();

		$this->add_link_item_controls( $repeater, true );

		$this->start_controls_section(
			'section_stages',
			array(
				'label' => __( 'Stadii implementare', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'stages',
			array(
				'label'       => __( 'Stadii', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ date }}} - {{{ title }}}',
				'default'     => array(
					array(
						'date'  => '28.11.2025',
						'title' => __( 'Semnare Contract de finanțare', 'schrack-woocommerce-sync' ),
					),
					array(
						'date'            => '29.12.2025',
						'title'           => __( 'Publicare comunicat de presă demarare proiect', 'schrack-woocommerce-sync' ),
						'href'            => array( 'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/docs/smis-334780-comunicat-demarare.pdf' ),
						'label'           => __( 'Descarcă comunicatul PDF', 'schrack-woocommerce-sync' ),
						'secondary_href'  => array( 'url' => 'https://portalsm.ro/2025/12/comunicat-de-presa-gene-sys-security-srl/' ),
						'secondary_label' => __( 'Vezi comunicatul pe PresaSM', 'schrack-woocommerce-sync' ),
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers gallery controls.
	 */
	private function register_gallery_controls(): void {
		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'src',
			array(
				'label' => __( 'Imagine', 'schrack-woocommerce-sync' ),
				'type'  => \Elementor\Controls_Manager::MEDIA,
			)
		);

		$repeater->add_control(
			'alt',
			array(
				'label'       => __( 'Alt imagine', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'caption',
			array(
				'label'       => __( 'Legenda', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'label_block' => true,
			)
		);

		$this->start_controls_section(
			'section_gallery',
			array(
				'label' => __( 'Galerie foto', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'gallery',
			array(
				'label'       => __( 'Fotografii', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ caption }}}',
				'default'     => array(
					array(
						'src'     => array( 'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/technical-maintenance.jpg' ),
						'alt'     => __( 'Specialist tehnic care verifică echipamente digitale și infrastructură de mentenanță', 'schrack-woocommerce-sync' ),
						'caption' => __( 'Echipamente și procese digitale pentru activitatea tehnică', 'schrack-woocommerce-sync' ),
					),
					array(
						'src'     => array( 'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/security-cctv.jpg' ),
						'alt'     => __( 'Cameră de supraveghere video instalată pentru protecția unui obiectiv', 'schrack-woocommerce-sync' ),
						'caption' => __( 'Soluții de securitate și monitorizare video', 'schrack-woocommerce-sync' ),
					),
					array(
						'src'     => array( 'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/electrical-installation.jpg' ),
						'alt'     => __( 'Lucrări de instalații electrice într-un spațiu tehnic', 'schrack-woocommerce-sync' ),
						'caption' => __( 'Activități de instalații electrice și infrastructură', 'schrack-woocommerce-sync' ),
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers video controls.
	 */
	private function register_video_controls(): void {
		$this->start_controls_section(
			'section_video',
			array(
				'label' => __( 'Galerie video', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'video_url',
			array(
				'label'       => __( 'URL clip video', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'default'     => array(
					'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/videos/project-overview.mp4',
				),
				'label_block' => true,
			)
		);

		$this->add_control(
			'video_poster_url',
			array(
				'label'   => __( 'Imagine poster video', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::MEDIA,
				'default' => array(
					'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/photos/security-cctv.jpg',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers press communication controls.
	 */
	private function register_communication_controls(): void {
		$repeater = new \Elementor\Repeater();

		$this->add_link_item_controls( $repeater, false );

		$repeater->add_control(
			'body',
			array(
				'label' => __( 'Text optional', 'schrack-woocommerce-sync' ),
				'type'  => \Elementor\Controls_Manager::TEXTAREA,
				'rows'  => 3,
			)
		);

		$this->start_controls_section(
			'section_communications',
			array(
				'label' => __( 'Comunicate de presa', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'communications',
			array(
				'label'       => __( 'Comunicate', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ title }}}',
				'default'     => array(
					array(
						'title'           => __( 'Comunicat de lansare proiect', 'schrack-woocommerce-sync' ),
						'href'            => array( 'url' => SCHRACK_WC_SYNC_URL . 'assets/funding/docs/smis-334780-comunicat-demarare.pdf' ),
						'label'           => __( 'Descarcă comunicatul PDF', 'schrack-woocommerce-sync' ),
						'secondary_href'  => array( 'url' => 'https://portalsm.ro/2025/12/comunicat-de-presa-gene-sys-security-srl/' ),
						'secondary_label' => __( 'Vezi comunicatul pe PresaSM', 'schrack-woocommerce-sync' ),
					),
					array(
						'title' => __( 'Comunicat de presă finalizare proiect', 'schrack-woocommerce-sync' ),
						'body'  => __( 'Va fi publicat la finalizarea proiectului.', 'schrack-woocommerce-sync' ),
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Registers style controls.
	 */
	private function register_style_controls(): void {
		$this->start_controls_section(
			'section_style',
			array(
				'label' => __( 'Stil', 'schrack-woocommerce-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'max_width',
			array(
				'label'      => __( 'Latime maxima', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 760,
						'max'  => 1280,
						'step' => 20,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1024,
				),
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'   => __( 'Culoare accent', 'schrack-woocommerce-sync' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#1e40af',
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'      => __( 'Rotunjire', 'schrack-woocommerce-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 8,
						'step' => 1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Adds common controls for timeline/communication link items.
	 */
	private function add_link_item_controls( \Elementor\Repeater $repeater, bool $include_date ): void {
		if ( $include_date ) {
			$repeater->add_control(
				'date',
				array(
					'label' => __( 'Data', 'schrack-woocommerce-sync' ),
					'type'  => \Elementor\Controls_Manager::TEXT,
				)
			);
		}

		$repeater->add_control(
			'title',
			array(
				'label'       => __( 'Titlu', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'href',
			array(
				'label'       => __( 'URL principal', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'label',
			array(
				'label'       => __( 'Text link principal', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'secondary_href',
			array(
				'label'       => __( 'URL secundar', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::URL,
				'label_block' => true,
			)
		);

		$repeater->add_control(
			'secondary_label',
			array(
				'label'       => __( 'Text link secundar', 'schrack-woocommerce-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'label_block' => true,
			)
		);
	}

	/**
	 * Renders the widget.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		foreach ( array( 'max_width', 'radius' ) as $slider_key ) {
			if ( isset( $settings[ $slider_key ] ) && is_array( $settings[ $slider_key ] ) ) {
				$settings[ $slider_key ] = (string) absint( $settings[ $slider_key ]['size'] ?? 0 );
			}
		}

		$renderer = new Schrack_Funding_Renderer();

		echo $renderer->render( $settings, $this->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
