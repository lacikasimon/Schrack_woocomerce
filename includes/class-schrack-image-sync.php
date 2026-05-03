<?php
/**
 * Product image synchronization.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Image_Sync {
	/**
	 * Settings.
	 *
	 * @var Schrack_Settings
	 */
	private Schrack_Settings $settings;

	/**
	 * Logger.
	 *
	 * @var Schrack_Logger
	 */
	private Schrack_Logger $logger;

	/**
	 * Product mapper.
	 *
	 * @var Schrack_Product_Mapper
	 */
	private Schrack_Product_Mapper $mapper;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, ?Schrack_Product_Mapper $mapper = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->mapper   = $mapper ?: new Schrack_Product_Mapper( $settings, $logger );
	}

	/**
	 * Syncs a batch of stored Schrack image URLs into the media library.
	 *
	 * @return array<string,mixed>
	 */
	public function sync_batch( int $limit ): array {
		$limit       = max( 1, $limit );
		$batch       = $this->get_image_product_batch( $limit, $this->batch_cursor() );
		$product_ids = $batch['product_ids'];

		if ( empty( $product_ids ) && $batch['batch_start'] > 0 ) {
			$batch       = $this->get_image_product_batch( $limit, 0 );
			$product_ids = $batch['product_ids'];
		}

		if ( empty( $product_ids ) && 0 === (int) $batch['total_products'] ) {
			$this->logger->info(
				'images',
				'No Schrack products with stored image URLs were found for image sync.',
				null,
				array(
					'meta_key'    => '_schrack_image_url',
					'batch_limit' => $limit,
				)
			);
		}

		$processed = 0;
		$errors    = 0;
		$imported  = 0;
		$stopped   = false;

		foreach ( $product_ids as $product_id ) {
			if ( $this->settings->is_stop_requested() ) {
				$stopped = true;
				break;
			}

			try {
				$attachment_id = $this->mapper->import_product_image( $product_id );
				++$processed;

				if ( $attachment_id > 0 ) {
					++$imported;
				}
			} catch ( Throwable $exception ) {
				++$errors;
				$this->logger->error(
					'images',
					'Failed to import Schrack product image.',
					null,
					array(
						'product_id' => $product_id,
						'error'      => $exception->getMessage(),
					)
				);
			}
		}

		$batch_count     = $stopped ? min( count( $product_ids ), $processed + $errors ) : count( $product_ids );
		$next_cursor     = $batch['batch_start'] + $batch_count;
		$completed_cycle = ! $stopped && ( 0 === $batch['total_products'] || $next_cursor >= $batch['total_products'] );

		if ( $completed_cycle ) {
			$next_cursor = 0;
		}

		$result = array(
			'processed'       => $processed,
			'imported'        => $imported,
			'errors'          => $errors,
			'cursor'          => $next_cursor,
			'total_products'  => $batch['total_products'],
			'batch_start'     => $batch['batch_start'],
			'batch_count'     => $batch_count,
			'batch_limit'     => $limit,
			'completed_cycle' => $completed_cycle ? 'yes' : 'no',
		);

		if ( $stopped ) {
			$result['stopped'] = 'yes';
		}

		$this->settings->update_status( 'images', $result );

		return $result;
	}

	/**
	 * Returns the stored cursor for image batch work.
	 */
	private function batch_cursor(): int {
		$status = $this->settings->get_status();
		$row    = isset( $status['images'] ) && is_array( $status['images'] ) ? $status['images'] : array();

		return absint( $row['cursor'] ?? 0 );
	}

	/**
	 * Returns a stable batch of products with a Schrack image URL.
	 *
	 * @return array{product_ids:array<int,int>,total_products:int,batch_start:int}
	 */
	private function get_image_product_batch( int $limit, int $offset ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => max( 1, $limit ),
				'offset'         => max( 0, $offset ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_schrack_image_url',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		return array(
			'product_ids'    => array_map( 'absint', $query->posts ),
			'total_products' => (int) $query->found_posts,
			'batch_start'    => max( 0, $offset ),
		);
	}
}
