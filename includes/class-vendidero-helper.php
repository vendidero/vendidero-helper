<?php

defined( 'ABSPATH' ) || exit;

final class Vendidero_Helper {

	public $api = null;

	/**
	 * Single instance
	 *
	 * @var Vendidero_Helper
	 */
	protected static $_instance = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function __construct() {
		$this->api = \Vendidero\VendideroHelper\Package::get_api();
	}

	public function load() {

	}

	public function init() {

	}

	public function get_product( $key ) {
		return \Vendidero\VendideroHelper\Package::get_product( $key );
	}

	public function get_product_by_id( $id ) {
		return \Vendidero\VendideroHelper\Package::get_product_by_id( $id );
	}

	public function get_products( $show_free = true ) {
		return \Vendidero\VendideroHelper\Package::get_products( $show_free );
	}

	public function get_helper_url() {
		return \Vendidero\VendideroHelper\Package::get_helper_url();
	}
}
