<?php
/** The public render does not need editor-only choice labels. No database. */
namespace Elementor {
	class Widget_Base {}
	class Plugin { public static $instance; }
}
namespace {
	define( 'ABSPATH', __DIR__ );
	$admin = false; $editing = false; $calls = 0;
	function is_admin() { return $GLOBALS['admin']; }
	function __( $text, $domain = '' ) { return $text; }
	function taxonomy_exists( $name ) { return true; }
	function is_wp_error( $value ) { return false; }
	class WP_Term { public $term_id = 12; public $name = 'Iluminat'; public $count = 30; }
	function get_terms( $args ) { $GLOBALS['calls']++; return array( new WP_Term() ); }
	\Elementor\Plugin::$instance = (object) array( 'editor' => new class { public function is_edit_mode() { return $GLOBALS['editing']; } } );
	require __DIR__ . '/../includes/widgets/class-schrack-elementor-featured-categories-widget.php';
	$widget = new Schrack_Elementor_Featured_Categories_Widget();
	$method = new \ReflectionMethod( $widget, 'category_options' );
	if ( array() !== $method->invoke( $widget ) || 0 !== $calls ) { throw new \RuntimeException( 'Public requests must not scan editor categories.' ); }
	$editing = true;
	if ( array( 12 => 'Iluminat (30)' ) !== $method->invoke( $widget ) ) { throw new \RuntimeException( 'Frontend editor must retain category choices.' ); }
	$editing = false; $admin = true;
	if ( array( 12 => 'Iluminat (30)' ) !== $method->invoke( $widget ) || 2 !== $calls ) { throw new \RuntimeException( 'Admin/AJAX editor must retain choices.' ); }
	require __DIR__ . '/../includes/widgets/class-schrack-elementor-product-filter-widget.php';
	$widget = new Schrack_Elementor_Product_Filter_Widget();
	$method = new \ReflectionMethod( $widget, 'category_options' );
	$admin = false; $calls = 0;
	if ( array( '' => 'Toate categoriile' ) !== $method->invoke( $widget ) || 0 !== $calls ) { throw new \RuntimeException( 'Public filter controls must not scan the category catalogue.' ); }
	$editing = true;
	$expected = array( '' => 'Toate categoriile', 12 => 'Iluminat' );
	if ( $expected !== $method->invoke( $widget ) ) { throw new \RuntimeException( 'Frontend filter editor must retain category labels.' ); }
	$editing = false; $admin = true;
	if ( $expected !== $method->invoke( $widget ) || 2 !== $calls ) { throw new \RuntimeException( 'Admin filter editor must retain all choices.' ); }
	echo "Elementor category options: 6 checks passed.\n";
}
