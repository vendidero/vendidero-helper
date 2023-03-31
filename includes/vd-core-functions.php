<?php

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'VD' ) ) {
	function VD() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		include_once \Vendidero\VendideroHelper\Package::get_path() . '/includes/class-vendidero-helper.php';

		return Vendidero_Helper::instance();
	}
}

$GLOBALS['vendidero_helper'] = VD();
