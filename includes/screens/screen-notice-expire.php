<?php
/**
 * Admin View: Notice - Expire
 */

defined( 'ABSPATH' ) || exit;

$dismiss_url = add_query_arg( 'notice', 'vd-hide-notice', add_query_arg( 'nonce', wp_create_nonce( 'vd-hide-notice' ) ) );
$products    = get_option( 'vendidero_notice_expire' );
$show_notice = false;

foreach ( $products as $key => $val ) {
	if ( ( $product = \Vendidero\VendideroHelper\Package::get_product( $key ) ) && $product->has_expired() ) {
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
	<h3><?php echo esc_html_x( 'Update- and Support flat expires', 'vd-helper', 'vendidero-helper' ); ?></h3>
	<p><?php echo esc_html_x( 'It seems like the Update- and Support flat of one of your vendidero products expires in a few days or has already expired:', 'vd-helper', 'vendidero-helper' ); ?></p>

	<?php
	foreach ( $products as $key => $val ) :
		$product = \Vendidero\VendideroHelper\Package::get_product( $key );
		?>
		<?php if ( $product->has_expired() ) : ?>
			<p><?php printf( esc_html_x( '%1$s expired on %2$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $product->get_expiration_date( get_option( 'date_format' ) ) ) ); ?></p>
		<?php else : ?>
			<p><?php printf( esc_html_x( '%1$s expires on %2$s', 'vd-helper', 'vendidero-helper' ), '<strong>' . esc_attr( $product->Name ) . '</strong>', esc_html( $product->get_expiration_date( get_option( 'date_format' ) ) ) ); ?></p>
		<?php endif; ?>

		<a class="button button-primary wc-gzd-button" href="<?php echo esc_url( $product->get_renewal_url() ); ?>" target="_blank"><?php echo esc_html_x( 'renew now', 'vd-helper', 'vendidero-helper' ); ?></a>
	<?php endforeach; ?>

	<p class="alignleft wc-gzd-button-wrapper"></p>

	<p class="alignright">
		<a class="" href="https://vendidero.de/vendidero-service" target="_blank"><?php echo esc_html_x( 'Learn more', 'vd-helper', 'vendidero-helper' ); ?></a> |
		<a class="" href="<?php echo esc_url( \Vendidero\VendideroHelper\Package::get_helper_url() ); ?>"><?php echo esc_html_x( 'See license details', 'vd-helper', 'vendidero-helper' ); ?></a> |
		<a href="<?php echo esc_url( $dismiss_url ); ?>" class="vendidero-helper-dismiss"><?php echo esc_html_x( 'Hide this notice', 'vd-helper', 'vendidero-helper' ); ?></a>
	</p>
	<div class="clear"></div>
</div>
