<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPI_Import
 *
 * Creates WooCommerce products (WC_Product_Simple) from the scraped data,
 * downloads the images into the media library and attaches them.
 */
class WPI_Import {

	/**
	 * Import a single product.
	 *
	 * @param array $product Normalized product data.
	 * @param array $options 'status' => 'publish'|'draft', 'skip_dup' => bool, 'categories' => array of term ids.
	 * @return array
	 */
	public static function import_product( $product, $options = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'ok' => false, 'message' => 'WooCommerce is not active.' );
		}
		if ( empty( $product['title'] ) ) {
			return array( 'ok' => false, 'message' => 'Product title is empty.' );
		}

		$status   = ! empty( $options['status'] ) ? 'publish' : 'draft';
		$skip_dup = ! empty( $options['skip_dup'] );
		$cats     = self::valid_categories( ! empty( $options['categories'] ) ? $options['categories'] : array() );

		$title = sanitize_text_field( wp_strip_all_tags( $product['title'] ) );
		$sku   = isset( $product['sku'] ) ? sanitize_text_field( trim( (string) $product['sku'] ) ) : '';

		// ---- Duplicate checks -----------------------------------------
		if ( $skip_dup ) {
			if ( '' !== $sku ) {
				$existing = wc_get_product_id_by_sku( $sku );
				if ( $existing ) {
					return array( 'ok' => false, 'skipped' => true, 'message' => 'Duplicate SKU: ' . $sku );
				}
			}

			$q = new WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'title'          => $title,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( $q->have_posts() ) {
				return array( 'ok' => false, 'skipped' => true, 'message' => 'Duplicate title: ' . $title );
			}
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$product_obj = new WC_Product_Simple();
		$product_obj->set_name( $title );
		$product_obj->set_status( $status );
		$product_obj->set_catalog_visibility( 'visible' );
		$product_obj->set_description( isset( $product['description'] ) ? wp_kses_post( $product['description'] ) : '' );
		$product_obj->set_short_description( isset( $product['short_description'] ) ? wp_kses_post( $product['short_description'] ) : '' );

		$price   = self::clean_price( isset( $product['price'] ) ? $product['price'] : '' );
		$compare = self::clean_price( isset( $product['compare_at_price'] ) ? $product['compare_at_price'] : '' );

		// Shopify logic: price = selling price, compare_at_price = regular price.
		$regular = ( '' !== $compare ) ? $compare : $price;
		$sale    = ( '' !== $compare && $price < $compare ) ? $price : '';

		if ( '' !== $regular ) {
			$product_obj->set_regular_price( $regular );
		}
		if ( '' !== $sale ) {
			$product_obj->set_sale_price( $sale );
		}

		if ( '' !== $sku ) {
			$product_obj->set_sku( $sku );
		}

		if ( ! empty( $cats ) ) {
			$product_obj->set_category_ids( $cats );
		}

		$product_obj->save();
		$product_id = (int) $product_obj->get_id();

		if ( ! $product_id ) {
			return array( 'ok' => false, 'message' => 'Could not create the product.' );
		}

		// Tags (Shopify tags / product types can map here).
		if ( ! empty( $product['tags'] ) ) {
			$raw_tags = is_array( $product['tags'] ) ? $product['tags'] : explode( ',', (string) $product['tags'] );
			$tags     = array();
			foreach ( $raw_tags as $t ) {
				$clean_t = sanitize_text_field( trim( (string) $t ) );
				if ( '' !== $clean_t ) {
					$tags[] = $clean_t;
				}
			}
			if ( ! empty( $tags ) ) {
				wp_set_object_terms( $product_id, $tags, 'product_tag' );
			}
		}

		// ---- Images ----------------------------------------------------
		$images = array();
		if ( ! empty( $product['image'] ) && is_string( $product['image'] ) ) {
			$images[] = $product['image'];
		}
		if ( ! empty( $product['images'] ) && is_array( $product['images'] ) ) {
			foreach ( $product['images'] as $img ) {
				if ( is_string( $img ) && '' !== trim( $img ) ) {
					$images[] = $img;
				}
			}
		}
		$images = array_values( array_unique( array_filter( $images ) ) );

		$attach_ids = array();
		foreach ( array_slice( $images, 0, 30 ) as $img_url ) {
			$attach_id = self::sideload_image( $img_url, $product_id, $title );
			if ( $attach_id ) {
				$attach_ids[] = $attach_id;
			}
		}

		if ( $attach_ids ) {
			$featured = array_shift( $attach_ids );
			set_post_thumbnail( $product_id, $featured );
			if ( $attach_ids ) {
				$product_obj->set_gallery_image_ids( $attach_ids );
				$product_obj->save();
			}
		}

		$edit_url = '';
		if ( function_exists( 'get_edit_post_link' ) ) {
			$edit_url = (string) get_edit_post_link( $product_id, 'raw' );
		}

		return array(
			'ok'         => true,
			'product_id' => $product_id,
			'edit_url'   => $edit_url,
			'title'      => $title,
			'message'    => 'Imported',
		);
	}

	/**
	 * Download an external image into the media library.
	 *
	 * @param string $url
	 * @param int    $post_id
	 * @param string $title
	 * @return int Attachment ID or 0.
	 */
	private static function sideload_image( $url, $post_id, $title ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return 0;
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$id = media_sideload_image( $url, $post_id, $title, 'id' );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Keep only product category ids that actually exist.
	 *
	 * @param array $cats
	 * @return array
	 */
	private static function valid_categories( $cats ) {
		$out = array();
		foreach ( (array) $cats as $c ) {
			$c = (int) $c;
			if ( $c > 0 && term_exists( $c, 'product_cat' ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Create (or reuse) a destination product category with its image.
	 *
	 * @param string $name
	 * @param string $slug
	 * @param string $image_url
	 * @return int Term id or 0.
	 */
	public static function ensure_category( $name, $slug, $image_url = '' ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}

		$name = sanitize_text_field( trim( (string) $name ) );
		if ( '' === $name ) {
			$name = 'Imported';
		}
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		$term    = get_term_by( 'slug', $slug, 'product_cat' );
		$term_id = 0;
		if ( $term && ! is_wp_error( $term ) ) {
			$term_id = (int) $term->term_id;
		} else {
			$res = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
			if ( is_wp_error( $res ) ) {
				$by_name = get_term_by( 'name', $name, 'product_cat' );
				if ( $by_name && ! is_wp_error( $by_name ) ) {
					$term_id = (int) $by_name->term_id;
				} else {
					return 0;
				}
			} else {
				$term_id = (int) $res['term_id'];
			}
		}

		// Category image: upload once, store as WooCommerce thumbnail_id.
		$image_url = trim( (string) $image_url );
		if ( '' !== $image_url && preg_match( '#^https?://#i', $image_url ) && ! get_term_meta( $term_id, 'thumbnail_id', true ) ) {
			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$attach = media_sideload_image( $image_url, 0, $name, 'id' );
			if ( ! is_wp_error( $attach ) ) {
				update_term_meta( $term_id, 'thumbnail_id', (int) $attach );
			}
		}

		return $term_id;
	}

	/**
	 * Import one whole category: create the category (with image), fetch all
	 * its products and import them. Products found in several categories are
	 * linked to each of them instead of being duplicated.
	 *
	 * @param string $origin
	 * @param array  $category
	 * @param array  $options 'status', 'skip_dup'.
	 * @return array
	 */
	public static function import_site_category( $origin, $category, $options = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'ok' => false, 'message' => 'WooCommerce is not active.' );
		}

		@set_time_limit( 0 );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$status   = ! empty( $options['status'] ) ? 'publish' : 'draft';
		$skip_dup = ! empty( $options['skip_dup'] );

		$term_id = self::ensure_category(
			isset( $category['title'] ) ? $category['title'] : '',
			isset( $category['handle'] ) ? $category['handle'] : '',
			isset( $category['image'] ) ? $category['image'] : ''
		);

		$products = WPI_Scraper::fetch_category_products_all( $origin, $category );

		$key = 'wpi_catmap_' . md5( $origin );
		$map = get_transient( $key );
		if ( ! is_array( $map ) ) {
			$map = array( 'terms' => array(), 'products' => array() );
		}
		if ( ! empty( $category['handle'] ) && $term_id ) {
			$map['terms'][ $category['handle'] ] = $term_id;
		}

		$stats = array(
			'imported' => 0,
			'skipped'  => 0,
			'linked'   => 0,
			'failed'   => 0,
			'total'    => count( $products ),
		);

		foreach ( $products as $product ) {
			$handle = ! empty( $product['handle'] )
				? $product['handle']
				: md5( ( isset( $product['source_url'] ) ? $product['source_url'] : '' ) . $product['title'] );

			// Already imported from a previous category in this store.
			if ( '' !== $handle && isset( $map['products'][ $handle ] ) ) {
				if ( $skip_dup ) {
					$stats['skipped']++;
					continue;
				}
				if ( $term_id ) {
					wp_set_object_terms( (int) $map['products'][ $handle ], array( $term_id ), 'product_cat', true );
				}
				$stats['linked']++;
				continue;
			}

			$opts = array(
				'status'     => $status,
				'skip_dup'   => false,
				'categories' => $term_id ? array( $term_id ) : array(),
			);
			$res  = self::import_product( $product, $opts );

			if ( ! empty( $res['ok'] ) ) {
				$stats['imported']++;
				if ( '' !== $handle ) {
					$map['products'][ $handle ] = (int) $res['product_id'];
				}
			} elseif ( ! empty( $res['skipped'] ) ) {
				$stats['skipped']++;
			} else {
				$stats['failed']++;
			}
		}

		set_transient( $key, $map, DAY_IN_SECONDS );

		return array(
			'ok'       => true,
			'term_id'  => $term_id,
			'category' => isset( $category['title'] ) ? $category['title'] : '',
			'stats'    => $stats,
		);
	}

	/**
	 * Prepare a category for site import: ensure the WC category term (with image) exists,
	 * and return its fetched product list for batch processing.
	 *
	 * @param string $origin
	 * @param array  $category
	 * @return array
	 */
	public static function prepare_site_category( $origin, $category ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'ok' => false, 'message' => 'WooCommerce is not active.' );
		}

		$term_id = self::ensure_category(
			isset( $category['title'] ) ? $category['title'] : '',
			isset( $category['handle'] ) ? $category['handle'] : '',
			isset( $category['image'] ) ? $category['image'] : ''
		);

		$products = WPI_Scraper::fetch_category_products_all( $origin, $category );

		$key = 'wpi_catmap_' . md5( $origin );
		$map = get_transient( $key );
		if ( ! is_array( $map ) ) {
			$map = array( 'terms' => array(), 'products' => array() );
		}
		if ( ! empty( $category['handle'] ) && $term_id ) {
			$map['terms'][ $category['handle'] ] = $term_id;
		}
		set_transient( $key, $map, DAY_IN_SECONDS );

		return array(
			'ok'       => true,
			'term_id'  => $term_id,
			'category' => isset( $category['title'] ) ? $category['title'] : '',
			'products' => $products,
		);
	}

	/**
	 * Import a batch of products (3-4 items).
	 *
	 * @param array $products List of products to import.
	 * @param array $options 'status', 'skip_dup', 'categories', 'origin'.
	 * @return array
	 */
	public static function import_products_batch( $products, $options = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'ok' => false, 'message' => 'WooCommerce is not active.' );
		}
		if ( empty( $products ) || ! is_array( $products ) ) {
			return array( 'ok' => false, 'message' => 'No products provided.' );
		}

		@set_time_limit( 300 );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$origin   = ! empty( $options['origin'] ) ? $options['origin'] : '';
		$skip_dup = ! empty( $options['skip_dup'] );

		$map = array( 'terms' => array(), 'products' => array() );
		$key = '';
		if ( '' !== $origin ) {
			$key        = 'wpi_catmap_' . md5( $origin );
			$stored_map = get_transient( $key );
			if ( is_array( $stored_map ) ) {
				$map = $stored_map;
			}
		}

		$results = array();

		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$client_id = isset( $product['_id'] ) ? $product['_id'] : '';
			$handle    = ! empty( $product['handle'] )
				? $product['handle']
				: md5( ( isset( $product['source_url'] ) ? $product['source_url'] : '' ) . ( isset( $product['title'] ) ? $product['title'] : '' ) );

			// Check multi-category mapping transient if origin is supplied
			if ( '' !== $key && '' !== $handle && isset( $map['products'][ $handle ] ) ) {
				$existing_id = (int) $map['products'][ $handle ];
				if ( $skip_dup ) {
					$results[] = array(
						'_id'     => $client_id,
						'ok'      => false,
						'skipped' => true,
						'linked'  => false,
						'message' => 'Duplicate product (already imported)',
					);
					continue;
				}
				$cats = self::valid_categories( ! empty( $options['categories'] ) ? $options['categories'] : array() );
				if ( ! empty( $cats ) ) {
					wp_set_object_terms( $existing_id, $cats, 'product_cat', true );
				}
				$edit_url = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $existing_id, 'raw' ) : '';
				$results[] = array(
					'_id'        => $client_id,
					'ok'         => true,
					'linked'     => true,
					'product_id' => $existing_id,
					'edit_url'   => $edit_url,
					'title'      => isset( $product['title'] ) ? wp_strip_all_tags( $product['title'] ) : '',
					'message'    => 'Linked to category',
				);
				continue;
			}

			// Single product import
			$res           = self::import_product( $product, $options );
			$res['_id']    = $client_id;
			$res['linked'] = false;

			if ( ! empty( $res['ok'] ) && '' !== $key && '' !== $handle && ! empty( $res['product_id'] ) ) {
				$map['products'][ $handle ] = (int) $res['product_id'];
			}

			$results[] = $res;
		}

		if ( '' !== $key ) {
			set_transient( $key, $map, DAY_IN_SECONDS );
		}

		return array(
			'ok'      => true,
			'results' => $results,
		);
	}

	/**
	 * Normalize a price value to a WooCommerce-friendly string like "1200.00".
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function clean_price( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = preg_replace( '/[^\d.,\-]/', '', $value );
		$value = str_replace( ',', '', $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return number_format( (float) $value, 2, '.', '' );
	}
}

