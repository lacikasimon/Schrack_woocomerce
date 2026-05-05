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
	private const IMAGE_META_KEY        = '_schrack_image_url';
	private const IMPORTED_META_KEY     = '_schrack_imported_image_url';
	private const CLAIM_META_KEY        = '_schrack_image_sync_claim';
	private const CLAIMED_AT_META_KEY   = '_schrack_image_sync_claimed_at';
	private const LAST_ATTEMPT_META_KEY = '_schrack_last_image_attempt_ts';

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
		$limit          = max( 1, $limit );
		$total_products = $this->count_pending_image_products();
		$run_id         = $this->new_run_id();
		$product_ids    = $this->claim_pending_product_ids( $limit, $run_id );

		if ( empty( $product_ids ) && 0 === $total_products ) {
			$this->logger->info(
				'images',
				'No pending Schrack product images were found for image sync.',
				null,
				array(
					'meta_key'       => self::IMAGE_META_KEY,
					'batch_limit'    => $limit,
					'retry_cooldown' => $this->retry_cooldown(),
				)
			);
		}

		$result          = $this->sync_product_ids( $product_ids, $run_id );
		$batch_count     = count( $product_ids );
		$completed_cycle = 0 === $batch_count || $batch_count >= $total_products;

		if ( 'yes' === (string) ( $result['stopped'] ?? 'no' ) ) {
			$completed_cycle = false;
		}

		if ( 'yes' === (string) ( $result['time_limited'] ?? 'no' ) ) {
			$completed_cycle = false;
		}

		$result = array_merge(
			$result,
			array(
				'cursor'          => 0,
				'total_products'  => $total_products,
				'batch_start'     => 0,
				'batch_count'     => $batch_count,
				'batch_limit'     => $limit,
				'completed_cycle' => $completed_cycle ? 'yes' : 'no',
				'run_id'          => $run_id,
			)
		);

		$this->settings->update_status( 'images', $result );

		return $result;
	}

	/**
	 * Keeps importing image batches until no pending images remain or a caller limit is reached.
	 *
	 * This is mainly useful for WP-CLI, where waiting for Action Scheduler follow-up
	 * waves is unnecessary overhead.
	 *
	 * @return array<string,mixed>
	 */
	public function sync_until_idle( int $batch_size, int $max_batches = 0, int $time_limit = 0 ): array {
		$batch_size  = max( 1, min( 250, $batch_size ) );
		$max_batches = max( 0, $max_batches );
		$time_limit  = max( 0, $time_limit );
		$deadline    = $time_limit > 0 ? time() + $time_limit : 0;
		$batches     = 0;
		$last_result = array();
		$totals      = array(
			'processed' => 0,
			'imported'  => 0,
			'attached'  => 0,
			'skipped'   => 0,
			'reused'    => 0,
			'errors'    => 0,
		);

		while ( 0 === $max_batches || $batches < $max_batches ) {
			if ( $this->settings->is_stop_requested() ) {
				$last_result['stopped'] = 'yes';
				break;
			}

			if ( $deadline > 0 && time() >= $deadline ) {
				$last_result['time_limited'] = 'yes';
				break;
			}

			$last_result = $this->sync_batch( $batch_size );
			++$batches;

			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += (int) ( $last_result[ $key ] ?? 0 );
			}

			if (
				'yes' === (string) ( $last_result['completed_cycle'] ?? 'yes' )
				|| 0 === (int) ( $last_result['batch_count'] ?? 0 )
				|| 'yes' === (string) ( $last_result['stopped'] ?? 'no' )
			) {
				break;
			}
		}

		$result = array_merge(
			$last_result,
			$totals,
			array(
				'batches_processed' => $batches,
				'cli_drain'         => 'yes',
			)
		);

		$this->settings->update_status( 'images', $result );

		return $result;
	}

	/**
	 * Claims multiple pending image batches for Action Scheduler workers.
	 *
	 * @return array<string,mixed>
	 */
	public function claim_parallel_batches( int $batch_size, int $workers ): array {
		$batch_size     = max( 1, $batch_size );
		$workers        = max( 1, $workers );
		$total_products = $this->count_pending_image_products();
		$run_id         = $this->new_run_id();
		$product_ids    = $this->claim_pending_product_ids( $batch_size * $workers, $run_id );
		$chunks         = array_values(
			array_filter(
				array_chunk( $product_ids, $batch_size ),
				static fn ( array $chunk ): bool => ! empty( $chunk )
			)
		);

		if ( empty( $chunks ) && 0 === $total_products ) {
			$this->logger->info(
				'images',
				'No pending Schrack product images were found for parallel image sync.',
				null,
				array(
					'meta_key'       => self::IMAGE_META_KEY,
					'batch_size'     => $batch_size,
					'workers'        => $workers,
					'retry_cooldown' => $this->retry_cooldown(),
				)
			);
		}

		return array(
			'run_id'          => $run_id,
			'chunks'          => $chunks,
			'queued_products' => count( $product_ids ),
			'total_products'  => $total_products,
			'batch_limit'     => $batch_size,
			'completed_cycle' => count( $product_ids ) >= $total_products ? 'yes' : 'no',
		);
	}

	/**
	 * Imports images for explicit product IDs.
	 *
	 * @param array<int,mixed> $product_ids Product IDs.
	 * @return array<string,mixed>
	 */
	public function sync_product_ids( array $product_ids, string $run_id = '' ): array {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		$processed    = 0;
		$errors       = 0;
		$imported     = 0;
		$attached     = 0;
		$skipped      = 0;
		$reused       = 0;
		$stopped      = false;
		$time_limited = false;
		$started_at   = time();
		$prefetched_meta = $this->prefetch_product_image_meta( $product_ids );

		try {
			foreach ( $product_ids as $product_id ) {
				if ( $this->settings->is_stop_requested() ) {
					$stopped = true;
					break;
				}

				if ( $this->should_pause_processing( $started_at, $processed ) ) {
					$time_limited = true;
					break;
				}

				try {
					$image_result  = $this->mapper->import_product_image_with_result( $product_id, $prefetched_meta[ $product_id ] ?? array() );
					$status        = (string) ( $image_result['status'] ?? '' );
					$attachment_id = absint( $image_result['attachment_id'] ?? 0 );
					++$processed;

					if ( 'imported' === $status ) {
						++$imported;
					} elseif ( 'reused_existing' === $status ) {
						++$reused;
					} elseif ( in_array( $status, array( 'failed', 'missing_product' ), true ) ) {
						++$errors;
					} else {
						++$skipped;
					}

					if ( $attachment_id > 0 ) {
						++$attached;
					}
				} catch ( Throwable $exception ) {
					++$processed;
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
		} finally {
			$this->release_product_claims( $product_ids, $run_id );
		}

		$result = array(
			'processed'   => $processed,
			'imported'    => $imported,
			'attached'    => $attached,
			'skipped'     => $skipped,
			'reused'      => $reused,
			'errors'      => $errors,
			'batch_count' => count( $product_ids ),
		);

		if ( '' !== $run_id ) {
			$result['run_id'] = $run_id;
		}

		if ( $stopped ) {
			$result['stopped'] = 'yes';
		}

		if ( $time_limited ) {
			$result['time_limited'] = 'yes';
		}

		return $result;
	}

	/**
	 * Prefetches the product meta needed for image import decisions in one query.
	 *
	 * @param array<int,int> $product_ids Product IDs.
	 * @return array<int,array<string,string>>
	 */
	private function prefetch_product_image_meta( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );

		if ( empty( $product_ids ) ) {
			return array();
		}

		$meta_keys = array(
			self::IMAGE_META_KEY,
			self::IMPORTED_META_KEY,
			'_schrack_image_attachment_id',
			'_thumbnail_id',
			'_sku',
			'_schrack_item_number',
		);
		$result = array_fill_keys( $product_ids, array() );

		foreach ( array_chunk( $product_ids, 500 ) as $chunk ) {
			$id_placeholders   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$key_placeholders  = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
			$query_parameters = array_merge( $chunk, $meta_keys );
			$sql = "
				SELECT post_id, meta_key, meta_value
				FROM {$wpdb->postmeta}
				WHERE post_id IN ({$id_placeholders})
					AND meta_key IN ({$key_placeholders})
			";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_parameters ), ARRAY_A );

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$product_id = absint( $row['post_id'] ?? 0 );
				$meta_key   = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

				if ( $product_id <= 0 || '' === $meta_key || ! array_key_exists( $product_id, $result ) ) {
					continue;
				}

				$result[ $product_id ][ $meta_key ] = is_scalar( $row['meta_value'] ?? null ) ? (string) $row['meta_value'] : '';
			}
		}

		return $result;
	}

	/**
	 * Releases image sync claims for product IDs.
	 *
	 * @param array<int,mixed> $product_ids Product IDs.
	 */
	public function release_product_claims( array $product_ids, string $run_id = '' ): void {
		foreach ( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) as $product_id ) {
			if ( '' !== $run_id ) {
				$current_claim = (string) get_post_meta( $product_id, self::CLAIM_META_KEY, true );

				if ( $current_claim !== $run_id ) {
					continue;
				}
			}

			delete_post_meta( $product_id, self::CLAIM_META_KEY );
			delete_post_meta( $product_id, self::CLAIMED_AT_META_KEY );
		}
	}

	/**
	 * Claims pending products so parallel workers do not download the same image.
	 *
	 * @return array<int,int>
	 */
	private function claim_pending_product_ids( int $limit, string $run_id ): array {
		$claimed = array();

		foreach ( $this->pending_image_product_ids( $limit ) as $product_id ) {
			if ( $this->claim_product_id( $product_id, $run_id ) ) {
				$claimed[] = $product_id;
			}
		}

		return $claimed;
	}

	/**
	 * Claims one product if no fresh worker claim exists.
	 */
	private function claim_product_id( int $product_id, string $run_id ): bool {
		$claimed_at = absint( get_post_meta( $product_id, self::CLAIMED_AT_META_KEY, true ) );

		if ( $claimed_at > 0 && $claimed_at >= time() - $this->claim_ttl() ) {
			return false;
		}

		update_post_meta( $product_id, self::CLAIM_META_KEY, $run_id );
		update_post_meta( $product_id, self::CLAIMED_AT_META_KEY, time() );

		return true;
	}

	/**
	 * Returns product IDs that still need an image import attempt.
	 *
	 * @return array<int,int>
	 */
	private function pending_image_product_ids( int $limit ): array {
		global $wpdb;

		$claim_cutoff = time() - $this->claim_ttl();
		$retry_cutoff = time() - $this->retry_cooldown();
		$sql          = $wpdb->prepare(
			"SELECT DISTINCT posts.ID
			FROM {$wpdb->posts} AS posts
			INNER JOIN {$wpdb->postmeta} AS image_meta
				ON posts.ID = image_meta.post_id AND image_meta.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} AS imported_meta
				ON posts.ID = imported_meta.post_id AND imported_meta.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} AS thumbnail_meta
				ON posts.ID = thumbnail_meta.post_id AND thumbnail_meta.meta_key = '_thumbnail_id'
			LEFT JOIN {$wpdb->postmeta} AS claim_meta
				ON posts.ID = claim_meta.post_id AND claim_meta.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} AS attempt_meta
				ON posts.ID = attempt_meta.post_id AND attempt_meta.meta_key = %s
			WHERE posts.post_type = 'product'
				AND posts.post_status IN ('publish', 'draft', 'private')
				AND image_meta.meta_value <> ''
				AND (
					imported_meta.meta_id IS NULL
					OR imported_meta.meta_value <> image_meta.meta_value
					OR thumbnail_meta.meta_id IS NULL
					OR thumbnail_meta.meta_value = ''
					OR thumbnail_meta.meta_value = '0'
				)
				AND (
					claim_meta.meta_id IS NULL
					OR CAST(claim_meta.meta_value AS UNSIGNED) < %d
				)
				AND (
					attempt_meta.meta_id IS NULL
					OR CAST(attempt_meta.meta_value AS UNSIGNED) < %d
				)
			ORDER BY COALESCE(CAST(attempt_meta.meta_value AS UNSIGNED), 0) ASC, posts.ID ASC
			LIMIT %d",
			self::IMAGE_META_KEY,
			self::IMPORTED_META_KEY,
			self::CLAIMED_AT_META_KEY,
			self::LAST_ATTEMPT_META_KEY,
			$claim_cutoff,
			$retry_cutoff,
			max( 1, $limit )
		);

		return array_map( 'absint', $wpdb->get_col( $sql ) );
	}

	/**
	 * Counts products that currently need an image import attempt.
	 */
	private function count_pending_image_products(): int {
		global $wpdb;

		$claim_cutoff = time() - $this->claim_ttl();
		$retry_cutoff = time() - $this->retry_cooldown();
		$sql          = $wpdb->prepare(
			"SELECT COUNT(DISTINCT posts.ID)
			FROM {$wpdb->posts} AS posts
			INNER JOIN {$wpdb->postmeta} AS image_meta
				ON posts.ID = image_meta.post_id AND image_meta.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} AS imported_meta
				ON posts.ID = imported_meta.post_id AND imported_meta.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} AS thumbnail_meta
				ON posts.ID = thumbnail_meta.post_id AND thumbnail_meta.meta_key = '_thumbnail_id'
			LEFT JOIN {$wpdb->postmeta} AS claim_meta
				ON posts.ID = claim_meta.post_id AND claim_meta.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} AS attempt_meta
				ON posts.ID = attempt_meta.post_id AND attempt_meta.meta_key = %s
			WHERE posts.post_type = 'product'
				AND posts.post_status IN ('publish', 'draft', 'private')
				AND image_meta.meta_value <> ''
				AND (
					imported_meta.meta_id IS NULL
					OR imported_meta.meta_value <> image_meta.meta_value
					OR thumbnail_meta.meta_id IS NULL
					OR thumbnail_meta.meta_value = ''
					OR thumbnail_meta.meta_value = '0'
				)
				AND (
					claim_meta.meta_id IS NULL
					OR CAST(claim_meta.meta_value AS UNSIGNED) < %d
				)
				AND (
					attempt_meta.meta_id IS NULL
					OR CAST(attempt_meta.meta_value AS UNSIGNED) < %d
				)",
			self::IMAGE_META_KEY,
			self::IMPORTED_META_KEY,
			self::CLAIMED_AT_META_KEY,
			self::LAST_ATTEMPT_META_KEY,
			$claim_cutoff,
			$retry_cutoff
		);

		return absint( $wpdb->get_var( $sql ) );
	}

	/**
	 * Returns how long an abandoned worker claim should block duplicate work.
	 */
	private function claim_ttl(): int {
		return 30 * MINUTE_IN_SECONDS;
	}

	/**
	 * Returns how long failed image downloads should wait before retrying.
	 */
	private function retry_cooldown(): int {
		return max( MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, (int) $this->settings->get( 'image_retry_cooldown', HOUR_IN_SECONDS ) ) );
	}

	/**
	 * Stops a worker before PHP reaches the hosting execution or memory limit.
	 */
	private function should_pause_processing( int $started_at, int $processed ): bool {
		if ( $processed <= 0 ) {
			return false;
		}

		if ( $this->is_memory_pressure_high() ) {
			return true;
		}

		$max_execution_time = (int) ini_get( 'max_execution_time' );

		if ( $max_execution_time <= 0 ) {
			return false;
		}

		return time() - $started_at >= max( 10, $max_execution_time - 8 );
	}

	/**
	 * Detects whether this worker is too close to PHP memory_limit.
	 */
	private function is_memory_pressure_high(): bool {
		$limit = $this->memory_limit_bytes();

		if ( $limit <= 0 ) {
			return false;
		}

		return memory_get_usage( true ) >= (int) floor( $limit * 0.75 );
	}

	/**
	 * Parses PHP shorthand memory_limit values.
	 */
	private function memory_limit_bytes(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || str_starts_with( $raw, '-' ) ) {
			return 0;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			$bytes = (int) wp_convert_hr_to_bytes( $raw );

			if ( $bytes > 0 ) {
				return $bytes;
			}
		}

		if ( is_numeric( $raw ) ) {
			return max( 0, (int) $raw );
		}

		$unit   = strtolower( substr( $raw, -1 ) );
		$number = (float) substr( $raw, 0, -1 );

		if ( $number <= 0 ) {
			return 0;
		}

		return (int) match ( $unit ) {
			'g'     => $number * 1024 * 1024 * 1024,
			'm'     => $number * 1024 * 1024,
			'k'     => $number * 1024,
			default => (float) $raw,
		};
	}

	/**
	 * Creates a short correlation ID for one image sync dispatch.
	 */
	private function new_run_id(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'schrack-images-', true );
	}
}
