<?php

class VD_Product_Theme extends VD_Product {

	public function __construct( $file, $product_id, $is_free = false ) {
		parent::__construct( $file, $product_id, $is_free = false );
		$this->theme = true;
	}

	public function set_meta() {
		$this->meta = VD()->themes[ $this->file ];
	}

	public function __get( $key ) {
		return ( $this->meta->__get( $key ) ? $this->meta->__get( $key ) : false );
	}

	public function __isset( $key ) {
		return ( $this->meta->__get( $key ) ? true : false );
	}

}

?>