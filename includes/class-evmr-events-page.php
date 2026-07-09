<?php
/**
 * The Events page — a real WordPress Page, assigned in settings, that acts as
 * the canonical events listing (paginated), the way WooCommerce assigns a Shop
 * page. Making it a real Page (rather than the invisible auto-archive) is what
 * makes it discoverable in the CMS: it shows in the Pages list with an "Events
 * Page" badge, can be edited, and can be dropped into menus.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assigns, creates, renders and signposts the Events listing page.
 */
class EVMR_Events_Page {

	const DEFAULT_TITLE = 'Events';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_filter( 'the_content', array( $this, 'render' ), 9 );
		add_filter( 'display_post_states', array( $this, 'post_state_badge' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
		add_action( 'admin_post_evmr_create_events_page', array( $this, 'handle_create' ) );
	}

	/**
	 * The assigned Events page ID, or 0 if none/invalid.
	 *
	 * @return int
	 */
	public static function page_id() {
		$settings = get_option( EVMR_OPTION, array() );
		$id       = isset( $settings['events_page'] ) ? (int) $settings['events_page'] : 0;
		if ( $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			return $id;
		}
		return 0;
	}

	/**
	 * Create the Events page if none is assigned, and store the assignment.
	 * Safe to call repeatedly — it no-ops once a valid page exists.
	 *
	 * @return int The Events page ID.
	 */
	public static function ensure_page() {
		$existing = self::page_id();
		if ( $existing ) {
			return $existing;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => self::DEFAULT_TITLE,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$settings                 = get_option( EVMR_OPTION, array() );
			$settings['events_page']  = (int) $page_id;
			update_option( EVMR_OPTION, $settings );
			return (int) $page_id;
		}
		return 0;
	}

	/**
	 * Register the pagination query var (kept distinct from core paging to avoid
	 * the static-page /page/2/ 404 pitfall).
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = 'evmr_page';
		return $vars;
	}

	/**
	 * Replace the assigned page's content with the paginated events listing.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function render( $content ) {
		$page_id = self::page_id();
		if ( ! $page_id || is_admin() ) {
			return $content;
		}
		if ( ! is_page( $page_id ) || ! is_main_query() || ! in_the_loop() || get_the_ID() !== $page_id ) {
			return $content;
		}

		$settings = get_option( EVMR_OPTION, array() );
		$per_page = isset( $settings['events_per_page'] ) ? max( 1, (int) $settings['events_per_page'] ) : 12;
		$layout   = ( isset( $settings['events_layout'] ) && 'grid' === $settings['events_layout'] ) ? 'grid' : 'list';
		$cta      = ( isset( $settings['cta_text'] ) && '' !== $settings['cta_text'] ) ? $settings['cta_text'] : __( 'Get tickets', 'event-mirror' );

		$paged = (int) get_query_var( 'evmr_page' );
		if ( ! $paged && isset( $_GET['evmr_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$paged = (int) $_GET['evmr_page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$paged = max( 1, $paged );

		$query = new WP_Query(
			array(
				'post_type'      => EVMR_POST_TYPE,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'meta_key'       => '_evmr_start_utc',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_evmr_start_utc',
						'value'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'compare' => '>=',
						'type'    => 'CHAR',
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return '<p class="evmr-empty">' . esc_html__( 'No upcoming events.', 'event-mirror' ) . '</p>';
		}

		wp_enqueue_style( 'event-mirror' );

		ob_start();
		if ( 'grid' === $layout ) {
			echo '<div class="evmr-grid evmr-grid--cols-auto">';
			while ( $query->have_posts() ) {
				$query->the_post();
				echo EVMR_Shortcode::card_html( get_the_ID(), $cta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		} else {
			echo '<div class="evmr-list">';
			while ( $query->have_posts() ) {
				$query->the_post();
				echo EVMR_Shortcode::list_item_html( get_the_ID(), $cta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
		wp_reset_postdata();

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'evmr_page', '%#%', get_permalink( $page_id ) ),
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

		return ob_get_clean();
	}

	/**
	 * Show a native "Events Page" badge next to the assigned page in the Pages
	 * list, exactly like core's "Posts Page" / "Front Page" badges.
	 *
	 * @param array   $states Post states.
	 * @param WP_Post $post   The post.
	 * @return array
	 */
	public function post_state_badge( $states, $post ) {
		if ( $post && (int) $post->ID === self::page_id() ) {
			$states['evmr_events_page'] = __( 'Events Page', 'event-mirror' );
		}
		return $states;
	}

	/**
	 * Prompt the admin to set up an Events page if none is assigned yet.
	 */
	public function setup_notice() {
		if ( ! current_user_can( 'manage_options' ) || self::page_id() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$here   = $screen && ( false !== strpos( (string) $screen->id, 'evmr' ) || false !== strpos( (string) $screen->id, EVMR_POST_TYPE ) || 'dashboard' === $screen->id );
		if ( ! $here ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Event Mirror:', 'event-mirror' ); ?></strong> <?php esc_html_e( 'Set up your Events page — the page that lists your events. This is the first thing to configure.', 'event-mirror' ); ?></p>
			<p>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
					<input type="hidden" name="action" value="evmr_create_events_page" />
					<?php wp_nonce_field( 'evmr_create_events_page' ); ?>
					<?php submit_button( __( 'Create Events page', 'event-mirror' ), 'primary', 'submit', false ); ?>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the "Create Events page" button.
	 */
	public function handle_create() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'event-mirror' ) );
		}
		check_admin_referer( 'evmr_create_events_page' );

		$page_id = self::ensure_page();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'         => EVMR_POST_TYPE,
					'page'              => 'evmr-settings',
					'evmr_page_created' => $page_id ? '1' : '0',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
