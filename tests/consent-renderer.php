<?php
/** Native WordPress escaping/tag parsing; no database, credentials or network. */
require __DIR__ . '/frontend-lazy-images.php';
require_once ABSPATH . WPINC . '/script-loader.php';
foreach ( array( 'class-wp-dependency.php', 'class-wp-dependencies.php', 'class-wp-scripts.php' ) as $file ) { require_once ABSPATH . WPINC . '/' . $file; }
require_once __DIR__ . '/../includes/class-schrack-consent-renderer.php';
define( 'COOKIEADMIN_VERSION', '1.2.2' );
function is_preview(): bool { return false; }
function current_theme_supports( string $feature, mixed ...$args ): bool { return true; }
function plugins_url( string $path = '' ): string { return 'https://shop.example/plugins/' . $path; }
$GLOBALS['wp_scripts'] = (new ReflectionClass(WP_Scripts::class))->newInstanceWithoutConstructor();
$scripts = wp_scripts();
$scripts->add( 'cookieadmin_js', plugins_url('cookieadmin/assets/js/consent.js'), array(), '1.2.2' );
$scripts->add( 'cookieadmin_pro_js', plugins_url('cookieadmin-pro/assets/js/consent.js'), array(), '1.2.2' );
$scripts->enqueue( array('cookieadmin_js', 'cookieadmin_pro_js') );
$policy = array( 'cookieadmin_layout'=>'box', 'cookieadmin_geo_tgt'=>'www', 'cookieadmin_position'=>'bottom_left', 'cookieadmin_notice_title'=>'Preferințe cookie', 'cookieadmin_notice'=>'Necesar pentru magazin. <a href="/privacy/">Detalii</a>', 'cookieadmin_customize_btn'=>'Personalizează', 'cookieadmin_reject_btn'=>'Respinge tot', 'cookieadmin_accept_btn'=>'Acceptă tot', 'is_pro'=>'cookieadmin-pro/cookieadmin-pro.php' );
$pro = array('respect_gpc'=>'');
$markup = '<div class="cookieadmin_law_container"><div class="cookieadmin_consent_inside"><p id="cookieadmin_notice_title"></p><div class="cookieadmin_notice_con"><p id="cookieadmin_notice"></p></div><div class="cookieadmin_consent_btns"><button id="cookieadmin_customize_button">Customize</button><button id="cookieadmin_reject_button">Reject All</button><button id="cookieadmin_accept_button">Accept All</button></div><div class="cookieadmin-poweredby">Native attribution</div></div></div><div class="cookieadmin_cookie_modal">Native preference controls</div>';
function renderer( array $policy, array $pro ): Schrack_Consent_Renderer {
 $scripts = wp_scripts();
 $scripts->add_data('cookieadmin_js','data','var cookieadmin_policy = '.wp_json_encode($policy).';');
 $scripts->add_data('cookieadmin_pro_js','data','var cookieadmin_pro_vars = '.wp_json_encode($pro).';');
 $r = new Schrack_Consent_Renderer(); $r->configure(); return $r;
}
$r = renderer($policy,$pro); $result = $r->render($markup);
verify_image(str_contains($result,'Preferințe cookie</p>') && str_contains($result,'<a href="/privacy/">Detalii</a>'), 'Existing localized text and allowed legal links are in server markup.');
verify_image(str_contains($result,'cookieadmin_box') && str_contains($result,'cookieadmin_bottom cookieadmin_left') && !str_contains($result,'cookieadmin_bottom_left'), 'Native separate position classes anchor the box before script initialization.');
foreach (array('bottom_right','top_left','top_right') as $position) {
 $positionPolicy=$policy;$positionPolicy['cookieadmin_position']=$position;
 $positionResult=renderer($positionPolicy,$pro)->render($markup);
 [$vertical,$horizontal]=explode('_',$position);
 verify_image(str_contains($positionResult,'cookieadmin_'.$vertical.' cookieadmin_'.$horizontal), 'Each supported corner has both native position classes before first paint.');
}
verify_image(str_contains($result,'Respinge tot</button>') && str_contains($result,'Acceptă tot</button>'), 'All original button IDs/classes retain localized labels.');
verify_image(str_contains($result,'Native attribution') && str_contains($result,'Native preference controls'), 'Attribution and preference controls remain unchanged.');
verify_image(!str_contains($result,'display:block') && !str_contains($result,'<script'), 'Cached markup contains no visitor choice and shows nothing before browser consent checks.');
ob_start();$r->bootstrap();$js=ob_get_clean();
verify_image(str_contains($js,'schrack-early-consent-banner') && str_contains($js,'data-no-optimize="1"'), 'Small cache-neutral bootstrap bypasses script deferral.');
foreach (array('cookieadmin_layout'=>'popup','cookieadmin_geo_tgt'=>'eu','cookieadmin_position'=>'custom','cookieadmin_notice'=>'') as $key=>$value) {
 $changed=$policy;$changed[$key]=$value; $r=renderer($changed,$pro);verify_image($markup===$r->render($markup),'Unsupported layouts/geo/custom positions/empty text retain the full native contract.');
}
foreach(array('1','0','false',true) as $gpc){$r=renderer($policy,array('respect_gpc'=>$gpc));verify_image($markup===$r->render($markup),'Every JS-truthy GPC setting must keep native initialization.');}
$r=renderer($policy,array());verify_image($markup===$r->render($markup),'Unknown Pro configuration keeps native initialization.');
$modified=$policy;$modified['cookieadmin_notice']='<img src="x" onerror="bad()"><script>bad()</script>';$modified['cookieadmin_accept_btn']='<script>unsafe</script>';
$result=renderer($modified,$pro)->render($markup);verify_image(!str_contains($result,'onerror=')&&!str_contains($result,'<script>')&&str_contains($result,'&lt;script&gt;unsafe'), 'Configured HTML is escaped/sanitized for each context.');
$r=renderer($policy,$pro);$custom=str_replace('<p id="cookieadmin_notice"></p>','<p id="cookieadmin_notice">Already populated</p>',$markup);verify_image($custom===$r->render($custom),'Custom populated templates must not be overwritten.');
$scripts->registered['cookieadmin_js']->src='https://cdn.example/consent.js';$r=renderer($policy,$pro);verify_image($markup===$r->render($markup),'Replaced vendor scripts retain native rendering.');
$scripts->registered['cookieadmin_js']->src=plugins_url('cookieadmin/assets/js/consent.js');
add_filter('schrack_wc_sync_early_consent_banner','__return_false');$r=renderer($policy,$pro);verify_image($markup===$r->render($markup),'Rollback restores native markup and startup.');
$logo_renderer = new Schrack_Consent_Renderer();
$logo_url = 'https://shop.example/uploads/schrack-frontend-cache/consent-assets/known.svg';
(new ReflectionProperty($logo_renderer,'brand_image'))->setValue($logo_renderer,$logo_url);
$sanitized_logo = wp_kses_post('<img src="'.$logo_url.'" width="90" height="15" alt="" loading="lazy" decoding="async" fetchpriority="low">');
$restored_logo = $logo_renderer->brand_image_attributes($sanitized_logo);
verify_image(str_contains($restored_logo,'decoding="async"')&&str_contains($restored_logo,'fetchpriority="low"'),'Only our cached brand image restores loading hints after native sanitization.');
verify_image($restored_logo===$logo_renderer->brand_image_attributes($restored_logo),'Restoring exact brand hints is idempotent.');
foreach(array(str_replace('known.svg','custom.svg',$sanitized_logo),str_replace('loading="lazy"','loading="eager"',$sanitized_logo))as $other){verify_image($other===$logo_renderer->brand_image_attributes($other),'Other images and deliberate eager loading retain original attributes.');}
add_filter('schrack_wc_sync_cache_consent_brand','__return_false');
verify_image($sanitized_logo===$logo_renderer->brand_image_attributes($sanitized_logo),'Brand rollback also retains original final HTML attributes.');
remove_filter('schrack_wc_sync_cache_consent_brand','__return_false');
echo "Consent renderer total: {$checks} checks passed.\n";
