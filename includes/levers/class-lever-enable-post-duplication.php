<?php
/**
 * Lever: enable post/page duplication.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Duplicate" row action to posts, pages and any other public
 * post type's admin list table.
 *
 * Clicking it creates a draft copy of the original with:
 *   - Title suffixed with " (copy)".
 *   - Same content / excerpt / parent / menu order / comment status.
 *   - Same taxonomy terms.
 *   - Same post meta (less internal lock keys).
 *   - Authorship set to whoever clicked.
 *
 * The clone is created as `draft` regardless of the original's status,
 * then the editor is opened on it - ready for the user to tweak and
 * publish (or trash). Nothing is published silently.
 */
class Levers_Lever_Enable_Post_Duplication extends Levers_Lever {

	/** Admin action slug used by the duplicate handler URL. */
	const ACTION = 'levers_duplicate_post';

	/** Nonce action prefix; the post id gets appended per row. */
	const NONCE_PREFIX = 'levers-duplicate-';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'enable-post-duplication';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Enable post/page duplication', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds a Duplicate row action to posts, pages and CPTs. Clones content, meta and taxonomies as a draft, ready for edit.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'admin-tools';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'copy-plus';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( ! is_admin() ) {
			return;
		}

		// post_row_actions covers non-hierarchical post types (post + CPTs),
		// page_row_actions covers hierarchical types (page + hierarchical CPTs).
		add_filter( 'post_row_actions', array( $this, 'add_duplicate_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_duplicate_action' ), 10, 2 );

		// Click handler: admin_action_{$action} on admin.php.
		add_action( 'admin_action_' . self::ACTION, array( $this, 'handle_duplicate' ) );
	}

	/* ---------------------------------------------------------------------
	 * Row action
	 * ------------------------------------------------------------------- */

	/**
	 * Append a "Duplicate" link to the row actions, when the current user
	 * can edit the post.
	 *
	 * @param array   $actions Existing actions keyed by slug.
	 * @param WP_Post $post    The row's post.
	 * @return array
	 */
	public function add_duplicate_action( $actions, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post->ID,
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_PREFIX . $post->ID
		);

		$actions['levers_duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr(
				sprintf(
					/* translators: %s: post title. */
					__( 'Duplicate "%s"', 'levers' ),
					$post->post_title
				)
			),
			esc_html__( 'Duplicate', 'levers' )
		);

		return $actions;
	}

	/* ---------------------------------------------------------------------
	 * Duplicate handler
	 * ------------------------------------------------------------------- */

	/**
	 * Clone the requested post and redirect to its edit screen.
	 *
	 * @return void
	 */
	public function handle_duplicate() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( $post_id <= 0 ) {
			wp_die( esc_html__( 'No post specified.', 'levers' ) );
		}

		check_admin_referer( self::NONCE_PREFIX . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate this post.', 'levers' ) );
		}

		$original = get_post( $post_id );

		if ( ! $original ) {
			wp_die( esc_html__( 'Original post no longer exists.', 'levers' ) );
		}

		$new_id = wp_insert_post(
			array(
				/* translators: %s: original post title. */
				'post_title'     => sprintf( __( '%s (copy)', 'levers' ), $original->post_title ),
				'post_content'   => $original->post_content,
				'post_excerpt'   => $original->post_excerpt,
				'post_status'    => 'draft',
				'post_type'      => $original->post_type,
				'post_author'    => get_current_user_id(),
				'post_parent'    => $original->post_parent,
				'menu_order'     => $original->menu_order,
				'comment_status' => $original->comment_status,
				'ping_status'    => $original->ping_status,
				'to_ping'        => $original->to_ping,
				'pinged'         => $original->pinged,
			),
			true
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			wp_die( esc_html__( 'Could not create the duplicate.', 'levers' ) );
		}

		$this->copy_taxonomies( $original, (int) $new_id );
		$this->copy_postmeta( $post_id, (int) $new_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => 'edit',
					'post'   => (int) $new_id,
				),
				admin_url( 'post.php' )
			)
		);
		exit;
	}

	/**
	 * Copy every taxonomy attached to the post.
	 *
	 * @param WP_Post $original   Source.
	 * @param int     $new_id     Destination.
	 * @return void
	 */
	private function copy_taxonomies( $original, $new_id ) {
		$taxonomies = get_object_taxonomies( $original->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $original->ID, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy );
		}
	}

	/**
	 * Copy postmeta, skipping core internal lock keys.
	 *
	 * @param int $from Source post id.
	 * @param int $to   Destination post id.
	 * @return void
	 */
	private function copy_postmeta( $from, $to ) {
		global $wpdb;

		$skip = array( '_edit_lock', '_edit_last' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot meta clone.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				$from
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( in_array( $row->meta_key, $skip, true ) ) {
				continue;
			}

			add_post_meta( $to, $row->meta_key, maybe_unserialize( $row->meta_value ) );
		}
	}
}
