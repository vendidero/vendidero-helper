<?php

class VD_Product_Theme extends VD_Product {

	public function __construct( $file, $product_id, $is_free = false ) {
		parent::__construct( $file, $product_id, $is_free = false );
		$this->theme = true;
	}

	public function __get( $key ) {

		$value = parent::__get( $key );

		if ( $this->meta->get( $key ) )
			$value = $this->meta->get( $key );

		return $value;
	}

	public function __isset( $key ) {
		
		$is = parent::__isset( $key );

		if ( $this->meta->get( $key ) )
			$is = true;

		return $is;

	}

	public function set_meta() {
		$this->meta = VD()->themes[ $this->file ];
	}

	public function get_url() {
		return $this->ThemeURI;
	}

}

?>