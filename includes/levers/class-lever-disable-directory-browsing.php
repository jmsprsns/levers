<?php
/**
 * Lever: disable Apache directory browsing.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds `Options -Indexes` to the root .htaccess so visitors can't list
 * the contents of folders that lack an index file.
 *
 * Apache's default-on autoindex is a classic info-leak: hit a path with
 * no index file and you get a clickable listing of every file in that
 * directory. The fix is one directive, but it has to live in .htaccess.
 *
 * Like Force SSL and Block PHP in uploads, this is flagged unavailable
 * on non-Apache/LiteSpeed servers - nginx ignores .htaccess and the
 * equivalent (`autoindex off;`) has to go in the server config by hand.
 */
class Levers_Lever_Disable_Directory_Browsing extends Levers_Lever {

	/** Marker name in the root .htaccess. */
	const MARKER = 'Levers Disable Directory Browsing';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-directory-browsing';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable directory browsing', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Adds Options -Indexes to .htaccess so visitors can't list raw folder contents - a classic info-leak shut at the server level.", 'levers' );
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
		return 'folder-tree';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return $this->server_uses_htaccess() && $this->root_writable();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason() {
		if ( ! $this->server_uses_htaccess() ) {
			return __( "Requires Apache or LiteSpeed (nginx ignores .htaccess; set autoindex off in your server config).", 'levers' );
		}

		if ( ! $this->root_writable() ) {
			return __( 'The WordPress root is not writable.', 'levers' );
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'admin_init', array( $this, 'self_heal' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_enable() {
		$this->write_htaccess_block();
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_disable() {
		$this->remove_htaccess_block();
	}

	/**
	 * Daily check: rewrite the marker if it's gone missing.
	 *
	 * @return void
	 */
	public function self_heal() {
		if ( get_transient( 'levers_disable_dir_browsing_check' ) ) {
			return;
		}

		set_transient( 'levers_disable_dir_browsing_check', 1, DAY_IN_SECONDS );

		if ( $this->server_uses_htaccess() && ! $this->htaccess_marker_present() ) {
			$this->write_htaccess_block();
		}
	}

	/* ---------------------------------------------------------------------
	 * Server checks
	 * ------------------------------------------------------------------- */

	/**
	 * Apache / LiteSpeed?
	 *
	 * @return bool
	 */
	private function server_uses_htaccess() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';

		return false !== strpos( $software, 'apache' )
			|| false !== strpos( $software, 'litespeed' );
	}

	/**
	 * Is the WP root writable from PHP?
	 *
	 * @return bool
	 */
	private function root_writable() {
		$path = $this->htaccess_path();

		// File already there? Just needs to be writable.
		if ( file_exists( $path ) ) {
			return is_writable( $path );
		}

		// File missing? We need to be able to create it in the root.
		return wp_is_writable( ABSPATH );
	}

	/* ---------------------------------------------------------------------
	 * .htaccess management
	 * ------------------------------------------------------------------- */

	/**
	 * Root .htaccess path.
	 *
	 * @return string
	 */
	private function htaccess_path() {
		return ABSPATH . '.htaccess';
	}

	/**
	 * The one-line rule we drop in.
	 *
	 * @return string[]
	 */
	private function rule_lines() {
		return array(
			'Options -Indexes',
		);
	}

	/**
	 * Is our marker already in the file?
	 *
	 * @return bool
	 */
	private function htaccess_marker_present() {
		$path = $this->htaccess_path();

		if ( ! file_exists( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- root htaccess.
		$content = @file_get_contents( $path );

		return is_string( $content ) && false !== strpos( $content, '# BEGIN ' . self::MARKER );
	}

	/**
	 * Add (or refresh) the marker block.
	 *
	 * @return void
	 */
	private function write_htaccess_block() {
		if ( ! $this->is_available() ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		insert_with_markers( $this->htaccess_path(), self::MARKER, $this->rule_lines() );
	}

	/**
	 * Remove the marker block; preserve everything else in the file.
	 *
	 * @return void
	 */
	private function remove_htaccess_block() {
		$path = $this->htaccess_path();

		if ( ! file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- root htaccess.
		$current = @file_get_contents( $path );

		if ( false === $current ) {
			return;
		}

		$pattern  = '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '.*?# END ' . preg_quote( self::MARKER, '/' ) . '\s*$/sm';
		$stripped = (string) preg_replace( $pattern, '', $current );
		$stripped = trim( $stripped );

		if ( '' === $stripped ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- file we manage.
			@unlink( $path );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- root htaccess.
		@file_put_contents( $path, $stripped . "\n" );
	}
}
