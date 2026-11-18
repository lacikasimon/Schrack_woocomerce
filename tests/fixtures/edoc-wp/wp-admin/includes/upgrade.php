<?php
/** Isolated dbDelta stand-in. Real schema migration is covered by edoc-v11-wordpress.php. */
function dbDelta( $sql ) {
	$GLOBALS['edoc_schema_sql'][] = $sql;
	if ( str_contains( $sql, 'kind varchar(16)' ) ) {
		foreach ( $GLOBALS['wpdb']->commands as &$row ) { $row += array( 'kind' => 'status' ); }
	}
}
