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

			<a class="refresh" href="<?php echo wp_nonce_url( add_query_arg( array( 'action' => 'vd_refresh' ), admin_url( 'index.php?page=vendidero' ) ), 'refresh_licenses' ); ?>"><?php _e( 'Refresh license statuses', 'vendidero-helper' ); ?></a>

			<?php wp_nonce_field( 'bulk_licenses' ); ?>

		</form>

	</div>

</div>