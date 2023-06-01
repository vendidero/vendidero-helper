<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

class Install {

	public static function get_current_version() {
		return is_multisite() ? get_site_option( 'vendidero_version', null ) : get_option( 'vendidero_version', null );
	}

	public static function install() {
		self::update();

		wp_clear_scheduled_hook( 'vendidero_cron' );
		wp_schedule_event( time(), 'daily', 'vendidero_cron' );

		if ( is_multisite() ) {
			update_site_option( 'vendidero_version', Package::get_version() );
		} else {
			update_option( 'vendidero_version', Package::get_version() );
		}
	}

	public static function update() {
		$current_version = self::get_current_version();

		/**
		 * Was stored as blog option in older versions.
		 */
		if ( is_null( $current_version ) && is_multisite() ) {
			$current_version = get_option( 'vendidero_version', null );
		}

		if ( ! empty( $current_version ) && version_compare( $current_version, '2.2.0', '<' ) ) {
			/**
			 * Copy network-wide license data to each (active) site.
			 */
			if ( is_multisite() ) {
				$license_data = get_site_option( 'vendidero_registered', array() );

				if ( ! empty( $license_data ) ) {
					foreach ( get_sites(
						array(
							'public'   => 1,
							'spam'     => 0,
							'deleted'  => 0,
							'archived' => 0,
						)
					) as $site ) {
						$plugins           = array_merge( (array) get_blog_option( $site->blog_id, 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
						$theme             = get_blog_option( $site->blog_id, 'stylesheet' );
						$license_blog_data = array();

						foreach ( $license_data as $file => $data ) {
							if ( in_array( $file, $plugins, true ) || $theme === $file ) {
								$license_blog_data[ $file ] = $data;
							}
						}

						update_blog_option( $site->blog_id, 'vendidero_registered', $license_blog_data );
					}
				}

				if ( Package::is_integration() ) {
					delete_option( 'vendidero_version' );
				}
			} else {
				if ( Package::is_integration() ) {
					deactivate_plugins( 'vendidero-helper/vendidero-helper.php', true );
				}
			}
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'vendidero_cron' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vd_helper_daily', array(), 'vd_helper' );
		}
	}
}


