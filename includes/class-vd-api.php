<?php

class VD_API {

	public function __construct() {}

	public function ping() {
		$request = new VD_Request( 'ping' );

		return ( ! $request->is_error() && $request->get_response() ? true : false );
	}

	public function register( VD_Product $product, $key ) {
		$request = new VD_Request( 'license/' . md5( $key ), $product );

		if ( ! $request->is_error() ) {
			$product->register( $key, ( $request->get_response( "expiration_date" ) ? $request->get_response( "expiration_date" ) : '' ) );
        }

		return $request->get_response();
	}

	public function unregister( VD_Product $product ) {
	    $product->unregister();

		return true;
	}

	public function info( VD_Product $product ) {
		$request = new VD_Request( "version/{$product->id}/latest/info", $product );

		return ( ! $request->is_error() ? $request->get_response() : false );
	}

	public function expiration_check( VD_Product $product ) {
		$request = new VD_Request( 'license/' . $product->get_key(), $product );

		return ( ! $request->is_error() ? $request->get_response( "expiration_date" ) : $request->get_response() );
	}

	public function update_check( VD_Product $product, $key = '' ) {
		$request = new VD_Request( "version/{$product->id}/latest", $product, array( 'key' => $key, 'version' => $product->Version ) );

		return $request;
	}

	public function generator_version_check( VD_Product $product, $generator ) {
	    $slug    = sanitize_title( $generator );
		$request = new VD_Request( "generator/{$slug}/info", $product );

		return ( ! $request->is_error() ? $request->get_response( 'all' ) : false );
	}

	public function generator_check( VD_Product $product, $generator, $settings = array() ) {
        $slug    = sanitize_title( $generator );
		$request = new VD_Request( "generator/{$slug}/question", $product, array( 'method' => 'POST', 'key' => $product->get_key(), 'settings' => $settings ) );

		return ( ! $request->is_error() ? $request->get_response() : $request->get_response() );
	}

	public function generator_result_check( VD_Product $product, $generator, $data = array(), $settings = array() ) {
        $slug    = sanitize_title( $generator );
		$request = new VD_Request( "generator/{$slug}/result", $product, array( 'method' => 'POST', 'key' => $product->get_key(), 'data' => $data, 'settings' => $settings ) );

		return ( ! $request->is_error() ? $request->get_response() : $request->get_response() );
	}

	public function to_array( $object ) {
		return json_decode( json_encode( $object ), true );
	}
}

?>