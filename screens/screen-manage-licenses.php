<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;} // Exit if accessed directly ?>

<div class="wrap about-wrap table-wrap">

	<div class="col-wrap">
		<form id="register" method="post" action="" class="validate">
			<input type="hidden" name="action" value="vd_register" />
			<input type="hidden" name="page" value="vendidero" />

			<?php
				$table       = new VD_Admin_License_Table();
				$table->data = VD()->get_products( false );
				$table->prepare_items();
				$table->display();

				$has_unregisted = false;

			foreach ( $table->data as $product ) {
				if ( ! $product->is_registered() ) {
					$has_unregisted = true;
				}
			}

			if ( $has_unregisted ) {
				submit_button( __( 'Register Products', 'vendidero-helper' ), 'button-primary' );
			}
			?>

			<?php wp_nonce_field( 'bulk_licenses' ); ?>

		</form>
	</div>
</div>
