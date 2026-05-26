<?php
/**
 * Lever: remove the "Website" field from the comment form.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulls the URL field out of the default comment form and refuses to
 * accept one server-side either.
 *
 * The Website field is the single biggest reason blog-comment spam
 * exists: it's a sanctioned slot to drop a backlink in. Take the slot
 * away and most of the spam goes with it. Pairs with the "Prevent links
 * in blog comments" lever: that one catches links in the comment body,
 * this one removes the sanctioned link slot at the source.
 *
 * Two layers:
 *   - `comment_form_default_fields` filter strips the field from the
 *     comment form WordPress renders.
 *   - `preprocess_comment` blanks `comment_author_url` on submission, so
 *     a custom theme that still renders the field (or a bot POSTing
 *     direct to wp-comments-post.php) can't sneak a URL through.
 */
class Levers_Lever_Remove_Comment_Url_Field extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'remove-comment-url-field';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Remove comment website field', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Removes the 'Website' field from the comment form (and strips any URL posted directly). Kills comment spam's main incentive.", 'levers' );
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
		return 'globe-x';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'comment_form_default_fields', array( $this, 'remove_url_field' ) );
		add_filter( 'preprocess_comment', array( $this, 'strip_author_url' ) );
	}

	/**
	 * Drop the `url` field from the default comment form.
	 *
	 * @param mixed $fields Default fields array.
	 * @return array
	 */
	public function remove_url_field( $fields ) {
		if ( is_array( $fields ) ) {
			unset( $fields['url'] );
		}

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Blank out comment_author_url on every incoming comment.
	 *
	 * @param mixed $commentdata Submitted comment data.
	 * @return array
	 */
	public function strip_author_url( $commentdata ) {
		if ( is_array( $commentdata ) ) {
			$commentdata['comment_author_url'] = '';
		}

		return $commentdata;
	}
}
