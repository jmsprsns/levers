<?php
/**
 * Lever: close the blog comment spam exploit.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Locks down comment approval to defeat a common spam exploit.
 *
 * Spammers post one or two harmless, link-free, AI-written comments and wait
 * for them to be approved. WordPress then auto-approves every later comment
 * from that "known good" author, so the spammers flood the site with spam
 * that sails straight through. This lever holds every comment for manual
 * review and removes the auto-approve-on-previous-approval shortcut they
 * depend on.
 */
class Levers_Lever_Close_Comment_Spam extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'close-comment-spam';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Close blog comment spam exploit', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Holds every comment for manual approval and stops auto-approving once-approved commenters. Closes the AI comment-spam exploit.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'spam';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'message-square-lock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Force "Comment must be manually approved" on.
		add_filter( 'option_comment_moderation', array( $this, 'force_checked' ) );

		// Force "Comment author must have a previously approved comment" off.
		add_filter( 'option_comment_previously_approved', array( $this, 'force_unchecked' ) );
	}

	/**
	 * Force an option to WordPress's "checked" value.
	 *
	 * Returns the string '1' because core compares this option with a strict
	 * `'1' === get_option( ... )`.
	 *
	 * @return string
	 */
	public function force_checked() {
		return '1';
	}

	/**
	 * Force an option to WordPress's "unchecked" value.
	 *
	 * @return string
	 */
	public function force_unchecked() {
		return '0';
	}
}
