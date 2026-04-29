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
		add_action( self::HOOK_FULL, array( $this, 'run_full_sync' ) );
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
			'full'    => self::HOOK_FULL,
			default   => '',
		};

		if ( '' === $hook ) {
			return false;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, array(), self::GROUP );
			$this->logger->info( $task, 'Queued manual Schrack sync task in Action Scheduler.' );
			return true;
		}

		wp_schedule_single_event( time() + 5, $hook );
		$this->logger->info( $task, 'Queued manual Schrack sync task in WP-Cron fallback.' );

		return true;
	}

	/**
	 * Runs a catalog import batch.
	 */
	public function run_catalog_import(): void {
		$importer = new Schrack_Catalog_Importer( $this->settings, $this->logger );
		$limit    = (int) $this->settings->get( 'sync_batch_size', 25 );

		try {
			$result = $importer->import_from_soap( 'CSV', $limit );
			$this->logger->info( 'catalog', 'Finished Schrack catalog import batch.', null, $result );
		} catch ( Throwable $exception ) {
			$this->logger->error( 'catalog', 'Schrack catalog import batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'catalog', array( 'processed' => 0, 'errors' => 1 ) );
		}
	}

	/**
	 * Runs a price sync batch.
	 */
	public function run_price_sync(): void {
		$sync  = new Schrack_Price_Sync( $this->settings, $this->logger );
		$limit = (int) $this->settings->get( 'sync_batch_size', 25 );

		try {
			$result = $sync->sync_batch( $limit );
			$this->logger->info( 'price', 'Finished Schrack price sync batch.', null, $result );
		} catch ( Throwable $exception ) {
			$this->logger->error( 'price', 'Schrack price sync batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'price', array( 'processed' => 0, 'errors' => 1 ) );
		}
	}

	/**
	 * Runs a stock sync batch.
	 */
	public function run_stock_sync(): void {
		$sync  = new Schrack_Stock_Sync( $this->settings, $this->logger );
		$limit = (int) $this->settings->get( 'sync_batch_size', 25 );

		try {
			$result = $sync->sync_batch( $limit );
			$this->logger->info( 'stock', 'Finished Schrack stock sync batch.', null, $result );
		} catch ( Throwable $exception ) {
			$this->logger->error( 'stock', 'Schrack stock sync batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'stock', array( 'processed' => 0, 'errors' => 1 ) );
		}
	}

	/**
	 * Runs catalog, price, and stock tasks.
	 */
	public function run_full_sync(): void {
		$mode = (string) $this->settings->get( 'import_mode', 'catalog_price_stock' );

		$this->run_catalog_import();

		if ( in_array( $mode, array( 'catalog_price', 'catalog_price_stock' ), true ) ) {
			$this->run_price_sync();
		}

		if ( 'catalog_price_stock' === $mode ) {
			$this->run_stock_sync();
		}
	}

	/**
	 * Clears scheduled actions.
	 */
	public static function clear_scheduled_actions(): void {
		foreach ( array( self::HOOK_CATALOG, self::HOOK_PRICES, self::HOOK_STOCK, self::HOOK_FULL ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
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
}
