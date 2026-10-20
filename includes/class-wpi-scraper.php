<?php
defined( 'ABSPATH' ) || exit;

/**
 * ALAMIN_PI_Scraper
 *
 * Detects the e-commerce platform from a category / collection URL and
 * pulls product data from it:
 *   - Shopify        -> uses the public /collections/{handle}/products.json feed
 *   - WooCommerce    -> parses the category HTML (listing + optional product pages)
 */
class ALAMIN_PI_Scraper {

	/** Safety cap on how many pages we will walk through. */
	const MAX_PAGES = 50;

	const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 WooCommerceProductImporter/1.0';

	/**
	 * Main entry: fetch one page of products from a category/collection URL.
	 *
	 * @param string $url  Category or collection link.
	 * @param array  $args 'page' => int, 'deep' => bool.
	 * @return array
	 */
	public static function fetch( $url, $args = array() ) {
		$page = ! empty( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$deep = ! empty( $args['deep'] );

		$platform = self::detect_platform( $url );

		// Unknown platform: probe Shopify first, otherwise parse as HTML.
		if ( 'unknown' === $platform ) {
			$platform = self::looks_like_shopify( $url ) ? 'shopify' : 'html';
		}

		if ( 'shopify' === $platform ) {
			return self::fetch_shopify( $url, $page );
		}

		// 'woocommerce' or 'html' -> parse the category page HTML.
		return self::fetch_woocommerce( $url, $page, $deep );
	}

	/**
	 * Guess the e-commerce platform from the URL.
	 *
	 * @param string $url
	 * @return string 'shopify' | 'woocommerce' | 'unknown'
	 */
	public static function detect_platform( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( preg_match( '#/(?:collections|products)/#i', $path ) || false !== strpos( $host, 'shopify' ) ) {
			return 'shopify';
		}
		if ( preg_match( '#/(?:product-category|product_tag|shop)(?:/|$)#i', $path ) ) {
			return 'woocommerce';
		}
		return 'unknown';
	}

	/**
	 * Probe: does {origin}/products.json exist? Every Shopify store exposes it.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function looks_like_shopify( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		$body   = self::http_get( $origin . '/products.json?limit=1' );
		$data   = json_decode( $body, true );
		return is_array( $data ) && array_key_exists( 'products', $data );
	}

	/**
	 * Scheme + host of a URL ('' on failure).
	 *
	 * @param string $url
	 * @return string
	 */
	private static function origin_of( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'];
	}

	/* ------------------------------------------------------------------
	 * Site-wide import: discover all categories / collections
	 * ---------------------------------------------------------------- */

	/**
	 * Given a store's homepage (or any page), find every category/collection.
	 *
	 * @param string $url
	 * @return array
	 */
	public static function fetch_site_categories( $url ) {
		$origin = self::origin_of( $url );
		if ( '' === $origin ) {
			return array( 'error' => 'Invalid URL.' );
		}

		$platform = self::detect_platform( $url );
		if ( 'unknown' === $platform ) {
			$platform = self::looks_like_shopify( $url ) ? 'shopify' : 'html';
		}

		if ( 'shopify' === $platform ) {
			return self::fetch_shopify_categories( $origin );
		}

		return self::fetch_woo_categories( $origin );
	}

	/**
	 * Shopify: GET /collections.json (all collections, with image + count).
	 *
	 * @param string $origin
	 * @return array
	 */
	private static function fetch_shopify_categories( $origin ) {
		$cats = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$api  = add_query_arg( array( 'limit' => 250, 'page' => $page ), $origin . '/collections.json' );
			$body = self::http_get( $api );
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || empty( $data['collections'] ) ) {
				break;
			}
			foreach ( $data['collections'] as $c ) {
				$image = '';
				if ( ! empty( $c['image']['src'] ) ) {
					$image = $c['image']['src'];
				}
				$handle = isset( $c['handle'] ) ? $c['handle'] : '';
				$cats[] = array(
					'id'       => isset( $c['id'] ) ? $c['id'] : '',
					'api_id'   => isset( $c['id'] ) ? $c['id'] : '',
					'handle'   => $handle,
					'title'    => isset( $c['title'] ) ? $c['title'] : $handle,
					'image'    => $image,
					'count'    => isset( $c['products_count'] ) ? (int) $c['products_count'] : 0,
					'url'      => $origin . '/collections/' . $handle,
					'platform' => 'shopify',
				);
			}
			if ( count( $data['collections'] ) < 250 ) {
				break;
			}
		}
		$cats = self::sort_categories( $cats );
		return array( 'platform' => 'shopify', 'categories' => $cats );
	}

	/**
	 * WooCommerce: public Store API categories first, HTML scan as fallback.
	 *
	 * @param string $origin
	 * @return array
	 */
	private static function fetch_woo_categories( $origin ) {
		$cats = self::fetch_woo_storeapi_categories( $origin );
		if ( ! empty( $cats ) ) {
			return array( 'platform' => 'woocommerce', 'categories' => $cats, 'via' => 'store-api' );
		}
		$cats = self::scan_html_categories( $origin );
		return array( 'platform' => 'woocommerce', 'categories' => $cats, 'via' => 'html' );
	}

	/**
	 * GET {origin}/wp-json/wc/store/v1/products/categories (public, no auth).
	 *
	 * @param string $origin
	 * @return array
	 */
	private static function fetch_woo_storeapi_categories( $origin ) {
		$cats = array();
		for ( $page = 1; $page <= 5; $page++ ) {
			$api  = add_query_arg( array( 'per_page' => 100, 'page' => $page ), $origin . '/wp-json/wc/store/v1/products/categories' );
			$body = self::http_get( $api );
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) || ! $data || isset( $data['code'] ) ) {
				break;
			}
			foreach ( $data as $c ) {
				if ( ! isset( $c['slug'] ) ) {
					continue;
				}
				$image = '';
				if ( ! empty( $c['image']['src'] ) ) {
					$image = $c['image']['src'];
				}
				$cats[] = array(
					'id'       => isset( $c['id'] ) ? $c['id'] : '',
					'api_id'   => isset( $c['id'] ) ? $c['id'] : '',
					'handle'   => $c['slug'],
					'title'    => isset( $c['name'] ) ? $c['name'] : $c['slug'],
					'image'    => $image,
					'count'    => isset( $c['count'] ) ? (int) $c['count'] : 0,
					'url'      => $origin . '/product-category/' . $c['slug'] . '/',
					'platform' => 'woocommerce',
				);
			}
			if ( count( $data ) < 100 ) {
				break;
			}
		}
		return self::sort_categories( $cats );
	}

	/**
	 * Sort categories by product count (desc), then title (asc), so real
	 * categories surface above internal / empty marketing collections.
	 *
	 * @param array $cats
	 * @return array
	 */
	private static function sort_categories( $cats ) {
		usort(
			$cats,
			function ( $a, $b ) {
				if ( (int) $a['count'] === (int) $b['count'] ) {
					return strcasecmp( (string) $a['title'], (string) $b['title'] );
				}
				return (int) $b['count'] - (int) $a['count'];
			}
		);
		return $cats;
	}

	/**
	 * HTML fallback: scan the homepage/shop page for product-category links,
	 * then fetch each category page for the name (+ og:image when present).
	 *
	 * @param string $origin
	 * @return array
	 */
	private static function scan_html_categories( $origin ) {
		$slugs = array();
		foreach ( array( $origin . '/', $origin . '/shop/' ) as $page_url ) {
			$html = self::http_get( $page_url );
			if ( '' === $html ) {
				continue;
			}
			if ( preg_match_all( '#/product-category/([A-Za-z0-9\-_]+)/?[^"\']*#i', $html, $m ) ) {
				foreach ( $m[1] as $s ) {
					$slugs[ strtolower( preg_replace( '/[^a-z0-9\-_]/i', '', $s ) ) ] = true;
				}
			}
		}

		$cats = array();
		foreach ( array_keys( $slugs ) as $slug ) {
			$url   = $origin . '/product-category/' . $slug . '/';
			$html  = self::http_get( $url );
			$title = ucwords( str_replace( '-', ' ', $slug ) );
			$image = '';

			if ( '' !== $html ) {
				if ( preg_match( '#<h1[^>]*class="[^"]*woocommerce-products-header__title[^"]*"[^>]*>(.*?)</h1>#is', $html, $m ) ) {
					$title = trim( wp_strip_all_tags( $m[1] ) );
				} elseif ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
					$title = trim( preg_replace( '/\s*[–—-|]\s*.+$/u', '', trim( wp_strip_all_tags( $m[1] ) ) ) );
				}
				if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m ) ) {
					$image = self::resolve_url( $m[1], $url );
				}
			}

			$cats[] = array(
				'id'       => '',
				'api_id'   => '',
				'handle'   => $slug,
				'title'    => $title,
				'image'    => $image,
				'count'    => 0,
				'url'      => $url,
				'platform' => 'woocommerce',
			);
		}

		return self::sort_categories( $cats );
	}

	/**
	 * Fetch ALL products of one category/collection (auto-paginated).
	 *
	 * @param string $origin
	 * @param array  $category Normalized category array (platform, handle, api_id).
	 * @param bool   $deep     Fetch full details for HTML-based stores.
	 * @return array
	 */
	public static function fetch_category_products_all( $origin, $category, $deep = false ) {
		$products = array();
		$platform = isset( $category['platform'] ) ? $category['platform'] : '';

		$current_cat = array();
		if ( ! empty( $category['title'] ) || ! empty( $category['handle'] ) ) {
			$current_cat = array(
				'name'  => ! empty( $category['title'] ) ? $category['title'] : $category['handle'],
				'slug'  => ! empty( $category['handle'] ) ? $category['handle'] : sanitize_title( $category['title'] ),
				'image' => ! empty( $category['image'] ) ? $category['image'] : '',
			);
		}

		if ( 'shopify' === $platform ) {
			for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
				$api  = add_query_arg( array( 'limit' => 250, 'page' => $page ), $origin . '/collections/' . $category['handle'] . '/products.json' );
				$body = self::http_get( $api );
				$data = json_decode( $body, true );
				if ( ! is_array( $data ) || empty( $data['products'] ) ) {
					break;
				}
				foreach ( $data['products'] as $p ) {
					if ( is_array( $p ) ) {
						$prod = self::normalize_shopify_product( $p, $origin );
						if ( ! empty( $current_cat ) ) {
							$has_cat = false;
							foreach ( $prod['categories'] as $c ) {
								if ( $c['slug'] === $current_cat['slug'] ) {
									$has_cat = true;
									break;
								}
							}
							if ( ! $has_cat ) {
								$prod['categories'][] = $current_cat;
							}
						}
						$products[] = $prod;
					}
				}
				if ( count( $data['products'] ) < 250 ) {
					break;
				}
			}
		} elseif ( ! empty( $category['api_id'] ) ) {
			// WooCommerce Store API (public) — full data, fast.
			for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
				$api  = add_query_arg( array( 'per_page' => 100, 'page' => $page, 'category' => $category['api_id'] ), $origin . '/wp-json/wc/store/v1/products' );
				$body = self::http_get( $api );
				$data = json_decode( $body, true );
				if ( ! is_array( $data ) || ! $data || isset( $data['code'] ) ) {
					break;
				}
				foreach ( $data as $p ) {
					$n = self::normalize_storeapi_product( $p );
					if ( $n ) {
						if ( ! empty( $current_cat ) ) {
							$has_cat = false;
							foreach ( $n['categories'] as $c ) {
								if ( $c['slug'] === $current_cat['slug'] ) {
									$has_cat = true;
									break;
								}
							}
							if ( ! $has_cat ) {
								$n['categories'][] = $current_cat;
							}
						}
						$products[] = $n;
					}
				}
				if ( count( $data ) < 100 ) {
					break;
				}
			}
		} else {
			// HTML fallback — walk the paged category archive.
			for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
				$r = self::fetch_woocommerce( $origin . '/product-category/' . $category['handle'] . '/', $page, $deep );
				if ( empty( $r['products'] ) ) {
					break;
				}
				foreach ( $r['products'] as $p ) {
					if ( ! empty( $current_cat ) ) {
						if ( empty( $p['categories'] ) ) {
							$p['categories'] = array();
						}
						$has_cat = false;
						foreach ( $p['categories'] as $c ) {
							if ( isset( $c['slug'] ) && $c['slug'] === $current_cat['slug'] ) {
								$has_cat = true;
								break;
							}
						}
						if ( ! $has_cat ) {
							$p['categories'][] = $current_cat;
						}
					}
					$products[] = $p;
				}
				if ( empty( $r['has_more'] ) ) {
					break;
				}
			}
		}

		return $products;
	}

	/**
	 * Normalize a WooCommerce Store API product into our shape.
	 *
	 * @param array $p
	 * @return array|null
	 */
	private static function normalize_storeapi_product( $p ) {
		if ( empty( $p['name'] ) ) {
			return null;
		}
		$prices  = isset( $p['prices'] ) && is_array( $p['prices'] ) ? $p['prices'] : array();
		$price   = isset( $prices['price'] ) ? $prices['price'] : '';
		$regular = isset( $prices['regular_price'] ) ? $prices['regular_price'] : $price;
		$sale    = isset( $prices['sale_price'] ) ? $prices['sale_price'] : '';
        // Store API amounts use the currency's smallest unit, not major units.
        $minor = isset( $prices['currency_minor_unit'] ) ? min( 8, absint( $prices['currency_minor_unit'] ) ) : 2;
        $to_major = static function ( $amount ) use ( $minor ) {
            return '' !== $amount && is_numeric( $amount ) ? number_format( (float) $amount / pow( 10, $minor ), $minor, '.', '' ) : '';
        };
        $price = $to_major( $price );
        $regular = $to_major( $regular );
        $sale = $to_major( $sale );

		$images = array();
		if ( ! empty( $p['images'] ) && is_array( $p['images'] ) ) {
			foreach ( $p['images'] as $img ) {
				if ( ! empty( $img['src'] ) ) {
					$images[] = $img['src'];
				}
			}
		}

		$categories = array();
		if ( ! empty( $p['categories'] ) && is_array( $p['categories'] ) ) {
			foreach ( $p['categories'] as $cat ) {
				if ( is_array( $cat ) && ! empty( $cat['name'] ) ) {
					$cat_img = '';
					if ( ! empty( $cat['image']['src'] ) ) {
						$cat_img = $cat['image']['src'];
					}
					$categories[] = array(
						'name'  => sanitize_text_field( $cat['name'] ),
						'slug'  => ! empty( $cat['slug'] ) ? sanitize_title( $cat['slug'] ) : sanitize_title( $cat['name'] ),
						'image' => $cat_img,
					);
				} elseif ( is_string( $cat ) && '' !== trim( $cat ) ) {
					$categories[] = array(
						'name'  => sanitize_text_field( $cat ),
						'slug'  => sanitize_title( $cat ),
						'image' => '',
					);
				}
			}
		}

		return array(
			'source_url'        => isset( $p['permalink'] ) ? $p['permalink'] : '',
			'title'             => $p['name'],
			'handle'            => isset( $p['slug'] ) ? $p['slug'] : '',
			'description'       => isset( $p['description'] ) ? $p['description'] : '',
			'short_description' => isset( $p['short_description'] ) ? $p['short_description'] : '',
			'price'             => $price,
			'compare_at_price'  => ( '' !== $sale && '' !== $regular ) ? $regular : '',
			'sku'               => isset( $p['sku'] ) ? $p['sku'] : '',
			'image'             => ! empty( $images ) ? $images[0] : '',
			'images'            => $images,
			'categories'        => $categories,
			'vendor'            => '',
			'product_type'      => '',
			'tags'              => '',
			'available'         => true,
		);
	}

	/* ------------------------------------------------------------------
	 * Shopify
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $url  e.g. https://store.com/collections/men
	 * @param int    $page
	 * @return array
	 */
	public static function fetch_shopify( $url, $page = 1 ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return array( 'error' => 'Invalid URL.' );
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		$collection_handle = '';

		if ( preg_match( '#/products/([^/]+)/?#i', $path, $m ) ) {
			// Single product page.
			$api = $origin . '/products/' . $m[1] . '.json';
		} elseif ( preg_match( '#/collections/([^/]+)#i', $path, $m ) ) {
			// Category-scoped: only products of THIS collection.
			$collection_handle = $m[1];
			$api = $origin . '/collections/' . $m[1] . '/products.json';
		} else {
			// Not a product/collection URL. Never silently import the whole
			// store — only the category the user asked for. Try to derive the
			// collection handle from the page; otherwise refuse with a hint.
			$handle = self::find_shopify_collection_handle( $url, $origin );
			if ( '' === $handle ) {
				return array(
					'error' => 'This does not look like a Shopify collection link. Please paste the full collection URL, e.g. https://store.com/collections/your-collection-name — only the products of that collection will be imported.',
				);
			}
			$collection_handle = $handle;
			$api = $origin . '/collections/' . $handle . '/products.json';
		}

		$api  = add_query_arg( array( 'limit' => 250, 'page' => $page ), $api );
		$body = self::http_get( $api );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return array( 'error' => 'Could not read the Shopify product feed. The store may block automated requests.' );
		}

		$raw = isset( $data['product'] ) ? array( $data['product'] ) : ( isset( $data['products'] ) ? $data['products'] : array() );

		$products = array();
		foreach ( (array) $raw as $p ) {
			if ( is_array( $p ) ) {
				$prod = self::normalize_shopify_product( $p, $origin );
				if ( '' !== $collection_handle ) {
					$coll_name = ucwords( str_replace( '-', ' ', $collection_handle ) );
					$has_cat = false;
					foreach ( $prod['categories'] as $c ) {
						if ( $c['slug'] === $collection_handle ) {
							$has_cat = true;
							break;
						}
					}
					if ( ! $has_cat ) {
						$prod['categories'][] = array(
							'name'  => $coll_name,
							'slug'  => $collection_handle,
							'image' => '',
						);
					}
				}
				$products[] = $prod;
			}
		}

		return array(
			'platform' => 'shopify',
			'products' => $products,
			'page'     => $page,
			'has_more' => count( $raw ) > 0 && $page < self::MAX_PAGES,
			'max_page' => 0,
		);
	}

	/**
	 * Turn a Shopify product array into our normalized shape.
	 *
	 * @param array  $p
	 * @param string $origin
	 * @return array
	 */
	private static function normalize_shopify_product( $p, $origin ) {
		$variants = ! empty( $p['variants'] ) && is_array( $p['variants'] ) ? $p['variants'] : array();
		$first    = ! empty( $variants ) ? $variants[0] : array();

		$images = array();
		if ( ! empty( $p['images'] ) && is_array( $p['images'] ) ) {
			foreach ( $p['images'] as $img ) {
				if ( ! empty( $img['src'] ) && is_string( $img['src'] ) ) {
					$resolved_img = self::resolve_url( $img['src'], $origin );
					if ( '' !== $resolved_img ) {
						$images[] = $resolved_img;
					}
				}
			}
		}

		$body_html = isset( $p['body_html'] ) ? $p['body_html'] : '';
		$plain     = wp_strip_all_tags( $body_html );

		$categories = array();
		if ( ! empty( $p['product_type'] ) && is_string( $p['product_type'] ) && '' !== trim( $p['product_type'] ) ) {
			$categories[] = array(
				'name'  => sanitize_text_field( trim( $p['product_type'] ) ),
				'slug'  => sanitize_title( $p['product_type'] ),
				'image' => '',
			);
		}

		return array(
			'source_url'        => $origin . '/products/' . ( isset( $p['handle'] ) ? $p['handle'] : '' ),
			'title'             => isset( $p['title'] ) ? $p['title'] : '',
			'handle'            => isset( $p['handle'] ) ? $p['handle'] : '',
			'description'       => $body_html,
			'short_description' => wp_trim_words( $plain, 40, '…' ),
			'price'             => isset( $first['price'] ) ? $first['price'] : '',
			'compare_at_price'  => isset( $first['compare_at_price'] ) ? $first['compare_at_price'] : '',
			'sku'               => isset( $first['sku'] ) ? $first['sku'] : '',
			'barcode'           => isset( $first['barcode'] ) ? $first['barcode'] : '',
			'image'             => ! empty( $images ) ? $images[0] : '',
			'images'            => $images,
			'categories'        => $categories,
			'vendor'            => isset( $p['vendor'] ) ? $p['vendor'] : '',
			'product_type'      => isset( $p['product_type'] ) ? $p['product_type'] : '',
			'tags'              => isset( $p['tags'] ) ? $p['tags'] : '',
			'available'         => isset( $first['available'] ) ? (bool) $first['available'] : true,
		);
	}

	/**
	 * Try to derive the collection handle from a Shopify page's canonical URL.
	 * Only trusts the canonical link / JSON-LD breadcrumb — never guesses
	 * from navigation links, so we can't pull in a different category.
	 *
	 * @param string $page_url
	 * @param string $origin
	 * @return string
	 */
	private static function find_shopify_collection_handle( $page_url, $origin ) {
		$html = self::http_get( $page_url );
		if ( '' === $html ) {
			return '';
		}

		if ( preg_match( '#<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m ) ) {
			$href = $m[1];
			if ( false !== strpos( $href, '/collections/' ) ) {
				$h = self::extract_collection_handle( $href );
				if ( '' !== $h ) {
					return $h;
				}
			}
		}

		// JSON-LD breadcrumb fallback: "item": "https://store.com/collections/x"
		if ( preg_match( '#"item"\s*:\s*"([^"]*/collections/[^"]+)"#i', $html, $m ) ) {
			$h = self::extract_collection_handle( $m[1] );
			if ( '' !== $h ) {
				return $h;
			}
		}

		return '';
	}

	/**
	 * Pull the collection handle out of a /collections/… URL.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function extract_collection_handle( $url ) {
		if ( preg_match( '#/collections/([^/?]+)#i', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * WooCommerce / generic HTML category pages
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $url   Category URL.
	 * @param int    $page  Page number.
	 * @param bool   $deep  Fetch full details (description + gallery) from each product page.
	 * @return array
	 */
	public static function fetch_woocommerce( $url, $page = 1, $deep = false ) {
		$target = $url;
		if ( $page > 1 ) {
			$target = add_query_arg( 'paged', $page, $url );
		}

		$html = self::http_get( $target );
		if ( '' === $html ) {
			return array( 'error' => 'Could not open the category page. The site may block automated requests.' );
		}

		$items    = self::parse_product_list( $html, $target );
		$max_page = self::detect_max_page( $html );

		$cat_name = '';
		$cat_slug = '';
		if ( preg_match( '#/product-category/([^/?\#]+)#i', $url, $m_cat ) ) {
			$cat_slug = sanitize_title( $m_cat[1] );
			$cat_name = ucwords( str_replace( '-', ' ', $cat_slug ) );
		}
		if ( preg_match( '#<h1[^>]*class="[^"]*woocommerce-products-header__title[^"]*"[^>]*>(.*?)</h1>#is', $html, $m_h1 ) ) {
			$cat_name = trim( wp_strip_all_tags( $m_h1[1] ) );
		}

		$listing_cats = array();
		if ( '' !== $cat_name ) {
			$listing_cats[] = array(
				'name'  => $cat_name,
				'slug'  => $cat_slug ? $cat_slug : sanitize_title( $cat_name ),
				'image' => '',
			);
		}

		$products = array();
		foreach ( $items as $item ) {
			$product = array(
				'source_url'        => $item['url'],
				'title'             => $item['title'],
				'image'             => $item['image'],
				'images'            => $item['image'] ? array( $item['image'] ) : array(),
				'categories'        => $listing_cats,
				'price'             => $item['price'],
				'compare_at_price'  => $item['compare_at_price'],
				'description'       => '',
				'short_description' => '',
				'sku'               => '',
				'vendor'            => '',
				'product_type'      => '',
				'tags'              => '',
				'available'         => true,
			);

			if ( $deep && ! empty( $item['url'] ) ) {
				$detail = self::fetch_woo_product_page( $item['url'] );
				foreach ( $detail as $k => $v ) {
					if ( 'images' === $k ) {
						if ( ! empty( $v ) ) {
							$product[ $k ] = $v;
						}
					} elseif ( 'categories' === $k ) {
						if ( ! empty( $v ) && is_array( $v ) ) {
							foreach ( $v as $new_cat ) {
								$has_it = false;
								foreach ( $product['categories'] as $existing_c ) {
									if ( isset( $existing_c['slug'], $new_cat['slug'] ) && $existing_c['slug'] === $new_cat['slug'] ) {
										$has_it = true;
										break;
									}
								}
								if ( ! $has_it ) {
									$product['categories'][] = $new_cat;
								}
							}
						}
					} elseif ( '' !== $v && null !== $v ) {
						$product[ $k ] = $v;
					}
				}
			}

			$products[] = $product;
		}

		$has_more = $max_page > $page || ( $max_page < 1 && count( $items ) > 0 && $page < self::MAX_PAGES );

		return array(
			'platform' => 'woocommerce',
			'products' => $products,
			'page'     => $page,
			'has_more' => $has_more,
			'max_page' => $max_page,
		);
	}

	/**
	 * Extract product cards from a listing page.
	 *
	 * @param string $html
	 * @param string $base_url
	 * @return array
	 */
	private static function parse_product_list( $html, $base_url = '' ) {
		if ( class_exists( 'DOMDocument' ) && function_exists( 'libxml_use_internal_errors' ) ) {
			$items = self::parse_product_list_dom( $html, $base_url );
			if ( ! empty( $items ) ) {
				return $items;
			}
		}
		return self::parse_product_list_regex( $html, $base_url );
	}

	/**
	 * DOMDocument-based product card extraction.
	 *
	 * @param string $html
	 * @param string $base_url
	 * @return array
	 */
	private static function parse_product_list_dom( $html, $base_url = '' ) {
		$doc = self::load_dom( $html );
		if ( ! $doc ) {
			return array();
		}

		$xp = new DOMXPath( $doc );

		// Strategy 1: score every element whose class list contains the exact
		// token "products" (ul.products / div.products). The category's main
		// loop lives in the main content area, so it wins over sidebar or
		// related-products widgets even when those contain more cards.
		$best_score = -PHP_INT_MAX;
		$best       = array();
		$containers = $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " products ")]' );
		foreach ( $containers as $container ) {
			$cards = self::parse_cards_in_container( $xp, $container, $base_url );
			if ( empty( $cards ) ) {
				continue;
			}
			$score = self::container_score( $container, count( $cards ) );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $cards;
			}
		}
		if ( ! empty( $best ) ) {
			return $best;
		}

		// Strategy 2: no "products" container — group all <li class="product">
		// cards by their parent list and keep the largest group.
		$groups = array();
		$nodes  = $xp->query( '//li[contains(concat(" ", normalize-space(@class), " "), " product ")]' );
		foreach ( $nodes as $node ) {
			$parent = $node->parentNode;
			$key    = spl_object_hash( $parent );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'parent' => $parent, 'nodes' => array() );
			}
			$groups[ $key ]['nodes'][] = $node;
		}
		$best = self::best_group( $xp, $groups, $base_url );
		if ( ! empty( $best ) ) {
			return $best;
		}

		// Strategy 3: generic "product" elements, grouped by parent.
		$groups = array();
		$nodes  = $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " product ")]' );
		foreach ( $nodes as $node ) {
			if ( in_array( strtolower( $node->nodeName ), array( 'html', 'body', 'ul', 'ol' ), true ) ) {
				continue;
			}
			$parent = $node->parentNode;
			$key    = spl_object_hash( $parent );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'parent' => $parent, 'nodes' => array() );
			}
			$groups[ $key ]['nodes'][] = $node;
		}
		return self::best_group( $xp, $groups, $base_url );
	}

	/**
	 * Parse all product cards inside one container (deduped by URL).
	 *
	 * @param DOMXPath $xp
	 * @param DOMNode  $container
	 * @param string   $base_url
	 * @return array
	 */
	private static function parse_cards_in_container( DOMXPath $xp, DOMNode $container, $base_url = '' ) {
		$items = array();
		$seen  = array();

		$nodes = $xp->query( './/li[contains(concat(" ", normalize-space(@class), " "), " product ")]', $container );
		if ( ! $nodes->length ) {
			$nodes = $xp->query( './/*[contains(concat(" ", normalize-space(@class), " "), " product ")]', $container );
		}

		foreach ( $nodes as $node ) {
			if ( count( $items ) > 500 ) {
				break;
			}
			$item = self::parse_card( $xp, $node, $base_url );
			if ( $item && ( $item['title'] || $item['image'] ) && ! isset( $seen[ $item['url'] ] ) ) {
				$seen[ $item['url'] ] = true;
				$items[]              = $item;
			}
		}

		return $items;
	}

	/**
	 * Score a container: more cards is better, but being inside the main
	 * content area beats being inside a sidebar / widget / related section.
	 *
	 * @param DOMNode $node
	 * @param int     $count
	 * @return int
	 */
	private static function container_score( DOMNode $node, $count ) {
		$score = $count * 10;
		$good  = array( 'main', 'site-main', 'primary', 'content', 'woocommerce', 'archive', 'shop', 'products' );
		$bad   = array( 'sidebar', 'secondary', 'footer', 'widget', 'widget-area', 'aside', 'related', 'upsells', 'cross-sells' );

		for ( $p = $node; $p instanceof DOMElement; $p = $p->parentNode ) {
			$tokens = preg_split( '/\s+/', trim( $p->getAttribute( 'class' ) . ' ' . $p->getAttribute( 'id' ) ) );
			foreach ( $tokens as $t ) {
				$t = strtolower( $t );
				if ( '' !== $t && in_array( $t, $good, true ) ) {
					$score += 15;
				}
				if ( '' !== $t && in_array( $t, $bad, true ) ) {
					$score -= 30;
				}
			}
		}

		return $score;
	}

	/**
	 * From groups of product nodes (grouped by parent), return the cards of
	 * the highest-scoring group.
	 *
	 * @param DOMXPath $xp
	 * @param array    $groups
	 * @param string   $base_url
	 * @return array
	 */
	private static function best_group( DOMXPath $xp, $groups, $base_url = '' ) {
		$best_score = -PHP_INT_MAX;
		$best       = array();

		foreach ( $groups as $group ) {
			$cards = array();
			$seen  = array();
			foreach ( $group['nodes'] as $node ) {
				if ( count( $cards ) > 500 ) {
					break;
				}
				$item = self::parse_card( $xp, $node, $base_url );
				if ( $item && ( $item['title'] || $item['image'] ) && ! isset( $seen[ $item['url'] ] ) ) {
					$seen[ $item['url'] ] = true;
					$cards[]              = $item;
				}
			}
			if ( empty( $cards ) ) {
				continue;
			}
			$score = self::container_score( $group['parent'], count( $cards ) );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $cards;
			}
		}

		return $best;
	}

	/**
	 * Parse a single product card node.
	 *
	 * @param DOMXPath $xp
	 * @param DOMNode  $node
	 * @param string   $base_url
	 * @return array|null
	 */
	private static function parse_card( DOMXPath $xp, DOMNode $node, $base_url = '' ) {
		// Sub-category tiles ("product-category") are not products — skip.
		if ( $node instanceof DOMElement && preg_match( '/(?:^|\s)product-category(?:\s|$)/i', ' ' . $node->getAttribute( 'class' ) . ' ' ) ) {
			return null;
		}

		// URL -----------------------------------------------------------
		$url     = '';
		$anchors = $xp->query( './/a[@href]', $node );
		foreach ( $anchors as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href || 0 === strpos( $href, 'javascript:' ) ) {
				continue;
			}
			if ( false !== strpos( $href, 'add_to_cart' ) || false !== strpos( $href, 'add-to-cart' ) ) {
				continue;
			}
			$url = $href;
			if ( false !== strpos( $href, '/product/' ) ) {
				break; // prefer the canonical product link
			}
		}
		if ( '' === $url ) {
			return null;
		}
		if ( '' !== $base_url ) {
			$url = self::resolve_url( $url, $base_url );
		}

		// Title ---------------------------------------------------------
		$title = '';
		$selectors = array(
			'.//h2[contains(concat(" ", normalize-space(@class), " "), " woocommerce-loop-product__title ")]',
			'.//h3[contains(concat(" ", normalize-space(@class), " "), " product-title ")]',
			'.//*[contains(concat(" ", normalize-space(@class), " "), " product__title ")]',
			'.//*[contains(concat(" ", normalize-space(@class), " "), " product-name ")]',
			'.//h2',
			'.//h3',
		);
		foreach ( $selectors as $s ) {
			$n = $xp->query( $s, $node );
			if ( $n->length ) {
				$title = trim( $n->item( 0 )->textContent );
				if ( '' !== $title ) {
					break;
				}
			}
		}
		if ( '' === $title ) {
			// Last resort: first non-empty line of text.
			$lines = preg_split( '/\s{2,}|[\r\n]+/', trim( $node->textContent ) );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$title = $line;
					break;
				}
			}
		}

		// Image ---------------------------------------------------------
		$image = '';
		$imgs  = $xp->query( './/img', $node );
		foreach ( $imgs as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
				$src = $img->getAttribute( 'data-src' );
			}
			if ( '' === $src ) {
				$src = $img->getAttribute( 'data-lazy-src' );
			}
			if ( '' !== $src ) {
				$image = $src;
				break;
			}
		}
		if ( '' !== $image && '' !== $base_url ) {
			$image = self::resolve_url( $image, $base_url );
		}

		// Price ---------------------------------------------------------
		$price_text = '';
		$prices     = $xp->query( './/*[contains(concat(" ", normalize-space(@class), " "), " price ")]', $node );
		if ( $prices->length ) {
			$price_text = self::dom_inner_html( $prices->item( 0 ) );
		}
		$parsed = self::parse_price_pair( $price_text );

		if ( '' === $title && '' === $image ) {
			return null; // not a real product card
		}

		return array(
			'url'              => $url,
			'title'            => $title,
			'image'            => $image,
			'price'            => $parsed['price'],
			'compare_at_price' => $parsed['compare'],
		);
	}

	/**
	 * Regex fallback for environments without DOMDocument.
	 *
	 * @param string $html
	 * @param string $base_url
	 * @return array
	 */
	private static function parse_product_list_regex( $html, $base_url = '' ) {
		$items = array();

		// Prefer the main products container (ul.products / div.products) so
		// sidebar widgets and related sections stay out of the result.
		$blocks_html = $html;
		if ( preg_match( '#<(?:ul|div|ol)[^>]*class="[^"]*\bproducts\b[^"]*"[^>]*>(.*?)</(?:ul|div|ol)>#is', $html, $m ) ) {
			$blocks_html = $m[1];
		}

		if ( ! preg_match_all( '#<li[^>]*class="[^"]*\bproduct\b[^"]*"[^>]*>(.*?)</li>#is', $blocks_html, $blocks ) ) {
			return $items;
		}

		foreach ( $blocks[1] as $block ) {
			// Sub-category tiles (li.product-category) are not products.
			if ( preg_match( '#class="[^"]*\bproduct-category\b#i', $block ) ) {
				continue;
			}

			$item = array(
				'url'              => '',
				'title'            => '',
				'image'            => '',
				'price'            => '',
				'compare_at_price' => '',
			);

			if ( preg_match( '#<a[^>]+href="([^"]+)"#is', $block, $m ) ) {
				$item['url'] = $m[1];
				if ( '' !== $base_url ) {
					$item['url'] = self::resolve_url( $item['url'], $base_url );
				}
			}
			if ( preg_match( '#<h[23][^>]*class="[^"]*(?:woocommerce-loop-product__title|product-title|product-name)[^"]*"[^>]*>(.*?)</h[23]>#is', $block, $m ) ) {
				$item['title'] = trim( wp_strip_all_tags( $m[1] ) );
			}
			if ( '' === $item['title'] && preg_match( '#<h[23][^>]*>(.*?)</h[23]>#is', $block, $m ) ) {
				$item['title'] = trim( wp_strip_all_tags( $m[1] ) );
			}

			foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attr ) {
				if ( preg_match( '#<img[^>]+' . $attr . '="([^"]+)"#is', $block, $m ) ) {
					$item['image'] = $m[1];
					if ( '' !== $base_url ) {
						$item['image'] = self::resolve_url( $item['image'], $base_url );
					}
					break;
				}
			}

			if ( preg_match( '#<(?:span|p|div)[^>]*class="[^"]*price[^"]*"[^>]*>(.*?)</(?:span|p|div)>#is', $block, $m ) ) {
				$parsed             = self::parse_price_pair( $m[1] );
				$item['price']      = $parsed['price'];
				$item['compare_at_price'] = $parsed['compare'];
			}

			if ( '' === $item['title'] && '' === $item['image'] ) {
				continue;
			}
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Find the biggest page number referenced by the pagination.
	 *
	 * @param string $html
	 * @return int
	 */
	public static function detect_max_page( $html ) {
		$max = 1;
		if ( preg_match_all( '#[?&]paged=(\d+)#i', $html, $m ) ) {
			$max = max( $max, max( array_map( 'intval', $m[1] ) ) );
		}
		if ( preg_match_all( '#/page/(\d+)/?#i', $html, $m ) ) {
			$max = max( $max, max( array_map( 'intval', $m[1] ) ) );
		}
		if ( $max < 2 && preg_match( '#class="[^"]*next[^"]*page-numbers[^"]*"#i', $html ) ) {
			$max = 2;
		}
		return min( $max, self::MAX_PAGES );
	}

	/**
	 * Fetch one WooCommerce product page for description, gallery, SKU, price and categories.
	 *
	 * @param string $url
	 * @return array
	 */
	public static function fetch_woo_product_page( $url ) {
		$html = self::http_get( $url );
		$out  = array();

		if ( '' === $html ) {
			return $out;
		}

		// Match the innermost <span class="sku"> (not sku_wrapper etc.).
		if ( preg_match( '#<span[^>]*class="[^"]*\bsku\b[^"]*"[^>]*>(.*?)</span>#is', $html, $m ) ) {
			$out['sku'] = trim( wp_strip_all_tags( $m[1] ) );
		}

		$categories = array();

		// Strategy 1: posted_in (standard WooCommerce product meta)
		if ( preg_match( '#<span[^>]*class="[^"]*\bposted_in\b[^"]*"[^>]*>(.*?)</span>#is', $html, $m_posted ) ) {
			if ( preg_match_all( '#<a[^>]+href="([^"]*)"[^>]*>(.*?)</a>#is', $m_posted[1], $m_links, PREG_SET_ORDER ) ) {
				foreach ( $m_links as $link ) {
					$c_name = trim( wp_strip_all_tags( $link[2] ) );
					$c_href = $link[1];
					$c_slug = '';
					if ( preg_match( '#/product-category/([^/?\#]+)#i', $c_href, $sm ) ) {
						$c_slug = sanitize_title( $sm[1] );
					}
					if ( '' !== $c_name ) {
						$categories[] = array(
							'name'  => $c_name,
							'slug'  => $c_slug ? $c_slug : sanitize_title( $c_name ),
							'image' => '',
						);
					}
				}
			}
		}

		$doc = self::load_dom( $html );
		if ( $doc ) {
			$xp = new DOMXPath( $doc );

			// Description.
			$q = $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " woocommerce-Tabs-panel--description ")]' );
			if ( $q->length ) {
				$out['description'] = self::dom_inner_html( $q->item( 0 ) );
			}

			$q2 = $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " woocommerce-product-details__short-description ")]' );
			if ( $q2->length ) {
				$out['short_description'] = self::dom_inner_html( $q2->item( 0 ) );
			}

			if ( empty( $out['description'] ) && ! empty( $out['short_description'] ) ) {
				$out['description'] = $out['short_description'];
			}
			if ( empty( $out['description'] ) ) {
				$q3 = $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]' );
				if ( $q3->length ) {
					$out['description'] = self::dom_inner_html( $q3->item( 0 ) );
				}
			}

			// Gallery images.
			$gallery = array();
			$q4      = $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " woocommerce-product-gallery ")]//img' );
			foreach ( $q4 as $img ) {
				$src = $img->getAttribute( 'src' );
				if ( '' === $src ) {
					$src = $img->getAttribute( 'data-src' );
				}
				if ( '' === $src ) {
					$src = $img->getAttribute( 'data-lazy-src' );
				}
				if ( '' !== $src ) {
					$resolved_src = self::resolve_url( $src, $url );
					if ( '' !== $resolved_src ) {
						$gallery[] = $resolved_src;
					}
				}
			}
			if ( ! empty( $gallery ) ) {
				$out['images'] = $gallery;
			}

			// Price (more accurate than the listing page).
			$q5 = $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " price ")][1]' );
			if ( $q5->length ) {
				$parsed = self::parse_price_pair( self::dom_inner_html( $q5->item( 0 ) ) );
				if ( '' !== $parsed['price'] ) {
					$out['price'] = $parsed['price'];
				}
				if ( '' !== $parsed['compare'] ) {
					$out['compare_at_price'] = $parsed['compare'];
				}
			}

			// DOM category lookup fallback if regex didn't find any
			if ( empty( $categories ) ) {
				$xp_cats = $xp->query( '//span[contains(concat(" ", normalize-space(@class), " "), " posted_in ")]//a' );
				if ( $xp_cats && $xp_cats->length ) {
					foreach ( $xp_cats as $a_node ) {
						$c_name = trim( $a_node->textContent );
						$c_href = $a_node->getAttribute( 'href' );
						$c_slug = '';
						if ( preg_match( '#/product-category/([^/?\#]+)#i', $c_href, $sm ) ) {
							$c_slug = sanitize_title( $sm[1] );
						}
						if ( '' !== $c_name ) {
							$categories[] = array(
								'name'  => $c_name,
								'slug'  => $c_slug ? $c_slug : sanitize_title( $c_name ),
								'image' => '',
							);
						}
					}
				}
			}
		} else {
			// Regex fallback.
			if ( preg_match( '#<div[^>]*class="[^"]*woocommerce-Tabs-panel--description[^"]*"[^>]*>(.*?)</div>#is', $html, $m ) ) {
				$out['description'] = $m[1];
			}
			if ( empty( $out['description'] ) && preg_match( '#<div[^>]*class="[^"]*woocommerce-product-details__short-description[^"]*"[^>]*>(.*?)</div>#is', $html, $m ) ) {
				$out['description'] = $m[1];
			}
			if ( preg_match_all( '#<img[^>]+(?:src|data-src|data-lazy-src)="([^"]+)"#is', $html, $m ) ) {
				$gallery = array();
				foreach ( $m[1] as $img_src ) {
					$resolved = self::resolve_url( $img_src, $url );
					if ( '' !== $resolved ) {
						$gallery[] = $resolved;
					}
				}
				$out['images'] = $gallery;
			}
		}

		// Strategy 3: JSON-LD BreadcrumbList / Category fallback
		if ( empty( $categories ) && preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $json_matches ) ) {
			foreach ( $json_matches[1] as $json_str ) {
				$json_data = json_decode( trim( $json_str ), true );
				if ( ! is_array( $json_data ) ) {
					continue;
				}
				$graph = isset( $json_data['@graph'] ) ? $json_data['@graph'] : array( $json_data );
				foreach ( $graph as $item ) {
					if ( isset( $item['@type'] ) && ( 'BreadcrumbList' === $item['@type'] || ( is_array( $item['@type'] ) && in_array( 'BreadcrumbList', $item['@type'], true ) ) ) && ! empty( $item['itemListElement'] ) && is_array( $item['itemListElement'] ) ) {
						$elements   = $item['itemListElement'];
						$count_elem = count( $elements );
						for ( $idx = 1; $idx < $count_elem - 1; $idx++ ) {
							$elem     = $elements[ $idx ];
							$name     = isset( $elem['name'] ) ? trim( (string) $elem['name'] ) : ( isset( $elem['item']['name'] ) ? trim( (string) $elem['item']['name'] ) : '' );
							$elem_url = isset( $elem['item'] ) && is_string( $elem['item'] ) ? $elem['item'] : ( isset( $elem['item']['@id'] ) ? $elem['item']['@id'] : '' );
							$slug     = '';
							if ( preg_match( '#/product-category/([^/?\#]+)#i', $elem_url, $sm ) ) {
								$slug = sanitize_title( $sm[1] );
							}
							if ( '' !== $name && ! in_array( strtolower( $name ), array( 'home', 'shop', 'products' ), true ) ) {
								$categories[] = array(
									'name'  => $name,
									'slug'  => $slug ? $slug : sanitize_title( $name ),
									'image' => '',
								);
							}
						}
					}
				}
			}
		}

		if ( ! empty( $out['images'] ) ) {
			$out['images'] = array_values( array_unique( array_filter( $out['images'], function ( $i ) {
				return 0 === strpos( $i, 'http' );
			} ) ) );
		} else {
			$out['images'] = array();
		}

		$out['categories'] = $categories;

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Resolve relative or protocol-relative URLs into absolute URLs.
	 *
	 * @param string $url
	 * @param string $base_url
	 * @return string
	 */
	public static function resolve_url( $url, $base_url ) {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url || 0 === strpos( $url, 'javascript:' ) || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
			return ( $scheme ? $scheme : 'https' ) . ':' . $url;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$base_parts = wp_parse_url( $base_url );
		if ( empty( $base_parts['host'] ) ) {
			return $url;
		}
		$scheme = ! empty( $base_parts['scheme'] ) ? $base_parts['scheme'] : 'https';
		$host   = $base_parts['host'];
		$port   = ! empty( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';
		$origin = $scheme . '://' . $host . $port;

		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}
		$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
		$dir       = preg_replace( '#/[^/]*$#', '/', $base_path );
		if ( '' === $dir || 0 !== strpos( $dir, '/' ) ) {
			$dir = '/' . $dir;
		}
		return $origin . rtrim( $dir, '/' ) . '/' . ltrim( $url, '/' );
	}

	/**
	 * Parse a price block that may contain <del>/<ins> sale markup.
	 *
	 * @param string $html_text
	 * @return array { price: string, compare: string }
	 */
	public static function parse_price_pair( $html_text ) {
		$price   = '';
		$compare = '';

		if ( preg_match( '#<ins[^>]*>(.*?)</ins>#is', $html_text, $m ) ) {
			$price = self::parse_price( $m[1] );
		}
		if ( preg_match( '#<(?:del|s|strike)[^>]*>(.*?)</(?:del|s|strike)>#is', $html_text, $m ) ) {
			$compare = self::parse_price( $m[1] );
		}

		if ( '' === $price && '' !== $compare ) {
			// Only a <del> was found: the real price is the text outside it.
			$without_del = preg_replace( '#<(?:del|s|strike)[^>]*>.*?</(?:del|s|strike)>#is', '', $html_text );
			$price       = self::parse_price( $without_del );
			if ( '' === $price ) {
				$price = $compare;
			}
		} elseif ( '' === $price && '' === $compare ) {
			$price = self::parse_price( $html_text );
		}

		if ( $price === $compare ) {
			$compare = '';
		}

		return array( 'price' => $price, 'compare' => $compare );
	}

	/**
	 * Extract the first number from a price string like "1,500.00" or "$49".
	 *
	 * @param string $text
	 * @return string
	 */
	public static function parse_price( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
        if ( ! preg_match( '/[-+]?(?:[0-9]{1,3}(?:[ \x{00A0}\x{202F}][0-9]{3})+(?:[.,][0-9]+)?|[0-9][0-9.,]*)/u', $text, $matches ) ) {
            return '';
        }
        $number = preg_replace( '/[ \x{00A0}\x{202F}]/u', '', $matches[0] );
        $comma = strrpos( $number, ',' );
        $dot = strrpos( $number, '.' );
        if ( false !== $comma && false !== $dot ) {
            $number = $comma > $dot ? str_replace( ',', '.', str_replace( '.', '', $number ) ) : str_replace( ',', '', $number );
        } elseif ( false !== $comma ) {
            $fraction = strlen( $number ) - $comma - 1;
            $number = $fraction > 0 && $fraction <= 2 ? str_replace( ',', '.', $number ) : str_replace( ',', '', $number );
        }
        return is_numeric( $number ) ? $number : '';
    }

	/**
	 * GET a URL and return the response body ('' on failure).
	 *
	 * @param string $url
	 * @return string
	 */
	public static function http_get( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return '';
		}

		$args = array(
			'timeout'     => 40,
			'redirection' => 5,
			'sslverify'   => true,
            'limit_response_size' => 5 * MB_IN_BYTES,
			'user-agent'  => self::UA,
			'headers'     => array(
				'Accept' => 'text/html,application/xhtml+xml,application/json,*/*;q=0.8',
			),
		);

		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Load HTML into DOMDocument with error suppression.
	 *
	 * @param string $html
	 * @return DOMDocument|null
	 */
	private static function load_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		if ( '' === trim( (string) $html ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = @$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return null;
		}
		return $doc;
	}

	/**
	 * Inner HTML of a DOM node.
	 *
	 * @param DOMNode|null $node
	 * @return string
	 */
	private static function dom_inner_html( $node ) {
		if ( ! $node || ! ( $node instanceof DOMNode ) || ! $node->ownerDocument ) {
			return '';
		}
		$html = '';
		if ( $node->childNodes ) {
			foreach ( $node->childNodes as $child ) {
				$html .= $node->ownerDocument->saveHTML( $child );
			}
		}
		return trim( $html );
	}
}
