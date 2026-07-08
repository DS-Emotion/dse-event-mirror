<?php
/**
 * "Sync and Settings" admin page.
 *
 * Paste token, set sync frequency, set default CTA text, set legacy-event
 * cleanup window, run a manual sync, view the activity log, and manage the
 * Events archive. Also surfaces a sync-health notice when syncing fails or
 * falls overdue.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the admin settings screen and handles manual sync + archive restore.
 */
class EVMR_Settings {

	const PAGE  = 'evmr-settings';
	const GROUP = 'evmr_settings_group';

	/**
	 * Eventbrite URLs. Filterable so the exact paths can be corrected without a
	 * code change if Eventbrite shifts them.
	 */
	public static function eventbrite_manage_url() {
		return apply_filters( 'evmr_eventbrite_manage_url', 'https://www.eventbrite.com/manage/events/' );
	}
	public static function eventbrite_apikeys_url() {
		return apply_filters( 'evmr_eventbrite_apikeys_url', 'https://www.eventbrite.com/platform/api-keys' );
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_evmr_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_evmr_toggle_exclude', array( $this, 'handle_toggle_exclude' ) );
		add_action( 'admin_notices', array( $this, 'sync_notice' ) );
		add_action( 'admin_notices', array( $this, 'health_notice' ) );
	}

	/**
	 * Add the "Sync and Settings" submenu under the Event Mirror post type menu.
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . EVMR_POST_TYPE,
			__( 'Sync and Settings', 'event-mirror' ),
			__( 'Sync and Settings', 'event-mirror' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the setting, sections, and fields.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			EVMR_OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);

		add_settings_section( 'evmr_connection', __( 'Connection', 'event-mirror' ), array( $this, 'section_connection' ), self::PAGE );
		add_settings_field( 'token', __( 'Eventbrite API token', 'event-mirror' ), array( $this, 'field_token' ), self::PAGE, 'evmr_connection' );
		add_settings_field( 'frequency', __( 'Sync frequency', 'event-mirror' ), array( $this, 'field_frequency' ), self::PAGE, 'evmr_connection' );

		add_settings_section( 'evmr_display', __( 'Display', 'event-mirror' ), '__return_false', self::PAGE );
		add_settings_field( 'cta_text', __( 'Default CTA text', 'event-mirror' ), array( $this, 'field_cta' ), self::PAGE, 'evmr_display' );

		add_settings_section( 'evmr_housekeeping', __( 'Auto Clean-Up', 'event-mirror' ), array( $this, 'section_housekeeping' ), self::PAGE );
		add_settings_field( 'prune', __( 'Delete events older than', 'event-mirror' ), array( $this, 'field_prune' ), self::PAGE, 'evmr_housekeeping' );
	}

	/**
	 * Sanitise settings on save.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$existing = get_option( EVMR_OPTION, array() );

		$clean             = array();
		$clean['token']    = isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '';
		$clean['cta_text'] = isset( $input['cta_text'] ) ? sanitize_text_field( $input['cta_text'] ) : '';

		$allowed_freq       = array( 'hourly', 'twicedaily', 'daily' );
		$clean['frequency'] = ( isset( $input['frequency'] ) && in_array( $input['frequency'], $allowed_freq, true ) )
			? $input['frequency']
			: 'hourly';

		$allowed_prune  = array( '', '1day', '1week', '1month', '3months' );
		$clean['prune'] = ( isset( $input['prune'] ) && in_array( $input['prune'], $allowed_prune, true ) )
			? $input['prune']
			: '';

		// If the token changed, drop the cached org so it re-resolves.
		$clean['org_id'] = ( isset( $existing['token'] ) && $existing['token'] === $clean['token'] && ! empty( $existing['org_id'] ) )
			? $existing['org_id']
			: '';

		return $clean;
	}

	/* ---- Section intros ---- */

	public function section_connection() {
		printf(
			'<p>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>.</p>',
			esc_html__( 'Manage your events on Eventbrite:', 'event-mirror' ),
			esc_url( self::eventbrite_manage_url() ),
			esc_html__( 'Open the Eventbrite event manager', 'event-mirror' )
		);
	}

	public function section_housekeeping() {
		echo '<p>' . esc_html__( 'Auto Clean-Up removes events whose end date is older than the window you choose, so your site only carries current events. Removed events are listed below and are not re-imported on the next sync. To keep a specific old event on your site, toggle "Exclude from Auto Clean-Up" on its row — it will be re-synced and never auto-removed.', 'event-mirror' ) . '</p>';
	}

	/* ---- Field renderers ---- */

	public function field_token() {
		$settings = get_option( EVMR_OPTION, array() );
		$value    = isset( $settings['token'] ) ? $settings['token'] : '';
		printf(
			'<input type="password" name="%s[token]" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( EVMR_OPTION ),
			esc_attr( $value )
		);
		printf(
			'<p class="description">%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>. %s</p>',
			esc_html__( 'Get your private token from', 'event-mirror' ),
			esc_url( self::eventbrite_apikeys_url() ),
			esc_html__( 'Eventbrite → Account Settings → Developer → API Keys', 'event-mirror' ),
			esc_html__( 'Copy the "Private token" and paste it here.', 'event-mirror' )
		);
	}

