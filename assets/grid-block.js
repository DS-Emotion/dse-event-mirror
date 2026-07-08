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
			layout: { type: 'string', default: 'grid' },
			columns: { type: 'string', default: 'auto' },
			limit: { type: 'number', default: 12 },
			upcoming: { type: 'boolean', default: true },
			category: { type: 'string', default: '' },
			tag: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var controls = [
				el( 'strong', { key: 'h' }, 'Event Mirror — events' ),
				el( SelectControl, {
					key: 'layout',
					label: 'Layout',
					value: a.layout,
					options: [
						{ label: 'Grid of cards', value: 'grid' },
						{ label: 'List (full-width rows)', value: 'list' }
					],
					onChange: function ( v ) { props.setAttributes( { layout: v } ); }
				} )
			];
			if ( a.layout !== 'list' ) {
				controls.push( el( SelectControl, {
					key: 'columns',
					label: 'Columns',
					value: a.columns,
					options: [
						{ label: 'Auto (responsive)', value: 'auto' },
						{ label: '2 columns', value: '2' },
						{ label: '3 columns', value: '3' }
					],
					onChange: function ( v ) { props.setAttributes( { columns: v } ); }
				} ) );
			}
			return el(
				'div',
				{ style: { padding: '12px', border: '1px solid #dcdcde', borderRadius: '4px' } },
				controls,
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
