<?php
/**
 * "How to Display" — an in-admin guide, styled in the DSE 2026 house style.
 *
 * Shows every way to place events on the site (grid, calendar, single card),
 * each with a wireframe, a copy-paste shortcode, and the block equivalent, so a
 * non-technical editor can self-serve. Also enqueues the shared DSE admin styles
 * across all Event Mirror screens.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the "How to Display" admin page.
 */
class EVMR_Help {

	const PAGE = 'evmr-help';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the "How to Display" submenu under the Event Mirror menu.
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . EVMR_POST_TYPE,
			__( 'How to Display', 'event-mirror' ),
			__( 'How to Display', 'event-mirror' ),
			'edit_posts',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load the DSE admin stylesheet on all Event Mirror admin screens.
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$is_evmr = ( isset( $screen->post_type ) && EVMR_POST_TYPE === $screen->post_type )
			|| false !== strpos( (string) $screen->id, 'evmr' );
		if ( ! $is_evmr ) {
			return;
		}
		wp_enqueue_style( 'evmr-admin-dse', EVMR_URL . 'assets/admin-dse.css', array(), EVMR_VERSION );
	}

	/**
	 * Render the guide.
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="wrap evmr-dse">
			<div class="evmr-topbar">
				<span class="evmr-topbar__mark">EM</span>
				<h1 class="evmr-topbar__title"><?php esc_html_e( 'Event Mirror · How to Display', 'event-mirror' ); ?></h1>
				<span class="evmr-topbar__spacer"></span>
				<span class="evmr-topbar__tag"><?php esc_html_e( 'DSE 2026', 'event-mirror' ); ?></span>
			</div>

			<div class="evmr-canvas">
				<div class="evmr-sheet">
					<h1><?php esc_html_e( 'Placing events on your site', 'event-mirror' ); ?></h1>
					<p class="evmr-lead"><?php esc_html_e( 'Your main events listing lives on your Events page — set it under Sync and Settings → Events page. The views below are for signposting events elsewhere (a homepage teaser, a sidebar, a landing page), using a shortcode in the classic editor or the matching block.', 'event-mirror' ); ?></p>

					<div class="evmr-grid-2">

						<?php
						// ---- Grid ----
						$this->card_open( __( 'Listing', 'event-mirror' ), __( 'Events grid', 'event-mirror' ), __( 'A responsive grid of upcoming events. Best for a "What\'s On" page.', 'event-mirror' ) );
						echo $this->wire_grid_desktop(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						$this->snippet( '[event_mirror columns="3"]' );
						?>
						<table class="evmr-table">
							<thead><tr><th><?php esc_html_e( 'Option', 'event-mirror' ); ?></th><th><?php esc_html_e( 'Values', 'event-mirror' ); ?></th><th><?php esc_html_e( 'Default', 'event-mirror' ); ?></th></tr></thead>
							<tbody>
								<tr><td><code>columns</code></td><td>2, 3, auto</td><td>auto</td></tr>
								<tr><td><code>limit</code></td><td><?php esc_html_e( 'any number', 'event-mirror' ); ?></td><td>12</td></tr>
								<tr><td><code>upcoming</code></td><td>yes, no</td><td>yes</td></tr>
								<tr><td><code>category</code></td><td><?php esc_html_e( 'category slug(s)', 'event-mirror' ); ?></td><td>—</td></tr>
								<tr><td><code>tag</code></td><td><?php esc_html_e( 'tag slug(s)', 'event-mirror' ); ?></td><td>—</td></tr>
							</tbody>
						</table>
						<p class="evmr-node__desc" style="margin-top:12px;"><?php esc_html_e( 'Block editor:', 'event-mirror' ); ?> <span class="evmr-chip"><?php esc_html_e( 'Events (grid)', 'event-mirror' ); ?></span></p>
						<?php $this->card_close(); ?>

						<?php
						// ---- List ----
						$this->card_open( __( 'Listing', 'event-mirror' ), __( 'Events list', 'event-mirror' ), __( 'Full-width rows, one event after another — date rail, details, Book Now and image. Great for a detailed What\'s On page.', 'event-mirror' ) );
						echo $this->wire_list(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						$this->snippet( '[event_mirror layout="list"]' );
						echo '<p class="evmr-node__desc">' . esc_html__( 'Same filters as the grid (limit, category, tag). Block editor:', 'event-mirror' ) . ' <span class="evmr-chip">' . esc_html__( 'Events (grid) → Layout: List', 'event-mirror' ) . '</span></p>';
						$this->card_close();
						?>

						<?php
						// ---- Calendar ----
						$this->card_open( __( 'Calendar', 'event-mirror' ), __( 'Month calendar', 'event-mirror' ), __( 'A month grid with events in their day cells and prev / next navigation.', 'event-mirror' ) );
						echo $this->wire_calendar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						$this->snippet( '[event_mirror_calendar]' );
						echo '<p class="evmr-node__desc">' . esc_html__( 'Optional: category="slug" or tag="slug" to filter.', 'event-mirror' ) . '</p>';
						echo '<p class="evmr-node__desc">' . esc_html__( 'Block editor:', 'event-mirror' ) . ' <span class="evmr-chip">' . esc_html__( 'Event Calendar', 'event-mirror' ) . '</span></p>';
						$this->card_close();
						?>

						<?php
						// ---- Single card ----
						$this->card_open( __( 'Featured', 'event-mirror' ), __( 'Single event card', 'event-mirror' ), __( 'One event, featured large (image left, details right). Use the event\'s ID.', 'event-mirror' ) );
						echo $this->wire_single(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						$this->snippet( '[event_mirror_card event_id="123"]' );
						echo '<p class="evmr-node__desc">' . esc_html__( 'Find the ID in the All Events list. Block editor:', 'event-mirror' ) . ' <span class="evmr-chip">' . esc_html__( 'Event Mirror Card', 'event-mirror' ) . '</span></p>';
						$this->card_close();
						?>

					</div>

					<h2 style="margin-top:28px;"><?php esc_html_e( 'Classic editor vs. blocks', 'event-mirror' ); ?></h2>
					<p class="evmr-node__desc" style="max-width:64ch;">
						<?php esc_html_e( 'In the classic editor, paste any shortcode above straight into the content. In the block editor, add the matching block (search "Event") — or use a Shortcode block and paste the same snippet. Both render identically on the front end.', 'event-mirror' ); ?>
					</p>
				</div>
			</div>
		</div>

		<script>
		( function () {
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.evmr-copy' );
				if ( ! btn ) { return; }
				var text = btn.getAttribute( 'data-copy' ) || '';
				var done = function () {
					var original = btn.textContent;
					btn.classList.add( 'is-done' );
					btn.textContent = '<?php echo esc_js( __( 'Copied', 'event-mirror' ) ); ?>';
					setTimeout( function () { btn.classList.remove( 'is-done' ); btn.textContent = original; }, 1400 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done, done );
				} else {
					var ta = document.createElement( 'textarea' );
					ta.value = text; document.body.appendChild( ta ); ta.select();
					try { document.execCommand( 'copy' ); } catch ( err ) {}
					document.body.removeChild( ta ); done();
				}
			} );
		} )();
		</script>
		<?php
	}

	/* ---- Small render helpers ---- */

	private function card_open( $eyebrow, $title, $desc ) {
		echo '<div class="evmr-node">';
		echo '<p class="evmr-eyebrow">' . esc_html( $eyebrow ) . '</p>';
		echo '<h3 class="evmr-node__title">' . esc_html( $title ) . '</h3>';
		echo '<p class="evmr-node__desc">' . esc_html( $desc ) . '</p>';
	}

	private function card_close() {
		echo '</div>';
	}

	private function snippet( $code ) {
		printf(
			'<div class="evmr-code"><code>%s</code><button type="button" class="evmr-copy" data-copy="%s">%s</button></div>',
			esc_html( $code ),
			esc_attr( $code ),
			esc_html__( 'Copy', 'event-mirror' )
		);
	}

	/* ---- Wireframe SVGs (styled by admin-dse.css) ---- */

	private function wire_grid_desktop() {
		$card = function ( $x ) {
			$w = 104;
			return '<rect x="' . $x . '" y="20" width="' . $w . '" height="150" rx="8" class="wf-card"/>'
				. '<rect x="' . ( $x + 8 ) . '" y="28" width="' . ( $w - 16 ) . '" height="52" rx="4" class="wf-img"/>'
				. '<rect x="' . ( $x + 8 ) . '" y="92" width="62" height="7" rx="3" class="wf-line"/>'
				. '<rect x="' . ( $x + 8 ) . '" y="104" width="46" height="7" rx="3" class="wf-line"/>'
				. '<rect x="' . ( $x + 8 ) . '" y="146" width="46" height="15" rx="7" class="wf-btn"/>';
		};
		return '<svg class="evmr-wire" viewBox="0 0 360 190" xmlns="http://www.w3.org/2000/svg">'
			. $card( 16 ) . $card( 132 ) . $card( 248 )
			. '</svg>';
	}

	private function wire_list() {
		$row = function ( $y ) {
			return '<rect x="8" y="' . $y . '" width="344" height="78" rx="8" class="wf-card"/>'
				. '<text x="32" y="' . ( $y + 34 ) . '" text-anchor="middle" class="wf-lbl">WED</text>'
				. '<text x="32" y="' . ( $y + 56 ) . '" text-anchor="middle" class="wf-lbl" font-size="16">8</text>'
				. '<line x1="58" y1="' . ( $y + 12 ) . '" x2="58" y2="' . ( $y + 66 ) . '" class="wf-grid"/>'
				. '<rect x="72" y="' . ( $y + 14 ) . '" width="120" height="13" rx="3" class="wf-btn"/>'
				. '<rect x="72" y="' . ( $y + 34 ) . '" width="84" height="6" rx="3" class="wf-line"/>'
				. '<rect x="72" y="' . ( $y + 46 ) . '" width="132" height="9" rx="3" class="wf-line"/>'
				. '<rect x="72" y="' . ( $y + 62 ) . '" width="58" height="6" rx="3" class="wf-line"/>'
				. '<rect x="250" y="' . ( $y + 10 ) . '" width="96" height="58" rx="4" class="wf-img"/>';
		};
		return '<svg class="evmr-wire" viewBox="0 0 360 190" xmlns="http://www.w3.org/2000/svg">'
			. $row( 12 ) . $row( 100 )
			. '</svg>';
	}

	private function wire_calendar() {
		$svg  = '<svg class="evmr-wire" viewBox="0 0 360 190" xmlns="http://www.w3.org/2000/svg">';
		$svg .= '<text x="16" y="24" class="wf-lbl">&#8592;</text>';
		$svg .= '<text x="164" y="24" class="wf-lbl">July 2026</text>';
		$svg .= '<text x="344" y="24" class="wf-lbl" text-anchor="end">&#8594;</text>';
		$svg .= '<rect x="16" y="34" width="328" height="24" rx="4" class="wf-img"/>';
		$svg .= '<rect x="16" y="58" width="328" height="120" class="wf-grid"/>';
		for ( $k = 1; $k < 7; $k++ ) {
			$x = 16 + ( 328 / 7 ) * $k;
			$svg .= '<line x1="' . round( $x, 1 ) . '" y1="58" x2="' . round( $x, 1 ) . '" y2="178" class="wf-grid"/>';
		}
		foreach ( array( 88, 118, 148 ) as $y ) {
			$svg .= '<line x1="16" y1="' . $y . '" x2="344" y2="' . $y . '" class="wf-grid"/>';
		}
		$svg .= '<rect x="66" y="66" width="30" height="6" rx="3" class="wf-tick"/>';
		$svg .= '<rect x="206" y="96" width="30" height="6" rx="3" class="wf-tick"/>';
		$svg .= '<rect x="112" y="126" width="30" height="6" rx="3" class="wf-tick"/>';
		$svg .= '</svg>';
		return $svg;
	}

	private function wire_single() {
		return '<svg class="evmr-wire" viewBox="0 0 360 190" xmlns="http://www.w3.org/2000/svg">'
			. '<rect x="16" y="16" width="328" height="158" rx="10" class="wf-card"/>'
			. '<rect x="26" y="26" width="150" height="138" rx="6" class="wf-img"/>'
			. '<rect x="190" y="40" width="130" height="9" rx="3" class="wf-line"/>'
			. '<rect x="190" y="60" width="104" height="7" rx="3" class="wf-line"/>'
			. '<rect x="190" y="76" width="118" height="7" rx="3" class="wf-line"/>'
			. '<rect x="190" y="92" width="88" height="7" rx="3" class="wf-line"/>'
			. '<rect x="190" y="140" width="66" height="18" rx="9" class="wf-btn"/>'
			. '</svg>';
	}
}
