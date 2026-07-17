/* global jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var $environment = $( '#woocommerce_kpay_environment' );

		if ( ! $environment.length ) {
			return;
		}

		var sections = {
			sandbox: [
				'#woocommerce_kpay_sandbox_api_key',
				'#woocommerce_kpay_sandbox_secret_key',
			],
			live: [
				'#woocommerce_kpay_live_api_key',
				'#woocommerce_kpay_live_secret_key',
			],
		};

		function toggle() {
			var isSandbox = $environment.val() === 'sandbox';

			// Les champs "title" sont rendus en <h3> + <p>, hors du tableau :
			// on masque l'en-tête et sa description avec les lignes associées.
			$( '#woocommerce_kpay_sandbox_section' ).closest( 'h3, table' ).toggle( isSandbox );
			$( '#woocommerce_kpay_live_section' ).closest( 'h3, table' ).toggle( ! isSandbox );

			$.each( sections.sandbox, function ( i, selector ) {
				$( selector ).closest( 'tr' ).toggle( isSandbox );
			} );
			$.each( sections.live, function ( i, selector ) {
				$( selector ).closest( 'tr' ).toggle( ! isSandbox );
			} );
		}

		toggle();
		$environment.on( 'change', toggle );
	} );
} )( jQuery );
