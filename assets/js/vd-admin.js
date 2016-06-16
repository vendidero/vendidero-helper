jQuery( function( $ ) {
	
	if ( $( '.vd-notice-theme-update' ).length > 0 ) {
		$( '.vd-notice-theme-update' ).insertBefore( $( '#update-themes-table' ) );
	}
	
	if ( $( '.vd-notice-plugin-update' ).length > 0 ) {
		$( '.vd-notice-plugin-update' ).insertBefore( $( '#update-plugins-table' ) );
	}

	if ( $( 'body' ).hasClass( 'update-core-php' ) ) {

		$( '.vd-upgrade-notice' ).each( function() {
			
			var text = $( this ).html();
			var checkbox_id = 'checkbox_' + $( this ).data( 'for' );

			if ( $( '#' + checkbox_id ).length ) {
				
				var checkbox = $( '#' + checkbox_id );
				var tr = checkbox.parents( 'tr' );
				tr.find( 'td.plugin-title p:first' ).append( '<br/>' + text );
			
			}

		});

	}

});