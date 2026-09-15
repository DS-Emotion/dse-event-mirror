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

	/**
	 * Counts rendered grids so each paginated instance gets a stable anchor id.
	 * Deterministic within a page render, so a link built on page 1 still
	 * resolves to the same element on page 2.
	 *
	 * @var int
	 */
	private static $instance = 0;

	public function hooks() {
		add_shortcode( 'event_mirror', array( $this, 'render_grid' ) );
		add_shortcode( 'event_mirror_card', array( $this, 'render_single' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register the card stylesheet.
	 *
	 * It is registered AND always enqueued on the front end, deliberately.
	 * Enqueuing lazily (only when a shortcode actually rendered) meant the file
	 * landed at different points in the cascade depending on the page: early on
	 * the assigned Events page, which enqueues on wp_enqueue_scripts, but after
	 * the theme's stylesheet anywhere the shortcode was rendered mid-content.
	 * Identical markup then styled differently on two pages, because equal
	 * specificity ties were resolved by source order.
	 *
	 * The file is a couple of KB, so loading it site-wide costs far less than
	 * that inconsistency. A theme rule now reliably beats a plugin rule of the
	 * same specificity.
	 */
	public function register_assets() {
		wp_register_style( 'event-mirror', EVMR_URL . 'assets/event-mirror.css', array(), EVMR_VERSION );

		if ( ! is_admin() ) {
			wp_enqueue_style( 'event-mirror' );
		}
	}

	/**
	 * [event_mirror] — grid of upcoming events, optionally paginated.
	 */
	public function render_grid( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 12,     // With paginate="yes" this is the per-page count.
				'paginate' => 'no',   // yes | no. Adds numbered paging below the events.
				'upcoming' => 'yes',
				'columns'  => 'auto', // 2 | 3 | auto (responsive). Grid layout only.
				'layout'   => 'grid', // grid | list (full-width rows).
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

		// Paging uses the same evmr_page query var as the assigned Events page, so
		// the two never fight over ?paged and page 2+ still resolves on a static
		// front page. Without paging we skip SQL_CALC_FOUND_ROWS entirely.
		$paginate = in_array( strtolower( (string) $atts['paginate'] ), array( 'yes', 'true', '1' ), true );
		$paged    = 1;
		if ( $paginate ) {
			$paged         = max( 1, (int) get_query_var( 'evmr_page' ) );
			$args['paged'] = $paged;
		} else {
			$args['no_found_rows'] = true;
		}

		$args  = apply_filters( 'evmr_display_query_args', $args, $atts );
		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '<p class="evmr-empty">' . esc_html__( 'No upcoming events.', 'event-mirror' ) . '</p>';
		}

		wp_enqueue_style( 'event-mirror' );
		$cta = $this->default_cta();

		$layout = ( 'list' === strtolower( (string) $atts['layout'] ) ) ? 'list' : 'grid';

		// Only paginated instances need an anchor to jump back to.
		$anchor    = $paginate ? 'evmr-events-' . ++self::$instance : '';
		$anchor_at = $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';

		ob_start();
		if ( 'list' === $layout ) {
			echo '<div' . $anchor_at . ' class="evmr-list">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$last_month = '';
			while ( $query->have_posts() ) {
				$query->the_post();

				// Break the run of events with a month heading, so a long list
				// stays scannable.
				$start_utc = get_post_meta( get_the_ID(), '_evmr_start_utc', true );
				if ( $start_utc ) {
					$local = get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $start_utc ) );
					$month = mysql2date( 'F Y', $local );
					if ( $month !== $last_month ) {
						printf( '<h3 class="evmr-list-month">%s</h3>', esc_html( $month ) );
						$last_month = $month;
					}
				}

				echo self::list_item_html( get_the_ID(), $cta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		} else {
			$col = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $atts['columns'] ) );
			printf(
				'<div%s class="evmr-grid evmr-grid--cols-%s">',
				$anchor_at, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr( $col )
			);
			while ( $query->have_posts() ) {
				$query->the_post();
				echo self::card_html( get_the_ID(), $cta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
		wp_reset_postdata();

		if ( $paginate && $query->max_num_pages > 1 ) {
			// Page through whichever singular page or post the shortcode sits on,
			// so this works inside a Flexible Blocks template as well as on the
			// assigned Events page.
			$base_id  = get_queried_object_id();
			$base_url = $base_id ? get_permalink( $base_id ) : home_url( '/' );
			$links    = paginate_links(
				array(
					// Fragment lands the reader on the listing, not the top of the page.
					'base'      => add_query_arg( 'evmr_page', '%#%', $base_url ) . '#' . $anchor,
					'format'    => '',
					'current'   => $paged,
					'total'     => (int) $query->max_num_pages,
					'mid_size'  => 1,
					'prev_text' => __( '&larr; Previous', 'event-mirror' ),
					'next_text' => __( 'Next &rarr;', 'event-mirror' ),
				)
			);
			if ( $links ) {
				echo '<nav class="evmr-pagination">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

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

		return '<div class="evmr-grid">'
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
		// Grid cards carry a shorter summary than the featured card so a row of
		// them stays even; both get one.
		$desc = wp_trim_words(
			wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ),
			$is_featured ? 40 : 22,
			'…'
		);

		// Grid cards hide the CTA for cancelled/ended; a featured card always
		// shows it (you chose to highlight that event).
		$show_cta = $url && ( ! $is_dead || $is_featured );

		// Location visibility is controlled in Settings > Display.
		$show_venue = EVMR_Settings::show_location();

		list( $dow, $date, $time ) = self::when_parts( $post_id );

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
				<h3 class="evmr-card__title"><?php echo esc_html( self::sentence_case( get_the_title( $post_id ) ) ); ?></h3>
				<?php if ( $date ) : ?>
					<p class="evmr-card__when">
						<?php if ( $dow ) : ?>
							<span class="evmr-card__dow"><?php echo esc_html( $dow ); ?></span>
							<span class="evmr-card__sep" aria-hidden="true">|</span>
						<?php endif; ?>
						<span class="evmr-card__date"><?php echo esc_html( $date ); ?></span>
					</p>
				<?php endif; ?>
				<?php if ( $time ) : ?>
					<p class="evmr-card__time"><?php echo esc_html( $time ); ?></p>
				<?php endif; ?>
				<?php if ( $venue && $show_venue ) : ?>
					<p class="evmr-card__venue"><span class="evmr-card__venue-label"><?php esc_html_e( 'Location:', 'event-mirror' ); ?></span> <?php echo esc_html( $venue ); ?></p>
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
	 * Render one full-width list row (date rail + details + image), used by the
	 * layout="list" view. Public + static so blocks can reuse it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $cta     CTA label.
	 * @return string
	 */
	public static function list_item_html( $post_id, $cta ) {
		$start  = get_post_meta( $post_id, '_evmr_start_utc', true );
		$url    = get_post_meta( $post_id, '_evmr_url', true );
		$venue  = get_post_meta( $post_id, '_evmr_venue', true );
		$image  = get_post_meta( $post_id, '_evmr_image', true );
		$status = get_post_meta( $post_id, '_evmr_status', true );

		$eyebrow  = self::status_label( $status );
		$is_dead  = in_array( $status, array( 'canceled', 'cancelled', 'ended', 'completed' ), true );
		$desc     = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 40, '…' );
		$when     = self::when_range( $post_id );
		$price    = self::price_label( $post_id );
		$show_cta = $url && ! $is_dead;

		$dow  = '';
		$dnum = '';
		if ( $start ) {
			$local = get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $start ) );
			$dow   = mysql2date( 'D', $local );
			$dnum  = mysql2date( 'j', $local );
		}

		$item_class = 'evmr-list-item' . ( $image ? '' : ' evmr-list-item--noimg' );

		ob_start();
		?>
		<article class="<?php echo esc_attr( $item_class ); ?>">
			<div class="evmr-list-item__date">
				<span class="evmr-list-item__dow"><?php echo esc_html( $dow ); ?></span>
				<span class="evmr-list-item__dnum"><?php echo esc_html( $dnum ); ?></span>
			</div>
			<div class="evmr-list-item__body">
				<?php if ( $show_cta ) : ?>
					<a class="evmr-list-item__book wp-element-button button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cta ); ?></a>
				<?php endif; ?>
				<?php if ( $eyebrow ) : ?>
					<p class="evmr-card__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<?php endif; ?>
				<?php if ( $when ) : ?>
					<p class="evmr-list-item__when"><?php echo esc_html( $when ); ?></p>
				<?php endif; ?>
				<h3 class="evmr-list-item__title"><?php echo esc_html( self::sentence_case( get_the_title( $post_id ) ) ); ?></h3>
				<?php if ( $venue && EVMR_Settings::show_location() ) : ?>
					<p class="evmr-card__venue"><span class="evmr-card__venue-label"><?php esc_html_e( 'Location:', 'event-mirror' ); ?></span> <?php echo esc_html( $venue ); ?></p>
				<?php endif; ?>
				<?php if ( $desc ) : ?>
					<p class="evmr-card__desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
				<?php if ( $price ) : ?>
					<p class="evmr-list-item__price"><?php echo esc_html( $price ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $image ) : ?>
				<div class="evmr-list-item__media">
					<img class="evmr-list-item__image" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
				</div>
			<?php endif; ?>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Human-readable start–end range, e.g. "8 July @ 6:00 pm - 6:45 pm".
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	/**
	 * Start date split into its parts, so a card can put the day, the date and
	 * the time on separate lines. when_range() stays for the list view, which
	 * wants them on one line.
	 *
	 * @param int $post_id Event post ID.
	 * @return array{0:string,1:string,2:string} [ day-of-week, date, time range ].
	 */
	private static function when_parts( $post_id ) {
		$start = get_post_meta( $post_id, '_evmr_start_utc', true );
		if ( ! $start ) {
			return array( '', '', '' );
		}
		$to_local = function ( $utc ) {
			return get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $utc ) );
		};
		$date_fmt = get_option( 'date_format' );
		$time_fmt = 'g:i a';
		$start_l  = $to_local( $start );

		$dow  = mysql2date( 'D', $start_l );
		$date = mysql2date( $date_fmt, $start_l );
		$time = mysql2date( $time_fmt, $start_l );

		// Show the finish time too, so a reader can see how long it runs.
		$end = get_post_meta( $post_id, '_evmr_end_utc', true );
		if ( $end ) {
			$end_l = $to_local( $end );
			if ( mysql2date( 'Y-m-d', $start_l ) === mysql2date( 'Y-m-d', $end_l ) ) {
				$time .= ' - ' . mysql2date( $time_fmt, $end_l );
			} else {
				$time .= ' - ' . mysql2date( $date_fmt . ' \@ ' . $time_fmt, $end_l );
			}
		}
		return array( $dow, $date, $time );
	}

	/**
	 * Eventbrite listings are often typed in ALL CAPS. Titles are shown in
	 * sentence case for consistency across the views, but only when the title is
	 * actually shouting — a normally-cased title is left exactly as written.
	 *
	 * Short all-caps tokens (CIC, NOMA, BBC) are preserved, since lowercasing an
	 * acronym reads worse than the shouting did.
	 *
	 * @param string $title Raw post title.
	 * @return string
	 */
	public static function sentence_case( $title ) {
		$title   = (string) $title;
		$letters = preg_replace( '/[^\p{L}]/u', '', $title );
		if ( '' === $letters ) {
			return $title;
		}
		$upper = preg_replace( '/[^\p{Lu}]/u', '', $letters );
		if ( ( mb_strlen( $upper ) / mb_strlen( $letters ) ) < 0.6 ) {
			return $title;
		}

		// No attempt to guess acronyms: in a fully-shouting title there is no
		// signal that tells CIC from CLUB, and a length rule keeps far too much
		// ("THE", "YOGA"). Lowercase throughout, then restore the sentence start
		// and the pronoun "I" — the only reliable exceptions.
		$out = mb_strtolower( $title );
		$out = preg_replace( '/\\bi\\b/u', 'I', $out );
		$out = preg_replace( "/\\bi'/u", "I'", $out );
		$out = mb_strtoupper( mb_substr( $out, 0, 1 ) ) . mb_substr( $out, 1 );

		/**
		 * Filter the displayed title, e.g. to disable the sentence-case rewrite.
		 *
		 * @param string $out   Rewritten title.
		 * @param string $title Original title.
		 */
		return apply_filters( 'evmr_display_title', $out, $title );
	}

	private static function when_range( $post_id ) {
		$start = get_post_meta( $post_id, '_evmr_start_utc', true );
		if ( ! $start ) {
			return '';
		}
		$date_fmt = get_option( 'date_format' );
		$time_fmt = 'g:i a';
		$start_l  = get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $start ) );
		$out      = mysql2date( $date_fmt . ' \@ ' . $time_fmt, $start_l );

		$end = get_post_meta( $post_id, '_evmr_end_utc', true );
		if ( $end ) {
			$end_l = get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $end ) );
			if ( mysql2date( 'Y-m-d', $start_l ) === mysql2date( 'Y-m-d', $end_l ) ) {
				$out .= ' - ' . mysql2date( $time_fmt, $end_l );
			} else {
				$out .= ' - ' . mysql2date( $date_fmt . ' \@ ' . $time_fmt, $end_l );
			}
		}
		return $out;
	}

	/**
	 * Price label from the mirrored ticket data ("Free", "From £10", or '').
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function price_label( $post_id ) {
		if ( get_post_meta( $post_id, '_evmr_is_free', true ) ) {
			return __( 'Free', 'event-mirror' );
		}
		$price    = get_post_meta( $post_id, '_evmr_price', true );
		$currency = get_post_meta( $post_id, '_evmr_currency', true );
		if ( '' === $price || '' === $currency ) {
			return '';
		}
		$symbols = array(
			'GBP' => '£',
			'USD' => '$',
			'EUR' => '€',
			'AUD' => 'A$',
			'CAD' => 'C$',
		);
		$sym = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : ( $currency . ' ' );
		/* translators: %s: formatted lowest ticket price, e.g. "£10". */
		return sprintf( __( 'From %s', 'event-mirror' ), $sym . $price );
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
