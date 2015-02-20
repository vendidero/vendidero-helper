jQuery( function( $ ) {
	if ( $( '.vd-notice-theme-update' ).length > 0 ) {
		$( '.vd-notice-theme-update' ).insertBefore( $( '#update-themes-table' ) );
	}
	if ( $( '.vd-notice-plugin-update' ).length > 0 ) {
		$( '.vd-notice-plugin-update' ).insertBefore( $( '#update-plugins-table' ) );
	}
});