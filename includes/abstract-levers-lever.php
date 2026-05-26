<?php
/**
 * Base class every Lever extends.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single tweak ("lever") the user can switch on or off.
 */
abstract class Levers_Lever {

	/**
	 * Unique, stable identifier for this lever (kebab-case).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human-readable name shown on the settings screen.
	 *
	 * @return string
	 */
	abstract public function title();

	/**
	 * Short explanation of what the lever does.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Category slug this lever belongs to.
	 *
	 * One of: usability, security, performance, spam, bugfix.
	 *
	 * @return string
	 */
	abstract public function category();

	/**
	 * Apply the tweak. Only called when the lever is switched on.
	 *
	 * @return void
	 */
	abstract public function run();

	/**
	 * Lucide icon name (a file in the plugin's /icons/ folder) shown beside
	 * the lever on the settings screen. Levers should override this.
	 *
	 * @return string
	 */
	public function icon() {
		return 'sliders-horizontal';
	}

	/**
	 * Extra UI rendered beneath the lever's description on the settings
	 * screen. Levers may override this; by default nothing is shown.
	 *
	 * @param bool $enabled Whether the lever is currently switched on.
	 * @return void
	 */
	public function render_extra( $enabled = false ) {}

	/**
	 * Called once, when the lever is switched on. Levers may override this
	 * for one-time setup such as scheduling a cron event.
	 *
	 * @return void
	 */
	public function on_enable() {}

	/**
	 * Called once, when the lever is switched off. Levers may override this
	 * for teardown such as clearing a scheduled cron event.
	 *
	 * @return void
	 */
	public function on_disable() {}

	/**
	 * Whether the lever is on by default, before the user saves anything.
	 *
	 * The whole pack ships "on" - every lever is recommended out of the
	 * box. Levers that {@see is_available()} marks unavailable for the
	 * current environment (e.g. Force SSL on a local dev site) still
	 * stay off, because is_available() takes precedence in both
	 * apply_levers() and the settings save handler.
	 *
	 * Existing installs aren't affected: saved settings always win over
	 * this default, so a previously-disabled lever stays disabled.
	 *
	 * @return bool
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * Whether the lever can be turned on in the current environment.
	 *
	 * Levers may override this to refuse activation when it would not make
	 * sense, e.g. SSL-related levers on a local site without HTTPS.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Short explanation of why the lever is unavailable, shown beneath the
	 * description when {@see is_available()} returns false.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return '';
	}

	/**
	 * Whether the site appears to be a local development environment.
	 *
	 * Inspects every signal we reasonably can: WordPress's own environment
	 * type, the configured site URL, the incoming Host header, the server
	 * name, and finally the server and remote IP addresses. Any single one
	 * looking local is treated as enough.
	 *
	 * @return bool
	 */
	public static function is_local_environment() {
		// Strongest signal: WordPress's own environment type.
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			return true;
		}

		// Host-shaped signals.
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ) );

		foreach ( array( 'HTTP_HOST', 'SERVER_NAME' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$hosts[] = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised by self::host_is_local().
			}
		}

		foreach ( $hosts as $host ) {
			if ( self::host_is_local( $host ) ) {
				return true;
			}
		}

		// IP-shaped signals: a loopback address is a near-definitive sign
		// the request is happening on the same box as the server.
		foreach ( array( 'SERVER_ADDR', 'REMOTE_ADDR' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$ip = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as string below.

			if ( in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a single host string looks like a local development host.
	 *
	 * Tolerates trailing `:port` and surrounding `[...]` for IPv6.
	 *
	 * @param mixed $host Host candidate.
	 * @return bool
	 */
	private static function host_is_local( $host ) {
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( $host );
		$host = preg_replace( '/:\d+$/', '', $host );
		$host = trim( (string) $host, '[]' );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		foreach ( array( '.local', '.test', '.localhost' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
