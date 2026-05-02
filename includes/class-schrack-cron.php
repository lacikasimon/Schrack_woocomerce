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
	 *
	 * @return array{queued:bool,code:string,message:string,task:string}
	 */
	public function queue_action( string $task ): array {
		$definitions = $this->task_definitions();
		$hook        = (string) ( $definitions[ $task ]['hook'] ?? '' );

		if ( '' === $hook ) {
			return array(
				'queued'  => false,
				'code'    => 'unknown_task',
				'message' => __( 'Unknown sync task.', 'schrack-woocommerce-sync' ),
				'task'    => $task,
			);
		}

		$conflict = $this->active_sync_conflict();

		if ( null !== $conflict ) {
			$message = sprintf(
				/* translators: %s: operation name. */
				__( 'A Schrack sync task is already running or queued: %s.', 'schrack-woocommerce-sync' ),
				(string) $conflict['label']
			);

			$this->logger->warning(
				$task,
				'Skipped manual Schrack sync queue request because another sync is active.',
				null,
				array(
					'requested_task' => $task,
					'active_task'    => $conflict['task'],
					'active_hook'    => $conflict['hook'],
					'pending'        => $conflict['pending'],
					'running'        => $conflict['running'],
				)
			);

			return array(
				'queued'  => false,
				'code'    => 'active_sync',
				'message' => $message,
				'task'    => $task,
			);
		}

		$args = 'full' === $task ? array( 'catalog' ) : array();
		$this->settings->clear_stop_request();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );
			$this->logger->info( $task, 'Queued manual Schrack sync task in Action Scheduler.' );
			return array(
				'queued'  => true,
				'code'    => 'queued',
				'message' => __( 'Sync task queued.', 'schrack-woocommerce-sync' ),
				'task'    => $task,
			);
		}

		wp_schedule_single_event( time() + 5, $hook, $args );
		$this->logger->info( $task, 'Queued manual Schrack sync task in WP-Cron fallback.' );

		return array(
			'queued'  => true,
			'code'    => 'queued',
			'message' => __( 'Sync task queued.', 'schrack-woocommerce-sync' ),
			'task'    => $task,
		);
	}

	/**
	 * Returns the current Action Scheduler/WP-Cron queue state for Schrack tasks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function queue_status(): array {
		$rows = array();
		$now  = time();

		foreach ( $this->task_definitions() as $task => $definition ) {
			$hook     = (string) $definition['hook'];
			$pending  = $this->scheduled_action_count( $hook, 'pending' );
			$running  = $this->scheduled_action_count( $hook, 'in-progress' );
			$next_run = $this->next_scheduled_timestamp( $hook );
			$state    = 'idle';

			if ( $running > 0 ) {
				$state = 'running';
			} elseif ( $pending > 0 && null !== $next_run && $next_run <= $now ) {
				$state = 'due';
			} elseif ( $pending > 0 && null !== $next_run && $next_run <= $now + 5 * MINUTE_IN_SECONDS ) {
				$state = 'queued';
			} elseif ( $pending > 0 ) {
				$state = 'scheduled';
			}

			$rows[] = array(
				'task'      => $task,
				'label'     => $definition['label'],
				'hook'      => $hook,
				'pending'   => $pending,
				'running'   => $running,
				'next_run'  => $next_run,
				'state'     => $state,
				'is_active' => in_array( $state, array( 'running', 'due', 'queued' ), true ),
			);
		}

		return $rows;
	}

	/**
	 * Requests running syncs to stop, clears queued follow-up actions, and restores configured recurring schedules.
	 *
	 * @return array<string,mixed>
	 */
	public function stop_actions(): array {
		$before       = $this->queue_totals();
		$stop_request = $this->settings->request_stop();

		self::clear_scheduled_actions();
		$after_cleanup = $this->queue_totals();
		$this->maybe_schedule_recurring_actions();

		$after = $this->queue_totals();
		$stop_request_active = $before['running'] > 0;

		if ( ! $stop_request_active ) {
			$this->settings->clear_stop_request();
		}

		$result = array(
			'stop_requested'       => 'yes',
			'stop_request_active'  => $stop_request_active ? 'yes' : 'no',
			'requested_at'         => $stop_request['requested_at'] ?? current_time( 'mysql' ),
			'pending_before'       => $before['pending'],
			'pending_after_cleanup' => $after_cleanup['pending'],
			'pending_after'        => $after['pending'],
			'pending_cancelled'    => max( 0, $before['pending'] - $after_cleanup['pending'] ),
			'recurring_reset'      => 'yes',
			'recurring_restored'   => $after['pending'],
			'running'              => $before['running'],
		);

		$this->logger->warning( 'admin', 'Schrack sync stop was requested from admin.', null, $result );

		return $result;
	}

	/**
	 * Runs a catalog import batch.
	 */
	public function run_catalog_import( bool $queue_continuation = true ): array {
		$importer = new Schrack_Catalog_Importer( $this->settings, $this->logger );
		$limit    = (int) $this->settings->get( 'catalog_batch_size', 1000 );
		$max_batches = max( 1, (int) $this->settings->get( 'catalog_batches_per_run', 3 ) );
		$started_at  = time();

		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( 'catalog', 0, 0 );
			}

			$result          = array();
			$total_processed = 0;
			$total_errors    = 0;
			$batches         = 0;

			for ( $batch_index = 0; $batch_index < $max_batches; ++$batch_index ) {
				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'catalog', $total_processed, $total_errors );
				}

				$result = $importer->import_from_soap( 'CSV', $limit );
				++$batches;

				$total_processed += (int) ( $result['processed'] ?? 0 );
				$total_errors    += (int) ( $result['errors'] ?? 0 );

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'catalog', $total_processed, $total_errors );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'catalog', $total_processed, $total_errors );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'              => $total_processed,
					'errors'                 => $total_errors,
					'batches_processed'      => $batches,
					'catalog_batches_per_run'=> $max_batches,
				)
			);

			$this->settings->update_status( 'catalog', $result );
			$this->logger->info( 'catalog', 'Finished Schrack catalog import run.', null, $result );
			if ( $queue_continuation ) {
				if ( $this->should_continue_batch( $result ) ) {
					$this->queue_next_batch_if_needed( self::HOOK_CATALOG, 'catalog', $result );
				} elseif ( $this->should_import_images() ) {
					$this->queue_sync_batch( self::HOOK_IMAGES, 'images', array(), array( 'source' => 'catalog_completed' ) );
				}
			}
			return $result;
		} catch ( Schrack_Rate_Limit_Exception $exception ) {
			$result = $this->handle_rate_limited_sync( 'catalog', $total_processed ?? 0, $total_errors ?? 0, $exception );

			if ( $queue_continuation ) {
				$this->queue_rate_limited_batch( self::HOOK_CATALOG, 'catalog', array(), $result );
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
		$max_batches = max( 1, (int) $this->settings->get( 'sync_batches_per_run', 3 ) );
		$started_at  = time();

		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( 'price', 0, 0 );
			}

			$result          = array();
			$total_processed = 0;
			$total_errors    = 0;
			$batches         = 0;

			for ( $batch_index = 0; $batch_index < $max_batches; ++$batch_index ) {
				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'price', $total_processed, $total_errors );
				}

				$result = $sync->sync_batch( $limit );
				++$batches;

				$total_processed += (int) ( $result['processed'] ?? 0 );
				$total_errors    += (int) ( $result['errors'] ?? 0 );

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'price', $total_processed, $total_errors );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'price', $total_processed, $total_errors );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'           => $total_processed,
					'errors'              => $total_errors,
					'batches_processed'   => $batches,
					'sync_batches_per_run'=> $max_batches,
				)
			);

			$this->settings->update_status( 'price', $result );
			$this->logger->info( 'price', 'Finished Schrack price sync run.', null, $result );
			if ( $queue_continuation ) {
				$this->queue_next_batch_if_needed( self::HOOK_PRICES, 'price', $result );
			}
			return $result;
		} catch ( Schrack_Rate_Limit_Exception $exception ) {
			$result = $this->handle_rate_limited_sync( 'price', $total_processed ?? 0, $total_errors ?? 0, $exception );

			if ( $queue_continuation ) {
				$this->queue_rate_limited_batch( self::HOOK_PRICES, 'price', array(), $result );
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
		$max_batches = max( 1, (int) $this->settings->get( 'sync_batches_per_run', 3 ) );
		$started_at  = time();

		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( 'stock', 0, 0 );
			}

			$result          = array();
			$total_processed = 0;
			$total_errors    = 0;
			$batches         = 0;

			for ( $batch_index = 0; $batch_index < $max_batches; ++$batch_index ) {
				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'stock', $total_processed, $total_errors );
				}

				$result = $sync->sync_batch( $limit );
				++$batches;

				$total_processed += (int) ( $result['processed'] ?? 0 );
				$total_errors    += (int) ( $result['errors'] ?? 0 );

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'stock', $total_processed, $total_errors );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'stock', $total_processed, $total_errors );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'           => $total_processed,
					'errors'              => $total_errors,
					'batches_processed'   => $batches,
					'sync_batches_per_run'=> $max_batches,
				)
			);

			$this->settings->update_status( 'stock', $result );
			$this->logger->info( 'stock', 'Finished Schrack stock sync run.', null, $result );
			if ( $queue_continuation ) {
				$this->queue_next_batch_if_needed( self::HOOK_STOCK, 'stock', $result );
			}
			return $result;
		} catch ( Schrack_Rate_Limit_Exception $exception ) {
			$result = $this->handle_rate_limited_sync( 'stock', $total_processed ?? 0, $total_errors ?? 0, $exception );

			if ( $queue_continuation ) {
				$this->queue_rate_limited_batch( self::HOOK_STOCK, 'stock', array(), $result );
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
		$max_batches = max( 1, (int) $this->settings->get( 'sync_batches_per_run', 3 ) );
		$started_at  = time();

		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( 'images', 0, 0 );
			}

			$result          = array();
			$total_processed = 0;
			$total_imported  = 0;
			$total_errors    = 0;
			$batches         = 0;

			for ( $batch_index = 0; $batch_index < $max_batches; ++$batch_index ) {
				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'images', $total_processed, $total_errors, array( 'imported' => $total_imported ) );
				}

				$result = $sync->sync_batch( $limit );
				++$batches;

				$total_processed += (int) ( $result['processed'] ?? 0 );
				$total_imported  += (int) ( $result['imported'] ?? 0 );
				$total_errors    += (int) ( $result['errors'] ?? 0 );

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'images', $total_processed, $total_errors, array( 'imported' => $total_imported ) );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'images', $total_processed, $total_errors, array( 'imported' => $total_imported ) );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'           => $total_processed,
					'imported'            => $total_imported,
					'errors'              => $total_errors,
					'batches_processed'   => $batches,
					'sync_batches_per_run'=> $max_batches,
				)
			);

			$this->settings->update_status( 'images', $result );
			$this->logger->info( 'images', 'Finished Schrack image sync run.', null, $result );
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

		if ( $this->settings->is_stop_requested() ) {
			$this->handle_stopped_sync( 'full', 0, 0, array( 'stage' => $stage ) );
			return;
		}

		if ( 'catalog' === $stage ) {
			$result = $this->run_catalog_import( false );

			if ( $this->is_stopped_result( $result ) ) {
				return;
			}

			if ( $this->is_rate_limited_result( $result ) ) {
				$this->queue_rate_limited_batch( self::HOOK_FULL, 'full', array( 'catalog' ), $result );
				return;
			}

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

			if ( $this->is_stopped_result( $result ) ) {
				return;
			}

			if ( $this->is_rate_limited_result( $result ) ) {
				$this->queue_rate_limited_batch( self::HOOK_FULL, 'full', array( 'price' ), $result );
				return;
			}

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
			if ( $this->is_stopped_result( $result ) ) {
				return;
			}

			if ( $this->is_rate_limited_result( $result ) ) {
				$this->queue_rate_limited_batch( self::HOOK_FULL, 'full', array( 'stock' ), $result );
				return;
			}

			if ( $this->should_continue_batch( $result ) ) {
				$this->queue_next_batch_if_needed( self::HOOK_FULL, 'full', $result, array( 'stock' ) );
			} elseif ( $this->should_import_images() ) {
				$this->queue_sync_batch( self::HOOK_FULL, 'full', array( 'images' ), array( 'next_stage' => 'images' ) );
			}

			return;
		}

		if ( 'images' === $stage && $this->should_import_images() ) {
			$result = $this->run_image_sync( false );
			if ( $this->is_stopped_result( $result ) ) {
				return;
			}

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
		$first_run = $this->next_recurring_timestamp( $frequency );

		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
				as_schedule_recurring_action( $first_run, $interval, $hook, array(), self::GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( $first_run, $this->wp_cron_frequency_key( $frequency ), $hook );
		}
	}

	/**
	 * Returns the next clean schedule boundary for configured recurring syncs.
	 */
	private function next_recurring_timestamp( string $frequency ): int {
		$now = new DateTimeImmutable( 'now', wp_timezone() );

		return match ( $frequency ) {
			'thirty_minutes' => $this->next_boundary_timestamp( $now, 30 * MINUTE_IN_SECONDS ),
			'hourly'         => $this->next_boundary_timestamp( $now, HOUR_IN_SECONDS ),
			'six_hours'      => $this->next_boundary_timestamp( $now, 6 * HOUR_IN_SECONDS ),
			'weekly'         => $this->next_weekly_timestamp( $now ),
			default          => $this->next_daily_timestamp( $now ),
		};
	}

	/**
	 * Returns the next local time boundary for minute/hour based intervals.
	 */
	private function next_boundary_timestamp( DateTimeImmutable $now, int $interval ): int {
		$seconds = (int) $now->format( 'H' ) * HOUR_IN_SECONDS + (int) $now->format( 'i' ) * MINUTE_IN_SECONDS + (int) $now->format( 's' );
		$next    = (int) ( ceil( ( $seconds + 1 ) / $interval ) * $interval );
		$days    = intdiv( $next, DAY_IN_SECONDS );
		$next   %= DAY_IN_SECONDS;
		$hour    = intdiv( $next, HOUR_IN_SECONDS );
		$minute  = intdiv( $next % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );

		return $now->modify( '+' . $days . ' days' )->setTime( $hour, $minute, 0 )->getTimestamp();
	}

	/**
	 * Returns the next local midnight.
	 */
	private function next_daily_timestamp( DateTimeImmutable $now ): int {
		return $now->modify( '+1 day' )->setTime( 0, 0, 0 )->getTimestamp();
	}

	/**
	 * Returns the next local Monday midnight.
	 */
	private function next_weekly_timestamp( DateTimeImmutable $now ): int {
		$next = $now->modify( 'monday this week' )->setTime( 0, 0, 0 );

		if ( $next <= $now ) {
			$next = $next->modify( '+1 week' );
		}

		return $next->getTimestamp();
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
	private function queue_sync_batch( string $hook, string $operation, array $args = array(), array $context = array(), ?int $delay_override = null ): void {
		$sleep            = null === $delay_override ? max( 0, (int) $this->settings->get( 'rate_limit_sleep', 0 ) ) : max( 0, $delay_override );
		$duplicate_window = max( 60, $sleep + 5 );

		if ( $this->has_active_followup_action( $hook, $args, $duplicate_window ) ) {
			$this->logger->debug(
				$operation,
				'Skipped duplicate Schrack follow-up batch queue request.',
				null,
				array_merge(
					array(
						'hook'      => $hook,
						'next_args' => $args,
					),
					$context
				)
			);
			return;
		}

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
	 * Queues a delayed retry after Schrack throttles SOAP messages.
	 *
	 * @param array<int,mixed>    $args Hook arguments.
	 * @param array<string,mixed> $result Rate-limited result.
	 */
	private function queue_rate_limited_batch( string $hook, string $operation, array $args, array $result ): void {
		$cooldown = max( 30, (int) ( $result['cooldown_seconds'] ?? $this->rate_limit_cooldown() ) );

		$this->queue_sync_batch(
			$hook,
			$operation,
			$args,
			array(
				'rate_limited' => 'yes',
				'retry_after'  => $result['retry_after'] ?? null,
			),
			$cooldown
		);
	}

	/**
	 * Records a paused sync run after Schrack rate limiting.
	 */
	private function handle_rate_limited_sync( string $operation, int $processed, int $errors, Throwable $exception ): array {
		$cooldown  = $this->rate_limit_cooldown();
		$retry_at  = time() + $cooldown;
		$status    = $this->settings->get_status();
		$last_row  = isset( $status[ $operation ] ) && is_array( $status[ $operation ] ) ? $status[ $operation ] : array();
		$error     = $exception->getMessage();
		$result    = array(
			'processed'        => $processed,
			'errors'           => $errors,
			'cursor'           => absint( $last_row['cursor'] ?? 0 ),
			'batch_start'      => absint( $last_row['batch_start'] ?? 0 ),
			'batch_count'      => max( 1, absint( $last_row['batch_count'] ?? 0 ) ),
			'batch_limit'      => absint( $last_row['batch_limit'] ?? 0 ),
			'completed_cycle'  => 'no',
			'rate_limited'     => 'yes',
			'cooldown_seconds' => $cooldown,
			'retry_after'      => wp_date( 'Y-m-d H:i:s', $retry_at ),
			'error'            => $error,
		);

		foreach ( array( 'total_items', 'total_products' ) as $total_key ) {
			if ( isset( $last_row[ $total_key ] ) ) {
				$result[ $total_key ] = absint( $last_row[ $total_key ] );
			}
		}

		$this->settings->update_status( $operation, $result );
		$this->logger->warning(
			$operation,
			'Paused Schrack sync because SOAP rate limit was reached.',
			null,
			array(
				'cooldown_seconds' => $cooldown,
				'retry_after'      => $result['retry_after'],
				'error'            => $error,
			)
		);

		return $result;
	}

	/**
	 * Records that a sync run stopped by admin request.
	 *
	 * @param array<string,mixed> $extra Extra status values.
	 * @return array<string,mixed>
	 */
	private function handle_stopped_sync( string $operation, int $processed, int $errors, array $extra = array() ): array {
		$status       = $this->settings->get_status();
		$last_row     = isset( $status[ $operation ] ) && is_array( $status[ $operation ] ) ? $status[ $operation ] : array();
		$stop_request = $this->settings->stop_request();
		$result       = array_merge(
			array(
				'processed'       => $processed,
				'errors'          => $errors,
				'cursor'          => absint( $last_row['cursor'] ?? 0 ),
				'batch_start'     => absint( $last_row['batch_start'] ?? 0 ),
				'batch_count'     => 0,
				'batch_limit'     => absint( $last_row['batch_limit'] ?? 0 ),
				'completed_cycle' => 'no',
				'stopped'         => 'yes',
				'stop_requested_at' => is_array( $stop_request ) ? (string) ( $stop_request['requested_at'] ?? '' ) : current_time( 'mysql' ),
			),
			$extra
		);

		foreach ( array( 'total_items', 'total_products' ) as $total_key ) {
			if ( isset( $last_row[ $total_key ] ) ) {
				$result[ $total_key ] = absint( $last_row[ $total_key ] );
			}
		}

		$this->settings->update_status( $operation, $result );
		$this->logger->warning( $operation, 'Stopped Schrack sync because admin requested it.', null, $result );

		return $result;
	}

	/**
	 * Returns the configured pause after a Schrack SOAP throttling response.
	 */
	private function rate_limit_cooldown(): int {
		return max( 30, min( 1800, (int) $this->settings->get( 'soap_rate_limit_cooldown', 120 ) ) );
	}

	/**
	 * Determines whether a batch result has more work behind its cursor.
	 *
	 * @param array<string,mixed> $result Last batch result.
	 */
	private function should_continue_batch( array $result ): bool {
		return 'no' === (string) ( $result['completed_cycle'] ?? 'yes' )
			&& (int) ( $result['batch_count'] ?? 0 ) > 0
			&& ! $this->is_rate_limited_result( $result )
			&& ! $this->is_stopped_result( $result );
	}

	/**
	 * Determines whether a stage paused because Schrack throttled SOAP messages.
	 *
	 * @param array<string,mixed> $result Last batch result.
	 */
	private function is_rate_limited_result( array $result ): bool {
		return 'yes' === (string) ( $result['rate_limited'] ?? 'no' );
	}

	/**
	 * Determines whether a stage stopped because admin requested it.
	 *
	 * @param array<string,mixed> $result Last batch result.
	 */
	private function is_stopped_result( array $result ): bool {
		return 'yes' === (string) ( $result['stopped'] ?? 'no' );
	}

	/**
	 * Stops a multi-batch action before PHP reaches its execution limit.
	 */
	private function should_pause_batch_run( int $started_at ): bool {
		$max_execution_time = (int) ini_get( 'max_execution_time' );

		if ( $max_execution_time <= 0 ) {
			return false;
		}

		return time() - $started_at >= max( 10, $max_execution_time - 10 );
	}

	/**
	 * Returns the first running or immediately due Schrack sync task.
	 *
	 * @return array<string,mixed>|null
	 */
	private function active_sync_conflict(): ?array {
		foreach ( $this->queue_status() as $row ) {
			if ( ! empty( $row['is_active'] ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Returns aggregate pending/running counts for all Schrack hooks.
	 *
	 * @return array{pending:int,running:int}
	 */
	private function queue_totals(): array {
		$pending = 0;
		$running = 0;

		foreach ( $this->queue_status() as $row ) {
			$pending += absint( $row['pending'] ?? 0 );
			$running += absint( $row['running'] ?? 0 );
		}

		return array(
			'pending' => $pending,
			'running' => $running,
		);
	}

	/**
	 * Returns whether the same follow-up action is already running or due.
	 *
	 * @param array<int,mixed> $args Hook arguments.
	 */
	private function has_active_followup_action( string $hook, array $args, int $window = 60 ): bool {
		$next_run = $this->next_scheduled_timestamp( $hook, $args );

		return null !== $next_run && $next_run <= time() + max( 0, $window );
	}

	/**
	 * Counts Action Scheduler actions for a hook/status pair.
	 *
	 * @param array<int,mixed>|null $args Optional exact hook arguments.
	 */
	private function scheduled_action_count( string $hook, string $status, ?array $args = null ): int {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$query = array(
				'hook'          => $hook,
				'group'         => self::GROUP,
				'status'        => $status,
				'per_page'      => 1000,
				'return_format' => 'ids',
			);

			if ( null !== $args ) {
				$query['args'] = $args;
			}

			$actions = as_get_scheduled_actions( $query );

			return is_array( $actions ) ? count( $actions ) : 0;
		}

		if ( 'pending' !== $status ) {
			return 0;
		}

		return null !== $this->next_scheduled_timestamp( $hook, $args ) ? 1 : 0;
	}

	/**
	 * Returns the next scheduled timestamp for a hook.
	 *
	 * @param array<int,mixed>|null $args Optional exact hook arguments.
	 */
	private function next_scheduled_timestamp( string $hook, ?array $args = null ): ?int {
		$timestamp = false;

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$timestamp = as_next_scheduled_action( $hook, $args, self::GROUP );
		} else {
			$timestamp = null === $args ? wp_next_scheduled( $hook ) : wp_next_scheduled( $hook, $args );
		}

		return false === $timestamp || null === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * Returns queue-aware task definitions.
	 *
	 * @return array<string,array{hook:string,label:string}>
	 */
	private function task_definitions(): array {
		return array(
			'catalog' => array(
				'hook'  => self::HOOK_CATALOG,
				'label' => __( 'Catalog', 'schrack-woocommerce-sync' ),
			),
			'full'    => array(
				'hook'  => self::HOOK_FULL,
				'label' => __( 'Full sync', 'schrack-woocommerce-sync' ),
			),
			'prices'  => array(
				'hook'  => self::HOOK_PRICES,
				'label' => __( 'Prices', 'schrack-woocommerce-sync' ),
			),
			'stock'   => array(
				'hook'  => self::HOOK_STOCK,
				'label' => __( 'Stock', 'schrack-woocommerce-sync' ),
			),
			'images'  => array(
				'hook'  => self::HOOK_IMAGES,
				'label' => __( 'Images', 'schrack-woocommerce-sync' ),
			),
		);
	}

	/**
	 * Returns whether media-library image import should run after catalog data.
	 */
	private function should_import_images(): bool {
		return 'yes' === $this->settings->get( 'image_import_enabled', 'yes' );
	}
}
