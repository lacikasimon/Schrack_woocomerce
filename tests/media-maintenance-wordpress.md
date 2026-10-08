# Disposable media maintenance test

Never run this test against a live database. It inserts and removes its own image
and product fixtures. It refuses any home/siteurl other than
`http://127.0.0.1:18944`, requires WooCommerce and disabled WP-Cron, rejects supplier
credentials, and requires the complete importer plugin to be inactive.

Use a disposable WordPress/WooCommerce installation bound to localhost port 18944,
with PHP 8.1+, GD or Imagick and a non-default database prefix. Create a test-only
administrator named `media_admin`. Install the repository as
`wp-content/plugins/schrack-woocommerce-sync`; keep the plugin inactive. Put this
small loader in a test-only MU plugin:

```php
<?php
if ( 'http://127.0.0.1:18944' !== get_option( 'home' ) ) { return; }
define( 'SCHRACK_WC_SYNC_PATH', WP_PLUGIN_DIR . '/schrack-woocommerce-sync/' );
define( 'SCHRACK_WC_SYNC_URL', plugins_url( 'schrack-woocommerce-sync/' ) );
define( 'SCHRACK_WC_SYNC_VERSION', '0.1.153' );
foreach ( array( 'memory-guard', 'product-hero-cache', 'media-maintenance' ) as $part ) {
    require_once SCHRACK_WC_SYNC_PATH . 'includes/class-schrack-' . $part . '.php';
}
( new Schrack_Media_Maintenance() )->init();
add_action( 'admin_menu', static function() {
    add_submenu_page( 'woocommerce', 'Performanță magazin', 'Performanță magazin',
        'manage_woocommerce', 'schrack-performance', static function() {
            echo '<div class="wrap"><h1>Performanță magazin</h1>';
            do_action( 'schrack_performance_tools' );
            echo '</div>';
        } );
} );
```

Give the web/PHP user write access to the private parent of the WordPress directory.
With separate CLI/web containers, share that parent as well as the WordPress files.
Use no production credentials and do not enable any supplier synchronization.

Run from the test WordPress root:

```sh
wp eval-file wp-content/plugins/schrack-woocommerce-sync/tests/media-maintenance-wordpress.php exercise
```

This exercises real image editors and two MariaDB/MySQL connections. Fixtures
include absent metadata, a valid 5000×5000 JPEG, unknown source attribution, corrupt
and absent originals, exact file copies with different sources, shared sources
with different contents, product/gallery/category/content/Elementor references,
and an injected CDN failure. HTTP is intercepted during the test so no supplier
requests or credentials are needed. Assertions cover bounded previews, private
pre-write backups, unchanged original hashes and core metadata, worker exclusivity,
pause while a worker owns the lock, checkpoint resumption, old queued workers,
Action Scheduler successors, watchdog cleanup, insufficient memory, concurrent
same-source import reuse, the retained `medium` size, capabilities and nonces.

Run `seed` instead of `exercise` to leave fresh unrepaired fixtures for a manual UI
check. During browser repair tests, intercept the official CDN preset with the
1190×1330 `cdn` fixture path stored in the test-only option
`schrack_media_test_fixture`; preserve streaming to the supplied request filename.
Return a test `WP_Error` for `f_failure.jpg`. Keep this interceptor out of production.

Check the native Media grid, list and product image picker, then the new performance
section. Confirm that the original is available through its separate download/file
URL while grid thumbnails are <=300 px and details <=1024 px. Check scan, pause,
resume and repair through the actual buttons, including visible error messages.
Closing the admin tab must not lose the cursor. Compare image resource payloads,
request destinations and timing with the preview adapter temporarily disabled in
this test bootstrap, then enabled. Giant original URLs must not appear among
preview requests. Never disable the production adapter for this comparison.

Standalone JavaScript regressions:

```sh
node --test tests/admin-media.js
```

The initial local Chrome comparison used nine attachments, including three 4 MB
5000×5000 originals. Native preview image payloads totaled 13,100,006 bytes; bounded
previews totaled 62,114 bytes. Giant preview requests fell from three to zero.
Last image response ended at 561 ms vs 231 ms; HTML response took 118 ms vs 82 ms.
These are single local fixture measurements with browser caching, not a production
benchmark or a promised server response improvement. Measure the live site after
normal Git deployment; the audit itself does not measure live server latency.
