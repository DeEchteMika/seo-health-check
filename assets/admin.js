/**
 * SEO Health Check: live scan progress and inline fixing of alt texts, titles and descriptions.
 */
( function () {
	'use strict';

	var settings = window.shcAdmin;
	if ( ! settings ) {
		return;
	}

	function format( template, a, b ) {
		return template.replace( '%1$s', a ).replace( '%2$s', b ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	function post( body ) {
		return fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/* Scan progress. */
	function watchScan() {
		var panel = document.getElementById( 'shc-scan-panel' );
		if ( ! panel || '1' !== panel.getAttribute( 'data-running' ) ) {
			return;
		}

		var bar = panel.querySelector( '.shc-progress__bar' );
		var progress = panel.querySelector( '.shc-progress' );
		var text = panel.querySelector( '.shc-progress__text' );

		function poll() {
			var body = new URLSearchParams();
			body.append( 'action', 'seohc_scan_status' );
			body.append( 'nonce', settings.nonce );

			post( body )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						return;
					}
					var data = json.data;
					var percent = data.total > 0 ? Math.min( 100, Math.floor( ( data.processed / data.total ) * 100 ) ) : 0;

					bar.style.transform = 'scaleX(' + ( percent / 100 ) + ')';
					progress.setAttribute( 'aria-valuenow', percent );
					text.textContent = format( settings.i18n.progress, data.processed.toLocaleString(), data.total.toLocaleString() );

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
	}

	/* Inline fixing. */
	function setUpInlineEditing() {
		var editors = document.querySelectorAll( '.seohc-inline' );
		if ( ! editors.length ) {
			return;
		}

		Array.prototype.forEach.call( editors, function ( editor ) {
			var toggle = editor.querySelector( '.seohc-inline__toggle' );
			var form = editor.querySelector( '.seohc-inline__form' );
			var input = editor.querySelector( '.seohc-inline__input' );
			var counter = editor.querySelector( '.seohc-inline__count' );
			var status = editor.querySelector( '.seohc-inline__status' );
			var saveButton = editor.querySelector( '.seohc-inline__save' );
			var cancelButton = editor.querySelector( '.seohc-inline__cancel' );
			var max = parseInt( editor.getAttribute( 'data-max' ), 10 ) || 0;
			var original = input.value;

			function updateCounter() {
				var length = input.value.length;
				counter.textContent = format( settings.i18n.counter, length, max );
				counter.classList.toggle( 'is-over', max > 0 && length > max );
			}

			function open( isOpen ) {
				form.hidden = ! isOpen;
				toggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
				if ( isOpen ) {
					updateCounter();
					input.focus();
				}
			}

			function save() {
				var body = new URLSearchParams();
				body.append( 'action', 'seohc_inline_save' );
				body.append( 'nonce', settings.inlineNonce );
				body.append( 'issue_id', editor.getAttribute( 'data-issue' ) );
				body.append( 'value', input.value );

				saveButton.disabled = true;
				status.textContent = settings.i18n.saving;

				post( body )
					.then( function ( json ) {
						saveButton.disabled = false;

						if ( ! json || ! json.success ) {
							status.textContent = ( json && json.data && json.data.message ) || settings.i18n.failed;
							return;
						}

						original = input.value;
						status.textContent = json.data.message;

						var row = editor.closest( 'tr' );
						if ( row && json.data.resolved ) {
							row.classList.add( 'seohc-row--fixed' );
							open( false );
						}
					} )
					.catch( function () {
						saveButton.disabled = false;
						status.textContent = settings.i18n.failed;
					} );
			}

			toggle.addEventListener( 'click', function () {
				open( form.hidden );
			} );

			cancelButton.addEventListener( 'click', function () {
				input.value = original;
				status.textContent = '';
				open( false );
			} );

			saveButton.addEventListener( 'click', save );
			input.addEventListener( 'input', updateCounter );

			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key ) {
					cancelButton.click();
				}
				if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
					event.preventDefault();
					save();
				}
			} );
		} );
	}

	watchScan();
	setUpInlineEditing();
}() );
