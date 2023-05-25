<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap about-wrap table-wrap">
	<div class="col-wrap">
		<div class="vd-notice-wrapper">
			<?php do_action( 'vd_admin_notices' ); ?>
		</div>

		<form id="register" method="post" action="" class="validate">
			<input type="hidden" name="action" value="vd_register" />
			<input type="hidden" name="page" value="vendidero" />
			<?php
				$table       = new \Vendidero\VendideroHelper\LicenseTable();
				$table->data = \Vendidero\VendideroHelper\Package::get_products( false );
				$table->prepare_items();
				$table->display();

				$has_unregisted = false;

			foreach ( $table->data as $product ) {
				if ( ! $product->is_registered() ) {
					$has_unregisted = true;
				}
			}

			if ( $has_unregisted ) {
				submit_button( _x( 'Register Products', 'vd-helper', 'vendidero-helper' ), 'button-primary' );
			}
			?>

			<?php wp_nonce_field( 'bulk_licenses' ); ?>
		</form>
	</div>
</div>
