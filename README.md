# Product furnizor importer

Professional WooCommerce plugin skeleton for importing supplier products and synchronizing purchase prices and stock quantities.

## Scope

This plugin handles only:

- Catalog import from Schrack SOAP `GetCatalogAs`, including streamed detailed XML properties, facets, and technical documents.
- Separate Telesystem CSV feed import from the configured B2B feed URL.
- Purchase price lookup through `GetItemPrice`.
- Stock lookup through `GetStockItemQuantities`.
- WooCommerce simple product create/update by SKU.
- Category based markup and rounding.

It must not be used for order submission. Order related SOAP methods, including `InsertUpdateOrder`, are intentionally not implemented and are blocked by the SOAP client wrapper.

## Requirements

- PHP 8.1+
- WordPress
- WooCommerce
- PHP SOAP extension
- WooCommerce Action Scheduler for preferred background jobs; WP-Cron fallback is included.

## Installation

1. Clone this repository into `wp-content/plugins/schrack-woocommerce-sync/`.
2. Activate WooCommerce first.
3. Activate `Product furnizor importer`.
4. Open `WooCommerce > Product furnizor importer`.
5. Configure TEST or LIVE credentials and save settings.
6. Enable debug mode temporarily and use the WSDL function/type list to confirm the exact Schrack SOAP request structures.

Example server deploy:

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

Before creating a release ZIP, bump both plugin version values in `schrack-woocommerce-sync.php`:

- Plugin header `Version`
- `SCHRACK_WC_SYNC_VERSION`

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
```

Use `wp schrack-sync images --drain` for a large initial media backlog when SSH/WP-CLI is available. It bypasses Action Scheduler follow-up latency and keeps processing image batches in the same CLI process until the backlog is clear or the optional batch/time limit is reached. `wp schrack-sync telesystem --drain` does the same for a large initial Telesystem feed import, running consecutive import cycles until the feed is fully imported or the optional run/time limit is reached.

## Complete WooCommerce product and category export/import

`WooCommerce > Product/category export` provides resumable CSV backup and restore jobs designed for large catalogs. Product jobs run in bounded Action Scheduler/WP-Cron batches and persist their file position, so closing the browser does not interrupt them.

The transfer worker is tuned for cPanel/shared-hosting accounts with up to 2 GB available memory. It reads PHP's effective `memory_limit`, chooses an adaptive batch size, stops an export action after 25 seconds or at 70% usage, and primes/releases WordPress product caches in small groups. Persistent Redis/Memcached entries are not invalidated by the read-only export. WooCommerce import batches are intentionally smaller because core parses a complete CSV batch before saving it. The worker recalculates its size in the cron process, so differing web/cron php.ini limits remain safe. Export/import continuations explicitly wake the async queue runner instead of waiting for its normal loopback interval. On shared-hosting limits, this plugin also caps its Action Scheduler concurrency to one worker, instead of allowing several memory-heavy PHP processes to overlap.

The export uses WooCommerce's official product CSV schema and, without filters, includes every non-trashed product and variation, attributes, categories, tags, images, downloads, linked products, and all custom product metadata. Optional database-level filters select post status, WooCommerce product type, product category (including descendants and child variations), supplier source (Schrack/Telesystem/other), stock status, or a partial product-name/SKU/ID match. A header builder can switch between the complete backup schema and an ordered custom selection of official WooCommerce fields plus discovered Schrack/Telesystem or manually entered Meta keys. Presets populate basic, recommended supplier, all WooCommerce, or all supplier columns; arrow controls change their CSV order, while dynamic downloads can be appended safely at the end. Attribute output can retain WooCommerce's numbered name/value groups, be omitted, or scan the assigned catalog attributes in memory-safe database batches and create one stable readable column per name (for example `Atribut: VPE [pa_vpe]`); products without that attribute receive an empty cell. The bundled importer maps these wide attribute columns back to WooCommerce attributes, including escaped commas in individual values. Supplier identity and prices use readable columns (`Furnizor`, `Preț achiziție furnizor`, `Preț furnizor original (sursă)`, and the two Telesystem price fields) instead of opaque `Meta:` headers. The bundled importer automatically maps those columns back to their original product metadata and converts localized comma decimals to machine decimals. The saved job retains the normalized filter and header configuration across every background batch and resume. Regular, sale, and readable supplier prices always contain WooCommerce's configured decimal places and localized decimal separator (for example `786,00` in a Romanian shop), even when the stored source value is a whole number. Selected rows still include the chosen Schrack and Telesystem identity, item numbers, EANs, purchase prices, VAT, stock details, sync timestamps, technical attributes, documents, image references, commercial fields, and `_schrack_raw_feed_data`.

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
