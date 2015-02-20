<?php

class VD_API {

	public function __construct() {

	}

	public function ping() {
		$request = new VD_Request( 'ping' );
		return ( ! $request->is_error() && $request->get_response() ? true : false );
	}

	public function register( VD_Product $product, $key ) {
		$request = new VD_Request( 'register', $product, array( 'key' => md5( $key ) ) );
		if ( ! $request->is_error() )
			$product->register( $key );
		return ( ! $request->is_error() ? true : false );
	}

	public function unregister( VD_Product $product ) {
		$request = new VD_Request( 'unregister', $product );
		if ( ! $request->is_error() )
			$product->unregister();
		return ( ! $request->is_error() ? true : false );
	}

	public function update_check( VD_Product $product, $key = '' ) {
		$request = new VD_Request( 'update_check', $product, array( 'key' => $key, 'version' => $product->Version ) );
		return $request;
	}

	public function check_license( VD_Product $product, $key ) {

	}

}

?>