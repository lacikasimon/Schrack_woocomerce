<?php
/**
 * WP-CLI commands.
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schrack_WP_CLI {
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
	 * Cron service.
	 *
	 * @var Schrack_Cron
	 */
	private Schrack_Cron $cron;

	/**
	 * Constructor.
	 */
	public function __construct( Schrack_Settings $settings, Schrack_Logger $logger, Schrack_Cron $cron ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->cron     = $cron;
	}

	/**
	 * Registers WP-CLI command.
	 */
	public static function register( Schrack_Settings $settings, Schrack_Logger $logger, Schrack_Cron $cron ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'schrack-sync', new self( $settings, $logger, $cron ) );
		}
	}

	/**
	 * Imports a catalog batch.
	 */
	public function catalog(): void {
		$this->cron->run_catalog_import();
		WP_CLI::success( 'Schrack catalog import batch finished.' );
	}

	/**
	 * Syncs price batch.
	 */
	public function prices(): void {
		$this->cron->run_price_sync();
		WP_CLI::success( 'Schrack price sync batch finished.' );
	}

	/**
	 * Syncs stock batch.
	 */
	public function stock(): void {
		$this->cron->run_stock_sync();
		WP_CLI::success( 'Schrack stock sync batch finished.' );
	}

	/**
	 * Imports an image batch.
	 *
	 * ## OPTIONS
	 *
	 * [--drain]
	 * : Keep processing image batches in this WP-CLI process until there is no pending work.
	 *
	 * [--batch-size=<count>]
	 * : Products to claim per batch in drain mode. Defaults to the image batch size setting.
	 *
	 * [--max-batches=<count>]
	 * : Stop drain mode after this many batches. Omit or pass 0 for no batch limit.
	 *
	 * [--time-limit=<seconds>]
	 * : Stop drain mode after this many seconds. Omit or pass 0 for no time limit.
	 */
	public function images( array $args = array(), array $assoc_args = array() ): void {
		if ( isset( $assoc_args['drain'] ) ) {
			$batch_size = isset( $assoc_args['batch-size'] )
				? absint( $assoc_args['batch-size'] )
				: absint( $this->settings->get( 'image_batch_size', 50 ) );
			$max_batches = isset( $assoc_args['max-batches'] ) ? absint( $assoc_args['max-batches'] ) : 0;
			$time_limit  = isset( $assoc_args['time-limit'] ) ? absint( $assoc_args['time-limit'] ) : 0;
			$sync        = new Schrack_Image_Sync( $this->settings, $this->logger );
			$result      = $sync->sync_until_idle( $batch_size, $max_batches, $time_limit );

			WP_CLI::success(
				sprintf(
					'Schrack image drain finished. Batches: %d, processed: %d, imported: %d, reused: %d, errors: %d, complete: %s.',
					absint( $result['batches_processed'] ?? 0 ),
					absint( $result['processed'] ?? 0 ),
					absint( $result['imported'] ?? 0 ),
					absint( $result['reused'] ?? 0 ),
					absint( $result['errors'] ?? 0 ),
					(string) ( $result['completed_cycle'] ?? 'no' )
				)
			);
			return;
		}

		$result = $this->cron->run_image_sync();
		WP_CLI::success(
			sprintf(
				'Schrack image sync batch finished. Queued products: %d, processed: %d, imported: %d, errors: %d.',
				absint( $result['queued_products'] ?? ( $result['batch_count'] ?? 0 ) ),
				absint( $result['processed'] ?? 0 ),
				absint( $result['imported'] ?? 0 ),
				absint( $result['errors'] ?? 0 )
			)
		);
	}

	/**
	 * Runs full sync batch.
	 */
	public function full(): void {
		$this->cron->run_full_sync();
		WP_CLI::success( 'Schrack full sync batch finished.' );
	}

	/**
	 * Stops queued and running sync work.
	 */
	public function stop(): void {
		$result = $this->cron->stop_actions();
		WP_CLI::success(
			sprintf(
				'Schrack sync stop requested. Cancelled %d queued action(s); %d running action(s) will stop at the next checkpoint.',
				absint( $result['pending_cancelled'] ?? 0 ),
				absint( $result['running'] ?? 0 )
			)
		);
	}
}
