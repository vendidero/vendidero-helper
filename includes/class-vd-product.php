<?php

class VD_Product {

	public $file;
	public $id;
	public $slug;
	public $free     = false;
	public $theme    = false;
	public $meta     = array();
	public $updater  = null;
	public $blog_ids = array();
	public $expires;
	public $home_url          = array();
	public $supports_renewals = true;
	private $key;

	public function __construct( $file, $product_id, $args = array() ) {

		$args = wp_parse_args(
			$args,
			array(
				'free'              => false,
				'blog_ids'          => array(),
				'supports_renewals' => true,
			)
		);

		$this->id                = $product_id;
		$this->file              = $file;
		$this->free              = $args['free'];
		$this->blog_ids          = $args['blog_ids'];
		$this->supports_renewals = $args['supports_renewals'];
		$this->key               = '';
		$this->expires           = '';
		$this->home_url          = array();

		if ( ! empty( $this->blog_ids ) ) {
			foreach ( $this->blog_ids as $blog_id ) {
				$this->home_url[] = VD()->sanitize_domain( get_home_url( $blog_id, '/' ) );
			}
		} else {
			$this->home_url[] = VD()->sanitize_domain( home_url( '/' ) );
		}

		$this->home_url = array_values( array_unique( $this->home_url ) );

		$this->set_meta();
		$this->slug = sanitize_title( $this->Name );
		$registered = $this->get_options();

		if ( isset( $registered[ $this->file ] ) ) {
			$this->key     = $registered[ $this->file ]['key'];
			$this->expires = $registered[ $this->file ]['expires'];
		}
	}

	public function set_meta() {
		$this->meta = VD()->plugins[ $this->file ];
	}

	public function __get( $key ) {
		return ( isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : false );
	}

	public function __isset( $key ) {
		return ( isset( $this->meta[ $key ] ) ? true : false );
	}

	public function is_theme() {
		return $this->theme;
	}

	public function get_blog_ids() {
		return $this->blog_ids;
	}

	public function get_url() {
		return $this->PluginURI;
	}

	public function is_free() {
		return $this->free;
	}

	public function get_renewal_url() {
		return $this->get_url() . '?renew=true&license=' . $this->key;
	}

	public function supports_renewals() {
		return $this->is_free() ? false : $this->supports_renewals;
	}

	public function is_registered() {
		return ( ( ! empty( $this->key ) || $this->is_free() ) ? true : false );
	}

	public function refresh_expiration_date( $force = false ) {
		if ( $this->is_registered() ) {
			$expire = VD()->api->expiration_check( $this, $force );

			if ( ! is_wp_error( $expire ) ) {
				$this->set_expiration_date( $expire );
			}

			return $expire;
		}

		return false;
	}

	public function flush_api_cache() {
		delete_transient( "_vendidero_helper_updates_{$this->id}" );
		delete_transient( "_vendidero_helper_update_info_{$this->id}" );
		delete_transient( "_vendidero_helper_expiry_{$this->id}" );
	}

	public function get_expiration_date( $format = 'd.m.Y' ) {
		if ( ! $this->is_registered() || empty( $this->expires ) ) {
			return false;
		}

		$date = $this->expires;

		return ( ! $format ? $date : date( $format, strtotime( $date ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	public function has_expired() {
		if ( ! $this->is_registered() || empty( $this->expires ) ) {
			return false;
		}

		if ( ( strtotime( $this->expires ) < time() ) ) {
			return true;
		}

		return false;
	}

	protected function get_options() {
		if ( is_multisite() ) {
			return get_site_option( 'vendidero_registered', array() );
		} else {
			return get_option( 'vendidero_registered', array() );
		}
	}

	protected function update_options( $data ) {
		if ( is_multisite() ) {
			return update_site_option( 'vendidero_registered', $data );
		} else {
			return update_option( 'vendidero_registered', $data );
		}
	}

	public function register( $key, $expires = '' ) {
		$registered = $this->get_options();

		if ( ! isset( $registered[ $this->file ] ) ) {
			$registered[ $this->file ] = array(
				'key'     => md5( $key ),
				'expires' => $expires,
			);
			$this->expires             = $registered[ $this->file ]['expires'];
			$this->key                 = $registered[ $this->file ]['key'];
		}

		$this->update_options( $registered );
	}

	public function set_expiration_date( $expires ) {
		$registered = $this->get_options();

		if ( isset( $registered[ $this->file ] ) ) {
			$registered[ $this->file ]['expires'] = $expires;
			$this->expires                        = $registered[ $this->file ]['expires'];
		}

		$this->update_options( $registered );
	}

	public function unregister() {
		$registered = $this->get_options();

		if ( isset( $registered[ $this->file ] ) ) {
			unset( $registered[ $this->file ] );

			$this->key     = '';
			$this->expires = '';
		}

		if ( ! empty( $registered ) ) {
			foreach ( $registered as $key => $val ) {

				if ( is_numeric( $key ) ) {
					unset( $registered[ $key ] );
				}
			}
		}

		$this->update_options( array_filter( $registered ) );
	}

	public function get_home_url() {
		return $this->home_url;
	}

	public function get_key() {
		if ( ! $this->is_registered() ) {
			return false;
		}

		if ( $this->is_free() ) {
			return '';
		}

		return $this->key;
	}
}
