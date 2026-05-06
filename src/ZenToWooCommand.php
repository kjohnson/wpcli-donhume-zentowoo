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

		if(!isset($args[3])) WP_CLI::error( 'No product options JSON file specified' );
		$options_import = file_get_contents($args[3]);
		$options_import_data = json_decode($options_import, true);
		if(is_null($options_import_data)) WP_CLI::error( 'Unable to parse product options JSON file' );

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
		$count = 0;
		foreach($records as $record) {

//			if($count > 10) break;
//			$count++;

			$product = new WC_Product_Simple();
			$product->set_name( $record['name'] ); // product title
//			$product->set_slug( 'medium-size-wizard-hat-in-new-york' );
			$product->set_regular_price( $record['price'] ); // in current shop currency
			$product->set_sale_price( $record['price_sale'] ); // in current shop currency
			$product->set_description( $record['description'] );

			$product->save();

			if(isset($record['image']) && $record['image']) {
				$attachment_id = media_sideload_image('https://dev.donhume.com/wp-content/uploads/zentowoo/images/' . $record['image'], $product->get_id(), $record['name'], 'id');
				if(is_wp_error($attachment_id)) {
					WP_CLI::error( $attachment_id->get_error_message() . ': Unable to sideload image: ' . $record['image'], false );
				} else {
					WP_CLI::log( 'Imported image: ' . $attachment_id );
					$product->set_image_id(
						$attachment_id
					);
					$product->save();
				}
			}

			// Set required meta to display product options
			update_post_meta( $product->get_id(), 'af_addon_title_display_as_selector', 'af_addon_title_display_text' );

			$product_id_lookup[$record['id']] = $product->get_id();

			WP_CLI::log( 'Imported product: ' . $product->get_name() );
			$products[] = $product;
		}

		WP_CLI::success( 'Imported ' . count($products) . ' products' );


		// PRODUCT?CATEGORY MAPPING

		$csv = Reader::from($args[2], 'r');
		$csv->setHeaderOffset(0);

		$product_category_mapping = [];
		$records = $csv->getRecords(['products_id', 'categories_id']);
		foreach($records as $record) {
			if(isset($product_id_lookup[$record['products_id']])) {
				$product_id = $product_id_lookup[$record['products_id']];
				if (!isset($product_category_mapping[$product_id])) {
					$product_category_mapping[$product_id] = [];
				}
				if (isset($categories_id_lookup[$record['categories_id']])) {
					$product_category_mapping[$product_id][] = intval($categories_id_lookup[$record['categories_id']]);
				}
			}
		}

		foreach($product_category_mapping as $product_id => $category_ids) {
			wp_set_object_terms($product_id, $category_ids, 'product_cat');
			WP_CLI::success( 'Mapped product: ' . $product_id . ' to ' . count($category_ids) . ' categories' );
		}

		// OPTIONS

		$product_field_id_lookup = [];
		foreach($options_import_data as $data) {

			if(!isset($product_id_lookup[$data['product_id']])) {
				WP_CLI::error( 'Product lookup ID not found: ' . $data['product_id'], false );
				continue;
			}

			$product_id = $product_id_lookup[$data['product_id']];

			if(!isset($product_field_id_lookup[$data['product_id']][$data['field_id']])) {

				$field_id = wp_insert_post([
					'post_type'   => 'af_pao_fields',
					'post_status' => 'publish',
					'post_title'  => $data['option_name'],
					'post_parent' => $product_id,
				]);

				$field_type = [
					'Dropdown' => 'drop_down',
					'Text' => 'input_text',
					'Radio' => 'radio',
					'Checkbox' => 'check_boxes',
					'File' => 'file_upload',
					'Read Only' => '',
					'button' => '',
				][ $data['type'] ];
				if(!$field_type) {
					WP_CLI::error( 'Unknown field type: ' . $data['type'], false );
					continue;
				}
				update_post_meta( $field_id, 'af_addon_type_select', $field_type );

				$field_title = sanitize_meta( '', $data['option_name'], '' );
				update_post_meta( $field_id, 'af_addon_field_title', $field_title );

				$product_field_id_lookup[$data['product_id']][$data['field_id']] = $field_id;

				WP_CLI::log( 'Imported product field: ' . $data['option_name'] . ' for product ' . $product_id );
			}
			$field_id = $product_field_id_lookup[$data['product_id']][$data['field_id']];

			$option_id = wp_insert_post([
				'post_type'   => 'af_pao_options',
				'post_status' => 'publish',
				'post_title'  => $data['value'],
				'post_parent' => $field_id,
			]);

			$option_name = sanitize_meta( '', $data['value'], '' );
			update_post_meta( $option_id, 'af_addon_field_options_name', $option_name );

			update_post_meta( $option_id, 'af_addon_stock_status', 'in_stock' ); // Must be "in_stock" to display

			if($data['price_modifier']) {
				$option_price = sanitize_meta( '', $data['price_modifier'], '' );
				update_post_meta( $option_id, 'af_addon_field_options_price', $option_price );
				update_post_meta( $option_id, 'af_addon_field_options_price_type', 'af_addon_flat_fee' );
			} else {
				update_post_meta( $option_id, 'af_addon_field_options_price', '0.0000' );
				update_post_meta( $option_id, 'af_addon_field_options_price_type', 'free' );
			}

			WP_CLI::log( 'Imported product option: ' . $data['value'] . ' for product ' . $product_id );
		}
	}
}
