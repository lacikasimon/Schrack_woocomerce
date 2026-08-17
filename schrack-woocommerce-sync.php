<?php
/**
 * Plugin Name: Importator produse furnizori
 * Description: Importă datele cataloagelor furnizorilor și sincronizează prețurile de achiziție și stocurile produselor WooCommerce.
 * Version: 0.1.70
 * Author: Syshub
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: schrack-woocommerce-sync
 *
 * @package SchrackWooCommerceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCHRACK_WC_SYNC_VERSION', '0.1.70' );
define( 'SCHRACK_WC_SYNC_FILE', __FILE__ );
define( 'SCHRACK_WC_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCHRACK_WC_SYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Returns the Romanian form of a legacy English or Hungarian plugin string.
 *
 * @param string $text Original text.
 * @return string
 */
function schrack_wc_sync_romanian_text( string $text ): string {
	static $translations = null;
	if ( null === $translations ) {
		$translations = require SCHRACK_WC_SYNC_PATH . 'includes/schrack-romanian-ui.php';
	}

	if ( isset( $translations[ $text ] ) ) {
		return $translations[ $text ];
	}

	if ( preg_match( '/^Created (.+) product\.$/', $text, $matches ) ) {
		return sprintf( 'Produs %s creat.', $matches[1] );
	}

	if ( preg_match( '/^Updated (.+) product\.$/', $text, $matches ) ) {
		return sprintf( 'Produs %s actualizat.', $matches[1] );
	}

	if ( preg_match( '/^Line (\d+):\s*(.+)$/', $text, $matches ) ) {
		return sprintf( 'Rândul %1$d: %2$s', (int) $matches[1], schrack_wc_sync_romanian_text( $matches[2] ) );
	}

	return $text;
}

/**
 * Forces the plugin's legacy English and Hungarian gettext strings to Romanian.
 *
 * @param string $translation Current translation.
 * @param string $text        Original source text.
 * @param string $domain      Text domain.
 * @return string
 */
function schrack_wc_sync_romanian_ui( string $translation, string $text, string $domain ): string {
	return 'schrack-woocommerce-sync' === $domain ? schrack_wc_sync_romanian_text( $text ) : $translation;
}

add_filter( 'gettext', 'schrack_wc_sync_romanian_ui', 10, 3 );

require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-plugin.php';

register_activation_hook( __FILE__, array( 'Schrack_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Schrack_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Schrack_Plugin::instance()->init();
	}
);
