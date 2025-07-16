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
		return $item[ $column_name ];
	}

	public function get_sortable_columns() {
		return array();
	}

	public function get_columns() {
		$columns = array(
			'product_name'    => _x( 'Product', 'vd-helper', 'vendidero-helper' ),
			'product_version' => _x( 'Current version', 'vd-helper', 'vendidero-helper' ),
			'product_expires' => _x( 'Updates & Support', 'vd-helper', 'vendidero-helper' ),
			'product_status'  => _x( 'License', 'vd-helper', 'vendidero-helper' ),
		);

		return $columns;
	}

	protected function get_table_classes() {
		$classes = array_merge( parent::get_table_classes(), array( 'posts' ) );

		return array_diff( $classes, array( 'striped' ) );
	}

	/**
	 * @param Product $item
	 */
	public function column_product_name( $item ) {
		$count = 0;
		?>
		<p>
			<strong title="<?php echo esc_attr( $item->file ); ?>"><?php echo esc_html( $item->Name ); ?></strong>
		</p>

		<span class="active-on">
			<?php foreach ( $item->get_home_url() as $url ) : ?>
				<?php echo ( ( ++$count > 1 ) ? ', ' : '' ) . esc_url( $url ); ?>
			<?php endforeach; ?>
		</span>

		<?php if ( $item->has_errors() ) : ?>
			<?php foreach ( $item->get_errors() as $error_message ) : ?>
				<div class="vd-inline-error">
					<span class="dashicons dashicons-warning"></span>
					<?php echo wp_kses_post( wpautop( $error_message ) ); ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param Product $item
	 */
	public function column_product_expires( $item ) {
		if ( $item->get_expiration_date() ) :
			if ( $item->has_expired() && $item->supports_renewals() ) :
				?>
				<a href="<?php echo esc_url( $item->get_renewal_url() ); ?>" class="button button-primary wc-gzd-button" target="_blank"><?php echo esc_html_x( 'renew now', 'vd-helper', 'vendidero-helper' ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $item->get_expiration_date() ); ?>
			<?php endif; ?>
		<?php else : ?>
			<?php echo '-'; ?>
			<?php
		endif;
	}

	/**
	 * @param Product $item
	 */
	public function column_product_version( $item ) {
		$latest          = Package::get_api()->info( $item );
		$current_version = $item->Version;
		$is_obsolete     = $latest && version_compare( $latest->version, $current_version, '>' );
		$status          = $is_obsolete ? 'old' : 'latest';
		?>
		<span class="version version-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $current_version ); ?></span>

		<?php
		if ( $is_obsolete && $latest ) :
			$update_url = ( is_multisite() ? network_admin_url( 'update-core.php?force-check=1' ) : admin_url( 'update-core.php?force-check=1' ) );
			?>
			<?php echo esc_html_x( 'vs.', 'vd-helper', 'vendidero-helper' ); ?> <span class="version version-latest"><?php echo esc_html( $latest->version ); ?></span>

			<?php if ( ! $item->has_expired() ) : ?>
				<br/><a class="button button-secondary" href="<?php echo esc_url( $update_url ); ?> "><?php echo esc_html_x( 'Check for updates', 'vd-helper', 'vendidero-helper' ); ?></a>
				<?php
			endif;
		endif;
	}

	/**
	 * @param Product $item
	 */
	public function column_product_status( $item ) {
		if ( $item->is_registered() ) :
			?>
			<a href="#" class="button button-secondary vd-unregister-license" data-file="<?php echo esc_attr( $item->file ); ?>"><span class="btn-text"><?php echo esc_html_x( 'Unregister', 'vd-helper', 'vendidero-helper' ); ?></span></a>
		<?php else : ?>
			<div class="forminp">
				<input name="license_key_<?php echo esc_attr( $item->file ); ?>" id="license_keys-<?php echo esc_attr( $item->file ); ?>" type="text" class="license-key-input" value="" aria-required="true" placeholder="<?php echo esc_html_x( 'Enter license key', 'vd-helper', 'vendidero-helper' ); ?>" />
				<button class="button button-primary vd-register-license" type="submit" value="submit" data-file="<?php echo esc_attr( $item->file ); ?>">
					<span class="btn-text"><?php echo esc_html_x( 'Register', 'vd-helper', 'vendidero-helper' ); ?></span>
				</button>
			</div>
			<a class="vd-help-link" href="<?php echo esc_url( sprintf( _x( 'https://vendidero.com/products/latest/%s', 'vd-helper', 'vendidero-helper' ), $item->id ) ); ?>" target="_blank"><?php echo esc_html_x( 'Find your license key', 'vd-helper', 'vendidero-helper' ); ?></a>
			<?php
		endif;
	}

	public function get_bulk_actions() {
		return array();
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
