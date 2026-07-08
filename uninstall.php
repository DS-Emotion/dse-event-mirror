<?php
/**
 * Runs when the plugin is deleted (not just deactivated).
 * Removes plugin options. Mirrored posts are left in place by default so a
 * client doesn't lose their What's On page on an accidental delete.
 *
 * @package EventMirror
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'evmr_settings' );
delete_option( 'evmr_log' );
delete_option( 'evmr_last_sync' );
delete_option( 'evmr_last_error' );
delete_option( 'evmr_cleanup' );

wp_clear_scheduled_hook( 'evmr_sync_cron' );

/**
 * Note: evmr_event posts and their meta are intentionally NOT deleted here.
 * A "remove all mirrored events on uninstall" toggle can be added to settings later.
 */
