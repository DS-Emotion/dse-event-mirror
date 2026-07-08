<?php
/**
 * Display layer: [event_mirror] grid (with columns option) and
 * [event_mirror_card] single card. Cards use theme styles; only minimal
 * structural layout CSS is inlined. Status "eyebrow" shows Cancelled/Ended and
 * the CTA is hidden for those.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the event display shortcodes.
 */
class EVMR_Shortcode {

	public function hooks() {
		add_shortcode( 'event_mirror', array( $this, 'render_grid' ) );
		add_shortcode( 'event_mirror_card', array( $this, 'render_single' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register the (tiny) card stylesheet; enqueued only when actually rendered.
	 */
	public function register_assets() {
		wp_register_style( 'event-mirror', EVMR_URL . 'assets/event-mirror.css', array(), EVMR_VERSION );
	}

	/**
	 * [event_mirror] — grid of upcoming events.
	 */
	public function render_grid( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 12,
				'upcoming' => 'yes',
				'columns'  => 'auto', // 2 | 3 | auto (responsive).
				'category' => '',     // evmr_category slug(s), comma-separated.
				'tag'      => '',     // evmr_tag slug(s), comma-separated.
			),
			$atts,
			'event_mirror'
		);

		$args = array(
			'post_type'      => EVMR_POST_TYPE,
			'posts_per_page' => (int) $atts['limit'],
			'meta_key'       => '_evmr_start_utc',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
		);
		$tax = self::tax_clauses( $atts['category'], $atts['tag'] );
		if ( $tax ) {
			$args['tax_query'] = $tax;
		}
		if ( 'yes' === $atts['upcoming'] ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_evmr_start_utc',
					'value'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'compare' => '>=',
					'type'    => 'CHAR',
				),
			);
		}

