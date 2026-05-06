# Schrack WooCommerce Sync

Professional WooCommerce plugin skeleton for importing Schrack products and synchronizing purchase prices and stock quantities.

## Scope

This plugin handles only:

- Catalog import from Schrack SOAP `GetCatalogAs` or future Datanorm / CSV / XML sources.
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
3. Activate `Schrack WooCommerce Sync`.
4. Open `WooCommerce > Schrack Sync`.
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
- Customer number
- Webshop username
- Webshop password
- Provider code
- Default markup %
- Sync batch size
- Retry count
- Batch sleep seconds
- Import mode
- Product publish status
- Image media-library import toggle
- Image batch size
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

`WooCommerce > Schrack Manual Sync` includes:

- Queue catalog import
- Queue price sync
- Queue stock sync
- Queue full sync
- Fetch price for one SKU
- Fetch stock for one SKU
- Create/update one WooCommerce simple product by SKU

The one-product create/update tool is intended for validating SKU idempotency, category mapping, price markup calculation, and stock handling before enabling full catalog batches.

## Category Markups

`WooCommerce > Schrack Markups` lets an administrator define per-category:

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
sale_price = purchase_price * (1 + markup / 100)
```

If a minimum margin is configured, the plugin uses the higher value.

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

The customer/B2B account portal can be placed with the Elementor widget or with `[schrack_account_page]`. It renders a custom login form plus direct B2C and B2B registration choices for guests, and a WooCommerce account dashboard for logged-in users, including in-page orders, billing-address editing, account-detail editing, and B2B status from `_schrack_account_type` and `_schrack_b2b_status`. Store admins can edit those B2B fields from the WordPress user profile screen.

## Cron and Background Jobs

Recurring jobs are registered through Action Scheduler when available:

- Catalog import: daily / weekly
- Price sync: daily / every 6 hours / hourly
- Stock sync: hourly / every 30 minutes

If Action Scheduler is unavailable, WP-Cron is used as a fallback.

Catalog, price, and stock batches persist cursors in the status option. Each batch continues from the previous offset and wraps to the beginning after a full pass. Catalog imports also reset when the parsed SKU sequence changes.

Catalog sync stores product image URLs in `_schrack_image_url`. If media-library image import is enabled, image sync then claims existing products with pending image URLs and dispatches parallel Action Scheduler workers, controlled by the image batch size, follow-up delay, download timeout, retry cooldown, and `Parallel image workers` settings. If image import is disabled, pending products are left with their external image URLs and the storefront remote-image fallback continues to use those URLs for products without downloaded images. Image workers stop before PHP timeout/memory pressure and release unfinished claims for the next wave. Failed image downloads are marked in product meta and retried after a cooldown.

## WP-CLI

Commands:

```bash
wp schrack-sync catalog
wp schrack-sync prices
wp schrack-sync stock
wp schrack-sync images
wp schrack-sync images --drain --batch-size=50 --time-limit=1800
wp schrack-sync full
```

Use `wp schrack-sync images --drain` for a large initial media backlog when SSH/WP-CLI is available. It bypasses Action Scheduler follow-up latency and keeps processing image batches in the same CLI process until the backlog is clear or the optional batch/time limit is reached.

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
