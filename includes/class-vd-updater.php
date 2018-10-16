<?php

class VD_Updater {

	public $product;
	public $notices = array();
	public $upgrade_notices = array();
	public $added_upgrade_notice = false;

	public function __construct( VD_Product $product ) {
		
		$this->product = $product;
		
		// Check For Updates
		add_filter( 'pre_set_site_transient_update_' . ( $this->product->is_theme() ? 'themes' : 'plugins' ), array( $this, 'update_check' ) );
		add_action( 'http_request_args', array( $this, 'ssl_verify' ), 10, 2 );
	}

	public function ssl_verify( $args, $url ) {

		if ( is_admin() ) {
			if ( apply_filters( 'vd_helper_disable_ssl_verify', false ) && $url == VD()->get_api_url() ) {
				$args['sslverify'] = false;
			}
		}

		return $args; 
	}

	public function update_check( $transient ) {
		
		$request = VD()->api->update_check( $this->product, $this->product->get_key() );
		
		if ( $request->is_error() ) {
		
			$this->add_notice( $request->get_response() );
		
		} else {
		
			if ( $request->get_response( "notice" ) )
				$this->add_notice( (array) $request->get_response( "notice" ), 'error' );
			
			if ( $request->get_response( "payload" ) ) {
				
				$payload = $request->get_response( "payload" );
				
				// Do only add transient if remote version is newer than local version
				if ( version_compare( $payload->new_version, $this->product->Version, "<=" ) )
					return $transient;
				
				// Set plugin/theme file (seems to be necessary as for 4.2)
				if ( ! $this->product->is_theme() ) {
				
					$payload->plugin = $this->product->file;
					$payload->slug = sanitize_title( $this->product->Name );
					$payload->vd_expire_notice = sprintf( __( 'There is a new Version of %s but your Update Flatrate seems to have expired. Please <a href="%s" target="_blank">renew your Update Flatrate</a> first.', 'vendidero-helper' ), $this->product->Name,  $this->product->get_renewal_url() );

				} else {
				
					$payload = (array) $payload;
					$payload['theme'] = $this->product->file;
					$payload['vd_expire_notice'] = sprintf( __( 'There is a new Version of %s but your Update Flatrate seems to have expired. Please <a href="%s" target="_blank">renew your Update Flatrate</a> first.', 'vendidero-helper' ), $this->product->Name,  $this->product->get_renewal_url() );

				}

				$transient->response[ ( ( $this->product->is_theme() ) ? $this->product->Name : $this->product->file ) ] = $payload;
			}
		}

	    return $transient;
	}

	public function add_notice( $notice = array(), $type = 'error' ) {
		$this->notices = array( "msg" => $notice, "type" => $type );
		add_action( "admin_notices", array( $this, "print_notice" ) );
	}

	public function print_notice() {
		if ( ! empty( $this->notices ) ) {
			echo '<div class="vd-notice-' . ( $this->product->is_theme() ? 'theme' : 'plugin' ) . '-update inline ' . $this->notices[ 'type' ] . '"><p>';
			echo implode( "<br/>", $this->notices[ 'msg' ] );
			echo '</p></div>';
		}
	}

}

?>