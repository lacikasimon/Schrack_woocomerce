<?php
/**
 * WooCommerce CSV importer that tags only the placeholders created by one job.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends Woo's importer without changing its product parsing behavior.
 */
class Schrack_Scoped_Product_CSV_Importer extends WC_Product_CSV_Importer {
	public const JOB_META_KEY = '_schrack_product_import_job';

	private string $schrack_import_id = '';

	/**
	 * @param string              $file   CSV path.
	 * @param array<string,mixed> $params Importer arguments.
	 */
	public function __construct( $file, $params = array() ) {
		$this->schrack_import_id = sanitize_key( (string) ( $params['schrack_import_id'] ?? '' ) );
		unset( $params['schrack_import_id'] );

		parent::__construct( $file, $params );
	}

	/**
	 * Tags a newly created ID placeholder as belonging to this import.
	 *
	 * @param mixed $value Original CSV product ID.
	 * @return int
	 */
	public function parse_id_field( $value ) {
		global $wpdb;

		$original_id = absint( $value );
		$known_id    = 0;

		if ( $original_id > 0 ) {
			$known_id = absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_original_id' AND meta_value = %s LIMIT 1",
						$original_id
					)
				)
			);

			if ( 0 === $known_id ) {
				$mapped_keys = $this->get_mapped_keys();
				$sku_index   = array_search( 'sku', $mapped_keys, true );
				$row_sku     = false !== $sku_index && isset( $this->raw_data[ $this->parsing_raw_data_index ][ $sku_index ] )
					? (string) $this->raw_data[ $this->parsing_raw_data_index ][ $sku_index ]
					: '';

				if ( '' !== $row_sku ) {
					$known_id = absint( wc_get_product_id_by_sku( $row_sku ) );
				}
			}
		}

		$id = parent::parse_id_field( $value );

		if ( 0 === $known_id ) {
			$this->tag_new_placeholder( absint( $id ) );
		}

		return $id;
	}

	/**
	 * Tags a newly created relationship/SKU placeholder as belonging to this import.
	 *
	 * @param mixed $value Relationship value.
	 * @return int|string
	 */
	public function parse_relative_field( $value ) {
		global $wpdb;

		$value    = (string) $value;
		$known_id = 0;

		if ( preg_match( '/^id:(\d+)$/', $value, $matches ) ) {
			$source_id = absint( $matches[1] );
			$known_id  = absint(
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_original_id' AND meta_value = %s LIMIT 1",
						$source_id
					)
				)
			);

			if ( 0 === $known_id ) {
				$known_id = absint(
					$wpdb->get_var(
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'product', 'product_variation' ) AND ID = %d LIMIT 1",
							$source_id
						)
					)
				);
			}
		} elseif ( '' !== $value ) {
			$known_id = absint( wc_get_product_id_by_sku( $value ) );
		}

		$id = parent::parse_relative_field( $value );

		if ( 0 === $known_id ) {
			$this->tag_new_placeholder( absint( $id ) );
		}

		return $id;
	}

	/**
	 * Marks only a temporary product created during the current parser call.
	 */
	private function tag_new_placeholder( int $product_id ): void {
		if (
			$product_id <= 0 ||
			'' === $this->schrack_import_id ||
			'importing' !== get_post_status( $product_id )
		) {
			return;
		}

		update_post_meta( $product_id, self::JOB_META_KEY, $this->schrack_import_id );
	}
}
