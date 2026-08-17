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
		WP_CLI::success( 'Lotul de import al catalogului Schrack s-a încheiat.' );
	}

	/**
	 * Imports a Telesystem feed batch.
	 *
	 * ## OPTIONS
	 *
	 * [--drain]
	 * : Continuă ciclurile de import Telesystem în acest proces WP-CLI până la importarea
	 * completă a fluxului, fără întârzierea dintre acțiunile Action Scheduler. Fiecare
	 * ciclu procesează până la valoarea configurată pentru loturile Telesystem per rulare.
	 *
	 * [--max-batches=<count>]
	 * : Oprește modul continuu după acest număr de cicluri. Omite sau folosește 0 pentru nelimitat.
	 *
	 * [--time-limit=<seconds>]
	 * : Oprește modul continuu după acest număr de secunde. Omite sau folosește 0 pentru nelimitat.
	 */
	public function telesystem( array $args = array(), array $assoc_args = array() ): void {
		if ( isset( $assoc_args['drain'] ) ) {
			$max_runs   = isset( $assoc_args['max-batches'] ) ? absint( $assoc_args['max-batches'] ) : 0;
			$time_limit = isset( $assoc_args['time-limit'] ) ? absint( $assoc_args['time-limit'] ) : 0;
			$deadline   = $time_limit > 0 ? time() + $time_limit : 0;
			$runs       = 0;
			$totals     = array(
				'processed'     => 0,
				'errors'        => 0,
				'prices_synced' => 0,
				'stock_synced'  => 0,
			);
			$result     = array();

			while ( 0 === $max_runs || $runs < $max_runs ) {
				if ( $deadline > 0 && time() >= $deadline ) {
					break;
				}

				$result = $this->cron->run_telesystem_catalog_import( false );
				++$runs;

				foreach ( array_keys( $totals ) as $key ) {
					$totals[ $key ] += absint( $result[ $key ] ?? 0 );
				}

				if (
					'yes' === (string) ( $result['completed_cycle'] ?? 'yes' )
					|| 'yes' === (string) ( $result['stopped'] ?? 'no' )
				) {
					break;
				}
			}

			WP_CLI::success(
				sprintf(
					'Procesarea continuă Telesystem s-a încheiat. Rulări: %d, procesate: %d, erori: %d, prețuri sincronizate: %d, stocuri sincronizate: %d, finalizat: %s.',
					$runs,
					$totals['processed'],
					$totals['errors'],
					$totals['prices_synced'],
					$totals['stock_synced'],
					(string) ( $result['completed_cycle'] ?? 'no' )
				)
			);
			return;
		}

		$this->cron->run_telesystem_catalog_import();
		WP_CLI::success( 'Lotul de import al catalogului Telesystem s-a încheiat.' );
	}

	/**
	 * Syncs price batch.
	 */
	public function prices(): void {
		$this->cron->run_price_sync();
		WP_CLI::success( 'Lotul de sincronizare a prețurilor Schrack s-a încheiat.' );
	}

	/**
	 * Syncs stock batch.
	 */
	public function stock(): void {
		$this->cron->run_stock_sync();
		WP_CLI::success( 'Lotul de sincronizare a stocurilor Schrack s-a încheiat.' );
	}

	/**
	 * Imports an image batch.
	 *
	 * ## OPTIONS
	 *
	 * [--drain]
	 * : Continuă procesarea loturilor de imagini în acest proces WP-CLI până când nu mai există sarcini în așteptare.
	 *
	 * [--batch-size=<count>]
	 * : Produse preluate per lot în modul continuu. Implicit folosește dimensiunea configurată a lotului de imagini.
	 *
	 * [--max-batches=<count>]
	 * : Oprește modul continuu după acest număr de loturi. Omite sau folosește 0 pentru nelimitat.
	 *
	 * [--time-limit=<seconds>]
	 * : Oprește modul continuu după acest număr de secunde. Omite sau folosește 0 pentru nelimitat.
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

			if ( 'yes' === (string) ( $result['disabled'] ?? 'no' ) ) {
				WP_CLI::success( 'Sincronizarea imaginilor Schrack este dezactivată. URL-urile externe rămân salvate pe produse.' );
				return;
			}

			WP_CLI::success(
				sprintf(
					'Procesarea continuă a imaginilor Schrack s-a încheiat. Loturi: %d, procesate: %d, importate: %d, reutilizate: %d, erori: %d, finalizat: %s.',
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
		if ( 'yes' === (string) ( $result['disabled'] ?? 'no' ) ) {
			WP_CLI::success( 'Sincronizarea imaginilor Schrack este dezactivată. URL-urile externe rămân salvate pe produse.' );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Lotul de sincronizare a imaginilor Schrack s-a încheiat. Produse în coadă: %d, procesate: %d, importate: %d, erori: %d.',
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
		WP_CLI::success( 'Lotul de sincronizare completă Schrack s-a încheiat.' );
	}

	/**
	 * Stops queued and running sync work.
	 */
	public function stop(): void {
		$result = $this->cron->stop_actions();
		WP_CLI::success(
			sprintf(
				'Oprirea sincronizării Schrack a fost solicitată. Au fost anulate %d acțiuni din coadă; %d acțiuni în curs se vor opri la următorul punct de control.',
				absint( $result['pending_cancelled'] ?? 0 ),
				absint( $result['running'] ?? 0 )
			)
		);
	}
}
