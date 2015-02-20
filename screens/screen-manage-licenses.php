<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="about-wrap">

	<div class="col-wrap">

		<form id="register" method="post" action="" class="validate">
			<input type="hidden" name="action" value="vd_register" />
			<input type="hidden" name="page" value="vendidero" />
			
			<?php
				
				$table = new VD_Admin_License_Table();
				$table->data = VD()->get_products( false );
				$table->prepare_items();
				$table->display();
				submit_button( __( 'Register Products', 'vendidero-helper' ), 'button-primary' );
			
			?>

			<?php wp_nonce_field( 'bulk_licenses' ); ?>

		</form>

	</div>

</div>