	public function field_frequency() {
		$settings = get_option( EVMR_OPTION, array() );
		$current  = isset( $settings['frequency'] ) ? $settings['frequency'] : 'hourly';
		$options  = array(
			'hourly'     => __( 'Hourly', 'event-mirror' ),
			'twicedaily' => __( 'Twice daily', 'event-mirror' ),
			'daily'      => __( 'Daily', 'event-mirror' ),
		);
		echo '<select name="' . esc_attr( EVMR_OPTION ) . '[frequency]">';
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function field_cta() {
		$settings = get_option( EVMR_OPTION, array() );
		$value    = isset( $settings['cta_text'] ) ? $settings['cta_text'] : __( 'Get tickets', 'event-mirror' );
		printf(
			'<input type="text" name="%s[cta_text]" value="%s" class="regular-text" /><p class="description">%s</p>',
			esc_attr( EVMR_OPTION ),
			esc_attr( $value ),
			esc_html__( 'Button label shown on event cards, linking to the Eventbrite listing.', 'event-mirror' )
		);
	}

	public function field_prune() {
		$settings = get_option( EVMR_OPTION, array() );
		$current  = isset( $settings['prune'] ) ? $settings['prune'] : '';
		$options  = array(
			''        => __( 'Keep all', 'event-mirror' ),
			'1day'    => __( '1 day', 'event-mirror' ),
			'1week'   => __( '1 week', 'event-mirror' ),
			'1month'  => __( '1 month', 'event-mirror' ),
			'3months' => __( '3 months', 'event-mirror' ),
		);
		echo '<select name="' . esc_attr( EVMR_OPTION ) . '[prune]">';
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Based on each event\'s end date. "Keep all" disables automatic removal.', 'event-mirror' ) . '</p>';
	}

