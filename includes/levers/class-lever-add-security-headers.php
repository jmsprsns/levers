<?php
/**
 * Lever: send a small set of recommended security headers.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds X-Frame-Options, X-Content-Type-Options, Referrer-Policy and a
 * restrictive Permissions-Policy header to every response.
 *
 * These are the four "free" security headers every site should send:
 *
 *   - X-Frame-Options: SAMEORIGIN keeps the site from being embedded
 *     in an iframe on someone else's domain, which is the basis of
 *     clickjacking. The block editor still works because same-origin
 *     iframes are allowed.
 *
 *   - X-Content-Type-Options: nosniff stops browsers from guessing a
 *     MIME type when the server's declared one doesn't look right. The
 *     usual abuse pattern is a .jpg that's actually HTML/JS getting
 *     run as a script.
 *
 *   - Referrer-Policy: strict-origin-when-cross-origin sends the full
 *     URL to same-origin links, the origin only to cross-origin HTTPS
 *     links, and nothing on HTTPS->HTTP downgrades. It's the modern
 *     default that newer browsers apply anyway, but older clients
 *     need it spelled out.
 *
 *   - Permissions-Policy denies camera, microphone, geolocation,
 *     payment and a few other sensitive APIs to the page and any
 *     iframes it embeds. Sites that need these should turn the lever
 *     off (or adjust the policy server-side).
 *
 * If a header is already on the response - because the server, another
 * plugin or a CDN added it first - we don't overwrite it. The lever
 * fills gaps; it doesn't compete.
 */
class Levers_Lever_Add_Security_Headers extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'add-security-headers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Add security headers', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Sends X-Frame-Options, X-Content-Type-Options, Referrer-Policy and a restrictive Permissions-Policy with every response.', 'levers' );
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
		return 'shield-check';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Front-end responses (incl. REST and feeds) flow through send_headers.
		add_action( 'send_headers', array( $this, 'send_security_headers' ) );

		// Admin and login pages don't fire send_headers, so hook them too,
		// early enough that nothing has flushed output yet.
		add_action( 'admin_init', array( $this, 'send_security_headers' ), 1 );
		add_action( 'login_init', array( $this, 'send_security_headers' ), 1 );
	}

	/**
	 * Emit each header in {@see headers()}, unless something already
	 * sent that header at the PHP layer.
	 *
	 * @return void
	 */
	public function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}

		$already = $this->existing_header_names();

		foreach ( $this->headers() as $name => $value ) {
			if ( isset( $already[ strtolower( $name ) ] ) ) {
				continue;
			}

			header( $name . ': ' . $value );
		}
	}

	/**
	 * The header name => value map we'd like the response to carry.
	 *
	 * Held in one place so the docblock for this class and the runtime
	 * agree, and so a site can filter the list if it really needs to.
	 *
	 * @return array<string,string>
	 */
	private function headers() {
		$headers = array(
			'X-Frame-Options'        => 'SAMEORIGIN',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			'Permissions-Policy'     => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
		);

		/**
		 * Filter the security headers Levers will send.
		 *
		 * Return an associative array of Header-Name => value. Setting
		 * a value to '' (empty string) drops the header entirely.
		 *
		 * @param array<string,string> $headers Default header set.
		 */
		$filtered = apply_filters( 'levers_security_headers', $headers );

		if ( ! is_array( $filtered ) ) {
			return $headers;
		}

		// Drop any explicitly-emptied entries.
		return array_filter(
			$filtered,
			static function ( $value ) {
				return is_string( $value ) && '' !== $value;
			}
		);
	}

	/**
	 * Lowercase set of header names PHP has already queued for output.
	 *
	 * Used to avoid overwriting a header set by another plugin or by
	 * the server above us. Server-level headers (.htaccess, nginx) are
	 * applied after PHP and aren't visible here - the browser will see
	 * both, and the more restrictive value wins for the headers above.
	 *
	 * @return array<string,true>
	 */
	private function existing_header_names() {
		$set = array();

		if ( ! function_exists( 'headers_list' ) ) {
			return $set;
		}

		foreach ( headers_list() as $header ) {
			$colon = strpos( $header, ':' );

			if ( false === $colon ) {
				continue;
			}

			$name = strtolower( trim( substr( $header, 0, $colon ) ) );

			if ( '' !== $name ) {
				$set[ $name ] = true;
			}
		}

		return $set;
	}
}
