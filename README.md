# Product furnizor importer

Professional WooCommerce plugin skeleton for importing supplier products and synchronizing purchase prices and stock quantities.

## Scope

This plugin handles only:

- Catalog import from Schrack SOAP `GetCatalogAs`, including streamed detailed XML properties, facets, and technical documents.
- Separate Telesystem CSV feed import from the configured B2B feed URL.
- Selected eDoc ERP articles as a third supplier, with availability and net supplier prices.
- An authenticated eDoc order mirror and status bridge, isolated from the supplier SOAP client.
- Purchase price lookup through `GetItemPrice`.
- Stock lookup through `GetStockItemQuantities`.
- WooCommerce simple product create/update by SKU.
- Category based markup and rounding.

It must not be used for supplier order submission. Order related SOAP methods, including `InsertUpdateOrder`, are intentionally not implemented and are blocked by the SOAP client wrapper.

## Requirements

- PHP 8.1+
- WordPress
- WooCommerce 8.2+
- PHP SOAP extension
- WooCommerce Action Scheduler for preferred background jobs; WP-Cron fallback is included.

## Installation

1. Clone this repository into `wp-content/plugins/schrack-woocommerce-sync/`.
2. Activate WooCommerce first.
3. Activate `Product furnizor importer`.
4. Open `WooCommerce > Product furnizor importer`.
5. Configure TEST or LIVE credentials and save settings.
6. Enable debug mode temporarily and use the WSDL function/type list to confirm the exact Schrack SOAP request structures.

Deployment uses Git; no release ZIP is needed. On the cPanel hosting account,
use the existing Git deployment workflow. SSH is not available to the operator.
The commands below document the repository layout for development or hosts with a terminal:

```bash
cd wp-content/plugins
git clone git@github.com:lacikasimon/Schrack_woocomerce.git schrack-woocommerce-sync
```

For updates:

```bash
cd wp-content/plugins/schrack-woocommerce-sync
git pull --ff-only
```

## Publishing

Before deploying an update through Git, bump both plugin version values in `schrack-woocommerce-sync.php`:

- Plugin header `Version`
- `SCHRACK_WC_SYNC_VERSION`

Project conventions and operational constraints are recorded in [AGENTS.md](AGENTS.md).

## Duplicate attribute consolidation

Open **WooCommerce → Unificare atribute**. Choose **Previzualizare**, review the
duplicate groups and conflicting values, then **Pornește unificarea**. No SSH,
WP-CLI or database export executable is required for this admin workflow.
It preserves the first populated export column per product, including its complete
value list and zero values, and saves redirects for subsequent supplier/CSV imports.

The job runs in checkpointed background batches; keeping the page open also advances
processing when cron is delayed. Before catalog changes it creates a private SQL
backup of attributes and shared taxonomy data, downloadable from the same page.
Supplier imports pause during the operation. Finish CSV transfers first and avoid
editing products, attributes or categories until completion. See the
[Hungarian operating guide](scripts/merge-attributes.md) for backup scope, resuming
after errors, restoration and the optional WP-CLI workflow.

## Settings

The admin settings page stores values through the WordPress Options API:

- Environment: TEST / LIVE
- SOAP endpoint URL
- WSDL URL
- Datanorm URL
- Schrack SOAP sync toggle, Telesystem feed toggle, and Telesystem feed URL
- Telesystem batch size, batches per run, and price column strategy
- Customer number
- Webshop username
- Webshop password
- Provider code
- Default markup %
- TVA %
- Sync batch size
- Retry count
- Batch sleep seconds
- Import mode
- Product publish status
- Image media-library import toggle
- Schrack catalog format: detailed XML (streamed from disk) or compact CSV
- Image batch size
- Parallel catalog workers
- Parallel image workers
- Image follow-up delay
- Image download timeout
- Image retry cooldown
- Stock handling
- Stock source
- Delete missing products
- Floating Syshub support widget
- Cron frequencies
- Log level
- Debug mode

Password and provider code fields are masked and are not rendered back into HTML. Leaving them empty while saving keeps the stored value.

## TEST and LIVE

Default endpoints:

- TEST: `https://ws-test.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice`
- TEST WSDL: `https://ws-test.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice?wsdl`
- LIVE: `https://ws.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice`
- LIVE WSDL: `https://ws.schrack.com/SchrackServicePortal/SchrackCommonVersionedWebservice?wsdl`

Use TEST credentials until SOAP payload field names have been verified against the WSDL.

## WSDL Debug

The settings screen includes:

- WSDL connection test
- WSDL functions/types listing through `__getFunctions()` and `__getTypes()`

The WSDL list is shown only when debug mode is enabled.

When the default TEST WSDL is temporarily unavailable, the SOAP client can load the LIVE WSDL as the schema while keeping the configured TEST endpoint as the SOAP call location.

## Manual MVP Tools

`WooCommerce > Product furnizor importer Manual Sync` includes:

- Queue catalog import
- Queue price sync
- Queue stock sync
- Queue full sync
- Fetch price for one SKU
- Fetch stock for one SKU
- Create/update one WooCommerce simple product by SKU

The one-product create/update tool is intended for validating SKU idempotency, category mapping, price markup calculation, and stock handling before enabling full catalog batches.

## Category Markups

`WooCommerce > Product furnizor importer Markups` lets an administrator define per-category:

- Markup %
- Optional minimum margin
- Optional rounding rule

Supported rounding:

- None
- Round up to `.99`
- Round up to whole RON
- Round up to 5 RON

Price formula:

```text
sale_price = purchase_price * (1 + markup / 100) * (1 + vat_rate / 100)
```

The catalog's `PretUnitar` value is stored per Schrack product. Every later price sync divides the quoted purchase price by that positive value exactly once, before markup and VAT are applied. This converts package quotations such as a cable price per 100 metres into the price per metre.

For supplier-like product pages and technical filtering, use the default detailed XML catalog format. The importer reads the large XML response incrementally, pairs structured property/facet names with their values, promotes categorical values to global WooCommerce attributes, and stores datasheet/CAD/drawing links in a dedicated product document section. Attribute facets are recalculated when the shopper changes category, with an in-facet value search for longer lists.

