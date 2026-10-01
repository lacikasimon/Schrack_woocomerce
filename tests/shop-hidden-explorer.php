<?php
/** Native hook tests against WordPress source; no bootstrap, database or credentials. */
$wordpress = $argv[1] ?? '';
if ( ! is_file( $wordpress . '/wp-includes/plugin.php' ) ) { throw new RuntimeException( 'Pass the WordPress source directory.' ); }
define( 'ABSPATH', $wordpress . '/' );
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/shortcodes.php';
function is_admin(): bool { return $GLOBALS['admin_test'] ?? false; }
function is_shop(): bool { return $GLOBALS['shop_test'] ?? false; }
function is_page( mixed $page ): bool { return $GLOBALS['page_test'] ?? false; }
function is_product_category(): bool { return $GLOBALS['category_test'] ?? false; }
function get_queried_object(): mixed { return $GLOBALS['queried_test'] ?? null; }
function taxonomy_exists( string $taxonomy ): bool { ++$GLOBALS['taxonomy_reads']; return false; }
require __DIR__ . '/../includes/class-schrack-product-filter-renderer.php';
require __DIR__ . '/../includes/class-schrack-registration-renderer.php';
require __DIR__ . '/../includes/class-schrack-account-renderer.php';
require __DIR__ . '/../includes/class-schrack-header-search-renderer.php';
require __DIR__ . '/../includes/class-schrack-elementor.php';
$renderer = new Schrack_Product_Filter_Renderer();
$elementor = new Schrack_Elementor( $renderer );
$elementor->init();
$explorer = new ReflectionMethod( $renderer, 'category_explorer' );
$checks = 0;
function verify_explorer( bool $ok, string $message ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
$GLOBALS['shop_test'] = true;
$GLOBALS['taxonomy_reads'] = 0;
$classes = $elementor->shop_archive_body_class( array() );
verify_explorer( in_array( 'schrack-shop-main-page', $classes, true ) && in_array( 'schrack-shop-has-intro', $classes, true ), 'The skipped shop is precisely the body-class pair hidden by existing CSS.' );
verify_explorer( '' === $explorer->invoke( $renderer, array() ) && 0 === $GLOBALS['taxonomy_reads'], 'Hidden navigation returns before every taxonomy/count/link operation.' );
$rollback = static fn() => false;
add_filter( 'schrack_wc_sync_skip_hidden_shop_explorer', $rollback );
$explorer->invoke( $renderer, array() );
verify_explorer( 1 === $GLOBALS['taxonomy_reads'], 'Rollback restores native taxonomy processing.' );
remove_filter( 'schrack_wc_sync_skip_hidden_shop_explorer', $rollback );
$GLOBALS['shop_test'] = false;
foreach ( array( array(), array( 'admin_test' => true ), array( 'category_test' => true ) ) as $context ) {
	$GLOBALS['admin_test'] = $context['admin_test'] ?? false;
	$GLOBALS['category_test'] = $context['category_test'] ?? false;
	$before = $GLOBALS['taxonomy_reads'];
	$explorer->invoke( $renderer, array() );
	verify_explorer( $GLOBALS['taxonomy_reads'] === $before + 1, 'Other pages, category archives and admin/AJAX retain native navigation processing.' );
}
$GLOBALS['admin_test'] = false; $GLOBALS['category_test'] = false;
$GLOBALS['queried_test'] = (object) array( 'post_name' => 'shop-3' );
$before = $GLOBALS['taxonomy_reads']; $explorer->invoke( $renderer, array() );
verify_explorer( $GLOBALS['taxonomy_reads'] === $before, 'The supported custom shop page uses the same hidden-body contract.' );
$GLOBALS['admin_test'] = true; $explorer->invoke( $renderer, array() );
verify_explorer( $GLOBALS['taxonomy_reads'] === $before + 1, 'Admin/AJAX requests retain native processing even with a shop object.' );
verify_explorer( false === $elementor->category_explorer_visible( false ), 'Earlier visibility filters stay authoritative.' );
echo "Hidden shop explorer: {$checks} checks passed.\n";
