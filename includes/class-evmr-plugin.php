<?php
/**
 * Main plugin orchestrator.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads and coordinates every component of the plugin.
 */
class EVMR_Plugin {

	/**
	 * Component instances, keyed by short name.
	 *
	 * @var array
	 */
	private $components = array();

	/**
	 * Wire everything up.
	 */
	public function __construct() {
		$this->components['logger']     = new EVMR_Logger();
		$this->components['cpt']        = new EVMR_CPT();
		$this->components['settings']   = new EVMR_Settings();
		$this->components['eventbrite'] = new EVMR_Eventbrite();
		$this->components['sync']       = new EVMR_Sync(
			$this->components['eventbrite'],
			$this->components['logger']
		);
		$this->components['cron']      = new EVMR_Cron( $this->components['sync'] );
		$this->components['shortcode'] = new EVMR_Shortcode();
		$this->components['block']     = new EVMR_Block();
		$this->components['calendar']  = new EVMR_Calendar();
		$this->components['schema']    = new EVMR_Schema();
		$this->components['help']      = new EVMR_Help();
		$this->components['events_page'] = new EVMR_Events_Page();
		$this->components['wizard']      = new EVMR_Wizard();

		foreach ( $this->components as $component ) {
			if ( method_exists( $component, 'hooks' ) ) {
				$component->hooks();
			}
		}

		// Regenerate rewrite rules once after an update that changes them (e.g.
		// disabling the /events/ archive), so admins don't have to re-save
		// Permalinks by hand. Runs after the post type registers on `init`.
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );

		/**
		 * Fires after Event Mirror has loaded all of its components.
		 * Pro add-ons should hook here to register their own pieces.
		 *
		 * @param EVMR_Plugin $plugin The plugin instance.
		 */
		do_action( 'evmr_loaded', $this );
	}

	/**
	 * Retrieve a component by short name (e.g. 'sync', 'logger').
	 *
	 * @param string $name Component key.
	 * @return object|null
	 */
	public function get( $name ) {
		return isset( $this->components[ $name ] ) ? $this->components[ $name ] : null;
	}

	/**
	 * Flush rewrite rules once per plugin version. This clears the stale
	 * /events/ archive rule after it was disabled, so the assigned Events page
	 * (which shares that slug) resolves correctly — no manual Permalinks re-save.
	 */
	public function maybe_flush_rewrites() {
		if ( get_option( 'evmr_rewrite_version' ) !== EVMR_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'evmr_rewrite_version', EVMR_VERSION, false );
		}
	}

	/**
	 * Activation: register the CPT so rewrite rules exist, then flush.
	 */
	public static function activate() {
		// Ensure the post type is registered before flushing permalinks.
		( new EVMR_CPT() )->register();
		flush_rewrite_rules();

		// Seed default settings if none exist.
		if ( false === get_option( EVMR_OPTION ) ) {
			add_option(
				EVMR_OPTION,
				array(
					'token'         => '',
					'frequency'     => 'hourly',
					'cta_text'      => __( 'Get tickets', 'event-mirror' ),
					'org_id'        => '',
					'events_layout' => 'list',
				)
			);
		}

		// Create and assign the Events page (the canonical listing) if needed.
		EVMR_Events_Page::ensure_page();

		// Trigger the one-time setup wizard redirect (single activations only).
		set_transient( 'evmr_activation_redirect', 1, 30 );

		do_action( 'evmr_activated' );
	}

	/**
	 * Deactivation: clear scheduled syncs and flush rewrite rules.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'evmr_sync_cron' );
		flush_rewrite_rules();

		do_action( 'evmr_deactivated' );
	}
}