Frontend unit prices include the imported sales unit directly after the price (for example `301,60 lei / m.`) on product pages, product cards, search results, and cart unit-price rows.

If a minimum margin is configured, the plugin uses the higher net value before applying TVA.

Automatic sale pricing applies only to Schrack, Telesystem, and eDoc supplier products (including legacy Schrack/Telesystem imports identified by their supplier item number). eDoc prices are updated by the ERP importer and remain excluded from the Schrack price-sync path. Manually created products keep their entered WooCommerce prices: saving a manual price does not derive an automatic price from the regular price, and clearing it does not restore a stale automatic price. Any old automatic-price metadata on a manual product is removed when that product is saved. Supplier products retain the existing rule: a manual price stays active until a higher automatic supplier price overrides it.

The product editor includes an accent-insensitive category search above the category checklists. It filters both category tabs, keeps matching branches visible, and preserves every checkbox selection, including hidden categories. Parent-category dropdowns also support searching when adding a category inside the product editor or creating/editing a category on the product categories screen. They retain the existing hierarchy and the no-parent option, and remain searchable after WordPress replaces the dropdown following an inline category creation.

## Product Mapping

Imported products are WooCommerce simple products.

SKU is the Schrack item number. Existing products are found by SKU and updated instead of duplicated.

Stored meta fields:

- `_schrack_item_number`
- `_schrack_ean`
- `_schrack_manufacturer`
- `_schrack_raw_category`
- `_schrack_last_price_sync`
- `_schrack_last_stock_sync`
- `_schrack_purchase_price`
- `_schrack_purchase_price_raw`
- `_schrack_price_unit`
- `_schrack_package_quantity`
- `_schrack_documents`
- `_schrack_technical_attributes`
- `_schrack_unit`
- `_schrack_catalog_status`
- `_schrack_image_url`
- `_schrack_imported_image_url`
- `_schrack_image_attachment_id`
- `_schrack_image_status`
- `_schrack_image_error`
- `_schrack_stock_breakdown`
- `_schrack_technical_attributes`

The product page widget shows mapped product identity fields, visible WooCommerce attributes, and stored Schrack technical attributes that are relevant to customers. Catalog imports populate `_schrack_technical_attributes` from extra public catalog columns while excluding duplicate core fields plus commercial, import, sync, and internal values.

To recommend services such as installation alongside a photovoltaic system, create each service as a WooCommerce product (enable **Virtual** if it does not require shipping). Edit the system product and open **Product data > Linked products > Servicii recomandate**. Search by name or SKU, select one or more service products, and save; drag the selections to change their display order. The **Pagina produs Schrack** Elementor widget shows these services before the product details, with an image, short description, current WooCommerce price, and a link to the service page. Its **Servicii recomandate** display switch is enabled by default. Services are ordered separately; linking them does not change the system price or automatically add anything to the cart. Empty selections produce no section, and unpublished, hidden, password-protected, or deleted services are omitted. Links are stored in `_schrack_recommended_service_ids` and remain intact during supplier syncs and quick edits; removing all selections in the product editor clears them.

For mandatory installation or other required services, use **Product data > Linked products > Servicii obligatorii** instead. The main product can be simple or variable; each required service must be a separate **simple product** with its own price, and cannot itself have mandatory services. Variations inherit their parent's selection. Invalid editor selections leave the last valid configuration intact and show an admin error. A service may be hidden from the catalog while remaining published and purchasable. Required links are stored in `_schrack_required_service_ids`; imports and quick edits preserve them.

The product page identifies mandatory services and their current customer prices beside the buy button, explains the additional cost and removal rule, and marks them **Obligatoriu · adăugat automat** in the service cards. The optional-services display switch does not hide mandatory services. Adding the product updates the cart asynchronously and creates a separate WooCommerce line for each mandatory service, one service unit per product unit. Each service keeps its own price, tax, shipping/virtual setting and stock handling. Changing the product quantity updates its services. Removing either the product or one of its mandatory services removes that entire linked group; unrelated products and separately purchased services remain. The cart's Undo restores the group together, subject to availability. Unavailable services or insufficient service stock prevent a partial addition. If required links change while a cart is saved, the old group is removed with a notice and the customer must explicitly add the product again. Never configure a nested or circular chain of mandatory services.

Required-service checks: `php tests/product-services.php` and `php tests/required-services-cart.php`. The latter can also exercise the official WooCommerce cart lifecycle without a database: `php tests/required-services-cart.php /path/to/woocommerce/includes/class-wc-cart.php` (use a complete local WooCommerce source checkout; product/session/totals doubles remain isolated).

The customer/B2B account portal can be placed with the Elementor widget or with `[schrack_account_page]`. It renders a custom login form plus direct B2C and B2B registration choices for guests, and a WooCommerce account dashboard for logged-in users, including in-page orders, billing-address editing, account-detail editing, and B2B status from `_schrack_account_type` and `_schrack_b2b_status`. Order details include a product-level return request form during the 14-day return window; guests can submit an order-number/email verified request from the account portal or a standalone `[schrack_return_form]` page. Return requests are visible on the dedicated `WooCommerce > Retururi` admin screen, in WooCommerce order lists, order details, and order notes. Store admins can edit the B2B fields from the WordPress user profile screen.

## Cron and Background Jobs

Recurring jobs can be globally enabled or disabled from the admin settings. Schrack SOAP sync and the Telesystem CSV feed can also be enabled or disabled separately. When automatic sync is enabled, jobs are registered through Action Scheduler when available:

- Catalog import: daily / weekly
- Telesystem CSV import: daily / weekly
- Price sync: daily / every 6 hours / hourly
- Stock sync: hourly / every 30 minutes

If Action Scheduler is unavailable, WP-Cron is used as a fallback.

Catalog, price, and stock batches persist cursors in the status option. Each batch continues from the previous offset and wraps to the beginning after a full pass. Catalog imports also reset when the parsed SKU sequence changes.

Catalog rows receive a versioned source fingerprint after a successful WooCommerce save. Later cycles skip the expensive product load, taxonomy assignment, metadata rewrite, lookup-table update, and product save when the normalized supplier row and output-affecting settings are unchanged. Import status reports created, updated, and unchanged counts separately. SKU batch lookup uses WooCommerce's indexed product lookup table, with a postmeta fallback for older or partially migrated stores.