	/**
	 * Render the full page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$last_sync = get_option( 'evmr_last_sync', '' );
		$logger    = evmr()->get( 'logger' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event Mirror — Sync and Settings', 'event-mirror' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Manual sync', 'event-mirror' ); ?></h2>
			<?php if ( $last_sync ) : ?>
				<p>
					<?php
					/* translators: %s: human-readable time since last sync. */
					printf( esc_html__( 'Last synced %s ago.', 'event-mirror' ), esc_html( human_time_diff( strtotime( $last_sync ), current_time( 'timestamp' ) ) ) );
					?>
				</p>
			<?php endif; ?>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="evmr_sync_now" />
				<?php wp_nonce_field( 'evmr_sync_now' ); ?>
				<?php submit_button( __( 'Sync now', 'event-mirror' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Recent activity', 'event-mirror' ); ?></h2>
			<?php $this->render_log( $logger ); ?>

			<hr />

			<h2><?php esc_html_e( 'Auto Clean-Up — old events', 'event-mirror' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Events past your clean-up window. Each is either removed from the site or, if you exclude it, kept permanently. Toggle the switch to change what happens to it.', 'event-mirror' ); ?></p>
			<?php $this->render_cleanup_list(); ?>
		</div>
		<?php
	}

	/**
	 * Render the recent log table.
	 *
	 * @param EVMR_Logger $logger Logger instance.
	 */
	private function render_log( $logger ) {
		$entries = $logger ? $logger->get_entries( 25 ) : array();
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No activity yet. Run a sync to get started.', 'event-mirror' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'event-mirror' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'event-mirror' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'event-mirror' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['time'] ),
				esc_html( $row['level'] ),
				esc_html( $row['message'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the Auto Clean-Up list (ID, title, date, status) with an
	 * "Exclude from Auto Clean-Up" toggle per row.
	 */
	private function render_cleanup_list() {
		$registry = get_option( 'evmr_cleanup', array() );
		if ( empty( $registry ) ) {
			echo '<p>' . esc_html__( 'No old events yet. Once events pass your clean-up window, they will appear here.', 'event-mirror' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Event ID', 'event-mirror' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'event-mirror' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'event-mirror' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'event-mirror' ) . '</th>';
		echo '<th>' . esc_html__( 'Exclude from Auto Clean-Up', 'event-mirror' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $registry as $eb_id => $data ) {
			$title    = isset( $data['title'] ) ? $data['title'] : '';
			$date     = isset( $data['date'] ) ? $data['date'] : '';
			$excluded = ! empty( $data['excluded'] );

			echo '<tr>';
			printf( '<td><code>%s</code></td>', esc_html( $eb_id ) );
			printf( '<td>%s</td>', esc_html( $title ) );
			printf( '<td>%s</td>', esc_html( $date ) );
			printf(
				'<td>%s</td>',
				$excluded
					? '<strong>' . esc_html__( 'Excluded — kept on your site', 'event-mirror' ) . '</strong>'
					: esc_html__( 'Removed by Auto Clean-Up', 'event-mirror' )
			);

			echo '<td>';
			printf( '<form action="%s" method="post" style="margin:0;">', esc_url( admin_url( 'admin-post.php' ) ) );
			echo '<input type="hidden" name="action" value="evmr_toggle_exclude" />';
			printf( '<input type="hidden" name="eb_id" value="%s" />', esc_attr( $eb_id ) );
			// Submit the OPPOSITE of the current state.
			printf( '<input type="hidden" name="exclude" value="%s" />', $excluded ? '0' : '1' );
			wp_nonce_field( 'evmr_toggle_exclude_' . $eb_id );
			if ( $excluded ) {
				submit_button( __( 'Turn off — allow clean-up', 'event-mirror' ), 'small', 'submit', false );
			} else {
				submit_button( __( 'Exclude — keep this event', 'event-mirror' ), 'small primary', 'submit', false );
			}
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Handle the "Sync now" button submission.
	 */
	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'event-mirror' ) );
		}
		check_admin_referer( 'evmr_sync_now' );

		$result = evmr()->get( 'sync' )->run();
		$status = is_wp_error( $result ) ? 'error' : 'ok';

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'   => EVMR_POST_TYPE,
					'page'        => self::PAGE,
					'evmr_synced' => $status,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Handle toggling an event's "Exclude from Auto Clean-Up" state.
	 */
	public function handle_toggle_exclude() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'event-mirror' ) );
		}
		$eb_id = isset( $_POST['eb_id'] ) ? sanitize_text_field( wp_unslash( $_POST['eb_id'] ) ) : '';
		check_admin_referer( 'evmr_toggle_exclude_' . $eb_id );

		$exclude  = ! empty( $_POST['exclude'] );
		$registry = get_option( 'evmr_cleanup', array() );
		if ( isset( $registry[ $eb_id ] ) ) {
			$registry[ $eb_id ]['excluded'] = $exclude;
			update_option( 'evmr_cleanup', $registry, false );
		}

		// Mirror onto the event's own flag if the post still exists.
		$posts = get_posts(
			array(
				'post_type'      => EVMR_POST_TYPE,
				'post_status'    => 'any',
				'meta_key'       => '_evmr_eb_id',
				'meta_value'     => $eb_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		if ( ! empty( $posts ) ) {
			if ( $exclude ) {
				update_post_meta( $posts[0], '_evmr_exclude_cleanup', '1' );
			} else {
				delete_post_meta( $posts[0], '_evmr_exclude_cleanup' );
			}
		}

		do_action( 'evmr_cleanup_exclude_toggled', $eb_id, $exclude );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => EVMR_POST_TYPE,
					'page'         => self::PAGE,
					'evmr_toggled' => $exclude ? 'excluded' : 'included',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Show an admin notice after a manual sync or a restore.
	 */
	public function sync_notice() {
		if ( ! empty( $_GET['evmr_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok = 'ok' === $_GET['evmr_synced']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				$ok
					? esc_html__( 'Event Mirror: sync finished. See recent activity below.', 'event-mirror' )
					: esc_html__( 'Event Mirror: sync failed. Check recent activity for details.', 'event-mirror' )
			);
		}
		if ( ! empty( $_GET['evmr_toggled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$excluded = 'excluded' === $_GET['evmr_toggled']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				$excluded
					? esc_html__( 'Event Mirror: this event is now excluded from Auto Clean-Up. It will be re-synced and kept on your site on the next sync. Run "Sync now" to apply it immediately.', 'event-mirror' )
					: esc_html__( 'Event Mirror: this event is back under Auto Clean-Up and will be removed on the next sync.', 'event-mirror' )
			);
		}
	}

	/**
	 * Surface a sync-health warning if the last sync errored or is overdue.
	 */
	public function health_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Only nag on plugin screens to avoid being intrusive everywhere.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false === strpos( (string) $screen->id, EVMR_POST_TYPE ) && 'dashboard' !== $screen->id ) {
			return;
		}

		$error = get_option( 'evmr_last_error', '' );
		if ( $error ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Event Mirror: the last sync failed.', 'event-mirror' ),
				esc_html( $error )
			);
			return;
		}

		// Overdue check: no successful sync within ~2x the configured interval.
		$last_sync = get_option( 'evmr_last_sync', '' );
		if ( ! $last_sync ) {
			return;
		}
		$settings  = get_option( EVMR_OPTION, array() );
		$frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'hourly';
		$intervals = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
		);
		$window = isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : HOUR_IN_SECONDS;
		if ( ( current_time( 'timestamp' ) - strtotime( $last_sync ) ) > ( 2 * $window ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				sprintf(
					/* translators: %s: human-readable time since last sync. */
					esc_html__( 'Event Mirror: events may be out of date — the last successful sync was %s ago.', 'event-mirror' ),
					esc_html( human_time_diff( strtotime( $last_sync ), current_time( 'timestamp' ) ) )
				)
			);
		}
	}
}
