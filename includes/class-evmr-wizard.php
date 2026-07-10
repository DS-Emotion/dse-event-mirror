<?php
/**
 * Setup wizard — a short, DSE-styled onboarding flow shown on activation so a
 * non-developer can get from "just installed" to "events showing" in a few
 * steps: connect Eventbrite, confirm the Events page and view, done.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the setup wizard.
 */
class EVMR_Wizard {

	const PAGE  = 'evmr-setup';
	const STEPS = 4;

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_init', array( $this, 'handle_submit' ) );
		add_action( 'admin_notices', array( $this, 'prompt_notice' ) );
	}

	/**
	 * Add the (hidden) wizard page under the Event Mirror menu.
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . EVMR_POST_TYPE,
			__( 'Setup', 'event-mirror' ),
			__( 'Setup', 'event-mirror' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Wizard URL for a given step.
	 *
	 * @param int $step Step number.
	 * @return string
	 */
	private function url( $step = 1 ) {
		return add_query_arg(
			array(
				'post_type' => EVMR_POST_TYPE,
				'page'      => self::PAGE,
				'step'      => $step,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Redirect to the wizard once, right after a single-plugin activation.
	 */
	public function maybe_redirect() {
		if ( ! get_transient( 'evmr_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'evmr_activation_redirect' );

		// Skip during bulk activation or for users who can't configure.
		if ( isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( $this->url( 1 ) );
		exit;
	}

	/**
	 * Handle wizard step submissions.
	 */
	public function handle_submit() {
		if ( empty( $_POST['evmr_wizard_step'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'evmr_wizard' );

		$step     = (int) $_POST['evmr_wizard_step'];
		$settings = get_option( EVMR_OPTION, array() );

		if ( 2 === $step ) {
			$settings['token']  = isset( $_POST['evmr_token'] ) ? sanitize_text_field( wp_unslash( $_POST['evmr_token'] ) ) : '';
			$settings['org_id'] = '';
			update_option( EVMR_OPTION, $settings );

			// Test the token by resolving the organization.
			$ok  = false;
			$api = evmr()->get( 'eventbrite' );
			if ( $api ) {
				$org = $api->organization_id();
				$ok  = ! is_wp_error( $org );
			}
			wp_safe_redirect( add_query_arg( 'evmr_conn', $ok ? 'ok' : 'fail', $this->url( 3 ) ) );
			exit;
		}

		if ( 3 === $step ) {
			$settings['events_page']   = isset( $_POST['evmr_events_page'] ) ? (int) $_POST['evmr_events_page'] : 0;
			$settings['events_layout'] = ( isset( $_POST['evmr_events_layout'] ) && 'grid' === $_POST['evmr_events_layout'] ) ? 'grid' : 'list';
			update_option( EVMR_OPTION, $settings );
			update_option( 'evmr_setup_complete', 1, false );
			wp_safe_redirect( $this->url( 4 ) );
			exit;
		}
	}

	/**
	 * Nudge admins to run setup until it's complete.
	 */
	public function prompt_notice() {
		$settings = get_option( EVMR_OPTION, array() );
		// Done, or already configured on an existing install (has a token).
		if ( ! current_user_can( 'manage_options' ) || get_option( 'evmr_setup_complete' ) || ! empty( $settings['token'] ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return; // Not on the wizard page itself.
		}
		$relevant = false !== strpos( (string) $screen->id, 'evmr' )
			|| false !== strpos( (string) $screen->id, EVMR_POST_TYPE )
			|| in_array( $screen->id, array( 'dashboard', 'plugins' ), true );
		if ( ! $relevant ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Event Mirror', 'event-mirror' ),
			esc_html__( 'Finish setup to start mirroring your events — it only takes a moment.', 'event-mirror' ),
			esc_url( $this->url( 1 ) ),
			esc_html__( 'Run setup', 'event-mirror' )
		);
	}

	/**
	 * Render the wizard.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$step = isset( $_GET['step'] ) ? max( 1, min( self::STEPS, (int) $_GET['step'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 3 === $step ) {
			EVMR_Events_Page::ensure_page();
		}
		?>
		<div class="wrap evmr-dse">
			<div class="evmr-topbar">
				<span class="evmr-topbar__mark">EM</span>
				<h1 class="evmr-topbar__title"><?php esc_html_e( 'Event Mirror · Setup', 'event-mirror' ); ?></h1>
				<span class="evmr-topbar__spacer"></span>
				<span class="evmr-topbar__tag">
					<?php
					/* translators: 1: current step, 2: total steps. */
					printf( esc_html__( 'Step %1$d of %2$d', 'event-mirror' ), (int) $step, (int) self::STEPS );
					?>
				</span>
			</div>
			<div class="evmr-canvas">
				<div class="evmr-sheet" style="max-width:640px;">
					<?php $this->render_step( $step ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single step's content.
	 *
	 * @param int $step Step number.
	 */
	private function render_step( $step ) {
		$settings = get_option( EVMR_OPTION, array() );

		switch ( $step ) {
			case 1:
				?>
				<p class="evmr-eyebrow"><?php esc_html_e( 'Welcome', 'event-mirror' ); ?></p>
				<h1><?php esc_html_e( 'Let’s set up Event Mirror', 'event-mirror' ); ?></h1>
				<p class="evmr-lead"><?php esc_html_e( 'Three quick steps: connect your Eventbrite account, confirm the page that lists your events, and choose how they look. You can change any of it later in Sync and Settings.', 'event-mirror' ); ?></p>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url( $this->url( 2 ) ); ?>"><?php esc_html_e( 'Get started', 'event-mirror' ); ?></a></p>
				<?php
				break;

			case 2:
				$token = isset( $settings['token'] ) ? $settings['token'] : '';
				?>
				<p class="evmr-eyebrow"><?php esc_html_e( 'Step 1 — Connect', 'event-mirror' ); ?></p>
				<h1><?php esc_html_e( 'Connect Eventbrite', 'event-mirror' ); ?></h1>
				<p class="evmr-lead">
					<?php esc_html_e( 'Paste your Eventbrite Private token so the plugin can pull your events.', 'event-mirror' ); ?>
					<a href="<?php echo esc_url( EVMR_Settings::eventbrite_apikeys_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Where do I find it?', 'event-mirror' ); ?></a>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field( 'evmr_wizard' ); ?>
					<input type="hidden" name="evmr_wizard_step" value="2" />
					<p>
						<input type="password" name="evmr_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Eventbrite private token', 'event-mirror' ); ?>" />
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Connect and continue', 'event-mirror' ); ?></button>
						<a class="button" href="<?php echo esc_url( $this->url( 3 ) ); ?>"><?php esc_html_e( 'Skip for now', 'event-mirror' ); ?></a>
					</p>
				</form>
				<?php
				break;

			case 3:
				$conn   = isset( $_GET['evmr_conn'] ) ? sanitize_key( $_GET['evmr_conn'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$layout = ( isset( $settings['events_layout'] ) && 'grid' === $settings['events_layout'] ) ? 'grid' : 'list';
				if ( 'ok' === $conn ) {
					echo '<p><span class="evmr-chip">' . esc_html__( 'Eventbrite connected', 'event-mirror' ) . '</span></p>';
				} elseif ( 'fail' === $conn ) {
					echo '<p class="evmr-node__desc">' . esc_html__( 'We couldn’t connect with that token — you can continue and fix it later in Sync and Settings.', 'event-mirror' ) . '</p>';
				}
				?>
				<p class="evmr-eyebrow"><?php esc_html_e( 'Step 2 — Your events page', 'event-mirror' ); ?></p>
				<h1><?php esc_html_e( 'Where events appear', 'event-mirror' ); ?></h1>
				<p class="evmr-lead"><?php esc_html_e( 'We created an Events page for you — the page that lists all your events. Keep it, or pick a different page. Then choose how the listing looks.', 'event-mirror' ); ?></p>
				<form method="post" action="">
					<?php wp_nonce_field( 'evmr_wizard' ); ?>
					<input type="hidden" name="evmr_wizard_step" value="3" />
					<p>
						<label><strong><?php esc_html_e( 'Events page', 'event-mirror' ); ?></strong></label><br />
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'evmr_events_page',
								'selected'          => EVMR_Events_Page::page_id(),
								'show_option_none'  => __( '— None —', 'event-mirror' ),
								'option_none_value' => 0,
							)
						);
						?>
					</p>
					<p>
						<label><strong><?php esc_html_e( 'Listing view', 'event-mirror' ); ?></strong></label><br />
						<label style="margin-right:1rem;"><input type="radio" name="evmr_events_layout" value="list" <?php checked( 'list', $layout ); ?> /> <?php esc_html_e( 'List (full-width rows)', 'event-mirror' ); ?></label>
						<label><input type="radio" name="evmr_events_layout" value="grid" <?php checked( 'grid', $layout ); ?> /> <?php esc_html_e( 'Grid of cards', 'event-mirror' ); ?></label>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'event-mirror' ); ?></button></p>
				</form>
				<?php
				break;

			case 4:
				$page_id  = EVMR_Events_Page::page_id();
				$sync_url = admin_url( 'admin-post.php' );
				?>
				<p class="evmr-eyebrow"><?php esc_html_e( 'All set', 'event-mirror' ); ?></p>
				<h1><?php esc_html_e( 'You’re ready to go', 'event-mirror' ); ?></h1>
				<p class="evmr-lead"><?php esc_html_e( 'Run your first sync to pull events from Eventbrite, then take a look at your Events page.', 'event-mirror' ); ?></p>
				<p>
					<form action="<?php echo esc_url( $sync_url ); ?>" method="post" style="display:inline;">
						<input type="hidden" name="action" value="evmr_sync_now" />
						<?php wp_nonce_field( 'evmr_sync_now' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Run first sync', 'event-mirror' ); ?></button>
					</form>
					<?php if ( $page_id ) : ?>
						<a class="button" href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Events page', 'event-mirror' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'post_type' => EVMR_POST_TYPE, 'page' => 'evmr-settings' ), admin_url( 'edit.php' ) ) ); ?>"><?php esc_html_e( 'Go to settings', 'event-mirror' ); ?></a>
				</p>
				<?php
				break;
		}
	}
}
