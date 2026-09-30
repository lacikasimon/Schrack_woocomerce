<?php
/** Reuse Elementor's saved CSS/assets without constructing template documents. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Schrack_Elementor_Assets {
	public function init(): void { add_action( 'wp_enqueue_scripts', array( $this, 'prepare' ), 8 ); }
	public function prepare(): void {
		if ( is_admin() || is_preview() || ! defined( 'ELEMENTOR_PRO_VERSION' ) || '4.2.3' !== ELEMENTOR_PRO_VERSION
			|| ! defined( 'ELEMENTOR_VERSION' ) || '4.2.4' !== ELEMENTOR_VERSION
			|| ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' )
			|| ! apply_filters( 'schrack_wc_sync_elementor_saved_assets', true )
			|| isset( $_GET['theme_template_id'] ) || isset( $_GET['elementor-preview'] )
			|| 'elementor_library' === get_post_type( get_the_ID() ) ) { return; }
		if ( ! ( is_front_page() || ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product' ) && is_product() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) ) { return; }
		$elementor = \Elementor\Plugin::instance();
		if ( $elementor->editor->is_edit_mode() || $elementor->preview->is_preview_mode() ) { return; }
		$template = get_page_template_slug( get_queried_object_id() );
		// These templates suppress locations; keep Elementor's own filtering there.
		if ( ! in_array( $template, array( false, '', 'default' ), true ) ) { return; }
		$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
		$manager = $module->get_locations_manager(); $conditions = $module->get_conditions_manager();
		if ( ! method_exists( $manager, 'get_locations' ) || ! method_exists( $conditions, 'get_location_templates' ) ) { return; }
		$original = array( $manager, 'enqueue_styles' ); $priority = has_action( 'wp_enqueue_scripts', $original );
		if ( false === $priority || $priority < 8 ) { return; }
		$plan = array(); $current = get_the_ID();
		foreach ( $manager->get_locations() as $location => $settings ) {
			// Native condition checks still execute for every request and user context.
			foreach ( $conditions->get_location_templates( $location ) as $id => $weight ) {
				if ( $current !== (int) $id ) {
					$key = \Elementor\Core\Base\Elements_Iteration_Actions\Assets::ASSETS_META_KEY;
					if ( ! metadata_exists( 'post', $id, $key ) || ! metadata_exists( 'post', $id, '_elementor_css' ) ) { return; }
					$assets = get_post_meta( $id, $key, true );
					if ( ! is_array( $assets ) ) { return; }
					$plan[] = array( 'id' => (int) $id, 'assets' => $assets );
				}
				if ( empty( $settings['multiple'] ) ) { break; }
			}
		}
		if ( ! $plan ) { return; }
		remove_action( 'wp_enqueue_scripts', $original, $priority );
		add_action( 'wp_enqueue_scripts', static function() use ( $plan, $elementor ): void {
			do_action( 'schrack_performance_profile_mark', 'elementor_saved_assets_start' );
			$css = array();
			foreach ( $plan as $entry ) {
				do_action( 'elementor/post/render', $entry['id'] );
				$css[] = new \Elementor\Core\Files\CSS\Post( $entry['id'] );
				if ( $entry['assets'] ) { $elementor->assets_loader->enable_assets( $entry['assets'] ); }
			}
			$elementor->frontend->enqueue_styles();
			do_action( 'schrack_performance_profile_mark', 'elementor_saved_css_start' );
			foreach ( $css as $file ) { $file->enqueue(); }
			do_action( 'schrack_performance_profile_mark', 'elementor_saved_assets_end' );
		}, $priority );
	}
}
