<?php

class VD_Updater {

	public $product;
	public $notices = array();

	public function __construct( VD_Product $product ) {
		$this->product = $product;
		delete_transient( 'update_plugins' );
		// Check For Updates
		add_filter( 'pre_set_site_transient_update_' . ( $this->product->is_theme() ? 'themes' : 'plugins' ), array( $this, 'update_check' ) );
	}

	public function update_check( $transient ) {
		$request = VD()->api->update_check( $this->product, $this->product->get_key() );
		if ( $request->is_error() )
			$this->add_notice( $request->get_response() );
		else {
			if ( $request->get_response( "notice" ) )
				$this->add_notice( (array) $request->get_response( "notice" ), 'error' );
			if ( $request->get_response( "payload" ) ) {
				$payload = $request->get_response( "payload" );
				// Do only add transient if remote version is newer than local version
				if ( version_compare( $payload->new_version, $this->product->Version, "<=" ) )
					return $transient;
				if ( $this->product->is_theme() )
					$payload = (array) $payload;
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