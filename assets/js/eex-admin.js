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
		var payload = { connection: button.getAttribute( 'data-connection' ) };

		// Send the key as typed, so testing works before saving (a key that
		// authenticates is saved server-side). Settings page: the key field
		// in the same table row; wizard: the single key field on the form.
		var row = button.closest( 'tr' ) || document;
		var keyField = row.querySelector( 'input[type="password"][name*="api_key"]' ) || document.getElementById( 'eex-wizard-key' );

		if ( keyField && ! keyField.disabled && keyField.value ) {
			payload.api_key = keyField.value;
		}

		return payload;
	}, function ( button, payload ) {
		if ( ! payload.connection ) {
			return;
		}

		// In the wizard, a successful test unlocks the next step and the
		// discovery summary — both server-rendered, so reload to show them
		// (this also refreshes the form's hidden connection ID).
		if ( button.closest( '.eex-wizard' ) ) {
			window.setTimeout( function () {
				window.location.reload();
			}, 800 );

			return;
		}

		// A saved key means the row now has a real connection ID: put it on
		// the button (so a re-test targets the saved row) and into the row's
		// hidden id field (so a later "Save settings" updates this
		// connection instead of re-creating it), and clear the typed key
		// (blank keeps the stored key on save).
		button.setAttribute( 'data-connection', payload.connection );

		var row = button.closest( 'tr' );
		if ( row && payload.saved ) {
			var idField = row.querySelector( 'input[name*="[id]"]' );
			var keyField = row.querySelector( 'input[type="password"][name*="api_key"]' );
			if ( idField ) {
				idField.value = payload.connection;
			}
			if ( keyField ) {
				keyField.value = '';
				keyField.placeholder = ( window.eexAdmin && eexAdmin.i18n && eexAdmin.i18n.keySaved ) || 'Key saved';
			}
		}
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

/* WooCommerce product mapping: load tickets for the chosen connection + event. */
( function () {
	'use strict';

	if ( typeof window.eexAdmin === 'undefined' ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.eex-woo-load-tickets' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var panel = button.closest( '.woocommerce_options_panel, .eex-variation-mapping' ) || document;
		var connection = panel.querySelector( '[name="' + button.getAttribute( 'data-connection-field' ) + '"]' );
		var eventField = panel.querySelector( '[name="' + button.getAttribute( 'data-event-field' ) + '"]' );

		var body = new window.FormData();
		body.append( 'action', 'eex_woo_tickets' );
		body.append( 'nonce', window.eexAdmin.nonce );
		body.append( 'connection', connection ? connection.value : '' );
		body.append( 'event', eventField ? eventField.value : '' );

		button.disabled = true;
		window.fetch( window.eexAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				button.disabled = false;
				if ( ! json.success ) {
					window.alert( ( json.data && json.data.message ) || window.eexAdmin.i18n.failed );
					return;
				}
				var select = button.parentElement.querySelector( 'select' );
				if ( ! select ) {
					return;
				}
				var current = select.value;
				select.innerHTML = '<option value=""></option>';
				json.data.tickets.forEach( function ( ticket ) {
					var option = document.createElement( 'option' );
					option.value = ticket.id;
					option.textContent = ticket.title;
					option.selected = ticket.id === current;
					select.appendChild( option );
				} );
			} );
	} );
}() );

/* Relay test buttons. */
( function () {
	'use strict';

	if ( typeof window.eexAdmin === 'undefined' ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.eex-relay-test' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var slot = button.parentElement.querySelector( '.eex-inline-result' );
		var body = new window.FormData();
		body.append( 'action', 'eex_relay_test' );
		body.append( 'nonce', window.eexAdmin.nonce );
		body.append( 'index', button.getAttribute( 'data-index' ) );

		button.disabled = true;
		window.fetch( window.eexAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				if ( slot ) {
					slot.textContent = ( json.data && json.data.message ) || ( json.success ? 'OK' : window.eexAdmin.i18n.failed );
				}
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );
}() );
