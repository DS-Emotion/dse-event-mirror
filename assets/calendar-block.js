( function ( blocks, element, components ) {
	var el = element.createElement;
	var SelectControl = components.SelectControl;

	var cats = ( window.EVMR_CAL && window.EVMR_CAL.categories ) || [];
	var tags = ( window.EVMR_CAL && window.EVMR_CAL.tags ) || [];
	var catOpts = [ { label: 'All categories', value: '' } ].concat( cats );
	var tagOpts = [ { label: 'All tags', value: '' } ].concat( tags );

	blocks.registerBlockType( 'event-mirror/calendar', {
		title: 'Event Calendar',
		description: 'Month calendar of your mirrored events.',
		icon: 'calendar',
		category: 'widgets',
		attributes: {
			category: { type: 'string', default: '' },
			tag: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ style: { padding: '12px', border: '1px solid #dcdcde', borderRadius: '4px' } },
				el( 'strong', null, 'Event Mirror — month calendar' ),
				el( SelectControl, {
					label: 'Category',
					value: a.category,
					options: catOpts,
					onChange: function ( v ) { props.setAttributes( { category: v } ); }
				} ),
				el( SelectControl, {
					label: 'Tag',
					value: a.tag,
					options: tagOpts,
					onChange: function ( v ) { props.setAttributes( { tag: v } ); }
				} ),
				el( 'p', { style: { margin: '6px 0 0', opacity: 0.7 } }, 'Shows a month grid of events on the published page.' )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components );
