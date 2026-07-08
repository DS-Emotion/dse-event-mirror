<?php
/**
 * "Event Calendar" block: a month grid with each event shown as a linked title
 * in its day cell, plus prev/next month navigation.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the month calendar block.
 */
class EVMR_Calendar {

	public function hooks() {
		add_action( 'init', array( $this, 'register' ) );
		add_shortcode( 'event_mirror_calendar', array( $this, 'shortcode' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
	}

	/**
	 * Register the month-navigation parameter as a known query var.
	 *
	 * Without this, WordPress's canonical redirect treats ?evmr_month as an
	 * unknown argument and strips it, so the prev/next arrows appear to do
	 * nothing. The param is the calendar's own contract, so the plugin owns it.
	 *
	 * @param array $vars Registered public query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = 'evmr_month';
		return $vars;
	}

	/**
	 * [event_mirror_calendar category="slug"] — same month grid as the block.
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'category' => '', 'tag' => '' ), $atts, 'event_mirror_calendar' );
		return $this->render( array( 'category' => $atts['category'], 'tag' => $atts['tag'] ) );
	}

	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'evmr-calendar-block',
			EVMR_URL . 'assets/calendar-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components' ),
			EVMR_VERSION,
			true
		);
		wp_localize_script(
			'evmr-calendar-block',
			'EVMR_CAL',
			array(
				'categories' => $this->term_options( EVMR_CPT::TAXONOMY ),
				'tags'       => $this->term_options( EVMR_CPT::TAG_TAXONOMY ),
			)
		);
		register_block_type(
			'event-mirror/calendar',
			array(
				'api_version'     => 2,
				'editor_script'   => 'evmr-calendar-block',
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
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
	 * Build {label,value} options from a taxonomy's terms (value = slug).
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return array
	 */
	private function term_options( $taxonomy ) {
		$out   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $term ) {
			$out[] = array(
				'label' => $term->name,
				'value' => $term->slug,
			);
		}
		return $out;
	}

	/**
	 * Render the calendar for the current (or navigated) month.
	 */
	public function render( $attributes = array() ) {
		wp_enqueue_style( 'event-mirror' );
		$category = isset( $attributes['category'] ) ? $attributes['category'] : '';
		$tag      = isset( $attributes['tag'] ) ? $attributes['tag'] : '';

		// Which month? ?evmr_month=YYYY-MM, else current. Read via get_query_var()
		// (now that it is registered) with a $_GET fallback for block previews.
		$req_raw = get_query_var( 'evmr_month' );
		if ( '' === $req_raw && isset( $_GET['evmr_month'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$req_raw = wp_unslash( $_GET['evmr_month'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$req  = preg_replace( '/[^0-9\-]/', '', (string) $req_raw );
		$base = ( $req && preg_match( '/^\d{4}-\d{2}$/', $req ) ) ? strtotime( $req . '-01' ) : current_time( 'timestamp' );

		$year      = (int) gmdate( 'Y', $base );
		$month     = (int) gmdate( 'n', $base );
		$first     = strtotime( sprintf( '%04d-%02d-01', $year, $month ) );
		$days      = (int) gmdate( 't', $first );
		$first_dow = (int) gmdate( 'w', $first ); // 0=Sun.
		$start_dow = (int) get_option( 'start_of_week', 0 );

		$events = $this->events_by_day( $year, $month, $category, $tag );

		// Navigation targets.
		$prev = gmdate( 'Y-m', strtotime( '-1 month', $first ) );
		$next = gmdate( 'Y-m', strtotime( '+1 month', $first ) );
		$base_url = remove_query_arg( 'evmr_month' );

		// Stable per-instance anchor so the prev/next reload lands back on the
		// calendar instead of the top of the page. Scoped by category/tag so
		// multiple calendars on one page each anchor to themselves.
		$cal_id = 'evmr-cal-' . substr( md5( $category . '|' . $tag ), 0, 6 );

		// Weekday labels ordered by start_of_week.
		$labels = array(
			__( 'Sun', 'event-mirror' ),
			__( 'Mon', 'event-mirror' ),
			__( 'Tue', 'event-mirror' ),
			__( 'Wed', 'event-mirror' ),
			__( 'Thu', 'event-mirror' ),
			__( 'Fri', 'event-mirror' ),
			__( 'Sat', 'event-mirror' ),
		);

		// Leading blank cells.
		$lead = ( $first_dow - $start_dow + 7 ) % 7;

		ob_start();
		?>
		<div class="evmr-calendar-wrap" id="<?php echo esc_attr( $cal_id ); ?>">
			<div class="evmr-calendar__nav">
				<a class="wp-element-button button" href="<?php echo esc_url( add_query_arg( 'evmr_month', $prev, $base_url ) . '#' . $cal_id ); ?>">&larr;</a>
				<strong><?php echo esc_html( date_i18n( 'F Y', $first ) ); ?></strong>
				<a class="wp-element-button button" href="<?php echo esc_url( add_query_arg( 'evmr_month', $next, $base_url ) . '#' . $cal_id ); ?>">&rarr;</a>
			</div>
			<table class="evmr-calendar">
				<thead><tr>
					<?php for ( $i = 0; $i < 7; $i++ ) : ?>
						<th><?php echo esc_html( $labels[ ( $start_dow + $i ) % 7 ] ); ?></th>
					<?php endfor; ?>
				</tr></thead>
				<tbody>
				<tr>
					<?php
					for ( $b = 0; $b < $lead; $b++ ) {
						echo '<td class="evmr-calendar__pad"></td>';
					}
					$col = $lead;
					for ( $d = 1; $d <= $days; $d++ ) {
						echo '<td>';
						printf( '<span class="evmr-calendar__daynum">%d</span>', $d );
						if ( ! empty( $events[ $d ] ) ) {
							foreach ( $events[ $d ] as $ev ) {
								if ( $ev['url'] ) {
									printf(
										'<a class="evmr-calendar__event" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
										esc_url( $ev['url'] ),
										esc_html( $ev['title'] )
									);
								} else {
									printf( '<span class="evmr-calendar__event">%s</span>', esc_html( $ev['title'] ) );
								}
							}
						}
						echo '</td>';
						$col++;
						if ( 0 === $col % 7 && $d < $days ) {
							echo '</tr><tr>';
						}
					}
					// Trailing blanks.
					while ( 0 !== $col % 7 ) {
						echo '<td class="evmr-calendar__pad"></td>';
						$col++;
					}
					?>
				</tr>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Map [ day_of_month => [ {title,url}, ... ] ] for a given month.
	 */
	private function events_by_day( $year, $month, $category = '', $tag = '' ) {
		$start = sprintf( '%04d-%02d-01T00:00:00Z', $year, $month );
		$end   = gmdate( 'Y-m-01\T00:00:00\Z', strtotime( sprintf( '%04d-%02d-01', $year, $month ) . ' +1 month' ) );

		$query_args = array(
			'post_type'      => EVMR_POST_TYPE,
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_evmr_start_utc',
					'value'   => array( $start, $end ),
					'compare' => 'BETWEEN',
					'type'    => 'CHAR',
				),
			),
		);
		$tax = EVMR_Shortcode::tax_clauses( $category, $tag );
		if ( $tax ) {
			$query_args['tax_query'] = $tax;
		}

		$query = new WP_Query( $query_args );

		$out = array();
		foreach ( $query->posts as $post ) {
			$s = get_post_meta( $post->ID, '_evmr_start_utc', true );
			if ( ! $s ) {
				continue;
			}
			$local = get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $s ) );
			$day   = (int) mysql2date( 'j', $local );
			$out[ $day ][] = array(
				'title' => get_the_title( $post ),
				'url'   => get_post_meta( $post->ID, '_evmr_url', true ),
			);
		}
		return $out;
	}
}
