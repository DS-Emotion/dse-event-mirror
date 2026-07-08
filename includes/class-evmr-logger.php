<?php
/**
 * Simple log so you can see what the sync engine did (and where Eventbrite hiccupped).
 *
 * POC implementation: keeps the most recent entries in an option. A later "tidy"
 * pass can move this to a dedicated table if volume warrants it.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records and exposes a rolling log of plugin activity.
 */
class EVMR_Logger {

	const OPTION   = 'evmr_log';
	const MAX_ROWS = 200;

	/**
	 * Record a log line.
	 *
	 * @param string $message Human-readable message.
	 * @param string $level   One of: info, success, warning, error.
	 * @param array  $context Optional structured context.
	 */
	public function log( $message, $level = 'info', $context = array() ) {
		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);

		/**
		 * Filter a log entry before it is stored (e.g. to ship it elsewhere).
		 *
		 * @param array $entry The log entry.
		 */
		$entry = apply_filters( 'evmr_log_entry', $entry );

		$log   = get_option( self::OPTION, array() );
		$log[] = $entry;

		// Trim to the most recent MAX_ROWS.
		if ( count( $log ) > self::MAX_ROWS ) {
			$log = array_slice( $log, -self::MAX_ROWS );
		}

		update_option( self::OPTION, $log, false );
	}

	/** Convenience wrappers. */
	public function info( $message, $context = array() ) {
		$this->log( $message, 'info', $context );
	}
	public function success( $message, $context = array() ) {
		$this->log( $message, 'success', $context );
	}
	public function warning( $message, $context = array() ) {
		$this->log( $message, 'warning', $context );
	}
	public function error( $message, $context = array() ) {
		$this->log( $message, 'error', $context );
	}

	/**
	 * Return stored log entries, newest first.
	 *
	 * @param int $limit Max rows to return.
	 * @return array
	 */
	public function get_entries( $limit = 50 ) {
		$log = get_option( self::OPTION, array() );
		$log = array_reverse( $log );
		return array_slice( $log, 0, $limit );
	}

	/**
	 * Clear the log.
	 */
	public function clear() {
		update_option( self::OPTION, array(), false );
	}
}
