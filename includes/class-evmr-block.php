<?php
/**
 * "Event Mirror Card" Gutenberg block: pick an event from a dropdown (title +
 * date) and optionally override the button text. Server-rendered via the shared
 * card markup, so it always matches the shortcode output.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the single-event block.
 */
class EVMR_Block {

	public function hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the block type and its editor script.
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'evmr-block',
			EVMR_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components' ),
			EVMR_VERSION,
			true
		);

		wp_localize_script( 'evmr-block', 'EVMR_BLOCK', array( 'events' => $this->event_options() ) );

		register_block_type(
			'event-mirror/card',
			array(
				'api_version'     => 2,
				'editor_script'   => 'evmr-block',
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'postId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'cta'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		// Events grid block — the block equivalent of the [event_mirror] shortcode.
		wp_register_script(
			'evmr-grid-block',
			EVMR_URL . 'assets/grid-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components' ),
			EVMR_VERSION,
			true
		);

		register_block_type(
			'event-mirror/grid',
			array(
				'api_version'     => 2,
				'editor_script'   => 'evmr-grid-block',
				'render_callback' => array( $this, 'render_grid' ),
				'attributes'      => array(
					'columns'  => array(
						'type'    => 'string',
						'default' => 'auto',
					),
					'limit'    => array(
						'type'    => 'number',
						'default' => 12,
					),
					'upcoming' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'category' => array(
						'type'    => 'string',
						'default' => '',
					),
					'tag'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Server render for the events grid block: hand off to the shared grid
	 * renderer so it matches the [event_mirror] shortcode exactly.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_grid( $attributes ) {
		$atts = array(
			'columns'  => isset( $attributes['columns'] ) ? $attributes['columns'] : 'auto',
			'limit'    => isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 12,
			'upcoming' => ( ! isset( $attributes['upcoming'] ) || $attributes['upcoming'] ) ? 'yes' : 'no',
			'category' => isset( $attributes['category'] ) ? $attributes['category'] : '',
			'tag'      => isset( $attributes['tag'] ) ? $attributes['tag'] : '',
		);

		$shortcode = evmr()->get( 'shortcode' );
		return $shortcode ? $shortcode->render_grid( $atts ) : '';
	}

	/**
	 * Build the dropdown list: every event with title + date for disambiguation.
	 *
	 * @return array
	 */
	private function event_options() {
		$out   = array();
		$query = new WP_Query(
			array(
				'post_type'      => EVMR_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'meta_value',
				'meta_key'       => '_evmr_start_utc',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $query->posts as $post ) {
			$start = get_post_meta( $post->ID, '_evmr_start_utc', true );
			$date  = $start
				? mysql2date( get_option( 'date_format' ), get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $start ) ) )
				: '';
			$out[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'date'  => $date,
			);
		}
		return $out;
	}

	/**
	 * Server render: reuse the shared card markup.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$post_id = isset( $attributes['postId'] ) ? (int) $attributes['postId'] : 0;
		if ( ! $post_id || EVMR_POST_TYPE !== get_post_type( $post_id ) ) {
			return '';
		}

		$cta = ( isset( $attributes['cta'] ) && '' !== $attributes['cta'] )
			? $attributes['cta']
			: ( get_option( EVMR_OPTION, array() )['cta_text'] ?? __( 'Get tickets', 'event-mirror' ) );

		wp_enqueue_style( 'event-mirror' );
		return '<div class="evmr-grid" style="display:grid;grid-template-columns:1fr;gap:1.5rem;">'
			. EVMR_Shortcode::card_html( $post_id, $cta, 'evmr-card--horizontal' )
			. '</div>';
	}
}
