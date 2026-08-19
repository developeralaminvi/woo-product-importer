# Product Importer — URL to WooCommerce 🚀

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg?logo=wordpress)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a.svg?logo=woocommerce)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.2%20to%208.3-777bb4.svg?logo=php)](https://php.net)
[![Author: Md Alamin](https://img.shields.io/badge/Author-Md%20Alamin-brightgreen.svg)](http://devalamin.me/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A powerful, standalone WordPress plugin designed to scrape, extract, and import e-commerce products directly into your **WooCommerce** store from **Shopify** collections or **WooCommerce** categories with one click.

---

## 🌟 Key Features

### 🛍️ Shopify Store Support
- **Collections**: Paste any Shopify collection link (`https://store.com/collections/collection-name`) to automatically fetch all products using Shopify's public JSON feeds.
- **Single Products**: Paste individual Shopify product links (`https://store.com/products/product-name`).
- **Comprehensive Data Extraction**: Captures titles, descriptions (HTML), prices, compare-at (regular) prices, SKUs, barcodes, vendors, tags, and complete high-resolution image galleries.

### 🛒 WooCommerce & Generic HTML Store Support
- **WooCommerce Store API**: Automatically utilizes the public WooCommerce Store API (`/wp-json/wc/store/v1/`) for ultra-fast, structured category and product fetching.
- **Intelligent DOM Scraper**: Fallback engine that uses `DOMXPath` and semantic scoring to extract product cards from main catalog grids while excluding widgets, related product loops, and sidebars.
- **Deep Inspection Mode**: Option to crawl individual product single pages to fetch long descriptions, SKU details, and multi-image galleries.

### 🏬 Full-Site Migration Mode
- Paste a store homepage URL (`https://store.com`).
- **Automatic Category Discovery**: Detects all public categories and collections.
- **Category Thumbnail Import**: Sideloads category banner/featured images and assigns them as WooCommerce category thumbnails (`thumbnail_id`).
- **Multi-Category Linking**: Products belonging to multiple categories are imported once and linked across categories rather than duplicated.
- **Empty Category Filtering**: Easily hide marketing tags or empty collections.

### ⚡ Resilient Batch Architecture
- **Chunked AJAX Pipeline**: Processes imports in micro-batches (3–4 products per request) to prevent PHP max execution time (`max_execution_time`) issues, gateway timeouts (504), and memory exhaustion.
- **Live Progress & Control**: Visual progress bar with dynamic status feedback (`Importing`, `Imported`, `Skipped`, `Linked`, `Failed`) and a cancel/stop button.

### 🚫 Duplicate Protection
- Smart deduplication by **SKU** and **Product Title** to prevent duplicate catalog entries.

---

## 🔒 Security Hardened

This plugin has undergone thorough security audits and adheres to WordPress Coding Standards:

1. **CSRF & Action Protection**: All AJAX endpoints require valid WordPress security nonces (`check_ajax_referer`).
2. **Strict Capability Checks**: Restricted strictly to authorized administrative users (`current_user_can('manage_options')`).
3. **SSRF Mitigation**: HTTP requests are validated and routed through safe WordPress transport helpers (`wp_safe_remote_get` / `wp_http_validate_url`) to protect against Server-Side Request Forgery and internal network probing.
4. **Input & HTML Sanitization**:
   - Clean product titles and SKUs via `sanitize_text_field` and `wp_strip_all_tags`.
   - Rich product descriptions sanitized with `wp_kses_post` to neutralize malicious `<script>` tags, event handlers, and XSS vectors.
   - Slugs and categories sanitized via `sanitize_title` and `sanitize_text_field`.
5. **DOM XSS Defense**: Client-side JavaScript strictly validates protocol schemes (`http:`, `https:`) and escapes all rendered properties before inserting into the DOM.
6. **Safe Media Sideloading**: Remote images are verified and downloaded through WordPress's native `media_sideload_image` API with mime-type checking.

---

## 📋 Requirements

| Requirement | Minimum | Recommended |
| ----------- | ------- | ----------- |
| **WordPress** | 5.8 | 6.4+ |
| **WooCommerce** | 5.0 | 8.0+ |
| **PHP** | 7.2 | 8.1 / 8.2 / 8.3 |
| **PHP Extensions** | `curl`, `dom`, `libxml`, `json` | Enabled |

---

## 🚀 Installation

### Option 1: Via Git
1. Navigate to your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/your-username/woo-product-importer.git
   ```
2. Go to **WordPress Admin > Plugins > Installed Plugins**.
3. Locate **Product Importer — URL to WooCommerce** and click **Activate**.

### Option 2: Manual ZIP Upload
1. Download or zip the `woo-product-importer` folder.
2. In WordPress Admin, navigate to **Plugins > Add New > Upload Plugin**.
3. Upload the `.zip` file and click **Install Now**, then **Activate Plugin**.

---

## 📖 How to Use

Once activated, go to **Product Importer** in the WordPress admin menu (`/wp-admin/admin.php?page=wpi-importer`).

### Mode 1: Single Category / Collection Mode
1. Select **"🗂️ Category / Collection Link"**.
2. Paste a category URL:
   - Shopify: `https://example-shop.com/collections/summer-collection`
   - WooCommerce: `https://example-store.com/product-category/t-shirts/`
3. Configure options:
   - **Fetch full details**: Enable deep crawl for WooCommerce product pages (descriptions + gallery images).
   - **Skip duplicates**: Prevents re-importing products already in your catalog.
   - **Product status**: Choose **Draft** or **Published**.
   - **Import into category**: Optionally select existing categories in your store.
4. Click **Load**.
5. Select the products you want to import from the preview table and click **Import selected** (or **Import all**).

### Mode 2: Full-Site Mode
1. Select **"🏬 Whole Site (All Categories + All Products)"**.
2. Enter the root store URL (e.g. `https://example-store.com`).
3. Click **Load**. The plugin will scan and list all available categories along with product counts and images.
4. Select the desired categories and click **Import all products of selected categories**.
5. The importer will automatically create categories, attach category images, and import all products into their respective categories.

---

## 📁 Repository Structure

```
woo-product-importer/
├── assets/
│   ├── css/
│   │   └── admin.css          # Admin UI styles, responsive cards & progress indicators
│   └── js/
│       └── admin.js           # Async batch orchestrator, DOM renderer & safe URL sanitizer
├── includes/
│   ├── class-wpi-admin.php    # Admin page registration, asset enqueuing & secure AJAX endpoints
│   ├── class-wpi-import.php   # WooCommerce product creator, media sideloading & deduplication logic
│   └── class-wpi-scraper.php  # Multi-platform scraping engine (Shopify, Store API, DOM/HTML parser)
├── readme.txt                 # Standard WordPress.org plugin directory readme
├── README.md                  # GitHub documentation and developer reference
└── woo-product-importer.php   # Main plugin bootstrap & WooCommerce dependency handler
```

---

## 👨‍💻 Author & Connect

Developed & maintained with ❤️ by **Md Alamin**.

- 🌐 **Website**: [http://devalamin.me/](http://devalamin.me/)
- 💬 **WhatsApp**: [+88 01676-086772](https://wa.me/8801676086772)
- ✉️ **Gmail**: [developeralaminvi@gmail.com](mailto:developeralaminvi@gmail.com)
- 💼 **LinkedIn**: [linkedin.com/in/md-alaminvi](https://www.linkedin.com/in/md-alaminvi/)
- 🟢 **Upwork**: [upwork.com/freelancers/developeralamin](https://www.upwork.com/freelancers/developeralamin)
- 🟢 **Fiverr**: [fiverr.com/md_alaminvi](https://www.fiverr.com/md_alaminvi)
- 🐙 **GitHub**: [@developeralaminvi](https://github.com/developeralaminvi)
- 📘 **Facebook**: [facebook.com/developeralaminvai](https://www.facebook.com/developeralaminvai/)

---

## ⚖️ Legal & Ethical Usage Notice

> **Important**: This tool is intended for importing products from stores that you own, partner stores where you have express permission, authorized suppliers, dropshipping partners, or publicly open data sources.
> 
> Scraping and re-hosting copyrighted text and images from third-party websites without permission may violate their **Terms of Service (ToS)** and applicable copyright laws. Always ensure you have the appropriate rights or licenses before importing third-party content.

---

## 📄 License & Copyright

Copyright &copy; 2024–2026 **[Md Alamin](http://devalamin.me/)**. All rights reserved.

This project is open-source and licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

