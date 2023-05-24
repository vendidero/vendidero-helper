<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

class Admin {
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'check_notice_hide' ) );
		add_action( 'admin_notices', array( __CLASS__, 'expire_notice' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		add_action( 'vd_process_register', array( __CLASS__, 'process_register' ) );
		add_action( 'vd_process_unregister', array( __CLASS__, 'process_unregister' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_filter' ), 150, 3 );
		add_action( 'in_admin_header', array( __CLASS__, 'set_upgrade_notice' ) );

		add_action( 'admin_notices', array( __CLASS__, 'product_registered' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'product_registered' ) );

		add_action( 'admin_post_vd_refresh_license_status', array( __CLASS__, 'refresh_license_status' ) );

		add_action( 'vd_admin_notices', array( __CLASS__, 'print_notice' ) );
	}

	public static function check_notice_hide() {
		if ( isset( $_GET['notice'] ) && 'vd-hide-notice' === $_GET['notice'] && check_admin_referer( 'vd-hide-notice', 'nonce' ) ) {
			delete_option( 'vendidero_notice_expire' );
			remove_action( 'admin_notices', array( __CLASS__, 'expire_notice' ), 0 );
		}
	}

	protected static function maybe_output_expire_notice( $blog_id = null ) {
		// Check whether license has been renewed already
		$products     = $blog_id ? get_blog_option( $blog_id, 'vendidero_notice_expire' ) : get_option( 'vendidero_notice_expire' );
		$new_products = array();

		foreach ( $products as $key => $val ) {
			if ( $product = Package::get_product( $key ) ) {
				if ( $product->supports_renewals() ) {
					if ( $expire = $product->get_expiration_date( false ) ) {
						$diff = Package::get_date_diff( date( 'Y-m-d' ), $expire ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

						if ( ( strtotime( $expire ) <= time() ) || ( empty( $diff['y'] ) && empty( $diff['m'] ) && $diff['d'] <= 7 ) ) {
							$new_products[ $key ] = true;
						}
					}
				}
			}
		}

		if ( ! empty( $new_products ) ) {
			if ( $blog_id ) {
				update_blog_option( $blog_id, 'vendidero_notice_expire', $new_products );
			} else {
				update_option( 'vendidero_notice_expire', $new_products );
			}

			include_once Package::get_path() . '/includes/screens/screen-notice-expire.php';
		} else {
			if ( $blog_id ) {
				delete_blog_option( $blog_id, 'vendidero_notice_expire' );
			} else {
				delete_option( 'vendidero_notice_expire' );
			}
		}
	}

	public static function expire_notice() {
		if ( is_multisite() && is_network_admin() ) {
			$screen = get_current_screen();

			if ( in_array( $screen->id, self::get_notice_excluded_screens(), true ) ) {
				return;
			}

			foreach ( Package::get_products( false ) as $product ) {
				foreach ( $product->get_blog_ids() as $blog_id ) {
					if ( get_blog_option( $blog_id, 'vendidero_notice_expire' ) ) {
						self::maybe_output_expire_notice( $blog_id );
					}
				}
			}
		} elseif ( get_option( 'vendidero_notice_expire' ) ) {
			$screen = get_current_screen();

			if ( in_array( $screen->id, self::get_notice_excluded_screens(), true ) ) {
				return;
			}

			self::maybe_output_expire_notice();
		}
	}

	public static function refresh_license_status() {
		if ( isset( $_GET['_wpnonce'], $_GET['product_id'] ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				if ( wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'vd-refresh-license-status' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$product_id = absint( $_GET['product_id'] );

					if ( $product = Package::get_product_by_id( $product_id ) ) {
						$product->refresh_expiration_date( true );
					}

					wp_safe_redirect( esc_url_raw( Package::get_helper_url() ) );
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

			if ( ! empty( $transient ) && isset( $transient->response ) ) {
				foreach ( $transient->response as $plugin => $data ) {
					if ( isset( $products[ $plugin ] ) ) {
						$product = $products[ $plugin ];
						$product->refresh_expiration_date();

						if ( $product->supports_renewals() && $product->has_expired() ) {
							echo '<div class="vd-upgrade-notice" data-for="' . esc_attr( md5( $product->file ) ) . '" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( esc_html__( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vendidero-helper' ), '<a href="' . esc_url( Package::get_helper_url( $product->get_expired_blog_id() ) ) . '">' . esc_html__( 'check', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
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
							echo '<div class="vd-upgrade-notice" data-for="' . esc_attr( md5( $product->file ) ) . '" style="display: none"><span class="vd-inline-upgrade-expire-notice">' . sprintf( esc_html__( 'Seems like your update- and support flat has expired. Please %s your license before updating.', 'vendidero-helper' ), '<a href="' . esc_url( Package::get_helper_url( $product->get_expired_blog_id() ) ) . '">' . esc_html__( 'check', 'vendidero-helper' ) . '</a>' ) . '</span></div>';
						}
					}
				}
			}
		}
	}

	public static function add_menu() {
		$hook = add_dashboard_page( 'vendidero', 'vendidero', 'manage_options', 'vendidero', array( __CLASS__, 'screen' ) );

		add_action( 'load-' . $hook, array( __CLASS__, 'process' ) );
		add_action( 'load-' . $hook, array( __CLASS__, 'license_refresh' ) );
	}

	public static function license_refresh() {
		$products = Package::get_products( false );

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
				self::add_notice( $errors, 'error' );
			}
		}
	}

	public static function get_notice_excluded_screens() {
		return array( 'index_page_vendidero-network', 'dashboard_page_vendidero', 'update-core-network', 'update-core' );
	}

	public static function product_registered() {
		$screen = get_current_screen();

		if ( in_array( $screen->id, self::get_notice_excluded_screens(), true ) ) {
			return;
		}

		$admin_url = Package::get_helper_url();

		foreach ( Package::get_products( false ) as $product ) {
			if ( is_multisite() && is_network_admin() ) {
				foreach ( $product->get_blog_ids() as $blog_id ) {
					$admin_url = Package::get_helper_url( $blog_id );
					$blog_info = get_blog_details( $blog_id );

					if ( ! $product->is_registered( $blog_id ) ) {
						?>
						<div class="error">
							<p><?php printf( esc_html__( 'Your %1$s license for %2$s doesn\'t seem to be registered. Please %3$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $blog_info->blogname ), '<a style="margin-left: 5px;" class="button button-secondary" href="' . esc_url( $admin_url ) . '">' . esc_html__( 'manage your licenses', 'vendidero-helper' ) . '</a>' ); ?></p>
						</div>
						<?php
					} elseif ( $product->supports_renewals() && $product->has_expired( $blog_id ) ) {
						?>
						<div class="error">
							<p><?php printf( esc_html__( 'Your %1$s license for %2$s has expired on %3$s. %4$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $blog_info->blogname ), esc_html( $product->get_expiration_date( get_option( 'date_format' ), $blog_id ) ), '<a style="margin-left: 5px;" class="button button-primary wc-gzd-button" target="_blank" href="' . esc_url( $product->get_renewal_url( $blog_id ) ) . '">' . esc_html__( 'renew now', 'vendidero-helper' ) . '</a>' ); ?></p>
						</div>
						<?php
					}
				}
			} else {
				if ( ! $product->is_registered() ) {
					?>
					<div class="error">
						<p><?php printf( esc_html__( 'Your %1$s license doesn\'t seem to be registered. Please %2$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', '<a style="margin-left: 5px;" class="button button-secondary" href="' . esc_url( $admin_url ) . '">' . esc_html__( 'manage your licenses', 'vendidero-helper' ) . '</a>' ); ?></p>
					</div>
				<?php } elseif ( $product->has_expired() && $product->supports_renewals() ) { ?>
					<div class="error">
						<p><?php printf( esc_html__( 'Your %1$s license has expired on %2$s. %3$s %4$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $product->get_expiration_date( get_option( 'date_format' ) ) ), '<a style="margin-left: 5px;" class="button button-primary wc-gzd-button" target="_blank" href="' . esc_url( $product->get_renewal_url() ) . '">' . esc_html__( 'renew now', 'vendidero-helper' ) . '</a>', '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vd_refresh_license_status&product_id=' . esc_attr( $product->id ) ), 'vd-refresh-license-status' ) ) . '" class="" style="margin-left: 1em;">' . esc_html__( 'Already renewed?', 'vendidero-helper' ) . '</a>' ); ?></p>
					</div>
					<?php
				}
			}
		}
	}

	public static function screen() {
		?>
		<div class="vd-wrapper">
			<div class="wrap about-wrap vendidero-wrap">
				<div class="col-wrap">
					<h1><?php esc_html_e( 'Welcome to vendidero', 'vendidero-helper' ); ?></h1>
					<div class="about-text vendidero-updater-about-text">
						<?php esc_html_e( 'Easily manage your licenses for vendidero Products and enjoy automatic updates & more.', 'vendidero-helper' ); ?>
					</div>
				</div>
			</div>

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

	public static function process() {
		if ( ! isset( $_GET['_wpnonce'] ) && ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		$action = self::get_action( array( 'vd_register', 'vd_unregister' ) );

		if ( current_user_can( 'manage_options' ) ) {
			if ( $action && wp_verify_nonce( ( isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : wp_unslash( $_POST['_wpnonce'] ) ), 'bulk_licenses' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				do_action( 'vd_process_' . $action );
			}
		}
	}

	public static function process_register() {
		$errors   = array();
		$products = Package::get_products();

		if ( isset( $_POST['license_keys'] ) && 0 < count( $_POST['license_keys'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['license_keys'] ) as $file => $key ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
				$key  = sanitize_text_field( $key );
				$file = sanitize_text_field( $file );

				if ( empty( $key ) || $products[ $file ]->is_registered() ) {
					continue;
				} else {
					$response = Package::get_api()->register( $products[ $file ], $key );

					if ( is_wp_error( $response ) ) {
						array_push( $errors, $response->get_error_message( $response->get_error_code() ) );
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			self::add_notice( $errors, 'error' );
		}

		Package::get_api()->flush_cache();

		wp_safe_redirect( esc_url_raw( Package::get_helper_url() ) );
		exit();
	}

	public static function process_unregister() {
		$errors   = array();
		$products = Package::get_products();
		$file     = isset( $_GET['filepath'] ) ? sanitize_text_field( wp_unslash( $_GET['filepath'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $products[ $file ] ) ) {
			if ( ! Package::get_api()->unregister( $products[ $file ] ) ) {
				array_push( $errors, sprintf( __( 'Sorry, there was an error while unregistering %s', 'vendidero-helper' ), $products[ $file ]->Name ) );
			}
		}

		if ( ! empty( $errors ) ) {
			self::add_notice( $errors, 'error' );
		}

		Package::get_api()->flush_cache();

		wp_safe_redirect( esc_url_raw( Package::get_helper_url() ) );
		exit();
	}

	public static function add_notice( $msg = array(), $type = 'error' ) {
		set_transient(
			'vendidero_helper_notices',
			array(
				'msg'  => $msg,
				'type' => $type,
			),
			MINUTE_IN_SECONDS * 10
		);
	}

	public static function get_notices() {
		return get_transient( 'vendidero_helper_notices' );
	}

	public static function clean_notices() {
		return delete_transient( 'vendidero_helper_notices' );
	}

	public static function print_notice() {
		if ( $notices = self::get_notices() ) {
			echo '<div class="inline ' . esc_attr( $notices['type'] ) . '"><p>';
			echo wp_kses_post( implode( '<br/>', $notices['msg'] ) );
			echo '</p></div>';
			self::clean_notices();
		}
	}

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
		);

		if ( in_array( $screen_id, $screens, true ) ) {
			wp_register_style( 'vp_admin', Package::get_url() . '/assets/css/vd-admin.css', array(), Package::get_version() );
			wp_enqueue_style( 'vp_admin' );

			wp_register_script( 'vd_admin_js', Package::get_url() . '/assets/js/vd-admin.js', array( 'jquery' ), Package::get_version(), true );
			wp_enqueue_script( 'vd_admin_js' );
		}
	}
}
