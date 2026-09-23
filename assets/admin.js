/**
 * SEO Health Check: live progress for a running scan.
 */
( function () {
	'use strict';

	var panel = document.getElementById( 'shc-scan-panel' );
	if ( ! panel || '1' !== panel.getAttribute( 'data-running' ) || 'undefined' === typeof window.shcAdmin ) {
		return;
	}

	var bar = panel.querySelector( '.shc-progress__bar' );
	var progress = panel.querySelector( '.shc-progress' );
	var text = panel.querySelector( '.shc-progress__text' );
	var settings = window.shcAdmin;

	function format( template, processed, total ) {
		return template.replace( '%1$s', processed.toLocaleString() ).replace( '%2$s', total.toLocaleString() );
	}

	function poll() {
		var body = new URLSearchParams();
		body.append( 'action', 'seohc_scan_status' );
		body.append( 'nonce', settings.nonce );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					return;
				}
				var data = json.data;
				var percent = data.total > 0 ? Math.min( 100, Math.floor( ( data.processed / data.total ) * 100 ) ) : 0;

				bar.style.transform = 'scaleX(' + ( percent / 100 ) + ')';
				progress.setAttribute( 'aria-valuenow', percent );
				text.textContent = format( settings.i18n.progress, data.processed, data.total );

				if ( 'running' !== data.status ) {
					text.textContent = settings.i18n.finished;
					window.location.reload();
					return;
				}
				window.setTimeout( poll, 3000 );
			} )
			.catch( function () {
				window.setTimeout( poll, 10000 );
			} );
	}

	window.setTimeout( poll, 2000 );
}() );
