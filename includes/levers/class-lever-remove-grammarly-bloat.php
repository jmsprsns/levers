<?php
/**
 * Lever: remove Grammarly code bloat.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strips Grammarly's leftover markup from post content.
 *
 * When you copy out of Grammarly and paste into the editor, Grammarly leaves
 * two signature artefacts behind:
 *
 *   - <span data-preserver-spaces="true">...</span> wrappers around runs of
 *     text, used to preserve trailing/leading spaces in their tool.
 *   - <a class="editor-rtfLink" ...> on links, marking them as having come
 *     out of Grammarly's rich-text export.
 *
 * Neither does anything useful once it lands in WordPress; they just bloat
 * the markup. This lever cleans them on save (via `wp_insert_post_data`,
 * before the row is written to the database) and also filters them out at
 * render time, so existing dirty posts are also cleaned up on display.
 */
class Levers_Lever_Remove_Grammarly_Bloat extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'remove-grammarly-bloat';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Remove Grammarly code bloat', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Strips the leftover spans and link classes Grammarly leaves behind in pasted content. Cleans both newly-saved posts and rendered output.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'frontend';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'eraser';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Render-time cleanup: catches dirty content already sitting in DB.
		add_filter( 'the_content', array( $this, 'strip_grammarly' ), 5 );

		// Save-time cleanup: clean before the row is written, so the DB
		// itself is gradually cleansed as posts are re-saved. Hooking
		// wp_insert_post_data avoids the save_post -> wp_update_post
		// recursion the inspiration snippet had to guard against manually.
		add_filter( 'wp_insert_post_data', array( $this, 'clean_post_data' ) );
	}

	/**
	 * Clean the post_content that's about to be written to the posts table.
	 *
	 * @param array $data Slashed post data heading for the database.
	 * @return array
	 */
	public function clean_post_data( $data ) {
		if ( ! is_array( $data ) || empty( $data['post_content'] ) || ! is_string( $data['post_content'] ) ) {
			return $data;
		}

		// $data is slashed - unslash, clean, re-slash.
		$content              = wp_unslash( $data['post_content'] );
		$cleaned              = $this->strip_grammarly( $content );
		$data['post_content'] = wp_slash( $cleaned );

		return $data;
	}

	/**
	 * Strip Grammarly's signature markup from a chunk of HTML.
	 *
	 * @param mixed $content HTML content.
	 * @return mixed
	 */
	public function strip_grammarly( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		// Fast path: if neither marker is present, skip the regexes.
		if ( false === stripos( $content, 'editor-rtfLink' )
			&& false === stripos( $content, 'data-preserver-spaces' )
		) {
			return $content;
		}

		// Drop class="editor-rtfLink" (handles single or double quotes,
		// with a leading space so we don't leave a stray double space).
		$content = preg_replace(
			'/\s+class=(["\'])editor-rtfLink\1/i',
			'',
			(string) $content
		);

		// Unwrap <span data-preserver-spaces[="..."]>...</span>, keeping
		// the inner text. The `s` flag lets the inner content span lines.
		$content = preg_replace(
			'/<span\s+data-preserver-spaces(?:=(["\'])[^"\']*\1)?\s*>(.*?)<\/span>/is',
			'$2',
			(string) $content
		);

		return $content;
	}
}
