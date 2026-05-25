<?php
/**
 * Store legal document pages and shortcodes.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Legal_Pages {
	private const META_KEY   = '_schrack_legal_page_type';
	private const OPTION_KEY = 'schrack_legal_pages_version';
	private const VERSION    = '2026-05-25';

	/**
	 * Registers shortcode and lazy page installation.
	 */
	public function init(): void {
		add_shortcode( 'schrack_legal_page', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( self::class, 'maybe_install_pages' ) );
	}

	/**
	 * Enqueues legal page assets early enough for generated pages.
	 */
	public function enqueue_assets(): void {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( '' === (string) get_post_meta( (int) $post->ID, self::META_KEY, true ) && ! has_shortcode( (string) $post->post_content, 'schrack_legal_page' ) ) {
			return;
		}

		$this->enqueue_style();
	}

	/**
	 * Creates or refreshes the generated legal pages after plugin updates.
	 */
	public static function maybe_install_pages(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::VERSION === (string) get_option( self::OPTION_KEY, '' ) ) {
			return;
		}

		self::install_pages();
	}

	/**
	 * Creates WordPress pages for all shop documents.
	 *
	 * @return array<string,int>
	 */
	public static function install_pages(): array {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return array();
		}

		$page_ids = array();

		foreach ( self::page_definitions() as $type => $definition ) {
			$page_id     = self::find_page_id( $type, $definition['slug'] );
			$content     = sprintf( '[schrack_legal_page type="%s"]', $type );
			$managed     = false;

			$post_data = array(
				'post_title'   => $definition['title'],
				'post_name'    => $definition['slug'],
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
				'meta_input'   => array(
					self::META_KEY => $type,
				),
			);

			if ( $page_id > 0 ) {
				if ( self::is_generated_page( $page_id, $type ) ) {
					$post_data['ID'] = $page_id;
					wp_update_post( wp_slash( $post_data ) );
					$managed = true;
				}
			} else {
				$inserted = wp_insert_post( wp_slash( $post_data ), true );
				$page_id  = is_wp_error( $inserted ) ? 0 : (int) $inserted;
				$managed  = $page_id > 0;
			}

			if ( $page_id > 0 && $managed ) {
				update_post_meta( $page_id, self::META_KEY, $type );
			}

			if ( $page_id > 0 ) {
				$page_ids[ $type ] = $page_id;
			}
		}

		update_option( self::OPTION_KEY, self::VERSION, false );

		return $page_ids;
	}

	/**
	 * Returns a generated document URL, falling back to the expected slug.
	 */
	public static function page_url( string $type ): string {
		$definitions = self::page_definitions();

		if ( ! isset( $definitions[ $type ] ) ) {
			return home_url( '/' );
		}

		$page_id = self::find_page_id( $type, $definitions[ $type ]['slug'] );

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );

			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/' . $definitions[ $type ]['slug'] . '/' );
	}

	/**
	 * Renders one legal document by shortcode.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render_shortcode( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'type' => 'terms',
			),
			is_array( $atts ) ? $atts : array(),
			'schrack_legal_page'
		);

		$type      = sanitize_key( (string) $atts['type'] );
		$document  = self::document( $type );
		$documents = self::page_definitions();

		if ( ! isset( $documents[ $type ] ) ) {
			return '';
		}

		$this->enqueue_style();

		return $this->render_document( $type, $document );
	}

	/**
	 * Enqueues the shared legal document stylesheet.
	 */
	private function enqueue_style(): void {
		wp_enqueue_style(
			'schrack-wc-legal-pages',
			SCHRACK_WC_SYNC_URL . 'assets/schrack-legal-pages.css',
			array(),
			self::VERSION
		);
	}

	/**
	 * Finds an existing generated page by meta key or slug.
	 */
	private static function find_page_id( string $type, string $slug ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_KEY,
				'meta_value'     => $type,
			)
		);

		if ( is_array( $posts ) && ! empty( $posts ) ) {
			return absint( $posts[0] );
		}

		$page = get_page_by_path( $slug, OBJECT, 'page' );

		if ( $page instanceof WP_Post ) {
			return (int) $page->ID;
		}

		return 0;
	}

	/**
	 * Returns whether a page still points to the generated shortcode.
	 */
	private static function is_generated_page( int $page_id, string $type ): bool {
		if ( $type === (string) get_post_meta( $page_id, self::META_KEY, true ) ) {
			return true;
		}

		$post = get_post( $page_id );

		return $post instanceof WP_Post && str_contains( (string) $post->post_content, '[schrack_legal_page' );
	}

	/**
	 * Page metadata used for creation and navigation.
	 *
	 * @return array<string,array{title:string,slug:string,label:string}>
	 */
	private static function page_definitions(): array {
		return array(
			'terms'     => array(
				'title' => __( 'Termeni si conditii', 'schrack-woocommerce-sync' ),
				'slug'  => 'termeni-si-conditii',
				'label' => __( 'Termeni', 'schrack-woocommerce-sync' ),
			),
			'delivery'  => array(
				'title' => __( 'Livrare si plata', 'schrack-woocommerce-sync' ),
				'slug'  => 'livrare-si-plata',
				'label' => __( 'Livrare si plata', 'schrack-woocommerce-sync' ),
			),
			'returns'   => array(
				'title' => __( 'Retur si rambursare', 'schrack-woocommerce-sync' ),
				'slug'  => 'retur-si-rambursare',
				'label' => __( 'Retur', 'schrack-woocommerce-sync' ),
			),
			'warranty'  => array(
				'title' => __( 'Garantii si service', 'schrack-woocommerce-sync' ),
				'slug'  => 'garantii-si-service',
				'label' => __( 'Garantii', 'schrack-woocommerce-sync' ),
			),
			'privacy'   => array(
				'title' => __( 'Politica de confidentialitate', 'schrack-woocommerce-sync' ),
				'slug'  => 'politica-de-confidentialitate',
				'label' => __( 'Confidentialitate', 'schrack-woocommerce-sync' ),
			),
			'cookies'   => array(
				'title' => __( 'Politica cookie-uri', 'schrack-woocommerce-sync' ),
				'slug'  => 'politica-cookie-uri',
				'label' => __( 'Cookie-uri', 'schrack-woocommerce-sync' ),
			),
			'disputes'  => array(
				'title' => __( 'Solutionarea litigiilor', 'schrack-woocommerce-sync' ),
				'slug'  => 'solutionarea-litigiilor',
				'label' => __( 'Litigii', 'schrack-woocommerce-sync' ),
			),
		);
	}

	/**
	 * Shared store identity shown in the generated documents.
	 *
	 * @return array<string,string>
	 */
	private static function business_context(): array {
		$email = sanitize_email( (string) get_option( 'admin_email', '' ) );

		return array(
			'company_name'  => 'GENE SYS SECURITY SRL',
			'brand_name'    => 'GENE SYS SECURITY',
			'site_url'      => home_url( '/' ),
			'phone_display' => '0749 235 958',
			'phone_tel'     => '+40749235958',
			'email'         => '' !== $email ? $email : 'contact@syshub.ro',
			'address_one'   => __( 'Judet Satu Mare, loc. Satu Mare', 'schrack-woocommerce-sync' ),
			'address_two'   => __( 'Str. Gheorghe Baritiu 86, cod postal 440135', 'schrack-woocommerce-sync' ),
			'cui'           => 'RO 38322763',
			'reg_com'       => 'J2017001105304',
			'euid'          => 'ROONRC.J2017001105304',
			'updated_at'    => '25 mai 2026',
		);
	}

	/**
	 * Official resources linked from the generated documents.
	 *
	 * @return array<string,string>
	 */
	private static function official_links(): array {
		return array(
			'anpc'       => 'https://anpc.ro/',
			'anpc_sal'   => 'https://anpc.ro/sal/',
			'anspdcp'    => 'https://www.dataprotection.ro/',
			'eu_redress' => 'https://consumer-redress.ec.europa.eu/site-relocation_en',
			'oug_34'     => 'https://legislatie.just.ro/Public/DetaliiDocument/158913',
		);
	}

	/**
	 * Builds one legal document.
	 *
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function document( string $type ): array {
		$context = self::business_context();
		$links   = self::official_links();

		return match ( $type ) {
			'delivery' => self::delivery_document( $context ),
			'returns'  => self::returns_document( $context, $links ),
			'warranty' => self::warranty_document( $context ),
			'privacy'  => self::privacy_document( $context, $links ),
			'cookies'  => self::cookies_document( $context ),
			'disputes' => self::disputes_document( $context, $links ),
			default    => self::terms_document( $context, $links ),
		};
	}

	/**
	 * Terms and conditions document.
	 *
	 * @param array<string,string> $context Business identity.
	 * @param array<string,string> $links Official links.
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function terms_document( array $context, array $links ): array {
		return array(
			'title'    => __( 'Termeni si conditii', 'schrack-woocommerce-sync' ),
			'intro'    => sprintf(
				__( 'Acesti termeni reglementeaza utilizarea magazinului online %1$s si plasarea comenzilor pentru produse electrice, sisteme fotovoltaice, securitate si produse conexe comercializate de %2$s.', 'schrack-woocommerce-sync' ),
				esc_html( $context['site_url'] ),
				esc_html( $context['company_name'] )
			),
			'sections' => array(
				self::section(
					__( '1. Identitatea comerciantului', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Magazinul online este operat de %1$s, cu sediul in %2$s, %3$s.', 'schrack-woocommerce-sync' ),
							esc_html( $context['company_name'] ),
							esc_html( $context['address_one'] ),
							esc_html( $context['address_two'] )
						),
					),
					array(
						sprintf( 'CUI: %s', esc_html( $context['cui'] ) ),
						sprintf( 'Registrul Comertului: %s', esc_html( $context['reg_com'] ) ),
						sprintf( 'EUID: %s', esc_html( $context['euid'] ) ),
						sprintf( 'Telefon: <a href="tel:%1$s">%2$s</a>', esc_attr( $context['phone_tel'] ), esc_html( $context['phone_display'] ) ),
						sprintf( 'Email: <a href="mailto:%1$s">%1$s</a>', esc_attr( $context['email'] ) ),
					)
				),
				self::section(
					__( '2. Utilizarea site-ului', 'schrack-woocommerce-sync' ),
					array(
						__( 'Clientul se obliga sa foloseasca site-ul in scopuri legale si sa furnizeze date reale, complete si actuale atunci cand creeaza un cont, solicita o oferta sau plaseaza o comanda.', 'schrack-woocommerce-sync' ),
						__( 'Ne rezervam dreptul de a corecta erori de afisare, pret, stoc sau descriere si de a contacta clientul inainte de confirmarea comenzii atunci cand o informatie trebuie clarificata.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '3. Produse, preturi si disponibilitate', 'schrack-woocommerce-sync' ),
					array(
						__( 'Descrierile, imaginile si specificatiile produselor sunt prezentate pentru informarea clientului. Pentru proiecte tehnice, dimensionari sau produse cu compatibilitate speciala, recomandam confirmarea solutiei cu echipa noastra inainte de achizitie.', 'schrack-woocommerce-sync' ),
						__( 'Preturile sunt afisate in RON. Taxele, costurile de transport si eventualele reduceri sunt afisate inainte de finalizarea comenzii, in functie de configuratia magazinului si de adresa de livrare.', 'schrack-woocommerce-sync' ),
						__( 'Disponibilitatea produselor poate depinde de stocul propriu, stocul furnizorilor si conditiile logistice. O comanda devine ferma dupa confirmarea disponibilitatii si, dupa caz, dupa confirmarea platii.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '4. Comenzi si cont client', 'schrack-woocommerce-sync' ),
					array(
						__( 'Clientul poate plasa comenzi prin magazinul online, prin contul de client sau prin alte canale comunicate pe site. Confirmarea automata primita dupa plasarea comenzii confirma inregistrarea solicitarii, nu disponibilitatea finala a tuturor produselor.', 'schrack-woocommerce-sync' ),
						__( 'Pentru clientii B2B pot exista preturi, discounturi, termene de plata sau conditii comerciale diferite, confirmate separat de vanzator.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '5. Plata', 'schrack-woocommerce-sync' ),
					array(
						__( 'Metodele de plata disponibile sunt prezentate in pagina de checkout. Plata poate fi procesata de furnizori externi de servicii de plata, iar clientul trebuie sa respecte termenii acestora.', 'schrack-woocommerce-sync' ),
						__( 'Factura se emite pe baza datelor furnizate de client. Clientul este responsabil pentru corectitudinea datelor de facturare introduse in cont sau in formularul de comanda.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '6. Livrare', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Termenele, costurile si conditiile de livrare sunt detaliate in pagina <a href="%s">Livrare si plata</a>. Termenul estimativ curge de la confirmarea comenzii si, dupa caz, de la confirmarea platii.', 'schrack-woocommerce-sync' ),
							esc_url( self::page_url( 'delivery' ) )
						),
					)
				),
				self::section(
					__( '7. Dreptul de retragere si retururi', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Consumatorii beneficiaza de dreptul legal de retragere pentru contractele la distanta, in conditiile OUG 34/2014. Procedura completa este disponibila in pagina <a href="%1$s">Retur si rambursare</a>, iar cadrul legal poate fi consultat pe <a href="%2$s" target="_blank" rel="noopener noreferrer">Portalul Legislativ</a>.', 'schrack-woocommerce-sync' ),
							esc_url( self::page_url( 'returns' ) ),
							esc_url( $links['oug_34'] )
						),
						__( 'Dreptul legal de retragere se aplica persoanelor fizice care cumpara in calitate de consumator. Pentru persoane juridice, returul se accepta numai conform intelegerii comerciale scrise sau politicii comerciale confirmate de vanzator.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '8. Garantii si conformitate', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Produsele beneficiaza de garantia legala de conformitate si, dupa caz, de garantie comerciala oferita de producator/importator. Detaliile operationale sunt prezentate in pagina <a href="%s">Garantii si service</a>.', 'schrack-woocommerce-sync' ),
							esc_url( self::page_url( 'warranty' ) )
						),
					)
				),
				self::section(
					__( '9. Reclamatii si solutionarea litigiilor', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Orice reclamatie poate fi transmisa la <a href="mailto:%1$s">%1$s</a>. Vom analiza solicitarea si vom raspunde in termenul legal aplicabil, in functie de natura sesizarii.', 'schrack-woocommerce-sync' ),
							esc_attr( $context['email'] )
						),
						sprintf(
							__( 'Consumatorii se pot adresa ANPC si pot consulta informatii despre solutionarea alternativa a litigiilor in pagina <a href="%s">Solutionarea litigiilor</a>.', 'schrack-woocommerce-sync' ),
							esc_url( self::page_url( 'disputes' ) )
						),
					)
				),
				self::section(
					__( '10. Date personale si cookie-uri', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Prelucrarea datelor personale este descrisa in pagina <a href="%1$s">Politica de confidentialitate</a>, iar utilizarea cookie-urilor in pagina <a href="%2$s">Politica cookie-uri</a>.', 'schrack-woocommerce-sync' ),
							esc_url( self::page_url( 'privacy' ) ),
							esc_url( self::page_url( 'cookies' ) )
						),
					)
				),
				self::section(
					__( '11. Modificarea termenilor', 'schrack-woocommerce-sync' ),
					array(
						__( 'Putem actualiza acesti termeni pentru modificari operationale, comerciale sau legislative. Versiunea aplicabila unei comenzi este cea publicata la data plasarii comenzii, cu exceptia cazului in care legea prevede altfel.', 'schrack-woocommerce-sync' ),
					)
				),
			),
		);
	}

	/**
	 * Delivery and payment document.
	 *
	 * @param array<string,string> $context Business identity.
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function delivery_document( array $context ): array {
		return array(
			'title'    => __( 'Livrare si plata', 'schrack-woocommerce-sync' ),
			'intro'    => __( 'Aceasta pagina descrie termenele estimative de livrare, costurile, receptia produselor si metodele de plata disponibile pentru comenzile plasate in magazinul online.', 'schrack-woocommerce-sync' ),
			'sections' => array(
				self::section(
					__( '1. Zone de livrare', 'schrack-woocommerce-sync' ),
					array(
						__( 'Livrarea se efectueaza pe teritoriul Romaniei, prin curier sau prin alta metoda confirmata de vanzator. Pentru proiecte, produse agabaritice sau livrari speciale, conditiile se confirma separat.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '2. Termene estimative de livrare', 'schrack-woocommerce-sync' ),
					array(
						__( 'Termenele sunt estimative si depind de disponibilitatea produselor, validarea comenzii, confirmarea platii si programul transportatorului.', 'schrack-woocommerce-sync' ),
					),
					array(
						__( 'Produse aflate in stoc propriu: in mod uzual 1-3 zile lucratoare de la confirmarea comenzii.', 'schrack-woocommerce-sync' ),
						__( 'Produse disponibile la furnizor sau la comanda: in mod uzual 3-10 zile lucratoare, daca furnizorul confirma disponibilitatea.', 'schrack-woocommerce-sync' ),
						__( 'Produse speciale, configurate sau pentru proiecte: termenul de livrare se confirma individual prin oferta sau mesaj scris.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '3. Costuri de transport', 'schrack-woocommerce-sync' ),
					array(
						__( 'Costul de transport este afisat inainte de finalizarea comenzii, in functie de adresa, greutate, volum, valoarea cosului si metoda de livrare disponibila.', 'schrack-woocommerce-sync' ),
						__( 'Daca o comanda necesita transport special, manipulare suplimentara sau livrari multiple, clientul va fi informat inainte de procesarea finala.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '4. Receptia produselor', 'schrack-woocommerce-sync' ),
					array(
						__( 'La primirea coletului, clientul trebuie sa verifice integritatea ambalajului si numarul coletelor. Deteriorarile vizibile trebuie mentionate in documentele transportatorului si comunicate cat mai rapid catre vanzator.', 'schrack-woocommerce-sync' ),
						__( 'Pentru produse fragile, tehnice sau cu valoare ridicata, recomandam documentarea prin fotografii la receptie daca ambalajul prezinta urme de lovire, umezeala sau desfacere.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '5. Plata', 'schrack-woocommerce-sync' ),
					array(
						__( 'Metodele disponibile pot include plata online cu cardul, transfer bancar, ramburs sau alte metode active in checkout. Disponibilitatea unei metode poate varia in functie de tipul clientului, valoarea comenzii si configuratia magazinului.', 'schrack-woocommerce-sync' ),
						__( 'Pentru plata prin transfer bancar, comanda poate fi procesata dupa confirmarea incasarii sau conform conditiilor comerciale agreate cu clientul.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '6. Intarzieri si indisponibilitati', 'schrack-woocommerce-sync' ),
					array(
						__( 'Daca livrarea intarzie sau un produs devine indisponibil, clientul va fi contactat pentru alegerea unei solutii: asteptarea produsului, inlocuirea cu un produs echivalent, livrare partiala sau anularea produsului indisponibil.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '7. Contact pentru livrare', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Pentru intrebari despre livrare sau plata ne puteti contacta la <a href="mailto:%1$s">%1$s</a> sau telefonic la <a href="tel:%2$s">%3$s</a>.', 'schrack-woocommerce-sync' ),
							esc_attr( $context['email'] ),
							esc_attr( $context['phone_tel'] ),
							esc_html( $context['phone_display'] )
						),
					)
				),
			),
		);
	}

	/**
	 * Return and refund document.
	 *
	 * @param array<string,string> $context Business identity.
	 * @param array<string,string> $links Official links.
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function returns_document( array $context, array $links ): array {
		return array(
			'title'    => __( 'Retur si rambursare', 'schrack-woocommerce-sync' ),
			'intro'    => __( 'Aceasta politica explica modul in care consumatorii isi pot exercita dreptul de retragere, conditiile de retur si modul de rambursare.', 'schrack-woocommerce-sync' ),
			'sections' => array(
				self::section(
					__( '1. Dreptul de retragere', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Consumatorul are dreptul sa se retraga din contractul la distanta in termen de 14 zile, fara a preciza motivele, conform OUG 34/2014. Textul legal actualizat poate fi consultat pe <a href="%s" target="_blank" rel="noopener noreferrer">Portalul Legislativ</a>.', 'schrack-woocommerce-sync' ),
							esc_url( $links['oug_34'] )
						),
						__( 'Termenul de 14 zile curge, in general, de la ziua in care consumatorul sau o persoana indicata de acesta intra in posesia fizica a produselor.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '2. Cum se solicita returul', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Solicitarea se transmite la <a href="mailto:%1$s">%1$s</a>, cu numarul comenzii, datele clientului si produsele returnate. Clientul poate folosi modelul de formular de retragere de mai jos, dar folosirea acestuia nu este obligatorie.', 'schrack-woocommerce-sync' ),
							esc_attr( $context['email'] )
						),
					),
					array(
						__( 'Catre: GENE SYS SECURITY SRL', 'schrack-woocommerce-sync' ),
						__( 'Va informez prin prezenta cu privire la retragerea mea din contractul referitor la vanzarea urmatoarelor produse:', 'schrack-woocommerce-sync' ),
						__( 'Comandate la data / primite la data:', 'schrack-woocommerce-sync' ),
						__( 'Numele consumatorului:', 'schrack-woocommerce-sync' ),
						__( 'Adresa consumatorului:', 'schrack-woocommerce-sync' ),
						__( 'Data si semnatura, daca formularul este transmis pe hartie:', 'schrack-woocommerce-sync' ),
					),
					'is_notice'
				),
				self::section(
					__( '3. Trimiterea produselor inapoi', 'schrack-woocommerce-sync' ),
					array(
						__( 'Cu exceptia cazului in care vanzatorul se ofera sa preia produsele, consumatorul returneaza produsele fara intarziere nejustificata si cel tarziu in 14 zile de la comunicarea retragerii.', 'schrack-woocommerce-sync' ),
						__( 'Costul direct de returnare este suportat de consumator, cu exceptia situatiilor in care produsul livrat este gresit, neconform sau deteriorat din culpa vanzatorului/transportului confirmata documentar.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '4. Conditia produselor returnate', 'schrack-woocommerce-sync' ),
					array(
						__( 'Produsele trebuie returnate, pe cat posibil, in ambalajul original, cu accesoriile, documentele si etichetele primite. Clientul raspunde pentru diminuarea valorii produselor rezultata din manipulari care depasesc ceea ce este necesar pentru stabilirea naturii, caracteristicilor si functionarii produselor.', 'schrack-woocommerce-sync' ),
						__( 'Pentru produse electrice, electronice, de securitate sau fotovoltaice, montajul, cablarea, configurarea, deteriorarea sigiliilor sau urmele de instalare pot afecta valoarea produsului si posibilitatea de revanzare.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '5. Rambursarea', 'schrack-woocommerce-sync' ),
					array(
						__( 'Sumele eligibile se ramburseaza folosind aceeasi metoda de plata folosita la tranzactia initiala, cu exceptia cazului in care clientul a agreat expres o alta metoda.', 'schrack-woocommerce-sync' ),
						__( 'Rambursarea se poate amana pana la primirea produselor returnate sau pana la furnizarea unei dovezi de expediere, in functie de care eveniment are loc primul.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '6. Exceptii si produse speciale', 'schrack-woocommerce-sync' ),
					array(
						__( 'Dreptul de retragere poate fi limitat pentru produse executate dupa specificatiile clientului, produse personalizate, produse sigilate care nu pot fi returnate din motive de protectie a sanatatii sau igiena dupa desigilare, precum si alte exceptii prevazute de legislatia aplicabila.', 'schrack-woocommerce-sync' ),
						__( 'Pentru persoane juridice, returul produselor se face numai in baza unei acceptari scrise din partea vanzatorului sau conform conditiilor comerciale agreate.', 'schrack-woocommerce-sync' ),
					)
				),
			),
		);
	}

	/**
	 * Warranty document.
	 *
	 * @param array<string,string> $context Business identity.
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function warranty_document( array $context ): array {
		return array(
			'title'    => __( 'Garantii si service', 'schrack-woocommerce-sync' ),
			'intro'    => __( 'Aceasta pagina descrie modul de gestionare a garantiilor, produselor neconforme si solicitarilor de service pentru produsele cumparate din magazin.', 'schrack-woocommerce-sync' ),
			'sections' => array(
				self::section(
					__( '1. Garantia legala de conformitate', 'schrack-woocommerce-sync' ),
					array(
						__( 'Consumatorii beneficiaza de garantia legala de conformitate conform legislatiei in vigoare. Drepturile legale ale consumatorului nu sunt afectate de garantiile comerciale oferite separat de producator sau vanzator.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '2. Garantia comerciala', 'schrack-woocommerce-sync' ),
					array(
						__( 'Anumite produse pot beneficia de garantie comerciala oferita de producator, importator sau distribuitor. Durata si conditiile acesteia pot fi mentionate in certificatul de garantie, pe ambalaj, in documentatia produsului sau in oferta comerciala.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '3. Cum se transmite o solicitare de garantie', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Solicitarile se transmit la <a href="mailto:%1$s">%1$s</a>, impreuna cu numarul comenzii/facturii, descrierea defectului, fotografii sau video relevante si datele de contact.', 'schrack-woocommerce-sync' ),
							esc_attr( $context['email'] )
						),
					),
					array(
						__( 'Nu demontati componente care necesita service autorizat daca acest lucru poate agrava defectul sau poate anula garantia comerciala.', 'schrack-woocommerce-sync' ),
						__( 'Pentru echipamente instalate, poate fi necesara verificarea conditiilor de montaj, alimentare, impamantare, configurare si exploatare.', 'schrack-woocommerce-sync' ),
						__( 'Produsele trebuie ambalate corespunzator pentru transportul catre service.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '4. Excluderi uzuale', 'schrack-woocommerce-sync' ),
					array(
						__( 'Garantia nu acopera, in mod uzual, defecte cauzate de montaj incorect, utilizare necorespunzatoare, interventii neautorizate, socuri mecanice, supratensiuni, umezeala, mediu de exploatare necorespunzator sau uzura normala, cu exceptia cazului in care legea prevede altfel.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '5. Solutii posibile', 'schrack-woocommerce-sync' ),
					array(
						__( 'In functie de constatarea tehnica si cadrul legal aplicabil, solutia poate include remedierea, inlocuirea, reducerea pretului sau rambursarea, dupa caz.', 'schrack-woocommerce-sync' ),
					)
				),
			),
		);
	}

	/**
	 * Privacy policy document.
	 *
	 * @param array<string,string> $context Business identity.
	 * @param array<string,string> $links Official links.
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function privacy_document( array $context, array $links ): array {
		return array(
			'title'    => __( 'Politica de confidentialitate', 'schrack-woocommerce-sync' ),
			'intro'    => sprintf(
				__( '%s prelucreaza date personale pentru administrarea magazinului online, onorarea comenzilor, comunicarea cu clientii si respectarea obligatiilor legale.', 'schrack-woocommerce-sync' ),
				esc_html( $context['company_name'] )
			),
			'sections' => array(
				self::section(
					__( '1. Operatorul de date', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Operatorul datelor este %1$s, cu sediul in %2$s, %3$s. Pentru intrebari privind datele personale ne puteti contacta la <a href="mailto:%4$s">%4$s</a>.', 'schrack-woocommerce-sync' ),
							esc_html( $context['company_name'] ),
							esc_html( $context['address_one'] ),
							esc_html( $context['address_two'] ),
							esc_attr( $context['email'] )
						),
					)
				),
				self::section(
					__( '2. Date prelucrate', 'schrack-woocommerce-sync' ),
					array(
						__( 'Putem prelucra date precum nume, prenume, companie, CUI, adresa de facturare/livrare, email, telefon, date de cont, istoric comenzi, produse comandate, date de plata necesare confirmarii tranzactiilor si date tehnice despre utilizarea site-ului.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '3. Scopuri si temeiuri', 'schrack-woocommerce-sync' ),
					array(
						__( 'Datele sunt prelucrate pentru executarea contractului, administrarea contului, emiterea facturilor, livrarea produselor, suport clienti, gestionarea garantiilor si retururilor, indeplinirea obligatiilor fiscale/contabile, securitatea site-ului si, daca este cazul, marketing cu acordul persoanei vizate sau in baza interesului legitim permis de lege.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '4. Destinatari', 'schrack-woocommerce-sync' ),
					array(
						__( 'Datele pot fi transmise catre furnizori de servicii implicati in operarea magazinului: curieri, procesatori de plata, servicii IT/hosting, contabilitate, consultanti, furnizori de service/garantie si autoritati publice, atunci cand legea impune acest lucru.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '5. Durata pastrarii', 'schrack-woocommerce-sync' ),
					array(
						__( 'Datele sunt pastrate pe durata necesara scopurilor pentru care au fost colectate, pe durata relatiei contractuale si ulterior conform termenelor legale de arhivare, prescriptie, fiscalitate si aparare a drepturilor in instanta.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '6. Drepturile persoanelor vizate', 'schrack-woocommerce-sync' ),
					array(
						__( 'Persoanele vizate pot solicita acces, rectificare, stergere, restrictionare, portabilitate, opozitie si retragerea consimtamantului, atunci cand aceste drepturi sunt aplicabile.', 'schrack-woocommerce-sync' ),
						sprintf(
							__( 'Persoanele vizate pot depune plangere la Autoritatea Nationala de Supraveghere a Prelucrarii Datelor cu Caracter Personal: <a href="%s" target="_blank" rel="noopener noreferrer">dataprotection.ro</a>.', 'schrack-woocommerce-sync' ),
							esc_url( $links['anspdcp'] )
						),
					)
				),
				self::section(
					__( '7. Securitate', 'schrack-woocommerce-sync' ),
					array(
						__( 'Aplicam masuri tehnice si organizatorice rezonabile pentru protejarea datelor impotriva accesului neautorizat, pierderii, distrugerii sau modificarii. Nicio transmisie prin internet nu poate fi garantata absolut, dar luam masuri proportionale cu riscurile.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '8. Cookie-uri', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Detalii despre fisierele cookie si tehnologii similare sunt disponibile in pagina <a href="%s">Politica cookie-uri</a>.', 'schrack-woocommerce-sync' ),
							esc_url( self::page_url( 'cookies' ) )
						),
					)
				),
			),
		);
	}

	/**
	 * Cookie policy document.
	 *
	 * @param array<string,string> $context Business identity.
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function cookies_document( array $context ): array {
		return array(
			'title'    => __( 'Politica cookie-uri', 'schrack-woocommerce-sync' ),
			'intro'    => sprintf(
				__( 'Site-ul %s foloseste cookie-uri si tehnologii similare pentru functionarea magazinului, securitate, preferinte si, dupa caz, statistici sau marketing.', 'schrack-woocommerce-sync' ),
				esc_html( $context['site_url'] )
			),
			'sections' => array(
				self::section(
					__( '1. Ce sunt cookie-urile', 'schrack-woocommerce-sync' ),
					array(
						__( 'Cookie-urile sunt fisiere mici salvate pe dispozitivul utilizatorului. Ele pot retine informatii necesare functionarii site-ului, precum sesiunea, cosul de cumparaturi, preferintele sau interactiunile cu magazinul.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '2. Tipuri de cookie-uri', 'schrack-woocommerce-sync' ),
					array(),
					array(
						__( 'Cookie-uri strict necesare: esentiale pentru cos, checkout, autentificare, securitate si functionarea magazinului.', 'schrack-woocommerce-sync' ),
						__( 'Cookie-uri de preferinte: retin optiuni precum limba, regiunea sau setari ale interfetei.', 'schrack-woocommerce-sync' ),
						__( 'Cookie-uri de statistica: ajuta la intelegerea modului in care este folosit site-ul, daca sunt activate.', 'schrack-woocommerce-sync' ),
						__( 'Cookie-uri de marketing: pot fi folosite pentru masurarea campaniilor sau afisarea de continut relevant, numai daca sunt active in site.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '3. Cookie-uri WooCommerce', 'schrack-woocommerce-sync' ),
					array(
						__( 'Magazinul poate folosi cookie-uri pentru pastrarea cosului, detectarea modificarilor in cos, autentificarea clientilor si finalizarea comenzilor. Acestea sunt necesare pentru serviciul solicitat de utilizator.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '4. Administrarea cookie-urilor', 'schrack-woocommerce-sync' ),
					array(
						__( 'Utilizatorul poate sterge sau bloca cookie-urile din setarile browserului. Blocarea cookie-urilor strict necesare poate face imposibila utilizarea cosului, checkout-ului sau autentificarii.', 'schrack-woocommerce-sync' ),
						__( 'Daca site-ul foloseste un modul de consimtamant, preferintele pot fi modificate prin acel modul, atunci cand este disponibil.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '5. Servicii terte', 'schrack-woocommerce-sync' ),
					array(
						__( 'Unele servicii externe, precum procesatorii de plata, hartile, instrumentele de analiza sau platformele de marketing, pot seta propriile cookie-uri atunci cand sunt integrate si active. Politicile acestor furnizori se aplica separat.', 'schrack-woocommerce-sync' ),
					)
				),
			),
		);
	}

	/**
	 * Dispute resolution document.
	 *
	 * @param array<string,string> $context Business identity.
	 * @param array<string,string> $links Official links.
	 * @return array{title:string,intro:string,sections:array<int,array<string,mixed>>}
	 */
	private static function disputes_document( array $context, array $links ): array {
		return array(
			'title'    => __( 'Solutionarea litigiilor', 'schrack-woocommerce-sync' ),
			'intro'    => __( 'Dorim rezolvarea amiabila si rapida a oricarei nemultumiri legate de produse, comenzi, livrare, retururi sau garantii.', 'schrack-woocommerce-sync' ),
			'sections' => array(
				self::section(
					__( '1. Contact initial', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Pentru orice sesizare, clientul ne poate contacta la <a href="mailto:%1$s">%1$s</a> sau telefonic la <a href="tel:%2$s">%3$s</a>, cu numarul comenzii si documentele relevante.', 'schrack-woocommerce-sync' ),
							esc_attr( $context['email'] ),
							esc_attr( $context['phone_tel'] ),
							esc_html( $context['phone_display'] )
						),
					)
				),
				self::section(
					__( '2. Reclamatia scrisa', 'schrack-woocommerce-sync' ),
					array(
						__( 'Pentru o analiza corecta, reclamatia trebuie sa includa datele clientului, numarul comenzii/facturii, produsul vizat, descrierea problemei, fotografii sau alte documente utile si solutia solicitata.', 'schrack-woocommerce-sync' ),
					)
				),
				self::section(
					__( '3. ANPC si SAL', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Consumatorii se pot adresa Autoritatii Nationale pentru Protectia Consumatorilor: <a href="%1$s" target="_blank" rel="noopener noreferrer">anpc.ro</a>. Informatii despre solutionarea alternativa a litigiilor sunt disponibile pe pagina ANPC SAL: <a href="%2$s" target="_blank" rel="noopener noreferrer">anpc.ro/sal</a>.', 'schrack-woocommerce-sync' ),
							esc_url( $links['anpc'] ),
							esc_url( $links['anpc_sal'] )
						),
					)
				),
				self::section(
					__( '4. Redresare consumatori in UE', 'schrack-woocommerce-sync' ),
					array(
						sprintf(
							__( 'Platforma europeana SOL/ODR pentru solutionarea online a litigiilor a fost inchisa la 20 iulie 2025. Informatii actuale despre instrumentele europene pentru consumatori sunt disponibile la <a href="%s" target="_blank" rel="noopener noreferrer">Consumer Redress in the EU</a>.', 'schrack-woocommerce-sync' ),
							esc_url( $links['eu_redress'] )
						),
					)
				),
				self::section(
					__( '5. Litigii B2B', 'schrack-woocommerce-sync' ),
					array(
						__( 'Pentru clienti persoane juridice, eventualele litigii se solutioneaza potrivit contractului, ofertei acceptate, termenilor comerciali agreati si legislatiei aplicabile profesionistilor.', 'schrack-woocommerce-sync' ),
					)
				),
			),
		);
	}

	/**
	 * Creates a normalized section array.
	 *
	 * @param array<int,string> $paragraphs Paragraph HTML strings.
	 * @param array<int,string> $items List item HTML strings.
	 * @return array{title:string,paragraphs:array<int,string>,items:array<int,string>,variant:string}
	 */
	private static function section( string $title, array $paragraphs = array(), array $items = array(), string $variant = '' ): array {
		return array(
			'title'      => $title,
			'paragraphs' => $paragraphs,
			'items'      => $items,
			'variant'    => $variant,
		);
	}

	/**
	 * Renders the document shell.
	 *
	 * @param array{title:string,intro:string,sections:array<int,array<string,mixed>>} $document Document data.
	 */
	private function render_document( string $type, array $document ): string {
		$context     = self::business_context();
		$definitions = self::page_definitions();

		ob_start();
		?>
		<article class="schrack-legal">
			<header class="schrack-legal__hero">
				<p class="schrack-legal__eyebrow"><?php esc_html_e( 'Documente magazin online', 'schrack-woocommerce-sync' ); ?></p>
				<h1><?php echo esc_html( $document['title'] ); ?></h1>
				<p><?php echo wp_kses_post( $document['intro'] ); ?></p>
				<dl class="schrack-legal__meta">
					<div>
						<dt><?php esc_html_e( 'Comerciant', 'schrack-woocommerce-sync' ); ?></dt>
						<dd><?php echo esc_html( $context['company_name'] ); ?></dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Actualizat', 'schrack-woocommerce-sync' ); ?></dt>
						<dd><?php echo esc_html( $context['updated_at'] ); ?></dd>
					</div>
				</dl>
			</header>

			<div class="schrack-legal__layout">
				<nav class="schrack-legal__nav" aria-label="<?php esc_attr_e( 'Documente legale', 'schrack-woocommerce-sync' ); ?>">
					<?php foreach ( $definitions as $nav_type => $definition ) : ?>
						<a href="<?php echo esc_url( self::page_url( $nav_type ) ); ?>" <?php echo $type === $nav_type ? 'aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<?php echo esc_html( $definition['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<div class="schrack-legal__content">
					<?php foreach ( $document['sections'] as $section ) : ?>
						<?php $variant = isset( $section['variant'] ) ? (string) $section['variant'] : ''; ?>
						<section class="<?php echo esc_attr( 'schrack-legal__section' . ( '' !== $variant ? ' ' . $variant : '' ) ); ?>">
							<h2><?php echo esc_html( (string) $section['title'] ); ?></h2>
							<?php foreach ( (array) $section['paragraphs'] as $paragraph ) : ?>
								<p><?php echo wp_kses_post( (string) $paragraph ); ?></p>
							<?php endforeach; ?>
							<?php if ( ! empty( $section['items'] ) ) : ?>
								<ul>
									<?php foreach ( (array) $section['items'] as $item ) : ?>
										<li><?php echo wp_kses_post( (string) $item ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>
		</article>
		<?php

		return (string) ob_get_clean();
	}
}