		$args  = apply_filters( 'evmr_display_query_args', $args, $atts );
		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '<p class="evmr-empty">' . esc_html__( 'No upcoming events.', 'event-mirror' ) . '</p>';
		}

		wp_enqueue_style( 'event-mirror' );
		$cta = $this->default_cta();

		ob_start();
		$col = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $atts['columns'] ) );
		printf(
			'<div class="evmr-grid evmr-grid--cols-%s" style="%s">',
			esc_attr( $col ),
			esc_attr( $this->grid_style() )
		);
		while ( $query->have_posts() ) {
			$query->the_post();
			echo self::card_html( get_the_ID(), $cta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * [event_mirror_card event_id="123" cta="..."] — one event by post ID.
	 */
	public function render_single( $atts ) {
		$atts = shortcode_atts(
			array(
				'event_id' => 0,
				'cta'      => '',
			),
			$atts,
			'event_mirror_card'
		);

		$post_id = (int) $atts['event_id'];
		if ( ! $post_id || EVMR_POST_TYPE !== get_post_type( $post_id ) ) {
			// Front-end stays silent; editors get a hint so a mistyped ID is
			// easy to spot instead of the card just vanishing.
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="evmr-notice">' . sprintf(
					/* translators: %s: the event_id supplied to the shortcode. */
					esc_html__( 'Event Mirror: no mirrored event found for event_id "%s". (This note is only visible to editors.)', 'event-mirror' ),
					esc_html( (string) $atts['event_id'] )
				) . '</p>';
			}
			return '';
		}
		wp_enqueue_style( 'event-mirror' );
		$cta = '' !== $atts['cta'] ? $atts['cta'] : $this->default_cta();

		return '<div class="evmr-grid" style="' . esc_attr( $this->grid_style() ) . '">'
			. self::card_html( $post_id, $cta, 'evmr-card--horizontal' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '</div>';
	}

	/**
	 * Build a tax_query from optional category and tag slug strings.
	 *
	 * @param string $category Comma-separated evmr_category slugs.
	 * @param string $tag      Comma-separated evmr_tag slugs.
	 * @return array Empty array if neither set.
	 */
	public static function tax_clauses( $category = '', $tag = '' ) {
		$clauses = array();
		if ( '' !== $category ) {
			$clauses[] = array(
				'taxonomy' => EVMR_CPT::TAXONOMY,
				'field'    => 'slug',
				'terms'    => array_map( 'trim', explode( ',', $category ) ),
			);
		}
		if ( '' !== $tag ) {
			$clauses[] = array(
				'taxonomy' => EVMR_CPT::TAG_TAXONOMY,
				'field'    => 'slug',
				'terms'    => array_map( 'trim', explode( ',', $tag ) ),
			);
		}
		if ( count( $clauses ) > 1 ) {
			$clauses['relation'] = 'AND';
		}
		return $clauses;
	}

	/**
	 * Default CTA from settings.
	 */
	private function default_cta() {
		$settings = get_option( EVMR_OPTION, array() );
		return ( isset( $settings['cta_text'] ) && '' !== $settings['cta_text'] )
			? $settings['cta_text']
			: __( 'Get tickets', 'event-mirror' );
	}

	/**
	 * Minimal grid layout. Only display + gap are inlined; the column count is
	 * left to the stylesheet (keyed off the evmr-grid--cols-* class) so it can
	 * collapse to a single column on small screens via media queries — an inline
	 * grid-template-columns could not be overridden responsively.
	 */
	private function grid_style() {
		return 'display:grid;gap:2.5rem 1.5rem;';
	}

	/**
	 * Render a single event card. Public + static so the Gutenberg block reuses it.
	 * Uses theme classes (wp-element-button) for the CTA; no custom card CSS.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $cta     CTA label.
	 * @return string
	 */
	public static function card_html( $post_id, $cta, $extra_class = '' ) {
		$start  = get_post_meta( $post_id, '_evmr_start_utc', true );
		$url    = get_post_meta( $post_id, '_evmr_url', true );
		$venue  = get_post_meta( $post_id, '_evmr_venue', true );
		$image  = get_post_meta( $post_id, '_evmr_image', true );
		$status = get_post_meta( $post_id, '_evmr_status', true );

		$eyebrow = self::status_label( $status );
		$is_dead = in_array( $status, array( 'canceled', 'cancelled', 'ended', 'completed' ), true );

		// Description only on the featured (horizontal) card, capped at ~40 words.
		$is_featured = ( false !== strpos( $extra_class, 'evmr-card--horizontal' ) );
		$show_desc   = $is_featured;
		$desc        = $show_desc
			? wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 40, '…' )
			: '';

		// Grid cards hide the CTA for cancelled/ended; a featured card always
		// shows it (you chose to highlight that event).
		$show_cta = $url && ( ! $is_dead || $is_featured );

		$when = $start
			? mysql2date(
				get_option( 'date_format' ) . ' g:i a',
				get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $start ) )
			)
			: '';

		ob_start();
		?>
		<article class="evmr-card <?php echo esc_attr( $extra_class ); ?>">
			<?php if ( $image ) : ?>
				<img class="evmr-card__image" src="<?php echo esc_url( $image ); ?>" alt="" />
			<?php endif; ?>
			<div class="evmr-card__body">
				<?php if ( $eyebrow ) : ?>
					<p class="evmr-card__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<?php endif; ?>
				<h3 class="evmr-card__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
				<?php if ( $when ) : ?>
					<p class="evmr-card__date"><?php echo esc_html( $when ); ?></p>
				<?php endif; ?>
				<?php if ( $venue ) : ?>
					<p class="evmr-card__venue"><?php echo esc_html( $venue ); ?></p>
				<?php endif; ?>
				<?php if ( $desc ) : ?>
					<p class="evmr-card__desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
				<?php if ( $show_cta ) : ?>
					<a class="evmr-card__cta wp-element-button button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cta ); ?></a>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Map an Eventbrite status to a display label, or '' to show nothing.
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case 'canceled':
			case 'cancelled':
				return __( 'Cancelled', 'event-mirror' );
			case 'ended':
			case 'completed':
				return __( 'Ended', 'event-mirror' );
			default:
				return '';
		}
	}
}