When `Parallel catalog workers` is above one and Action Scheduler is available, the parsed catalog is split into non-overlapping ranges. Each worker keeps its own progress option to avoid concurrent writes to the shared serialized status row, and Full sync waits for all catalog workers before advancing. JSONL cache readers stay open across consecutive batches in the same request, while category and attribute term counts are deferred across the whole multi-batch run.

Catalog sync stores product image URLs in `_schrack_image_url`. If media-library image import is enabled, image sync then claims existing products with pending image URLs and dispatches parallel Action Scheduler workers, controlled by the image batch size, follow-up delay, download timeout, retry cooldown, and `Parallel image workers` settings. If image import is disabled, pending products are left with their external image URLs and the storefront remote-image fallback continues to use those URLs for products without downloaded images. Image workers stop before PHP timeout/memory pressure and release unfinished claims for the next wave. Failed image downloads are marked in product meta and retried after a cooldown.

Homepage, featured-category and filter/archive product cards defer their image URLs
until they are within 100 px of the viewport. This also covers cards inserted by
AJAX filtering and pagination. Local thumbnails retain their responsive `srcset`
and `sizes`; the single-product hero remains eager. A `noscript` fallback displays
the original image when JavaScript is disabled. WordPress versions without the
HTML Tag Processor retain native lazy loading. LiteSpeed is excluded from
reprocessing these images and delaying the small loader script.
For remote Schrack JPGs in these cards, the loader uses the supplier's own
`image.schrackcdn.com/260x145/` catalogue preset. Only recognised `/foto/f_*.jpg`
URLs on the two official image hosts are eligible; other suppliers, query strings,
local media and explicit full-size image requests keep their existing URLs.
An unavailable or 1x1 CDN response falls back once to the original image. With
JavaScript disabled, the original is used directly. Stored image URLs and media
import jobs are unchanged; no bulk image download is needed.
The featured-category navigation uses the same loader for its banner images,
including images clipped by the horizontal mobile scroller. Only the first two
banners (the first mobile column) remain eager, with high priority on the first.
Other visible banners load automatically on desktop; horizontal scrolling loads
newly revealed banners on mobile. The original images remain available without JS.
After deploying this change through Git, purge the LiteSpeed page/optimization
cache so cached pages receive the new markup and versioned loader.
Regression checks: `node --test tests/frontend-lazy-images.js` and
`php tests/frontend-lazy-images.php /path/to/wordpress-source`. The PHP check uses
WordPress's real HTML parser and sanitizer without loading a site or database.

### Frontend performance (0.1.83)

- The single-product image uses Schrack's verified `340x380` / `1190x1330`
  gallery presets and responsive `srcset`, eager/high-priority loading and an
  explicit LiteSpeed lazy-load exclusion. The original remains accessible through
  the image link, and is restored once if the CDN fails (including cached 1x1
  responses). Other suppliers and signed URLs are not rewritten.
- Header search results use the same deferred CDN card loader, including AJAX
  results. Bundled category artwork has 240/480/720 px WebP variants; funding
  logos have lossless WebP variants with responsive sizes. Original files remain.
- Header, search, category, product-page, archive/filter, services and support CSS is inlined only when its
  handle is already being printed, preserving cascade order and conditional
  loading. This avoids extra blocking requests on a cold visit at the cost of
  adding the CSS to HTML. A custom source URL or future CSS containing external
  asset URLs/imports falls back to
  the external stylesheet. To disable this optimization use
  `add_filter( 'schrack_wc_sync_inline_critical_css', '__return_false' );`.
- Product/catalog pages also inline an explicit allowlist of installed Hello
  Elementor, Elementor layout/widget and CookieAdmin CSS files. They keep their
  original order/media and conditional enqueue behavior. No remote fetch or
  generated vendor copy is used. Each file is limited to 64 KiB, with a 128 KiB
  total vendor budget per response. Changed paths, relative asset URLs/imports,
  conditional/integrity tags or oversized files retain normal external loading.
  Disable with `schrack_wc_sync_inline_catalog_css`. Other pages, including
  cart and checkout, retain normal vendor loading.
- The archive banner explicitly bypasses lazy loading on desktop; its mobile
  picture source uses the tiny shared placeholder below the existing 720 px
  hide breakpoint, avoiding the hidden decorative photograph's download.
- Technical attribute facets count available products across all registered
  attributes in one aggregate query, then load only matching term IDs. Published
  products, backorders, category descendants, distinct counts and natural option
  sorting retain their previous semantics. No persistent stock cache is added.
  Regression: `php tests/product-filter-counts.php` (in-memory SQLite only).
- CookieAdmin remains the consent UI and preference/log storage. Its scripts use
  ordered native defer, excluded from LiteSpeed processing. A small integration
  for CookieAdmin 1.2.2 leaves the Google `gtag/js` URL inert until analytics or
  marketing consent is saved. Cached HTML is identical for all visitors; the
  browser reads the existing consent cookie. Categories map independently to
  Consent Mode v2, including withdrawal, without reload or polling. After a tag
  has loaded, withdrawal changes its consent state; it does not unload Google's
  running library. Google's denied mode may still send cookieless signals.
  The integration is inactive when CookieAdmin is not enqueued. If its save API
  is unavailable, the tag stays blocked. Retest this integration after updating
  CookieAdmin; keep **Reload on Consent** off. Compact Romanian notice text is
  configured in CookieAdmin's existing consent form, separately from Git.

- Catalog pages opt into WordPress 6.8+'s block asset loading on demand: rendered
  blocks still enqueue their own styles/scripts; unused form/media block assets
  are not enqueued globally. The combined core block stylesheet is retained.
  Opt out with `schrack_wc_sync_catalog_block_assets`.
- A single active Elementor product template containing only our product widget
  and known basic product/tabs widgets does not need WooCommerce's PhotoSwipe,
  zoom or slider assets. Gallery support is omitted for that request only.
  Native image widgets, nested templates, unknown widgets, block/shortcode product
  content and preview pages keep native support. Opt out with
  `schrack_wc_sync_trim_product_gallery`.
