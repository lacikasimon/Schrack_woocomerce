<?php
/** Local filesystem fixtures only: no WordPress bootstrap, network or production writes. */
define( 'ABSPATH', '/source-only/' );
require __DIR__ . '/../includes/class-schrack-consent-renderer.php';
function apply_filters( string $name, mixed $value ): mixed { return $GLOBALS['rollback'] ?? $value; }
function wp_upload_dir( mixed $time, bool $create ): array { return $GLOBALS['uploads']; }
function wp_mkdir_p( string $directory ): bool { return ! is_file( $directory ) && ( is_dir( $directory ) || mkdir( $directory, 0700, true ) ); }
function esc_url( string $url ): string { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
$svg = file_get_contents( $argv[1] ?? '' );
if ( ! is_string( $svg ) || hash( 'sha256', $svg ) !== 'c54148c69663f803341e944d6b858209d9f875ecda7fe75d43fe99056f6517bb' ) { throw new RuntimeException( 'Pass the exact unmodified native CookieAdmin 1.2.2 logo SVG fixture.' ); }
$directory = sys_get_temp_dir() . '/schrack-consent-brand-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory, 0700 );
$GLOBALS['uploads'] = array( 'basedir' => $directory, 'baseurl' => 'https://shop.example/uploads', 'error' => false );
$prefix = '<div class="cookieadmin-poweredby"><a href="https://cookieadmin.net/?utm_source=wpplugin&amp;utm_medium=footer" target="_blank"><span>Propulsat de</span> ';
$html = $prefix . $svg . '</a></div><div>Unrelated</div>' . $prefix . $svg . '</a></div>';
$method = new ReflectionMethod( Schrack_Consent_Renderer::class, 'cache_brand_image' );
$render = static fn( string $input ): string => $method->invoke( new Schrack_Consent_Renderer(), $input );
$target = $directory . '/schrack-frontend-cache/consent-assets/' . hash( 'sha256', $svg ) . '.svg';
$checks = 0;
function verify_brand( bool $ok, string $message ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
try {
	$output = $render( $html );
	verify_brand( 2 === substr_count( $output, '<img ') && ! str_contains( $output, 'data:image/png;base64' ), 'Both native copies use the same external asset without inline bitmaps.' );
	verify_brand( is_file( $target ) && file_get_contents( $target ) === $svg, 'Static SVG preserves every original native byte, including its viewBox and bitmaps.' );
	verify_brand( 2 === substr_count( $output, $prefix ) && str_contains( $output, '<div>Unrelated</div>' ), 'Original links, localized attribution and adjacent markup remain unchanged.' );
	verify_brand( 2 === substr_count( $output, 'width="90" height="15" alt="" loading="lazy" decoding="async" fetchpriority="low"' ), 'Fixed layout and noncritical loading hints apply to both identical logos.' );
	verify_brand( $output === $render( $output ), 'Already optimized HTML is unchanged.' );
	touch( $target, 1000000000 ); $render( $html ); clearstatcache( true, $target );
	verify_brand( 1000000000 === filemtime( $target ), 'A verified cache file is reused without rewriting it.' );
	file_put_contents( $target, 'Incomplete cache' ); $render( $html );
	verify_brand( $svg === file_get_contents( $target ), 'Corrupted owned cache files recover atomically.' );
	verify_brand( array() === glob( dirname( $target ) . '/.brand-*' ), 'Atomic writes leave no temporary files behind.' );
	$modified = str_replace( 'width="90"', 'width="91"', $html );
	verify_brand( $modified === $render( $modified ), 'Changed vendor/custom SVG sources retain their original rendering.' );
	verify_brand( $svg === $render( $svg ), 'The logo outside the native attribution container is untouched.' );
	$GLOBALS['rollback'] = false;
	verify_brand( $html === $render( $html ), 'Rollback retains the original inline SVG contract.' );
	unset( $GLOBALS['rollback'] ); $GLOBALS['uploads']['error'] = 'Unavailable';
	verify_brand( $html === $render( $html ), 'Unavailable uploads keep the complete native logo.' );
	$GLOBALS['uploads']['error'] = false;
	unlink( $target ); symlink( __FILE__, $target );
	verify_brand( $html === $render( $html ), 'A symlink target cannot be read or overwritten by the asset cache.' );
	unlink( $target ); rmdir( dirname( $target ) );
	symlink( $directory, dirname( $target ) );
	verify_brand( $html === $render( $html ), 'A symlink cache directory retains native markup.' );
	unlink( dirname( $target ) );
} finally {
	if ( is_link( $target ) || is_file( $target ) ) { unlink( $target ); }
	if ( is_link( dirname( $target ) ) ) { unlink( dirname( $target ) ); }
	if ( is_dir( dirname( $target ) ) ) { rmdir( dirname( $target ) ); }
	if ( is_dir( $directory . '/schrack-frontend-cache' ) ) { rmdir( $directory . '/schrack-frontend-cache' ); }
	rmdir( $directory );
}
echo "Consent brand cache: {$checks} checks passed.\n";
