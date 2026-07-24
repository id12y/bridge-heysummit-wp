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

	var timeNodes = [];
	var timeModeOverride = null;

	function storedTimeMode() {
		if ( timeModeOverride ) {
			return timeModeOverride;
		}
		try {
			return window.sessionStorage.getItem( 'eex-tz-mode' ) || 'local';
		} catch ( e ) {
			return 'local';
		}
	}

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
			// Both renderings are kept so a timezone toggle can flip between
			// them: the server's event-local markup and the visitor-local one.
			if ( ! node.eexEventHtml ) {
				node.eexEventHtml = node.innerHTML;
			}
			node.textContent = timeFormat.format( date );
			if ( zone ) {
				var tz = document.createElement( 'span' );
				tz.className = 'eex-tz';
				tz.textContent = ' (' + zone + ')';
				node.appendChild( tz );
			}
			node.eexLocalHtml = node.innerHTML;
			timeNodes.push( node );
		} );

		applyTimeMode();
	}

	function applyTimeMode() {
		var eventMode = 'event' === storedTimeMode();

		timeNodes.forEach( function ( node ) {
			node.innerHTML = eventMode ? node.eexEventHtml : node.eexLocalHtml;
		} );

		// The toggle is a JS-only affordance: reveal it, and keep its label
		// naming the mode a click switches TO. aria-pressed = "my timezone".
		document.querySelectorAll( '[data-eex-tz-toggle]' ).forEach( function ( button ) {
			var wrap = button.closest( '.eex-tz-toggle' );
			if ( wrap ) {
				wrap.hidden = false;
			}
			button.setAttribute( 'aria-pressed', eventMode ? 'false' : 'true' );
			button.textContent = ( eventMode ? button.getAttribute( 'data-label-local' ) : button.getAttribute( 'data-label-event' ) ) || button.textContent;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest( '[data-eex-tz-toggle]' );
		if ( ! toggle ) {
			return;
		}
		timeModeOverride = 'event' === storedTimeMode() ? 'local' : 'event';
		try {
			window.sessionStorage.setItem( 'eex-tz-mode', timeModeOverride );
		} catch ( e ) {
			/* Preference just won't persist beyond this page. */
		}
		applyTimeMode();
	} );

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

		// The shared event-level drawer is opened from a specific session's
		// button; stamp that session onto every free-registration form so the
		// talk is added to the attendee's schedule on submit.
		var talk = button.getAttribute( 'data-eex-talk' ) || '';
		var talkInputs = drawer.querySelectorAll( 'input[name="talk"]' );
		for ( var i = 0; i < talkInputs.length; i++ ) {
			talkInputs[ i ].value = talk;
		}

		// Say so, too: name the session in the drawer header so the visitor
		// can see what the registration will cover. Event-level openers
		// (register bar, hero without a session) leave the line hidden.
		var context = drawer.querySelector( '[data-eex-drawer-context]' );
		if ( context ) {
			var talkTitle = button.getAttribute( 'data-eex-talk-title' ) || '';
			if ( talk && talkTitle ) {
				context.textContent = ( context.getAttribute( 'data-eex-prefix' ) || 'Registering for:' ) + ' ' + talkTitle;
				context.hidden = false;
			} else {
				context.textContent = '';
				context.hidden = true;
			}
		}

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
			// The form sits next to the toggle (drawer rows) or just outside
			// its actions container (cards, the feature card, the bar) — one
			// level up covers both without ever reaching a neighbouring card.
			var form = toggle.parentNode.querySelector( '[data-eex-reg]' );
			if ( ! form && toggle.parentNode.parentNode ) {
				form = toggle.parentNode.parentNode.querySelector( '[data-eex-reg]' );
			}
			if ( form ) {
				// RSVP toggles are anchors keeping a real ticket-page href as
				// the no-JS fallback; with JS the form opens instead.
				event.preventDefault();
				form.hidden = ! form.hidden;
				toggle.setAttribute( 'aria-expanded', form.hidden ? 'false' : 'true' );

				// Once the form is open the toggle has done its job — two
				// stacked primary buttons otherwise read as competing actions.
				toggle.hidden = ! form.hidden;

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
					// Remember locally so later page views can confirm the
					// RSVP without a click (see the RSVP memory section).
					if ( window.eexRsvpRemember ) {
						window.eexRsvpRemember( data );
					}

					var done = document.createElement( 'p' );
					done.className = 'eex-reg-done';
					done.setAttribute( 'role', 'status' );

					// A session-scoped form (talk field filled) confirms the
					// schedule, not just the registration — the difference a
					// returning member actually cares about.
					var talkField = form.querySelector( 'input[name="talk"]' );
					var forTalk = talkField && '' !== talkField.value;
					done.textContent = 'already' === ( result.json && result.json.status )
						? ( forTalk
							? ( config.i18n.regAlreadyTalk || 'You are already registered — this session is on your schedule.' )
							: ( config.i18n.regAlready || 'You are already registered.' ) )
						: ( forTalk
							? ( config.i18n.regDoneTalk || 'You are registered — this session is on your schedule.' )
							: ( config.i18n.regDone || 'You are registered.' ) );
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
 * Sticky register bar: rendered in normal flow (the no-JS presentation),
 * then pinned and revealed after the scroll offset. A dismissal is
 * remembered for the browsing session. Static inside the Elementor editor
 * so it stays selectable where it was placed.
 */
( function () {
	'use strict';

	var bars = document.querySelectorAll( '[data-eex-register-bar]' );
	if ( ! bars.length || document.body.classList.contains( 'elementor-editor-active' ) ) {
		return;
	}

	bars.forEach( function ( bar ) {
		var dismissed = false;
		try {
			dismissed = !! window.sessionStorage.getItem( 'eex-dismissed-' + bar.id );
		} catch ( e ) {
			/* No storage: the bar just shows. */
		}
		if ( dismissed ) {
			return;
		}

		var offset = parseInt( bar.getAttribute( 'data-eex-bar-offset' ) || '0', 10 );
		bar.classList.add( 'eex-bar-fixed' );

		function update() {
			bar.classList.toggle( 'eex-bar-visible', ( window.scrollY || window.pageYOffset || 0 ) >= offset );
		}

		window.addEventListener( 'scroll', update, { passive: true } );
		update();

		bar.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-eex-bar-dismiss]' ) ) {
				return;
			}
			bar.classList.remove( 'eex-bar-visible' );
			try {
				window.sessionStorage.setItem( 'eex-dismissed-' + bar.id, '1' );
			} catch ( e ) {
				/* The dismissal just won't survive a reload. */
			}
		} );
	} );
}() );

