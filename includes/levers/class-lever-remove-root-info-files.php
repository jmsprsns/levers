<?php
/**
 * Lever: remove readme.html and license.txt.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deletes - and, on Apache, .htaccess-blocks - the two root files that
 * advertise the exact WordPress version.
 *
 * `readme.html` includes a "Version X.Y" line at the top. `license.txt`
 * has the version in its filename context but, more importantly, its
 * presence at all confirms a WordPress install to scanners. Both files
 * are routinely fingerprinted by automated exploit kits looking for
 * version-specific vulnerabilities.
 *
 * Completes "Hide WordPress version" by closing the two channels that
 * lever doesn't cover.
 *
 * Strategy:
 *   - Delete both files (works on every server, doesn't depend on
 *     htaccess).
 *   - Add an .htaccess deny rule so they stay blocked if WordPress
 *     restores them on the next core update.
 *   - Self-heal once a day: if the files reappear (WP core update),
 *     delete them again.
 */
class Levers_Lever_Remove_Root_Info_Files extends Levers_Lever {

	/** Marker name used inside the root .htaccess. */
	const MARKER = 'Levers Block Root Info Files';

	/** Daily self-heal throttle. */
	const HEAL_TRANSIENT = 'levers_root_info_files_check';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'remove-root-info-files';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Remove readme.html & license.txt', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Deletes /readme.html and /license.txt (which broadcast your WordPress version) and blocks them via .htaccess in case they come back.', 'levers' );
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
		return 'file-x';
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
		$this->delete_files();
		$this->write_htaccess_block();
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_disable() {
		// Files came from WordPress; we don't try to restore them.
		// Just take our .htaccess section out.
		$this->remove_htaccess_block();
	}

	/**
	 * Daily check that re-deletes the files if WordPress put them back
	 * (a core update will), and ensures the .htaccess marker is present.
	 *
	 * @return void
	 */
	public function self_heal() {
		if ( get_transient( self::HEAL_TRANSIENT ) ) {
			return;
		}

		set_transient( self::HEAL_TRANSIENT, 1, DAY_IN_SECONDS );

		$this->delete_files();

		if ( $this->server_uses_htaccess() && ! $this->htaccess_marker_present() ) {
			$this->write_htaccess_block();
		}
	}

	/* ---------------------------------------------------------------------
	 * File deletion
	 * ------------------------------------------------------------------- */

	/**
	 * Files we manage.
	 *
	 * @return string[]
	 */
	private function target_files() {
		return array(
			ABSPATH . 'readme.html',
			ABSPATH . 'license.txt',
		);
	}

	/**
	 * Best-effort delete.
	 *
	 * @return void
	 */
	private function delete_files() {
		foreach ( $this->target_files() as $file ) {
			if ( file_exists( $file ) && is_writable( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- managing core info files.
				@unlink( $file );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * .htaccess management
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the server is Apache / LiteSpeed (honours .htaccess).
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
	 * Root .htaccess path.
	 *
	 * @return string
	 */
	private function htaccess_path() {
		return ABSPATH . '.htaccess';
	}

	/**
	 * .htaccess rule lines.
	 *
	 * @return string[]
	 */
	private function rule_lines() {
		return array(
			'<FilesMatch "^(readme\.html|license\.txt)$">',
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
	 * Whether our marker block is already in the root .htaccess.
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
	 * Add (or refresh) our marked block in the root .htaccess.
	 *
	 * @return void
	 */
	private function write_htaccess_block() {
		if ( ! $this->server_uses_htaccess() ) {
			return;
		}

		$path = $this->htaccess_path();

		if ( ! wp_is_writable( dirname( $path ) ) && ! is_writable( $path ) ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		insert_with_markers( $path, self::MARKER, $this->rule_lines() );
	}

	/**
	 * Strip our marked block out of the root .htaccess (no file delete,
	 * since other things live in this .htaccess).
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
