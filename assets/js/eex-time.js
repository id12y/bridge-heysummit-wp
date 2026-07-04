/**
 * eex-time: cache-safe time handling. Converts server-rendered event-local
 * times to the visitor's timezone, computes upcoming / starting-soon /
 * live-now / past states client-side (so hours-old cached HTML stays
 * correct), drives countdowns, and refreshes the registration counter over
 * REST. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var config = window.eexTime || { i18n: {}, soonMinutes: 60, restBase: '' };

	function localise() {
		var timeFormat = new window.Intl.DateTimeFormat( undefined, {
			dateStyle: 'medium',
			timeStyle: 'short'
		} );
		var zone = ( window.Intl.DateTimeFormat().resolvedOptions().timeZone || '' );

		document.querySelectorAll( 'time[data-eex-time]' ).forEach( function ( node ) {
			var date = new Date( node.getAttribute( 'datetime' ) );
			if ( isNaN( date.getTime() ) ) {
				return;
			}
			node.textContent = timeFormat.format( date );
			if ( zone ) {
				var tz = document.createElement( 'span' );
				tz.className = 'eex-tz';
				tz.textContent = ' (' + zone + ')';
				node.appendChild( tz );
			}
		} );
	}

	function sessionStates() {
		var now = Date.now();
		var soonWindow = ( config.soonMinutes || 60 ) * 60 * 1000;

		document.querySelectorAll( '[data-eex-session]' ).forEach( function ( node ) {
			var start = Date.parse( node.getAttribute( 'data-eex-start' ) || '' );
			var end = Date.parse( node.getAttribute( 'data-eex-end' ) || '' );

			if ( isNaN( start ) ) {
				return;
			}
			if ( isNaN( end ) ) {
				end = start + 3600 * 1000;
			}

			var slot = node.querySelector( '[data-eex-live-slot]' );
			var cta = node.querySelector( '[data-eex-cta]' );
			var join = node.getAttribute( 'data-eex-join' ) || '';

			// Defence in depth: the value is sanitised server-side, but this
			// attribute becomes an href, so only http(s) may pass here too.
			if ( join && ! /^https?:\/\//i.test( join ) ) {
				join = '';
			}

			node.classList.remove( 'eex-is-live', 'eex-is-soon', 'eex-is-past' );

			if ( now >= start && now <= end ) {
				node.classList.add( 'eex-is-live' );
				if ( slot ) {
					slot.hidden = false;
					slot.textContent = config.i18n.liveNow || 'Live now';
				}
				if ( cta && join ) {
					cta.textContent = config.i18n.joinNow || 'Join now';
					cta.setAttribute( 'href', join );
				}
			} else if ( now < start && start - now <= soonWindow ) {
				node.classList.add( 'eex-is-soon' );
				if ( slot ) {
					slot.hidden = false;
					slot.textContent = config.i18n.startingSoon || 'Starting soon';
				}
			} else if ( now > end ) {
				node.classList.add( 'eex-is-past' );
				if ( slot ) {
					slot.hidden = true;
				}
			} else if ( slot ) {
				slot.hidden = true;
			}
		} );
	}

	function liveBar() {
		document.querySelectorAll( '[data-eex-live-bar]' ).forEach( function ( bar ) {
			var live = bar.querySelector( '.eex-live-bar-watch .eex-is-live' );
			var link = bar.querySelector( '[data-eex-live-bar-link]' );

			if ( ! live || ! link ) {
				bar.hidden = true;
				return;
			}

			var join = live.getAttribute( 'data-eex-join' ) || '';
			if ( join && ! /^https?:\/\//i.test( join ) ) {
				join = '';
			}

			link.textContent = live.getAttribute( 'data-eex-bar-title' ) || '';
			if ( join ) {
				link.setAttribute( 'href', join );
			} else {
				link.removeAttribute( 'href' );
			}
			bar.hidden = false;
		} );
	}

	function countdowns() {
		document.querySelectorAll( '[data-eex-countdown]' ).forEach( function ( node ) {
			var target = Date.parse( node.getAttribute( 'data-eex-countdown' ) || '' );
			if ( isNaN( target ) ) {
				return;
			}

			var remaining = target - Date.now();
			if ( remaining <= 0 ) {
				return; // Session state handling takes over.
			}

			var minutes = Math.floor( remaining / 60000 );
			var days = Math.floor( minutes / 1440 );
			var hours = Math.floor( ( minutes % 1440 ) / 60 );
			var mins = minutes % 60;

			var parts = [];
			if ( days > 0 ) {
				parts.push( days + ' ' + ( config.i18n.days || 'days' ) );
			}
			if ( hours > 0 || days > 0 ) {
				parts.push( hours + ' ' + ( config.i18n.hours || 'hours' ) );
			}
			parts.push( mins + ' ' + ( config.i18n.minutes || 'minutes' ) );

			var display = node.querySelector( '.eex-countdown-remaining' );
			if ( ! display ) {
				display = document.createElement( 'strong' );
				display.className = 'eex-countdown-remaining';
				// The separator only belongs before a following label; a bare
				// countdown (the hero) must not end in a dangling dash.
				display.setAttribute( 'data-eex-sep', ( node.textContent || '' ).trim() ? '1' : '' );
				node.insertBefore( display, node.firstChild );
			}
			display.textContent = parts.join( ' ' ) + ( display.getAttribute( 'data-eex-sep' ) ? ' — ' : '' );
		} );
	}

	function refreshCounters() {
		if ( ! config.restBase || ! window.fetch ) {
			return;
		}

		document.querySelectorAll( '[data-eex-counter]' ).forEach( function ( node ) {
			var eventId = node.getAttribute( 'data-eex-counter' );
			if ( ! eventId ) {
				return;
			}

			window
				.fetch( config.restBase + 'counter/' + encodeURIComponent( eventId ) )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( json ) {
					if ( ! json || 'undefined' === typeof json.count ) {
						return;
					}
					var threshold = parseInt( node.getAttribute( 'data-eex-threshold' ) || '0', 10 );
					if ( json.count < threshold ) {
						node.hidden = true;
						return;
					}
					node.hidden = false;
					var figure = node.querySelector( '.eex-reg-count' );
					if ( figure ) {
						figure.textContent = json.count.toLocaleString();
					}
				} )
				.catch( function () {
					/* Server-rendered figure stays as the fallback. */
				} );
		} );
	}

	function tick() {
		sessionStates();
		liveBar();
		countdowns();
	}

	function init() {
		localise();
		tick();
		refreshCounters();
		window.setInterval( tick, 30000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

/**
 * Ticket drawer: Register buttons carrying data-eex-drawer open the
 * server-rendered slide-over instead of following their link (which stays
 * as the no-JS fallback). Accessible dialog behaviour: focus moves in,
 * Tab is trapped, Escape and the backdrop close, focus returns to the
 * opening button, and the page behind stops scrolling.
 */
( function () {
	'use strict';

	var opener = null;

	function openDrawer( drawer, button ) {
		opener = button;
		drawer.hidden = false;
		document.documentElement.classList.add( 'eex-drawer-open' );

		var panel = drawer.querySelector( '.eex-drawer-panel' );
		if ( panel ) {
			panel.focus();
		}
	}

	function closeDrawer( drawer ) {
		drawer.hidden = true;
		document.documentElement.classList.remove( 'eex-drawer-open' );

		if ( opener ) {
			opener.focus();
			opener = null;
		}
	}

	function currentDrawer() {
		return document.querySelector( '.eex-drawer:not([hidden])' );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-eex-drawer]' );
		if ( button ) {
			var drawer = document.getElementById( button.getAttribute( 'data-eex-drawer' ) );
			if ( drawer ) {
				event.preventDefault();
				openDrawer( drawer, button );
			}
			return;
		}

		var toggle = event.target.closest( '[data-eex-reg-toggle]' );
		if ( toggle ) {
			var form = toggle.parentNode.querySelector( '[data-eex-reg]' );
			if ( form ) {
				form.hidden = ! form.hidden;
				if ( ! form.hidden ) {
					var first = form.querySelector( 'input[name="name"]' );
					if ( first ) {
						first.focus();
					}
				}
			}
			return;
		}

		var close = event.target.closest( '[data-eex-drawer-close]' );
		if ( close ) {
			var open = close.closest( '.eex-drawer' );
			if ( open ) {
				closeDrawer( open );
			}
		}
	} );

	// The free-ticket registration form: this site's own REST endpoint does
	// the HeySummit call server-side; the drawer shows the outcome inline.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '[data-eex-reg]' );
		if ( ! form ) {
			return;
		}

		event.preventDefault();

		var config = window.eexTime || { i18n: {} };
		var msg = form.querySelector( '.eex-reg-msg' );
		var submit = form.querySelector( 'button[type="submit"]' );

		if ( ! config.restBase || ! window.fetch ) {
			if ( msg ) {
				msg.textContent = config.i18n.regError || 'Something went wrong — please try again.';
			}
			return;
		}

		if ( submit ) {
			submit.disabled = true;
		}

		var data = {};
		new window.FormData( form ).forEach( function ( value, key ) {
			data[ key ] = value;
		} );

		window
			.fetch( config.restBase + 'register', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( data )
			} )
			.then( function ( response ) {
				return response.json().then( function ( json ) {
					return { ok: response.ok, json: json };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					var done = document.createElement( 'p' );
					done.className = 'eex-reg-done';
					done.setAttribute( 'role', 'status' );
					done.textContent = 'already' === ( result.json && result.json.status )
						? ( config.i18n.regAlready || 'You are already registered.' )
						: ( config.i18n.regDone || 'You are registered.' );
					form.replaceWith( done );
					return;
				}

				if ( msg ) {
					msg.textContent = ( result.json && result.json.message ) || config.i18n.regError || 'Something went wrong — please try again.';
				}
				if ( submit ) {
					submit.disabled = false;
				}
			} )
			.catch( function () {
				if ( msg ) {
					msg.textContent = config.i18n.regError || 'Something went wrong — please try again.';
				}
				if ( submit ) {
					submit.disabled = false;
				}
			} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		var drawer = currentDrawer();
		if ( ! drawer ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			closeDrawer( drawer );
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		// Keep focus inside the dialog while it is open.
		var focusable = drawer.querySelectorAll( 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])' );
		if ( ! focusable.length ) {
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );
}() );

/**
 * Session filter bar: progressive enhancement. Without JS the category,
 * speaker and search links work as ordinary links/forms; with JS they
 * filter the rendered session grids instantly.
 */
( function () {
	'use strict';

	var bars = document.querySelectorAll( '[data-eex-filter]' );
	if ( ! bars.length ) {
		return;
	}

	var state = { cat: '', speaker: '', text: '' };

	function applyFilters() {
		document.querySelectorAll( '.eex li[data-eex-title]' ).forEach( function ( item ) {
			var show = true;
			if ( state.cat ) {
				var cats = ( item.getAttribute( 'data-eex-cats' ) || '' ).split( ',' );
				show = show && -1 !== cats.indexOf( state.cat );
			}
			if ( state.speaker ) {
				var speakers = ( item.getAttribute( 'data-eex-speakers' ) || '' ).split( ',' );
				show = show && -1 !== speakers.indexOf( state.speaker );
			}
			if ( state.text ) {
				show = show && -1 !== ( item.getAttribute( 'data-eex-title' ) || '' ).indexOf( state.text );
			}
			item.hidden = ! show;
		} );
	}

	bars.forEach( function ( bar ) {
		bar.addEventListener( 'click', function ( event ) {
			var cat = event.target.closest( '[data-eex-filter-cat]' );
			var speaker = event.target.closest( '[data-eex-filter-speaker]' );
			if ( ! cat && ! speaker ) {
				return;
			}
			event.preventDefault();

			if ( cat ) {
				var value = cat.getAttribute( 'data-eex-filter-cat' );
				state.cat = state.cat === value ? '' : value;
				bar.querySelectorAll( '[data-eex-filter-cat]' ).forEach( function ( link ) {
					link.classList.toggle( 'eex-current', link === cat && state.cat === value );
				} );
			}
			if ( speaker ) {
				var name = speaker.getAttribute( 'data-eex-filter-speaker' );
				state.speaker = state.speaker === name ? '' : name;
				bar.querySelectorAll( '[data-eex-filter-speaker]' ).forEach( function ( link ) {
					link.classList.toggle( 'eex-current', link === speaker && state.speaker === name );
				} );
			}
			applyFilters();
		} );

		var text = bar.querySelector( '[data-eex-filter-text]' );
		if ( text ) {
			text.addEventListener( 'input', function () {
				state.text = text.value.toLowerCase();
				applyFilters();
			} );
			var form = text.closest( 'form' );
			if ( form ) {
				form.addEventListener( 'submit', function ( event ) {
					event.preventDefault();
					state.text = text.value.toLowerCase();
					applyFilters();
				} );
			}
		}
	} );
}() );
