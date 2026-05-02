<?php
/**
 * Plugin Name: Schrack WooCommerce Sync
 * Description: Imports Schrack catalog data and synchronizes purchase prices and stock with WooCommerce products.
 * Version: 0.1.15
 * Author: Schrack WooCommerce Sync
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: schrack-woocommerce-sync
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCHRACK_WC_SYNC_VERSION', '0.1.15' );
define( 'SCHRACK_WC_SYNC_FILE', __FILE__ );
define( 'SCHRACK_WC_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCHRACK_WC_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-plugin.php';

register_activation_hook( __FILE__, array( 'Schrack_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Schrack_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Schrack_Plugin::instance()->init();
	}
);
