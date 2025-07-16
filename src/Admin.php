<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

class Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_filter' ), 150, 3 );
		add_action( 'in_admin_header', array( __CLASS__, 'set_upgrade_notice' ) );

		add_action( 'admin_notices', array( __CLASS__, 'product_status_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'product_status_notice' ) );
		add_action( 'wp_ajax_vd_dismiss_notice', array( __CLASS__, 'dismiss_notice' ) );
		add_action( 'wp_ajax_vd_register_license', array( __CLASS__, 'process_register' ) );
		add_action( 'wp_ajax_vd_unregister_license', array( __CLASS__, 'process_unregister' ) );
		add_action( 'admin_post_vd_refresh_license_status', array( __CLASS__, 'refresh_license_status' ) );

		if ( is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_standalone_check' ) );
			add_action( 'admin_post_install_vd_helper', array( __CLASS__, 'multisite_helper_install' ) );
		}
	}

	public static function multisite_helper_install() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'vd_install_helper' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		if ( ! is_super_admin() ) {
			return;
		}

		require_once ABSPATH . '/wp-admin/includes/plugin.php';
		include_once ABSPATH . '/wp-admin/includes/admin.php';
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		include_once ABSPATH . '/wp-admin/includes/plugin.php';
		include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/class-plugin-upgrader.php';
		include_once ABSPATH . '/wp-admin/includes/class-automatic-upgrader-skin.php';

		$plugins = array_keys( get_plugins() );

		if ( ! in_array( 'vendidero-helper/vendidero-helper.php', $plugins, true ) ) {
			$download_url = 'https://github.com/vendidero/vendidero-helper/releases/download/1.0.0/vendidero-helper.zip';
			$upgrader     = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result       = $upgrader->install( $download_url );
		} else {
			$result = true;
		}

		if ( true === $result ) {
			wp_safe_redirect( esc_url_raw( self::get_activate_helper_url() ) );
		} else {
			wp_safe_redirect( esc_url_raw( network_admin_url( 'plugins.php' ) ) );
		}

		exit();
	}

	protected static function is_helper_network_activated() {
		require_once ABSPATH . '/wp-admin/includes/plugin.php';

		if ( ! is_plugin_active_for_network( 'vendidero-helper/vendidero-helper.php' ) ) {
			return false;
		}

		return true;
	}

	protected static function get_activate_helper_url() {
		return network_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( 'vendidero-helper/vendidero-helper.php' ) . '&plugin_status=all&paged=1&s&_wpnonce=' . rawurlencode( wp_create_nonce( 'activate-plugin_vendidero-helper/vendidero-helper.php' ) ) );
	}

	public static function multisite_standalone_check() {
		if ( self::is_helper_network_activated() || ! is_super_admin() ) {
			return;
		}

		$install_url  = wp_nonce_url( admin_url( 'admin-post.php?action=install_vd_helper' ), 'vd_install_helper' );
		$activate_url = self::get_activate_helper_url();

		$message = wp_kses_post( sprintf( _x( 'To ensure smooth updates you\'ll need to %1$sinstall the standalone vendidero helper plugin%2$s network-wide.', 'vd-helper', 'vendidero-helper' ), '<a href="' . esc_url( $install_url ) . '">', '</a>' ) );
		$plugins = array_keys( get_plugins() );

		// Helper is installed but not activated
		if ( in_array( 'vendidero-helper/vendidero-helper.php', $plugins, true ) ) {
			$message = wp_kses_post( sprintf( _x( 'The vendidero helper needs to be %1$sactivated network-wide%2$s to ensure smooth updates for your products.', 'vd-helper', 'vendidero-helper' ), '<a href="' . esc_url( $activate_url ) . '">', '</a>' ) );
		}

		echo '<div class="error fade"><p>' . $message . '</p></div>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function refresh_license_status() {
		if ( isset( $_GET['_wpnonce'], $_GET['product_id'] ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				if ( wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'vd-refresh-license-status' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$product_id = absint( $_GET['product_id'] );

					if ( $product = Package::get_product_by_id( $product_id ) ) {
						$product->refresh_expiration_date( true );
					}

					wp_safe_redirect( esc_url_raw( $product->get_license_page() ) );
					exit();
				}
			}
		}
	}

	public static function plugins_api_filter( $result, $action, $args ) {
		$products = Package::get_products();
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

		$api_result = Package::get_api()->info( $product );

		if ( $api_result ) {
			$result = array_replace_recursive( $result, json_decode( wp_json_encode( $api_result ), true ) );
		}

		return (object) $result;
	}

	public static function set_upgrade_notice() {
		if ( in_array( get_current_screen()->id, array( 'update-core', 'update-core-network' ), true ) ) {
			$transient        = get_site_transient( 'update_plugins' );
			$transient_themes = get_site_transient( 'update_themes' );
			$products         = Package::get_products();

			if ( empty( $products ) ) {
				return;
			}

			if ( ! empty( $transient ) && isset( $transient->response ) ) {
				foreach ( $transient->response as $plugin => $data ) {
					if ( isset( $products[ $plugin ] ) ) {
						$product = $products[ $plugin ];
						$product->refresh_expiration_date();

						if ( $product->supports_renewals() && $product->has_expired() ) {
							echo '<div class="vd-upgrade-notice" data-for="' . esc_attr( md5( $product->file ) ) . '" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( esc_html_x( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vd-helper', 'vendidero-helper' ), '<a href="' . esc_url( $product->get_license_page( $product->get_expired_blog_id() ) ) . '">' . esc_html_x( 'check', 'vd-helper', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
						}
					}
				}
			}

			if ( ! empty( $transient_themes ) && isset( $transient_themes->response ) ) {
				foreach ( $transient_themes->response as $theme => $data ) {
					if ( isset( $data['theme'] ) && isset( $products[ $data['theme'] ] ) ) {
						$product = $products[ $data['theme'] ];
						$product->refresh_expiration_date();

						if ( $product->supports_renewals() && $product->has_expired() ) {
							echo '<div class="vd-upgrade-notice" data-for="' . esc_attr( md5( $product->file ) ) . '" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( esc_html_x( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vd-helper', 'vendidero-helper' ), '<a href="' . esc_url( $product->get_license_page( $product->get_expired_blog_id() ) ) . '">' . esc_html_x( 'check', 'vd-helper', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
						}
					}
				}
			}
		}
	}

	public static function add_menu() {
		$add_global_page = Package::is_standalone();

		if ( ! $add_global_page ) {
			$products = Package::get_products( false );

			if ( ! empty( $products ) ) {
				foreach ( $products as $product ) {
					if ( ! $product->single_license_page ) {
						$add_global_page = true;
						break;
					}
				}
			}
		}

		if ( $add_global_page ) {
			add_dashboard_page( 'vendidero', 'vendidero', 'manage_options', 'vendidero', array( __CLASS__, 'screen' ) );
		} else {
			/**
			 * Add a hidden page which is only available for direct access via URL.
			 */
			$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 'vendidero' === $current_page ) {
				add_dashboard_page( 'vendidero', 'vendidero', 'manage_options', 'vendidero', array( __CLASS__, 'screen' ) );
			}
		}
	}

	public static function license_refresh() {
		$products = Package::get_products( false );

		if ( ! empty( $products ) ) {
			foreach ( $products as $product ) {
				$product->refresh_expiration_date();
			}
		}
	}

	public static function get_notice_excluded_screens() {
		return array( 'index_page_vendidero-network', 'dashboard_page_vendidero', 'toplevel_page_vendidero', 'update-core-network', 'update-core' );
	}

	protected static function get_hidden_notices( $context = 'view' ) {
		$notices = array_filter( (array) get_transient( '_vd_hide_notice' ) );

		if ( 'view' === $context ) {
			foreach ( self::get_notice_types() as $notice_type ) {
				if ( ! array_key_exists( $notice_type, $notices ) ) {
					$notices[ $notice_type ] = array();
				}

				$notices[ $notice_type ] = array_unique( (array) $notices[ $notice_type ] );
			}
		}

		return $notices;
	}

	protected static function notice_is_hidden( $notice, $product_id = '-1' ) {
		$notices = self::get_hidden_notices();

		return array_key_exists( $notice, $notices ) && in_array( $product_id, $notices[ $notice ] ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict
	}

	protected static function current_user_can_hide_notices() {
		return current_user_can( 'manage_options' );
	}

	public static function dismiss_notice() {
		check_ajax_referer( 'vd_dismiss_notice' );

		if ( ! self::current_user_can_hide_notices() ) {
			return;
		}

		$product_id = sanitize_text_field( isset( $_POST['product_id'] ) ? wp_unslash( $_POST['product_id'] ) : '-1' );
		$notice     = sanitize_text_field( isset( $_POST['notice'] ) ? wp_unslash( $_POST['notice'] ) : '' );

		if ( in_array( $notice, self::get_notice_types(), true ) ) {
			$current_hidden_notices              = self::get_hidden_notices();
			$current_hidden_notices[ $notice ][] = $product_id;

			set_transient( '_vd_hide_notice', array_unique( $current_hidden_notices ), DAY_IN_SECONDS * 30 );

			wp_send_json_success();
		}

		wp_send_json_error();
	}

	protected static function get_notice_types() {
		return array( 'expired' );
	}

	protected static function screen_is_excluded_for_notices() {
		$screen = get_current_screen();

		if ( $screen && ( in_array( $screen->id, self::get_notice_excluded_screens(), true ) || ( 'woocommerce_page_wc-settings' === $screen->id && isset( $_GET['tab'] ) && strstr( sanitize_text_field( wp_unslash( $_GET['tab'] ) ), 'license' ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		return false;
	}

	public static function product_status_notice() {
		if ( self::screen_is_excluded_for_notices() ) {
			return;
		}

		$has_dismissible_notice = false;

		foreach ( Package::get_products( false ) as $product ) :
			if ( is_multisite() && is_network_admin() ) :
				foreach ( $product->get_blog_ids() as $blog_id ) :
					$blog_info         = get_blog_details( $blog_id );
					$notice_product_id = $blog_id . '_' . $product->id;

					if ( ! $product->is_registered( $blog_id ) ) : ?>
						<div class="vd-notice notice error" role="alert">
							<p><?php printf( esc_html_x( 'Your %1$s license for %2$s doesn\'t seem to be registered. Please %3$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $blog_info->blogname ), '<a style="margin-left: 5px;" class="button button-secondary" href="' . esc_url( $product->get_license_page( $blog_id ) ) . '">' . esc_html_x( 'manage your licenses', 'vd-helper', 'vendidero-helper' ) . '</a>' ); ?></p>
						</div>
						<?php
					elseif ( $product->supports_renewals() && $product->has_expired( $blog_id ) && ! self::notice_is_hidden( 'expired', $notice_product_id ) ) :
						$has_dismissible_notice = true;
						?>
						<div class="vd-notice notice error <?php echo esc_attr( self::current_user_can_hide_notices() ? 'is-dismissible' : '' ); ?>" role="alert" data-id="expired" data-product_id="<?php echo esc_attr( $notice_product_id ); ?>">
							<p>
								<?php printf( esc_html_x( 'Your %1$s license for %2$s has expired on %3$s. %4$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $blog_info->blogname ), esc_html( $product->get_expiration_date( get_option( 'date_format' ), $blog_id ) ), '<a style="margin-left: 5px;" class="button button-primary wc-gzd-button" target="_blank" href="' . esc_url( $product->get_renewal_url( $blog_id ) ) . '">' . esc_html_x( 'renew now', 'vd-helper', 'vendidero-helper' ) . '</a>' ); ?>
								<?php if ( self::current_user_can_hide_notices() ) : ?>
									<button type="button" href="#" class="notice-dismiss" style="bottom: 0;"></button>
								<?php endif; ?>
							</p>
						</div>
						<?php
					elseif ( $product->has_errors() ) :
						$error_message = implode( ', ', $product->get_errors() );
						?>
						<div class="vd-notice notice error" role="alert">
							<p>
								<?php echo wp_kses_post( sprintf( _x( 'We\'ve detected an issue with your %1$s <a href="%2$s">license</a>: %3$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', $product->get_license_page( $blog_id ), $error_message ) ); ?>
							</p>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php elseif ( ! $product->is_registered() ) : ?>
				<div class="vd-notice notice error" role="alert">
					<p><?php printf( esc_html_x( 'Your %1$s license doesn\'t seem to be registered. Please %2$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', '<a style="margin-left: 5px;" class="button button-secondary" href="' . esc_url( $product->get_license_page() ) . '">' . esc_html_x( 'manage your license', 'vd-helper', 'vendidero-helper' ) . '</a>' ); ?></p>
				</div>
				<?php
			elseif ( $product->has_expired() && $product->supports_renewals() && ! self::notice_is_hidden( 'expired', $product->id ) ) :
				$has_dismissible_notice = true;
				?>
				<div class="vd-notice notice error <?php echo esc_attr( self::current_user_can_hide_notices() ? 'is-dismissible' : '' ); ?>" role="alert" data-id="expired" data-product_id="<?php echo esc_attr( $product->id ); ?>">
					<p>
						<?php printf( esc_html_x( 'Your %1$s license has expired on %2$s. %3$s %4$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $product->get_expiration_date( get_option( 'date_format' ) ) ), '<a style="margin-left: 5px;" class="button button-primary wc-gzd-button" target="_blank" href="' . esc_url( $product->get_renewal_url() ) . '">' . esc_html_x( 'renew now', 'vd-helper', 'vendidero-helper' ) . '</a>', '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vd_refresh_license_status&product_id=' . esc_attr( $product->id ) ), 'vd-refresh-license-status' ) ) . '" class="" style="margin-left: 1em;">' . esc_html_x( 'Already renewed?', 'vd-helper', 'vendidero-helper' ) . '</a>' ); ?>
						<?php if ( self::current_user_can_hide_notices() ) : ?>
							<button type="button" href="#" class="notice-dismiss" style="bottom: 0;"></button>
						<?php endif; ?>
					</p>
				</div>
				<?php
			elseif ( $product->has_errors() ) :
				$error_message = implode( ', ', $product->get_errors() );
				?>
				<div class="vd-notice notice error" role="alert">
					<p>
						<?php echo wp_kses_post( sprintf( _x( 'We\'ve detected an issue with your %1$s <a href="%2$s">license</a>: %3$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', $product->get_license_page(), $error_message ) ); ?>
					</p>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( $has_dismissible_notice ) : ?>
			<script type="text/javascript" >
				jQuery( document ).ready(function( $ ) {
					$( '.vd-notice .notice-dismiss' ).click(function() {
						var
							notice           = $( this ).parents( '.vd-notice' ),
							id               = notice.data( 'id' ),
							product_id       = notice.data( 'product_id' );

						notice.fadeOut( 'fast', function() {
							var data = {
								action    : 'vd_dismiss_notice',
								_wpnonce  : <?php echo wp_json_encode( wp_create_nonce( 'vd_dismiss_notice' ) ); ?>,
								notice    : id,
								product_id: product_id
							};

							$.post( '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>', data, function( response ) {

							});

							notice.remove();
						});
					});
				});
			</script>
			<?php
		endif;
	}

	public static function screen( $single_product_id = false ) {
		$is_single_display = false;

		if ( $single_product_id ) {
			$is_single_display = true;
			$vd_product        = Package::get_product_by_id( $single_product_id );

			if ( ! $vd_product ) {
				return;
			}
		}
		wp_enqueue_script( 'vd_license_table_js' );
		self::license_refresh();
		?>
		<div class="vd-wrapper <?php echo esc_attr( $is_single_display ? 'vd-single-wrapper' : '' ); ?>">
			<?php if ( ! $is_single_display ) : ?>
				<div class="wrap about-wrap vendidero-wrap">
					<div class="col-wrap">
						<h1><?php echo esc_html_x( 'Welcome to vendidero', 'vd-helper', 'vendidero-helper' ); ?></h1>
						<div class="about-text vendidero-updater-about-text">
							<?php echo esc_html_x( 'Easily manage your licenses for vendidero Products and enjoy automatic updates & more.', 'vd-helper', 'vendidero-helper' ); ?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( Package::get_api()->ping() ) : ?>
				<?php require_once Package::get_path() . '/includes/screens/screen-manage-licenses.php'; ?>
			<?php else : ?>
				<?php require_once Package::get_path() . '/includes/screens/screen-api-unavailable.php'; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function get_action( $actions = array() ) {
		foreach ( $actions as $action ) {
			if ( ( isset( $_GET['action'] ) && $_GET['action'] === $action ) || ( isset( $_POST['action'] ) && $_POST['action'] === $action ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
				return str_replace( 'vd_', '', $action );
			}
		}

		return false;
	}

	public static function process_register() {
		check_ajax_referer( 'vd_register_license', 'security' );

		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['license_key'], $_POST['file'] ) ) {
			wp_die( - 1 );
		}

		$license_key = sanitize_text_field( wp_unslash( $_POST['license_key'] ) );
		$file        = sanitize_text_field( wp_unslash( $_POST['file'] ) );

		if ( $product = Package::get_product( $file ) ) {
			if ( empty( $license_key ) ) {
				wp_send_json(
					array(
						'message' => _x( 'Please provide your license key.', 'vd-helper', 'vendidero-helper' ),
					)
				);
			} elseif ( ! $product->is_registered() ) {
				$response = Package::get_api()->register( $product, $license_key );

				if ( is_wp_error( $response ) ) {
					wp_send_json(
						array(
							'message' => $response->get_error_message(),
						)
					);
				} else {
					Package::get_api()->flush_cache();

					wp_send_json(
						array(
							'success' => true,
						)
					);
				}
			} else {
				wp_send_json(
					array(
						'success' => true,
					)
				);
			}
		}

		wp_send_json(
			array(
				'message' => _x( 'There was an issue registering the license.', 'vd-helper', 'vendidero-helper' ),
			)
		);
	}

	public static function process_unregister() {
		check_ajax_referer( 'vd_unregister_license', 'security' );

		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['file'] ) ) {
			wp_die( - 1 );
		}

		$file = sanitize_text_field( wp_unslash( $_POST['file'] ) );

		if ( $product = Package::get_product( $file ) ) {
			Package::get_api()->unregister( $product );
		}

		wp_send_json(
			array(
				'success' => true,
			)
		);
	}

	public static function add_notice( $msg = array(), $type = 'error' ) {}

	public static function get_notices() {
		return array();
	}

	public static function clean_notices() {}

	public static function print_notice() {}

	public static function enqueue_scripts() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		$screens   = array(
			'update-core-network',
			'update-core',
			'plugins-network',
			'plugins',
			'themes-network',
			'themes',
			'dashboard_page_vendidero',
			'toplevel_page_vendidero',
		);

		wp_register_style( 'vd_admin', Package::get_url() . '/assets/css/vd-admin.css', array(), Package::get_version() );
		wp_register_script( 'vd_admin_js', Package::get_url() . '/assets/js/vd-admin.js', array( 'jquery' ), Package::get_version(), true );

		if ( in_array( $screen_id, $screens, true ) ) {
			wp_enqueue_style( 'vd_admin' );
			wp_enqueue_script( 'vd_admin_js' );
		}

		wp_register_script( 'vd_license_table_js', Package::get_url() . '/assets/js/vd-license-table.js', array( 'jquery' ), Package::get_version(), true );

		wp_localize_script(
			'vd_license_table_js',
			'vd_license_table_params',
			array(
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				'unregister_nonce' => wp_create_nonce( 'vd_unregister_license' ),
				'register_nonce'   => wp_create_nonce( 'vd_register_license' ),
			)
		);
	}
}
