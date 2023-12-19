<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Main package class.
 */
class Package {

	/**
	 * Version.
	 *
	 * @var string
	 */
	const VERSION = '2.2.2';

	/**
	 * @var null|Product[]
	 */
	private static $products = null;

	private static $is_integration = false;

	/**
	 * Init the package
	 */
	public static function init( $is_integration = false ) {
		self::$is_integration = $is_integration;

		include_once self::get_path() . '/includes/vd-core-functions.php';

		if ( ! self::is_integration() ) {
			add_action( 'init', array( __CLASS__, 'maybe_update' ), 10 );
			add_action( 'init', array( __CLASS__, 'load_plugin_textdomain' ) );
		}

		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_action' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_action' ) );
		add_action( 'http_request_args', array( __CLASS__, 'ssl_verify' ), 10, 2 );

		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'flush_cache' ) );
		add_action( 'delete_site_transient_update_themes', array( __CLASS__, 'flush_cache' ) );
		add_action( 'automatic_updates_complete', array( __CLASS__, 'flush_cache' ) );

		add_action( 'init', array( __CLASS__, 'setup_recurring_actions' ), 10 );
		add_action( 'vd_helper_daily', array( __CLASS__, 'expire_cron' ) );
		add_action( 'vendidero_cron', array( __CLASS__, 'maybe_run_fallback_cron' ), 0 );

		// Support signed releases
		add_filter( 'wp_trusted_keys', array( __CLASS__, 'add_signature_trusted_keys' ) );
		add_filter( 'wp_signature_hosts', array( __CLASS__, 'add_signature_hosts' ) );
		add_filter( 'wp_signature_url', array( __CLASS__, 'adjust_signature_url' ), 10, 2 );

		// Allow local url for testing purposes
		if ( self::is_debug_mode() ) {
			add_filter( 'http_request_host_is_external', array( __CLASS__, 'allow_local_urls' ) );
			add_filter( 'http_request_args', array( __CLASS__, 'disable_ssl_verify' ), 10, 1 );
		}

		add_action( 'upgrader_pre_download', array( __CLASS__, 'block_expired_updates' ), 50, 2 );

		add_filter( 'pre_set_site_transient_update_themes', array( __CLASS__, 'register_on_update' ), 0, 1 );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'register_on_update' ), 0, 1 );
		add_action( 'wp_maybe_auto_update', array( __CLASS__, 'register_on_update' ), 1 );

		if ( is_admin() ) {
			Admin::init();
		}
	}

	public static function is_debug_mode() {
		return defined( 'VD_DEBUG' ) ? VD_DEBUG : false;
	}

	public static function register_on_update( $value = false ) {
		self::register_products();

		return $value;
	}

	public static function flush_cache() {
		self::get_api()->flush_cache();
	}

	public static function on_plugin_action( $filename ) {
		foreach ( self::get_products() as $product ) {
			if ( $product->file === $filename ) {
				$product->flush_api_cache();
				break;
			}
		}
	}

	public static function get_api() {
		return Api::instance();
	}

	public static function maybe_run_fallback_cron() {
		if ( ! function_exists( 'as_next_scheduled_action' ) || false === as_next_scheduled_action( 'vd_helper_daily', array(), 'vd_helper' ) ) {
			self::expire_cron();
		}
	}

	public static function setup_recurring_actions() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( false === as_next_scheduled_action( 'vd_helper_daily', array(), 'vd_helper' ) ) {
			$timestamp = strtotime( 'tomorrow midnight' );
			$date      = new \DateTime();

			$date->setTimestamp( $timestamp );
			$date->modify( '+3 hours' );

			as_unschedule_all_actions( 'vd_helper_daily', array(), 'vd_helper' );
			as_schedule_recurring_action( $date->getTimestamp(), DAY_IN_SECONDS, 'vd_helper_daily', array(), 'vd_helper' );
		}
	}

	public static function expire_cron() {
		$notice = get_option( 'vendidero_notice_expire', array() );

		foreach ( self::get_products( false ) as $key => $product ) {
			if ( ! $product->is_registered() ) {
				unset( $notice[ $key ] );
				continue;
			}

			if ( $product->supports_renewals() ) {
				// Refresh expiration date
				$product->refresh_expiration_date( true );

				if ( $expire = $product->get_expiration_date( false ) ) {
					$diff = self::get_date_diff( date( 'Y-m-d' ), $expire ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

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

	/**
	 * Hooked into the upgrader_pre_download filter in order to better handle error messaging around expired
	 * plugin updates. Initially we were using an empty string, but the error message that no_package
	 * results in does not fit the cause.
	 *
	 * @since 2.0.0
	 * @param bool   $reply Holds the current filtered response.
	 * @param string $package The path to the package file for the update.
	 * @return false|\WP_Error False to proceed with the update as normal, anything else to be returned instead of updating.
	 */
	public static function block_expired_updates( $reply, $package ) {
		// Don't override a reply that was set already.
		if ( false !== $reply ) {
			return $reply;
		}

		// Only for packages with expired subscriptions.
		if ( 0 !== strpos( $package, 'vendidero-expired-' ) ) {
			return $reply;
		}

		$product_id = absint( str_replace( 'vendidero-expired-', '', $package ) );

		if ( $product = self::get_product_by_id( $product_id ) ) {
			return new \WP_Error(
				'vendidero_expired',
				sprintf(
				// translators: %s: Renewal url.
					_x( 'Your update- and support-flat has expired. Please <a href="%s" target="_blank">renew</a> your license before updating.', 'vd-helper', 'vendidero-helper' ),
					esc_url( $product->get_renewal_url() )
				)
			);
		} else {
			return new \WP_Error(
				'vendidero_expired',
				_x( 'Your update- and support-flat has expired. Please renew your license before updating.', 'vd-helper', 'vendidero-helper' )
			);
		}
	}

	public static function adjust_signature_url( $signature_url, $url ) {
		if ( strstr( $url, self::get_download_api_url() ) ) {
			$signature_url = str_replace( 'latest/download', 'latest/downloadSignature', $url );
		}

		return $signature_url;
	}

	public static function add_signature_trusted_keys( $keys ) {
		$keys[] = '5AJRLVJJyHHrr9FSgJIBDcKyOu2TCLY5kDO2kVhGAnU=';

		return $keys;
	}

	public static function add_signature_hosts( $hosts ) {
		$url     = @wp_parse_url( self::get_download_api_url() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$hosts[] = $url['host'];

		return $hosts;
	}

	public static function disable_ssl_verify( $args ) {
		$args['sslverify'] = false;
		return $args;
	}

	public static function allow_local_urls() {
		return true;
	}

	public static function sanitize_domain( $domain ) {
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

	public static function get_available_plugins() {
		return array();
	}

	public static function get_available_themes() {
		return array();
	}

	protected static function register_products() {
		$product_data = apply_filters( 'vendidero_updateable_products', array() );
		$products     = array();

		foreach ( $product_data as $product ) {
			$products[ $product->file ] = $product;
		}

		if ( is_multisite() && is_network_admin() ) {
			foreach ( get_sites(
				array(
					'public'   => 1,
					'spam'     => 0,
					'deleted'  => 0,
					'archived' => 0,
				)
			) as $site ) {
				switch_to_blog( $site->blog_id );
				ExtensionHelper::clear_cache();

				foreach ( $products as $file => $product ) {
					if ( ExtensionHelper::is_plugin_active( $file ) || ExtensionHelper::is_theme_active( $file ) ) {
						if ( ! isset( $products[ $file ]->blog_ids ) ) {
							$products[ $file ]->blog_ids = array();
						}

						$products[ $file ]->blog_ids[] = $site->blog_id;
					}
				}
				restore_current_blog();
			}
		}

		if ( ! empty( $products ) && is_array( $products ) ) {
			foreach ( $products as $product ) {
				if ( is_object( $product ) && ! empty( $product->file ) && ! empty( $product->product_id ) ) {
					self::add_product(
						$product->file,
						$product->product_id,
						array(
							'supports_renewals' => isset( $product->supports_renewals ) ? $product->supports_renewals : true,
							'blog_ids'          => isset( $product->blog_ids ) ? $product->blog_ids : array(),
						)
					);
				}
			}
		}

		/**
		 * In case this installation is not an integration, register
		 * the helper as a product to make sure it may be updated too.
		 */
		if ( ! self::is_integration() || ExtensionHelper::is_plugin_active( 'vendidero-helper' ) ) {
			self::add_product( 'vendidero-helper/vendidero-helper.php', 2198, array( 'free' => true ) );
		}
	}

	public static function add_product( $file, $product_id, $args = array() ) {
		if ( is_null( self::$products ) ) {
			self::$products = array();
		}

		$product_id = absint( $product_id );

		$args = wp_parse_args(
			$args,
			array(
				'free'              => false,
				'blog_ids'          => array(),
				'supports_renewals' => true,
			)
		);

		if ( '' !== $file && ! isset( self::$products[ $file ] ) ) {
			$is_theme = ( strpos( $file, 'style.css' ) === false ? false : true );

			/**
			 * Mark legacy VendiPro theme as non-renewable to prevent notices.
			 */
			if ( 48 === $product_id ) {
				$args['supports_renewals'] = false;
			}

			self::$products[ $file ] = ( $is_theme ? new Theme( $file, $product_id, $args ) : new Product( $file, $product_id, $args ) );

			return true;
		}

		return false;
	}

	public static function remove_product( $file ) {
		$products = self::get_products();
		$response = false;

		if ( '' !== $file && in_array( $file, array_keys( self::$products ), true ) ) {
			unset( self::$products[ $file ] );
			$response = true;
		}

		return $response;
	}

	/**
	 * @param bool $show_free
	 *
	 * @return Product[]
	 */
	public static function get_products( $show_free = true ) {
		if ( is_null( self::$products ) ) {
			self::register_products();
		}

		$products = self::$products;

		if ( ! $show_free ) {
			foreach ( self::$products as $key => $product ) {
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
	 * @return false|Product
	 */
	public static function get_product( $key ) {
		$products = self::get_products();

		return ( isset( $products[ $key ] ) ? $products[ $key ] : false );
	}

	/**
	 * @param $id
	 *
	 * @return false|Product
	 */
	public static function get_product_by_id( $id ) {
		foreach ( self::get_products() as $key => $product ) {
			if ( (int) $product->id === (int) $id ) {
				return $product;
			}
		}

		return false;
	}

	public static function ssl_verify( $args, $url ) {
		if ( is_admin() ) {
			if ( apply_filters( 'vd_helper_disable_ssl_verify', false ) && (string) self::get_api_url() === (string) $url ) {
				$args['sslverify'] = false;
			}
		}

		return $args;
	}

	public static function get_helper_url( $blog_id = null ) {
		if ( is_null( $blog_id ) ) {
			return admin_url( 'index.php?page=vendidero' );
		} else {
			return get_admin_url( $blog_id, 'index.php?page=vendidero' );
		}
	}

	public static function get_admin_url( $blog_id = null ) {
		return self::get_helper_url( $blog_id );
	}

	public static function get_api_url() {
		return 'https://vendidero.de/wp-json/vd/v1/';
	}

	public static function get_download_api_url() {
		return 'https://download.vendidero.de/api/v1/';
	}

	public static function get_token() {
		return 'vendidero-api';
	}

	/**
	 * PHP 5.3 backwards compatibility for getting date diff
	 *
	 * @param  string $from date from
	 * @param  string $to   date to
	 * @return array  array containing year, month, date diff
	 */
	public static function get_date_diff( $from, $to ) {
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

	public static function load_plugin_textdomain() {
		add_filter( 'plugin_locale', array( __CLASS__, 'support_german_language_variants' ), 10, 2 );

		$domain = 'vendidero-helper';

		if ( function_exists( 'determine_locale' ) ) {
			$locale = determine_locale();
		} else {
			// @todo Remove when start supporting WP 5.0 or later.
			$locale = is_admin() ? get_user_locale() : get_locale();
		}

		unload_textdomain( $domain );
		load_textdomain( $domain, trailingslashit( WP_LANG_DIR ) . $domain . '/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, false, plugin_basename( self::get_path() ) . '/i18n/' );
	}

	public static function support_german_language_variants( $locale, $domain ) {
		if ( 'vendidero-helper' === $domain && in_array( $locale, array( 'de_CH', 'de_AT' ), true ) ) {
			$locale = 'de_DE';
		}

		return $locale;
	}

	public static function maybe_update() {
		if ( ! defined( 'IFRAME_REQUEST' ) && Install::get_current_version() !== self::get_version() ) {
			Install::install();
		}
	}

	public static function install() {
		self::init();

		Install::install();
	}

	public static function deactivate() {
		Install::deactivate();
	}

	public static function install_integration() {
		self::init( true );

		Install::install();
	}

	public static function is_integration() {
		return self::$is_integration;
	}

	/**
	 * Return the version of the package.
	 *
	 * @return string
	 */
	public static function get_version() {
		return self::VERSION;
	}

	/**
	 * Return the path to the package.
	 *
	 * @return string
	 */
	public static function get_path() {
		return dirname( __DIR__ );
	}

	/**
	 * Return the path to the package.
	 *
	 * @return string
	 */
	public static function get_url() {
		return plugins_url( '', __DIR__ );
	}

	public static function get_assets_url() {
		return self::get_url() . '/assets';
	}

	private static function define_constant( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	public static function log( $message, $type = 'info' ) {
		$logger = wc_get_logger();

		if ( ! $logger || ! apply_filters( 'vd_helper_enable_logging', true ) ) {
			return;
		}

		if ( ! is_callable( array( $logger, $type ) ) ) {
			$type = 'info';
		}

		$logger->{$type}( $message, array( 'source' => 'vd-helper' ) );
	}
}
