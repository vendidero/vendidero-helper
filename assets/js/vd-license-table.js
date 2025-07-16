window.vendidero = window.vendidero || {};

( function( $, vendidero ) {

    vendidero.table = {
        params: {},

        init: function() {
            var self = this;
            this.params = vd_license_table_params;

            $( document )
                .on( 'click', '.vd-register-license', this.onRegister )
                .on( 'click', '.vd-unregister-license', this.onUnregister );
        },

        onUnregister: function() {
            var self  = vendidero.table,
                $this = $( this );

            var data = {
                action: 'vd_unregister_license',
                security: self.params.unregister_nonce,
                file: $this.data( 'file' )
            };

            self.doAjax( $this, data );

            return false;
        },

        doAjax: function( $this, data ) {
            var self  = vendidero.table,
                $wrapper = $( '.vd-wrapper' );

            $wrapper.find( '.error' ).remove();

            $this.addClass( 'vd-is-loading' );
            $this.append( '<span class="spinner is-active"></span>' );

            if ( $this.is( ':button' ) ) {
                $this.addClass( 'disabled' ).prop( 'disabled', true );
            }

            window.onbeforeunload = '';

            $.ajax( {
                url: self.params.ajax_url,
                data: data,
                dataType: 'json',
                type: 'POST',
                success: function( response ) {
                    if ( response.success ) {
                        window.location.reload();
                    } else if ( response.hasOwnProperty( 'message' ) ) {
                        $wrapper.prepend( '<div class="error inline"><p>' + response.message + '</p></div>' );

                        $( 'html, body' ).animate({
                            scrollTop: ( $wrapper.find( '.error' ).offset().top - 92 )
                        }, 1000 );
                    }

                    $this.find( '.spinner' ).remove();
                    $this.removeClass( 'vd-is-loading' );

                    if ( $this.is( ':button' ) ) {
                        $this.removeClass( 'disabled' ).prop( 'disabled', false );
                    }
                },
                error: function() {
                    $this.find( '.spinner' ).remove();
                    $this.removeClass( 'vd-is-loading' );

                    if ( $this.is( ':button' ) ) {
                        $this.removeClass( 'disabled' ).prop( 'disabled', false );
                    }
                },
            } );

            return false;
        },

        onRegister: function() {
            var self  = vendidero.table,
                $this = $( this );

            var data = {
                action: 'vd_register_license',
                security: self.params.register_nonce,
                file: $this.data( 'file' ),
                license_key: $this.parents( '.forminp' ).find( '.license-key-input' ).val(),
            };

            self.doAjax( $this, data );

            return false;
        }
    };

    $( document ).ready( function() {
        vendidero.table.init();
    });

})( jQuery, window.vendidero );