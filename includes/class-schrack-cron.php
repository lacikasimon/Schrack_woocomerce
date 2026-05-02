<?php
/**
 * Cron and Action Scheduler integration.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_Cron {
	public const GROUP        = 'schrack-wc-sync';
	public const HOOK_CATALOG = 'schrack_wc_sync_catalog';
	public const HOOK_PRICES  = 'schrack_wc_sync_prices';
	public const HOOK_STOCK   = 'schrack_wc_sync_stock';
	public const HOOK_FULL    = 'schrack_wc_sync_full';
	public const HOOK_IMAGES  = 'schrack_wc_sync_images';

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
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Registers hooks.
	 */
	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_wp_cron_schedules' ) );
		add_action( 'init', array( $this, 'maybe_schedule_recurring_actions' ) );
		add_action( self::HOOK_CATALOG, array( $this, 'run_catalog_import' ) );
		add_action( self::HOOK_PRICES, array( $this, 'run_price_sync' ) );
		add_action( self::HOOK_STOCK, array( $this, 'run_stock_sync' ) );
		add_action( self::HOOK_IMAGES, array( $this, 'run_image_sync' ) );
		add_action( self::HOOK_FULL, array( $this, 'run_full_sync' ), 10, 1 );
	}

	/**
	 * Adds WP-Cron fallback schedules.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_wp_cron_schedules( array $schedules ): array {
		$schedules['schrack_thirty_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes', 'schrack-woocommerce-sync' ),
		);

		$schedules['schrack_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'schrack-woocommerce-sync' ),
		);

		$schedules['schrack_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly', 'schrack-woocommerce-sync' ),
		);

		return $schedules;
	}

	/**
	 * Schedules recurring syncs.
	 */
	public function maybe_schedule_recurring_actions(): void {
		$this->schedule_recurring_action( self::HOOK_CATALOG, (string) $this->settings->get( 'catalog_sync_frequency', 'daily' ) );
		$this->schedule_recurring_action( self::HOOK_PRICES, (string) $this->settings->get( 'price_sync_frequency', 'daily' ) );
		$this->schedule_recurring_action( self::HOOK_STOCK, (string) $this->settings->get( 'stock_sync_frequency', 'hourly' ) );
	}

	/**
	 * Reschedules all recurring actions.
	 */
	public function reschedule(): void {
		self::clear_scheduled_actions();
		$this->maybe_schedule_recurring_actions();
	}

	/**
	 * Queues an immediate manual task.
	 */
	public function queue_action( string $task ): bool {
		$hook = match ( $task ) {
			'catalog' => self::HOOK_CATALOG,
			'prices'  => self::HOOK_PRICES,
			'stock'   => self::HOOK_STOCK,
			'images'  => self::HOOK_IMAGES,
			'full'    => self::HOOK_FULL,
			default   => '',
		};

		if ( '' === $hook ) {
			return false;
		}

		$args = 'full' === $task ? array( 'catalog' ) : array();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
			$this->logger->info( $task, 'Queued manual Schrack sync task in Action Scheduler.' );
			return true;
		}

		wp_schedule_single_event( time() + 5, $hook, $args );
		$this->logger->info( $task, 'Queued manual Schrack sync task in WP-Cron fallback.' );

		return true;
	}

	/**
	 * Runs a catalog import batch.
	 */
	public function run_catalog_import( bool $queue_continuation = true ): array {
		$importer = new Schrack_Catalog_Importer( $this->settings, $this->logger );
		$limit    = (int) $this->settings->get( 'sync_batch_size', 25 );

		try {
			$result = $importer->import_from_soap( 'CSV', $limit );
			$this->logger->info( 'catalog', 'Finished Schrack catalog import batch.', null, $result );
			if ( $queue_continuation ) {
				if ( $this->should_continue_batch( $result ) ) {
					$this->queue_next_batch_if_needed( self::HOOK_CATALOG, 'catalog', $result );
				} elseif ( $this->should_import_images() ) {
					$this->queue_sync_batch( self::HOOK_IMAGES, 'images', array(), array( 'source' => 'catalog_completed' ) );
				}
			}
			return $result;
		} catch ( Throwable $exception ) {
			$this->logger->error( 'catalog', 'Schrack catalog import batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'catalog', array( 'processed' => 0, 'errors' => 1 ) );
			return array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
			);
		}
	}

	/**
	 * Runs a price sync batch.
	 */
	public function run_price_sync( bool $queue_continuation = true ): array {
		$sync  = new Schrack_Price_Sync( $this->settings, $this->logger );
		$limit = (int) $this->settings->get( 'sync_batch_size', 25 );

		try {
			$result = $sync->sync_batch( $limit );
			$this->logger->info( 'price', 'Finished Schrack price sync batch.', null, $result );
			if ( $queue_continuation ) {
				$this->queue_next_batch_if_needed( self::HOOK_PRICES, 'price', $result );
			}
			return $result;
		} catch ( Throwable $exception ) {
			$this->logger->error( 'price', 'Schrack price sync batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'price', array( 'processed' => 0, 'errors' => 1 ) );
			return array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
			);
		}
	}

	/**
	 * Runs a stock sync batch.
	 */
	public function run_stock_sync( bool $queue_continuation = true ): array {
		$sync  = new Schrack_Stock_Sync( $this->settings, $this->logger );
		$limit = (int) $this->settings->get( 'sync_batch_size', 25 );

		try {
			$result = $sync->sync_batch( $limit );
			$this->logger->info( 'stock', 'Finished Schrack stock sync batch.', null, $result );
			if ( $queue_continuation ) {
				$this->queue_next_batch_if_needed( self::HOOK_STOCK, 'stock', $result );
			}
			return $result;
		} catch ( Throwable $exception ) {
			$this->logger->error( 'stock', 'Schrack stock sync batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'stock', array( 'processed' => 0, 'errors' => 1 ) );
			return array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
			);
		}
	}

	/**
	 * Runs an image import batch.
	 */
	public function run_image_sync( bool $queue_continuation = true ): array {
		$sync  = new Schrack_Image_Sync( $this->settings, $this->logger );
		$limit = (int) $this->settings->get( 'sync_batch_size', 25 );

		try {
			$result = $sync->sync_batch( $limit );
			$this->logger->info( 'images', 'Finished Schrack image sync batch.', null, $result );
			if ( $queue_continuation ) {
				$this->queue_next_batch_if_needed( self::HOOK_IMAGES, 'images', $result );
			}
			return $result;
		} catch ( Throwable $exception ) {
			$this->logger->error( 'images', 'Schrack image sync batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'images', array( 'processed' => 0, 'errors' => 1 ) );
			return array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
			);
		}
	}

	/**
	 * Runs catalog, price, and stock tasks.
	 */
	public function run_full_sync( string $stage = 'catalog' ): void {
		$mode = (string) $this->settings->get( 'import_mode', 'catalog_price_stock' );

		if ( 'catalog' === $stage ) {
			$result = $this->run_catalog_import( false );

			if ( $this->should_continue_batch( $result ) ) {
				$this->queue_next_batch_if_needed( self::HOOK_FULL, 'full', $result, array( 'catalog' ) );
				return;
			}

			if ( in_array( $mode, array( 'catalog_price', 'catalog_price_stock' ), true ) ) {
				$this->queue_sync_batch( self::HOOK_FULL, 'full', array( 'price' ), array( 'next_stage' => 'price' ) );
			} elseif ( $this->should_import_images() ) {
				$this->queue_sync_batch( self::HOOK_FULL, 'full', array( 'images' ), array( 'next_stage' => 'images' ) );
			}

			return;
		}

		if ( 'price' === $stage && in_array( $mode, array( 'catalog_price', 'catalog_price_stock' ), true ) ) {
			$result = $this->run_price_sync( false );

			if ( $this->should_continue_batch( $result ) ) {
				$this->queue_next_batch_if_needed( self::HOOK_FULL, 'full', $result, array( 'price' ) );
				return;
			}

			if ( 'catalog_price_stock' === $mode ) {
				$this->queue_sync_batch( self::HOOK_FULL, 'full', array( 'stock' ), array( 'next_stage' => 'stock' ) );
			} elseif ( $this->should_import_images() ) {
				$this->queue_sync_batch( self::HOOK_FULL, 'full', array( 'images' ), array( 'next_stage' => 'images' ) );
			}

			return;
		}

		if ( 'stock' === $stage && 'catalog_price_stock' === $mode ) {
			$result = $this->run_stock_sync( false );
			if ( $this->should_continue_batch( $result ) ) {
				$this->queue_next_batch_if_needed( self::HOOK_FULL, 'full', $result, array( 'stock' ) );
			} elseif ( $this->should_import_images() ) {
				$this->queue_sync_batch( self::HOOK_FULL, 'full', array( 'images' ), array( 'next_stage' => 'images' ) );
			}

			return;
		}

		if ( 'images' === $stage && $this->should_import_images() ) {
			$result = $this->run_image_sync( false );
			$this->queue_next_batch_if_needed( self::HOOK_FULL, 'full', $result, array( 'images' ) );
		}
	}

	/**
	 * Clears scheduled actions.
	 */
	public static function clear_scheduled_actions(): void {
		foreach ( array( self::HOOK_CATALOG, self::HOOK_PRICES, self::HOOK_STOCK, self::HOOK_FULL, self::HOOK_IMAGES ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, null, self::GROUP );
			}

			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Schedules one recurring hook with Action Scheduler or WP-Cron fallback.
	 */
	private function schedule_recurring_action( string $hook, string $frequency ): void {
		$interval = $this->settings->frequency_to_seconds( $frequency );

		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + $interval, $interval, $hook, array(), self::GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + $interval, $this->wp_cron_frequency_key( $frequency ), $hook );
		}
	}

	/**
	 * Maps setting keys to WP-Cron schedule keys.
	 */
	private function wp_cron_frequency_key( string $frequency ): string {
		return match ( $frequency ) {
			'thirty_minutes' => 'schrack_thirty_minutes',
			'six_hours'      => 'schrack_six_hours',
			'weekly'         => 'schrack_weekly',
			'hourly'         => 'hourly',
			default          => 'daily',
		};
	}

	/**
	 * Queues the next batch when the current cursor has not completed a cycle.
	 *
	 * @param array<string,mixed> $result Last batch result.
	 * @param array<int,mixed>    $args Hook arguments.
	 */
	private function queue_next_batch_if_needed( string $hook, string $operation, array $result, array $args = array() ): void {
		if ( ! $this->should_continue_batch( $result ) ) {
			return;
		}

		$this->queue_sync_batch(
			$hook,
			$operation,
			$args,
			array(
				'cursor'      => $result['cursor'] ?? null,
				'batch_count' => $result['batch_count'] ?? null,
			)
		);
	}

	/**
	 * Queues one follow-up sync action.
	 *
	 * @param array<int,mixed>    $args Hook arguments.
	 * @param array<string,mixed> $context Extra log context.
	 */
	private function queue_sync_batch( string $hook, string $operation, array $args = array(), array $context = array() ): void {
		$sleep = max( 0, (int) $this->settings->get( 'rate_limit_sleep', 0 ) );

		if ( 0 === $sleep && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 1, $sleep ), $hook, $args, self::GROUP );
		} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time() + max( 5, $sleep ), $hook, $args );
		}

		$this->logger->info(
			$operation,
			'Queued next Schrack sync batch.',
			null,
			array_merge(
				array(
					'hook'      => $hook,
					'next_args' => $args,
					'sleep'     => $sleep,
				),
				$context
			)
		);
	}

	/**
	 * Determines whether a batch result has more work behind its cursor.
	 *
	 * @param array<string,mixed> $result Last batch result.
	 */
	private function should_continue_batch( array $result ): bool {
		return 'no' === (string) ( $result['completed_cycle'] ?? 'yes' ) && (int) ( $result['batch_count'] ?? 0 ) > 0;
	}

	/**
	 * Returns whether media-library image import should run after catalog data.
	 */
	private function should_import_images(): bool {
		return 'yes' === $this->settings->get( 'image_import_enabled', 'yes' );
	}
}
