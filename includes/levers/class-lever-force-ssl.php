<?php
/**
 * Lever: force SSL.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirects every HTTP request to HTTPS.
 *
 * Covers the front end, the login screen and the dashboard. AJAX, REST,
 * cron and WP-CLI requests are deliberately left alone so background and
 * API traffic is not broken by a redirect.
 */
class Levers_Lever_Force_Ssl extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'force-ssl';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Force SSL', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Redirects HTTP requests to HTTPS on the front end, login and dashboard. Only enable once a valid SSL certificate is installed.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return ! self::is_local_environment();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason() {
		return __( 'Disabled on local development sites.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'lock-keyhole';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'template_redirect', array( $this, 'redirect_to_https' ), 1 );
		add_action( 'login_init', array( $this, 'redirect_to_https' ), 1 );
		add_action( 'admin_init', array( $this, 'redirect_to_https' ), 1 );
	}

	/**
	 * Send an insecure request to its HTTPS equivalent.
	 *
	 * @return void
	 */
	public function redirect_to_https() {
		if ( $this->is_secure() ) {
			return;
		}

		// Never interfere with CLI or cron requests.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		// Build the target from the site's own host (never the client-supplied
		// Host header) so this cannot be abused as an open redirect.
		$home = wp_parse_url( home_url() );
		$host = isset( $home['host'] ) ? $home['host'] : '';

		if ( '' === $host ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by wp_safe_redirect().

		// 302 (not 301) so switching the lever off takes effect immediately,
		// without browsers caching a permanent redirect.
		wp_safe_redirect( 'https://' . $host . $path, 302 );
		exit;
	}

	/**
	 * Whether the request is already secure.
	 *
	 * Also treats the request as secure when a reverse proxy reports HTTPS
	 * upstream, which avoids a redirect loop on proxied / load-balanced setups.
	 *
	 * @return bool
	 */
	private function is_secure() {
		if ( is_ssl() ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			&& 'https' === strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )
		) {
			return true;
		}

		return false;
	}
}
