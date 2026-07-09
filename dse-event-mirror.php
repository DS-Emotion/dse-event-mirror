<?php
/**
 * Plugin Name:       Event Mirror
 * Plugin URI:        https://www.dsemotion.com
 * Description:        Mirrors your Eventbrite events into WordPress as native posts, kept in sync automatically. Works with Eventbrite.
 * Version:           0.10.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DS.Emotion
 * Author URI:        https://www.dsemotion.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-mirror
 *
 * Event Mirror is a third-party tool and is not affiliated with, endorsed by,
 * or sponsored by Eventbrite. "Eventbrite" is a trademark of its respective owner.
 *
 * @package EventMirror
 */

defined( 'ABSPATH' ) || exit;

define( 'EVMR_VERSION', '0.10.1' );
define( 'EVMR_FILE', __FILE__ );
define( 'EVMR_PATH', plugin_dir_path( __FILE__ ) );
define( 'EVMR_URL', plugin_dir_url( __FILE__ ) );
define( 'EVMR_POST_TYPE', 'evmr_event' );
define( 'EVMR_OPTION', 'evmr_settings' );

/**
 * Lightweight class loader for the evmr_ namespace.
 * Maps class "EVMR_Foo_Bar" to includes/class-evmr-foo-bar.php.
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'EVMR_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$path = EVMR_PATH . 'includes/' . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 *
 * @return EVMR_Plugin
 */
function evmr() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new EVMR_Plugin();
	}
	return $instance;
}

add_action( 'plugins_loaded', 'evmr' );

/**
 * Self-hosted updates from the GitHub repo (one-click updates in wp-admin).
 * Publish a GitHub Release tagged like "0.6.0" (or "v0.6.0"), and sites running
 * an older version will be offered the update. The checker downloads the
 * Release's auto-generated source zip and installs it into the "event-mirror"
 * folder automatically -- no zip needs to be attached to the Release.
 */
require EVMR_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';

$evmr_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/DS-Emotion/dse-event-mirror/',
	EVMR_FILE,
	'dse-event-mirror'
);

// Activation / deactivation.
register_activation_hook( __FILE__, array( 'EVMR_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EVMR_Plugin', 'deactivate' ) );
