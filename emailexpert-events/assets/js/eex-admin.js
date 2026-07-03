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

/* Wizard behaviours. */
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
			var value = fields[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key, item );
				} );
			} else {
				body.append( key, value );
			}
		} );
		return window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} );
	}

	/* Step 2: load events with dates and session counts, then reload. */
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.eex-wizard-load-events' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		button.disabled = true;
		post( 'eex_wizard_events', { connection: button.getAttribute( 'data-connection' ) } )
			.then( function ( json ) {
				if ( json.success ) {
					window.location.reload();
				} else {
					var slot = button.parentElement.querySelector( '.eex-inline-result' );
					if ( slot ) {
						slot.textContent = ( json.data && json.data.message ) || config.i18n.failed;
					}
					button.disabled = false;
				}
			} );
	} );

	/* Step 4: live dry-run preview per event as scope changes. */
	function refreshPreview( row ) {
		var slot = row.querySelector( '.eex-wizard-preview' );
		if ( ! slot ) {
			return;
		}
		slot.textContent = config.i18n.working;

		var fields = {
			connection: row.getAttribute( 'data-connection' ),
			event: row.getAttribute( 'data-event' )
		};
		row.querySelectorAll( '.eex-scope-input' ).forEach( function ( input ) {
			var name = input.getAttribute( 'name' ) || '';
			var short = name.replace( /^scope\[[^\]]*\]\[/, 'scope[' );
			if ( 'checkbox' === input.type && ! input.checked ) {
				return;
			}
			if ( ! fields[ short ] ) {
				fields[ short ] = [];
			}
			fields[ short ].push( input.value );
		} );

		post( 'eex_wizard_dry_run', fields ).then( function ( json ) {
			slot.textContent = json.success ? json.data.message : ( ( json.data && json.data.message ) || config.i18n.failed );
		} );
	}

	document.querySelectorAll( '.eex-wizard-scope' ).forEach( function ( row ) {
		if ( row.querySelector( '.eex-wizard-preview' ) ) {
			refreshPreview( row );
			row.addEventListener( 'change', function () {
				refreshPreview( row );
			} );
		}
	} );

	/* Step 5: progress polling. */
	var progress = document.querySelector( '[data-eex-progress]' );
	if ( progress ) {
		var poll = function () {
			post( 'eex_wizard_progress', {} ).then( function ( json ) {
				if ( ! json.success ) {
					return;
				}
				progress.innerHTML = '';
				var pre = document.createElement( 'pre' );
				pre.textContent = ( json.data.lines || [] ).join( '\n' ) || '…';
				progress.appendChild( pre );

				if ( json.data.done ) {
					var complete = document.getElementById( 'eex-wizard-complete' );
					if ( complete ) {
						complete.hidden = false;
					}
				} else {
					window.setTimeout( poll, 3000 );
				}
			} );
		};
		poll();
	}
}() );
