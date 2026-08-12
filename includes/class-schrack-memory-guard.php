<?php
/**
 * Shared memory limits for background work on cPanel/shared hosting.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schrack_Memory_Guard {
	private const SHARED_HOST_LIMIT = 2 * 1024 * 1024 * 1024;

	/**
	 * Parses PHP's effective memory_limit value.
	 *
	 * A zero return value means that PHP reports an unlimited or unknown limit.
	 */
	public static function limit_bytes(): int {
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
			't'     => $number * 1024 * 1024 * 1024 * 1024,
			'g'     => $number * 1024 * 1024 * 1024,
			'm'     => $number * 1024 * 1024,
			'k'     => $number * 1024,
			default => (float) $raw,
		};
	}

	/**
	 * Treats unlimited/unknown PHP limits conservatively on shared hosting.
	 */
	public static function is_shared_host_limit(): bool {
		$limit = self::limit_bytes();

		return 0 === $limit || $limit <= self::SHARED_HOST_LIMIT;
	}

	/**
	 * Safe export size. Products are released individually, so this can stay fast.
	 */
	public static function export_batch_size(): int {
		$limit = self::limit_bytes();

		$size = match ( true ) {
			0 === $limit                                => 300,
			$limit <= 128 * MB_IN_BYTES                 => 100,
			$limit <= 256 * MB_IN_BYTES                 => 200,
			$limit <= 512 * MB_IN_BYTES                 => 350,
			$limit <= 1024 * MB_IN_BYTES                => 600,
			$limit <= self::SHARED_HOST_LIMIT           => 800,
			default                                     => 1000,
		};

		return max( 1, min( 1000, (int) apply_filters( 'schrack_wc_product_export_batch_size', $size, $limit ) ) );
	}

	/**
	 * Number of product caches primed at once inside a time-bounded export action.
	 */
	public static function export_cache_chunk_size(): int {
		$limit = self::limit_bytes();

		$size = match ( true ) {
			0 === $limit                                => 10,
			$limit <= 128 * MB_IN_BYTES                 => 5,
			$limit <= 256 * MB_IN_BYTES                 => 10,
			$limit <= 512 * MB_IN_BYTES                 => 15,
			$limit <= 1024 * MB_IN_BYTES                => 25,
			$limit <= self::SHARED_HOST_LIMIT           => 30,
			default                                     => 40,
		};

		return max( 1, min( 50, (int) apply_filters( 'schrack_wc_product_export_cache_chunk_size', $size, $limit ) ) );
	}

	/**
	 * Safe import size. WooCommerce parses the complete batch before importing it.
	 */
	public static function import_batch_size(): int {
		$limit = self::limit_bytes();

		$size = match ( true ) {
			0 === $limit                                => 5,
			$limit <= 128 * MB_IN_BYTES                 => 1,
			$limit <= 256 * MB_IN_BYTES                 => 2,
			$limit <= 512 * MB_IN_BYTES                 => 5,
			$limit <= 1024 * MB_IN_BYTES                => 10,
			$limit <= self::SHARED_HOST_LIMIT           => 15,
			default                                     => 20,
		};

		return max( 1, min( 20, (int) apply_filters( 'schrack_wc_product_import_batch_size', $size, $limit ) ) );
	}

	/**
	 * Limits the final importer cleanup query and delete loop.
	 */
	public static function cleanup_batch_size(): int {
		$limit = self::limit_bytes();

		return match ( true ) {
			$limit > 0 && $limit <= 128 * MB_IN_BYTES => 20,
			$limit > 0 && $limit <= 256 * MB_IN_BYTES => 40,
			$limit > 0 && $limit <= 512 * MB_IN_BYTES => 75,
			default                                    => 100,
		};
	}

	/**
	 * Caps simultaneous Action Scheduler PHP processes on small accounts.
	 */
	public static function parallel_worker_limit(): int {
		return self::is_shared_host_limit() ? 1 : 8;
	}

	/**
	 * Stops a batch before PHP reaches a fatal out-of-memory condition.
	 */
	public static function is_pressure_high(): bool {
		$limit = self::limit_bytes();

		if ( $limit <= 0 ) {
			return false;
		}

		return memory_get_usage( true ) >= (int) floor( $limit * 0.70 );
	}

	/**
	 * Returns values suitable for status rows and logs.
	 *
	 * @return array<string,mixed>
	 */
	public static function diagnostics(): array {
		$limit = self::limit_bytes();
		$usage = memory_get_usage( true );
		$peak  = memory_get_peak_usage( true );
		$row   = array(
			'memory_usage_mb'  => round( $usage / MB_IN_BYTES, 2 ),
			'memory_peak_mb'   => round( $peak / MB_IN_BYTES, 2 ),
			'memory_safe_mode' => self::is_shared_host_limit() ? 'yes' : 'no',
		);

		if ( $limit > 0 ) {
			$row['memory_limit_mb'] = round( $limit / MB_IN_BYTES, 2 );
			$row['memory_usage_pct'] = round( ( $usage / $limit ) * 100, 2 );
			$row['memory_peak_pct']  = round( ( $peak / $limit ) * 100, 2 );
		}

		return $row;
	}

	/**
	 * Drops all per-product references retained by WordPress and newer WooCommerce.
	 */
	public static function forget_product( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		// Runtime cache flushing below is local-only. Per-key deletion is not:
		// Redis/Memcached drop-ins would delete the storefront's persistent cache.
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return;
		}

		wp_cache_delete( $product_id, 'posts' );
		wp_cache_delete( $product_id, 'post_meta' );

		$product_cache_class = 'Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';

		if ( class_exists( $product_cache_class ) && function_exists( 'wc_get_container' ) ) {
			try {
				$product_cache = wc_get_container()->get( $product_cache_class );

				if ( is_object( $product_cache ) && method_exists( $product_cache, 'remove' ) ) {
					$product_cache->remove( $product_id );
				}
			} catch ( Throwable ) {
				// Older/custom WooCommerce containers may not expose this optional cache.
			}
		}
	}

	/**
	 * Releases request-local caches without flushing a persistent object cache.
	 *
	 * @param array<int,int> $product_ids Product IDs touched by the completed batch.
	 */
	public static function release_runtime_memory( array $product_ids = array() ): void {
		foreach ( $product_ids as $product_id ) {
			self::forget_product( absint( $product_id ) );
		}

		if (
			function_exists( 'wp_cache_supports' ) &&
			wp_cache_supports( 'flush_runtime' ) &&
			function_exists( 'wp_cache_flush_runtime' )
		) {
			wp_cache_flush_runtime();
		}

		if (
			defined( 'SAVEQUERIES' ) &&
			SAVEQUERIES &&
			isset( $GLOBALS['wpdb']->queries ) &&
			is_array( $GLOBALS['wpdb']->queries )
		) {
			$GLOBALS['wpdb']->queries = array();
		}

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}
}
