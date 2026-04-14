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
				$attachment_id = media_sideload_image('https://www.donhume.com/images/' . $record['image'], $product->get_id(), $record['name'], 'id');
				if(is_wp_error($attachment_id)) {
					WP_CLI::error( $attachment_id->get_error_message() );
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
}
