<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPI_Admin
 *
 * Admin menu page, asset enqueueing and AJAX handlers.
 */
class WPI_Admin {

	private static $instance = null;
	private $hook = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpi_fetch_products', array( $this, 'ajax_fetch_products' ) );
		add_action( 'wp_ajax_wpi_import_products', array( $this, 'ajax_import_products' ) );
		add_action( 'wp_ajax_wpi_import_products_batch', array( $this, 'ajax_import_products_batch' ) );
		add_action( 'wp_ajax_wpi_fetch_site_categories', array( $this, 'ajax_fetch_site_categories' ) );
		add_action( 'wp_ajax_wpi_prepare_site_category', array( $this, 'ajax_prepare_site_category' ) );
		add_action( 'wp_ajax_wpi_import_site_category', array( $this, 'ajax_import_site_category' ) );
	}

	public function register_menu() {
		$this->hook = add_menu_page(
			__( 'Product Importer', 'woo-product-importer' ),
			__( 'Product Importer', 'woo-product-importer' ),
			'manage_options',
			'wpi-importer',
			array( $this, 'render_page' ),
			'dashicons-download',
			56
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'wpi-admin', WPI_PLUGIN_URL . 'assets/css/admin.css', array(), WPI_VERSION );
		wp_enqueue_script( 'wpi-admin', WPI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), WPI_VERSION, true );
		wp_localize_script(
			'wpi-admin',
			'wpi_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpi_admin' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

	public function ajax_fetch_products() {
		check_ajax_referer( 'wpi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a valid http(s) category / collection link.' ) );
		}

		$page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$deep = ! empty( $_POST['deep'] );

		$result = WPI_Scraper::fetch( $url, array( 'page' => $page, 'deep' => $deep ) );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_import_products() {
		check_ajax_referer( 'wpi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$raw_product = isset( $_POST['product'] ) ? wp_unslash( $_POST['product'] ) : '';
		$product     = is_string( $raw_product ) ? json_decode( $raw_product, true ) : ( is_array( $raw_product ) ? $raw_product : array() );
		if ( empty( $product ) || ! is_array( $product ) ) {
			wp_send_json_error( array( 'message' => 'No product data received.' ) );
		}

		$categories = array();
		if ( ! empty( $_POST['categories'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['categories'] ) ) ) as $c ) {
				$c = (int) $c;
				if ( $c > 0 ) {
					$categories[] = $c;
				}
			}
		}

		$options = array(
			'status'     => ! empty( $_POST['status'] ) && 'publish' === sanitize_key( $_POST['status'] ),
			'skip_dup'   => ! empty( $_POST['skip_dup'] ),
			'categories' => $categories,
		);

		$result = WPI_Import::import_product( $product, $options );
		wp_send_json_success( $result );
	}

	public function ajax_import_products_batch() {
		check_ajax_referer( 'wpi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$raw_products = isset( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : '';
		$products     = is_string( $raw_products ) ? json_decode( $raw_products, true ) : ( is_array( $raw_products ) ? $raw_products : array() );
		if ( empty( $products ) || ! is_array( $products ) ) {
			wp_send_json_error( array( 'message' => 'No product batch received.' ) );
		}

		$categories = array();
		if ( ! empty( $_POST['categories'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['categories'] ) ) ) as $c ) {
				$c = (int) $c;
				if ( $c > 0 ) {
					$categories[] = $c;
				}
			}
		}

		$options = array(
			'status'     => ! empty( $_POST['status'] ) && 'publish' === sanitize_key( $_POST['status'] ),
			'skip_dup'   => ! empty( $_POST['skip_dup'] ),
			'categories' => $categories,
			'origin'     => isset( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : '',
		);

		$result = WPI_Import::import_products_batch( $products, $options );
		wp_send_json_success( $result );
	}

	/**
	 * Full-site mode step 1: discover all categories / collections.
	 */
	public function ajax_fetch_site_categories() {
		check_ajax_referer( 'wpi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			wp_send_json_error( array( 'message' => 'Please enter the store link, e.g. https://store.com' ) );
		}

		$result = WPI_Scraper::fetch_site_categories( $url );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Full-site mode step 2a: prepare category (create term + fetch products list for batching).
	 */
	public function ajax_prepare_site_category() {
		check_ajax_referer( 'wpi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$origin  = isset( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : '';
		$raw_cat = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '';
		$cat     = is_string( $raw_cat ) ? json_decode( $raw_cat, true ) : ( is_array( $raw_cat ) ? $raw_cat : array() );

		if ( ! is_array( $cat ) || empty( $cat ) || ! preg_match( '#^https?://#i', $origin ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data received.' ) );
		}

		$result = WPI_Import::prepare_site_category( $origin, $cat );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['message'] ) ? $result['message'] : 'Failed to prepare category.' ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Full-site mode step 2: import one category's products (category with
	 * image is created automatically).
	 */
	public function ajax_import_site_category() {
		check_ajax_referer( 'wpi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$origin  = isset( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : '';
		$raw_cat = isset( $_POST['category'] ) ? wp_unslash( $_POST['category'] ) : '';
		$cat     = is_string( $raw_cat ) ? json_decode( $raw_cat, true ) : ( is_array( $raw_cat ) ? $raw_cat : array() );

		if ( ! is_array( $cat ) || empty( $cat ) || ! preg_match( '#^https?://#i', $origin ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data received.' ) );
		}

		$options = array(
			'status'   => ! empty( $_POST['status'] ) && 'publish' === sanitize_key( $_POST['status'] ),
			'skip_dup' => ! empty( $_POST['skip_dup'] ),
		);

		$result = WPI_Import::import_site_category( $origin, $cat, $options );
		wp_send_json_success( $result );
	}

	/* ------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------- */

	public function render_page() {
		$categories = array();
		if ( taxonomy_exists( 'product_cat' ) ) {
			$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			if ( is_wp_error( $categories ) ) {
				$categories = array();
			}
		}

		$wc_active = class_exists( 'WooCommerce' );
		?>
		<div class="wrap wpi-wrap">
			<h1>Product Importer <span class="wpi-ver">v<?php echo esc_html( WPI_VERSION ); ?></span></h1>
			<p class="description">Shopify বা WooCommerce সাইটের লিংক দিন — ক্যাটাগরি লিংকে <strong>ওই ক্যাটাগরির সব প্রোডাক্ট</strong>, আর সাইট লিংকে <strong>সব ক্যাটাগরি (ছবি সহ) + সব প্রোডাক্ট</strong> এক ক্লিকে আপনার WooCommerce স্টোরে ইমপোর্ট হবে।</p>

			<?php if ( ! $wc_active ) : ?>
				<div class="notice notice-error"><p><strong>WooCommerce পাওয়া যায়নি!</strong> প্রোডাক্ট ইমপোর্ট করতে আগে WooCommerce প্লাগইন ইনস্টল ও অ্যাক্টিভেট করুন।</p></div>
			<?php endif; ?>

			<div class="wpi-card">
				<div class="wpi-mode-row">
					<label class="wpi-mode"><input type="radio" name="wpi-mode" value="category" checked> 🗂️ একটা ক্যাটাগরি / collection লিংক</label>
					<label class="wpi-mode"><input type="radio" name="wpi-mode" value="site"> 🏬 পুরো সাইট (সব ক্যাটাগরি + সব প্রোডাক্ট)</label>
				</div>

				<label class="field" for="wpi-url">🔗 Source link</label>
				<input type="url" id="wpi-url" class="code" placeholder="https://store.com/collections/men  or  https://store.com/product-category/men">
				<div class="wpi-actions">
					<button type="button" class="button button-primary button-hero" id="wpi-fetch">Load</button>
					<button type="button" class="button" id="wpi-stop" style="display:none">Stop</button>
					<span id="wpi-busy-label" class="wpi-busy-label"></span>
				</div>
				<p class="wpi-note" id="wpi-note">ক্যাটাগরি মোড: <code>…/collections/…</code> (Shopify) বা <code>…/product-category/…</code>, <code>…/shop/…</code> (WooCommerce)। পুরো সাইট মোড: শুধু <code>https://store.com</code> দিন।</p>
			</div>

			<div class="wpi-card wpi-options">
				<h2>Options</h2>

				<div id="wpi-single-options">
					<p>
						<label><input type="checkbox" id="wpi-deep" checked> Fetch full details (description + gallery images) — for WooCommerce sites</label><br>
						<label><input type="checkbox" id="wpi-skip-dup" checked> Skip products that already exist (by SKU or title)</label>
					</p>
					<div class="wpi-inline-group">
						<label class="field" for="wpi-status">Product status</label>
						<select id="wpi-status">
							<option value="draft">Draft</option>
							<option value="publish">Published</option>
						</select>
					</div>
					<div class="wpi-inline-group">
						<label class="field" for="wpi-cats">Import into category (optional, hold Ctrl/Cmd for multiple)</label>
						<select id="wpi-cats" multiple size="4">
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div id="wpi-site-options" style="display:none">
					<p>
						<label><input type="checkbox" id="wpi-skip-dup-site" checked> Skip products that already exist (by SKU or title)</label><br>
						<label><input type="checkbox" id="wpi-hide-empty" checked> শুধু প্রোডাক্ট আছে এমন ক্যাটাগরি দেখাও (খালি/মার্কেটিং কালেকশন বাদ)</label>
					</p>
					<div class="wpi-inline-group">
						<label class="field" for="wpi-status-site">Product status</label>
						<select id="wpi-status-site">
							<option value="draft">Draft</option>
							<option value="publish">Published</option>
						</select>
					</div>
					<p class="wpi-note">পুরো সাইট মোডে ক্যাটাগরি অটো তৈরি হবে সোর্স সাইটের ক্যাটাগরির নামে — <strong>ছবি থাকলে সেটাও আপলোড হবে</strong>, আর সব প্রোডাক্ট সেই ক্যাটাগরিতে বসবে। প্রোডাক্ট ইমপোর্টই মূল কাজ।</p>
				</div>
			</div>

			<div id="wpi-progress-wrap" class="wpi-card" style="display:none">
				<div class="wpi-progress"><div class="wpi-progress-bar" id="wpi-progress-bar"></div></div>
				<span id="wpi-progress-label"></span>
			</div>

			<div id="wpi-summary" style="display:none"></div>

			<!-- Full-site mode: category preview -->
			<div id="wpi-cat-preview" class="wpi-card" style="display:none">
				<div class="wpi-toolbar">
					<button type="button" class="button" id="wpi-cat-select-all">Select all</button>
					<button type="button" class="button" id="wpi-cat-select-none">Clear</button>
					<button type="button" class="button button-primary" id="wpi-import-site">Import all products of selected categories</button>
					<span class="wpi-count">Categories: <strong id="wpi-cat-count">0</strong></span>
				</div>
				<table class="widefat striped wpi-table">
					<thead>
						<tr>
							<th style="width:36px"></th>
							<th style="width:76px">Image</th>
							<th>Category</th>
							<th style="width:100px">Products</th>
							<th style="width:240px">Status</th>
						</tr>
					</thead>
					<tbody id="wpi-cat-tbody"></tbody>
				</table>
			</div>

			<!-- Single-category mode: product preview -->
			<div id="wpi-empty" class="notice notice-warning" style="display:none">
				<p>No products found on that link. Make sure it is a valid Shopify collection or WooCommerce category URL, and that the store does not block automated requests.</p>
			</div>

			<div id="wpi-results" style="display:none">
				<div class="wpi-card">
					<div class="wpi-toolbar">
						<button type="button" class="button" id="wpi-select-all">Select all</button>
						<button type="button" class="button" id="wpi-select-none">Clear</button>
						<button type="button" class="button button-primary" id="wpi-import-selected">Import selected</button>
						<button type="button" class="button" id="wpi-import-all">Import all (new)</button>
						<span class="wpi-count">Products: <strong id="wpi-count">0</strong></span>
					</div>
					<table class="widefat striped wpi-table">
						<thead>
							<tr>
								<th style="width:36px"></th>
								<th style="width:76px">Image</th>
								<th>Title</th>
								<th style="width:90px">Price</th>
								<th style="width:130px">SKU</th>
								<th style="width:190px">Status</th>
							</tr>
						</thead>
						<tbody id="wpi-tbody"></tbody>
					</table>
				</div>
			</div>

			<div class="wpi-card wpi-legal">
				<h2>⚠️ দয়া করে খেয়াল রাখুন</h2>
				<p>অন্যের সাইট থেকে প্রোডাক্ট কপি করে ইমপোর্ট করলে ওই সাইটের নিয়ম (<abbr title="Terms of Service">ToS</abbr>) ও ছবির কপিরাইটের সমস্যা হতে পারে। শুধু সেই সাইট থেকেই ইমপোর্ট করুন যেখানে আপনার অনুমতি আছে — নিজের সাইট, পার্টনার/সাপ্লায়ার সাইট, অথবা যে সাইট স্ক্র্যাপিং-অনুমোদিত। ইমপোর্টের পর ছবি ও কনটেন্ট যাচাই করে নিজের স্টোরে সাজিয়ে নিন।</p>
			</div>

			<div class="wpi-card wpi-author-card">
				<div class="wpi-author-info">
					<p><strong>Product Importer — URL to WooCommerce</strong> &bull; Developed with ❤️ by <a href="http://devalamin.me/" target="_blank" rel="noopener noreferrer"><strong>Md Alamin</strong></a></p>
					<p class="wpi-author-links">
						<a href="https://wa.me/8801676086772" target="_blank" rel="noopener noreferrer">💬 WhatsApp</a> &bull;
						<a href="mailto:developeralaminvi@gmail.com">✉️ Gmail</a> &bull;
						<a href="http://devalamin.me/" target="_blank" rel="noopener noreferrer">🌐 Website</a> &bull;
						<a href="https://www.linkedin.com/in/md-alaminvi/" target="_blank" rel="noopener noreferrer">💼 LinkedIn</a> &bull;
						<a href="https://www.upwork.com/freelancers/developeralamin" target="_blank" rel="noopener noreferrer">🟢 Upwork</a> &bull;
						<a href="https://www.fiverr.com/md_alaminvi" target="_blank" rel="noopener noreferrer">🟢 Fiverr</a> &bull;
						<a href="https://github.com/developeralaminvi" target="_blank" rel="noopener noreferrer">🐙 GitHub</a> &bull;
						<a href="https://www.facebook.com/developeralaminvai/" target="_blank" rel="noopener noreferrer">📘 Facebook</a>
					</p>
					<p class="wpi-copyright">Copyright &copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <a href="http://devalamin.me/" target="_blank" rel="noopener noreferrer">Md Alamin</a>. All rights reserved.</p>
				</div>
			</div>
		</div>
		<?php
	}
}