/**
 * Stat count-up: opt-in per widget, first time the number scrolls into
 * view, skipped entirely for visitors preferring reduced motion. The
 * server-rendered figure is always the fallback.
 */
( function () {
	'use strict';

	var nodes = document.querySelectorAll( '[data-eex-countup]' );
	if ( ! nodes.length || ! window.IntersectionObserver ) {
		return;
	}
	if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}

	var observer = new window.IntersectionObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( ! entry.isIntersecting ) {
				return;
			}
			observer.unobserve( entry.target );

			var node = entry.target;
			var target = parseInt( node.getAttribute( 'data-eex-countup' ) || '', 10 );
			if ( isNaN( target ) || target <= 0 ) {
				return;
			}

			var start = null;
			function step( timestamp ) {
				if ( null === start ) {
					start = timestamp;
				}
				var progress = Math.min( 1, ( timestamp - start ) / 900 );
				node.textContent = Math.round( target * ( 1 - Math.pow( 1 - progress, 3 ) ) ).toLocaleString();
				if ( progress < 1 ) {
					window.requestAnimationFrame( step );
				}
			}
			window.requestAnimationFrame( step );
		} );
	}, { threshold: 0.4 } );

	nodes.forEach( function ( node ) {
		observer.observe( node );
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

// RSVP memory: after a successful registration through any of this
// plugin's forms, the browser remembers who registered and for what
// (localStorage only — nothing leaves the visitor's machine). On later
// views the RSVP button for a session already on their schedule becomes
// a quiet confirmation, no clicks needed, and future forms prefill.
// Registered-state is never queried from the server for an anonymous
// visitor — that would make it probeable by email (see the suppression
// rule in the register endpoint); this remembers only what this browser
// itself submitted.
( function () {
	var KEY = 'eex-rsvp-v1';

	function load() {
		try {
			return JSON.parse( window.localStorage.getItem( KEY ) ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function save( data ) {
		try {
			window.localStorage.setItem( KEY, JSON.stringify( data ) );
		} catch ( e ) {
			// Private mode / full disk: memory is a nicety, never an error.
		}
	}

	// Called by the submit handler on success with the submitted fields.
	window.eexRsvpRemember = function ( data ) {
		var store = load();
		store.name = data.name || store.name || '';
		store.email = data.email || store.email || '';
		store.talks = store.talks || {};
		store.events = store.events || {};
		if ( data.talk ) {
			store.talks[ data.talk ] = true;
		} else if ( data.event ) {
			store.events[ data.event ] = true;
		}
		save( store );
	};

	if ( document.body.classList.contains( 'elementor-editor-active' ) ) {
		return;
	}

	var store = load();
	var config = window.eexTime || { i18n: {} };

	document.querySelectorAll( '[data-eex-reg]' ).forEach( function ( form ) {
		var nameInput = form.querySelector( 'input[name="name"]' );
		var emailInput = form.querySelector( 'input[name="email"]' );
		if ( nameInput && ! nameInput.value && store.name ) {
			nameInput.value = store.name;
		}
		if ( emailInput && ! emailInput.value && store.email ) {
			emailInput.value = store.email;
		}

		var talkField = form.querySelector( 'input[name="talk"]' );
		var eventField = form.querySelector( 'input[name="event"]' );
		var talkId = talkField ? talkField.value : '';
		var eventId = eventField ? eventField.value : '';
		var known = ( talkId && store.talks && store.talks[ talkId ] ) ||
			( ! talkId && eventId && store.events && store.events[ eventId ] );

		if ( ! known || ! form.parentNode ) {
			return;
		}

		var toggle = form.parentNode.querySelector( '[data-eex-reg-toggle]' );
		if ( ! toggle ) {
			return;
		}

		// Advise without a single click: the button becomes a confirmation.
		var chip = document.createElement( 'p' );
		chip.className = 'eex-reg-done eex-rsvp-known';
		chip.setAttribute( 'role', 'status' );
		chip.textContent = talkId
			? ( config.i18n.rsvpKnownTalk || 'You’re going — this session is on your schedule.' )
			: ( config.i18n.rsvpKnownEvent || 'You’re registered for this event.' );

		var other = document.createElement( 'button' );
		other.type = 'button';
		other.className = 'eex-rsvp-other';
		other.textContent = config.i18n.rsvpOther || 'Not you? RSVP someone else';
		other.addEventListener( 'click', function () {
			chip.remove();
			toggle.hidden = false;
			if ( nameInput ) {
				nameInput.value = '';
			}
			if ( emailInput ) {
				emailInput.value = '';
			}
		} );
		chip.appendChild( document.createTextNode( ' ' ) );
		chip.appendChild( other );

		toggle.hidden = true;
		toggle.parentNode.insertBefore( chip, toggle );
	} );
}() );
