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
	 * Per-request cached markup rules.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $rules_cache = null;

	/**
	 * Per-request cached effective product rules.
	 *
	 * @var array<int,array{markup:float|string,min_margin:float|string,rounding:string}>
	 */
	private array $product_rule_cache = array();

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
		if ( null !== $this->rules_cache ) {
			return $this->rules_cache;
		}

		$rules = get_option( Schrack_Settings::MARKUPS_OPTION_NAME, array() );

		$this->rules_cache = is_array( $rules ) ? $rules : array();

		return $this->rules_cache;
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

			if ( ! $this->is_valid_rounding( $rounding ) ) {
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

		$this->rules_cache        = $rules;
		$this->product_rule_cache = array();
	}

	/**
	 * Applies a bulk rule payload to category markup input before saving.
	 *
	 * @param array<string,mixed> $input Unsanitized markup input.
	 * @param array<string,mixed> $bulk Unsanitized bulk input.
	 * @return array<string,mixed>
	 */
	public function merge_bulk_input( array $input, array $bulk ): array {
		$term_ids = isset( $bulk['category_ids'] ) && is_array( $bulk['category_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $bulk['category_ids'] ) ) ) )
			: array();

		$markup   = array_key_exists( 'markup', $bulk ) ? $this->sanitize_float( $bulk['markup'], 0, 500 ) : '';
		$rounding = array_key_exists( 'rounding', $bulk ) ? sanitize_key( (string) $bulk['rounding'] ) : '';
		$mode     = isset( $bulk['mode'] ) ? sanitize_key( (string) $bulk['mode'] ) : 'empty';

		if ( '' !== $rounding && ! $this->is_valid_rounding( $rounding ) ) {
			$rounding = '';
		}

		if ( 'overwrite' !== $mode ) {
			$mode = 'empty';
		}

		if ( empty( $term_ids ) || ( '' === $markup && '' === $rounding ) ) {
			return $input;
		}

		foreach ( $term_ids as $term_id ) {
			$rule = isset( $input[ $term_id ] ) && is_array( $input[ $term_id ] )
				? $input[ $term_id ]
				: array();

			if ( 'empty' === $mode && $this->raw_rule_has_values( $rule ) ) {
				continue;
			}

			if ( '' !== $markup ) {
				$rule['markup'] = $markup;
			}

			if ( '' !== $rounding ) {
				$rule['rounding'] = $rounding;
			}

			$input[ $term_id ] = $rule;
		}

		return $input;
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

		$price = $this->apply_vat( $price );

		return $this->apply_rounding( $price, $rounding );
	}

	/**
	 * Applies the configured TVA/VAT rate to a net public price.
	 */
	public function apply_vat( float $price ): float {
		$price = max( 0.0, $price );
		$rate  = $this->vat_rate();

		if ( $rate <= 0.0 ) {
			return $price;
		}

		return $price * ( 1 + ( $rate / 100 ) );
	}

	/**
	 * Returns the configured TVA/VAT rate.
	 */
	public function vat_rate(): float {
		$rate = (string) $this->settings->get( 'vat_rate', 19 );
		$rate = str_replace( ',', '.', $rate );

		return max( 0.0, min( 100.0, is_numeric( $rate ) ? (float) $rate : 19.0 ) );
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

		if ( isset( $this->product_rule_cache[ $product_id ] ) ) {
			return $this->product_rule_cache[ $product_id ];
		}

		$rules = $this->all();

		if ( empty( $rules ) ) {
			$this->product_rule_cache[ $product_id ] = $default;
			return $default;
		}

		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			$this->product_rule_cache[ $product_id ] = $default;
			return $default;
		}

		foreach ( $terms as $term ) {
			if ( isset( $rules[ $term->term_id ] ) ) {
				$this->product_rule_cache[ $product_id ] = wp_parse_args( $rules[ $term->term_id ], $default );
				return $this->product_rule_cache[ $product_id ];
			}

			$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );

			foreach ( $ancestors as $ancestor_id ) {
				if ( isset( $rules[ $ancestor_id ] ) ) {
					$this->product_rule_cache[ $product_id ] = wp_parse_args( $rules[ $ancestor_id ], $default );
					return $this->product_rule_cache[ $product_id ];
				}
			}
		}

		$this->product_rule_cache[ $product_id ] = $default;

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

	/**
	 * Returns whether a raw rule contains any configured value.
	 */
	private function raw_rule_has_values( array $rule ): bool {
		$markup     = isset( $rule['markup'] ) ? $this->sanitize_float( $rule['markup'], 0, 500 ) : '';
		$min_margin = isset( $rule['min_margin'] ) ? $this->sanitize_float( $rule['min_margin'], 0, 1000000 ) : '';
		$rounding   = isset( $rule['rounding'] ) ? sanitize_key( (string) $rule['rounding'] ) : 'none';

		if ( ! $this->is_valid_rounding( $rounding ) ) {
			$rounding = 'none';
		}

		return '' !== $markup || '' !== $min_margin || 'none' !== $rounding;
	}

	/**
	 * Checks supported rounding keys.
	 */
	private function is_valid_rounding( string $rounding ): bool {
		return in_array( $rounding, array( 'none', 'ending_99', 'integer_ron', 'five_ron' ), true );
	}
}
