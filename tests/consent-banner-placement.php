<?php
/** Native CookieAdmin source placement tests; no database, HTTP or plugin bootstrap.
 * php tests/consent-banner-placement.php /path/to/wordpress /path/to/cookieadmin-parent
 */
namespace CookieAdmin {
 function get_option($key, $default = null) { return 'cookieadmin_gdpr'; }
 function cookieadmin_load_policy() { return array('cookieadmin_gdpr' => $GLOBALS['policy']); }
 function cookieadmin_load_consent_template($policy, $view) { ++$GLOBALS['native_banner_calls']; return array($GLOBALS['markup']); }
 function cookieadmin_kses_allowed_html() { return \wp_kses_allowed_html('post'); }
}
namespace {
 require __DIR__ . '/consent-renderer.php';
 remove_filter('schrack_wc_sync_early_consent_banner', '__return_false');
 $native_root = $argv[2] ?? '';
 if (!is_file($native_root . '/cookieadmin/includes/enduser.php')) { throw new \RuntimeException('Pass the unmodified official CookieAdmin source parent as the second argument.'); }
 define('WP_PLUGIN_DIR', $native_root);
 require WP_PLUGIN_DIR . '/cookieadmin/includes/enduser.php';
 $callback = '\\CookieAdmin\\Enduser::cookieadmin_show_banner';
 $GLOBALS['native_banner_calls'] = 0;
 $r = renderer($policy, $pro); $r->init();
 add_action('wp_footer', $callback, 10);
 ob_start(); do_action('wp_body_open'); echo '<main>Catalog</main>'; do_action('wp_footer'); $output = ob_get_clean();
 verify_image(1 === $GLOBALS['native_banner_calls'], 'Original vendor renderer must execute exactly once across both theme hooks.');
 verify_image(strpos($output, 'cookieadmin_law_container') < strpos($output, '<main>Catalog'), 'Native banner markup precedes the large catalog body.');
 verify_image(str_contains($output, 'Preferințe cookie') && str_contains($output, 'Native preference controls'), 'Original vendor sanitization, local notice and modal controls remain present.');
 verify_image(1 === substr_count($output, 'id="schrack-early-consent-banner"'), 'Early first-visitor bootstrap executes once, never again in the footer.');
 verify_image(false === has_action('wp_footer', $callback), 'Moved native footer callback cannot output a second consent panel.');
 remove_action('wp_body_open', array($r, 'body_banner'), 1); remove_filter('cookieadmin_after_banner', array($r, 'render'), 20); remove_action('wp_footer', array($r, 'bootstrap'), 11);
 foreach (array('missing_body_hook', 'rollback', 'unexpected_priority') as $case) {
  $r = renderer($policy, $pro); $r->init();
  $priority = $case === 'unexpected_priority' ? 12 : 10;
  add_action('wp_footer', $callback, $priority);
  if ($case === 'rollback') { add_filter('schrack_wc_sync_body_consent_banner', '__return_false'); }
  $before = $GLOBALS['native_banner_calls'];
  ob_start(); if ($case !== 'missing_body_hook') { do_action('wp_body_open'); } $early = ob_get_clean();
  verify_image('' === $early && $priority === has_action('wp_footer', $callback), 'Unsupported hook/priority or rollback preserves native footer output.');
  ob_start(); do_action('wp_footer'); $footer = ob_get_clean();
  verify_image($before + 1 === $GLOBALS['native_banner_calls'] && str_contains($footer, 'cookieadmin_law_container'), 'Footer fallback still renders the original native banner once.');
  remove_filter('schrack_wc_sync_body_consent_banner', '__return_false'); remove_action('wp_footer', $callback, $priority);
  remove_action('wp_body_open', array($r, 'body_banner'), 1); remove_filter('cookieadmin_after_banner', array($r, 'render'), 20); remove_action('wp_footer', array($r, 'bootstrap'), 11);
 }
 echo 'Native banner placement total: ' . $GLOBALS['checks'] . " checks passed.\n";
}
