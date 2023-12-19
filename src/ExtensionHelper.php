<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

class ExtensionHelper {

	private static $active_plugins = null;

	private static $plugins = null;

	public static function get_active_theme() {
		$theme = get_option( 'stylesheet' );
		$theme = strpos( $theme, 'style.css' ) === false ? $theme . '/style.css' : $theme;

		return $theme;
	}

	public static function is_theme_active( $theme ) {
		$theme = strpos( $theme, 'style.css' ) === false ? $theme . '/style.css' : $theme;

		if ( self::get_active_theme() === $theme ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the path to the plugin file relative to the plugins directory from the plugin slug.
	 *
	 * E.g. 'woocommerce' returns 'woocommerce/woocommerce.php'
	 *
	 * @param string $slug Plugin slug to get path for.
	 *
	 * @return string|false
	 */
	public static function get_plugin_path_from_slug( $slug ) {
		if ( strstr( $slug, '/' ) ) {
			$slug = self::get_plugin_slug( $slug );
		}

		$res = preg_grep( self::get_plugin_search_regex( $slug ), array_keys( self::get_plugins() ) );

		return false !== $res && count( $res ) > 0 ? array_values( $res )[0] : false;
	}

	protected static function get_plugin_slug( $path ) {
		$path_parts = explode( '/', $path );

		return $path_parts[0];
	}

	/**
	 * Get an array of installed plugin slugs.
	 *
	 * @return array
	 */
	public static function get_installed_plugin_slugs() {
		return array_map( array( __CLASS__, 'get_plugin_slug' ), array_keys( self::get_plugins() ) );
	}

	/**
	 * Get an array of installed plugins with their file paths as a key value pair.
	 *
	 * @return array
	 */
	public static function get_installed_plugins_paths() {
		$plugins           = self::get_plugins();
		$installed_plugins = array();

		foreach ( $plugins as $path => $plugin ) {
			$installed_plugins[ self::get_plugin_slug( $path ) ] = $path;
		}

		return $installed_plugins;
	}

	/**
	 * Get an array of active plugin slugs.
	 *
	 * @return array
	 */
	public static function get_active_plugin_slugs() {
		return array_map( array( __CLASS__, 'get_plugin_slug' ), self::get_active_plugins() );
	}

	/**
	 * Use a regex to find the actual plugin. This regex ignores
	 * plugin path suffixes, e.g. is able to detect plugin paths like woocommerce-2/woocommerce.php
	 *
	 * @param string $slug May either be a slug-only, e.g. woocommerce or a path like woocommerce-multilingual/wpml-woocommerce.php
	 *
	 * @return string
	 */
	private static function get_plugin_search_regex( $slug ) {
		$path_part = $slug;
		$slug_part = $slug;

		if ( strstr( $slug, '/' ) ) {
			$parts = explode( '/', $slug );

			if ( ! empty( $parts ) && 2 === count( $parts ) ) {
				$path_part = $parts[0];
				$slug_part = preg_replace( '/\.\w+$/', '', $parts[1] ); // remove .php
			} else {
				$slug = self::get_plugin_slug( $slug );

				$path_part = $slug;
				$slug_part = $slug;
			}
		}

		return '/^' . $path_part . '.*\/' . $slug_part . '.php$/';
	}

	/**
	 * Checks if a plugin is installed.
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory or the plugin directory name.
	 *
	 * @return bool
	 */
	public static function is_plugin_installed( $plugin ) {
		$res = preg_grep( self::get_plugin_search_regex( $plugin ), array_keys( self::get_plugins() ) );

		return false !== $res && count( $res ) > 0 ? true : false;
	}

	protected static function get_plugins() {
		if ( is_null( self::$plugins ) ) {
			self::$plugins = get_plugins();
		}

		return self::$plugins;
	}

	protected static function get_active_plugins() {
		if ( is_null( self::$active_plugins ) ) {
			$active_plugins = get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$active_plugins = array_merge( $active_plugins, array_keys( get_site_option( 'active_sitewide_plugins', array() ) ) );
			}

			self::$active_plugins = $active_plugins;
		}

		return self::$active_plugins;
	}

	/**
	 * Checks if a plugin is active.
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory or the plugin directory name.
	 *
	 * @return bool
	 */
	public static function is_plugin_active( $plugin ) {
		$res = preg_grep( self::get_plugin_search_regex( $plugin ), self::get_active_plugins() );

		return false !== $res && count( $res ) > 0 ? true : false;
	}

	/**
	 * Get plugin data.
	 *
	 * @param string $plugin Path to the plugin file relative to the plugins directory or the plugin directory name.
	 *
	 * @return array|false
	 */
	public static function get_plugin_data( $plugin ) {
		$plugin_path = self::get_plugin_path_from_slug( $plugin );
		$plugins     = self::get_plugins();

		return isset( $plugins[ $plugin_path ] ) ? $plugins[ $plugin_path ] : false;
	}

	/**
	 * @param $version
	 *
	 * @return string
	 */
	protected static function parse_version( $version ) {
		$version = preg_replace( '#(\.0+)+($|-)#', '', $version );

		// Remove/ignore beta, alpha, rc status from version strings
		$version = trim( preg_replace( '#(beta|alpha|rc)#', ' ', $version ) );

		// Make sure version has at least 2 signs, e.g. 3 -> 3.0
		if ( strlen( $version ) === 1 ) {
			$version = $version . '.0';
		}

		return $version;
	}

	public static function get_major_version( $version ) {
		$expl_ver = explode( '.', $version );

		return implode( '.', array_slice( $expl_ver, 0, 2 ) );
	}

	/**
	 * This method removes additional accuracy from $ver2 if this version is more accurate than $main_ver.
	 *
	 * @param $main_ver
	 * @param $ver2
	 * @param $operator
	 *
	 * @return bool
	 */
	public static function compare_versions( $main_ver, $ver2, $operator ) {
		$expl_main_ver = explode( '.', $main_ver );
		$expl_ver2     = explode( '.', $ver2 );

		// Check if ver2 string is more accurate than main_ver
		if ( 2 === count( $expl_main_ver ) && count( $expl_ver2 ) > 2 ) {
			$new_ver_2 = array_slice( $expl_ver2, 0, 2 );
			$ver2      = implode( '.', $new_ver_2 );
		}

		return version_compare( $main_ver, $ver2, $operator );
	}

	public static function get_plugin_version( $plugin ) {
		$data = self::get_plugin_data( $plugin );

		return ( $data && isset( $data['Version'] ) ) ? self::parse_version( $data['Version'] ) : false;
	}

	public static function clear_cache() {
		self::$plugins        = null;
		self::$active_plugins = null;
	}
}
