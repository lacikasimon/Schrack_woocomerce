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
	 */
	public function images(): void {
		$this->cron->run_image_sync();
		WP_CLI::success( 'Schrack image sync batch finished.' );
	}

	/**
	 * Runs full sync batch.
	 */
	public function full(): void {
		$this->cron->run_full_sync();
		WP_CLI::success( 'Schrack full sync batch finished.' );
	}
}
