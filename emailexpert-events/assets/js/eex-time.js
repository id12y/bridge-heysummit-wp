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
				node.insertBefore( display, node.firstChild );
			}
			display.textContent = parts.join( ' ' ) + ' — ';
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
