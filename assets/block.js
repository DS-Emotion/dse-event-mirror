( function ( blocks, element, components ) {
	var el = element.createElement;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;

	var events = ( window.EVMR_BLOCK && window.EVMR_BLOCK.events ) || [];
	var options = [ { label: '— Select an event —', value: 0 } ].concat(
		events.map( function ( ev ) {
			return {
				label: ev.title + ( ev.date ? ' (' + ev.date + ')' : '' ),
				value: ev.id
			};
		} )
	);

	blocks.registerBlockType( 'event-mirror/card', {
		title: 'Event Mirror Card',
		description: 'Show a single mirrored Eventbrite event.',
		icon: 'calendar-alt',
		category: 'widgets',
		attributes: {
			postId: { type: 'number', default: 0 },
			cta: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var selected = events.filter( function ( ev ) { return ev.id === a.postId; } )[ 0 ];

			return el(
				'div',
				{ style: { padding: '12px', border: '1px solid #dcdcde', borderRadius: '4px' } },
				el( 'strong', null, 'Event Mirror — single event card' ),
				el( SelectControl, {
					label: 'Event',
					value: a.postId,
					options: options,
					onChange: function ( v ) { props.setAttributes( { postId: parseInt( v, 10 ) || 0 } ); }
				} ),
				el( TextControl, {
					label: 'Custom button text (optional)',
					value: a.cta,
					placeholder: 'Uses the default from settings',
					onChange: function ( v ) { props.setAttributes( { cta: v } ); }
				} ),
				selected
					? el( 'p', { style: { margin: '8px 0 0', opacity: 0.75 } }, 'Showing: ' + selected.title )
					: el( 'p', { style: { margin: '8px 0 0', opacity: 0.6 } }, 'Pick an event to display.' )
			);
		},
		save: function () { return null; } // Dynamic: rendered by PHP.
	} );
} )( window.wp.blocks, window.wp.element, window.wp.components );
