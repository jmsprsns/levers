<?php
/**
 * Lever: remove the default "Uncategorized" category.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hides the default "Uncategorized" category everywhere categories are listed.
 *
 * This is intentionally non-destructive: the term and any posts attached to it
 * are left untouched, so switching the lever off brings the category straight
 * back. It simply disappears from category pickers, lists and widgets.
 */
class Levers_Lever_Remove_Uncategorized extends Levers_Lever {

	/**
	 * Cached "Uncategorized" term id (0 once looked up and not found).
	 *
	 * @var int|null
	 */
	private $term_id = null;

	/**
	 * True while we are resolving our own term id, so the get_terms_args
	 * filter can bail and avoid recursing into itself.
	 *
	 * @var bool
	 */
	private $resolving = false;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'remove-uncategorized';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Remove "Uncategorized" category', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Hides the default "Uncategorized" category from pickers, lists and widgets. Reversible - nothing is deleted.', 'levers' );
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
		return 'folder-x';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'get_terms_args', array( $this, 'exclude_uncategorized' ), 10, 2 );
	}

	/**
	 * Add the "Uncategorized" term to the exclude list for category queries.
	 *
	 * @param array    $args       Arguments passed to get_terms().
	 * @param string[] $taxonomies Taxonomies being queried.
	 * @return array
	 */
	public function exclude_uncategorized( $args, $taxonomies ) {
		// Resolving our own term id runs a category query that comes back
		// through this very filter - leave that lookup untouched.
		if ( $this->resolving ) {
			return $args;
		}

		if ( ! in_array( 'category', (array) $taxonomies, true ) ) {
			return $args;
		}

		$term_id = $this->get_uncategorized_id();

		if ( ! $term_id ) {
			return $args;
		}

		$exclude         = empty( $args['exclude'] ) ? array() : (array) $args['exclude'];
		$exclude[]       = $term_id;
		$args['exclude'] = $exclude;

		return $args;
	}

	/**
	 * Resolve (and cache) the id of the "Uncategorized" category.
	 *
	 * get_term_by() runs a get_terms() query internally, which fires the
	 * get_terms_args filter - including this lever's own callback. The
	 * $resolving guard short-circuits that callback for the duration of the
	 * lookup so it cannot recurse infinitely.
	 *
	 * @return int 0 when the category does not exist.
	 */
	private function get_uncategorized_id() {
		if ( null === $this->term_id ) {
			$this->resolving = true;
			$term            = get_term_by( 'slug', 'uncategorized', 'category' );
			$this->resolving = false;

			$this->term_id = ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
		}

		return (int) $this->term_id;
	}
}
