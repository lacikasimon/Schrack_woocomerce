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
	public const HOOK_TELESYSTEM_CATALOG = 'schrack_wc_sync_telesystem_catalog';
	public const HOOK_PRICES  = 'schrack_wc_sync_prices';
	public const HOOK_STOCK   = 'schrack_wc_sync_stock';
	public const HOOK_FULL    = 'schrack_wc_sync_full';
	public const HOOK_IMAGES  = 'schrack_wc_sync_images';
	public const HOOK_IMAGE_WORKER = 'schrack_wc_sync_image_worker';
	public const HOOK_CATALOG_WORKER = 'schrack_wc_sync_catalog_worker';
	public const HOOK_DEBUG_EXPORT = 'schrack_wc_sync_debug_export';
	public const HOOK_CATEGORY_IMPORT = 'schrack_wc_sync_category_csv_import';

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
		add_filter( 'action_scheduler_queue_runner_concurrent_batches', array( $this, 'raise_action_scheduler_concurrency' ) );
		add_filter( 'action_scheduler_queue_runner_batch_size', array( $this, 'lower_action_scheduler_batch_size' ) );
		add_action( 'init', array( $this, 'maybe_handle_queue_runner_ping' ), 0 );
		add_action( 'init', array( $this, 'maybe_schedule_recurring_actions' ) );
		add_action( self::HOOK_CATALOG, array( $this, 'run_catalog_import' ) );
		add_action( self::HOOK_TELESYSTEM_CATALOG, array( $this, 'run_telesystem_catalog_import' ) );
		add_action( self::HOOK_PRICES, array( $this, 'run_price_sync' ) );
		add_action( self::HOOK_STOCK, array( $this, 'run_stock_sync' ) );
		add_action( self::HOOK_IMAGES, array( $this, 'run_image_sync' ) );
		add_action( self::HOOK_IMAGE_WORKER, array( $this, 'run_image_worker' ), 10, 3 );
		add_action( self::HOOK_CATALOG_WORKER, array( $this, 'run_catalog_worker' ), 10, 9 );
		add_action( self::HOOK_FULL, array( $this, 'run_full_sync' ), 10, 1 );
		add_action( self::HOOK_DEBUG_EXPORT, array( $this, 'run_debug_export' ), 10, 2 );
		add_action( self::HOOK_CATEGORY_IMPORT, array( $this, 'run_category_csv_import' ), 10, 1 );
	}

	/**
	 * A fixed-per-site secret so an external cron job can trigger Action
	 * Scheduler's queue runner directly, without needing a WP nonce (which
	 * rotates and can't be embedded in a static cron command). wp-cron.php
	 * itself can't serve this purpose for real concurrency: it holds
	 * WordPress's own site-wide "doing_cron" lock, so several near-simultaneous
	 * hits to wp-cron.php still only let ONE of them actually process
	 * anything -- the rest just see the lock and exit immediately. Hitting
	 * this endpoint several times at once from cron instead calls the Action
	 * Scheduler hook directly, bypassing that lock entirely, gated only by the
	 * action_scheduler_queue_runner_concurrent_batches ceiling raised above.
	 *
	 * Derived from the site's own AUTH_SALT rather than a value stored in
	 * code or the database, so it's never committed to version control and
	 * differs per install automatically.
	 */
	public function queue_runner_ping_secret(): string {
		return substr( wp_hash( 'schrack_as_queue_runner_ping_v1', 'auth' ), 0, 32 );
	}

	/**
	 * Handles an external cron hit to the queue-runner ping endpoint. Exits
	 * immediately either way, before the rest of WordPress (theme, most
	 * plugins) loads, since this only ever needs Action Scheduler's own hook.
	 *
	 * Requires both the secret AND that the request originates from the
	 * server itself (loopback, or the server's own resolved IP) -- defense in
	 * depth so that even a leaked secret can't be used to trigger this from
	 * off-server.
	 */
	public function maybe_handle_queue_runner_ping(): void {
		if ( ! isset( $_GET['schrack_as_ping'] ) ) {
			return;
		}

		$provided = sanitize_text_field( wp_unslash( (string) $_GET['schrack_as_ping'] ) );

		if ( ! hash_equals( $this->queue_runner_ping_secret(), $provided ) ) {
			return;
		}

		if ( ! $this->request_is_from_this_server() ) {
			return;
		}

		if ( has_action( 'action_scheduler_run_queue' ) ) {
			do_action( 'action_scheduler_run_queue', 'Schrack Cron Ping' );
		}

		exit;
	}

	/**
	 * Checks whether the current request's remote address is the server this
	 * site itself runs on, so the ping endpoint only ever honors requests the
	 * server made to itself (e.g. its own cron), regardless of who has the
	 * secret.
	 */
	private function request_is_from_this_server(): bool {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		if ( '' === $remote ) {
			return false;
		}

		if ( in_array( $remote, array( '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$server_ip = gethostbyname( $host );

		return '' !== $server_ip && $server_ip !== $host && $server_ip === $remote;
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
		if ( 'yes' !== (string) $this->settings->get( 'automatic_sync_enabled', 'yes' ) ) {
			return;
		}

		if ( $this->is_schrack_enabled() ) {
			$this->schedule_recurring_action( self::HOOK_CATALOG, (string) $this->settings->get( 'catalog_sync_frequency', 'daily' ) );
			$this->schedule_recurring_action( self::HOOK_PRICES, (string) $this->settings->get( 'price_sync_frequency', 'daily' ) );
			$this->schedule_recurring_action( self::HOOK_STOCK, (string) $this->settings->get( 'stock_sync_frequency', 'hourly' ) );
		} else {
			$this->clear_schrack_source_actions();
		}

		if ( 'yes' === (string) $this->settings->get( 'telesystem_enabled', 'yes' ) ) {
			$this->schedule_recurring_action( self::HOOK_TELESYSTEM_CATALOG, (string) $this->settings->get( 'telesystem_sync_frequency', 'daily' ) );
		} elseif ( self::has_scheduled_hook( self::HOOK_TELESYSTEM_CATALOG ) ) {
			self::clear_hook_actions( self::HOOK_TELESYSTEM_CATALOG );
		}
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
	 * @return array<string,mixed>
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

		if ( in_array( $task, array( 'catalog', 'prices', 'stock' ), true ) && ! $this->is_schrack_enabled() ) {
			return array(
				'queued'  => false,
				'code'    => 'schrack_disabled',
				'message' => __( 'Schrack sync is disabled.', 'schrack-woocommerce-sync' ),
				'task'    => $task,
			);
		}

		if ( 'full' === $task && ! $this->is_schrack_enabled() && ! $this->is_telesystem_enabled() ) {
			return array(
				'queued'  => false,
				'code'    => 'schrack_disabled',
				'message' => __( 'Full sync is disabled: both Schrack and Telesystem are disabled.', 'schrack-woocommerce-sync' ),
				'task'    => $task,
			);
		}

		if ( 'images' === $task && ! $this->should_import_images() ) {
			return array(
				'queued'  => false,
				'code'    => 'image_import_disabled',
				'message' => __( 'Image sync is disabled. Catalog image URLs remain stored as external references.', 'schrack-woocommerce-sync' ),
				'task'    => $task,
			);
		}

		if ( 'telesystem_catalog' === $task && 'yes' !== (string) $this->settings->get( 'telesystem_enabled', 'yes' ) ) {
			return array(
				'queued'  => false,
				'code'    => 'telesystem_disabled',
				'message' => __( 'Telesystem sync is disabled.', 'schrack-woocommerce-sync' ),
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

		$queued = $this->queue_manual_action( $hook, $args );

		if ( ! empty( $queued['queued'] ) ) {
			$this->logger->info(
				$task,
				'Queued manual Schrack sync task.',
				null,
				array_merge(
					array(
						'hook' => $hook,
						'args' => $args,
					),
					$queued
				)
			);

			return array(
				'queued'  => true,
				'code'    => 'queued',
				'message' => __( 'Sync task queued.', 'schrack-woocommerce-sync' ),
				'task'    => $task,
			) + $queued;
		}

		$this->logger->error(
			$task,
			'Failed to queue manual Schrack sync task.',
			null,
			array(
				'hook' => $hook,
				'args' => $args,
			)
		);

		return array(
			'queued'  => false,
			'code'    => 'queue_failed',
			'message' => __( 'Could not queue sync task. Please check Action Scheduler/WP-Cron.', 'schrack-woocommerce-sync' ),
			'task'    => $task,
		);
	}

	/**
	 * Queues a manual action in a way that stays visible in Action Scheduler status.
	 *
	 * @param array<int,mixed> $args Hook arguments.
	 * @return array<string,mixed>
	 */
	private function queue_manual_action( string $hook, array $args ): array {
		$scheduled_for = time() + 1;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = absint( as_schedule_single_action( $scheduled_for, $hook, $args, self::GROUP ) );

			if ( $action_id > 0 ) {
				return array(
					'queued'        => true,
					'queue_runner'  => 'action_scheduler_single',
					'action_id'     => $action_id,
					'scheduled_for' => wp_date( 'Y-m-d H:i:s', $scheduled_for ),
				);
			}
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = absint( as_enqueue_async_action( $hook, $args, self::GROUP ) );

			if ( $action_id > 0 ) {
				return array(
					'queued'       => true,
					'queue_runner' => 'action_scheduler_async',
					'action_id'    => $action_id,
				);
			}
		}

		$wp_cron_result = wp_schedule_single_event( time() + 5, $hook, $args );

		if ( false !== $wp_cron_result ) {
			return array(
				'queued'        => true,
				'queue_runner'  => 'wp_cron',
				'scheduled_for' => wp_date( 'Y-m-d H:i:s', time() + 5 ),
			);
		}

		return array( 'queued' => false );
	}

	/**
	 * Queues a raw feed debug export in the background so a large row count can
	 * never time out the triggering HTTP request. Runs independently of the
	 * Schrack-enabled/active-sync-conflict gating used for real sync tasks, since
	 * it never touches WooCommerce product data.
	 *
	 * @return array<string,mixed>
	 */
	public function queue_debug_export( string $source, int $limit ): array {
		$this->settings->update_status(
			'debug_export',
			array(
				'state'      => 'queued',
				'source'     => $source,
				'limit'      => $limit,
				'started_at' => time(),
			)
		);

		$queued = $this->queue_manual_action( self::HOOK_DEBUG_EXPORT, array( $source, $limit ) );

		if ( empty( $queued['queued'] ) ) {
			$this->settings->update_status(
				'debug_export',
				array(
					'state'   => 'error',
					'source'  => $source,
					'limit'   => $limit,
					'message' => __( 'Could not queue the debug export. Please check Action Scheduler/WP-Cron.', 'schrack-woocommerce-sync' ),
				)
			);

			return array( 'queued' => false );
		}

		$this->logger->info( 'debug', 'Queued raw feed debug export.', null, array_merge( array( 'source' => $source, 'limit' => $limit ), $queued ) );

		return $queued;
	}

	/**
	 * Queues a background product category CSV import batch.
	 *
	 * @return array<string,mixed>
	 */
	public function queue_category_csv_import( string $import_id ): array {
		$queued = $this->queue_manual_action( self::HOOK_CATEGORY_IMPORT, array( $import_id ) );

		if ( empty( $queued['queued'] ) ) {
			$importer = new Schrack_Category_CSV_Importer( $this->settings, $this->logger );
			$importer->mark_queue_failed( $import_id );

			return array(
				'queued'  => false,
				'message' => __( 'Could not queue the category CSV import. Please check Action Scheduler/WP-Cron.', 'schrack-woocommerce-sync' ),
			);
		}

		$this->logger->info( 'category_import', 'Queued category CSV import worker.', null, array_merge( array( 'import_id' => $import_id ), $queued ) );

		return $queued;
	}

	/**
	 * Runs one queued product category CSV import batch.
	 *
	 * @return array<string,mixed>
	 */
	public function run_category_csv_import( string $import_id ): array {
		$importer = new Schrack_Category_CSV_Importer( $this->settings, $this->logger );
		$result   = $importer->run_batch( $import_id );

		if ( 'no' === (string) ( $result['completed_cycle'] ?? 'yes' ) && 'error' !== (string) ( $result['state'] ?? '' ) ) {
			if ( ! $this->queue_sync_batch(
				self::HOOK_CATEGORY_IMPORT,
				'category_import',
				array( $import_id ),
				array(
					'line_number' => $result['line_number'] ?? null,
					'batch_count' => $result['batch_count'] ?? null,
				),
				1
			) ) {
				$importer->mark_queue_failed( $import_id );
			}
		}

		return $result;
	}

	/**
	 * Runs a queued raw feed debug export and writes the result to a file so it
	 * can be downloaded once ready, instead of holding an HTTP request open.
	 *
	 * @return array<string,mixed>
	 */
	public function run_debug_export( string $source, int $limit ): array {
		$previous_status = $this->settings->get_status();
		$previous_export = isset( $previous_status['debug_export'] ) && is_array( $previous_status['debug_export'] ) ? $previous_status['debug_export'] : array();
		$started_at      = absint( $previous_export['started_at'] ?? 0 ) ?: time();

		$this->settings->update_status(
			'debug_export',
			array(
				'state'      => 'running',
				'source'     => $source,
				'limit'      => $limit,
				'started_at' => $started_at,
			)
		);

		try {
			if ( 'telesystem' === $source ) {
				$importer = new Schrack_Telesystem_Importer( $this->settings, $this->logger );
				$data     = $importer->debug_raw_rows( $limit );
			} else {
				$format   = 'schrack_xml' === $source ? 'XML' : 'CSV';
				$importer = new Schrack_Catalog_Importer( $this->settings, $this->logger );
				$data     = $importer->debug_raw_rows( $format, $limit );
			}

			$data['source'] = $source;

			if ( ! empty( $data['error'] ) ) {
				$result = array(
					'state'      => 'error',
					'source'     => $source,
					'limit'      => $limit,
					'started_at' => $started_at,
					'message'    => (string) $data['error'],
				);
				$this->settings->update_status( 'debug_export', $result );

				return $result;
			}

			$file = $this->write_debug_export_file( $source, $data );

			if ( null === $file ) {
				$result = array(
					'state'      => 'error',
					'source'     => $source,
					'limit'      => $limit,
					'started_at' => $started_at,
					'message'    => __( 'Could not write the debug export file.', 'schrack-woocommerce-sync' ),
				);
				$this->settings->update_status( 'debug_export', $result );

				return $result;
			}

			$result = array(
				'state'        => 'done',
				'source'       => $source,
				'limit'        => $limit,
				'started_at'   => $started_at,
				'rows'         => count( $data['rows'] ?? array() ),
				'file'         => $file['path'],
				'file_name'    => $file['name'],
				'bytes'        => $file['bytes'],
				'capped_early' => ! empty( $data['capped_early'] ) ? 'yes' : 'no',
			);
			$this->settings->update_status( 'debug_export', $result );
			$this->logger->info( 'debug', 'Finished raw feed debug export.', null, $result );

			return $result;
		} catch ( Throwable $exception ) {
			$result = array(
				'state'      => 'error',
				'source'     => $source,
				'limit'      => $limit,
				'started_at' => $started_at,
				'message'    => $exception->getMessage(),
			);
			$this->settings->update_status( 'debug_export', $result );
			$this->logger->error( 'debug', 'Raw feed debug export failed.', null, array( 'error' => $exception->getMessage() ) );

			return $result;
		}
	}

	/**
	 * Clears a stuck or finished debug export status so a new one can be queued.
	 *
	 * @return array<string,mixed>
	 */
	public function reset_debug_export(): array {
		$result = array( 'state' => 'idle' );
		$this->settings->update_status( 'debug_export', $result );

		return $result;
	}

	/**
	 * Writes a debug export result to a file under the uploads directory.
	 *
	 * @param array<string,mixed> $data Export data.
	 * @return array{path:string,name:string,bytes:int}|null
	 */
	private function write_debug_export_file( string $source, array $data ): ?array {
		$dir = $this->debug_export_dir();

		if ( '' === $dir ) {
			return null;
		}

		$this->cleanup_debug_export_files( $dir );

		$name = 'schrack-debug-' . sanitize_key( $source ) . '-' . time() . '.json';
		$path = trailingslashit( $dir ) . $name;
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) || false === file_put_contents( $path, $json ) ) {
			return null;
		}

		return array(
			'path'  => $path,
			'name'  => $name,
			'bytes' => (int) filesize( $path ),
		);
	}

	/**
	 * Returns the debug export directory, creating it if needed.
	 */
	private function debug_export_dir(): string {
		$upload = wp_upload_dir( null, false );

		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( (string) $upload['basedir'] ) . 'schrack-wc-sync/debug';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Removes previous debug export files before writing a new one.
	 */
	private function cleanup_debug_export_files( string $dir ): void {
		foreach ( glob( trailingslashit( $dir ) . 'schrack-debug-*.json' ) ?: array() as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
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
			$pending  = 0;
			$running  = 0;
			$next_run = null;

			foreach ( $this->definition_hooks( $definition ) as $definition_hook ) {
				$pending += $this->scheduled_action_count( $definition_hook, 'pending' );
				$running += $this->active_running_action_count( $definition_hook );
				$hook_next_run = $this->next_scheduled_timestamp( $definition_hook );

				if ( null !== $hook_next_run && ( null === $next_run || $hook_next_run < $next_run ) ) {
					$next_run = $hook_next_run;
				}
			}

			$state    = 'idle';

			if ( $running > 0 ) {
				$state = 'running';
			} elseif ( $pending > 0 && null === $next_run ) {
				$state = 'queued';
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
		$result = array(
			'stop_requested'       => 'yes',
			'stop_request_active'  => 'yes',
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
	 *
	 * @param bool $allow_parallel Whether parallel workers may be dispatched.
	 *                             Full sync forces this false: should_continue_batch()
	 *                             can't tell "workers still running in the background"
	 *                             from "nothing left to do", so chaining stages through
	 *                             a parallel dispatch would advance to the next stage
	 *                             before the catalog workers actually finish.
	 */
	public function run_catalog_import( bool $queue_continuation = true, bool $allow_parallel = true ): array {
		if ( ! $this->is_schrack_enabled() ) {
			return $this->disabled_schrack_result( 'catalog' );
		}

		if ( $allow_parallel ) {
			$workers = $this->catalog_parallel_workers();

			if ( $workers > 1 && $this->can_queue_parallel_image_workers() ) {
				return $this->queue_parallel_catalog_sync( $workers, $queue_continuation );
			}
		}

		$importer = new Schrack_Catalog_Importer( $this->settings, $this->logger );
		$limit    = $this->catalog_batch_limit();
		$max_batches = $this->catalog_batches_per_run();
		$started_at  = time();

		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( 'catalog', 0, 0 );
			}

			$result                    = array();
			$total_processed           = 0;
			$total_errors              = 0;
			$total_image_seen          = 0;
			$total_image_urls          = 0;
			$total_image_backfilled   = 0;
			$total_image_meta_errors  = 0;
			$batches                   = 0;

			for ( $batch_index = 0; $batch_index < $max_batches; ++$batch_index ) {
				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'catalog', $total_processed, $total_errors );
				}

				if ( $batch_index > 0 && $this->should_pause_batch_run( $started_at, 'catalog' ) ) {
					break;
				}

				$result = $importer->import_from_soap( 'CSV', $limit );
				++$batches;

				$total_processed           += (int) ( $result['processed'] ?? 0 );
				$total_errors              += (int) ( $result['errors'] ?? 0 );
				$total_image_seen          += (int) ( $result['image_urls_seen'] ?? 0 );
				$total_image_urls          += (int) ( $result['image_urls_stored'] ?? 0 );
				$total_image_backfilled  += (int) ( $result['image_urls_backfilled'] ?? 0 );
				$total_image_meta_errors += (int) ( $result['image_url_meta_errors'] ?? 0 );
				$this->release_batch_memory();

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'catalog', $total_processed, $total_errors );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'catalog', $total_processed, $total_errors );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at, 'catalog' ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'               => $total_processed,
					'errors'                  => $total_errors,
					'image_urls_seen'         => $total_image_seen,
					'image_urls_stored'       => $total_image_urls,
					'image_urls_backfilled'   => $total_image_backfilled,
					'image_url_meta_errors'   => $total_image_meta_errors,
					'batches_processed'       => $batches,
					'catalog_batches_per_run' => $max_batches,
				),
				$this->memory_status_context()
			);

			$this->settings->update_status( 'catalog', $result );
			$this->logger->info( 'catalog', 'Finished Schrack catalog import run.', null, $result );
			if ( $queue_continuation ) {
				if ( $this->should_continue_batch( $result ) ) {
					$this->queue_next_batch_if_needed( self::HOOK_CATALOG, 'catalog', $result );
				} elseif ( $this->should_import_images() && 'yes' !== (string) ( $result['disabled'] ?? 'no' ) ) {
					if ( ! $this->queue_sync_batch( self::HOOK_IMAGES, 'images', array(), array( 'source' => 'catalog_completed' ) ) ) {
						$this->mark_queue_failed(
							'images',
							array(
								'processed'       => 0,
								'errors'          => 1,
								'completed_cycle' => 'no',
								'source'          => 'catalog_completed',
							),
							self::HOOK_IMAGES,
							array()
						);
					}
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
	 * Dispatches one wave of parallel catalog import workers: claims the whole
	 * remaining catalog as fixed, non-overlapping row ranges (one per worker),
	 * primes every category/attribute term those rows reference so workers
	 * never race to create the same new term, then queues one Action Scheduler
	 * action per range. Mirrors queue_parallel_image_sync()'s pattern.
	 */
	private function queue_parallel_catalog_sync( int $workers, bool $queue_continuation ): array {
		if ( $this->settings->is_stop_requested() ) {
			return $this->handle_stopped_sync( 'catalog', 0, 0 );
		}

		$active_workers = $this->active_catalog_worker_count();

		if ( $active_workers > 0 ) {
			return $this->defer_parallel_catalog_dispatcher( $workers, $active_workers, $queue_continuation );
		}

		$status   = $this->settings->get_status();
		$last_row = isset( $status['catalog'] ) && is_array( $status['catalog'] ) ? $status['catalog'] : array();

		if ( 'yes' === (string) ( $last_row['parallel'] ?? 'no' ) && 'no' === (string) ( $last_row['completed_cycle'] ?? 'yes' ) ) {
			// No workers are pending/running anymore, but the last dispatched wave
			// never got marked complete -- that means it just finished. Finalize
			// that cycle instead of immediately fetching and starting a new one.
			return $this->finalize_parallel_catalog_cycle( $last_row, $queue_continuation );
		}

		$importer = new Schrack_Catalog_Importer( $this->settings, $this->logger );

		try {
			$cache = $importer->fetch_and_cache_catalog( 'CSV' );
		} catch ( Schrack_Rate_Limit_Exception $exception ) {
			$result = $this->handle_rate_limited_sync( 'catalog', 0, 0, $exception );

			if ( $queue_continuation ) {
				$this->queue_rate_limited_batch( self::HOOK_CATALOG, 'catalog', array(), $result );
			}

			return $result;
		} catch ( Throwable $exception ) {
			$this->logger->error( 'catalog', 'Failed to fetch/parse Schrack catalog for parallel import.', null, array( 'error' => $exception->getMessage() ) );
			$result = array( 'processed' => 0, 'errors' => 1, 'completed_cycle' => 'yes' );
			$this->settings->update_status( 'catalog', $result );

			return $result;
		}

		if ( null === $cache || absint( $cache['total_items'] ?? 0 ) <= 0 ) {
			$result = array( 'processed' => 0, 'errors' => 0, 'completed_cycle' => 'yes' );
			$this->settings->update_status( 'catalog', $result );
			$this->logger->info( 'catalog', 'Schrack catalog was empty; nothing to import.', null, $result );

			return $result;
		}

		$total_items = absint( $cache['total_items'] );
		$format      = (string) $cache['format'];
		$signature   = (string) $cache['signature'];
		$items_path  = (string) $cache['items_path'];
		$run_id      = wp_generate_uuid4();

		$this->prime_catalog_terms( $importer, $items_path, $total_items );

		$ranges          = $this->split_range( $total_items, $workers );
		$queued_workers  = 0;
		$queued_ranges   = array();
		$queue_errors    = 0;

		foreach ( $ranges as $index => $range ) {
			[$start, $count] = $range;
			$end              = $start + $count;

			$queued = $this->queue_sync_batch(
				self::HOOK_CATALOG_WORKER,
				'catalog',
				array( $format, $signature, $items_path, $start, $end, $run_id, $start, 0, 0 ),
				array(
					'run_id'       => $run_id,
					'worker_index' => $index + 1,
					'range_start'  => $start,
					'range_end'    => $end,
				),
				0
			);

			if ( $queued ) {
				++$queued_workers;
				$queued_ranges[] = array( $start, $end );
			} else {
				++$queue_errors;
			}
		}

		$result = array_merge(
			array(
				'processed'         => 0,
				'errors'            => $queue_errors,
				'total_items'       => $total_items,
				'batch_count'       => 0,
				'completed_cycle'   => 'no',
				'parallel'          => 'yes',
				'run_id'            => $run_id,
				'workers_requested' => $workers,
				'workers_queued'    => $queued_workers,
				'catalog_format'    => $format,
				'catalog_signature' => $signature,
			),
			$this->memory_status_context()
		);

		$this->settings->update_status( 'catalog', $result );
		$this->logger->info(
			'catalog',
			'Queued parallel Schrack catalog import workers.',
			null,
			array_merge( $result, array( 'ranges' => $queued_ranges ) )
		);

		// Action Scheduler's own async dispatch only starts one new runner at
		// most once every 60 seconds, so without this the 5 queued workers
		// above would still only progress one at a time. Firing several
		// concurrent loopback pings ourselves is what actually lets them run
		// at the same time (combined with the concurrency/batch-size filters
		// registered in init()).
		if ( $queued_workers > 0 ) {
			$this->dispatch_concurrent_queue_runners( $queued_workers );
		}

		if ( $queue_continuation && $queued_workers > 0 ) {
			$this->queue_sync_batch(
				self::HOOK_CATALOG,
				'catalog',
				array(),
				array( 'source' => 'parallel_catalog_continuation', 'run_id' => $run_id ),
				$this->image_parallel_followup_delay()
			);
		}

		return $result;
	}

	/**
	 * Re-queues the catalog dispatcher to check back later instead of claiming
	 * new work while a previous wave of parallel workers is still active.
	 */
	private function defer_parallel_catalog_dispatcher( int $workers, int $active_workers, bool $queue_continuation ): array {
		$status   = $this->settings->get_status();
		$last_row = isset( $status['catalog'] ) && is_array( $status['catalog'] ) ? $status['catalog'] : array();
		$result   = array_merge(
			$last_row,
			array(
				'parallel'          => 'yes',
				'completed_cycle'   => 'no',
				'waiting_workers'   => 'yes',
				'active_workers'    => $active_workers,
				'workers_requested' => $workers,
			),
			$this->memory_status_context()
		);

		$this->settings->update_status( 'catalog', $result );

		if ( $queue_continuation ) {
			$this->queue_sync_batch(
				self::HOOK_CATALOG,
				'catalog',
				array(),
				array( 'source' => 'catalog_workers_active', 'active_workers' => $active_workers ),
				$this->image_parallel_followup_delay()
			);
		}

		return $result;
	}

	/**
	 * Marks a parallel catalog cycle complete once every worker has finished,
	 * deletes the now-fully-consumed cache and the per-worker progress rows it
	 * left behind, and (like the sequential path) triggers the image sync stage.
	 *
	 * @param array<string,mixed> $last_row Status row from the dispatch that just finished.
	 */
	private function finalize_parallel_catalog_cycle( array $last_row, bool $queue_continuation ): array {
		$format      = (string) ( $last_row['catalog_format'] ?? 'CSV' );
		$signature   = (string) ( $last_row['catalog_signature'] ?? '' );
		$total_items = absint( $last_row['total_items'] ?? 0 );
		$run_id      = (string) ( $last_row['run_id'] ?? '' );
		$totals      = $this->sum_and_clear_catalog_worker_status( $run_id );

		if ( '' !== $signature ) {
			$importer = new Schrack_Catalog_Importer( $this->settings, $this->logger );
			$importer->delete_cache_for_signature( $format, $signature );
		}

		$result = array_merge(
			$last_row,
			array(
				'processed'       => $totals['processed'] > 0 ? $totals['processed'] : $total_items,
				'errors'          => $totals['errors'],
				'batch_count'     => $total_items,
				'completed_cycle' => 'yes',
				'parallel'        => 'yes',
			),
			$this->memory_status_context()
		);

		$this->settings->update_status( 'catalog', $result );
		$this->logger->info( 'catalog', 'Finished parallel Schrack catalog import cycle.', null, $result );

		if ( $queue_continuation && $this->should_import_images() ) {
			if ( ! $this->queue_sync_batch( self::HOOK_IMAGES, 'images', array(), array( 'source' => 'catalog_completed' ) ) ) {
				$this->mark_queue_failed(
					'images',
					array(
						'processed'       => 0,
						'errors'          => 1,
						'completed_cycle' => 'no',
						'source'          => 'catalog_completed',
					),
					self::HOOK_IMAGES,
					array()
				);
			}
		}

		return $result;
	}

	/**
	 * Sums every 'catalog_worker_*' progress row belonging to a run, then
	 * deletes them -- they exist only to let the status page show live
	 * progress while workers are active (see Schrack_Admin::
	 * aggregate_parallel_catalog_status()); once the cycle is finalized they'd
	 * otherwise sit around as stale option rows forever.
	 *
	 * @return array{processed:int,errors:int}
	 */
	private function sum_and_clear_catalog_worker_status( string $run_id ): array {
		$processed = 0;
		$errors    = 0;

		if ( '' === $run_id ) {
			return array( 'processed' => $processed, 'errors' => $errors );
		}

		$status = $this->settings->get_status();

		foreach ( $status as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'catalog_worker_' ) || ! is_array( $value ) ) {
				continue;
			}

			if ( $run_id !== (string) ( $value['run_id'] ?? '' ) ) {
				continue;
			}

			$processed += absint( $value['processed'] ?? 0 );
			$errors    += absint( $value['errors'] ?? 0 );
			$this->settings->delete_status( $key );
		}

		return array( 'processed' => $processed, 'errors' => $errors );
	}

	/**
	 * Runs one parallel catalog import worker over its own fixed [start, end)
	 * row range. If it gets close to its time/memory budget before finishing,
	 * it requeues itself with an updated start offset -- the same
	 * should_pause_batch_run() safety net the sequential loop uses, just scoped
	 * to this worker's own slice instead of the whole catalog.
	 *
	 * @return array<string,mixed>
	 */
	public function run_catalog_worker( string $format, string $signature, string $items_path, int $start, int $end, string $run_id, int $range_start = -1, int $carried_processed = 0, int $carried_errors = 0 ): array {
		// A worker that pauses and requeues itself starts this method fresh each
		// time, with its own processed/errors counters back at zero. range_start
		// (stable across resumptions, unlike $start) and the carried totals let
		// the status row report this worker's true cumulative progress instead
		// of resetting to 0 every time it resumes -- see status_key() below.
		if ( $range_start < 0 ) {
			$range_start = $start;
		}

		$status_key = 'catalog_worker_' . $range_start;

		try {
			$importer   = new Schrack_Catalog_Importer( $this->settings, $this->logger );
			$limit      = $this->catalog_batch_limit();
			$started_at = time();
			$position   = max( 0, $start );
			$processed  = $carried_processed;
			$errors     = $carried_errors;

			while ( $position < $end ) {
				if ( $this->settings->is_stop_requested() ) {
					$this->logger->warning(
						'catalog',
						'Stopped parallel Schrack catalog worker because admin requested it.',
						null,
						array( 'run_id' => $run_id, 'processed' => $processed, 'errors' => $errors, 'resumed_at' => $position, 'range_end' => $end )
					);

					$this->settings->update_status( $status_key, array( 'run_id' => $run_id, 'processed' => $processed, 'errors' => $errors, 'state' => 'stopped' ) );

					return array( 'processed' => $processed, 'errors' => $errors, 'run_id' => $run_id, 'stopped' => 'yes' );
				}

				$batch_limit = min( $limit, $end - $position );
				$result      = $importer->import_cache_range( $format, $signature, $items_path, $position, $batch_limit );
				$batch_count = absint( $result['batch_count'] ?? 0 );

				$processed += absint( $result['processed'] ?? 0 );
				$errors    += absint( $result['errors'] ?? 0 );
				$this->release_batch_memory();

				if ( $batch_count <= 0 ) {
					// Nothing left to read at this offset (short file/race with
					// cache cleanup) -- stop instead of spinning on an empty read.
					break;
				}

				$position += $batch_count;

				$this->settings->update_status(
					$status_key,
					array( 'run_id' => $run_id, 'processed' => $processed, 'errors' => $errors, 'state' => 'running' )
				);

				if ( $position < $end && $this->should_pause_batch_run( $started_at, 'catalog' ) ) {
					$this->queue_sync_batch(
						self::HOOK_CATALOG_WORKER,
						'catalog',
						array( $format, $signature, $items_path, $position, $end, $run_id, $range_start, $processed, $errors ),
						array( 'run_id' => $run_id, 'resumed_at' => $position, 'range_end' => $end ),
						0
					);
					// Workers in a wave tend to hit their pause threshold around
					// the same moment; without this, only the first of them to
					// requeue would trigger Action Scheduler's throttled
					// self-dispatch, and the rest would sit due but idle for up
					// to another 60 seconds.
					$this->dispatch_queue_runner_ping();

					$this->logger->info(
						'catalog',
						'Paused parallel Schrack catalog worker before its time/memory budget ran out; requeued the remaining range.',
						null,
						array_merge(
							array( 'run_id' => $run_id, 'processed' => $processed, 'errors' => $errors, 'resumed_at' => $position, 'range_end' => $end ),
							$this->memory_status_context()
						)
					);

					return array( 'processed' => $processed, 'errors' => $errors, 'run_id' => $run_id, 'paused' => 'yes' );
				}
			}

			$this->settings->update_status( $status_key, array( 'run_id' => $run_id, 'processed' => $processed, 'errors' => $errors, 'state' => 'completed' ) );
			$this->logger->info(
				'catalog',
				'Finished parallel Schrack catalog worker range.',
				null,
				array_merge(
					array( 'run_id' => $run_id, 'processed' => $processed, 'errors' => $errors, 'range_start' => $start, 'range_end' => $end ),
					$this->memory_status_context()
				)
			);

			return array( 'processed' => $processed, 'errors' => $errors, 'run_id' => $run_id, 'completed' => 'yes' );
		} catch ( Throwable $exception ) {
			$this->logger->error(
				'catalog',
				'Parallel Schrack catalog worker failed.',
				null,
				array( 'run_id' => $run_id, 'range_start' => $start, 'range_end' => $end, 'error' => $exception->getMessage() )
			);

			$this->settings->update_status(
				'catalog_worker_' . $range_start,
				array( 'run_id' => $run_id, 'processed' => $carried_processed, 'errors' => $carried_errors + 1, 'state' => 'failed' )
			);

			return array( 'processed' => 0, 'errors' => 1, 'run_id' => $run_id );
		}
	}

	/**
	 * Sequentially pre-creates every category/attribute term the remaining
	 * catalog references, reading the cache in pages so priming a large
	 * catalog does not hold the whole thing in memory at once.
	 */
	private function prime_catalog_terms( Schrack_Catalog_Importer $importer, string $items_path, int $total_items ): void {
		$mapper = new Schrack_Product_Mapper( $this->settings, $this->logger );
		$page   = 2000;

		for ( $offset = 0; $offset < $total_items; $offset += $page ) {
			$items = $importer->read_cache_range( $items_path, $offset, min( $page, $total_items - $offset ) );

			if ( empty( $items ) ) {
				break;
			}

			$mapper->prime_terms_for_items( $items );
		}

		$this->logger->debug(
			'catalog',
			'Primed category/attribute terms before dispatching parallel catalog workers.',
			null,
			array( 'total_items' => $total_items )
		);
	}

	/**
	 * Splits a [0, $total) range into up to $workers contiguous, non-overlapping
	 * chunks as evenly as possible.
	 *
	 * @return array<int,array{0:int,1:int}> List of [start, count] pairs.
	 */
	private function split_range( int $total, int $workers ): array {
		$workers = max( 1, min( $workers, max( 1, $total ) ) );
		$base    = intdiv( $total, $workers );
		$extra   = $total % $workers;
		$ranges  = array();
		$offset  = 0;

		for ( $i = 0; $i < $workers; $i++ ) {
			$count = $base + ( $i < $extra ? 1 : 0 );

			if ( $count <= 0 ) {
				continue;
			}

			$ranges[] = array( $offset, $count );
			$offset  += $count;
		}

		return $ranges;
	}

	/**
	 * Returns how many parallel catalog worker actions are already queued or running.
	 */
	private function active_catalog_worker_count(): int {
		return $this->scheduled_action_count( self::HOOK_CATALOG_WORKER, 'pending' )
			+ $this->active_running_action_count( self::HOOK_CATALOG_WORKER );
	}

	/**
	 * Returns how many Action Scheduler workers catalog import may dispatch at once.
	 */
	private function catalog_parallel_workers(): int {
		$workers = max( 1, min( 8, (int) $this->settings->get( 'catalog_parallel_workers', 1 ) ) );

		return $this->is_low_memory_host() ? min( $workers, 5 ) : $workers;
	}

	/**
	 * Action Scheduler only allows one claimed batch of due actions to run at a
	 * time, site-wide, by default (filter default: 1). Whichever queue-runner
	 * process wins the race claims *every* due action in one go and processes
	 * them one by one in its own single PHP process before a second runner is
	 * even permitted to start (see has_maximum_concurrent_batches() in Action
	 * Scheduler core). That default is why parallel catalog/image workers,
	 * despite being dispatched as separate Action Scheduler actions, never
	 * actually ran at the same time -- raising this lets multiple
	 * concurrently-triggered runner processes each hold their own claim.
	 *
	 * @param mixed $current Action Scheduler's current filtered value.
	 */
	public function raise_action_scheduler_concurrency( mixed $current ): int {
		return max( (int) $current, $this->catalog_parallel_workers(), $this->image_parallel_workers() );
	}

	/**
	 * A single claim grabs up to this many due actions at once (Action
	 * Scheduler's default: 25), then processes them all sequentially in one
	 * PHP process regardless of how many *other* runner processes are also
	 * allowed to run concurrently. With e.g. 5 worker actions queued together,
	 * one claim would happily scoop up all 5 and quietly defeat the
	 * concurrency raise above. Capping this near 1 forces each
	 * concurrently-dispatched runner to claim at most a couple of actions, so
	 * they actually split the work instead of one runner doing it all anyway.
	 *
	 * @param mixed $current Action Scheduler's current filtered value.
	 */
	public function lower_action_scheduler_batch_size( mixed $current ): int {
		return min( (int) $current, 2 );
	}

	/**
	 * Fires $count near-simultaneous Action Scheduler async-runner loopback
	 * requests instead of relying on its natural self-dispatch, which
	 * throttles itself to at most one new runner every 60 seconds
	 * (ActionScheduler_QueueRunner::maybe_dispatch_async_request()) regardless
	 * of how many actions are due or how many times as_enqueue_async_action()
	 * is called. That single throttle -- not a hosting/loopback problem -- is
	 * why only one worker action progressed roughly once a minute no matter
	 * how many were dispatched at once.
	 */
	private function dispatch_concurrent_queue_runners( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->dispatch_queue_runner_ping();
		}
	}

	/**
	 * Fires one Action Scheduler async-runner loopback request immediately,
	 * bypassing its natural once-per-60-seconds self-dispatch throttle.
	 * Replicates ActionScheduler_AsyncRequest_QueueRunner's own dispatch call
	 * exactly (same admin-ajax action/nonce). Used both to burst-start a fresh
	 * wave of parallel workers and, from run_catalog_worker() itself, so a
	 * worker that pauses and requeues its own continuation wakes a runner
	 * right away instead of waiting on the same throttle -- since all workers
	 * in a wave tend to pause around the same moment, each firing its own ping
	 * reproduces the same concurrent burst that started them.
	 */
	private function dispatch_queue_runner_ping(): void {
		if ( ! function_exists( 'wp_create_nonce' ) ) {
			return;
		}

		$identifier = 'as_async_request_queue_runner';
		$url        = add_query_arg(
			array(
				'action' => $identifier,
				'nonce'  => wp_create_nonce( $identifier ),
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_post(
			esc_url_raw( $url ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Runs a Telesystem catalog import batch.
	 */
	public function run_telesystem_catalog_import( bool $queue_continuation = true ): array {
		$importer    = new Schrack_Telesystem_Importer( $this->settings, $this->logger );
		$limit       = $this->telesystem_batch_limit();
		$max_batches = $this->telesystem_batches_per_run();
		$started_at  = time();

		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( Schrack_Telesystem_Importer::STATUS_KEY, 0, 0 );
			}

			$result            = array();
			$total_processed   = 0;
			$total_errors      = 0;
			$total_prices      = 0;
			$total_stock       = 0;
			$total_image_urls  = 0;
			$batches           = 0;

			for ( $batch_index = 0; $batch_index < $max_batches; ++$batch_index ) {
				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( Schrack_Telesystem_Importer::STATUS_KEY, $total_processed, $total_errors );
				}

				if ( $batch_index > 0 && $this->should_pause_batch_run( $started_at, Schrack_Telesystem_Importer::STATUS_KEY ) ) {
					break;
				}

				$result = $importer->import_from_feed( $limit );
				++$batches;

				$total_processed  += (int) ( $result['processed'] ?? 0 );
				$total_errors     += (int) ( $result['errors'] ?? 0 );
				$total_prices     += (int) ( $result['prices_synced'] ?? 0 );
				$total_stock      += (int) ( $result['stock_synced'] ?? 0 );
				$total_image_urls += (int) ( $result['image_urls_seen'] ?? 0 );
				$this->release_batch_memory();

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( Schrack_Telesystem_Importer::STATUS_KEY, $total_processed, $total_errors );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( Schrack_Telesystem_Importer::STATUS_KEY, $total_processed, $total_errors );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at, Schrack_Telesystem_Importer::STATUS_KEY ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'                    => $total_processed,
					'errors'                       => $total_errors,
					'prices_synced'                => $total_prices,
					'stock_synced'                 => $total_stock,
					'image_urls_seen'              => $total_image_urls,
					'batches_processed'            => $batches,
					'telesystem_batches_per_run'   => $max_batches,
					'catalog_source'               => 'telesystem',
				),
				$this->memory_status_context()
			);

			$this->settings->update_status( Schrack_Telesystem_Importer::STATUS_KEY, $result );
			$this->logger->info( 'telesystem', 'Finished Telesystem catalog import run.', null, $result );

			if ( $queue_continuation ) {
				if ( $this->should_continue_batch( $result ) ) {
					$this->queue_next_batch_if_needed( self::HOOK_TELESYSTEM_CATALOG, Schrack_Telesystem_Importer::STATUS_KEY, $result );
				} elseif ( $this->should_import_images() ) {
					if ( ! $this->queue_sync_batch( self::HOOK_IMAGES, 'images', array(), array( 'source' => 'telesystem_catalog_completed' ) ) ) {
						$this->mark_queue_failed(
							'images',
							array(
								'processed'       => 0,
								'errors'          => 1,
								'completed_cycle' => 'no',
								'source'          => 'telesystem_catalog_completed',
							),
							self::HOOK_IMAGES,
							array()
						);
					}
				}
			}

			return $result;
		} catch ( Throwable $exception ) {
			$this->logger->error( 'telesystem', 'Telesystem catalog import batch failed.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( Schrack_Telesystem_Importer::STATUS_KEY, array( 'processed' => 0, 'errors' => 1 ) );
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
		if ( ! $this->is_schrack_enabled() ) {
			return $this->disabled_schrack_result( 'price' );
		}

		$sync  = new Schrack_Price_Sync( $this->settings, $this->logger );
		$limit = $this->sync_batch_limit();
		$max_batches = $this->sync_batches_per_run();
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
				$this->release_batch_memory();

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'price', $total_processed, $total_errors );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'price', $total_processed, $total_errors );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at, 'price' ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'           => $total_processed,
					'errors'              => $total_errors,
					'batches_processed'   => $batches,
					'sync_batches_per_run' => $max_batches,
				),
				$this->memory_status_context()
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
		if ( ! $this->is_schrack_enabled() ) {
			return $this->disabled_schrack_result( 'stock' );
		}

		$sync  = new Schrack_Stock_Sync( $this->settings, $this->logger );
		$limit = $this->sync_batch_limit();
		$max_batches = $this->sync_batches_per_run();
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
				$this->release_batch_memory();

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'stock', $total_processed, $total_errors );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'stock', $total_processed, $total_errors );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at, 'stock' ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'           => $total_processed,
					'errors'              => $total_errors,
					'batches_processed'   => $batches,
					'sync_batches_per_run' => $max_batches,
				),
				$this->memory_status_context()
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
		$sync             = new Schrack_Image_Sync( $this->settings, $this->logger );
		$limit            = $this->image_batch_limit();
		$parallel_workers = $this->image_parallel_workers();

		if ( ! $this->should_import_images() ) {
			return $sync->sync_batch( $limit );
		}

		if ( $parallel_workers > 1 && $this->can_queue_parallel_image_workers() ) {
			return $this->queue_parallel_image_sync( $sync, $limit, $parallel_workers, $queue_continuation );
		}

		$max_batches = $this->sync_batches_per_run();
		$started_at  = time();

		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( 'images', 0, 0 );
			}

			$result          = array();
			$total_processed = 0;
			$total_imported  = 0;
			$total_reused    = 0;
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
				$total_reused    += (int) ( $result['reused'] ?? 0 );
				$total_errors    += (int) ( $result['errors'] ?? 0 );
				$this->release_batch_memory();

				if ( $this->is_stopped_result( $result ) ) {
					return $this->handle_stopped_sync( 'images', $total_processed, $total_errors, array( 'imported' => $total_imported ) );
				}

				if ( $this->settings->is_stop_requested() ) {
					return $this->handle_stopped_sync( 'images', $total_processed, $total_errors, array( 'imported' => $total_imported ) );
				}

				if ( ! $this->should_continue_batch( $result ) || $this->should_pause_batch_run( $started_at, 'images' ) ) {
					break;
				}
			}

			$result = array_merge(
				$result,
				array(
					'processed'           => $total_processed,
					'imported'            => $total_imported,
					'reused'              => $total_reused,
					'errors'              => $total_errors,
					'batches_processed'   => $batches,
					'sync_batches_per_run' => $max_batches,
				),
				$this->memory_status_context()
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
	 * Dispatches one wave of parallel image import workers.
	 */
	private function queue_parallel_image_sync( Schrack_Image_Sync $sync, int $limit, int $workers, bool $queue_continuation ): array {
		try {
			if ( $this->settings->is_stop_requested() ) {
				return $this->handle_stopped_sync( 'images', 0, 0 );
			}

			$active_workers = $this->active_image_worker_count();

			if ( $active_workers > 0 ) {
				return $this->defer_parallel_image_dispatcher( $limit, $workers, $active_workers, $queue_continuation );
			}

			$claimed        = $sync->claim_parallel_batches( $limit, $workers );
			$chunks         = isset( $claimed['chunks'] ) && is_array( $claimed['chunks'] ) ? $claimed['chunks'] : array();
			$run_id         = (string) ( $claimed['run_id'] ?? '' );
			$queued_workers  = 0;
			$queued_products = 0;
			$failed_workers  = 0;
			$queue_errors    = 0;
			$stopped         = false;

			foreach ( $chunks as $index => $product_ids ) {
				if ( $this->settings->is_stop_requested() ) {
					$stopped = true;
					foreach ( array_slice( $chunks, $index ) as $remaining_product_ids ) {
						$sync->release_product_claims( is_array( $remaining_product_ids ) ? $remaining_product_ids : array(), $run_id );
					}
					break;
				}

				if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
					continue;
				}

				$product_ids = array_values( array_map( 'absint', $product_ids ) );
				$queued      = $this->queue_sync_batch(
					self::HOOK_IMAGE_WORKER,
					'images',
					array( $product_ids, $run_id, $index + 1 ),
					array(
						'run_id'       => $run_id,
						'worker_index' => $index + 1,
						'products'     => count( $product_ids ),
					),
					0
				);

				if ( ! $queued ) {
					$sync->release_product_claims( $product_ids, $run_id );
					++$failed_workers;
					$queue_errors += count( $product_ids );
					continue;
				}

				++$queued_workers;
				$queued_products += count( $product_ids );
			}

			$total_products   = absint( $claimed['total_products'] ?? 0 );
			$completed_cycle  = $stopped || $queue_errors > 0 ? 'no' : (string) ( $claimed['completed_cycle'] ?? 'yes' );
			$result           = array_merge(
				array(
					'processed'        => 0,
					'imported'         => 0,
					'attached'         => 0,
					'skipped'          => 0,
					'reused'           => 0,
					'errors'           => $queue_errors,
					'cursor'           => 0,
					'total_products'   => $total_products,
					'batch_start'      => 0,
					'batch_count'      => $queued_products,
					'batch_limit'      => max( 1, $limit ),
					'completed_cycle'  => $completed_cycle,
					'parallel'         => 'yes',
					'run_id'           => $run_id,
					'workers_requested' => $workers,
					'workers_queued'   => $queued_workers,
					'workers_failed'   => $failed_workers,
					'queued_products'  => $queued_products,
					'queue_errors'     => $queue_errors,
				),
				$this->memory_status_context()
			);

			if ( $stopped ) {
				$result['stopped'] = 'yes';
			}

			if ( $queue_errors > 0 ) {
				$result['queue_failed'] = 'yes';
			}

			$this->settings->update_status( 'images', $result );
			$this->logger->info( 'images', 'Queued parallel Schrack image sync workers.', null, $result );

			if ( ! $stopped && $queue_continuation && $queued_workers > 0 ) {
				if ( ! $this->queue_sync_batch(
					self::HOOK_IMAGES,
					'images',
					array(),
					array(
						'source'          => 'parallel_image_continuation',
						'run_id'          => $run_id,
						'queued_products' => $queued_products,
						'claimed_completed_cycle' => $completed_cycle,
					),
					$this->image_parallel_followup_delay()
				) ) {
					$this->mark_queue_failed( 'images', $result, self::HOOK_IMAGES, array() );
				}
			}

			return $result;
		} catch ( Throwable $exception ) {
			$this->logger->error( 'images', 'Failed to queue parallel Schrack image sync workers.', null, array( 'error' => $exception->getMessage() ) );
			$this->settings->update_status( 'images', array( 'processed' => 0, 'errors' => 1 ) );

			return array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
			);
		}
	}

	/**
	 * Runs one explicit parallel image worker batch.
	 *
	 * @param mixed  $product_ids Product IDs passed by Action Scheduler.
	 * @param string $run_id Parallel run ID.
	 * @return array<string,mixed>
	 */
	public function run_image_worker( mixed $product_ids = array(), string $run_id = '', int $worker_index = 0 ): array {
		$sync        = new Schrack_Image_Sync( $this->settings, $this->logger );
		$product_ids = is_array( $product_ids ) ? array_values( array_map( 'absint', $product_ids ) ) : array();

		try {
			if ( ! $this->should_import_images() ) {
				$result = $sync->sync_product_ids( $product_ids, $run_id );
				$result = array_merge(
					$result,
					array(
						'cursor'          => 0,
						'batch_start'     => 0,
						'batch_limit'     => count( $product_ids ),
						'completed_cycle' => 'yes',
						'parallel'        => 'yes',
						'worker_index'    => absint( $worker_index ),
					),
					$this->memory_status_context()
				);

				$this->settings->update_status( 'images', $result );
				$this->logger->info( 'images', 'Skipped Schrack image sync worker because image imports are disabled.', null, $result );

				return $result;
			}

			$result = $sync->sync_product_ids( $product_ids, $run_id );
			$result = array_merge(
				$result,
				array(
					'cursor'          => 0,
					'batch_start'     => 0,
					'batch_limit'     => count( $product_ids ),
					'completed_cycle' => (
						'yes' === (string) ( $result['stopped'] ?? 'no' )
						|| 'yes' === (string) ( $result['time_limited'] ?? 'no' )
					) ? 'no' : 'yes',
					'parallel'        => 'yes',
					'worker_index'    => absint( $worker_index ),
				),
				$this->memory_status_context()
			);

			$this->settings->update_status( 'images', $result );
			$this->logger->info( 'images', 'Finished Schrack image sync worker.', null, $result );

			return $result;
		} catch ( Throwable $exception ) {
			$sync->release_product_claims( $product_ids, $run_id );
			$this->logger->error(
				'images',
				'Schrack image sync worker failed.',
				null,
				array(
					'run_id'       => $run_id,
					'worker_index' => $worker_index,
					'error'        => $exception->getMessage(),
				)
			);
			$this->settings->update_status( 'images', array( 'processed' => 0, 'errors' => 1, 'parallel' => 'yes', 'run_id' => $run_id ) );

			return array(
				'processed'       => 0,
				'errors'          => 1,
				'completed_cycle' => 'yes',
				'parallel'        => 'yes',
				'run_id'          => $run_id,
			);
		}
	}

	/**
	 * Defers the image dispatcher until already queued/running image workers finish.
	 *
	 * @return array<string,mixed>
	 */
	private function defer_parallel_image_dispatcher( int $limit, int $workers, int $active_workers, bool $queue_continuation ): array {
		$status   = $this->settings->get_status();
		$last_row = isset( $status['images'] ) && is_array( $status['images'] ) ? $status['images'] : array();
		$result = array_merge(
			array(
				'processed'        => 0,
				'imported'         => 0,
				'attached'         => 0,
				'skipped'          => 0,
				'reused'           => 0,
				'errors'           => 0,
				'cursor'           => 0,
				'total_products'   => absint( $last_row['total_products'] ?? 0 ),
				'batch_start'      => 0,
				'batch_count'      => 0,
				'batch_limit'      => max( 1, $limit ),
				'completed_cycle'  => 'no',
				'parallel'         => 'yes',
				'waiting_workers'  => 'yes',
				'active_workers'   => $active_workers,
				'workers_requested' => $workers,
			),
			$this->memory_status_context()
		);

		$this->settings->update_status( 'images', $result );
		$this->logger->info( 'images', 'Deferred Schrack image dispatcher because image workers are still active.', null, $result );

		if ( $queue_continuation ) {
			if ( ! $this->queue_sync_batch(
				self::HOOK_IMAGES,
				'images',
				array(),
				array(
					'source'         => 'image_workers_active',
					'active_workers' => $active_workers,
				),
				$this->image_parallel_followup_delay()
			) ) {
				$this->mark_queue_failed( 'images', $result, self::HOOK_IMAGES, array() );
			}
		}

		return $result;
	}

	/**
	 * Runs catalog, price, and stock tasks.
	 */
	public function run_full_sync( string $stage = 'catalog' ): void {
		if ( ! $this->is_schrack_enabled() && ! $this->is_telesystem_enabled() ) {
			$this->disabled_schrack_result( 'full', 'Full sync is disabled: both Schrack and Telesystem are disabled.' );
			return;
		}

		$mode = (string) $this->settings->get( 'import_mode', 'catalog_price_stock' );

		if ( $this->settings->is_stop_requested() ) {
			$this->handle_stopped_sync( 'full', 0, 0, array( 'stage' => $stage ) );
			return;
		}

		if ( 'catalog' === $stage ) {
			if ( ! $this->is_schrack_enabled() ) {
				$this->advance_full_sync_stage( 'telesystem_catalog', array() );
				return;
			}

			$result = $this->run_catalog_import( false, false );

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

			$this->advance_full_sync_stage( 'telesystem_catalog', $result );

			return;
		}

		if ( 'telesystem_catalog' === $stage ) {
			if ( ! $this->is_telesystem_enabled() ) {
				$this->advance_full_sync_stage( 'price', array() );
				return;
			}

			$importer = new Schrack_Telesystem_Importer( $this->settings, $this->logger );
			$result   = $importer->import_from_feed( $this->telesystem_batch_limit() );

			if ( $this->is_stopped_result( $result ) ) {
				return;
			}

			if ( $this->should_continue_batch( $result ) ) {
				$this->queue_next_batch_if_needed( self::HOOK_FULL, 'full', $result, array( 'telesystem_catalog' ) );
				return;
			}

			$this->advance_full_sync_stage( 'price', $result );

			return;
		}

		if ( 'price' === $stage && $this->is_schrack_enabled() && in_array( $mode, array( 'catalog_price', 'catalog_price_stock' ), true ) ) {
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

			$this->advance_full_sync_stage( 'catalog_price_stock' === $mode ? 'stock' : ( $this->should_import_images() ? 'images' : '' ), $result );

			return;
		}

		if ( 'price' === $stage ) {
			// Schrack disabled, or the configured import mode does not include a price stage.
			$this->advance_full_sync_stage( $this->should_import_images() ? 'images' : '', array() );

			return;
		}

		if ( 'stock' === $stage && $this->is_schrack_enabled() && 'catalog_price_stock' === $mode ) {
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
				return;
			}

			$this->advance_full_sync_stage( $this->should_import_images() ? 'images' : '', $result );

			return;
		}

		if ( 'stock' === $stage ) {
			$this->advance_full_sync_stage( $this->should_import_images() ? 'images' : '', array() );

			return;
		}

		if ( 'images' === $stage && $this->should_import_images() ) {
			$this->run_image_sync( true );
		}
	}

	/**
	 * Queues the next full-sync stage (Schrack catalog -> Telesystem catalog ->
	 * price -> stock -> images), skipping stages whose supplier or import mode is
	 * disabled. A blank next stage means the run is finished.
	 *
	 * @param array<string,mixed> $result Last stage result, used only for failure logging.
	 */
	private function advance_full_sync_stage( string $next_stage, array $result ): void {
		if ( '' === $next_stage ) {
			return;
		}

		if ( ! $this->queue_sync_batch( self::HOOK_FULL, 'full', array( $next_stage ), array( 'next_stage' => $next_stage ) ) ) {
			$this->mark_queue_failed( 'full', $result, self::HOOK_FULL, array( $next_stage ) );
		}
	}

	/**
	 * Clears scheduled actions.
	 */
	public static function clear_scheduled_actions(): void {
		$hooks = array( self::HOOK_CATALOG, self::HOOK_CATALOG_WORKER, self::HOOK_TELESYSTEM_CATALOG, self::HOOK_PRICES, self::HOOK_STOCK, self::HOOK_FULL, self::HOOK_IMAGES, self::HOOK_IMAGE_WORKER, self::HOOK_CATEGORY_IMPORT );

		if ( class_exists( 'Schrack_Frontend_Image_Loader' ) ) {
			$hooks[] = Schrack_Frontend_Image_Loader::BACKGROUND_HOOK;
		}

		foreach ( $hooks as $hook ) {
			self::clear_hook_actions( $hook );
		}
	}

	/**
	 * Clears only Schrack-source catalog, price, stock, and full sync actions.
	 */
	private function clear_schrack_source_actions(): void {
		foreach ( array( self::HOOK_CATALOG, self::HOOK_CATALOG_WORKER, self::HOOK_PRICES, self::HOOK_STOCK, self::HOOK_FULL ) as $hook ) {
			if ( self::has_scheduled_hook( $hook ) ) {
				self::clear_hook_actions( $hook );
			}
		}
	}

	/**
	 * Returns whether Schrack SOAP-backed syncs are enabled.
	 */
	private function is_schrack_enabled(): bool {
		return 'yes' === (string) $this->settings->get( 'schrack_enabled', 'yes' );
	}

	/**
	 * Returns whether the Telesystem CSV feed sync is enabled.
	 */
	private function is_telesystem_enabled(): bool {
		return 'yes' === (string) $this->settings->get( 'telesystem_enabled', 'yes' );
	}

	/**
	 * Stores and returns a no-op status for a disabled sync task.
	 *
	 * @return array<string,mixed>
	 */
	private function disabled_schrack_result( string $status_key, string $message = 'Schrack sync is disabled.' ): array {
		$result = array(
			'processed'       => 0,
			'errors'          => 0,
			'completed_cycle' => 'yes',
			'disabled'        => 'yes',
			'message'         => $message,
		);

		$this->settings->update_status( $status_key, $result );
		$this->logger->info( $status_key, $message, null, $result );

		return $result;
	}

	/**
	 * Clears Action Scheduler and WP-Cron actions for one hook.
	 */
	private static function clear_hook_actions( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, null, self::GROUP );
		}

		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Returns whether a hook currently has Action Scheduler or WP-Cron work.
	 */
	private static function has_scheduled_hook( string $hook ): bool {
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
			return true;
		}

		return (bool) wp_next_scheduled( $hook );
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
	private function queue_next_batch_if_needed( string $hook, string $operation, array $result, array $args = array() ): bool {
		if ( ! $this->should_continue_batch( $result ) ) {
			return true;
		}

		$queued = $this->queue_sync_batch(
			$hook,
			$operation,
			$args,
			array(
				'cursor'      => $result['cursor'] ?? null,
				'batch_count' => $result['batch_count'] ?? null,
			)
		);

		if ( ! $queued ) {
			$this->mark_queue_failed( $operation, $result, $hook, $args );
		}

		return $queued;
	}

	/**
	 * Queues one follow-up sync action.
	 *
	 * @param array<int,mixed>    $args Hook arguments.
	 * @param array<string,mixed> $context Extra log context.
	 */
	private function queue_sync_batch( string $hook, string $operation, array $args = array(), array $context = array(), ?int $delay_override = null ): bool {
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
			return true;
		}

		$queued       = false;
		$action_id    = 0;
		$queue_runner = '';

		if ( 0 === $sleep && function_exists( 'as_enqueue_async_action' ) ) {
			$action_id    = absint( as_enqueue_async_action( $hook, $args, self::GROUP ) );
			$queued       = $action_id > 0;
			$queue_runner = 'action_scheduler_async';
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id    = absint( as_schedule_single_action( time() + max( 1, $sleep ), $hook, $args, self::GROUP ) );
			$queued       = $action_id > 0;
			$queue_runner = 'action_scheduler_single';
		} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id    = absint( as_enqueue_async_action( $hook, $args, self::GROUP ) );
			$queued       = $action_id > 0;
			$queue_runner = 'action_scheduler_async';
		} else {
			$queued       = false !== wp_schedule_single_event( time() + max( 5, $sleep ), $hook, $args );
			$queue_runner = 'wp_cron';
		}

		if ( ! $queued ) {
			$this->logger->error(
				$operation,
				'Failed to queue next Schrack sync batch.',
				null,
				array_merge(
					array(
						'hook'         => $hook,
						'next_args'    => $args,
						'sleep'        => $sleep,
						'queue_runner' => $queue_runner,
					),
					$context
				)
			);

			return false;
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
					'queue_runner' => $queue_runner,
					'action_id' => $action_id,
				),
				$context
			)
		);

		return true;
	}

	/**
	 * Queues a delayed retry after Schrack throttles SOAP messages.
	 *
	 * @param array<int,mixed>    $args Hook arguments.
	 * @param array<string,mixed> $result Rate-limited result.
	 */
	private function queue_rate_limited_batch( string $hook, string $operation, array $args, array $result ): void {
		$cooldown = max( 30, (int) ( $result['cooldown_seconds'] ?? $this->rate_limit_cooldown() ) );

		if ( ! $this->queue_sync_batch(
			$hook,
			$operation,
			$args,
			array(
				'rate_limited' => 'yes',
				'retry_after'  => $result['retry_after'] ?? null,
			),
			$cooldown
		) ) {
			$this->mark_queue_failed( $operation, $result, $hook, $args );
		}
	}

	/**
	 * Marks a still-incomplete sync as blocked by a queueing failure.
	 *
	 * @param array<string,mixed> $result Last batch result.
	 * @param array<int,mixed>    $args Hook arguments.
	 */
	private function mark_queue_failed( string $operation, array $result, string $hook, array $args ): void {
		$this->settings->update_status(
			$operation,
			array_merge(
				$result,
				array(
					'completed_cycle' => 'no',
					'queue_failed'    => 'yes',
					'queue_hook'      => $hook,
					'queue_args'      => $args,
				)
			)
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
		$this->settings->pause_soap( $cooldown, $operation, $error );
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
		return max( 300, min( 3600, (int) $this->settings->get( 'soap_rate_limit_cooldown', 600 ) ) );
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
	private function should_pause_batch_run( int $started_at, string $operation ): bool {
		if ( $this->is_memory_pressure_high() ) {
			$this->logger->warning(
				$operation,
				'Paused Schrack sync run because PHP memory usage is near the configured memory limit.',
				null,
				$this->memory_status_context()
			);

			return true;
		}

		$max_execution_time = (int) ini_get( 'max_execution_time' );

		if ( $max_execution_time <= 0 ) {
			return false;
		}

		return time() - $started_at >= max( 10, $max_execution_time - 10 );
	}

	/**
	 * Returns the effective catalog batch size for the current hosting memory limit.
	 */
	private function catalog_batch_limit(): int {
		$limit = max( 1, min( 5000, (int) $this->settings->get( 'catalog_batch_size', 500 ) ) );

		return $this->is_low_memory_host() ? min( $limit, 500 ) : $limit;
	}

	/**
	 * Returns the effective Telesystem CSV batch size.
	 */
	private function telesystem_batch_limit(): int {
		$limit = max( 1, min( 5000, (int) $this->settings->get( 'telesystem_batch_size', 500 ) ) );

		return $this->is_low_memory_host() ? min( $limit, 500 ) : $limit;
	}

	/**
	 * Returns the effective SOAP-backed product batch size.
	 */
	private function sync_batch_limit(): int {
		$limit = max( 1, min( 500, (int) $this->settings->get( 'sync_batch_size', 100 ) ) );

		return $this->is_low_memory_host() ? 100 : $limit;
	}

	/**
	 * Returns the effective image batch size.
	 */
	private function image_batch_limit(): int {
		$limit = max( 1, min( 250, (int) $this->settings->get( 'image_batch_size', 50 ) ) );

		return $this->is_low_memory_host() ? min( $limit, 100 ) : $limit;
	}

	/**
	 * Returns how many catalog batches one PHP request may process.
	 */
	private function catalog_batches_per_run(): int {
		$max_batches = max( 1, min( 20, (int) $this->settings->get( 'catalog_batches_per_run', 1 ) ) );

		// Catalog import is now streamed and cache-backed, so 2 GB hosts can safely chain
		// several batches -- should_pause_batch_run() already bails on real memory/time
		// pressure per batch, so this ceiling only needs to stop runaway configuration,
		// not second-guess hosts that measure well under their memory limit.
		return $this->is_low_memory_host() ? min( max( $max_batches, 3 ), 15 ) : $max_batches;
	}

	/**
	 * Returns how many Telesystem feed batches one PHP request may process.
	 */
	private function telesystem_batches_per_run(): int {
		$max_batches = max( 1, min( 20, (int) $this->settings->get( 'telesystem_batches_per_run', 3 ) ) );

		// See catalog_batches_per_run() for why this ceiling was raised.
		return $this->is_low_memory_host() ? min( max( $max_batches, 3 ), 15 ) : $max_batches;
	}

	/**
	 * Returns how many price/stock/image batches one PHP request may process.
	 */
	private function sync_batches_per_run(): int {
		$max_batches = max( 1, min( 20, (int) $this->settings->get( 'sync_batches_per_run', 1 ) ) );

		return $this->is_low_memory_host() ? min( $max_batches, 1 ) : $max_batches;
	}

	/**
	 * Detects small shared-hosting style memory limits.
	 */
	private function is_low_memory_host(): bool {
		$limit = $this->memory_limit_bytes();

		return $limit > 0 && $limit <= 2 * 1024 * 1024 * 1024;
	}

	/**
	 * Returns whether the current request should hand off to the next Action Scheduler run.
	 */
	private function is_memory_pressure_high(): bool {
		$limit = $this->memory_limit_bytes();

		if ( $limit <= 0 ) {
			return false;
		}

		return memory_get_usage( true ) >= (int) floor( $limit * 0.70 );
	}

	/**
	 * Returns memory diagnostics for status rows and logs.
	 *
	 * @return array<string,mixed>
	 */
	private function memory_status_context(): array {
		$limit = $this->memory_limit_bytes();
		$usage = memory_get_usage( true );
		$peak  = memory_get_peak_usage( true );
		$context = array(
			'memory_usage_mb' => round( $usage / 1048576, 2 ),
			'memory_peak_mb'  => round( $peak / 1048576, 2 ),
			'memory_safe_mode' => $this->is_low_memory_host() ? 'yes' : 'no',
		);

		if ( $limit > 0 ) {
			$context['memory_limit_mb'] = round( $limit / 1048576, 2 );
			$context['memory_usage_pct'] = round( ( $usage / $limit ) * 100, 2 );
			$context['memory_peak_pct'] = round( ( $peak / $limit ) * 100, 2 );
		}

		return $context;
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
	 * Releases runtime-only caches between batches on small-memory hosting.
	 */
	private function release_batch_memory(): void {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
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
	 * Returns whether the same follow-up action is already pending soon.
	 *
	 * @param array<int,mixed> $args Hook arguments.
	 */
	private function has_active_followup_action( string $hook, array $args, int $window = 60 ): bool {
		$next_run = $this->next_pending_scheduled_timestamp( $hook, $args );

		return null !== $next_run && $next_run <= time() + max( 0, $window );
	}

	/**
	 * Returns the next pending Action Scheduler/WP-Cron timestamp for an exact hook/args pair.
	 *
	 * Unlike as_next_scheduled_action(), this intentionally ignores the current in-progress
	 * action so one running batch cannot block its own follow-up batch.
	 *
	 * @param array<int,mixed>|null $args Optional exact hook arguments.
	 */
	private function next_pending_scheduled_timestamp( string $hook, ?array $args = null ): ?int {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$query = array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 20,
				'orderby'  => 'date',
				'order'    => 'ASC',
			);

			if ( null !== $args ) {
				$query['args'] = $args;
			}

			try {
				$actions = as_get_scheduled_actions( $query, 'ids' );
			} catch ( Throwable ) {
				$query['return_format'] = 'ids';

				try {
					$actions = as_get_scheduled_actions( $query );
				} catch ( Throwable ) {
					return null;
				}
			}

			if ( ! is_array( $actions ) ) {
				return null;
			}

			$next = null;

			foreach ( $actions as $action ) {
				$timestamp = is_numeric( $action )
					? $this->scheduled_action_timestamp( (int) $action )
					: $this->scheduled_action_object_timestamp( $action );

				if ( null === $timestamp ) {
					continue;
				}

				if ( null === $next || $timestamp < $next ) {
					$next = $timestamp;
				}
			}

			return $next;
		}

		$timestamp = null === $args ? wp_next_scheduled( $hook ) : wp_next_scheduled( $hook, $args );

		return false === $timestamp || null === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * Returns a scheduled Action Scheduler action timestamp by ID.
	 */
	private function scheduled_action_timestamp( int $action_id ): ?int {
		if ( $action_id <= 0 || ! class_exists( 'ActionScheduler_Store' ) || ! method_exists( 'ActionScheduler_Store', 'instance' ) ) {
			return null;
		}

		try {
			$store = ActionScheduler_Store::instance();

			if ( ! is_object( $store ) || ! method_exists( $store, 'fetch_action' ) ) {
				return null;
			}

			return $this->scheduled_action_object_timestamp( $store->fetch_action( $action_id ) );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Extracts a timestamp from an Action Scheduler action object.
	 */
	private function scheduled_action_object_timestamp( mixed $action ): ?int {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_schedule' ) ) {
			return null;
		}

		try {
			$schedule = $action->get_schedule();

			if ( ! is_object( $schedule ) || ! method_exists( $schedule, 'get_date' ) ) {
				return null;
			}

			return $this->scheduled_date_timestamp( $schedule->get_date() );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Normalizes Action Scheduler date values.
	 */
	private function scheduled_date_timestamp( mixed $date ): ?int {
		if ( $date instanceof DateTimeInterface ) {
			return $date->getTimestamp();
		}

		if ( is_numeric( $date ) ) {
			return (int) $date;
		}

		if ( is_string( $date ) && '' !== trim( $date ) ) {
			$timestamp = strtotime( $date );

			return false === $timestamp ? null : $timestamp;
		}

		return null;
	}

	/**
	 * Counts Action Scheduler actions genuinely still running for a hook.
	 *
	 * A batch is bounded by should_pause_batch_run() and always yields well
	 * before PHP's max_execution_time, so a claimed action that is still
	 * "in-progress" long after that can only mean the worker process died
	 * (fatal error, OOM kill, host timeout) without Action Scheduler noticing --
	 * its own stuck-claim cleanup depends on WP-Cron ticking, which is not
	 * guaranteed. Without this, active_sync_conflict() would treat that zombie
	 * action as a permanently running sync and block every future manual sync
	 * request. Ignoring in-progress actions past a generous staleness window
	 * lets the plugin route around them instead of getting stuck forever.
	 */
	private function active_running_action_count( string $hook, int $stale_after = 20 * MINUTE_IN_SECONDS ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return $this->scheduled_action_count( $hook, 'in-progress' );
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'          => $hook,
				'group'         => self::GROUP,
				'status'        => 'in-progress',
				'per_page'      => 50,
				'return_format' => 'ids',
			)
		);

		if ( ! is_array( $actions ) ) {
			return 0;
		}

		$now   = time();
		$count = 0;

		foreach ( $actions as $action_id ) {
			$timestamp = $this->scheduled_action_timestamp( (int) $action_id );

			if ( null === $timestamp || ( $now - $timestamp ) < $stale_after ) {
				++$count;
			}
		}

		return $count;
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
	 * @return array<string,array{hook:string,label:string,extra_hooks?:array<int,string>}>
	 */
	private function task_definitions(): array {
		$image_extra_hooks = array( self::HOOK_IMAGE_WORKER );

		if ( class_exists( 'Schrack_Frontend_Image_Loader' ) ) {
			$image_extra_hooks[] = Schrack_Frontend_Image_Loader::BACKGROUND_HOOK;
		}

		return array(
			'catalog' => array(
				'hook'  => self::HOOK_CATALOG,
				'label' => __( 'Catalog', 'schrack-woocommerce-sync' ),
				'extra_hooks' => array( self::HOOK_CATALOG_WORKER ),
			),
			'telesystem_catalog' => array(
				'hook'  => self::HOOK_TELESYSTEM_CATALOG,
				'label' => __( 'Telesystem catalog', 'schrack-woocommerce-sync' ),
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
				'extra_hooks' => $image_extra_hooks,
			),
			'category_import' => array(
				'hook'  => self::HOOK_CATEGORY_IMPORT,
				'label' => __( 'Category CSV import', 'schrack-woocommerce-sync' ),
			),
		);
	}

	/**
	 * Returns all hooks that belong to a queue status definition.
	 *
	 * @param array<string,mixed> $definition Task definition.
	 * @return array<int,string>
	 */
	private function definition_hooks( array $definition ): array {
		$hooks = array( (string) $definition['hook'] );

		foreach ( (array) ( $definition['extra_hooks'] ?? array() ) as $hook ) {
			if ( is_string( $hook ) && '' !== $hook ) {
				$hooks[] = $hook;
			}
		}

		return array_values( array_unique( $hooks ) );
	}

	/**
	 * Returns whether media-library image import should run after catalog data.
	 */
	private function should_import_images(): bool {
		return 'yes' === $this->settings->get( 'image_import_enabled', 'yes' );
	}

	/**
	 * Returns how many Action Scheduler workers image sync may dispatch at once.
	 */
	private function image_parallel_workers(): int {
		$workers = max( 1, min( 8, (int) $this->settings->get( 'image_parallel_workers', 2 ) ) );

		return $this->is_low_memory_host() ? min( $workers, 4 ) : $workers;
	}

	/**
	 * Returns how many image worker actions are already queued or running.
	 */
	private function active_image_worker_count(): int {
		return $this->scheduled_action_count( self::HOOK_IMAGE_WORKER, 'pending' )
			+ $this->active_running_action_count( self::HOOK_IMAGE_WORKER );
	}

	/**
	 * Returns whether Action Scheduler can run worker batches independently.
	 */
	private function can_queue_parallel_image_workers(): bool {
		return function_exists( 'as_enqueue_async_action' ) || function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Delay before the next image dispatcher wave checks for more pending products.
	 */
	private function image_parallel_followup_delay(): int {
		$delay = max( 5, min( 300, (int) $this->settings->get( 'image_parallel_followup_delay', 10 ) ) );

		return $this->is_low_memory_host() ? max( $delay, 10 ) : $delay;
	}
}
