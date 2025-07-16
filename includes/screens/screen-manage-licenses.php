<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap about-wrap table-wrap">
	<div class="col-wrap">
		<?php
			$table = new \Vendidero\VendideroHelper\LicenseTable();

		if ( $is_single_display ) {
			$table->data = array( $vd_product );
		} else {
			$table->data = \Vendidero\VendideroHelper\Package::get_products( false );
		}

			$table->prepare_items();
			$table->display();
		?>
	</div>
</div>
