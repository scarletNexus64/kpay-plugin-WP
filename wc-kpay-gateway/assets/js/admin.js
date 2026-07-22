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

		/**
		 * Un champ de type "title" est rendu par WooCommerce en <h3> suivi d'un
		 * <p> de description, tous deux hors du tableau. Masquer le seul <h3>
		 * laisse la description orpheline sous la section précédente.
		 */
		function toggleSection( id, visible ) {
			var $heading = $( '#' + id ).closest( 'h3' );

			$heading.toggle( visible );

			// La description suit immédiatement le titre, avant le tableau.
			$heading.nextUntil( 'table, h3' ).filter( 'p' ).toggle( visible );
		}

		function toggle() {
			var isSandbox = $environment.val() === 'sandbox';

			toggleSection( 'woocommerce_kpay_sandbox_section', isSandbox );
			toggleSection( 'woocommerce_kpay_live_section', ! isSandbox );

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
