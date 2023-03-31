<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

class Theme extends Product {

	public function __construct( $file, $product_id, $args = array() ) {
		parent::__construct( $file, $product_id, $args );

		$this->theme = true;
	}

	protected function get_multisite_registered_data() {
		$theme_network_wide_registered = true;
		$registered                    = array();

		foreach ( get_sites(
			array(
				'public'   => 1,
				'spam'     => 0,
				'deleted'  => 0,
				'archived' => 0,
			)
		) as $site ) {
			$theme_active = false;
			$theme        = get_blog_option( $site->blog_id, 'template', '' );

			if ( $theme === $this->meta->template ) {
				$theme_active = true;
			}

			// Do only check license if plugin is activated
			if ( $theme_active ) {
				$site_registered = get_blog_option( $site->blog_id, 'vendidero_registered', array() );

				if ( ! isset( $site_registered[ $this->file ] ) ) {
					$theme_network_wide_registered = false;
				} else {
					$registered     = $site_registered;
					$this->home_url = get_home_url( $site->blog_id, '/' );
				}
			}
		}

		if ( ! $theme_network_wide_registered ) {
			$registered = array();
		}

		return $registered;
	}

	protected function get_meta_data() {
		if ( function_exists( 'wp_get_theme' ) ) {
			$this->meta = wp_get_theme( $this->file );
		}
	}

	public function __get( $key ) {
		$value = parent::__get( $key );

		if ( $this->meta->get( $key ) ) {
			$value = $this->meta->get( $key );
		}

		return $value;
	}

	public function __isset( $key ) {
		$is = parent::__isset( $key );

		if ( $this->meta->get( $key ) ) {
			$is = true;
		}

		return $is;
	}

	public function get_url() {
		return $this->ThemeURI;
	}
}
