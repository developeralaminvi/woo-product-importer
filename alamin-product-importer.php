<?php
/**
 * Plugin Name:       Alamin Product Importer – URL Import for WooCommerce
 * Plugin URI:        https://devalamin.me/alamin-product-importer/
 * Description:       Paste a Shopify collection link or a WooCommerce category link and import all its products (images, title, price, description, gallery, SKU) into your WooCommerce store with one click.
 * Version:           1.2.3
 * Author:            Md Alamin
 * Author URI:        https://devalamin.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       alamin-product-importer
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'ALAMIN_PI_VERSION', '1.2.3' );
define( 'ALAMIN_PI_PLUGIN_FILE', __FILE__ );
define( 'ALAMIN_PI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALAMIN_PI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ALAMIN_PI_PLUGIN_DIR . 'includes/class-wpi-scraper.php';
require_once ALAMIN_PI_PLUGIN_DIR . 'includes/class-wpi-import.php';
require_once ALAMIN_PI_PLUGIN_DIR . 'includes/class-wpi-admin.php';

/**
 * Boot the plugin once all plugins are loaded (WooCommerce included).
 */
function alamin_pi_boot() {
	ALAMIN_PI_Admin::instance();
}
add_action( 'plugins_loaded', 'alamin_pi_boot' );

/**
 * Friendly notice if WooCommerce is not active.
 */
function alamin_pi_admin_notices() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Product Importer:', 'alamin-product-importer' ) . '</strong> ' . esc_html__( 'WooCommerce is required to import products. Please install and activate the WooCommerce plugin first.', 'alamin-product-importer' ) . '</p></div>';
	}
}
add_action( 'admin_notices', 'alamin_pi_admin_notices' );