- OneTap **2.14.0** on catalog pages initializes on toolbar activation or keyboard
  use for new visitors. Existing saved preferences (including hidden-toolbar
  choices) initialize immediately. Its original CSS, settings and UI remain;
  scripts load in dependency order. Failed downloads show a retry message and
  require another user action. Other versions/custom script sources retain native
  loading. Opt out with `schrack_wc_sync_onetap_on_demand`.
- Live Elementor template cleanup is separate from Git: remove the obsolete
  container hidden on desktop/tablet/mobile when it duplicates the custom product
  widget. Keep the visible custom widget and Product Data Tabs. Elementor revisions
  provide rollback; do not remove any currently visible product information.

Checks: `node --test tests/frontend-lazy-images.js tests/frontend-consent.js tests/frontend-onetap.js` and
`php tests/frontend-performance.php /path/to/wordpress-source`. After pulling the
release into the live plugin directory, purge LiteSpeed's page/optimization cache.

Telesystem is handled as a separate catalog source. Its products are marked with `_schrack_catalog_source = telesystem` and source-specific metadata such as `_telesystem_item_number`, `_telesystem_price_1`, `_telesystem_price_2`, `_telesystem_stock_text`, and `_telesystem_technical_attributes`. WooCommerce SKUs are prefixed with `TS-` while the original feed code remains in `_telesystem_item_number`, preventing collisions with Schrack item numbers. Telesystem products are not given `_schrack_item_number`, so Schrack SOAP price and stock syncs do not process them. The shared image queue still uses `_schrack_image_url` so Telesystem product images can be downloaded by the existing image sync.

## WP-CLI

Commands:

```bash
wp schrack-sync catalog
wp schrack-sync telesystem
wp schrack-sync telesystem --drain --max-batches=20 --time-limit=1800
wp schrack-sync prices
wp schrack-sync stock
wp schrack-sync images
wp schrack-sync images --drain --batch-size=50 --time-limit=1800
wp schrack-sync full
wp schrack-sync merge-attributes
```

Use `wp schrack-sync images --drain` for a large initial media backlog when SSH/WP-CLI is available. It bypasses Action Scheduler follow-up latency and keeps processing image batches in the same CLI process until the backlog is clear or the optional batch/time limit is reached. `wp schrack-sync telesystem --drain` does the same for a large initial Telesystem feed import, running consecutive import cycles until the feed is fully imported or the optional run/time limit is reached.

`wp schrack-sync merge-attributes` previews consolidation of global attributes with the same visible label. It retains the first populated value list in the wide export's column order. Apply with `--apply --backup=/absolute/private/path.sql` while imports and product editing are paused. The command creates a full database backup, migrates terms/product assignments, refreshes filtering, and saves redirects used by subsequent supplier and wide CSV imports. See [the Hungarian runbook](scripts/merge-attributes.md) for the standalone script, reports, checks and recovery.

## Complete WooCommerce product and category export/import

`WooCommerce > Product/category export` provides resumable CSV backup and restore jobs designed for large catalogs. Product jobs run in bounded Action Scheduler/WP-Cron batches and persist their file position, so closing the browser does not interrupt them.

The export/import page refreshes its three progress panels asynchronously every five seconds while work is active. Starting, resuming, stopping, and uploading imports also update the page in place. Filter choices, column order, selected files, and import modes remain intact. Polling pauses in hidden tabs, stops when jobs complete or become stale, and retries temporary connection failures with backoff. The completed CSV link appears automatically. Project-wide UI guidance in `AGENTS.md` requires asynchronous data updates instead of full-page reloads.

The transfer worker is tuned for cPanel/shared-hosting accounts with up to 2 GB available memory. It reads PHP's effective `memory_limit`, chooses an adaptive batch size, stops an export action after 25 seconds or at 70% usage, and primes/releases WordPress product caches in small groups. Persistent Redis/Memcached entries are not invalidated by the read-only export. WooCommerce import batches are intentionally smaller because core parses a complete CSV batch before saving it. The worker recalculates its size in the cron process, so differing web/cron php.ini limits remain safe. Export/import continuations explicitly wake the async queue runner instead of waiting for its normal loopback interval. On shared-hosting limits, this plugin also caps its Action Scheduler concurrency to one worker, instead of allowing several memory-heavy PHP processes to overlap.

The export uses WooCommerce's official product CSV schema and includes every non-trashed product and variation, attributes, categories, tags, images, downloads, linked products, and all custom product metadata. Its explicit all-products scope ignores the optional filters and includes supplier-imported, manually created, and products created by other plugins. The filtered scope can instead select post status, WooCommerce product type, product category (including descendants and child variations), supplier source (Schrack/Telesystem/eDoc/other), stock status, or a partial product-name/SKU/ID match. A header builder can switch between the complete backup schema and an ordered custom selection of official WooCommerce fields plus discovered Schrack/Telesystem or manually entered Meta keys. Presets populate basic, recommended supplier, all WooCommerce, or all supplier columns; arrow controls change their CSV order, while dynamic downloads can be appended safely at the end. Attribute output can retain WooCommerce's numbered name/value groups, be omitted, or scan the assigned catalog attributes in memory-safe database batches and create one stable readable column per name (for example `Atribut: VPE [pa_vpe]`); products without that attribute receive an empty cell. The bundled importer maps these wide attribute columns back to WooCommerce attributes, including escaped commas in individual values. Supplier identity and prices use readable columns (`Furnizor`, `Preț achiziție furnizor`, `Preț furnizor original (sursă)`, and the two Telesystem price fields) instead of opaque `Meta:` headers. The bundled importer automatically maps those columns back to their original product metadata and converts localized comma decimals to machine decimals. The saved job retains the normalized filter and header configuration across every background batch and resume. Regular, sale, and readable supplier prices always contain at least two decimal places and use a decimal comma (for example `786,00`), even when the stored source value is a whole number or the shop display is configured for zero decimals/a decimal point. Selected rows still include the chosen Schrack and Telesystem identity, item numbers, EANs, purchase prices, VAT, stock details, sync timestamps, technical attributes, documents, image references, commercial fields, and `_schrack_raw_feed_data`.

