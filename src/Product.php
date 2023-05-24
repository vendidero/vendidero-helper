<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

class Product {

	protected $options = null;

	public $file;
	public $id;
	public $slug;
	public $free              = false;
	public $theme             = false;
	protected $meta           = array();
	public $updater           = null;
	public $blog_ids          = array();
	public $home_url          = array();
	public $supports_renewals = true;

	public function __construct( $file, $product_id, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'free'              => false,
				'blog_ids'          => array(),
				'supports_renewals' => true,
				'meta'              => array(),
			)
		);

		$this->id                = $product_id;
		$this->file              = $file;
		$this->free              = $args['free'];
		$this->blog_ids          = $args['blog_ids'];
		$this->supports_renewals = $args['supports_renewals'];
		$this->home_url          = array();

		if ( ! empty( $this->blog_ids ) ) {
			foreach ( $this->blog_ids as $blog_id ) {
				$this->home_url[] = Package::sanitize_domain( get_home_url( $blog_id, '/' ) );
			}
		} else {
			$this->home_url[] = Package::sanitize_domain( home_url( '/' ) );
		}

		$this->home_url = array_values( array_unique( $this->home_url ) );
		$this->get_meta_data();

		$this->slug = sanitize_title( $this->Name );

		if ( $this->is_registered() ) {
			$this->updater = new Updater( $this );
		}
	}

	protected function get_meta_data() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'get_plugin_data' ) ) {
			$this->meta = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->file );
		}
	}

	public function __get( $key ) {
		if ( 'key' === $key ) {
			return $this->get_key();
		} elseif ( 'expires' === $key ) {
			return $this->get_expires();
		}

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

	public function get_renewal_url( $blog_id = null ) {
		return $this->get_url() . '?renew=true&license=' . $this->get_key( $blog_id );
	}

	public function supports_renewals() {
		return $this->is_free() ? false : $this->supports_renewals;
	}

	public function is_registered( $blog_id = null ) {
		if ( $this->is_free() ) {
			return true;
		}

		if ( is_null( $blog_id ) && ! empty( $this->blog_ids ) ) {
			$is_registered = true;

			foreach ( $this->blog_ids as $blog_id ) {
				if ( ! $this->is_registered( $blog_id ) ) {
					$is_registered = false;
					break;
				}
			}

			return $is_registered;
		} else {
			return $this->get_key( $blog_id ) ? true : false;
		}
	}

	public function refresh_expiration_date( $force = false ) {
		if ( $this->is_registered() ) {
			$expire = Package::get_api()->expiration_check( $this, $force );

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

	public function get_expiration_date( $format = 'd.m.Y', $blog_id = null ) {
		if ( ! $this->is_registered( $blog_id ) || ! $this->get_expires( $blog_id ) ) {
			return false;
		}

		$date = $this->get_expires( $blog_id );

		return ( ! $format ? $date : date( $format, strtotime( $date ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}

	public function has_expired( $blog_id = null ) {
		if ( is_null( $blog_id ) && ! empty( $this->blog_ids ) ) {
			$has_expired = false;

			foreach ( $this->blog_ids as $blog_id ) {
				if ( $this->has_expired( $blog_id ) ) {
					$has_expired = true;
					break;
				}
			}

			return $has_expired;
		} else {
			if ( ! $this->is_registered( $blog_id ) || ! $this->get_expires( $blog_id ) ) {
				return false;
			}

			if ( ( strtotime( $this->get_expires( $blog_id ) ) < time() ) ) {
				return true;
			}
		}

		return false;
	}

	public function get_expired_blog_id() {
		if ( ! empty( $this->blog_ids ) ) {
			foreach ( $this->blog_ids as $blog_id ) {
				if ( $this->has_expired( $blog_id ) ) {
					return $blog_id;
				}
			}
		}

		return null;
	}

	protected function get_options( $blog_id = null ) {
		$default_options = array(
			'key'     => '',
			'expires' => null,
		);

		if ( is_null( $blog_id ) ) {
			if ( is_null( $this->options ) ) {
				$this->options = get_option( 'vendidero_registered', array() );
			}

			$options = $this->options;
		} else {
			$options = get_blog_option( $blog_id, 'vendidero_registered', array() );
		}

		if ( isset( $options[ $this->file ] ) ) {
			return wp_parse_args( $options[ $this->file ], $default_options );
		}

		return $default_options;
	}

	protected function update_options( $data, $blog_id = null ) {
		$data = wp_parse_args(
			$data,
			array(
				'key'     => '',
				'expires' => null,
			)
		);

		if ( is_null( $blog_id ) ) {
			$options = get_option( 'vendidero_registered', array() );
		} else {
			$options = get_blog_option( $blog_id, 'vendidero_registered', array() );
		}

		$options[ $this->file ] = $data;
		$this->options          = null;

		if ( is_null( $blog_id ) ) {
			return update_option( 'vendidero_registered', $options );
		} else {
			return update_blog_option( $blog_id, 'vendidero_registered', $options );
		}
	}

	public function register( $key, $expires = '' ) {
		$registered = array(
			'key'     => md5( $key ),
			'expires' => $expires,
		);

		$this->update_options( $registered );
	}

	public function set_expiration_date( $expires, $blog_id = null ) {
		$options            = $this->get_options( $blog_id );
		$options['expires'] = $expires;

		$this->update_options( $options, $blog_id );
	}

	public function unregister() {
		$registered = get_option( 'vendidero_registered', array() );

		if ( isset( $registered[ $this->file ] ) ) {
			unset( $registered[ $this->file ] );
		}

		$this->options = null;

		update_option( 'vendidero_registered', array_filter( $registered ) );
	}

	public function get_home_url() {
		return $this->home_url;
	}

	public function get_expires( $blog_id = null ) {
		if ( $this->is_free() ) {
			return null;
		}

		if ( is_null( $blog_id ) && ! empty( $this->blog_ids ) ) {
			$expires = 0;

			foreach ( $this->blog_ids as $blog_id ) {
				$blog_expires = strtotime( $this->get_expires( $blog_id ) );

				if ( false !== $blog_expires && $blog_expires > $expires ) {
					$expires = $blog_expires;
				}
			}

			return $expires;
		} else {
			$options = $this->get_options( $blog_id );

			return $options['expires'];
		}
	}

	public function get_key( $blog_id = null ) {
		if ( $this->is_free() ) {
			return '';
		}

		if ( is_null( $blog_id ) && ! empty( $this->blog_ids ) ) {
			$key     = '';
			$expires = 0;

			foreach ( $this->blog_ids as $blog_id ) {
				$blog_key     = $this->get_key( $blog_id );
				$blog_expires = strtotime( $this->get_expires( $blog_id ) );

				if ( ! empty( $blog_key ) && ( '' === $key || ( false !== $blog_expires && $blog_expires > $expires ) ) ) {
					$key     = $blog_key;
					$expires = $blog_expires;
				}
			}

			return $key;
		} else {
			$options = $this->get_options( $blog_id );

			return $options['key'];
		}
	}
}
