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
