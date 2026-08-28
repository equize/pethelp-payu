window.pethelpPayuWidgetCallback = function ( response ) {
	response = response || {};

	jQuery( '.pethelp-payu-token-type' ).val( response.tokenType || '' );
	jQuery( '.pethelp-payu-token-value' ).val( response.value || '' );
	jQuery( '.pethelp-payu-masked-card' ).val( response.maskedCard || '' );
	jQuery( '.pethelp-payu-widget-response' ).val( JSON.stringify( response ) );

	jQuery.pethelpPayuResponse = response;
	jQuery( '#place_order' ).trigger( 'click' );
};

( function ( $ ) {
	$( document ).on( 'click', '#place_order', function ( e ) {
		var $fields = $( '.pethelp-payu-card-fields' );

		if ( ! $fields.length ) {
			return;
		}

		var selectedMethod = $( 'input[name="payment_method"]:checked' ).val();

		if ( selectedMethod !== $fields.data( 'gateway-id' ) ) {
			return;
		}

		if ( ! $.pethelpPayuResponse ) {
			e.preventDefault();
			$( '#pethelp_payu_widget_trigger' ).trigger( 'click' );
		}
	} );

	$( document.body ).on( 'payment_method_selected updated_checkout', function () {
		$.pethelpPayuResponse = null;
	} );
} )( jQuery );
