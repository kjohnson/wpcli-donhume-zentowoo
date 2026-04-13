<?php

namespace ForgeMedia\ZenToWoo;

use WP_CLI;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$forgemedia_zentowoo_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $forgemedia_zentowoo_autoloader ) ) {
	require_once $forgemedia_zentowoo_autoloader;
}

WP_CLI::add_command( 'zentowoo', ZenToWooCommand::class );
