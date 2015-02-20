<?php

class VD_Product {

	public $file;
	public $id;
	public $free = false;
	public $theme = false;
	private $key;
	public $meta = array();
	public $updater = null;

	public function __construct( $file, $product_id, $free = false ) {
		$this->id = $product_id;
		$this->file = $file;
		$this->free = $free;
		$this->key = '';
		$this->set_meta();
		$registered = get_option( 'vendidero_registered', array() );
		if ( isset( $registered[ $this->file ] ) )
			$this->key = $registered[ $this->file ];
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

	public function is_free() {
		return $this->free;
	}

	public function is_registered() {
		return ( ( ! empty( $this->key ) || $this->is_free() ) ? true : false );
	}

	public function register( $key ) {
		$registered = get_option( 'vendidero_registered', array() );
		if ( ! isset( $registered[ $this->file ] ) ) {
			$registered[ $this->file ] = md5( $key );
			$this->key = $registered[ $this->file ];
		}
		update_option( 'vendidero_registered', $registered );
	}

	public function unregister() {
		$registered = get_option( 'vendidero_registered', array() );
		if ( isset( $registered[ $this->file ] ) ) {
			unset( $registered[ $this->file ] );
			$this->key = '';
		}
		update_option( 'vendidero_registered', array_values( $registered ) );
	}

	public function get_key( $hash = false ) {
		if ( ! $this->is_registered() )
			return false;
		if ( $this->is_free() )
			return '';
		return ( $hash ? md5( $this->key ) : $this->key );
	}

}