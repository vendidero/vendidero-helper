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
	}

	public function update_check( $transient ) {
		$data = VD()->api->update_check( $this->product, $this->product->get_key() );
		
		if ( ! empty( $data['errors'] ) ) {
			$this->add_notice( $data['errors'] );
		} else {
			if ( ! empty( $data['notices'] ) ) {
				$this->add_notice( $data['notices'], 'error' );
            }

			$filename = ( ( $this->product->is_theme() ) ? $this->product->Name : $this->product->file );
			
			if ( ! empty( $data['payload'] ) ) {
				$payload = $data['payload'];
				
				// Set plugin/theme file (seems to be necessary as for 4.2)
				if ( ! $this->product->is_theme() ) {
					$payload->plugin = $this->product->file;
					$payload->slug   = sanitize_title( $this->product->Name );

					// update-core.php expects icons to be formatted as array (see wp-admin/update-core.php:473
					if ( isset( $payload->custom_icons ) ) {
						$payload->icons = (array) $payload->custom_icons;
					}
				} else {
					$payload = (array) $payload;
					$payload['theme'] = $this->product->file;
				}

				if ( version_compare( $payload->new_version, $this->product->Version, "<=" ) ) {
					if ( ! isset( $transient->no_update ) ) {
						$transient->no_update = array();
					}
					$transient->no_update[ $filename ] = $payload;
					unset( $transient->response[ $filename ] );
				} else {
					$transient->response[ $filename ] = $payload;
					unset( $transient->no_update[ $filename ] );
				}
			}
		}

	    return $transient;
	}

	public function add_notice( $notice = array(), $type = 'error' ) {
		$this->notices = array( "msg" => $notice, "type" => $type );

		add_action( "admin_notices", array( $this, "print_notice" ) );
		add_action( "network_admin_notices", array( $this, "print_notice" ) );
	}

	public function print_notice() {
		if ( ! empty( $this->notices ) ) {
			echo '<div class="vd-notice-' . ( $this->product->is_theme() ? 'theme' : 'plugin' ) . '-update inline ' . $this->notices['type'] . '"><p>';

			if ( is_array( $this->notices['msg'] ) ) {
                echo implode( "<br/>", $this->notices['msg'] );
            } else {
                echo $this->notices['msg'];
            }

			echo '</p></div>';
		}
	}
}
?>