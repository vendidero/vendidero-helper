<?php

class VD_Request {

	public $product   = null;
	private $response = null;
	private $raw      = null;
	private $args     = array();
	private $code     = 500;

	public function __construct( $type = 'ping', VD_Product $product = null, $args = array() ) {
		$default_args = array(
			'method'  => 'GET',
			'request' => $type,
		);

		if ( $product ) {
			$this->product          = $product;
			$default_args['id']     = $product->id;
			$default_args['key']    = ( $product->is_registered() ? $product->get_key() : false );
			$domain                 = $product->get_home_url();
			$default_args['domain'] = is_array( $domain ) ? array_map( 'esc_url', $product->get_home_url() ) : esc_url( $domain );
		} else {
			$default_args['domain'] = esc_url( home_url( '/' ) );
		}

		$this->args     = wp_parse_args( $args, $default_args );
		$this->response = new stdClass();

		$this->init();
	}

	public function init() {
		$this->do_request();
	}

	private function get_endpoint() {
		$api_url = VD()->get_api_url();

		if ( strpos( $this->args['request'], 'releases/' ) !== false ) {
			$api_url = VD()->get_download_api_url();
		}

		return trailingslashit( $api_url ) . $this->args['request'];
	}

	public function do_request() {

		if ( 'GET' === $this->args['method'] ) {
			$url = add_query_arg( $this->args, $this->get_endpoint() );

			$this->raw = wp_remote_get(
				esc_url_raw( $url ),
				array(
					'redirection' => 5,
					'blocking'    => true,
					'headers'     => array( 'user-agent' => 'Vendidero/' . VD()->version ),
					'cookies'     => array(),
					'sslverify'   => false,
				)
			);
		} else {
			$this->raw = wp_remote_post(
				esc_url_raw( $this->get_endpoint() ),
				array(
					'method'      => 'POST',
					'redirection' => 5,
					'blocking'    => true,
					'headers'     => array( 'user-agent' => 'Vendidero/' . VD()->version ),
					'body'        => $this->args,
					'cookies'     => array(),
					'sslverify'   => false,
				)
			);
		}

		if ( '' !== $this->raw ) {
			$this->code     = wp_remote_retrieve_response_code( $this->raw );
			$this->response = json_decode( wp_remote_retrieve_body( $this->raw ) );
		}
	}

	public function is_error() {
		if ( in_array( (int) $this->code, array( 500, 404, 429 ), true ) ) {
			return true;
		}

		return false;
	}

	public function get_response( $type = 'filtered' ) {
		if ( 'filtered' === $type ) {
			if ( $this->is_error() ) {

				if ( isset( $this->response->code ) ) {
					$wp_error = new WP_Error( $this->response->code, $this->response->message, $this->response->data );

					if ( isset( $this->response->additional_errors ) ) {
						foreach ( $this->response->additional_errors as $error ) {
							$wp_error->add( $error->code, $error->message );
						}
					}
				} else {
					$wp_error = new WP_Error( 500, __( 'Error while requesting vendidero helper data.', 'vendidero-helper' ) );
				}

				return $wp_error;

			} elseif ( isset( $this->response->payload ) ) {
				return $this->response->payload;
			} elseif ( isset( $this->response->success ) ) {
				return $this->response->success;
			}
		} elseif ( 'all' === $type ) {
			return $this->response;
		} elseif ( isset( $this->response->$type ) ) {
			return $this->response->$type;
		}

		return false;
	}
}


