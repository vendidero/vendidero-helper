<?php

class VD_Admin {

	public $notices = array();

	public function __construct() {
		// Add Pages
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'vd_process_register', array( $this, 'process_register' ) );
		add_action( 'vd_process_unregister', array( $this, 'process_unregister' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 150, 3 );

		add_action( 'in_admin_header', array( $this, 'set_upgrade_notice' ) );
	}

	public function plugins_api_filter( $result, $action, $args ) {

		$products = VD()->get_products();
		$product = false;

		if ( ! isset( $args->slug ) )
			return $result;

		foreach ( $products as $product_item ) {

			if ( ! $product_item->is_theme() && $args->slug === $product_item->slug )
				$product = $product_item;

		}

		if ( ! $product )
			return $result;

		$result = array(
			'name' 				=> $product->Name,
			'slug' 				=> $product->slug,
			'author' 			=> $product->Author,
			'author_profile' 	=> $product->AuthorURI,
			'version' 			=> $product->Version,
			'homepage' 			=> $product->PluginURI,
			'sections' 			=> array(
				'description' 	=> '',
				'changelog'		=> '',
			),
		);

		$api_result = VD()->api->info( $product );

		if ( $api_result )
			$result = array_merge( $result, json_decode( json_encode( $api_result ), true ) );

		if ( ! isset( $result[ 'sections' ][ 'description' ] ) )
			$result[ 'sections' ][ 'description' ] = $product->Description;

		return (object) $result;
	}

	public function set_upgrade_notice() {
		
		if ( get_current_screen()->id === 'update-core' ) {
			
			$transient = get_site_transient( 'update_plugins' );
			$transient_themes = get_site_transient( 'update_themes' );
			$products = VD()->get_products();

			if ( ! empty( $transient ) && isset( $transient->response ) ) {
			
				foreach ( $transient->response as $plugin => $data ) {
					
					if ( isset( $products[ $plugin ] ) ) {
						$product = $products[ $plugin ];

						if ( $product->has_expired() && isset( $data->vd_expire_notice ) ) {
							echo '<div class="vd-upgrade-notice" data-for="' . md5( $product->Name ) .'" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . $data->vd_expire_notice . '</span></div>';
						}
					}
				}
			}

			if ( ! empty( $transient_themes ) && isset( $transient_themes->response ) ) {

				foreach ( $transient_themes->response as $theme => $data ) {

					if ( isset( $data[ 'theme' ] ) && isset( $products[ $data[ 'theme' ] ] ) ) {
						$product = $products[ $data[ 'theme' ] ];
	
						if ( $product->has_expired() && isset( $data[ 'vd_expire_notice' ] ) ) {
							echo '<div class="vd-upgrade-notice" data-for="' . md5( $product->Name ) .'" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . $data[ 'vd_expire_notice' ] . '</span></div>';
						}
					}
				}
			}
		}
	}

	public function add_menu() {
		$hook = add_dashboard_page( 'vendidero', 'Vendidero', 'manage_options', 'vendidero', array( $this, 'screen' ) );
		add_action( 'load-' . $hook, array( $this, 'process' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'product_registered' ) );
		add_action( 'load-' . $hook, array( $this, 'license_refresh' ) );
	}

	public function license_refresh() {
		$action = $this->get_action( array( 'vd_refresh' ) );
		if ( $action && wp_verify_nonce( ( isset( $_GET[ '_wpnonce' ] ) ? $_GET[ '_wpnonce' ] : '' ), 'refresh_licenses' ) ) {
			$products = VD()->get_products( false );
			if ( ! empty( $products ) ) {
				foreach ( $products as $product )
					$product->refresh_expiration_date();
			}
		}
	}

	public function product_registered() {

		$screen = get_current_screen();

		if ( 'dashboard_page_vendidero' === $screen->id )
			return;

		foreach ( VD()->get_products( false ) as $product ) {

			if ( ! $product->is_registered() ) {
				?>
				<div class="error">
			        <p><?php printf( __( 'Your %s license doesn\'t seem to be registered or your Update Flatrate has expired <a style="margin-left: 1em" href="%s" class="button button-secondary">Manage your licenses</a>', 'vendidero-helper' ), $product->Name, admin_url( 'index.php?page=vendidero' ) ); ?></p>
			    </div>
				<?php
			}

		}

	}

	public function screen() {
		?><div class="vd-wrapper">
			<div class="wrap about-wrap vendidero-wrap">
				<div class="col-wrap">
					<h1><?php _e( 'Welcome to Vendidero', 'vendidero-helper' ); ?></h1>
					<div class="about-text vendidero-updater-about-text">
						<?php _e( 'Easily manage your licenses for Vendidero Products and enjoy automatic updates.', 'vendidero-helper' ); ?>
						<?php if ( is_multisite() ) : ?>
							<?php _e( 'You are running a multisite installation. Please make sure to register your license for every site individually.', 'vendidero-helper' ); ?>
						<?php endif; ?>
                    </div>
					<?php do_action( 'vd_admin_notices' ); ?>
				</div>
			</div>
		</div>
		<?php if ( VD()->api->ping() ) :

			// Refresh License Data
			$products = VD()->get_products( false );
			
			if ( ! empty( $products ) ) {
				
				foreach ( $products as $product )
					$product->refresh_expiration_date();
			}

		?>
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
		if ( isset( $_POST['license_keys'] ) && 0 < count( $_POST['license_keys'] ) ) {
			foreach ( $_POST['license_keys'] as $file => $key ) {
				if ( empty( $key ) || $products[ $file ]->is_registered() )
					continue;
				if ( ! VD()->api->register( $products[ $file ], $key ) )
					array_push( $errors, sprintf( __( "Sorry, but could not register %s. Please register your domain within your <a href='%s' target='_blank'>Customer Account</a>.", "vendidero-helper" ), $products[ $file ]->Name, 'https://vendidero.de/mein-konto/lizenzen' ) );
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
				array_push( $errors, sprintf( __( "Sorry, there was an error while unregistering %s", "vendidero-helper" ), $products[ $file ]->Name ) );
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

	public function enqueue_scripts() {

		wp_register_style( 'vp_admin', VD()->plugin_url() . '/assets/css/vd-admin.css' );
		wp_enqueue_style( 'vp_admin' );

		wp_register_script( 'vd_admin_js', VD()->plugin_url() . '/assets/js/vd-admin.js', array( 'jquery' ) );
		wp_enqueue_script( 'vd_admin_js' );

	}

}

return new VD_Admin();

?>