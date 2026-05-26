<?php
/**
 * Lever: block PHP execution inside /wp-content/uploads.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drops an .htaccess rule into the uploads folder that refuses to execute
 * any .php (or .phtml / .phar / etc.) file living under it.
 *
 * The uploads folder is the most popular malware persistence spot on
 * compromised WordPress installs: an attacker who gets a single .php
 * file uploaded there has a backdoor they can hit at a public URL.
 * Blocking PHP execution under /uploads neutralises that pattern.
 *
 * Server-dependent: only meaningful on Apache and LiteSpeed (which read
 * .htaccess). On nginx the lever marks itself unavailable - the rule has
 * to live in the server-level config, not a per-directory file.
 */
class Levers_Lever_Block_Php_In_Uploads extends Levers_Lever {

	/** Marker name used inside the .htaccess. */
	const MARKER = 'Levers Block PHP in Uploads';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'block-php-in-uploads';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Block PHP execution in uploads', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Drops an .htaccess rule into /wp-content/uploads so PHP files there can't run - the most common malware-persistence path.", 'levers' );
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
		return 'folder-lock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return $this->server_uses_htaccess() && $this->uploads_writable();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason() {
		if ( ! $this->server_uses_htaccess() ) {
			return __( 'Requires Apache or LiteSpeed (nginx ignores .htaccess).', 'levers' );
		}

		if ( ! $this->uploads_writable() ) {
			return __( 'The uploads folder is not writable.', 'levers' );
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// The .htaccess does the work, but we self-heal it on admin loads
		// so the rule survives manual deletion of the file and the
		// "lever defaulted on but on_enable() never had a transition to
		// react to" case on a fresh install.
		add_action( 'admin_init', array( $this, 'ensure_htaccess_present' ) );
	}

	/**
	 * Re-write the .htaccess marker if it's gone missing. Throttled to
	 * one filesystem check per hour to stay cheap.
	 *
	 * @return void
	 */
	public function ensure_htaccess_present() {
		if ( get_transient( 'levers_uploads_htaccess_ok' ) ) {
			return;
		}

		set_transient( 'levers_uploads_htaccess_ok', 1, HOUR_IN_SECONDS );

		$path = $this->htaccess_path();

		if ( '' === $path ) {
			return;
		}

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file we manage.
			$content = @file_get_contents( $path );

			if ( is_string( $content ) && false !== strpos( $content, '# BEGIN ' . self::MARKER ) ) {
				return; // Marker already in place.
			}
		}

		$this->write_htaccess();
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_enable() {
		$this->write_htaccess();
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_disable() {
		$this->remove_htaccess_section();
	}

	/* ---------------------------------------------------------------------
	 * Server checks
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the server is one that honours .htaccess files.
	 *
	 * @return bool
	 */
	private function server_uses_htaccess() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';

		if ( '' === $software ) {
			return false;
		}

		return false !== strpos( $software, 'apache' )
			|| false !== strpos( $software, 'litespeed' );
	}

	/**
	 * Whether the uploads folder is writable from PHP.
	 *
	 * @return bool
	 */
	private function uploads_writable() {
		$upload = wp_upload_dir();

		if ( empty( $upload['basedir'] ) || ! empty( $upload['error'] ) ) {
			return false;
		}

		return wp_is_writable( $upload['basedir'] );
	}

	/* ---------------------------------------------------------------------
	 * .htaccess management
	 * ------------------------------------------------------------------- */

	/**
	 * Full path to the .htaccess we manage inside uploads/.
	 *
	 * @return string '' if uploads dir is unavailable.
	 */
	private function htaccess_path() {
		$upload = wp_upload_dir();

		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $upload['basedir'] ) . '.htaccess';
	}

	/**
	 * Rule lines we drop between the markers.
	 *
	 * @return string[]
	 */
	private function rule_lines() {
		return array(
			'<FilesMatch "\.(php|phtml|phar|php[34578]|pht|phps)$">',
			'    <IfModule mod_authz_core.c>',
			'        Require all denied',
			'    </IfModule>',
			'    <IfModule !mod_authz_core.c>',
			'        Order Allow,Deny',
			'        Deny from all',
			'    </IfModule>',
			'</FilesMatch>',
		);
	}

	/**
	 * Write (or update) the marked section in uploads/.htaccess.
	 *
	 * @return void
	 */
	private function write_htaccess() {
		$path = $this->htaccess_path();

		if ( '' === $path ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		insert_with_markers( $path, self::MARKER, $this->rule_lines() );
	}

	/**
	 * Strip our marked section out of uploads/.htaccess. Deletes the file
	 * entirely when it becomes empty so we don't leave a stub behind.
	 *
	 * @return void
	 */
	private function remove_htaccess_section() {
		$path = $this->htaccess_path();

		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file we created.
		$current = file_get_contents( $path );

		if ( false === $current ) {
			return;
		}

		$pattern = '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '.*?# END ' . preg_quote( self::MARKER, '/' ) . '\s*$/sm';
		$stripped = (string) preg_replace( $pattern, '', $current );
		$stripped = trim( $stripped );

		if ( '' === $stripped ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing a file we wrote.
			@unlink( $path );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- local file we manage.
		@file_put_contents( $path, $stripped . "\n" );
	}
}
