/**
 * Editor registration for all emailexpert Events blocks. Plain JS (no build
 * step): every block is dynamic and previews through ServerSideRender, so the
 * editor shows the exact front-end output.
 *
 * Components whose attribute specs carry a `group` (the compositions) get
 * grouped Inspector panels instead of one flat list, plus a handful of
 * conditional controls and an accessible section-order control — still plain
 * WordPress components, no build step.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || typeof window.eexBlocks === 'undefined' ) {
		return;
	}

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var Button = wp.components.Button;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	var labels = {
		event: __( 'Event (HeySummit ID, post ID or slug)', 'emailexpert-events' ),
		category: __( 'Category slug(s), comma separated', 'emailexpert-events' ),
		limit: __( 'Limit', 'emailexpert-events' ),
		columns: __( 'Columns', 'emailexpert-events' ),
		paginate: __( 'Paginate (1/0)', 'emailexpert-events' ),
		empty_text: __( 'Empty state text', 'emailexpert-events' ),
		show_subscribe: __( 'Show subscribe link (1/0)', 'emailexpert-events' ),
		series: __( 'Series slug', 'emailexpert-events' ),
		talk: __( 'Session (HeySummit ID or post ID)', 'emailexpert-events' ),
		ids: __( 'Session IDs, comma separated', 'emailexpert-events' ),
		threshold: __( 'Hide below (registrations)', 'emailexpert-events' )
	};

	// Progressive disclosure for the compositions: a control renders only
	// while it can have an effect. Names not listed always render.
	var conditions = {
		story_id: function ( a ) { return 'manual' === a.story_source; },
		story_fallback: function ( a ) { return 'manual' === a.story_source; },
		story2_source: function ( a ) { return '2' === a.story_count; },
		story2_id: function ( a ) { return '2' === a.story_count && 'manual' === a.story2_source; },
		story2_placement: function ( a ) { return '2' === a.story_count; },
		news_image_position: function ( a ) { return void 0 === a.news_show_image || !! a.news_show_image; },
		news_link_categories: function ( a ) { return void 0 === a.news_show_category || !! a.news_show_category; },
		news_link_images: function ( a ) { return void 0 === a.news_show_image || !! a.news_show_image; },
		news_all_text: function ( a ) { return void 0 === a.news_all_show || !! a.news_all_show; },
		news_all_url: function ( a ) { return void 0 === a.news_all_show || !! a.news_all_show; },
		news_image_size: function ( a ) {
			var position = a.news_image_position || 'auto';
			return ( void 0 === a.news_show_image || !! a.news_show_image ) && ( 'auto' === position || 'above' === position );
		},
		more_events_speakers: function ( a ) {
			var presentation = a.more_events_presentation || 'events';
			return ( void 0 === a.more_events || !! a.more_events ) && -1 !== [ 'speakers', 'auto', 'rich_horizontal', 'rich_vertical' ].indexOf( presentation );
		},
		more_events_interaction: function ( a ) {
			var presentation = a.more_events_presentation || 'events';
			return ( void 0 === a.more_events || !! a.more_events ) && -1 !== [ 'auto', 'rich_horizontal', 'rich_vertical' ].indexOf( presentation );
		},
		more_show_time: function ( a ) {
			var presentation = a.more_events_presentation || 'events';
			return ( void 0 === a.more_events || !! a.more_events ) && -1 !== [ 'auto', 'rich_horizontal', 'rich_vertical' ].indexOf( presentation );
		},
		more_show_event: function ( a ) {
			var presentation = a.more_events_presentation || 'events';
			return ( void 0 === a.more_events || !! a.more_events ) && -1 !== [ 'auto', 'rich_horizontal', 'rich_vertical' ].indexOf( presentation );
		},
		more_show_media: function ( a ) {
			var presentation = a.more_events_presentation || 'events';
			return ( void 0 === a.more_events || !! a.more_events ) && -1 !== [ 'auto', 'rich_horizontal', 'rich_vertical' ].indexOf( presentation );
		},
		more_events_presentation: function ( a ) { return void 0 === a.more_events || !! a.more_events; },
		more_events_layout: function ( a ) {
			var presentation = a.more_events_presentation || 'events';
			return ( void 0 === a.more_events || !! a.more_events ) && -1 === [ 'rich_horizontal', 'rich_vertical' ].indexOf( presentation );
		},
		more_events_whisper: function ( a ) { return void 0 === a.more_events || !! a.more_events; },
		featured_event: function ( a ) { return 'manual_event' === a.featured_source; },
		featured_session: function ( a ) { return 'manual_session' === a.featured_source; },
		event_presentation: function ( a ) { return 'none' !== a.featured_source && 'manual_session' !== a.featured_source; },
		presentation_session: function ( a ) { return 'session' === a.event_presentation && 'none' !== a.featured_source && 'manual_session' !== a.featured_source; },
		selection_strategy: function ( a ) { return 'auto' === a.featured_source || void 0 === a.featured_source; },
		pin_duration: function ( a ) { return 'manual_event' === a.featured_source || 'manual_session' === a.featured_source; },
		pin_until: function ( a ) { return 'until_date' === a.pin_duration && ( 'manual_event' === a.featured_source || 'manual_session' === a.featured_source ); },
		pin_expiry_action: function ( a ) { return 'manual_event' === a.featured_source || 'manual_session' === a.featured_source; },
		more_events_mode: function ( a ) { return void 0 === a.more_events || !! a.more_events; },
		more_events_limit: function ( a ) { return void 0 === a.more_events || !! a.more_events; },
		more_events_placement: function ( a ) { return !! a.more_events; },
		more_events_title: function ( a ) { return void 0 === a.more_events || !! a.more_events; },
		hero_session: function ( a ) { return 'session' === a.hero_presentation; },
		event: function ( a ) { return void 0 === a.event_source || 'manual' === a.event_source; }
	};

	// The Event Landing Page's canonical sections, for the order control.
	var sectionLabels = {
		hero: __( 'Hero', 'emailexpert-events' ),
		status: __( 'Status notice', 'emailexpert-events' ),
		stats: __( 'Event stats', 'emailexpert-events' ),
		intro: __( 'Introduction', 'emailexpert-events' ),
		sessions: __( 'Featured sessions', 'emailexpert-events' ),
		speakers: __( 'Speakers', 'emailexpert-events' ),
		schedule: __( 'Schedule', 'emailexpert-events' ),
		tickets: __( 'Tickets', 'emailexpert-events' ),
		venue: __( 'Venue', 'emailexpert-events' ),
		sponsors: __( 'Sponsors', 'emailexpert-events' ),
		replays: __( 'Replays', 'emailexpert-events' ),
		more_events: __( 'More events', 'emailexpert-events' ),
		final_cta: __( 'Final call to action', 'emailexpert-events' )
	};

	var allSections = Object.keys( sectionLabels );

	/**
	 * The section-order control: every section with an enable checkbox and
	 * accessible move up/down buttons. The value is the CSV of enabled
	 * sections, in order (the same canonical attribute the shortcode takes).
	 */
	function sectionOrderControl( key, props ) {
		var value = ( props.attributes[ key ] || '' ).split( ',' ).map( function ( s ) {
			return s.trim();
		} ).filter( function ( s ) {
			return -1 !== allSections.indexOf( s );
		} );

		var disabled = allSections.filter( function ( s ) {
			return -1 === value.indexOf( s );
		} );

		function update( next ) {
			var change = {};
			change[ key ] = next.join( ',' );
			props.setAttributes( change );
		}

		var rows = value.map( function ( section, index ) {
			return el(
				'div',
				{ key: section, style: { display: 'flex', alignItems: 'center', gap: '4px', marginBottom: '2px' } },
				el( CheckboxControl, {
					__nextHasNoMarginBottom: true,
					checked: true,
					label: sectionLabels[ section ],
					onChange: function () {
						update( value.filter( function ( s ) {
							return s !== section;
						} ) );
					}
				} ),
				el( Button, {
					size: 'small',
					icon: 'arrow-up-alt2',
					label: __( 'Move up:', 'emailexpert-events' ) + ' ' + sectionLabels[ section ],
					disabled: 0 === index,
					onClick: function () {
						var next = value.slice();
						next.splice( index - 1, 0, next.splice( index, 1 )[ 0 ] );
						update( next );
					}
				} ),
				el( Button, {
					size: 'small',
					icon: 'arrow-down-alt2',
					label: __( 'Move down:', 'emailexpert-events' ) + ' ' + sectionLabels[ section ],
					disabled: index === value.length - 1,
					onClick: function () {
						var next = value.slice();
						next.splice( index + 1, 0, next.splice( index, 1 )[ 0 ] );
						update( next );
					}
				} )
			);
		} );

		var off = disabled.map( function ( section ) {
			return el( CheckboxControl, {
				key: section,
				__nextHasNoMarginBottom: true,
				checked: false,
				label: sectionLabels[ section ],
				onChange: function () {
					update( value.concat( [ section ] ) );
				}
			} );
		} );

		return el(
			'div',
			{ key: key, className: 'eex-section-order' },
			rows,
			off.length ? el( 'p', { style: { margin: '8px 0 4px', color: '#757575' } }, __( 'Hidden sections', 'emailexpert-events' ) ) : null,
			off
		);
	}

	function controlFor( name, key, spec, props ) {
		var isNumber = 'integer' === spec.type;
		var label = spec.label || labels[ key ] || key;

		if ( conditions[ key ] && ! conditions[ key ]( props.attributes ) ) {
			return null;
		}

		if ( 'sections' === key && 'event-landing' === name ) {
			return sectionOrderControl( key, props );
		}

		// Enum attributes: a select built from the schema's
		// (pre-translated) options.
		if ( spec.options ) {
			return el( SelectControl, {
				key: key,
				label: label,
				help: spec.description || undefined,
				value: props.attributes[ key ],
				options: Object.keys( spec.options ).map( function ( value ) {
					return { value: value, label: spec.options[ value ] };
				} ),
				onChange: function ( value ) {
					var update = {};
					update[ key ] = value;
					props.setAttributes( update );
				}
			} );
		}

		// Boolean flags: a toggle storing 1/0.
		if ( spec.flag ) {
			return el( ToggleControl, {
				key: key,
				label: label,
				help: spec.description || undefined,
				checked: !! props.attributes[ key ],
				onChange: function ( value ) {
					var update = {};
					update[ key ] = value ? 1 : 0;
					props.setAttributes( update );
				}
			} );
		}

		// The preview timestamp gets a native picker where the browser
		// offers one; the server parses the value either way.
		var inputType = 'preview_at' === key || 'pin_until' === key ? 'datetime-local' : ( isNumber ? 'number' : 'text' );

		return el( TextControl, {
			key: key,
			label: label,
			help: spec.description || undefined,
			type: inputType,
			value: props.attributes[ key ],
			onChange: function ( value ) {
				var update = {};
				update[ key ] = isNumber ? parseInt( value, 10 ) || 0 : value;
				props.setAttributes( update );
			}
		} );
	}

	Object.keys( window.eexBlocks.definitions ).forEach( function ( name ) {
		var definition = window.eexBlocks.definitions[ name ];

		registerBlockType( 'eex/' + name, {
			title: definition.title,
			category: 'emailexpert-events',
			icon: 'calendar-alt',

			edit: function ( props ) {
				var groups = {};
				var order = [];

				Object.keys( definition.atts ).forEach( function ( key ) {
					var spec = definition.atts[ key ];
					var group = spec.group || definition.title;

					if ( ! groups[ group ] ) {
						groups[ group ] = [];
						order.push( group );
					}

					var control = controlFor( name, key, spec, props );
					if ( control ) {
						groups[ group ].push( control );
					}
				} );

				var panels = order.map( function ( group, index ) {
					return el(
						PanelBody,
						{ key: group, title: group, initialOpen: 0 === index },
						groups[ group ]
					);
				} );

				return el(
					'div',
					null,
					el( InspectorControls, null, panels ),
					el( ServerSideRender, {
						block: 'eex/' + name,
						attributes: props.attributes
					} )
				);
			},

			// Dynamic block: nothing stored in post content.
			save: function () {
				return null;
			}
		} );
	} );
}( window.wp ) );
