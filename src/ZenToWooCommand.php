<?php

namespace ForgeMedia\ZenToWoo;

use League\Csv\Reader;
use WC_Product_Simple;
use WP_CLI;
use WP_CLI_Command;

class ZenToWooCommand extends WP_CLI_Command {

	/**
	 * @param array $args       Indexed array of positional arguments.
	 * @param array $assoc_args Associative array of associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {

		if(isset($args[0]) && method_exists($this, $args[0])) {
			call_user_func_array([$this, $args[0]], [array_slice($args, 1), $assoc_args]);
			return;
		}

		WP_CLI::success( 'Hello World!' );
	}

	function csv( $args, $assoc_args ) {

		add_filter('https_ssl_verify', '__return_false');

		if(!isset($args[0])) {
			WP_CLI::error( 'No categories CSV file specified' );
			return;
		}

		if(!isset($args[1])) {
			WP_CLI::error( 'No products CSV file specified' );
			return;
		}

		if(!isset($args[2])) {
			WP_CLI::error( 'No products/category CSV file specified' );
			return;
		}

		if(!isset($args[3])) WP_CLI::error( 'No attribute JSON file specified' );
		$attribute_import = file_get_contents($args[3]);
		$attribute_import_data = json_decode($attribute_import, true);
		if(is_null($attribute_import_data)) WP_CLI::error( 'Unable to parse attribute JSON file' );

		// CATEGORIES

		//load the CSV document from a file path
		$csv = Reader::from($args[0], 'r');
		$csv->setHeaderOffset(0);

		$categories = [];
		$subcategories = [];
		$records = $csv->getRecords(['categories_id', 'parent_id', 'categories_name', 'categories_description', 'sort_order', 'categories_status']);
		foreach($records as $record) {

			if($record['parent_id'] == 0) {
				$categories[$record['categories_id']] = $record;
			} else {
				$subcategories[$record['categories_id']] = $record;
			}
		}

		$categories_id_lookup = [];
		foreach($categories as $category) {

			$existing_term = term_exists( $category['categories_name'], 'product_cat' );
			if( $existing_term ) {
				WP_CLI::log( 'Skipping category: ' . $category['categories_name'] );
				$categories_id_lookup[$category['categories_id']] = $existing_term['term_id'];
			} else {
				$result = wp_insert_term( $category['categories_name'], 'product_cat');

				if(is_wp_error($result)) {
					WP_CLI::error( $result->get_error_message(), false );
				} else {
					$categories_id_lookup[$category['categories_id']] = $result['term_id'];
					WP_CLI::log( 'Imported category: ' . $result['term_id'] );
				}
			}
		}
		WP_CLI::success( 'Processed ' . count($categories) . ' categories' );

		foreach($subcategories as $subcategory) {


			$existing_term = term_exists( $subcategory['categories_name'], 'product_cat' );
			if( $existing_term ) {
				WP_CLI::log( 'Skipping subcategory: ' . $subcategory['categories_name'] );
				$categories_id_lookup[$subcategory['categories_id']] = $existing_term['term_id'];
			} else {
				$result = wp_insert_term( $subcategory['categories_name'], 'product_cat', [
					'parent' => $categories_id_lookup[$subcategory['parent_id']]
				]);

				if(is_wp_error($result)) {
					WP_CLI::error( $result->get_error_message(), false );
				} else {
					$categories_id_lookup[$subcategory['categories_id']] = $result['term_id'];
					WP_CLI::log( 'Imported subcategory: ' . $result['term_id'] );
				}
			}
		}
		WP_CLI::success( 'Processed ' . count($subcategories) . ' subcategories' );

		// PRODUCTS

		//load the CSV document from a file path
		$csv = Reader::from($args[1], 'r');
		$csv->setHeaderOffset(0);

		$products = [];
		$product_id_lookup = [];
		$records = $csv->getRecords(['id', 'price', 'price_sale', 'image', 'name', 'description']);
		foreach($records as $record) {
			$product = new WC_Product_Simple();
			$product->set_name( $record['name'] ); // product title
//			$product->set_slug( 'medium-size-wizard-hat-in-new-york' );
			$product->set_regular_price( $record['price'] ); // in current shop currency
			$product->set_sale_price( $record['price_sale'] ); // in current shop currency
			$product->set_description( $record['description'] );

			$product->save();

//			if(isset($record['image']) && $record['image']) {
//				$attachment_id = media_sideload_image('https://dev.donhume.com/wp-content/uploads/zentowoo/images/' . $record['image'], $product->get_id(), $record['name'], 'id');
//				if(is_wp_error($attachment_id)) {
//					WP_CLI::error( $attachment_id->get_error_message() . ': Unable to sideload image: ' . $record['image'], false );
//				} else {
//					WP_CLI::log( 'Imported image: ' . $attachment_id );
//					$product->set_image_id(
//						$attachment_id
//					);
//					$product->save();
//				}
//			}

			$product_id_lookup[$record['id']] = $product->get_id();

			WP_CLI::log( 'Imported product: ' . $product->get_name() );
			$products[] = $product;
		}

		WP_CLI::success( 'Imported ' . count($products) . ' products' );


		// PRODUCT?CATEGORY MAPPING

//		$csv = Reader::from($args[2], 'r');
//		$csv->setHeaderOffset(0);
//
//		$product_category_mapping = [];
//		$records = $csv->getRecords(['products_id', 'categories_id']);
//		foreach($records as $record) {
//			if(isset($product_id_lookup[$record['products_id']])) {
//				$product_id = $product_id_lookup[$record['products_id']];
//				if (!isset($product_category_mapping[$product_id])) {
//					$product_category_mapping[$product_id] = [];
//				}
//				if (isset($categories_id_lookup[$record['categories_id']])) {
//					$product_category_mapping[$product_id][] = intval($categories_id_lookup[$record['categories_id']]);
//				}
//			}
//		}
//
//		foreach($product_category_mapping as $product_id => $category_ids) {
//			wp_set_object_terms($product_id, $category_ids, 'product_cat');
//			WP_CLI::success( 'Mapped product: ' . $product_id . ' to ' . count($category_ids) . ' categories' );
//		}


		// ATTRIBUTES

		foreach($attribute_import_data as $data) {

			if(!isset($product_id_lookup[$data['id']])) {
				WP_CLI::error( 'Product ID not found: ' . $data['id'], false );
				continue;
			}

			$product_id = $product_id_lookup[$data['id']];

			$attributes_data = $data['attributes'];

			$option_price_modifier_lookup = [];

			if( sizeof($attributes_data) > 0 ){

				$attributes = array(); // Initializing

				// Loop through defined attribute data
				foreach( $attributes_data as $key => $attribute_array ) {
					if( isset($attribute_array['name']) && isset($attribute_array['options']) ){
						// Clean attribute name to get the taxonomy
						$taxonomy = 'pa_' . wc_sanitize_taxonomy_name( $attribute_array['name'] );

						if(!taxonomy_exists($taxonomy)) {
							wc_create_attribute([
								'name' => $attribute_array['name'],
								'slug' => wc_sanitize_taxonomy_name( $attribute_array['name'] ),
							]);
							WP_CLI::log( 'Created taxonomy: ' . $taxonomy );
						}

						$option_term_ids = array(); // Initializing

						// Loop through defined attribute data options (terms values)
						foreach( $attribute_array['options'] as $option ){
							if( term_exists( $option['name'], $taxonomy ) ){
								// Save the possible option value for the attribute which will be used for variation later
								wp_set_object_terms( $product_id, $option['name'], $taxonomy, true );

								// Get the term ID
								$term = get_term_by( 'name', $option['name'], $taxonomy );
								$option_price_modifier_lookup[$term->term_id] = $option['price_modifier'];
								$option_term_ids[] = $term->term_id;

							} else {
								$result = wp_insert_term( $option['name'], $taxonomy );

								if(is_wp_error($result)) {
									WP_CLI::error( 'Failed to insert attribute term. ' . $result->get_error_message(), false );
								} else {
									$option_term_ids[] = $result['term_id'];
									$option_price_modifier_lookup[$result['term_id']] = $option['price_modifier'];
								}

							}
						}
					}
					// Loop through defined attribute data
					$attributes[$taxonomy] = array(
						'name'          => $taxonomy,
						'value'         => $option_term_ids, // Need to be term IDs
						'position'      => $key + 1,
						'is_visible'    => $attribute_array['visible'],
						'is_variation'  => $attribute_array['variation'],
						'is_taxonomy'   => '1'
					);
				}
				// Save the meta entry for product attributes
				update_post_meta( $product_id, '_product_attributes', $attributes );
				WP_CLI::success( 'Imported attributes for product: ' . $product_id );
			}

			// PRODUCT VARIATIONS
			$product = wc_get_product( $product_id );
			$data_store = $product->get_data_store();
			if ( ! is_callable( array( $data_store, 'create_all_product_variations' ) ) ) {
				WP_CLI::error( 'Product variations not supported for product ID ' . $product_id, false );
				continue;
			}

			$variations_count = $data_store->create_all_product_variations( $product );
			$data_store->sort_all_product_variations( $product->get_id() );
			WP_CLI::success( 'Generated ' . $variations_count . ' variations for product: ' . $product_id );

			$variations = $product->get_available_variations();
			if( $variations ) {
				foreach( $variations as $variation ) {
					// a WC_Product_Variation object
					$variation->set_price( $variation->get_price() + $option_price_modifier_lookup[$variation->get_attribute( 'pa_price_modifier' )] );
					WP_CLI::log( 'Set variation price for product: ' . $product_id . ' to ' . $variation->get_price() );
				}
			}
		}

	}

}
