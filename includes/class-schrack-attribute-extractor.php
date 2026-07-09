<?php
/**
 * Extracts filterable technical attributes from free-text product names.
 *
 * Schrack's catalog feeds do not expose structured technical attributes
 * (only price/logistics columns); the real specs -- IP rating, voltage,
 * wattage, poles, etc. -- are embedded as free text inside the product
 * name (e.g. "NUMINOS L 3-Ph. 28W 2620lm 4000K 36° 230V DALI IP20 negru").
 * This class recovers the well-standardized ones so they can become real,
 * filterable WooCommerce product attributes.
 *
 * Patterns were validated against a 5000-row real catalog export before
 * being written here; see each rule for its approximate hit rate.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Attribute_Extractor {
	/**
	 * Extracts known technical attributes from a product name.
	 *
	 * @return array<string,array{label:string,value:string}> Keyed by attribute slug.
	 */
	public static function extract( string $name ): array {
		$name = trim( $name );

		if ( '' === $name ) {
			return array();
		}

		$attributes = array();

		foreach ( self::rules() as $slug => $rule ) {
			$value = ( $rule['matcher'] )( $name );

			if ( null !== $value && '' !== $value ) {
				$attributes[ $slug ] = array(
					'label' => $rule['label'],
					'value' => $value,
				);
			}
		}

		return $attributes;
	}

	/**
	 * Returns the attribute label for a slug, or the slug itself if unknown.
	 */
	public static function label_for_slug( string $slug ): string {
		$rules = self::rules();

		return isset( $rules[ $slug ] ) ? (string) $rules[ $slug ]['label'] : $slug;
	}

	/**
	 * Returns all known attribute slugs.
	 *
	 * @return array<int,string>
	 */
	public static function slugs(): array {
		return array_keys( self::rules() );
	}

	/**
	 * Returns the extraction rule table. Each matcher returns the formatted
	 * attribute value, or null when the name does not contain that attribute.
	 *
	 * Memoized: a full catalog parse calls extract() once per product name
	 * (thousands of times per sync cycle), and rebuilding ~15 closures on every
	 * call was measurable overhead worth avoiding.
	 *
	 * @return array<string,array{label:string,matcher:callable(string):?string}>
	 */
	private static function rules(): array {
		static $rules = null;

		if ( null !== $rules ) {
			return $rules;
		}

		$rules = array(
			// ~19% of names, e.g. "NUMINOS L ... IP20 negru".
			'ip-rating'  => array(
				'label'   => __( 'Grad de protecție IP', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/\bIP\s?(\d{2})\b/i', $name, $m ) ) {
						return 'IP' . $m[1];
					}
					return null;
				},
			),
			// ~18% of names, e.g. "... 230V DALI ...". Excludes bare unit letters
			// glued to other tokens so it does not fire on unrelated text.
			'voltage'    => array(
				'label'   => __( 'Tensiune', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/(?<![\w.,])(\d{2,3})\s?V(?:AC|DC|c\.a\.|c\.c\.)?\b(?!\w)/i', $name, $m ) ) {
						return $m[1] . 'V';
					}
					return null;
				},
			),
			// ~23% of names, e.g. "... 28W 2620lm ...". Excludes "X=123W"-style
			// dimension callouts via the negative lookbehind on "=".
			'wattage'    => array(
				'label'   => __( 'Putere', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/(?<![\w.,=])(\d{1,4}(?:[.,]\d+)?)\s?W\b(?!\w)/i', $name, $m ) ) {
						return str_replace( ',', '.', $m[1] ) . 'W';
					}
					return null;
				},
			),
			// ~12% of names. Excludes "L=1200 A=600mm" dimension callouts and
			// "Cat.7a" cable-category codes via the negative look-around/word
			// boundary, both confirmed false positives in real feed data.
			'amperage'   => array(
				'label'   => __( 'Curent nominal', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/(?<![\w.,=A])(\d{1,4}(?:[.,]\d+)?)\s?A\b(?![\w=])/i', $name, $m ) ) {
						return str_replace( ',', '.', $m[1] ) . 'A';
					}
					return null;
				},
			),
			// ~8% of names, e.g. "..., 2 poli" or "..., 1P+N".
			'poles'      => array(
				'label'   => __( 'Număr de poli', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/\b(\d)[\s\-]?(?:poli|pol)\b/i', $name, $m ) ) {
						return $m[1] . ' poli';
					}
					if ( preg_match( '/\b(\d)P\+?N?\b/', $name, $m ) ) {
						return $m[1] . ' poli';
					}
					return null;
				},
			),
			// ~7% of names, e.g. "Întreruptor automat C40/1 10kA".
			'breaking-capacity' => array(
				'label'   => __( 'Capacitate de rupere', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/\b(\d{1,3}(?:[.,]\d+)?)\s?kA\b/i', $name, $m ) ) {
						return str_replace( ',', '.', $m[1] ) . 'kA';
					}
					return null;
				},
			),
			// ~12% of names, e.g. "... 2620lm 4000K ...".
			'color-temperature' => array(
				'label'   => __( 'Temperatură de culoare', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/(?<!\d)(\d{4})\s?K\b/i', $name, $m ) ) {
						return $m[1] . 'K';
					}
					return null;
				},
			),
			// ~15% of names, e.g. "... 2620lm ...".
			'lumens'     => array(
				'label'   => __( 'Flux luminos', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/\b(\d{2,6})\s?lm\b/i', $name, $m ) ) {
						return $m[1] . ' lm';
					}
					return null;
				},
			),
			// ~3% of names, e.g. "..., 30mA, tip A".
			'residual-current'  => array(
				'label'   => __( 'Curent rezidual', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/\b(\d{2,4})\s?mA\b/i', $name, $m ) ) {
						return $m[1] . 'mA';
					}
					return null;
				},
			),
			// ~6% of names, e.g. "... DALI IP20 negru" or "Taster KNX ...".
			'protocol'   => array(
				'label'   => __( 'Protocol', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					$canonical = array(
						'dali'      => 'DALI',
						'knx'       => 'KNX',
						'bluetooth' => 'Bluetooth',
						'wifi'      => 'WiFi',
						'zigbee'    => 'Zigbee',
						'dmx'       => 'DMX',
					);

					if ( preg_match( '/\b(DALI|KNX|Bluetooth|WiFi|Zigbee|DMX)\b/i', $name, $m ) ) {
						return $canonical[ strtolower( $m[1] ) ] ?? $m[1];
					}
					return null;
				},
			),
			// ~33% of names, e.g. "... DALI IP20 negru". The best single hit rate of any
			// rule here; Romanian color words are near-universally the last token in
			// luminaire/device names.
			'color'      => array(
				'label'   => __( 'Culoare', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/\b(alb|negru|gri|argintiu|antracit|auriu|maro|crom|bronz|nichel|ro[sș]u|verde|albastru|galben|crem|bej|transparent|opal|fumuriu|grafit|titan)\b/iu', $name, $m ) ) {
						return ucfirst( strtolower( $m[1] ) );
					}
					return null;
				},
			),
			// ~11% of names, e.g. "Cablu de energie, PVC, ...". Dominated by "PVC"
			// (cable insulation), the largest single catalog segment.
			'material'   => array(
				'label'   => __( 'Material', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/\b(o[tț]el|plastic|metalic|metal|aluminiu|inox|polimer|policarbonat|pvc|abs|cauciuc|sticl[aă]|poliester|ceramic[aă]|tabl[aă])\b/iu', $name, $m ) ) {
						$value = strtolower( $m[1] );
						return in_array( $value, array( 'pvc', 'abs' ), true ) ? strtoupper( $value ) : ucfirst( $value );
					}
					return null;
				},
			),
			// ~12% of names, e.g. "... 36° 230V ..." (luminaire beam angle) or
			// "... 360° ..." (motion-sensor detection angle) -- kept generic since both
			// domains use a bare "N°" in the name. Excludes "40° C" temperature ratings
			// via the negative lookahead, a confirmed false positive in real feed data.
			'angle'      => array(
				'label'   => __( 'Unghi', 'schrack-woocommerce-sync' ),
				'matcher' => static function ( string $name ): ?string {
					if ( preg_match( '/(?<![\d.])(\d{1,3})°(?!\s?[Cc]\b)/', $name, $m ) ) {
						return $m[1] . '°';
					}
					return null;
				},
			),
		);

		return $rules;
	}
}
