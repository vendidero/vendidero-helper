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

	public function flush_update_cache() {
		foreach( VD()->get_products() as $product ) {
			delete_transient( "_vendidero_helper_updates_{$product->id}" );
		}
	}

	private function _update_check( VD_Product $product, $key = '' ) {
		$cache_key = "_vendidero_helper_updates_{$product->id}";
		$data      = get_transient( $cache_key );

		if ( false !== $data && ! empty( $_GET['force-check'] ) ) {
			// Wait at least 1 minute between multiple forced version check requests.
			$timeout          = MINUTE_IN_SECONDS;
			$time_not_changed = ! empty( $data['updated'] ) && $timeout > ( time() - $data['updated'] );

			if ( ! $time_not_changed ) {
				delete_transient( $cache_key );
				$data = false;
			}
		}

		if ( false !== $data ) {
			return $data;
		}

		$data = array(
			'updated' => time(),
			'payload' => array(),
			'errors'  => array(),
			'notices' => array()
		);

		$request = new VD_Request( "version/{$product->id}/latest", $product, array( 'key' => $key, 'version' => $product->Version ) );

		if ( $request->is_error() ) {
			$error = $request->get_response();

			foreach( $error->get_error_messages( $error->get_error_code() ) as $msg ) {
				$data['errors'][] = $msg;
			}
		} else {
			if ( $request->get_response( "notice" ) ) {
				$data['notices'] = (array) $request->get_response( "notice" );
			}

			$data['payload'] = $request->get_response( "payload" );
		}

		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );

		return $data;
	}

	public function update_check( VD_Product $product, $key = '' ) {
		return $this->_update_check( $product, $key );
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