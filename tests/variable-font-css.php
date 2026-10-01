<?php
/** Actual public font bytes + Elementor CSS; no WordPress boot, database or HTTP. */
require __DIR__ . '/frontend-performance.php';
$font_directory = $argv[6] ?? '';
$css_file = $argv[7] ?? '';
if ( ! is_file( $css_file ) ) { throw new RuntimeException( 'Pass the inspected Figtree font directory and original Elementor CSS as arguments six and seven.' ); }
$root = sys_get_temp_dir() . '/schrack-variable-font-' . bin2hex( random_bytes( 6 ) );
$fonts = $root . '/elementor/google-fonts/fonts';
mkdir( $fonts, 0700, true );
$css = str_replace( 'https://shop.syshub.ro/wp-content/uploads/', 'https://shop.example/uploads/', file_get_contents( $css_file ) );
$uploads = array( 'basedir' => $root, 'baseurl' => 'https://shop.example/uploads' );
$compact = new ReflectionMethod( $performance, 'compact_figtree_font_faces' );
try {
	foreach ( array( '70ed8904', '3530fccd', '467cc915', '3c512d8e' ) as $suffix ) {
		$name = 'figtree-' . $suffix . '.woff2';
		if ( ! copy( $font_directory . '/' . $name, $fonts . '/' . $name ) ) { throw new RuntimeException( 'Missing native font fixture: ' . $name ); }
	}
	$result = $compact->invoke( $performance, $css, $uploads );
	verify_image( 28 === substr_count( $css, '@font-face' ) && 4 === substr_count( $result, '@font-face' ), 'The complete native CSS becomes four faces for four genuine variable fonts.' );
	verify_image( strlen( $css ) - strlen( $result ) > 10000 && 4 === substr_count( $result, 'font-weight:300 900;' ), 'The saved critical CSS retains the complete verified weight range.' );
	foreach ( array( 'font-style: italic;', 'font-style: normal;', 'U+0100-02BA', 'U+0000-00FF', 'font-display:optional;' ) as $descriptor ) {
		verify_image( str_contains( $result, $descriptor ), 'Original style, Latin ranges and display mode remain: ' . $descriptor );
	}
	preg_match_all( '~url\(([^)]+)\)~', $css, $original_urls );
	preg_match_all( '~url\(([^)]+)\)~', $result, $compact_urls );
	verify_image( array_values( array_unique( $original_urls[1] ) ) === $compact_urls[1], 'Every native URL remains in its original order; no extra downloads are added.' );
	foreach ( array( $css . 'body{font-size:14px}', str_replace( 'font-weight: 900;', 'font-weight: 800;', $css ), str_replace( 'font-weight: 300;', 'font-weight: 200;', $css ), str_replace( 'figtree-70ed8904', 'figtree-aaaaaaaa', $css ) ) as $unknown ) {
		verify_image( $unknown === $compact->invoke( $performance, $unknown, $uploads ), 'Unknown rules, weights, duplicate weights or changed font filenames retain original CSS.' );
	}
	$file = $fonts . '/figtree-70ed8904.woff2';
	$bytes = file_get_contents( $file );
	file_put_contents( $file, $bytes . 'changed' ); clearstatcache( true, $file );
	verify_image( $css === $compact->invoke( $performance, $css, $uploads ), 'Replacing font bytes preserves native CSS instead of applying an unverified variable range.' );
	unlink( $file ); clearstatcache( true, $file );
	verify_image( $css === $compact->invoke( $performance, $css, $uploads ), 'Missing fonts retain the native declarations.' );
	file_put_contents( $file, $bytes ); clearstatcache( true, $file );
	verify_image( $result === $compact->invoke( $performance, $result, $uploads ), 'Already compact CSS is unchanged.' );
	$css_directory = dirname( $fonts ) . '/css'; mkdir( $css_directory, 0700 );
	file_put_contents( $css_directory . '/figtree.css', $css );
	$GLOBALS['uploads_test'] = $uploads + array( 'error' => false, 'path' => $root, 'url' => $uploads['baseurl'], 'subdir' => '' );
	$GLOBALS['front_page_test'] = true;
	( new ReflectionProperty( $performance, 'catalog_inline_bytes' ) )->setValue( $performance, 0 );
	$url = $uploads['baseurl'] . '/elementor/google-fonts/css/figtree.css';
	$tag = '<link rel="stylesheet" href="' . $url . '">';
	$integrated = $performance->inline_local_font_style( $tag, 'elementor-gf-local-figtree', $url );
	verify_image( 4 === substr_count( $integrated, '@font-face' ) && str_contains( $integrated, 'data-schrack-optional-font="figtree"' ), 'Verified catalog output combines compact declarations with the existing LiteSpeed font marker.' );
	add_filter( 'schrack_wc_sync_compact_figtree_fonts', '__return_false' );
	verify_image( 28 === substr_count( $performance->inline_local_font_style( $tag, 'elementor-gf-local-figtree', $url ), '@font-face' ), 'Rollback keeps complete original declarations while retaining optional display.' );
	remove_filter( 'schrack_wc_sync_compact_figtree_fonts', '__return_false' );
	$GLOBALS['preview_test'] = true;
	verify_image( $tag === $performance->inline_local_font_style( $tag, 'elementor-gf-local-figtree', $url ), 'Editor previews retain native font loading.' );
	$GLOBALS['preview_test'] = false; $GLOBALS['front_page_test'] = false;
	echo "Variable font CSS total: {$checks} checks passed.\n";
} finally {
	if ( isset( $css_directory ) ) { unlink( $css_directory . '/figtree.css' ); rmdir( $css_directory ); }
	foreach ( glob( $fonts . '/*' ) ?: array() as $file ) { unlink( $file ); }
	rmdir( $fonts ); rmdir( dirname( $fonts ) ); rmdir( dirname( dirname( $fonts ) ) ); rmdir( $root );
}
