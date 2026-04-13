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

			if(isset($record['image']) && $record['image']) {
				$product->set_image_id(
					$this->sideload_external_image('http://www.donhume.com/images/' . $record['image'])
				);
			}

			$product->save();
			WP_CLI::log( 'Imported product: ' . $product->get_name() );
			$products[] = $product;
		}

		WP_CLI::success( 'Imported ' . count($products) . ' products' );
	}

	/**
	 * Upload image from URL programmatically
	 *
	 * @author Misha Rudrastyh
	 * @link https://rudrastyh.com/wordpress/how-to-add-images-to-media-library-from-uploaded-files-programmatically.html#upload-image-from-url
	 */
	protected function sideload_external_image( $image_url ) {

		// it allows us to use download_url() and wp_handle_sideload() functions
		require_once( ABSPATH . 'wp-admin/includes/file.php' );

		// download to temp dir
		$temp_file = download_url( $image_url );

		if( is_wp_error( $temp_file ) ) {
			WP_CLI::error( 'Error: ' . $temp_file->get_error_message() );
			return false;
		}

		// move the temp file into the uploads directory
		$file = array(
			'name'     => basename( $image_url ),
			'type'     => mime_content_type( $temp_file ),
			'tmp_name' => $temp_file,
			'size'     => filesize( $temp_file ),
		);
		$sideload = wp_handle_sideload(
			$file,
			array(
				'test_form'   => false // no needs to check 'action' parameter
			)
		);

		if( ! empty( $sideload[ 'error' ] ) ) {
			WP_CLI::error( 'Error: ' . $sideload[ 'error' ] );
			// you may return error message if you want
			return false;
		}

		// it is time to add our uploaded image into WordPress media library
		$attachment_id = wp_insert_attachment(
			array(
				'guid'           => $sideload[ 'url' ],
				'post_mime_type' => $sideload[ 'type' ],
				'post_title'     => basename( $sideload[ 'file' ] ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$sideload[ 'file' ]
		);

		if( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			WP_CLI::error( 'Error: ' . $attachment_id->get_error_message() );
			return false;
		}

		// update medatata, regenerate image sizes
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $sideload[ 'file' ] )
		);

		WP_CLI::success( 'Sideloaded image: ' . $image_url );

		return $attachment_id;
	}
}
