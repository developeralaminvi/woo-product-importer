<?php
/**
 * Plugin Name:       Product Importer — URL to WooCommerce
 * Plugin URI:        http://devalamin.me/
 * Description:       Paste a Shopify collection link or a WooCommerce category link and import all its products (images, title, price, description, gallery, SKU) into your WooCommerce store with one click.
 * Version:           1.1.0
 * Author:            Md Alamin
 * Author URI:        http://devalamin.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-product-importer
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WPI_VERSION', '1.1.0' );
define( 'WPI_PLUGIN_FILE', __FILE__ );
define( 'WPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPI_PLUGIN_DIR . 'includes/class-wpi-scraper.php';
require_once WPI_PLUGIN_DIR . 'includes/class-wpi-import.php';
require_once WPI_PLUGIN_DIR . 'includes/class-wpi-admin.php';

/**
 * Boot the plugin once all plugins are loaded (WooCommerce included).
 */
function wpi_boot() {
	WPI_Admin::instance();
}
add_action( 'plugins_loaded', 'wpi_boot' );

/**
 * Friendly notice if WooCommerce is not active.
 */
function wpi_admin_notices() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Product Importer:', 'woo-product-importer' ) . '</strong> ' . esc_html__( 'WooCommerce is required to import products. Please install and activate the WooCommerce plugin first.', 'woo-product-importer' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'wpi_admin_notices' );

