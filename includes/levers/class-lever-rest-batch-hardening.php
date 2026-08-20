<?php
/**
 * Lever: REST batch hardening.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Requires authentication for the REST batch API (/batch/v1), the entry
 * vector used by the WP2Shell intrusion.
 *
 * Hooked at rest_pre_dispatch with a very early priority so the request
 * is refused before any batched sub-requests are dispatched.
 *
 * Conflict safety: if an equivalent guard is already active on the site
 * (e.g. the standalone mitigation snippet defining
 * viewers_block_anon_rest_batch), the lever reports itself unavailable
 * and does nothing. The callback also yields to any earlier
 * rest_pre_dispatch filter that already produced a response.
 */
class Levers_Lever_Rest_Batch_Hardening extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'rest-batch-hardening';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'REST batch hardening', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Requires authentication for the REST batch API (/batch/v1), an endpoint abused by automated attacks.', 'levers' );
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
		return 'lock-keyhole';
	}

	/**
	 * Skip entirely when an equivalent guard is already active, so the two
	 * implementations never stack or conflict. Runs at plugins_loaded, after
	 * every other plugin (and mu-plugin) has been included.
	 *
	 * {@inheritDoc}
	 */
	public function is_available() {
		return ! function_exists( 'viewers_block_anon_rest_batch' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason() {
		return __( 'Another plugin on this site already restricts the REST batch API, so this lever is standing down to avoid conflicts.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'rest_pre_dispatch', array( $this, 'block_anonymous_batch' ), -1000, 3 );
	}

	/**
	 * Refuse anonymous requests to the REST batch endpoint.
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function block_anonymous_batch( $result, $server, $request ) {
		// Another rest_pre_dispatch filter already produced a response -
		// don't second-guess it.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		$route = untrailingslashit( strtolower( (string) $request->get_route() ) );

		if ( ! preg_match( '#(^|/)batch/v1$#', $route ) ) {
			return $result;
		}

		if ( is_user_logged_in() ) {
			return $result;
		}

		return new WP_Error(
			'rest_batch_authentication_required',
			__( 'Authentication is required to use the batch API.', 'levers' ),
			array( 'status' => 401 )
		);
	}
}
