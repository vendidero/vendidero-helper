<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if( ! class_exists( 'WP_List_Table' ) )
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );

class VD_Admin_License_Table extends WP_List_Table {
	
	public $per_page = 30;
	public $data = array();
	public $items = array();
	public $found_data = array();
	
	/**
	 * Constructor.
	 */
	public function __construct () {
		global $status, $page;
		$args = array(
            'singular'  => 'license',     
            'plural'    => 'licenses',  
            'ajax'      => false     
	    );
		$this->data = array();
		require_once( ABSPATH . '/wp-admin/includes/plugin-install.php' );
	    parent::__construct( $args );
	}

	public function no_items () {
	    echo wpautop( __( 'No Vendidero products found.', 'vendidero-helper' ) );
	} 

	public function column_default ( $item, $column_name ) {
	    switch( $column_name ) {
	        case 'product':
	        case 'product_status':
	        case 'product_version':
	        case 'product_expires':
	            return $item[$column_name];
	        break;
	    }
	}

	public function get_sortable_columns () {
	  return array();
	}

	public function get_columns () {
        $columns = array(
            'product_name' => __( 'Product', 'vendidero-helper' ),
            'product_version' => __( 'Version', 'vendidero-helper' ),
            'product_expires' => __( 'Update & Support', 'vendidero-helper' ),
            'product_status' => __( 'License Key', 'vendidero-helper' )
        );
         return $columns;
    }

	public function column_product_name ( $item ) {
		return wpautop( '<strong title="' . $item->file . '">' . $item->Name . '</strong>' );
	}

	public function column_product_expires ( $item ) {
		if ( $item->get_expiration_date() ) {
			if ( $item->has_expired() )
				return '<a href="' . $item->get_renewal_url() . '" class="button-secondary" target="_blank">' . __( 'renew now', 'vendidero-helper' ) . '</a>';
			return $item->get_expiration_date();
		}
		return '-';
	}

	public function column_product_version ( $item ) {
		return wpautop( $item->Version );
	}

	public function column_product_status ( $item ) {
		$response = '';
		if ( $item->is_registered() ) {
			$unregister_url = wp_nonce_url( add_query_arg( 'action', 'vd_unregister', add_query_arg( 'filepath', $item->file, add_query_arg( 'page', 'vendidero', admin_url( 'index.php' ) ) ) ), 'bulk_licenses' );
			$response = '<a href="' . esc_url( $unregister_url ) . '">' . __( 'Unregister', 'vendidero-helper' ) . '</a>' . "\n";
		} else {
			$response .= '<input name="license_keys[' . esc_attr( $item->file ) . ']" id="license_keys-' . esc_attr( $item->file ) . '" type="text" value="" style="width: 100%" aria-required="true" placeholder="' . esc_attr( sprintf( __( 'Place %s license key here', 'vendidero-helper' ), $item->Name ) ) . '" />' . "\n";
		}
		return $response;
	}

	public function get_bulk_actions () {
	  	$actions = array();
	  	return $actions;
	}

	public function prepare_items () {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$total_items = count( $this->data );

		$this->found_data = $this->data;

		$this->set_pagination_args( array(
		'total_items' => $total_items,                  
		'per_page'    => $total_items                 
		) );
		$this->items = $this->found_data;
	}

}
?>