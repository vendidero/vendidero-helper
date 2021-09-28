<?php
/**
 * Admin View: Notice - Expire
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$dismiss_url = add_query_arg( 'notice', 'vd-hide-notice', add_query_arg( 'nonce', wp_create_nonce( 'vd-hide-notice' ) ) );
$products    = get_option( 'vendidero_notice_expire' );
$show_notice = false;

foreach ( $products as $key => $val ) {
	if ( VD()->get_product( $key ) ) {
		$show_notice = true;
	} else {
		unset( $products[ $key ] );
	}
}

if ( ! $show_notice ) {
	delete_option( 'vendidero_notice_expire' );
	return;
}
?>

<div class="error fade">
	<h3><?php _e( 'Update & Support Flatrate expires', 'vendidero-helper' ); ?></h3>
	<p><?php _e( 'It seems like the Update & Support Flatrate of one of your vendidero products expires in a few days or has already expired:', 'vendidero-helper' ); ?></p>

    <?php foreach( $products as $key => $val ) : $product = VD()->get_product( $key ); ?>
        <?php if ( $product->has_expired() ) : ?>
 		    <p><?php printf( __( '%1$s expired on %2$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', $product->get_expiration_date( get_option( 'date_format' ) ) ); ?></p>
        <?php else: ?>
            <p><?php printf( __( '%1$s expires on %2$s', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', $product->get_expiration_date( get_option( 'date_format' ) ) ); ?></p>
	    <?php endif; ?>

        <a class="button button-primary wc-gzd-button" href="<?php echo $product->get_renewal_url();?>" target="_blank"><?php _e( 'renew now', 'vendidero-helper' );?></a>
	<?php endforeach; ?>

	<p class="alignleft wc-gzd-button-wrapper"></p>
	<p class="alignright">
		<a href="<?php echo esc_url( $dismiss_url );?>" class="vendidero-helper-dismiss"><?php _e( 'Hide this notice', 'vendidero-helper' ); ?></a>
	</p>
	<div class="clear"></div>
</div>