Keep at least about twice the expected CSV size free during export because the row work file and final CSV coexist during resumable assembly. The finalizer checks available filesystem space before each copy chunk and reports an actionable error instead of repeatedly timing out when space is exhausted (hosting-account quotas may not always be visible to PHP).

This is a product-catalog backup, not a database backup; it does not include orders, customers, or product reviews.

WooCommerce normally skips array/object metadata. The plugin encodes those values into a versioned, base64-wrapped JSON marker in `Meta:` columns and decodes them during its own import, preserving structured supplier records without unsafe PHP unserialization. Final CSV headers are assembled only after all dynamic attribute/download/meta columns are known. Final assembly copies at most 256 MB or 20 seconds per background action, persists source/output byte checkpoints, and rolls an interrupted partial write back to its last durable checkpoint. The completed file is streamed to the administrator without loading it into PHP memory.

The importer accepts this export or another recognizable WooCommerce product CSV and automatically maps official columns. A completed private export can be queued directly for import without downloading/re-uploading it, which bypasses the WordPress upload-size limit. "Update existing" restores rows by ID/SKU in the same store; "Create" is intended for an empty/new store and skips already existing IDs/SKUs. Uploaded copies and completed exports are kept in separate randomized, web-protected upload directories. Import copies are deleted at completion; export files are deleted on reset or age cleanup.

If a server timeout or queue-runner interruption leaves a job stale, the page offers a retry action. Export retries truncate the work file back to its last durable byte checkpoint before continuing, while import retries resume at the last confirmed CSV position.

The same page provides a separate complete product-category CSV. It preserves the `product_cat` hierarchy through stable path/parent columns, names, slugs, descriptions, display type, menu order, category image URL, and every portable custom term-meta key as a reversible `Meta: key` column. Category restore runs in small background batches, resumes directly from its saved byte position, recreates missing parent paths, reuses or downloads category images, and deletes its protected upload copy after completion. Its same-shop mode prioritizes exported term IDs, while new-shop mode deliberately ignores non-portable numeric IDs and matches by path/slug. Product and category transfers are mutually exclusive so a stalled worker cannot change the hierarchy while another catalog transfer is running.

For a 2 GB cPanel account, use PHP 8.1+ and set the PHP `memory_limit` to 512 MB (256 MB minimum) so the PHP process cannot consume the complete account allowance. Configure a real cron job to invoke WordPress cron every minute if loopback WP-Cron is unreliable. Disable WordPress's page-triggered cron only after the cPanel cron is confirmed working. The exact PHP binary and WordPress path are hosting-specific; cPanel's cron screen usually shows the correct command path. The export/import status table displays the detected PHP limit and effective batch size.

## Logging

Logs are stored in a custom database table:

- Timestamp
- Level: debug / info / warning / error
- Operation: catalog / price / stock / images / soap / admin
- SKU
- Message
- Context

Sensitive credential fields are redacted before logging.

## Security Notes

- Admin pages require `manage_woocommerce`.
- Admin actions use nonces.
- Inputs are sanitized and outputs are escaped.
- Password and provider code are not printed in admin HTML.
- Credential-like fields are redacted from logs.
- Order related SOAP methods are blocked in `Schrack_Soap_Client`.

## SOAP Template Alignment

The SOAP client is aligned to the received Schrack templates:

- `GetCatalogAsXMLV32`
- `GetCatalogAsCsvV33`
- `GetItemPriceV31`
- `GetStockItemQuantitiesV40`

Catalog calls request `ResultType=download`, and catalog responses with `Return > DownloadURL` are downloaded before parsing. CSV catalog sync tries the available Schrack CSV method versions from newest to older (`GetCatalogAsCsvV34`, then V33/V32/V31/V30) so one broken method version does not stop the whole import. Use the WSDL debug screen and TEST environment before LIVE usage, because full catalog field mapping still depends on the actual CSV/XML file headers returned by Schrack.


## Shop aggregate reuse (v0.1.99)

The public shop batches category-subtree counts, reads the category hierarchy once,
and fetches technical attribute terms across taxonomies in one request. The initial
sidebar reuses its already-rendered facet HTML. Distinct counts still exclude drafts,
variations and unavailable products while retaining backorders and descendant categories.

Public category counts, unscoped shop facet options and shop overview categories use
a two-minute aggregate cache. Product saves/deletions, stock/status updates, relevant
metadata, category/attribute membership and taxonomy changes invalidate its generation.
Concurrent edits prevent an older computation from being saved. AJAX filtering, admin
requests and the cold-selection diagnostic bypass this cache. Prices, product HTML,
carts and customer data are not stored. The first request after expiration or a catalogue
edit rebuilds the aggregates; page-cache MISS and aggregate-cache MISS are distinct.
Disable with `schrack_wc_sync_catalog_facet_cache` if an external writer bypasses
WordPress/WooCommerce invalidation hooks. Normal imports use those APIs.

The warmer accepts the exact published public WooCommerce shop URL even when
WordPress reverse URL lookup does not resolve the product archive to its page ID.
The private profiler includes individual filter rendering stages.

## Category rendering (v0.1.94)

The product-filter widget loads its category choice labels only inside Elementor's
editor. Public rendering still uses the saved category ID. Enabled manufacturer
and product-line facets share one aggregate query scoped to distinct products in
the selected category and its descendants. Request-local reuse avoids duplicate
queries while keeping fresh stock and publication status on each new request.
This v0.1.94 change introduced no persistent cache; v0.1.99 adds the bounded aggregate cache described above.

## Product hero discovery (v0.1.93)

