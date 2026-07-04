/**
 * Editor registration for all emailexpert Events blocks. Plain JS (no build
 * step): every block is dynamic and previews through ServerSideRender, so the
 * editor shows the exact front-end output.
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

	Object.keys( window.eexBlocks.definitions ).forEach( function ( name ) {
		var definition = window.eexBlocks.definitions[ name ];

		registerBlockType( 'eex/' + name, {
			title: definition.title,
			category: 'emailexpert-events',
			icon: 'calendar-alt',

			edit: function ( props ) {
				var controls = Object.keys( definition.atts ).map( function ( key ) {
					var spec = definition.atts[ key ];
					var isNumber = 'integer' === spec.type;
					var label = spec.label || labels[ key ] || key;

					// Enum attributes: a select built from the schema's
					// (pre-translated) options.
					if ( spec.options ) {
						return el( SelectControl, {
							key: key,
							label: label,
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
							checked: !! props.attributes[ key ],
							onChange: function ( value ) {
								var update = {};
								update[ key ] = value ? 1 : 0;
								props.setAttributes( update );
							}
						} );
					}

					return el( TextControl, {
						key: key,
						label: label,
						type: isNumber ? 'number' : 'text',
						value: props.attributes[ key ],
						onChange: function ( value ) {
							var update = {};
							update[ key ] = isNumber ? parseInt( value, 10 ) || 0 : value;
							props.setAttributes( update );
						}
					} );
				} );

				return el(
					'div',
					null,
					el(
						InspectorControls,
						null,
						el( PanelBody, { title: definition.title, initialOpen: true }, controls )
					),
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
