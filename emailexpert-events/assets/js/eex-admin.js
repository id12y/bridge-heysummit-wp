/**
 * Settings screen behaviours: connection tests, event loading, sync now,
 * secret regeneration and dynamic connection rows. Vanilla JS.
 */
( function () {
	'use strict';

	if ( typeof window.eexAdmin === 'undefined' ) {
		return;
	}

	var config = window.eexAdmin;

	function post( action, fields ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return window
			.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function feedback( button, message, isError ) {
		var slot = button.parentElement.querySelector( '.eex-inline-result' ) ||
			button.closest( 'td, p, div' ).querySelector( '.eex-inline-result' );
		if ( slot ) {
			slot.textContent = message;
			slot.classList.toggle( 'eex-error', !! isError );
		}
	}

	function bindAjaxButton( selector, action, fieldsFor, onSuccess ) {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( selector );
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			button.disabled = true;
			feedback( button, config.i18n.working, false );

			post( action, fieldsFor( button ) )
				.then( function ( json ) {
					var payload = json.data || {};
					feedback( button, payload.message || ( json.success ? 'OK' : config.i18n.failed ), ! json.success );
					if ( json.success && onSuccess ) {
						onSuccess( button, payload );
					}
				} )
				.catch( function () {
					feedback( button, config.i18n.failed, true );
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	}

	bindAjaxButton( '.eex-test-connection', 'eex_test_connection', function ( button ) {
		return { connection: button.getAttribute( 'data-connection' ) };
	} );

	bindAjaxButton( '.eex-load-events', 'eex_load_events', function ( button ) {
		return { connection: button.getAttribute( 'data-connection' ) };
	}, function () {
		window.location.reload();
	} );

	bindAjaxButton( '.eex-load-categories', 'eex_load_categories', function ( button ) {
		return {
			connection: button.getAttribute( 'data-connection' ),
			event: button.getAttribute( 'data-event' ),
		};
	}, function () {
		window.location.reload();
	} );

	bindAjaxButton( '#eex-sync-now', 'eex_sync_now', function () {
		return {};
	} );

	bindAjaxButton( '#eex-regenerate-secret', 'eex_regenerate_secret', function () {
		return {};
	}, function ( button, payload ) {
		var slot = document.getElementById( 'eex-webhook-url' );
		if ( slot && payload.url ) {
			slot.textContent = payload.url;
		}
	} );

	var addButton = document.getElementById( 'eex-add-connection' );
	if ( addButton ) {
		addButton.addEventListener( 'click', function () {
			var tbody = document.querySelector( '#eex-connections tbody' );
			var index = tbody.querySelectorAll( 'tr' ).length;
			var row = document.createElement( 'tr' );
			row.innerHTML =
				'<td><input type="hidden" name="connections[' + index + '][id]" value="" />' +
				'<input type="text" name="connections[' + index + '][label]" class="regular-text" /></td>' +
				'<td><input type="password" name="connections[' + index + '][api_key]" class="regular-text" autocomplete="new-password" /></td>' +
				'<td></td>';
			tbody.appendChild( row );
		} );
	}
}() );
