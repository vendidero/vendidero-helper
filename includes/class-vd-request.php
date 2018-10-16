<?php

class VD_Request {

	public $product = null;
	private $response = null;
	private $raw = null;
	private $args = array();

	public function __construct( $type = 'update_check', VD_Product $product = null, $args = array() ) {
		if ( $product ) {
			$this->product = $product;
			$this->args = array(
				'product_id' => $product->id,
				'product_file' => $product->file,
				'product_type' => ( $product->is_theme() ? 'theme' : 'plugin' ),
				'key' => ( $product->is_registered() ? $product->get_key() : false ),
				'home_url' => esc_url( $product->get_home_url() ),
			);
		} else {
			$this->args[ 'home_url' ] = esc_url( home_url( '/' ) );
		}

		if ( ! in_array( $type, array( 'update_check', 'update', 'ping', 'register', 'unregister', 'expiration_check', 'license_check', 'generator_version_check', 'generator_check', 'generator_result_check', 'info' ) ) )
			return new WP_Error( __( 'Request method not supported', 'vendidero' ) );

		$this->args = array_merge( $this->args, $args );
		$this->args['request'] = $type;
		$this->response = new stdClass();
		$this->init();
	}

	public function init() {
		$this->do_request();
	}

	public function do_request() {
		// Send request
	    $this->raw = wp_remote_post( VD()->get_api_url(), array(
			'method'      => 'POST',
			'timeout'     => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => array( 'user-agent' => 'Vendidero/' . VD()->version ),
			'body'        => $this->args,
			'cookies'     => array(),
			'sslverify'   => false
		) );

	    if ( $this->raw != '' )
	    	$this->response = json_decode( wp_remote_retrieve_body( $this->raw ) );
	}

	public function is_error() {
		if ( isset( $this->response->error ) ) {
			if ( is_array( $this->response->error ) && empty( $this->response->error ) )
				return false;
			return true;
		}
	}

	public function get_response( $type = "filtered" ) {
		if ( $type == "filtered" ) {
			if ( $this->is_error() )
				return $this->response->error;
			elseif ( isset( $this->response->payload ) )
				return $this->response->payload;
			elseif ( isset( $this->response->success ) )
				return $this->response->success;
		} elseif ( $type == 'all' )
			return $this->response;
		elseif ( isset( $this->response->$type ) )
			return $this->response->$type;
		return false;
	}

}

?>