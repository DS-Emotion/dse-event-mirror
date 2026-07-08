<?php
/**
 * Scheduling.
 *
 * POC uses WP-Cron on the frequency chosen in settings. The to-do flags moving
 * to a real server cron hitting wp-cron.php for low-traffic sites — that's a
 * deployment/setup-guide step, but the scheduled hook below is what real cron
 * would trigger, so nothing here changes when you make that switch.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and fires the scheduled sync.
 */
class EVMR_Cron {

	const HOOK = 'evmr_sync_cron';

	/**
	 * @var EVMR_Sync
	 */
	private $sync;

	/**
	 * Constructor.
	 *
	 * @param EVMR_Sync $sync Sync engine.
	 */
	public function __construct( EVMR_Sync $sync ) {
		$this->sync = $sync;
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		// Reschedule whenever settings are saved.
		add_action( 'update_option_' . EVMR_OPTION, array( $this, 'reschedule' ), 10, 0 );
	}

	/**
	 * Ensure an event is scheduled at the configured frequency.
	 */
	public function maybe_schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$settings  = get_option( EVMR_OPTION, array() );
		$frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'hourly';
		wp_schedule_event( time() + 60, $frequency, self::HOOK );
	}

	/**
	 * Clear and re-add the schedule (used when frequency changes).
	 */
	public function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$this->maybe_schedule();
	}

	/**
	 * Fire the sync.
	 */
	public function run() {
		$this->sync->run();
	}
}
