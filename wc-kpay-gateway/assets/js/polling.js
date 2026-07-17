/* global jQuery, wcKPayPolling */
( function ( $ ) {
	'use strict';

	if ( typeof wcKPayPolling === 'undefined' ) {
		return;
	}

	var startedAt = Date.now();
	var timer = null;
	var $notice = null;

	function ensureNotice() {
		if ( $notice ) {
			return $notice;
		}

		var $anchor = $( '.woocommerce-order' ).first();
		if ( ! $anchor.length ) {
			$anchor = $( '.entry-content, .wp-block-woocommerce-order-confirmation-status' ).first();
		}
		if ( ! $anchor.length ) {
			return null;
		}

		$notice = $( '<div class="woocommerce-info wc-kpay-status" role="status" aria-live="polite"></div>' )
			.text( wcKPayPolling.i18n.pending );
		$anchor.prepend( $notice );

		return $notice;
	}

	function setNotice( message, cssClass ) {
		var $el = ensureNotice();
		if ( ! $el ) {
			return;
		}
		$el.removeClass( 'woocommerce-info woocommerce-message woocommerce-error' )
			.addClass( cssClass )
			.text( message );
	}

	function stop() {
		if ( timer ) {
			clearInterval( timer );
			timer = null;
		}
	}

	function check() {
		if ( Date.now() - startedAt > wcKPayPolling.timeout ) {
			stop();
			setNotice( wcKPayPolling.i18n.expired, 'woocommerce-info' );
			return;
		}

		$.ajax( {
			url: wcKPayPolling.ajaxUrl,
			type: 'POST',
			data: {
				action: 'kpay_check_status',
				order_id: wcKPayPolling.orderId,
				order_key: wcKPayPolling.orderKey,
				nonce: wcKPayPolling.nonce,
			},
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					return;
				}

				var status = response.data.status;

				if ( status === 'COMPLETED' ) {
					stop();
					setNotice( wcKPayPolling.i18n.completed, 'woocommerce-message' );
					window.location.reload();
					return;
				}

				if ( status === 'FAILED' || status === 'CANCELLED' ) {
					stop();
					setNotice( wcKPayPolling.i18n.failed, 'woocommerce-error' );
					window.location.reload();
				}
			} )
			.fail( function ( xhr ) {
				// Une requête refusée ne se réparera pas en réessayant.
				if ( xhr.status === 403 || xhr.status === 404 ) {
					stop();
				}
			} );
	}

	$( function () {
		ensureNotice();
		timer = setInterval( check, wcKPayPolling.interval );
		check();
	} );
} )( jQuery );
