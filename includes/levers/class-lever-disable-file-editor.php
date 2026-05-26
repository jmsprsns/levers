<?php
/**
 * Lever: disable the WordPress theme/plugin file editor.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Disables the in-dashboard theme and plugin file editors.
 *
 * Appearance > Theme File Editor and Plugins > Plugin File Editor are
 * the first thing an attacker who has stolen an admin session reaches
 * for: they let you write arbitrary PHP directly into a theme or plugin
 * file. Turning them off doesn't stop a determined attacker who has the
 * server itself, but it slams the easiest in-dashboard backdoor shut.
 *
 * Implementation: defines `DISALLOW_FILE_EDIT = true` (the WordPress-
 * sanctioned switch) and, as belt-and-suspenders for the rare case where
 * wp-config.php already pinned the constant to false, also denies the
 * `edit_themes`/`edit_plugins`/`edit_files` caps via map_meta_cap.
 */
class Levers_Lever_Disable_File_Editor extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-file-editor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable file editor', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Sets DISALLOW_FILE_EDIT so theme and plugin PHP can't be edited from the dashboard. First place an attacker hits after stealing a session.", 'levers' );
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
		return 'file-lock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// The official switch. Core's map_meta_cap checks this for
		// edit_files / edit_plugins / edit_themes.
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// Belt-and-suspenders: if the constant was already pinned to
		// false in wp-config.php we can't redefine it, but we can still
		// deny the caps directly.
		add_filter( 'map_meta_cap', array( $this, 'block_file_edit_caps' ), 10, 2 );
	}

	/**
	 * Force the file-editing capabilities to a never-allowed state.
	 *
	 * @param string[] $caps Required caps as resolved by core so far.
	 * @param string   $cap  The meta cap being checked.
	 * @return string[]
	 */
	public function block_file_edit_caps( $caps, $cap ) {
		$blocked = array( 'edit_themes', 'edit_plugins', 'edit_files' );

		if ( in_array( $cap, $blocked, true ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}
}
