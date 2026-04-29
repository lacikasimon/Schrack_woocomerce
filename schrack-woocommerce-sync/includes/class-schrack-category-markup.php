<?php
/**
 * Category based markup rules.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Category_Markup {
	/**
	 * Settings service.
	 *
	 * @var Schrack_Settings
	 */
	private Schrack_Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns all category rules.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$rules = get_option( Schrack_Settings::MARKUPS_OPTION_NAME, array() );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Updates category rules.
	 *
	 * @param array<string,mixed> $input Unsanitized input.
	 */
	public function update( array $input ): void {
		$rules = array();

		foreach ( $input as $term_id => $rule ) {
			$term_id = absint( $term_id );

			if ( 0 === $term_id || ! is_array( $rule ) ) {
				continue;
			}

			$markup    = isset( $rule['markup'] ) ? $this->sanitize_float( $rule['markup'], 0, 500 ) : '';
			$min_margin = isset( $rule['min_margin'] ) ? $this->sanitize_float( $rule['min_margin'], 0, 1000000 ) : '';
			$rounding  = isset( $rule['rounding'] ) ? sanitize_key( (string) $rule['rounding'] ) : 'none';

			if ( ! in_array( $rounding, array( 'none', 'ending_99', 'integer_ron', 'five_ron' ), true ) ) {
				$rounding = 'none';
			}

			if ( '' === $markup && '' === $min_margin && 'none' === $rounding ) {
				continue;
			}

			$rules[ $term_id ] = array(
				'markup'     => '' === $markup ? '' : (float) $markup,
				'min_margin' => '' === $min_margin ? '' : (float) $min_margin,
				'rounding'   => $rounding,
			);
		}

		update_option( Schrack_Settings::MARKUPS_OPTION_NAME, $rules, false );
	}

	/**
	 * Calculates sale price from purchase price.
	 */
	public function calculate_sale_price( float $purchase_price, int $product_id = 0 ): float {
		$purchase_price = max( 0.0, $purchase_price );
		$rule       = $this->get_rule_for_product( $product_id );
		$markup     = '' !== $rule['markup'] ? (float) $rule['markup'] : (float) $this->settings->get( 'default_markup', 20 );
		$min_margin = '' !== $rule['min_margin'] ? (float) $rule['min_margin'] : 0.0;
		$rounding   = (string) $rule['rounding'];

		$price = $purchase_price * ( 1 + ( $markup / 100 ) );

		if ( $min_margin > 0 ) {
			$price = max( $price, $purchase_price + $min_margin );
		}

		return $this->apply_rounding( $price, $rounding );
	}

	/**
	 * Returns the effective rule for a product.
	 *
	 * @return array{markup:float|string,min_margin:float|string,rounding:string}
	 */
	public function get_rule_for_product( int $product_id ): array {
		$default = array(
			'markup'     => '',
			'min_margin' => '',
			'rounding'   => 'none',
		);

		if ( $product_id <= 0 ) {
			return $default;
		}

		$rules = $this->all();
		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return $default;
		}

		foreach ( $terms as $term ) {
			if ( isset( $rules[ $term->term_id ] ) ) {
				return wp_parse_args( $rules[ $term->term_id ], $default );
			}

			$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );

			foreach ( $ancestors as $ancestor_id ) {
				if ( isset( $rules[ $ancestor_id ] ) ) {
					return wp_parse_args( $rules[ $ancestor_id ], $default );
				}
			}
		}

		return $default;
	}

	/**
	 * Applies a rounding rule.
	 */
	public function apply_rounding( float $price, string $rule ): float {
		return match ( $rule ) {
			'ending_99'   => max( 0.99, ceil( $price ) - 0.01 ),
			'integer_ron' => ceil( $price ),
			'five_ron'    => ceil( $price / 5 ) * 5,
			default       => $price,
		};
	}

	/**
	 * Sanitizes a decimal field. Empty values stay empty.
	 *
	 * @return float|string
	 */
	private function sanitize_float( mixed $value, float $min, float $max ): float|string {
		$value = is_string( $value ) ? trim( str_replace( ',', '.', $value ) ) : $value;

		if ( '' === $value || null === $value ) {
			return '';
		}

		if ( ! is_numeric( $value ) ) {
			return '';
		}

		$float = (float) $value;

		return max( $min, min( $max, $float ) );
	}
}
