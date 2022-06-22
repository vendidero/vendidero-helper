<?php

class VD_Admin {

	public $notices = array();

	public function __construct() {

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'add_menu' ) );
		}

		add_action( 'vd_process_register', array( $this, 'process_register' ) );
		add_action( 'vd_process_unregister', array( $this, 'process_unregister' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 150, 3 );
		add_action( 'in_admin_header', array( $this, 'set_upgrade_notice' ) );

		add_action( 'admin_notices', array( $this, 'product_registered' ) );
		add_action( 'network_admin_notices', array( $this, 'product_registered' ) );

		add_action( 'admin_post_vd_refresh_license_status', array( $this, 'refresh_license_status' ) );

		add_action( 'vd_admin_notices', array( $this, 'print_notice' ) );
	}

	public function refresh_license_status() {
		if ( isset( $_GET['_wpnonce'], $_GET['product_id'] ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				if ( wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'vd-refresh-license-status' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$product_id = absint( $_GET['product_id'] );

					if ( $product = VD()->get_product_by_id( $product_id ) ) {
						$product->refresh_expiration_date( true );
					}

					wp_safe_redirect( esc_url_raw( VD()->get_helper_url() ) );
					exit();
				}
			}
		}
	}

	public function plugins_api_filter( $result, $action, $args ) {
		$products = VD()->get_products();
		$product  = false;

		if ( ! isset( $args->slug ) ) {
			return $result;
		}

		foreach ( $products as $product_item ) {
			if ( ! $product_item->is_theme() && $args->slug === $product_item->slug ) {
				$product = $product_item;
			}
		}

		if ( ! $product ) {
			return $result;
		}

		$result = array(
			'name'           => $product->Name,
			'slug'           => $product->slug,
			'author'         => $product->Author,
			'author_profile' => $product->AuthorURI,
			'version'        => $product->Version,
			'homepage'       => $product->PluginURI,
			'sections'       => array(
				'description' => $product->Description,
				'changelog'   => '',
			),
		);

		$api_result = VD()->api->info( $product );

		if ( $api_result ) {
			$result = array_replace_recursive( $result, json_decode( wp_json_encode( $api_result ), true ) );
		}

		return (object) $result;
	}

	public function set_upgrade_notice() {
		if ( 'update-core' === get_current_screen()->id ) {
			$transient        = get_site_transient( 'update_plugins' );
			$transient_themes = get_site_transient( 'update_themes' );
			$products         = VD()->get_products();

			if ( ! empty( $transient ) && isset( $transient->response ) ) {
				foreach ( $transient->response as $plugin => $data ) {

					if ( isset( $products[ $plugin ] ) ) {
						$product = $products[ $plugin ];
						$product->refresh_expiration_date();

						if ( $product->has_expired() && $product->supports_renewals() ) {
							echo '<div class="vd-upgrade-notice" data-for="' . esc_attr( md5( $product->Name ) ) . '" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( esc_html__( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vendidero-helper' ), '<a href="' . esc_url( VD()->get_helper_url() ) . '">' . esc_html__( 'check', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
						}
					}
				}
			}

			if ( ! empty( $transient_themes ) && isset( $transient_themes->response ) ) {
				foreach ( $transient_themes->response as $theme => $data ) {

					if ( isset( $data['theme'] ) && isset( $products[ $data['theme'] ] ) ) {
						$product = $products[ $data['theme'] ];
						$product->refresh_expiration_date();

						if ( $product->has_expired() && $product->supports_renewals() ) {
							echo '<div class="vd-upgrade-notice" data-for="' . esc_attr( md5( $product->Name ) ) . '" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( esc_html__( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vendidero-helper' ), '<a href="' . esc_url( VD()->get_helper_url() ) . '">' . esc_html__( 'check', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
						}
					}
				}
			}
		}
	}

	public function add_menu() {
		$hook = add_dashboard_page( 'vendidero', 'vendidero', 'manage_options', 'vendidero', array( $this, 'screen' ) );

		add_action( 'load-' . $hook, array( $this, 'process' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'load-' . $hook, array( $this, 'license_refresh' ) );
	}

	public function license_refresh() {
		$products = VD()->get_products( false );

		if ( ! empty( $products ) ) {
			$errors = array();

			foreach ( $products as $product ) {
				$result = $product->refresh_expiration_date();

				if ( is_wp_error( $result ) ) {
					foreach ( $result->get_error_messages( $result->get_error_code() ) as $message ) {
						$errors[] = $message;
					}
				}
			}

			if ( ! empty( $errors ) ) {
				$this->add_notice( $errors, 'error' );
			}
		}
	}

	public function get_notice_excluded_screens() {
		return array( 'index_page_vendidero-network', 'dashboard_page_vendidero', 'update-core-network', 'update-core' );
	}

	public function product_registered() {
		$screen = get_current_screen();

		if ( in_array( $screen->id, $this->get_notice_excluded_screens(), true ) ) {
			return;
		}

		$admin_url = VD()->get_helper_url();

		foreach ( VD()->get_products( false ) as $product ) {
			if ( is_multisite() ) {
				$blog_id = get_current_blog_id();

				if ( ! in_array( $blog_id, $product->get_blog_ids(), true ) && ! is_network_admin() ) {
					continue;
				}
			}

			if ( ! $product->is_registered() ) { ?>
				<div class="error">
					<p><?php printf( esc_html__( 'Your %1$s license doesn\'t seem to be registered. Please %2$s', 'vendidero-helper' ), esc_attr( $product->Name ), '<a style="margin-left: 5px;" class="button button-secondary" href="' . esc_url( $admin_url ) . '">' . esc_html__( 'manage your licenses', 'vendidero-helper' ) . '</a>' ); ?></p>
				</div>
			<?php } elseif ( $product->has_expired() && $product->supports_renewals() ) { ?>
				<div class="error">
					<p><?php printf( esc_html__( 'Your %1$s license has expired on %2$s. %3$s %4$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $product->get_expiration_date( get_option( 'date_format' ) ) ), '<a style="margin-left: 5px;" class="button button-primary wc-gzd-button" target="_blank" href="' . esc_url( $product->get_renewal_url() ) . '">' . esc_html__( 'renew now', 'vendidero-helper' ) . '</a>', '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vd_refresh_license_status&product_id=' . esc_attr( $product->id ) ), 'vd-refresh-license-status' ) ) . '" class="" style="margin-left: 1em;">' . esc_html__( 'Already renewed?', 'vendidero-helper' ) . '</a>' ); ?></p>
				</div>
				<?php
			}
		}
	}

	public function screen() {
		?>
		<div class="vd-wrapper">
			<div class="wrap about-wrap vendidero-wrap">
				<div class="col-wrap">
					<h1><?php esc_html_e( 'Welcome to vendidero', 'vendidero-helper' ); ?></h1>
					<div class="about-text vendidero-updater-about-text">
						<?php esc_html_e( 'Easily manage your licenses for vendidero Products and enjoy automatic updates & more.', 'vendidero-helper' ); ?>
					</div>

					<?php do_action( 'vd_admin_notices' ); ?>
				</div>
			</div>

			<?php if ( VD()->api->ping() ) : ?>
				<?php require_once VD()->plugin_path() . '/screens/screen-manage-licenses.php'; ?>
			<?php else : ?>
				<?php require_once VD()->plugin_path() . '/screens/screen-api-unavailable.php'; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function get_action( $actions = array() ) {
		foreach ( $actions as $action ) {
			if ( ( isset( $_GET['action'] ) && $_GET['action'] === $action ) || ( isset( $_POST['action'] ) && $_POST['action'] === $action ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
				return str_replace( 'vd_', '', $action );
			}
		}

		return false;
	}

	public function process() {
		if ( ! isset( $_GET['_wpnonce'] ) && ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		$action = $this->get_action( array( 'vd_register', 'vd_unregister' ) );

		if ( current_user_can( 'manage_options' ) ) {
			if ( $action && wp_verify_nonce( ( isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : wp_unslash( $_POST['_wpnonce'] ) ), 'bulk_licenses' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				do_action( 'vd_process_' . $action );
			}
		}
	}

	public function process_register() {
		$errors   = array();
		$products = VD()->get_products();

		if ( isset( $_POST['license_keys'] ) && 0 < count( $_POST['license_keys'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['license_keys'] ) as $file => $key ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
				$key  = sanitize_text_field( $key );
				$file = sanitize_text_field( $file );

				if ( empty( $key ) || $products[ $file ]->is_registered() ) {
					continue;
				} else {
					$response = VD()->api->register( $products[ $file ], $key );

					if ( is_wp_error( $response ) ) {
						array_push( $errors, $response->get_error_message( $response->get_error_code() ) );
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			$this->add_notice( $errors, 'error' );
		}

		VD()->api->flush_cache();

		wp_safe_redirect( esc_url_raw( VD()->get_helper_url() ) );
		exit();
	}

	public function process_unregister() {
		$errors   = array();
		$products = VD()->get_products();
		$file     = isset( $_GET['filepath'] ) ? sanitize_text_field( wp_unslash( $_GET['filepath'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $products[ $file ] ) ) {
			if ( ! VD()->api->unregister( $products[ $file ] ) ) {
				array_push( $errors, sprintf( __( 'Sorry, there was an error while unregistering %s', 'vendidero-helper' ), $products[ $file ]->Name ) );
			}
		}

		if ( ! empty( $errors ) ) {
			$this->add_notice( $errors, 'error' );
		}

		VD()->api->flush_cache();

		wp_safe_redirect( esc_url_raw( VD()->get_helper_url() ) );
		exit();
	}

	public function add_notice( $msg = array(), $type = 'error' ) {
		set_transient(
			'vendidero_helper_notices',
			array(
				'msg'  => $msg,
				'type' => $type,
			),
			MINUTE_IN_SECONDS * 10
		);
	}

	public function get_notices() {
		return get_transient( 'vendidero_helper_notices' );
	}

	public function clean_notices() {
		return delete_transient( 'vendidero_helper_notices' );
	}

	public function print_notice() {
		if ( $notices = $this->get_notices() ) {
			echo '<div class="inline ' . esc_attr( $notices['type'] ) . '"><p>';
			echo wp_kses_post( implode( '<br/>', $notices['msg'] ) );
			echo '</p></div>';

			$this->clean_notices();
		}
	}

	public function enqueue_scripts() {
		wp_register_style( 'vp_admin', VD()->plugin_url() . '/assets/css/vd-admin.css', array(), VD()->version );
		wp_enqueue_style( 'vp_admin' );

		wp_register_script( 'vd_admin_js', VD()->plugin_url() . '/assets/js/vd-admin.js', array( 'jquery' ), VD()->version, true );
		wp_enqueue_script( 'vd_admin_js' );
	}
}

return new VD_Admin();
