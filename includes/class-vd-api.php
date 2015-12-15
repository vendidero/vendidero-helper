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
			$product->register( $key, ( $request->get_response( "expiration_date" ) ? $request->get_response( "expiration_date" ) : '' ) );
		return ( ! $request->is_error() ? true : false );
	}

	public function unregister( VD_Product $product ) {
		$request = new VD_Request( 'unregister', $product );
		if ( ! $request->is_error() )
			$product->unregister();
		return ( ! $request->is_error() ? true : false );
	}

	public function info( VD_Product $product ) {
		$request = new VD_Request( 'info', $product );
		return ( ! $request->is_error() ? $request->get_response() : false );
	}

	public function expiration_check( VD_Product $product ) {
		$request = new VD_Request( 'expiration_check', $product );
		return ( ! $request->is_error() ? $request->get_response( "expiration_date" ) : false );
	}

	public function update_check( VD_Product $product, $key = '' ) {
		$request = new VD_Request( 'update_check', $product, array( 'key' => $key, 'version' => $product->Version ) );
		return $request;
	}

	public function license_check( VD_Product $product, $key ) {
		$request = new VD_Request( 'license_check', $product, array( 'key' => $key ) );
		return ( ! $request->is_error() ? true : false );
	}

	public function generator_version_check( VD_Product $product, $generator ) {
		$request = new VD_Request( 'generator_version_check', $product, array( 'generator' => sanitize_title( $generator ) ) );
		return ( ! $request->is_error() ? $request->get_response( 'all' ) : false );
	}

	public function generator_check( VD_Product $product, $generator, $settings = array() ) {
		$request = new VD_Request( 'generator_check', $product, array( 'key' => $product->get_key(), 'generator' => sanitize_title( $generator ), 'settings' => $settings ) );
		return ( ! $request->is_error() ? $request->get_response() : false );
	}

	public function generator_result_check( VD_Product $product, $generator, $data = array(), $settings = array() ) {
		$request = new VD_Request( 'generator_result_check', $product, array( 'key' => $product->get_key(), 'generator' => sanitize_title( $generator ), 'data' => $data, 'settings' => $settings ) );
		return ( ! $request->is_error() ? $request->get_response() : false );
	}

	public function to_array( $object ) {
		return json_decode( json_encode( $object ), true );
	}

}

?>