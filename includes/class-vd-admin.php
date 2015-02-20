<?php

class VD_Admin {

	public $notices = array();

	public function __construct() {
		// Add Pages
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'vd_process_register', array( $this, 'process_register' ) );
		add_action( 'vd_process_unregister', array( $this, 'process_unregister' ) );
	}

	public function add_menu() {
		$hook = add_dashboard_page( 'vendidero', 'Vendidero', 'manage_options', 'vendidero', array( $this, 'screen' ) );
		add_action( 'load-' . $hook, array( $this, 'process' ) );
		add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	public function screen() {
		?><div class="vd-wrapper">
			<div class="wrap about-wrap vendidero-wrap">
				<div class="col-wrap">
					<h1><?php _e( 'Welcome to Vendidero', 'vendidero' ); ?></h1>
					<div class="about-text vendidero-updater-about-text">
						<?php _e( 'Easily manage your licenses for Vendidero Products and enjoy automatic updates.', 'vendidero' ); ?>
					</div>
					<?php do_action( 'vd_admin_notices' ); ?>
				</div>
			</div>
		</div>
		<?php if ( VD()->api->ping() ) : ?>
			<?php require_once( VD()->plugin_path() . '/screens/screen-manage-licenses.php' ); ?>
		<?php else : ?>
			<?php require_once( VD()->plugin_path() . '/screens/screen-api-unavailable.php' ); ?>
		<?php endif; ?>

		<?php
	}

	public function get_action( $actions = array() ) {
		foreach ( $actions as $action ) {
			if ( ( isset( $_GET[ 'action' ] ) && $_GET[ 'action' ] == $action ) || ( isset( $_POST[ 'action' ] ) && $_POST[ 'action' ] == $action ) )
				return str_replace( "vd_", "", $action );
		}
		return false;
	}

	public function process() {
		$action = $this->get_action( array( 'vd_register', 'vd_unregister' ) );
		if ( $action && wp_verify_nonce( ( isset( $_GET[ '_wpnonce' ] ) ? $_GET[ '_wpnonce' ] : $_POST[ '_wpnonce' ] ), 'bulk_licenses' ) )
			do_action( 'vd_process_' . $action );
	}

	public function process_register() {
		$errors = array();
		$products = VD()->get_products();
		if ( isset( $_POST[ 'license_keys' ] ) && 0 < count( $_POST[ 'license_keys' ] ) ) {
			foreach ( $_POST[ 'license_keys' ] as $file => $key ) {
				if ( empty( $key ) )
					continue;
				if ( ! VD()->api->register( $products[ $file ], $key ) )
					array_push( $errors, sprintf( __( "Sorry, but could not register %s", "vendidero" ), $products[ $file ]->Name ) );
			}
		}
		if ( ! empty( $errors ) )
			$this->add_notice( $errors, 'error' );
	}

	public function process_unregister() {
		$errors = array();
		$products = VD()->get_products();
		$file = $_GET[ 'filepath' ];
		if ( isset( $products[ $file ] ) ) {
			if ( ! VD()->api->unregister( $products[ $file ] ) )
				array_push( $errors, sprintf( __( "Sorry, there was an error while unregistering %s", "vendidero" ), $products[ $file ]->Name ) );
		}
		if ( ! empty( $errors ) )
			$this->add_notice( $errors, 'error' );
	}

	public function add_notice( $msg = array(), $type = 'error' ) {
		$this->notices = array( 'msg' => $msg, 'type' => $type );
		add_action( 'vd_admin_notices', array( $this, 'print_notice' ) );
	}

	public function print_notice() {
		if ( ! empty( $this->notices ) ) {
			echo '<div class="' . $this->notices[ 'type' ] . '"><p>';
			echo implode( "<br/>", $this->notices[ 'msg' ] );
			echo '</p></div>';
		}
	}

	public function enqueue_styles() {
		wp_register_style( 'vp_admin', VD()->plugin_url() . '/assets/css/vd-admin.css' );
		wp_enqueue_style( 'vp_admin' );
	}

	public function enqueue_scripts() {
		wp_register_script( 'vd_admin_js', VD()->plugin_url() . '/assets/js/vd-admin.js', array( 'jquery' ) );
		wp_enqueue_script( 'vd_admin_js' );
	}

}

return new VD_Admin();

?>