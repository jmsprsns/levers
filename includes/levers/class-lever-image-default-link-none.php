<?php
/**
 * Lever: default inserted images to "link to none".
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forces the editor's default "Link to" for inserted images to None.
 *
 * Out of the box WordPress wires every newly-inserted image to the
 * attachment page (or, in some installs, the media file). That's what
 * creates the empty single-image pages the Redirect attachment pages
 * lever then has to clean up after the fact. Setting the default to
 * "none" stops them at the source: new posts published from this point
 * insert images that don't link anywhere unless the author explicitly
 * sets a target.
 *
 * Existing posts aren't touched - this only affects images inserted
 * *after* the lever is on.
 */
class Levers_Lever_Image_Default_Link_None extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'image-default-link-none';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Default inserted images to no link', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Sets the editor's default 'Link to' for new images to None. WordPress otherwise links each one to its attachment page.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'unlink';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// pre_option_* short-circuits get_option() before it hits the DB.
		add_filter( 'pre_option_image_default_link_type', array( $this, 'force_none' ) );
	}

	/**
	 * Force image_default_link_type to 'none'.
	 *
	 * @return string
	 */
	public function force_none() {
		return 'none';
	}
}
