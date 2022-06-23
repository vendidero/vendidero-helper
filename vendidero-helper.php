<?php
/*
 * Plugin Name: vendidero Helper
 * Plugin URI: https://github.com/vendidero/vendidero-helper
 * Description: Manage your vendidero licenses and enjoy automatic updates.
 * Version: 2.1.6
 * Author: vendidero
 * Author URI: https://vendidero.de
 * Requires at least: 3.8
 * Tested up to: 6.0
 * Network: True
 *
 * Text Domain: vendidero-helper
 * Domain Path: /i18n/
 * Update URI: false
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

final class Vendidero_Helper {

	/**
	 * Single instance of Vendidero Main Class
	 *
	 * @var object
	 */
	protected static $_instance = null;

	public $version = '2.1.6';

	/**
	 * @var VD_API $api
	 */
	public $api     = null;
	public $plugins = array();
	public $themes  = array();

	/**
	 * @var null|VD_Admin
	 */
	private $admin            = null;
	private $debug_mode       = false;
	private $token            = 'vendidero-api';
	private $api_url          = 'https://vendidero.de/wp-json/vd/v1/';
	private $download_api_url = 'https://download.vendidero.de/api/v1/';
	/**
	 * @var VD_Product[]
	 */
	private $products = array();

	/**
	 * Main Vendidero Instance
	 *
	 * Ensures that only one instance of Vendidero is loaded or can be loaded.
	 *
	 * @static
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		// Auto-load classes on demand
		if ( function_exists( '__autoload' ) ) {
			spl_autoload_register( '__autoload' );
		}

		spl_autoload_register( array( $this, 'autoload' ) );

		add_filter( 'cron_schedules', array( $this, 'set_weekly_schedule' ) );

		register_activation_hook( __FILE__, array( $this, 'install' ) );

		// Hooks
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );

		if ( is_admin() ) {
			$this->init();
		}

		add_action( 'vendidero_cron', array( $this, 'expire_cron' ), 0 );
		add_action( 'deactivated_plugin', array( $this, 'plugin_action' ) );
		add_action( 'activated_plugin', array( $this, 'plugin_action' ) );
		add_action( 'http_request_args', array( $this, 'ssl_verify' ), 10, 2 );

		/**
		 * Make sure that API is setup during auto-updates too
		 */
		add_action( 'wp_maybe_auto_update', array( $this, 'maybe_load' ), 1 );

		add_action( 'delete_site_transient_update_plugins', array( $this, 'flush_cache' ) );
		add_action( 'delete_site_transient_update_themes', array( $this, 'flush_cache' ) );
		add_action( 'automatic_updates_complete', array( $this, 'flush_cache' ) );
	}

	public function flush_cache() {
		$this->maybe_load();
		$this->api->flush_cache();
	}

	public function ssl_verify( $args, $url ) {
		if ( is_admin() ) {
			if ( apply_filters( 'vd_helper_disable_ssl_verify', false ) && (string) VD()->get_api_url() === (string) $url ) {
				$args['sslverify'] = false;
			}
		}

		return $args;
	}

	public function get_helper_url() {
		return is_multisite() ? network_admin_url( 'index.php?page=vendidero' ) : admin_url( 'index.php?page=vendidero' );
	}

	public function get_admin_url() {
		return $this->get_helper_url();
	}

	public function plugin_action( $filename ) {
		foreach ( $this->get_products() as $product ) {
			if ( $product->file === $filename ) {
				$product->flush_api_cache();
				break;
			}
		}
	}

	public function set_weekly_schedule( $schedules ) {
		$schedules['weekly'] = array(
			'interval' => 604800,
			'display'  => __( 'Once per week', 'vendidero-helper' ),
		);

		return $schedules;
	}

	public function maybe_init() {
		if ( ! did_action( 'vendidero_helper_init' ) ) {
			$this->init();
		}
	}

	public function init() {
		$this->debug_mode = defined( 'VD_DEBUG' ) ? VD_DEBUG : false;

		// Hook
		$this->api = new VD_API();
		$this->includes();

		add_action( 'admin_init', array( $this, 'load' ), 0 );
		add_action( 'admin_init', array( $this, 'check_notice_hide' ) );
		add_action( 'admin_notices', array( $this, 'expire_notice' ), 0 );

		// Support signed releases
		add_filter( 'wp_trusted_keys', array( $this, 'add_signature_trusted_keys' ) );
		add_filter( 'wp_signature_hosts', array( $this, 'add_signature_hosts' ) );
		add_filter( 'wp_signature_url', array( $this, 'adjust_signature_url' ), 10, 2 );

		// Allow local url for testing purposes
		if ( $this->debug_mode ) {
			add_filter( 'http_request_host_is_external', array( $this, 'allow_local_urls' ) );
			add_filter( 'http_request_args', array( $this, 'disable_ssl_verify' ), 10, 1 );
		}

		add_action( 'upgrader_pre_download', array( $this, 'block_expired_updates' ), 50, 2 );

		do_action( 'vendidero_helper_init' );
	}

	/**
	 * Hooked into the upgrader_pre_download filter in order to better handle error messaging around expired
	 * plugin updates. Initially we were using an empty string, but the error message that no_package
	 * results in does not fit the cause.
	 *
	 * @since 2.0.0
	 * @param bool   $reply Holds the current filtered response.
	 * @param string $package The path to the package file for the update.
	 * @return false|WP_Error False to proceed with the update as normal, anything else to be returned instead of updating.
	 */
	public function block_expired_updates( $reply, $package ) {
		// Don't override a reply that was set already.
		if ( false !== $reply ) {
			return $reply;
		}

		// Only for packages with expired subscriptions.
		if ( 0 !== strpos( $package, 'vendidero-expired-' ) ) {
			return $reply;
		}

		$product_id = absint( str_replace( 'vendidero-expired-', '', $package ) );

		if ( $product = $this->get_product_by_id( $product_id ) ) {
			return new WP_Error(
				'vendidero_expired',
				sprintf(
					// translators: %s: Renewal url.
					__( 'Your update- and support-flat has expired. Please <a href="%s" target="_blank">renew</a> your license before updating.', 'vendidero-helper' ),
					esc_url( $product->get_renewal_url() )
				)
			);
		} else {
			return new WP_Error(
				'vendidero_expired',
				__( 'Your update- and support-flat has expired. Please renew your license before updating.', 'vendidero-helper' )
			);
		}
	}

	public function adjust_signature_url( $signature_url, $url ) {
		if ( strpos( $url, $this->download_api_url ) !== false ) {
			$signature_url = str_replace( 'latest/download', 'latest/downloadSignature', $url );
		}

		return $signature_url;
	}

	public function add_signature_trusted_keys( $keys ) {
		$keys[] = '5AJRLVJJyHHrr9FSgJIBDcKyOu2TCLY5kDO2kVhGAnU=';

		return $keys;
	}

	public function add_signature_hosts( $hosts ) {
		$url     = @wp_parse_url( $this->download_api_url ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$hosts[] = $url['host'];

		return $hosts;
	}

	public function disable_ssl_verify( $args ) {
		$args['sslverify'] = false;
		return $args;
	}

	public function allow_local_urls() {
		return true;
	}

	public function maybe_load() {
		if ( ! did_action( 'vendidero_helper_loaded' ) ) {
			$this->load();
		}
	}

	public function load() {
		$this->maybe_init();

		// If multisite, plugin must be network activated. First make sure the is_plugin_active_for_network function exists
		if ( is_multisite() && ! is_network_admin() ) {
			remove_action( 'admin_notices', 'vendidero_helper_notice' );

			if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
				require_once ABSPATH . '/wp-admin/includes/plugin.php';
			}

			if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
				add_action( 'admin_notices', array( $this, 'admin_notice_require_network_activation' ) );
				return;
			}
		}

		$this->set_data();
		$this->register_products();
		$this->update_products();

		do_action( 'vendidero_helper_loaded' );
	}

	public function admin_notice_require_network_activation() {
		echo '<div class="error"><p>' . esc_html__( 'vendidero Helper must be network activated when in multisite environment.', 'vendidero-helper' ) . '</p></div>';
	}

	public function expire_cron() {
		$this->maybe_load();

		if ( ! empty( $this->products ) ) {
			$notice = get_option( 'vendidero_notice_expire', array() );

			foreach ( $this->products as $key => $product ) {
				if ( ! $product->is_registered() ) {
					unset( $notice[ $key ] );
					continue;
				}

				if ( $product->supports_renewals() ) {
					// Refresh expiration date
					$product->refresh_expiration_date( true );

					if ( $expire = $product->get_expiration_date( false ) ) {
						$diff = VD()->get_date_diff( date( 'Y-m-d' ), $expire ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

						if ( ( strtotime( $expire ) <= time() ) || ( empty( $diff['y'] ) && empty( $diff['m'] ) && $diff['d'] <= 7 ) ) {
							$notice[ $key ] = true;

							delete_transient( "_vendidero_helper_updates_{$product->id}" );
							delete_transient( "_vendidero_helper_update_info_{$product->id}" );
						} elseif ( strtotime( $expire ) > time() ) {
							unset( $notice[ $key ] );
						}
					}
				} else {
					unset( $notice[ $key ] );
				}
			}

			if ( empty( $notice ) ) {
				delete_option( 'vendidero_notice_expire' );
			} else {
				update_option( 'vendidero_notice_expire', $notice );
			}
		}
	}

	public function check_notice_hide() {
		if ( isset( $_GET['notice'] ) && 'vd-hide-notice' === $_GET['notice'] && check_admin_referer( 'vd-hide-notice', 'nonce' ) ) {
			delete_option( 'vendidero_notice_expire' );
			remove_action( 'admin_notices', array( $this, 'expire_notice' ), 0 );
		}
	}

	public function expire_notice() {
		if ( get_option( 'vendidero_notice_expire' ) ) {
			$screen = get_current_screen();

			if ( $this->admin && in_array( $screen->id, $this->admin->get_notice_excluded_screens(), true ) ) {
				return;
			}

			// Check whether license has been renewed already
			$products     = get_option( 'vendidero_notice_expire' );
			$new_products = array();

			foreach ( $products as $key => $val ) {
				if ( isset( VD()->products[ $key ] ) ) {
					$product = VD()->products[ $key ];

					if ( $product->supports_renewals() ) {
						if ( $expire = $product->get_expiration_date( false ) ) {
							$diff = VD()->get_date_diff( date( 'Y-m-d' ), $expire ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

							if ( ( strtotime( $expire ) <= time() ) || ( empty( $diff['y'] ) && empty( $diff['m'] ) && $diff['d'] <= 7 ) ) {
								$new_products[ $key ] = true;
							}
						}
					}
				}
			}

			if ( ! empty( $new_products ) ) {
				update_option( 'vendidero_notice_expire', $new_products );
				include_once 'screens/screen-notice-expire.php';
			} else {
				delete_option( 'vendidero_notice_expire' );
			}
		}
	}

	public function set_data() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( $plugins as $plugin_file => $plugin_data ) {

			// Make sure plugin info is translated
			if ( function_exists( '_get_plugin_data_markup_translate' ) ) {
				$plugin_data = _get_plugin_data_markup_translate( $plugin_file, (array) $plugin_data, false, true );
			}

			$this->plugins[ $plugin_file ] = $plugin_data;
		}

		$themes = wp_get_themes();

		if ( ! empty( $themes ) ) {
			foreach ( $themes as $theme ) {
				$this->themes[ basename( $theme->__get( 'stylesheet_dir' ) ) . '/style.css' ] = $theme;
			}
		}
	}

	public function install() {
		if ( '' !== $this->version ) {
			update_option( 'vendidero_version', $this->version );
		}

		wp_clear_scheduled_hook( 'vendidero_cron' );
		wp_schedule_event( time(), 'daily', 'vendidero_cron' );
	}

	/**
	 * Load Localisation files for WooCommerce Germanized.
	 */
	public function load_plugin_textdomain() {
		$domain = 'vendidero-helper';
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		load_textdomain( $domain, trailingslashit( WP_LANG_DIR ) . $domain . '/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, false, basename( dirname( __FILE__ ) ) . '/i18n/' );
	}

	/**
	 * Auto-load
	 *
	 * @param mixed   $class
	 * @return void
	 */
	public function autoload( $class ) {
		$path  = $this->plugin_path() . '/includes/';
		$class = strtolower( $class );

		$file = 'class-' . str_replace( '_', '-', $class ) . '.php';

		if ( $path && is_readable( $path . $file ) ) {
			include_once $path . $file;
			return;
		}
	}

	public function sanitize_domain( $domain ) {
		$domain = esc_url_raw( $domain );
		$parsed = @wp_parse_url( $domain ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( empty( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}

		// Remove www. prefix
		$parsed['host'] = str_replace( 'www.', '', $parsed['host'] );
		$domain         = $parsed['host'];

		return $domain;
	}

	public function get_available_plugins() {
		return array(
			'woocommerce-germanized-pro/woocommerce-germanized-pro.php' => array(
				'product_id' => 148,
			),
		);
	}

	public function get_available_themes() {
		return array(
			'vendipro/style.css' => array(
				'product_id'        => 48,
				'supports_renewals' => false,
			),
		);
	}

	public function includes() {
		$this->admin = include_once $this->plugin_path() . '/includes/class-vd-admin.php';
	}

	public function register_products() {
		$product_data      = apply_filters( 'vendidero_updateable_products', array() );
		$products          = array();
		$available_plugins = $this->get_available_plugins();
		$available_themes  = $this->get_available_themes();

		foreach ( $product_data as $product ) {
			$products[ $product->file ] = $product;
		}

		if ( is_multisite() ) {
			foreach ( get_sites() as $site ) {
				$plugins = get_blog_option( $site->blog_id, 'active_plugins' );
				$theme   = get_blog_option( $site->blog_id, 'stylesheet' );

				if ( ! empty( $plugins ) ) {
					foreach ( $available_plugins as $file => $args ) {
						$args = wp_parse_args(
							$args,
							array(
								'product_id'        => 0,
								'supports_renewals' => true,
							)
						);

						$product_id = $args['product_id'];

						if ( in_array( $file, $plugins, true ) ) {
							if ( array_key_exists( $file, $products ) ) {

								if ( ! isset( $products[ $file ]->blog_ids ) ) {
									$products[ $file ]->blog_ids = array();
								}

								$products[ $file ]->blog_ids[] = $site->blog_id;
							} else {
								$plugin                    = new stdClass();
								$plugin->file              = $file;
								$plugin->product_id        = $product_id;
								$plugin->supports_renewals = $args['supports_renewals'];
								$plugin->blog_ids          = array( $site->blog_id );

								$products[ $plugin->file ] = $plugin;
							}
						}
					}
				}

				if ( $theme ) {
					$theme = strpos( $theme, 'style.css' ) === false ? $theme . '/style.css' : $theme;

					foreach ( $available_themes as $file => $args ) {
						$args = wp_parse_args(
							$args,
							array(
								'product_id'        => 0,
								'supports_renewals' => true,
							)
						);

						$product_id = $args['product_id'];

						if ( $theme === $file ) {
							if ( array_key_exists( $file, $products ) ) {

								if ( ! isset( $products[ $file ]->blog_ids ) ) {
									$products[ $file ]->blog_ids = array();
								}

								$products[ $file ]->blog_ids[] = $site->blog_id;
							} else {
								$plugin                    = new stdClass();
								$plugin->file              = $file;
								$plugin->product_id        = $product_id;
								$plugin->supports_renewals = $args['supports_renewals'];
								$plugin->blog_ids          = array( $site->blog_id );

								$products[ $plugin->file ] = $plugin;
							}
						}
					}
				}
			}
		}

		if ( ! empty( $products ) && is_array( $products ) ) {
			foreach ( $products as $product ) {
				if ( is_object( $product ) && ! empty( $product->file ) && ! empty( $product->product_id ) ) {
					$this->add_product(
						$product->file,
						$product->product_id,
						array(
							'blog_ids'          => isset( $product->blog_ids ) ? $product->blog_ids : array(),
							'supports_renewals' => isset( $product->supports_renewals ) ? $product->supports_renewals : true,
						)
					);
				}
			}
		}

		// Self update
		$this->add_product( 'vendidero-helper/vendidero-helper.php', 2198, array( 'free' => true ) );
	}

	public function update_products() {
		if ( ! empty( $this->products ) ) {
			foreach ( $this->products as $key => $product ) {
				if ( $product->is_registered() ) {
					$product->updater = new VD_Updater( $product );
				}
			}
		}
	}

	public function add_product( $file, $product_id, $args = array() ) {
		$product_id = absint( $product_id );

		$args = wp_parse_args(
			$args,
			array(
				'free'              => false,
				'blog_ids'          => array(),
				'supports_renewals' => true,
			)
		);

		if ( '' !== $file && ! isset( $this->products[ $file ] ) ) {
			$is_theme = ( strpos( $file, 'style.css' ) === false ? false : true );

			// Check if is right file dir
			if ( $is_theme && ! isset( $this->themes[ $file ] ) ) {
				return false;
			} elseif ( ! $is_theme && ! isset( $this->plugins[ $file ] ) ) {
				return false;
			}

			/**
			 * Mark legacy VendiPro theme as non-renewable to prevent notices.
			 */
			if ( 48 === $product_id ) {
				$args['supports_renewals'] = false;
			}

			$this->products[ $file ] = ( $is_theme ? new VD_Product_Theme( $file, $product_id, $args ) : new VD_Product( $file, $product_id, $args ) );

			return true;
		}

		return false;
	}

	public function remove_product( $file ) {
		$response = false;

		if ( '' !== $file && in_array( $file, array_keys( $this->products ), true ) ) {
			unset( $this->products[ $file ] );
			$response = true;
		}

		return $response;
	}

	/**
	 * @param bool $show_free
	 *
	 * @return VD_Product[]
	 */
	public function get_products( $show_free = true ) {
		$products = $this->products;

		if ( ! $show_free ) {
			foreach ( $this->products as $key => $product ) {
				if ( $product->free ) {
					unset( $products[ $key ] );
				}
			}
		}

		return $products;
	}

	/**
	 * @param $key
	 *
	 * @return false|VD_Product
	 */
	public function get_product( $key ) {
		return ( isset( $this->products[ $key ] ) ? $this->products[ $key ] : false );
	}

	/**
	 * @param $id
	 *
	 * @return false|VD_Product
	 */
	public function get_product_by_id( $id ) {
		foreach ( $this->get_products() as $key => $product ) {
			if ( (int) $product->id === (int) $id ) {
				return $product;
			}
		}

		return false;
	}

	public function get_api_url() {
		return $this->api_url;
	}

	public function get_download_api_url() {
		return $this->download_api_url;
	}

	public function get_token() {
		return $this->token;
	}

	/**
	 * PHP 5.3 backwards compatibility for getting date diff
	 *
	 * @param  string $from date from
	 * @param  string $to   date to
	 * @return array  array containing year, month, date diff
	 */
	public function get_date_diff( $from, $to ) {
		$diff   = abs( strtotime( $to ) - strtotime( $from ) );
		$years  = floor( $diff / ( 365 * 60 * 60 * 24 ) );
		$months = floor( ( $diff - $years * 365 * 60 * 60 * 24 ) / ( 30 * 60 * 60 * 24 ) );
		$days   = floor( ( $diff - $years * 365 * 60 * 60 * 24 - $months * 30 * 60 * 60 * 24 ) / ( 60 * 60 * 24 ) );

		return array(
			'y' => $years,
			'm' => $months,
			'd' => $days,
		);
	}

	/**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Get the plugin path.
	 *
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

}

/**
 * @return Vendidero_Helper
 */
function VD() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return Vendidero_Helper::instance();
}

$GLOBALS['vendidero_helper'] = VD();

