<?php
/**
 * Lever: prevent XML-RPC login attacks.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Disables WordPress XML-RPC.
 *
 * XML-RPC is a legacy remote-access service that is now used almost
 * exclusively to brute-force logins and hammer the dashboard, often via
 * system.multicall to test many passwords in a single request.
 */
class Levers_Lever_Disable_Xmlrpc extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-xmlrpc';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Prevent XML-RPC login attacks', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Disables XML-RPC, a legacy service mostly used to brute-force logins. Leave off if you use the WordPress mobile app or Jetpack.', 'levers' );
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
	public function icon() {
		return 'shield-ban';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Reject every authenticated XML-RPC method - the login attack vector.
		add_filter( 'xmlrpc_enabled', '__return_false' );

		// Strip the whole method list (including pingback) so the endpoint
		// has nothing left to call.
		add_filter( 'xmlrpc_methods', '__return_empty_array' );

		// Stop advertising the endpoint via the X-Pingback HTTP header.
		add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
	}

	/**
	 * Remove the X-Pingback header that points crawlers at xmlrpc.php.
	 *
	 * @param array $headers HTTP headers about to be sent.
	 * @return array
	 */
	public function remove_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}

		return $headers;
	}
}
