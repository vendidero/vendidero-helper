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
			'name' 				=> $product->Name,
			'slug' 				=> $product->slug,
			'author' 			=> $product->Author,
			'author_profile' 	=> $product->AuthorURI,
			'version' 			=> $product->Version,
			'homepage' 			=> $product->PluginURI,
			'sections' 			=> array(
				'description' 	=> $product->Description,
				'changelog'		=> '',
			),
		);

		$api_result = VD()->api->info( $product );

		if ( $api_result ) {
			$result = array_replace_recursive( $result, json_decode( json_encode( $api_result ), true ) );
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
							echo '<div class="vd-upgrade-notice" data-for="' . md5( $product->Name ) .'" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( __( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vendidero-helper' ), '<a href="' . admin_url( 'index.php?page=vendidero' ) . '">' . __( 'check', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
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
                            echo '<div class="vd-upgrade-notice" data-for="' . md5( $product->Name ) .'" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( __( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vendidero-helper' ), '<a href="' . admin_url( 'index.php?page=vendidero' ) . '">' . __( 'check', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
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
                    foreach( $result->get_error_messages( $result->get_error_code() ) as $message ) {
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

		if ( in_array( $screen->id, $this->get_notice_excluded_screens() ) ) {
			return;
        }

		$admin_url = is_multisite() ? network_admin_url( 'index.php?page=vendidero' ) : admin_url( 'index.php?page=vendidero' );

		foreach ( VD()->get_products( false ) as $product ) {
		    if ( is_multisite() ) {
			    $blog_id = get_current_blog_id();

			    if ( ! in_array( $blog_id, $product->get_blog_ids() ) && ! is_network_admin() ) {
				    continue;
			    }
            }

			if ( ! $product->is_registered() ) { ?>
				<div class="error">
			        <p><?php printf( __( 'Your %s license doesn\'t seem to be registered. Please %s', 'vendidero-helper' ), esc_attr( $product->Name ), '<a style="margin-left: 5px;" class="button button-secondary" href="' . esc_url( $admin_url ) . '">' . __( 'manage your licenses', 'vendidero-helper' ) . '</a>' ); ?></p>
			    </div>
            <?php } elseif( $product->has_expired() && $product->supports_renewals() ) { ?>
                <div class="error">
                    <p><?php printf( __( 'Your %1$s license has expired on %2$s. %3$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', $product->get_expiration_date( get_option( 'date_format' ) ), '<a style="margin-left: 5px;" class="button button-primary wc-gzd-button" target="_blank" href="' . esc_url( $product->get_renewal_url() ) . '">' . __( 'renew now', 'vendidero-helper' ) . '</a>' ); ?></p>
                </div>
			<?php }
		}
	}

	public function screen() {
		?>
        <div class="vd-wrapper">
			<div class="wrap about-wrap vendidero-wrap">
				<div class="col-wrap">
					<h1><?php _e( 'Welcome to vendidero', 'vendidero-helper' ); ?></h1>
					<div class="about-text vendidero-updater-about-text">
						<?php _e( 'Easily manage your licenses for vendidero Products and enjoy automatic updates & more.', 'vendidero-helper' ); ?>
                    </div>

					<?php do_action( 'vd_admin_notices' ); ?>
				</div>
			</div>

            <?php if ( VD()->api->ping() ) : ?>
                <?php require_once( VD()->plugin_path() . '/screens/screen-manage-licenses.php' ); ?>
            <?php else : ?>
                <?php require_once( VD()->plugin_path() . '/screens/screen-api-unavailable.php' ); ?>
            <?php endif; ?>
        </div>
		<?php
	}

	public function get_action( $actions = array() ) {
		foreach ( $actions as $action ) {
		    if ( ( isset( $_GET['action'] ) && $_GET['action'] == $action ) || ( isset( $_POST['action'] ) && $_POST['action'] == $action ) ) {
				return str_replace( "vd_", "", $action );
            }
		}

		return false;
	}

	public function process() {
		$action = $this->get_action( array( 'vd_register', 'vd_unregister' ) );

		if ( current_user_can( 'manage_options' ) ) {
			if ( $action && wp_verify_nonce( ( isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : $_POST['_wpnonce'] ), 'bulk_licenses' ) ) {
				do_action( 'vd_process_' . $action );
			}
		}
	}

	public function process_register() {
		$errors   = array();
		$products = VD()->get_products();

		if ( isset( $_POST['license_keys'] ) && 0 < count( $_POST['license_keys'] ) ) {
			foreach ( $_POST['license_keys'] as $file => $key ) {
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
	}

	public function process_unregister() {
		$errors     = array();
		$products   = VD()->get_products();
		$file       = sanitize_text_field( $_GET['filepath'] );

		if ( isset( $products[ $file ] ) ) {
			if ( ! VD()->api->unregister( $products[ $file ] ) ) {
				array_push( $errors, sprintf( __( "Sorry, there was an error while unregistering %s", "vendidero-helper" ), $products[ $file ]->Name ) );
            }
		}

		if ( ! empty( $errors ) ) {
			$this->add_notice( $errors, 'error' );
        }

		VD()->api->flush_cache();
	}

	public function add_notice( $msg = array(), $type = 'error' ) {
		$this->notices = array( 'msg' => $msg, 'type' => $type );

		add_action( 'vd_admin_notices', array( $this, 'print_notice' ) );
	}

	public function print_notice() {
		if ( ! empty( $this->notices ) ) {
			echo '<div class="inline ' . $this->notices['type'] . '"><p>';
			echo implode( "<br/>", $this->notices['msg'] );
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