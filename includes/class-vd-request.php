<?php

defined( 'ABSPATH' ) || exit;

/**
 * During updating, the package may not be loaded yet. Manually include the dependency
 */
if ( ! class_exists( '\Vendidero\VendideroHelper\Request' ) ) {
	require __DIR__ . '/../src/Request.php';
}

/**
 * This file is for legacy purposes only as it may be used
 * by version <= 2.1.6 within the update request.
 */
class VD_Request extends \Vendidero\VendideroHelper\Request {

	public function __construct( $type = 'ping', $product = null, $args = array() ) {}

	public function do_request() {}

	public function is_error() {
		return false;
	}

	public function get_response( $type = 'filtered' ) {
		if ( 'filtered' === $type ) {
			return new WP_Error();
		}

		return false;
	}
}
