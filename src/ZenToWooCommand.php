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
			WP_CLI::error( 'No file path provided' );
			return;
		}

		//load the CSV document from a file path
		$csv = Reader::from($args[0], 'r');
		$csv->setHeaderOffset(0);

		$products = [];
		$records = $csv->getRecords(['id', 'price', 'price_sale', 'image', 'name', 'description']);
		foreach($records as $record) {
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

			WP_CLI::log( 'Imported product: ' . $product->get_name() );
			$products[] = $product;
		}

		WP_CLI::success( 'Imported ' . count($products) . ' products' );
	}

	function categories( $args, $assoc_args )
	{
		if(!isset($args[0])) {
			WP_CLI::error( 'No file path provided' );
			return;
		}

		//load the CSV document from a file path
		$csv = Reader::from($args[0], 'r');
		$csv->setHeaderOffset(0);

		$categories = [];
		$subcategories = [];
		$records = $csv->getRecords(['categories_id', 'parent_id', 'categories_name', 'categories_description', 'sort_order', 'categories_status']);
		foreach($records as $record) {

			if($record['parent_id'] == 0) {
				$subcategories[$record['categories_id']] = $record;
			} else {
				$categories[$record['parent_id']] = $record;
			}
		}

		$categories_id_lookup = [];
		foreach($categories as $category) {
			$result = wp_insert_term( $category['categories_name'], 'product_cat');

			if(is_wp_error($result)) {
				WP_CLI::error( $result->get_error_message() );
			} else {
				$categories_id_lookup[$category['categories_id']] = $result['term_id'];
				WP_CLI::log( 'Imported category: ' . $result['term_id'] );
			}
		}

		foreach($subcategories as $subcategory) {

			$result = wp_insert_term( $category['categories_name'], 'product_cat', [
				'parent' => $categories_id_lookup[$subcategory['categories_id']]
			]);

			if(is_wp_error($result)) {
				WP_CLI::error( $result->get_error_message() );
			} else {
				$categories_id_lookup[$category['categories_id']] = $result['term_id'];
				WP_CLI::log( 'Imported subcategory: ' . $result['term_id'] );
			}
		}
	}
}
