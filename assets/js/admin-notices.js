/*!
 * GatewayKit - Admin Notices
 * Handles dismiss/run interactions for admin notices via AJAX.
 * Data is provided via the localized `GatewayKitAdminNotices` object.
 */
( function () {
	'use strict';

	function postAjax( url, params, callback ) {
		var body = Object.keys( params )
			.map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
			} )
			.join( '&' );
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', url, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onreadystatechange = function () {
			if ( xhr.readyState === 4 && typeof callback === 'function' ) {
				try {
					callback( JSON.parse( xhr.responseText ) );
				} catch ( e ) {
					callback( null );
				}
			}
		};
		xhr.send( body );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var data = window.GatewayKitAdminNotices || {};
		if ( ! data.ajaxurl ) {
			return;
		}

		// Pro upgrade notice dismissal.
		// Use document-level event delegation because WordPress injects the
		// .notice-dismiss button after DOMContentLoaded; querying it directly at
		// load can return null and leave the dismissal un-persisted, causing the
		// notice to reappear after the page is reloaded.
		document.addEventListener( 'click', function ( ev ) {
			var proNotice = document.getElementById( 'gatewaykit-pro-notice' );
			if ( ! proNotice || ! proNotice.contains( ev.target ) ) {
				return;
			}
			if ( ! ev.target.closest( '.notice-dismiss' ) ) {
				return;
			}
			postAjax( data.ajaxurl, {
				action: 'gatewaykit_dismiss_pro_notice',
				nonce: data.proNonce || '',
			} );
		} );
	} );
} )();
