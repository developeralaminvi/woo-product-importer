<?php
defined( 'ABSPATH' ) || exit;

/**
 * ALAMIN_PI_Admin
 *
 * Admin menu page, asset enqueueing and AJAX handlers.
 */
class ALAMIN_PI_Admin {

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
		add_action( 'wp_ajax_alamin_pi_fetch_products', array( $this, 'ajax_fetch_products' ) );
		add_action( 'wp_ajax_alamin_pi_import_products', array( $this, 'ajax_import_products' ) );
		add_action( 'wp_ajax_alamin_pi_import_products_batch', array( $this, 'ajax_import_products_batch' ) );
		add_action( 'wp_ajax_alamin_pi_fetch_site_categories', array( $this, 'ajax_fetch_site_categories' ) );
		add_action( 'wp_ajax_alamin_pi_prepare_site_category', array( $this, 'ajax_prepare_site_category' ) );
		add_action( 'wp_ajax_alamin_pi_import_site_category', array( $this, 'ajax_import_site_category' ) );
	}

	public function register_menu() {
		$this->hook = add_menu_page(
			__( 'Alamin Product Importer', 'alamin-product-importer' ),
			__( 'Alamin Product Importer', 'alamin-product-importer' ),
			'manage_options',
			'alamin-product-importer',
			array( $this, 'render_page' ),
			'dashicons-download',
			56
		);
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'wpi-admin', ALAMIN_PI_PLUGIN_URL . 'assets/css/admin.css', array(), ALAMIN_PI_VERSION );
		wp_enqueue_script( 'wpi-admin', ALAMIN_PI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), ALAMIN_PI_VERSION, true );
		wp_localize_script(
			'wpi-admin',
			'alamin_pi_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'alamin_pi_admin' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

    /** Decode structured input and sanitize all leaf values before use. */
    private static function sanitize_payload( $raw ) {
        $data = is_string( $raw ) ? json_decode( $raw, true, 32 ) : $raw;
        return is_array( $data ) ? map_deep( $data, 'wp_kses_post' ) : array();
    }

	public function ajax_fetch_products() {
		check_ajax_referer( 'alamin_pi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			wp_send_json_error( array( 'message' => 'Enter a publicly accessible HTTP or HTTPS source URL. Private network addresses are not supported.' ) );
		}

		$page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$deep = ! empty( $_POST['deep'] );

		$result = ALAMIN_PI_Scraper::fetch( $url, array( 'page' => $page, 'deep' => $deep ) );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_import_products() {
		check_ajax_referer( 'alamin_pi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_payload decodes JSON and applies wp_kses_post to every leaf.
		$product = isset( $_POST['product'] ) ? self::sanitize_payload( wp_unslash( $_POST['product'] ) ) : array();
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
			'status'     => ( isset( $_POST['status'] ) && 'publish' === sanitize_key( wp_unslash( $_POST['status'] ) ) ) ? 'publish' : 'draft',
			'skip_dup'   => ! empty( $_POST['skip_dup'] ),
			'categories' => $categories,
			'origin'     => isset( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : '',
		);

		$result = ALAMIN_PI_Import::import_product( $product, $options );
		wp_send_json_success( $result );
	}

	public function ajax_import_products_batch() {
		check_ajax_referer( 'alamin_pi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_payload decodes JSON and applies wp_kses_post to every leaf.
		$products = isset( $_POST['products'] ) ? self::sanitize_payload( wp_unslash( $_POST['products'] ) ) : array();
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
			'status'     => ( isset( $_POST['status'] ) && 'publish' === sanitize_key( wp_unslash( $_POST['status'] ) ) ) ? 'publish' : 'draft',
			'skip_dup'   => ! empty( $_POST['skip_dup'] ),
			'categories' => $categories,
			'origin'     => isset( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : '',
		);

		$result = ALAMIN_PI_Import::import_products_batch( $products, $options );
		wp_send_json_success( $result );
	}

	/**
	 * Full-site mode step 1: discover all categories / collections.
	 */
	public function ajax_fetch_site_categories() {
		check_ajax_referer( 'alamin_pi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			wp_send_json_error( array( 'message' => 'Enter a publicly accessible store URL, such as https://store.com' ) );
		}

		$result = ALAMIN_PI_Scraper::fetch_site_categories( $url );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Full-site mode step 2a: prepare category (create term + fetch products list for batching).
	 */
	public function ajax_prepare_site_category() {
		check_ajax_referer( 'alamin_pi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$origin  = isset( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_payload decodes JSON and applies wp_kses_post to every leaf.
		$cat = isset( $_POST['category'] ) ? self::sanitize_payload( wp_unslash( $_POST['category'] ) ) : array();

		if ( ! is_array( $cat ) || empty( $cat ) || ! wp_http_validate_url( $origin ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data received.' ) );
		}

		$result = ALAMIN_PI_Import::prepare_site_category( $origin, $cat );
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
		check_ajax_referer( 'alamin_pi_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$origin  = isset( $_POST['origin'] ) ? esc_url_raw( wp_unslash( $_POST['origin'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_payload decodes JSON and applies wp_kses_post to every leaf.
		$cat = isset( $_POST['category'] ) ? self::sanitize_payload( wp_unslash( $_POST['category'] ) ) : array();

		if ( ! is_array( $cat ) || empty( $cat ) || ! wp_http_validate_url( $origin ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data received.' ) );
		}

		$options = array(
			'status'   => ( isset( $_POST['status'] ) && 'publish' === sanitize_key( wp_unslash( $_POST['status'] ) ) ) ? 'publish' : 'draft',
			'skip_dup' => ! empty( $_POST['skip_dup'] ),
		);

		$result = ALAMIN_PI_Import::import_site_category( $origin, $cat, $options );
		wp_send_json_success( $result );
	}

	/* ------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------- */

	public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
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
			<div class="wpi-eyebrow"><?php esc_html_e( 'CATALOG TOOLS / URL IMPORT', 'alamin-product-importer' ); ?></div><h1><?php esc_html_e( 'Alamin Product Importer', 'alamin-product-importer' ); ?> <span class="wpi-ver"><?php esc_html_e( 'v', 'alamin-product-importer' ); ?><?php echo esc_html( ALAMIN_PI_VERSION ); ?></span></h1>
			<p class="description"><?php esc_html_e( 'Bring your catalog home. Preview products from a source URL, choose what to import, and build your WooCommerce catalog.', 'alamin-product-importer' ); ?></p>

            <div class="wpi-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Plugin sections', 'alamin-product-importer' ); ?>">
                <button type="button" class="wpi-tab is-active" role="tab" id="wpi-tab-import" aria-controls="wpi-panel-import" aria-selected="true" tabindex="0"><?php esc_html_e( 'Product Import', 'alamin-product-importer' ); ?></button>
                <button type="button" class="wpi-tab" role="tab" id="wpi-tab-developer" aria-controls="wpi-panel-developer" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Developer', 'alamin-product-importer' ); ?></button>
            </div>
            <section id="wpi-panel-import" role="tabpanel" aria-labelledby="wpi-tab-import">

			<?php if ( ! $wc_active ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'WooCommerce is not active.', 'alamin-product-importer' ); ?></strong> <?php esc_html_e( 'Install and activate WooCommerce before importing products.', 'alamin-product-importer' ); ?></p></div>
			<?php endif; ?>

			<div class="wpi-card">
				<h2><?php esc_html_e( '1. Connect your source', 'alamin-product-importer' ); ?></h2><p class="wpi-section-description"><?php esc_html_e( 'Choose how you want to bring products into your store.', 'alamin-product-importer' ); ?></p><div class="wpi-mode-row">
					<label class="wpi-mode"><input type="radio" name="wpi-mode" value="category" checked> <?php esc_html_e( 'Category or product URL', 'alamin-product-importer' ); ?></label>
					<label class="wpi-mode"><input type="radio" name="wpi-mode" value="site"> <?php esc_html_e( 'Entire store', 'alamin-product-importer' ); ?></label>
				</div>

				<label class="field" for="wpi-url"><?php esc_html_e( 'Source URL', 'alamin-product-importer' ); ?></label>
				<input type="url" id="wpi-url" class="code" placeholder="https://store.com/collections/men  or  https://store.com/product-category/men">
				<div class="wpi-actions">
					<button type="button" class="button button-primary button-hero" id="wpi-fetch"><?php esc_html_e( 'Preview source', 'alamin-product-importer' ); ?></button>
					<button type="button" class="button" id="wpi-stop" style="display:none"><?php esc_html_e( 'Stop', 'alamin-product-importer' ); ?></button>
					<span id="wpi-busy-label" class="wpi-busy-label"></span>
				</div>
				<p class="wpi-note" id="wpi-note"><?php esc_html_e( 'Use a Shopify product or collection URL, or a WooCommerce category URL. Choose Entire store to discover categories from a store homepage.', 'alamin-product-importer' ); ?></p>
			</div>

			<div class="wpi-card wpi-options">
				<h2><?php esc_html_e( '2. Set your import preferences', 'alamin-product-importer' ); ?></h2><p class="wpi-section-description"><?php esc_html_e( 'Products are saved as drafts by default so you can review them first.', 'alamin-product-importer' ); ?></p>

				<div id="wpi-single-options">
					<p>
						<label><input type="checkbox" id="wpi-deep" checked> <?php esc_html_e( 'Fetch full details (description + gallery images) — for WooCommerce sites', 'alamin-product-importer' ); ?></label><br>
						<label><input type="checkbox" id="wpi-skip-dup" checked> <?php esc_html_e( 'Skip existing products; link new categories when needed', 'alamin-product-importer' ); ?></label>
					</p>
					<div class="wpi-inline-group">
						<label class="field" for="wpi-status"><?php esc_html_e( 'Product status', 'alamin-product-importer' ); ?></label>
						<select id="wpi-status">
							<option value="draft"><?php esc_html_e( 'Draft', 'alamin-product-importer' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'alamin-product-importer' ); ?></option>
						</select>
					</div>
					<div class="wpi-inline-group">
						<label class="field" for="wpi-cats"><?php esc_html_e( 'Import into category (optional, hold Ctrl/Cmd for multiple)', 'alamin-product-importer' ); ?></label>
						<select id="wpi-cats" multiple size="4">
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div id="wpi-site-options" style="display:none">
					<p>
						<label><input type="checkbox" id="wpi-skip-dup-site" checked> <?php esc_html_e( 'Skip existing products; link new categories when needed', 'alamin-product-importer' ); ?></label><br>
						<label><input type="checkbox" id="wpi-hide-empty" checked> <?php esc_html_e( 'Hide empty categories when product counts are available', 'alamin-product-importer' ); ?></label>
					</p>
					<div class="wpi-inline-group">
						<label class="field" for="wpi-status-site"><?php esc_html_e( 'Product status', 'alamin-product-importer' ); ?></label>
						<select id="wpi-status-site">
							<option value="draft"><?php esc_html_e( 'Draft', 'alamin-product-importer' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'alamin-product-importer' ); ?></option>
						</select>
					</div>
					<p class="wpi-note"><?php esc_html_e( 'Source categories are created automatically. Available category images are downloaded, and products are assigned to their categories.', 'alamin-product-importer' ); ?></p>
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
					<button type="button" class="button" id="wpi-cat-select-all"><?php esc_html_e( 'Select all', 'alamin-product-importer' ); ?></button>
					<button type="button" class="button" id="wpi-cat-select-none"><?php esc_html_e( 'Clear', 'alamin-product-importer' ); ?></button>
					<button type="button" class="button button-primary" id="wpi-import-site"><?php esc_html_e( 'Import all products of selected categories', 'alamin-product-importer' ); ?></button>
					<span class="wpi-count"><?php esc_html_e( 'Categories:', 'alamin-product-importer' ); ?> <strong id="wpi-cat-count">0</strong></span>
				</div>
				<table class="widefat striped wpi-table">
					<thead>
						<tr>
							<th style="width:36px"></th>
							<th style="width:76px"><?php esc_html_e( 'Image', 'alamin-product-importer' ); ?></th>
							<th><?php esc_html_e( 'Category', 'alamin-product-importer' ); ?></th>
							<th style="width:100px"><?php esc_html_e( 'Products', 'alamin-product-importer' ); ?></th>
							<th style="width:240px"><?php esc_html_e( 'Status', 'alamin-product-importer' ); ?></th>
						</tr>
					</thead>
					<tbody id="wpi-cat-tbody"></tbody>
				</table>
			</div>

			<!-- Single-category mode: product preview -->
			<div id="wpi-empty" class="notice notice-warning" style="display:none">
				<p><?php esc_html_e( 'No products found on that link. Make sure it is a valid Shopify collection or WooCommerce category URL, and that the store does not block automated requests.', 'alamin-product-importer' ); ?></p>
			</div>

			<div id="wpi-results" style="display:none">
				<div class="wpi-card">
					<div class="wpi-toolbar">
						<button type="button" class="button" id="wpi-select-all"><?php esc_html_e( 'Select all', 'alamin-product-importer' ); ?></button>
						<button type="button" class="button" id="wpi-select-none"><?php esc_html_e( 'Clear', 'alamin-product-importer' ); ?></button>
						<button type="button" class="button button-primary" id="wpi-import-selected"><?php esc_html_e( 'Import selected', 'alamin-product-importer' ); ?></button>
						<button type="button" class="button" id="wpi-import-all"><?php esc_html_e( 'Import all (new)', 'alamin-product-importer' ); ?></button>
						<span class="wpi-count"><?php esc_html_e( 'Products:', 'alamin-product-importer' ); ?> <strong id="wpi-count">0</strong></span>
					</div>
					<table class="widefat striped wpi-table">
						<thead>
							<tr>
								<th style="width:36px"></th>
								<th style="width:76px"><?php esc_html_e( 'Image', 'alamin-product-importer' ); ?></th>
								<th><?php esc_html_e( 'Title', 'alamin-product-importer' ); ?></th>
								<th style="width:90px"><?php esc_html_e( 'Price', 'alamin-product-importer' ); ?></th>
								<th style="width:130px"><?php esc_html_e( 'SKU', 'alamin-product-importer' ); ?></th>
								<th style="width:190px"><?php esc_html_e( 'Status', 'alamin-product-importer' ); ?></th>
							</tr>
						</thead>
						<tbody id="wpi-tbody"></tbody>
					</table>
				</div>
			</div>

			<div class="wpi-card wpi-legal">
				<h2><?php esc_html_e( 'Import with permission', 'alamin-product-importer' ); ?></h2>
				<p><?php esc_html_e( 'Only import content you own or have permission to use. Loading a URL contacts the source store and may load its image servers for previews. Importing downloads selected images to your media library. Review content and prices before publishing.', 'alamin-product-importer' ); ?></p>
			</div>

            </section>
            <section id="wpi-panel-developer" role="tabpanel" aria-labelledby="wpi-tab-developer" hidden>
                <div class="wpi-card wpi-developer-hero">
                    <div class="wpi-avatar" aria-hidden="true">MA</div>
                    <div><p class="wpi-eyebrow"><?php esc_html_e( 'MEET THE DEVELOPER', 'alamin-product-importer' ); ?></p><h2>Md Alamin</h2><p><?php esc_html_e( 'WordPress Plugin Developer', 'alamin-product-importer' ); ?></p><p class="wpi-developer-bio"><?php esc_html_e( 'Creator and maintainer of Alamin Product Importer. Connect through the channels below for plugin support and development enquiries.', 'alamin-product-importer' ); ?></p></div>
                </div>
                <div class="wpi-card"><h2><?php esc_html_e( 'Contact & Profiles', 'alamin-product-importer' ); ?></h2><p class="wpi-section-description"><?php esc_html_e( 'Get in touch or explore my work.', 'alamin-product-importer' ); ?></p><dl class="wpi-contact-grid"><div class="wpi-contact"><dt><?php esc_html_e( 'Website', 'alamin-product-importer' ); ?></dt><dd><a href="https://devalamin.me/" target="_blank" rel="noopener noreferrer">devalamin.me</a></dd></div>
<div class="wpi-contact"><dt><?php esc_html_e( 'Email', 'alamin-product-importer' ); ?></dt><dd><a href="mailto:developeralaminvi@gmail.com">developeralaminvi@gmail.com</a></dd></div>
<div class="wpi-contact"><dt><?php esc_html_e( 'WhatsApp', 'alamin-product-importer' ); ?></dt><dd><a href="https://wa.me/8801676086772" target="_blank" rel="noopener noreferrer">+880 1676-086772</a></dd></div>
<div class="wpi-contact"><dt><?php esc_html_e( 'LinkedIn', 'alamin-product-importer' ); ?></dt><dd><a href="https://www.linkedin.com/in/md-alaminvi/" target="_blank" rel="noopener noreferrer">md-alaminvi</a></dd></div>
<div class="wpi-contact"><dt><?php esc_html_e( 'GitHub', 'alamin-product-importer' ); ?></dt><dd><a href="https://github.com/developeralaminvi" target="_blank" rel="noopener noreferrer">developeralaminvi</a></dd></div>
<div class="wpi-contact"><dt><?php esc_html_e( 'Upwork', 'alamin-product-importer' ); ?></dt><dd><a href="https://www.upwork.com/freelancers/developeralamin" target="_blank" rel="noopener noreferrer">developeralamin</a></dd></div>
<div class="wpi-contact"><dt><?php esc_html_e( 'Fiverr', 'alamin-product-importer' ); ?></dt><dd><a href="https://www.fiverr.com/md_alaminvi" target="_blank" rel="noopener noreferrer">md_alaminvi</a></dd></div>
<div class="wpi-contact"><dt><?php esc_html_e( 'Facebook', 'alamin-product-importer' ); ?></dt><dd><a href="https://www.facebook.com/developeralaminvai/" target="_blank" rel="noopener noreferrer">developeralaminvai</a></dd></div></dl></div>
                <div class="wpi-card wpi-project-card"><p><a href="https://devalamin.me/alamin-product-importer/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit the Plugin Website', 'alamin-product-importer' ); ?></a></p><h2><?php esc_html_e( 'Plugin Source & Support', 'alamin-product-importer' ); ?></h2><p><?php esc_html_e( 'Read the documentation, browse the source, or report an issue on GitHub.', 'alamin-product-importer' ); ?></p><a class="button button-primary" href="https://github.com/developeralaminvi/woo-product-importer" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Plugin Repository', 'alamin-product-importer' ); ?></a><p class="wpi-note"><?php esc_html_e( 'Licensed under GNU GPL version 2 or later.', 'alamin-product-importer' ); ?></p></div>
            </section>
			<footer class="wpi-footer">Alamin Product Importer &bull; <a href="https://devalamin.me/alamin-product-importer/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Plugin Website', 'alamin-product-importer' ); ?></a></footer>
		</div>
		<?php
	}
}
