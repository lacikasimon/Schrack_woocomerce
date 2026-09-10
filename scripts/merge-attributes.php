<?php
/**
 * Standalone entry point, loaded with WP-CLI's --require (not from a browser).
 *
 * wp --require=/path/to/plugin/scripts/merge-attributes.php schrack-merge-attributes
 * wp --require=/path/to/plugin/scripts/merge-attributes.php schrack-merge-attributes --apply --backup=/private/path/before-attributes.sql
 *
 * Deploy the updated plugin too: its mapper honors the saved redirects.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run this script through WP-CLI --require. See scripts/merge-attributes.md.\n";
	exit( 1 );
}

require_once __DIR__ . '/../includes/class-schrack-attribute-merger.php';
WP_CLI::add_command( 'schrack-merge-attributes', array( 'Schrack_Attribute_Merger', 'command' ) );
