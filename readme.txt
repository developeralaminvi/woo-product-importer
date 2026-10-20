=== Alamin Product Importer – URL Import for WooCommerce ===
Contributors: devalaminvi
Tags: woocommerce, product importer, shopify, catalog, import
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import products from Shopify and WooCommerce URLs, including images, prices, descriptions, SKUs, and categories.

== Description ==

Alamin Product Importer brings products into WooCommerce from a source URL. Preview a Shopify product or collection, a WooCommerce category, or discover categories from a store homepage. Choose the content you want to import and save products as drafts or publish them.

= Features =

* Import Shopify products and collections using public JSON endpoints.
* Discover WooCommerce categories through the public Store API and supported HTML pages.
* Preview products and select individual items before importing.
* Import product titles, descriptions, prices, SKUs, tags, and available images.
* Create source categories and download available category images.
* Link existing products to additional categories instead of creating duplicates.
* Process product imports in small batches with progress and a stop control.
* English administration interface with responsive settings and draft defaults.
* No account registration or API keys required.

= Requirements and limitations =

WordPress 6.5 or later, PHP 7.4 or later, and an active WooCommerce installation are required. PHP DOM is recommended for HTML parsing. Your server must be able to make outbound HTTP requests and download images.

Products are created as simple products. This is a product catalog importer, not a complete store migration or inventory synchronization service. Variations, orders, customers, shipping, tax settings, currency conversion, and scheduled synchronization are not imported. Review source and destination currencies and prices before publishing. Store restrictions, custom themes, missing public APIs, and request limits can prevent extraction. Large catalogs may require smaller imports.

= External requests and privacy =

When you click Preview source, the plugin requests the source URL you provide and related product, collection, category, and pagination endpoints. Shopify sources use public JSON feeds; WooCommerce sources may use the public Store API. Product previews may load images directly from the source store or its image CDN. Importing downloads available product and category images into your media library.

The source store and its image providers receive ordinary HTTP request information, including your server IP address for server requests and your browser IP address for directly displayed preview images. The plugin does not send telemetry to its author and does not require a third-party account. Source URLs and product handles are stored with imported products for duplicate detection. Temporary source mappings expire after one day.

Only import from stores you own or have permission to use. Obtain rights to descriptions and images and comply with each source provider's terms. Shopify terms: https://www.shopify.com/legal/terms . WooCommerce terms: https://woocommerce.com/terms-conditions/ . Individual stores and image providers have their own terms and privacy policies.

= Source and support =

Plugin website and documentation: https://devalamin.me/alamin-product-importer/

Source code and issue tracker: https://github.com/developeralaminvi/woo-product-importer

This independent plugin is developed by Md Alamin and is not affiliated with or endorsed by Shopify or WooCommerce.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the alamin-product-importer ZIP using Plugins > Add New > Upload Plugin.
3. Activate Alamin Product Importer – URL Import for WooCommerce.
4. Open Product Importer from the administration menu.
5. Choose a source mode, enter a URL, and click Preview source.
6. Select products or categories and review import preferences.
7. Import as drafts, verify content and prices, then publish when ready.

== Frequently Asked Questions ==

= Does this import variable products? =

No. Products are created as simple products. Review variant-specific details manually.

= Does it convert currencies? =

No. Prices are copied as numeric values. Ensure the source and destination currency match or adjust prices before publishing.

= Can I import from any website? =

No. Shopify public feeds and WooCommerce public APIs or supported HTML structures are supported. Private stores, blocked requests, and custom storefronts may not work.

= What happens to existing products? =

Matching source identifiers, SKUs, or exact titles prevent duplicate creation. New category assignments may be linked to existing products. Existing product content is not overwritten.

= Will uninstalling remove imported products? =

No. Imported products, categories, and images remain in your store. Delete unwanted imported content through WooCommerce and the media library.

== Changelog ==

= 1.2.3 =
* Added the official plugin website URL and HTTPS developer links.
* Corrected historical changelog version headings.

= 1.2.2 =
* Added a keyboard-accessible Developer tab with contact information and professional profiles.
* Kept developer links separate from the product import workflow.

= 1.2.1 =
* Renamed the plugin to Alamin Product Importer – URL Import for WooCommerce.
* Rebuilt the administration styling and switched all default interface text to English.
* Enabled TLS certificate verification and removed unsafe request fallbacks.
* Corrected draft and published status handling and source-scoped handle matching.
* Added input sanitization, response limits, and clearer import and privacy documentation.
* Fixed Store API currency minor units and added common international HTML price formats.

= 1.2.0 =
* Added multi-category extraction, assignment, and source tracking.

= 1.1.0 =
* Added store category discovery and category image imports.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.3 =
Added the official plugin website and refreshed submission metadata.

= 1.2.2 =
Security and reliability improvements, a refreshed English interface, and the new Alamin Product Importer name.
