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

		if(method_exists($this, @$args[0])) {
			call_user_func_array([$this, $args[0]], [array_slice($args, 1), $assoc_args]);
			return;
		}

		WP_CLI::success( 'Hello World!' );
	}

	function csv( $args, $assoc_args ) {

		if(!isset($args[0])) {
			WP_CLI::error( 'No file path provided' );
			return;
		}

		//load the CSV document from a file path
		$csv = Reader::from($args[0], 'r');
		$csv->setHeaderOffset(0);

		$products = [];
		$records = $csv->getRecords(['products_id', 'products_price', 'products_name', 'products_description']);
		foreach($records as $record) {
			$product = new WC_Product_Simple();
			$product->set_name( $record['products_name'] ); // product title
//			$product->set_slug( 'medium-size-wizard-hat-in-new-york' );
			$product->set_regular_price( $record['products_price'] ); // in current shop currency
			$product->set_description( $record['products_description'] );
			$products[] = $product;
		}

		WP_CLI::success( 'Imported ' . count($products) . ' products' );
	}
}
