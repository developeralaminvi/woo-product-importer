=== Product Importer — URL to WooCommerce ===
Contributors: developeralaminvi, devalamin
Author: Md Alamin
Author URI: http://devalamin.me/
Tags: woocommerce, importer, shopify, scraping, products
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Paste a Shopify collection or WooCommerce category link and import all its products (image, title, price, description, SKU, gallery) into your WooCommerce store in one click.

== Description ==

= Features =

* Paste a **Shopify collection** link (`https://store.com/collections/men`) — imports every product in that collection via the public `products.json` feed (title, description, price, compare-at price, SKU, barcode, vendor, tags, all gallery images).
* Paste a **WooCommerce category** link (`https://store.com/product-category/men` or `…/shop/…`) — parses the category page, walks through every page, and (optionally) opens each product page for the full description, gallery and SKU.
* Also handles a single **Shopify product page** URL.
* One-click import into your own store as **WooCommerce products** — images are downloaded into the media library, sale prices are mapped correctly, SKU is set, tags/vendor kept, products can go to a chosen destination category.
* Preview table with thumbnails, prices and SKUs; tick the products you want; import as **draft or published**.
* Duplicate protection: skips products that already exist by SKU or title.
* No API keys needed.

= Requirements =

* WordPress 5.8+
* WooCommerce 5.0+ (active)

= Installation =

1. Upload the `woo-product-importer` folder to `/wp-content/plugins/` (or upload the zip under Plugins > Add New > Upload).
2. Activate the plugin.
3. Go to **Product Importer** in the admin menu.
4. Paste a category / collection link, click **Load Products**, review, then **Import selected**.

= Important =

Only import from stores you have permission to use. Respect the source site's Terms of Service and copyright (product images are usually copyrighted). Check your country's laws before re-selling content.

== Changelog ==

= 1.1.0 =
* NEW: Full-site mode — paste the store homepage and import ALL categories + ALL products automatically.
* NEW: Category images are downloaded and set as WooCommerce category images (thumbnail_id).
* NEW: Categories are auto-created from the source site (Shopify collections / WooCommerce Store API / HTML scan).
* NEW: Products found in several categories are linked instead of duplicated.
* NEW: Categories sorted by product count; "only categories with products" filter in the UI.
* NEW: WooCommerce public Store API integration for fast, accurate full-site import.

= 1.0.0 =
* Initial release.
