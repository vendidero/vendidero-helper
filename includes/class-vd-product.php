<?php

class VD_Product {

	public $file;
	public $id;
	public $slug;
	public $free = false;
	public $theme = false;
	private $key;
	public $meta = array();
	public $updater = null;
	public $expires;

	public function __construct( $file, $product_id, $free = false ) {
		$this->id = $product_id;
		$this->file = $file;
		$this->free = $free;
		$this->key = '';
		$this->expires = '';
		$this->set_meta();
		$this->slug = sanitize_title( $this->Name );
		$registered = get_option( 'vendidero_registered', array() );
		if ( isset( $registered[ $this->file ] ) ) {
			$this->key = $registered[ $this->file ]["key"];
			$this->expires = $registered[ $this->file ]["expires"];
		}
	}

	public function set_meta() {
		$this->meta = VD()->plugins[$this->file];
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

	public function get_url() {
		return $this->PluginURI;
	}

	public function is_free() {
		return $this->free;
	}

	public function get_renewal_url() {
		return $this->get_url() . '?renew=true&license=' . $this->key;
	}

	public function is_registered() {
		return ( ( ! empty( $this->key ) || $this->is_free() ) ? true : false );
	}

	public function refresh_expiration_date() {
		if ( $this->is_registered() ) {
			$expire = VD()->api->expiration_check( $this );
			if ( $expire )
                $this->set_expiration_date( $expire );
		}
	}

	public function get_expiration_date( $format = 'd.m.Y' ) {
		if ( ! $this->is_registered() || empty( $this->expires ) )
			return false;
		$date = $this->expires;
		return ( ! $format ? $date : date( $format, strtotime( $date ) ) );
	}

	public function has_expired() {
		if ( ! $this->is_registered() || empty( $this->expires ) )
			return false;
        if ( ( strtotime( $this->expires ) < time() ) ) 
            return true;
        return false;
	}

	public function register( $key, $expires = '' ) {
		$registered = get_option( 'vendidero_registered', array() );
		if ( ! isset( $registered[ $this->file ] ) ) {
			$registered[ $this->file ] = array( "key" => md5( $key ), "expires" => $expires );
			$this->expires = $registered[ $this->file ]["expires"];
			$this->key = $registered[ $this->file ]["key"];
		}
		update_option( 'vendidero_registered', $registered );
	}

	public function set_expiration_date( $expires ) {
		$registered = get_option( 'vendidero_registered', array() );
		if ( isset( $registered[ $this->file ] ) ) {
			$registered[ $this->file ]["expires"] = $expires;
			$this->expires = $registered[ $this->file ]["expires"];
		}
		update_option( 'vendidero_registered', $registered );
	}

	public function unregister() {
		
		$registered = get_option( 'vendidero_registered', array() );
		
		if ( isset( $registered[ $this->file ] ) ) {
			unset( $registered[ $this->file ] );
			$this->key = '';
			$this->expires = '';
		}

		if ( ! empty( $registered ) ) {
			foreach( $registered as $key => $val ) {
				if ( is_numeric( $key ) )
					unset( $registered[ $key ] );
			}
		}

		update_option( 'vendidero_registered', array_filter( $registered ) );
	}

	public function get_key() {
		if ( ! $this->is_registered() )
			return false;
		if ( $this->is_free() )
			return '';
		return $this->key;
	}

}