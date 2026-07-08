( function ( blocks, element, components ) {
	var el = element.createElement;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;

	blocks.registerBlockType( 'event-mirror/grid', {
		title: 'Events (grid)',
		description: 'A responsive grid of mirrored Eventbrite events.',
		icon: 'grid-view',
		category: 'widgets',
		attributes: {
			columns: { type: 'string', default: 'auto' },
			limit: { type: 'number', default: 12 },
			upcoming: { type: 'boolean', default: true },
			category: { type: 'string', default: '' },
			tag: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ style: { padding: '12px', border: '1px solid #dcdcde', borderRadius: '4px' } },
				el( 'strong', null, 'Event Mirror — events grid' ),
				el( SelectControl, {
					label: 'Columns',
					value: a.columns,
					options: [
						{ label: 'Auto (responsive)', value: 'auto' },
						{ label: '2 columns', value: '2' },
						{ label: '3 columns', value: '3' }
					],
					onChange: function ( v ) { props.setAttributes( { columns: v } ); }
				} ),
				el( TextControl, {
					label: 'Number of events',
					type: 'number',
					value: a.limit,
					onChange: function ( v ) { props.setAttributes( { limit: parseInt( v, 10 ) || 0 } ); }
				} ),
				el( ToggleControl, {
					label: 'Upcoming events only',
					checked: a.upcoming,
					onChange: function ( v ) { props.setAttributes( { upcoming: !! v } ); }
				} ),
				el( TextControl, {
					label: 'Category slug (optional)',
					value: a.category,
					onChange: function ( v ) { props.setAttributes( { category: v } ); }
				} ),
				el( TextControl, {
					label: 'Tag slug (optional)',
					value: a.tag,
					onChange: function ( v ) { props.setAttributes( { tag: v } ); }
				} ),
				el( 'p', { style: { margin: '8px 0 0', opacity: 0.6 } }, 'The grid renders on the published page.' )
			);
		},
		save: function () { return null; } // Dynamic: rendered by PHP.
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components );
