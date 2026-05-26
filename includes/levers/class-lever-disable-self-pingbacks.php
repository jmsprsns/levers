<?php
/**
 * Lever: disable self-pingbacks.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stops WordPress pinging itself when you link to your own posts.
 *
 * Closes the three paths a self-pingback can take: outgoing pings while a
 * post is being published, incoming XML-RPC pingback requests from the same
 * domain, and the post-author notification email that WordPress sends when
 * a pingback comment lands.
 */
class Levers_Lever_Disable_Self_Pingbacks extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-self-pingbacks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable self-pingbacks', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Stops WordPress creating pingbacks when you link to your own posts, and silences the notification emails.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'wordpress-cleanup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'link-2-off';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Strip same-site links from the outgoing ping list at publish time.
		add_action( 'pre_ping', array( $this, 'strip_self_pings' ) );

		// Reject incoming XML-RPC pingbacks whose source is our own domain.
		add_filter( 'xmlrpc_pingback_source_uri', array( $this, 'reject_xmlrpc_self' ) );

		// Skip the post-author notification email for self-pingbacks that
		// somehow slipped through (e.g. legacy comments).
		add_filter( 'notify_post_author', array( $this, 'skip_self_pingback_email' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Filters
	 * ------------------------------------------------------------------- */

	/**
	 * Drop any URL pointing at our own host from the ping list.
	 *
	 * @param string[] $links URLs WordPress is about to ping. Passed by ref.
	 * @return void
	 */
	public function strip_self_pings( &$links ) {
		$home = $this->home_domain();

		if ( '' === $home || ! is_array( $links ) ) {
			return;
		}

		foreach ( $links as $key => $link ) {
			if ( $home === $this->host_of( $link ) ) {
				unset( $links[ $key ] );
			}
		}
	}

	/**
	 * Refuse an incoming pingback whose source URL is on our own host.
	 *
	 * Returning false aborts the pingback in xmlrpc.php.
	 *
	 * @param string $source_uri URL the pingback claims to come from.
	 * @return string|false
	 */
	public function reject_xmlrpc_self( $source_uri ) {
		$home = $this->home_domain();

		if ( '' !== $home && $home === $this->host_of( $source_uri ) ) {
			return false;
		}

		return $source_uri;
	}

	/**
	 * Suppress the post-author notification email when a pingback or
	 * trackback comment comes from our own host.
	 *
	 * @param bool $notify     Whether to notify the author.
	 * @param int  $comment_id Comment id that just landed.
	 * @return bool
	 */
	public function skip_self_pingback_email( $notify, $comment_id ) {
		if ( ! $notify ) {
			return $notify;
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment || ! in_array( $comment->comment_type, array( 'pingback', 'trackback' ), true ) ) {
			return $notify;
		}

		$home = $this->home_domain();

		if ( '' !== $home && $home === $this->host_of( $comment->comment_author_url ) ) {
			return false;
		}

		return $notify;
	}

	/* ---------------------------------------------------------------------
	 * Host helpers
	 * ------------------------------------------------------------------- */

	/**
	 * The site's own host, lower-cased and with a leading "www." stripped.
	 *
	 * @return string
	 */
	private function home_domain() {
		return $this->normalise_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * The host portion of any URL, normalised the same way.
	 *
	 * @param mixed $url URL to inspect.
	 * @return string
	 */
	private function host_of( $url ) {
		return $this->normalise_host( wp_parse_url( (string) $url, PHP_URL_HOST ) );
	}

	/**
	 * Lower-case and strip a leading "www." so example.com == www.example.com.
	 *
	 * @param mixed $host Host candidate.
	 * @return string
	 */
	private function normalise_host( $host ) {
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		return (string) preg_replace( '/^www\./i', '', strtolower( $host ) );
	}
}
