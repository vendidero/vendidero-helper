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
			$product->register( $key, ( $request->get_response( 'expiration_date' ) ? $request->get_response( 'expiration_date' ) : '' ) );
		}

		return $request->get_response();
	}

	public function unregister( VD_Product $product ) {
		$product->unregister();

		return true;
	}

	public function info( VD_Product $product ) {
		$data = $this->info_check_callback( $product );

		return ( false !== $data ? $data : false );
	}

	private function expiry_check_callback( VD_Product $product ) {
		$cache_key = "_vendidero_helper_expiry_{$product->id}";
		$data      = get_transient( $cache_key );

		if ( false !== $data ) {
			if ( empty( $data['expiration_date'] ) ) {
				$errors = $data['errors'];
				$error  = new WP_Error();

				if ( ! empty( $errors ) ) {
					foreach ( $errors as $i => $e ) {
						$error->add( $i, $e );
					}
				}

				return $error;
			} else {
				return $data['expiration_date'];
			}
		}

		$data = array(
			'updated'         => time(),
			'expiration_date' => '',
			'errors'          => array(),
		);

		$request = new VD_Request( 'license/' . $product->get_key(), $product );
		$error   = false;

		if ( $request->is_error() ) {
			$error = $request->get_response();

			foreach ( $error->get_error_messages( $error->get_error_code() ) as $msg ) {
				$data['errors'][] = $msg;
			}
		} else {
			$data['expiration_date'] = $request->get_response( 'expiration_date' );
		}

		set_transient( $cache_key, $data, 10 * MINUTE_IN_SECONDS );

		return is_wp_error( $error ) ? $error : $data['expiration_date'];
	}

	public function expiration_check( VD_Product $product, $force = false ) {
		if ( $force ) {
			delete_transient( "_vendidero_helper_expiry_{$product->id}" );
		}

		return $this->expiry_check_callback( $product );
	}

	public function flush_cache() {
		foreach ( VD()->get_products() as $product ) {
			$product->flush_api_cache();
		}
	}

	private function info_check_callback( VD_Product $product ) {
		$cache_key        = "_vendidero_helper_update_info_{$product->id}";
		$data             = get_transient( $cache_key );
		$update_transient = get_transient( "_vendidero_helper_updates_{$product->id}" );

		if ( false !== $data && false !== $update_transient ) {
			/**
			 * In case the update data is newer than the info data - force refresh and return info data.
			 */
			if ( $update_transient['updated'] > $data['updated'] ) {
				delete_transient( $cache_key );

				return ! empty( $update_transient['info'] ) ? $update_transient['info'] : false;
			}
		}

		if ( false !== $data ) {
			return ! empty( $data['errors'] ) ? false : $data['payload'];
		}

		$data = array(
			'updated' => time(),
			'payload' => array(),
			'errors'  => array(),
		);

		$request = new VD_Request( "releases/{$product->id}/latest/info", $product );

		if ( $request->is_error() ) {
			$error = $request->get_response();

			foreach ( $error->get_error_messages( $error->get_error_code() ) as $msg ) {
				$data['errors'][] = $msg;
			}
		} else {
			$data['payload'] = $request->get_response( 'payload' );
		}

		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );

		return ! empty( $data['errors'] ) ? false : $data['payload'];
	}

	private function update_check_callback( VD_Product $product, $key = '' ) {
		$cache_key = "_vendidero_helper_updates_{$product->id}";
		$data      = get_transient( $cache_key );

		if ( false !== $data && ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
			'notices' => array(),
			'info'    => array(),
		);

		$request = new VD_Request(
			"releases/{$product->id}/latest",
			$product,
			array(
				'key'     => $key,
				'version' => $product->Version,
			)
		);

		if ( $request->is_error() ) {
			$error = $request->get_response();

			foreach ( $error->get_error_messages( $error->get_error_code() ) as $msg ) {
				$data['errors'][] = $msg;
			}
		} else {
			if ( $request->get_response( 'notice' ) ) {
				$data['notices'] = (array) $request->get_response( 'notice' );
			}

			if ( $request->get_response( 'info' ) ) {
				$data['info'] = $request->get_response( 'info' );
			}

			$data['payload'] = $request->get_response( 'payload' );
		}

		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );

		return $data;
	}

	public function update_check( VD_Product $product, $key = '' ) {
		return $this->update_check_callback( $product, $key );
	}

	public function generator_version_check( VD_Product $product, $generator ) {
		$slug    = sanitize_title( $generator );
		$request = new VD_Request( "generator/{$slug}/info", $product );

		return ( ! $request->is_error() ? $request->get_response( 'all' ) : false );
	}

	public function generator_check( VD_Product $product, $generator, $settings = array() ) {
		$slug    = sanitize_title( $generator );
		$request = new VD_Request(
			"generator/{$slug}/question",
			$product,
			array(
				'method'   => 'POST',
				'key'      => $product->get_key(),
				'settings' => $settings,
			)
		);

		return ( ! $request->is_error() ? $request->get_response() : $request->get_response() );
	}

	public function generator_result_check( VD_Product $product, $generator, $data = array(), $settings = array() ) {
		$slug    = sanitize_title( $generator );
		$request = new VD_Request(
			"generator/{$slug}/result",
			$product,
			array(
				'method'   => 'POST',
				'key'      => $product->get_key(),
				'data'     => $data,
				'settings' => $settings,
			)
		);

		return ( ! $request->is_error() ? $request->get_response() : $request->get_response() );
	}

	public function to_array( $object ) {
		return json_decode( wp_json_encode( $object ), true );
	}
}