For a verified Elementor single-product template containing one visible current-product
gallery, the remote hero image is now preloaded in the document head. It uses the
same `imagesrcset`/`imagesizes` as the gallery and does not trigger image import or
any server-side image download. Local images, custom/hidden/dynamic galleries,
multiple galleries and unfamiliar templates retain normal image discovery.
The filter `schrack_wc_sync_preload_product_image` can disable this optimization.
This improves image discovery; it does not bypass PHP or guarantee a browser LCP.
Responsive preload follows the [browser guidance](https://web.dev/articles/preload-responsive-images).

## Store response time tools (v0.1.92)

**WooCommerce → Performanță magazin** provides an optional public page warmer.
Save 1–100 canonical priority URLs (home, shop, public products or product categories) and
enable the hourly cycle, or start a single run. Since v0.1.100, each minute's job
makes up to 50 sequential anonymous GETs (v0.1.101), but limits each job to
five pages without an initial HIT with a soft 15-second budget checked
between requests (individual timeout: 20 seconds). Responses taking five seconds
or more end that batch immediately;
failed requests wait five minutes and three consecutive failures stop the run.
The stop button also disables future automatic runs. Redirects, query parameters,
foreign hosts, account/checkout pages and hidden/password protected products are
rejected. Every URL is revalidated before fetching. No shopper cookies are sent.
Only explicit MISS/HIT response headers are labelled as such; an HTTP 200 alone
does not prove that caching works. Displayed request durations include the response
body and are not TTFB.

Progress is stored across short WP-Cron runs with a MySQL connection lock. A real
hosting cron calling WordPress every minute is recommended (no SSH is necessary to
configure it in cPanel). Full LiteSpeed page purges trigger a delayed, coalesced
warmup when automation is enabled. The warmer does not change cache TTLs, cache
eligibility, price/stock invalidation or the native crawler's server configuration.
Personalized requests still need normal PHP rendering. Deactivation clears the
warmer's scheduled jobs.

### All-product preload (v0.1.100)

Enable **Preîncălzește categoriile cu produse și toate produsele publice în stoc** on that same admin screen to scan
every published, password-free product whose catalogue visibility is `visible` or
`catalog` or `search`. Since v0.1.102 only products with WooCommerce stock status
`instock` are warmed, including manual priority product URLs. Sold-out and
backordered products are excluded in the lookup query and rechecked immediately
before each GET. Stock changes after queueing are skipped without HTTP requests
or stopping the run; restocked products enter the next automatic pass. Public
profiling can still inspect saved sold-out URLs. There is no total product limit. Home and the canonical WooCommerce
shop run first, followed by manual priority URLs and up to 24 nonempty categories.
Since v0.1.103 every canonical product category archive is then scanned in
keyset pages of 100 term IDs, including nested categories, before
products. The first 24 categories are priorities, not a total category limit. Since
v0.1.105 WordPress `hide_empty` excludes empty categories from warming, including
manual and already-saved queues. Parents with products in descendants remain
eligible. Category emptiness is rechecked immediately before GET; product stock
filtering is unchanged.
Published product IDs are then scanned in pages of 100 using a saved ID cursor
and the actual WordPress posts table. At most three empty/hidden pages are scanned
per job. No full catalogue URL list is kept in memory.

Progress survives closed browser tabs and interrupted workers; an hourly trigger
does not reset product progress. During a long product scan, the hourly trigger
queues a separate category pass, saving and restoring the pending product URLs.
An unfinished category pass is allowed to finish rather than restarting hourly.
A product-only run from v0.1.102 can activate the new category pass with
**Pornește acum** (v0.1.104) while preserving its current product queue. Full page-cache purges queue a priority
refresh with a five-minute cooldown. A long product scan resumes from its saved
place afterwards, then repeats the product pass to cover pages visited before
the purge. Category pages visited before the purge are also revisited; a
priority refresh preserves both pending category and product queues. Disabling automation with the stop button cancels scheduled work.

An initial `MISS` gets exactly one confirmation GET: only a real subsequent `HIT`
is reported as `MISS → HIT`. Repeated MISS responses remain unconfirmed. The UI
shows processed category/product visits, confirmed/unconfirmed page visits and the latest
100 results. Counts include repeat visits after a purge, not distinct products.
Large catalogues can take hours or days depending on server response time. This
uses the existing page cache and minute hosting cron; it does not require enabling
the native LiteSpeed crawler in WHM or installing a second page-cache plugin.

The same screen can measure one saved URL without cache. A one-use, 60-second
ticket bound to the requested URI authorizes the anonymous measurement; results
are returned only through capability/nonce protected admin AJAX. Only PHP phase
timings, SQL counts/aggregate timings and peak memory are retained for at most five
minutes. SQL strings and customer/session data are never stored in the result.
Instrumentation begins when this plugin file loads, so earlier SQL is not timed.
The measurement adds a small amount of overhead and is not a browser TTFB test.
The cold-selection measurement also bypasses our product-ranking/category-image
ID transients for that one authenticated probe, without clearing the store cache.
WordPress/Redis and OPcache retain their normal behavior. Both measurement modes
share the same public URL allowlist, capability/nonce checks and cooldown.
When cURL is available, `ttfb_ms` separately reports the time until the first byte
from the server's own measurement request. Other transports return `null` for this
field; total HTTP duration is never presented as first-byte timing.

On the inspected LiteSpeed 7.9.1 integration, configured full-purge callbacks on
`create_term`, `edit_terms`, and `delete_term` retain full public HTML invalidation
for product categories/attributes but preserve CSS/JS, Redis and PHP OPcache.
WordPress/WooCommerce still invalidate their term/product objects normally. This
prevents catalog imports from repeatedly discarding unrelated technical caches.
Unrelated taxonomies and manual full purges retain their original behavior.
Cloudflare integration or another LiteSpeed version retains native purging.
The filter `schrack_wc_sync_preserve_catalog_technical_cache` can opt out.

Both homepage renderers' category-image fallbacks and anonymous product rankings cache only product
IDs for ten minutes, including empty results. Product objects, prices, publication
state and stock are read again on each render. A selected product's new thumbnail
is read immediately; a changed category fallback selection or product ranking can
take up to ten minutes to refresh. Logged-in visitors and existing WooCommerce
sessions bypass the ranking cache. This reduces repeated broad catalog scans even
when the full-page cache has been purged.

Cold homepage selections retain the WooCommerce product query API, filters and
ordering, but replace its simple `_stock_status = instock` meta condition with an
indexed lookup in WooCommerce's product metadata table. Extra or unfamiliar meta
conditions and an in-progress lookup regeneration retain the original query.
The filter `schrack_wc_sync_catalog_stock_lookup` can disable this optimization.
WordPress post/term/meta caches are primed together before product objects load.
The header shares the category-image ID cache. The featured-category widget only
builds its 500-entry category picker in the editor/admin, not on public renders.

Regressions: `php tests/cache-warmer.php`, `php tests/catalog-query.php`,
`php tests/homepage-selections.php`, `php tests/elementor-category-options.php`,
`php tests/cache-invalidation.php /path/to/wordpress-source` and
`node --test tests/admin-cache-warmer.js` (no live database or HTTP requests).

## eDoc ERP integration (v0.1.73)

Open **WooCommerce → eDoc ERP**. The integration is disabled by default. Configure a
separate connection in **eDoc → Gestiune → Webshop → Configurare**, select quantitative
warehouses and copy its key ID and one-time secret into WooCommerce. Enter the HTTPS
ERP origin (without `/api/webshop/v1`), save, and test the connection. Configure the
WooCommerce origin in ERP and test the other direction before enabling both ends.
Secrets remain masked in admin HTML; leaving the secret field empty preserves it.

Both systems must use RON for v1. Configure WooCommerce tax rates first, then map each
ERP VAT percentage to its WooCommerce tax-class slug, one line per rate. For example,
`21=` maps 21% to Standard (the empty slug), while `11=reduced-rate` maps to that class.
The rate configured for the store base address must match. No default VAT rate is
silently substituted. Missing mappings, invalid/nonpositive prices and withdrawn
articles remain draft and unavailable; the product's supplier box explains why.

Select articles under the ERP Catalog tab. eDoc `pret_vanzare` is a **net supplier
price**. The importer applies existing category/default markups, minimum margins,
rounding and protected manual-price rules. Tax is included once only when the shop
stores prices inclusive of tax. Products use source `edoc` and stable SKU
`ERP-<entity_id>-<article_id>`; their original code and barcodes remain searchable.
A collision with another supplier is reported without overwriting the product.
New products are draft. Names, descriptions, images and categories become shop-owned.
Only price, tax, unit, identity metadata and availability change on later syncs.
WooCommerce stock management is disabled for eDoc products: stock quantities remain
null, backorders are off, and unavailable products cannot be purchased. Withdrawing
an article makes the existing product draft/outofstock without deleting its history.

The eDoc admin screen shows queue counts, the current import page, last successful
delivery, catalog validation counts and failures. Use its manual catalog, complete
history and retry actions as needed. **Full sync** also queues the enabled eDoc
catalog. `wp schrack-sync edoc` processes one bounded bridge run. eDoc has its own
explicit enable switch and queue, independent of the Schrack/Telesystem schedule.

Action Scheduler runs the bridge every minute (WP-Cron fallback). Catalog cycles run
every five minutes and resume after each successful page. When both phases are due,
the worker alternates their first turn across runs and saves that choice before
starting work, so slow orders cannot indefinitely delay the catalog and slow catalog
pages cannot indefinitely delay orders. Both share the existing per-run time budget. All shop orders, including
mixed suppliers and the full history, are mirrored through the WooCommerce CRUD API
with either HPOS or legacy storage. Reconciliation runs every fifteen minutes using
fixed modified-time windows, a stable ID cursor and an overlap. Individual capture
failures (such as an order exceeding 500 lines) remain visible in durable storage
and can be retried without blocking later orders or the catalog. Native saves only capture to local durable
storage; ERP HTTP delivery happens in the background. Failed deliveries back off and
remain visible after ten attempts until retried. A successful old delivery never
acknowledges a newer pending snapshot.

ERP changes standard order statuses immediately through signed versioned commands.
A persistent journal deduplicates retries and detects reuse of a command ID with
different parameters. Concurrent writes serialize per order. After an interrupted
command, the plugin verifies the current state and reports a conflict if the effect
cannot be confirmed; it never repeats an uncertain WooCommerce side effect. Refunds,
custom statuses, payments and supplier order placement stay in WooCommerce.

The public routes are `/wp-json/schrack-sync/v1/erp/health`,
`/wp-json/schrack-sync/v1/erp/orders/<id>` and
`/wp-json/schrack-sync/v1/erp/orders/<id>/status`. Both directions use HMAC-SHA256 over
the method, logical route (without `/wp-json`), sorted RFC3986 query, key ID, timestamp,
nonce and exact body SHA256, joined with LF. Timestamp tolerance is five minutes;
nonces are stored atomically. Requests/responses are bounded to 2 MiB and 500 product
lines. HTTPS is required, redirects are not followed, and snapshots/secrets are not
logged. Synchronize server clocks. The ERP protocol document is canonical.

For an **isolated local harness only**, `EDOC_ALLOW_INSECURE_LOCAL=true` also requires
`WP_ENVIRONMENT_TYPE=local` or `development`; HTTP is then allowed only to local host
names. Keep separate test credentials and never enable a cloned site against the
live ERP. This flag does not disable TLS certificate verification.

Run `php tests/edoc-contract.php` for the independent HMAC fixtures and catalog rules.
Run `php tests/edoc-worker.php` for virtual-time scheduling and backoff regressions.
The paired eDoc repository supplies the live WordPress/WooCommerce Docker harness and
Playwright flow for HPOS and legacy order storage. Installation upgrades create three
bridge tables (`schrack_edoc_orders`, `schrack_edoc_commands`, `schrack_edoc_nonces`)
without rewriting WooCommerce orders. Deactivation stops bridge scheduling and retains
the journal and snapshots so reenabling can safely resume.

### Server response tools (v0.1.106)

**WooCommerce → Performanță magazin** also provides asynchronous, capability and
nonce protected controls for the server profile, search index and private log
retention. Applying the profile preserves a snapshot of only the four changed
settings: debug off, log level info, two catalogue workers and one image worker.
The profile caps Action Scheduler at two concurrent batches across the site.
Rollback restores those four settings without touching supplier credentials.
Disable visit-triggered WP-Cron only after verifying a working minute hosting
cron for `wp-cron.php`; a separate control restores it.

The search document table uses the actual site prefix and keeps title, excerpt,
content, SKU and each supplier code/EAN in separate fields. This removes the six
postmeta joins while preserving literal substring searches and the existing
fuzzy candidate ordering. It is a denormalized lookup, not a full-text search
that discards short codes or punctuation. All published products are indexed;
stock, taxonomy and customer visibility filtering remain in their original
queries. A saved keyset cursor processes at most 100 products and checks a
five-second budget between products. WP-Cron continues work after closing admin.
Search uses the existing query until the initial build and dirty queue complete.
Product edits enqueue revision-fenced updates; pending writes temporarily fall
back to the original search. Neither private products nor partial builds become
search results. Restart continues progress and retries a failed worker.

Category metadata and attribute facets now share the mutation-invalidated cache
on scoped category pages and AJAX, with a 30-minute maximum lifetime. Category
parent maps have a separate 24-hour generation so stock changes do not rebuild
the taxonomy topology. Only public counts and options are cached, never prices,
customer records or rendered HTML. SQL errors do not publish empty aggregates.

Saved Elementor template CSS/assets can avoid constructing theme documents
solely for style preparation on public store pages. This compatibility path is
restricted to the inspected Elementor 4.2.4 / Pro 4.2.3 pair. Native condition
checks, render hooks, asset enabling and CSS order remain intact. Missing saved
metadata, custom page templates, editor/preview contexts and other versions keep
native preparation. Dynamic customer/product HTML remains live.

Log retention keeps debug/info for 30 days and warning/error for 90 days. Each
background batch archives at most 500 immutable rows into private gzip JSON
segments outside the web root, with permissions 0700/0600, SHA-256 verification
and durable checkpoints. An InnoDB transaction compares the originals before
deleting only the backed-up IDs. Crash recovery is idempotent. Retention checks
daily after starting; stop or restore suspends automation. Archives remain on
the hosting account and are never automatically deleted. Admin restore preserves
IDs, timestamps, nulls and context; collisions stop without overwriting data.
No TRUNCATE or long blocking OPTIMIZE is used. Removing rows does not promise an
immediate reduction in the physical InnoDB file size. Insufficient private
storage, disk space or verification errors stop before removing affected rows.

Isolated checks: `php tests/search-index.php`, `php tests/log-archive.php`,
`php tests/elementor-assets.php`, `php tests/catalog-facet-cache.php`, and
`php tests/product-filter-counts.php`. They use disposable SQLite/files or test
doubles, without production credentials or a WordPress database.

v0.1.107 primes product posts and metadata together during index construction and
raises the initial batch limit to 500 while keeping the five-second budget.
The SEO audit reports populated field counts and the public title templates of
both systems. A separate reversible operation fills only missing SiteSEO 1.4.1
primary product categories from Yoast (up to 100 selections), after validating
product membership. Existing SiteSEO values, Yoast source metadata, redirects
and both plugins' options remain stored. Restore preserves subsequent edits.
Plugin deactivation remains a separate operation requiring plugin-management
permission; verify canonical URLs, robots, title templates, schema and sitemaps
before and after choosing one active SEO provider.

v0.1.108 hardens interrupted index writes: a failed dirty mark requires a rebuild
before indexed queries can resume, and failed dirty checkpoints stop visibly.
Deactivation clears the new schedules; the next admin visit resumes unfinished
jobs after reactivation. Archive comparison uses exact field equality and stopping
before first use does not create an invalid state. Performance status polls every
15 seconds, pauses when hidden and backs off on errors. Private probes also show
saved Elementor asset/CSS stages and facet cache hits, misses and mutation races;
ordinary requests do not retain these diagnostics.

v0.1.109 extends the storefront performance policy to the home page: block assets
load on demand and the inspected OneTap integration starts on use for new visitors,
while saved accessibility preferences still start immediately. Self-contained
Elementor post CSS from the current local uploads directory is inlined at its
original cascade position within the shared 128 KiB budget; relative URLs, fonts,
previews and replaced/CDN files retain their external stylesheets. Footer layout
CSS also avoids a blocking request. Known jQuery/WooCommerce scripts request native
ordered defer; WordPress retains blocking execution whenever inline scripts or
other dependencies require it. These changes exclude cart, checkout and account
pages and preserve existing async strategies.

v0.1.110 applies the same bounded, self-contained CSS policy to inspected
WooCommerce, OneTap and Elementor atomic layout handles. Their source must resolve
inside the current WordPress content directory without traversal. Files containing
relative fonts/images or imports retain normal external loading.

v0.1.111 requests footer placement for the known jQuery/WooCommerce chain,
including the source-less jQuery alias. Native WordPress dependency grouping
can still move libraries into the head for head-dependent scripts. Existing
inline code and execution order remain intact when defer is ineligible.

v0.1.112 can inline the inspected locally generated Poppins/Figtree CSS after
Elementor local Google Fonts is enabled in its Performance settings. Only
absolute font URLs under the current uploads directory are accepted; remote or
relative URLs, imports, modified tags and oversize files retain native loading.
The existing 128 KiB shared inline budget and font-display rules are preserved.

v0.1.113 gives the two inspected font files up to 64 KiB additional room after
the existing 128 KiB layout limit. The combined inline budget never exceeds
192 KiB; other layout files retain their previous bounds. This avoids keeping
both tiny compressed font stylesheets external when layout CSS uses its budget.

v0.1.114 inlines three exact local vendor stylesheets on catalog pages:
WooCommerce general, OneTap frontend and OneTap readable fonts. Inspected
relative image/font URLs are resolved to their original absolute plugin asset
destinations, preserving rules, media and cascade order. Unknown assets, changed
sources, imports, special link attributes and oversized files retain native
loading. The separate vendor budget is 192 KiB with a 96 KiB per-file limit;
all inline budgets combined stay within 384 KiB. Disable this addition with
`schrack_wc_sync_inline_vendor_asset_css`. Cart, checkout and account retain
their existing loading behavior.

v0.1.115 keeps the inspected OneTap readable-font stylesheet inactive until its
preference-aware loader starts. The vendor's global Roboto font faces otherwise
match the theme's fallback stack and download several large fonts on first load,
even when Readable Font has not been selected. Inline and external stylesheets
retain their original media and activate before native OneTap scripts. Saved
preferences, keyboard activation and retry behavior keep their existing startup;
disabling OneTap on-demand loading restores native font loading as well.
