<?php
/**
 * Plugin Name:       Levers
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Tweak WordPress with recommended settings to improve usability, security, performance, reduce spam, fix bugs and more - one lever at a time.
 * Version:           1.1.4
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://leverswp.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       levers
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

define( 'LEVERS_VERSION', '1.1.4' );
define( 'LEVERS_FILE', __FILE__ );
define( 'LEVERS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEVERS_URL', plugin_dir_url( __FILE__ ) );

// Plugin Update Checker (skip in dev)
if ( ! defined( 'LEVERS_DEV_MODE' ) || ! LEVERS_DEV_MODE ) {
	require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

	$leversUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/jmsprsns/levers/',
		__FILE__,
		'levers'
	);

	$leversUpdateChecker->setBranch( 'main' );
}

require_once LEVERS_DIR . 'includes/class-levers-icons.php';
require_once LEVERS_DIR . 'includes/abstract-levers-lever.php';
require_once LEVERS_DIR . 'includes/class-levers-admin.php';
require_once LEVERS_DIR . 'includes/class-levers-plugin.php';

/**
 * Boot the plugin once all other plugins have loaded.
 */
function levers() {
	return Levers_Plugin::instance();
}
add_action( 'plugins_loaded', 'levers' );

/**
 * Inject our plugin icon into the update_plugins transient so the WordPress
 * core updates screen (wp-admin/update-core.php) shows it next to the
 * Levers row instead of the generic dashicons-admin-plugins placeholder.
 */
add_filter( 'site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->response ) || ! is_array( $transient->response ) ) {
		return $transient;
	}

	$slug = plugin_basename( LEVERS_FILE );

	if ( ! isset( $transient->response[ $slug ] ) || ! is_object( $transient->response[ $slug ] ) ) {
		return $transient;
	}

	$icon_url = LEVERS_URL . 'images/plugin-icon.png';

	$icons = isset( $transient->response[ $slug ]->icons ) && is_array( $transient->response[ $slug ]->icons )
		? $transient->response[ $slug ]->icons
		: array();

	$icons['default'] = $icon_url;
	$icons['1x']      = $icon_url;

	$transient->response[ $slug ]->icons = $icons;

	return $transient;
} );

/**
 * Clear Levers' scheduled events when the plugin is deactivated.
 */
function levers_deactivate() {
	wp_clear_scheduled_hook( 'levers_sweep_comment_spam' );
	wp_clear_scheduled_hook( 'levers_purge_spam_trash_comments' );
	wp_clear_scheduled_hook( 'levers_purge_expired_transients' );
	wp_clear_scheduled_hook( 'levers_optimize_db_tables' );
	wp_clear_scheduled_hook( 'levers_purge_expired_sessions' );
	wp_clear_scheduled_hook( 'levers_trim_post_revisions' );
	wp_clear_scheduled_hook( 'levers_clean_orphan_meta' );
}
register_deactivation_hook( LEVERS_FILE, 'levers_deactivate' );
