<?php

namespace Vendidero\VendideroHelper;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LicenseTable extends \WP_List_Table {

	public $per_page   = 30;
	public $data       = array();
	public $items      = array();
	public $found_data = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $status, $page;

		$args = array(
			'singular' => 'license',
			'plural'   => 'licenses',
			'ajax'     => false,
		);

		$this->data = array();

		require_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		parent::__construct( $args );
	}

	public function no_items() {
		echo wpautop( esc_html_x( 'No vendidero products found.', 'vd-helper', 'vendidero-helper' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'product':
			case 'product_status':
			case 'product_version':
			case 'product_expires':
				return $item[ $column_name ];
		}
	}

	public function get_sortable_columns() {
		return array();
	}

	public function get_columns() {
		$columns = array(
			'product_name'    => _x( 'Product', 'vd-helper', 'vendidero-helper' ),
			'product_version' => _x( 'Version', 'vd-helper', 'vendidero-helper' ),
			'product_expires' => _x( 'Update & Support', 'vd-helper', 'vendidero-helper' ),
			'product_status'  => _x( 'License Key', 'vd-helper', 'vendidero-helper' ),
		);

		return $columns;
	}

	protected function get_table_classes() {
		return array_merge( parent::get_table_classes(), array( 'posts' ) );
	}

	/**
	 * @param Product $item
	 *
	 * @return string
	 */
	public function column_product_name( $item ) {
		echo wpautop( '<strong title="' . esc_attr( $item->file ) . '">' . esc_html( $item->Name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$count = 0;

		echo '<span class="active-on">';

		foreach ( $item->get_home_url() as $url ) {
			echo ( ( ++$count > 1 ) ? ', ' : '' ) . esc_url( $url );
		}

		echo '</span>';
	}

	/**
	 * @param Product $item
	 *
	 * @return string
	 */
	public function column_product_expires( $item ) {
		if ( $item->get_expiration_date() ) {

			if ( $item->has_expired() && $item->supports_renewals() ) {
				$return = '<a href="' . esc_url( $item->get_renewal_url() ) . '" class="button button-primary wc-gzd-button" target="_blank">' . _x( 'renew now', 'vd-helper', 'vendidero-helper' ) . '</a>';
			} else {
				$return = $item->get_expiration_date();
			}

			if ( $item->supports_renewals() ) {
				$return .= '<a class="refresh-expiration" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=vd_refresh_license_status&product_id=' . esc_attr( $item->id ) ), 'vd-refresh-license-status' ) ) . '">' . _x( 'Refresh', 'vd-helper', 'vendidero-helper' ) . '</a>';
			}

			return $return;
		}

		return '-';
	}

	/**
	 * @param Product $item
	 *
	 * @return string
	 */
	public function column_product_version( $item ) {
		$latest          = Package::get_api()->info( $item );
		$current_version = $item->Version;
		$status          = 'latest';
		$new_version     = '';

		if ( $latest ) {
			if ( version_compare( $latest->version, $current_version, '>' ) ) {
				$update_url  = ( is_multisite() ? network_admin_url( 'update-core.php?force-check=1' ) : admin_url( 'update-core.php?force-check=1' ) );
				$status      = 'old';
				$new_version = _x( 'vs.', 'vd-helper', 'vendidero-helper' ) . ' <span class="version version-latest">' . $latest->version . '</span>';

				if ( ! $item->has_expired() ) {
					$new_version .= '<br/><a class="button button-secondary" href="' . esc_url( $update_url ) . '">' . esc_html_x( 'Check for updates', 'vd-helper', 'vendidero-helper' ) . '</a>';
				}
			}
		}

		echo '<span class="version version-' . esc_attr( $status ) . '">' . esc_html( $current_version ) . '</span>';

		if ( ! empty( $new_version ) ) {
			echo wp_kses_post( $new_version );
		}
	}

	/**
	 * @param Product $item
	 *
	 * @return string
	 */
	public function column_product_status( $item ) {
		$response = '';

		if ( $item->is_registered() ) {
			$base_url = admin_url( 'index.php' );

			$unregister_url = wp_nonce_url( add_query_arg( 'action', 'vd_unregister', add_query_arg( 'filepath', $item->file, add_query_arg( 'page', 'vendidero', $base_url ) ) ), 'bulk_licenses' );
			$response       = '<a href="' . esc_url( $unregister_url ) . '">' . _x( 'Unregister', 'vd-helper', 'vendidero-helper' ) . '</a>' . "\n";
		} else {
			$response .= '<input name="license_keys[' . esc_attr( $item->file ) . ']" id="license_keys-' . esc_attr( $item->file ) . '" type="text" value="" style="width: 100%" aria-required="true" placeholder="' . esc_attr( _x( 'Enter license key', 'vd-helper', 'vendidero-helper' ) ) . '" /><br/>';
			$response .= '<a href="https://vendidero.de/dashboard/products/" target="_blank">' . _x( 'Find your license key', 'vd-helper', 'vendidero-helper' ) . '</a>';
		}

		return $response;
	}

	public function get_bulk_actions() {
		$actions = array();
		return $actions;
	}

	public function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$total_items      = count( $this->data );
		$this->found_data = $this->data;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $total_items,
			)
		);

		$this->items = $this->found_data;
	}
}
