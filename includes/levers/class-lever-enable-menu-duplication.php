<?php
/**
 * Lever: enable nav menu duplication.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Duplicate menu" link beside "Delete Menu" on the Appearance >
 * Menus screen.
 *
 * Clicking it creates a copy of the currently-selected menu with:
 *   - Name suffixed with " (copy)".
 *   - Every menu item copied, preserving parent/child hierarchy,
 *     menu order, CSS classes, XFN, title attribute, target, etc.
 *   - Per-item postmeta copied (less internal edit-lock keys).
 *
 * The clone is NOT assigned to any theme location - locations remain on
 * the original. After duplication the user is redirected to the new menu
 * in the editor.
 */
class Levers_Lever_Enable_Menu_Duplication extends Levers_Lever {

	/** Admin action slug used by the duplicate handler URL. */
	const ACTION = 'levers_duplicate_menu';

	/** Nonce action prefix; the menu id gets appended per link. */
	const NONCE_PREFIX = 'levers-duplicate-menu-';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'enable-menu-duplication';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Enable menu duplication', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds a "Duplicate menu" link beside "Delete Menu" on the Menus screen. Clones every item, preserving hierarchy and meta, into a new draft menu.', 'levers' );
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
		return 'book-copy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_footer-nav-menus.php', array( $this, 'inject_duplicate_link' ) );
		add_action( 'admin_action_' . self::ACTION, array( $this, 'handle_duplicate' ) );
	}

	/* ---------------------------------------------------------------------
	 * Duplicate link
	 * ------------------------------------------------------------------- */

	/**
	 * Print a tiny script that inserts a "Duplicate menu" link next to the
	 * existing "Delete Menu" link in the menu editor.
	 *
	 * Core's nav-menus.php doesn't expose a hook in that spot, so we splice
	 * the link in from the client. The link target itself is a nonced URL
	 * handled by {@see self::handle_duplicate()}.
	 *
	 * @return void
	 */
	public function inject_duplicate_link() {
		global $nav_menu_selected_id;

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$menu_id = isset( $nav_menu_selected_id ) ? (int) $nav_menu_selected_id : 0;

		if ( $menu_id <= 0 ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'menu'   => $menu_id,
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_PREFIX . $menu_id
		);

		?>
		<script>
		(function () {
			var leversDuplicateUrl   = <?php echo wp_json_encode( $url ); ?>;
			var leversDuplicateLabel = <?php echo wp_json_encode( __( 'Duplicate menu', 'levers' ) ); ?>;

			function inject() {
				var del = document.querySelector( '.menu-edit .delete-action' );
				if ( ! del || del.previousElementSibling && del.previousElementSibling.classList.contains( 'levers-duplicate-action' ) ) {
					return;
				}

				var span = document.createElement( 'span' );
				span.className = 'levers-duplicate-action';
				span.style.marginRight = '8px';

				var a = document.createElement( 'a' );
				a.href = leversDuplicateUrl;
				a.className = 'submitduplicate';
				a.textContent = leversDuplicateLabel;

				span.appendChild( a );
				del.parentNode.insertBefore( span, del );
			}

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', inject );
			} else {
				inject();
			}
		})();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Duplicate handler
	 * ------------------------------------------------------------------- */

	/**
	 * Clone the requested menu and redirect to its edit screen.
	 *
	 * @return void
	 */
	public function handle_duplicate() {
		$menu_id = isset( $_GET['menu'] ) ? (int) $_GET['menu'] : 0;

		if ( $menu_id <= 0 ) {
			wp_die( esc_html__( 'No menu specified.', 'levers' ) );
		}

		check_admin_referer( self::NONCE_PREFIX . $menu_id );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate this menu.', 'levers' ) );
		}

		$original = wp_get_nav_menu_object( $menu_id );

		if ( ! $original || is_wp_error( $original ) ) {
			wp_die( esc_html__( 'Original menu no longer exists.', 'levers' ) );
		}

		/* translators: %s: original menu name. */
		$new_name = sprintf( __( '%s (copy)', 'levers' ), $original->name );
		$new_name = $this->unique_menu_name( $new_name );

		$new_menu_id = wp_update_nav_menu_object(
			0,
			array(
				'menu-name' => $new_name,
			)
		);

		if ( is_wp_error( $new_menu_id ) || ! $new_menu_id ) {
			wp_die( esc_html__( 'Could not create the duplicate menu.', 'levers' ) );
		}

		$this->copy_menu_items( (int) $menu_id, (int) $new_menu_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => 'edit',
					'menu'   => (int) $new_menu_id,
				),
				admin_url( 'nav-menus.php' )
			)
		);
		exit;
	}

	/**
	 * Copy every item from the source menu into the destination menu,
	 * preserving parent/child hierarchy and per-item postmeta.
	 *
	 * @param int $from Source menu id.
	 * @param int $to   Destination menu id.
	 * @return void
	 */
	private function copy_menu_items( $from, $to ) {
		$items = wp_get_nav_menu_items(
			$from,
			array(
				'orderby'     => 'menu_order',
				'post_status' => 'any',
			)
		);

		if ( empty( $items ) || ! is_array( $items ) ) {
			return;
		}

		// Old item id => new item id, used to remap parent references as
		// we go. wp_get_nav_menu_items returns parents before children
		// when ordered by menu_order, so a single pass is enough.
		$id_map = array();

		foreach ( $items as $item ) {
			$new_parent = 0;

			if ( ! empty( $item->menu_item_parent ) && isset( $id_map[ (int) $item->menu_item_parent ] ) ) {
				$new_parent = (int) $id_map[ (int) $item->menu_item_parent ];
			}

			$args = array(
				'menu-item-db-id'       => 0,
				'menu-item-object-id'   => (int) $item->object_id,
				'menu-item-object'      => (string) $item->object,
				'menu-item-parent-id'   => $new_parent,
				'menu-item-position'    => (int) $item->menu_order,
				'menu-item-type'        => (string) $item->type,
				'menu-item-title'       => (string) $item->title,
				'menu-item-url'         => (string) $item->url,
				'menu-item-description' => (string) $item->description,
				'menu-item-attr-title'  => (string) $item->attr_title,
				'menu-item-target'      => (string) $item->target,
				'menu-item-classes'     => implode( ' ', (array) $item->classes ),
				'menu-item-xfn'         => (string) $item->xfn,
				'menu-item-status'      => 'publish',
			);

			$new_item_id = wp_update_nav_menu_item( $to, 0, $args );

			if ( is_wp_error( $new_item_id ) || ! $new_item_id ) {
				continue;
			}

			$id_map[ (int) $item->ID ] = (int) $new_item_id;

			$this->copy_item_postmeta( (int) $item->ID, (int) $new_item_id );
		}
	}

	/**
	 * Copy postmeta from one menu item to another, skipping the keys that
	 * wp_update_nav_menu_item already set and core internal lock keys.
	 *
	 * @param int $from Source menu item id (a post id).
	 * @param int $to   Destination menu item id (a post id).
	 * @return void
	 */
	private function copy_item_postmeta( $from, $to ) {
		global $wpdb;

		$skip = array(
			'_edit_lock',
			'_edit_last',
			'_menu_item_type',
			'_menu_item_menu_item_parent',
			'_menu_item_object_id',
			'_menu_item_object',
			'_menu_item_target',
			'_menu_item_classes',
			'_menu_item_xfn',
			'_menu_item_url',
		);

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

	/**
	 * Return a menu name that doesn't already exist, suffixing " 2", " 3"
	 * etc. if needed. wp_update_nav_menu_object would otherwise return a
	 * "menu exists" error for a second duplicate of the same source menu.
	 *
	 * @param string $name Candidate name.
	 * @return string
	 */
	private function unique_menu_name( $name ) {
		if ( ! wp_get_nav_menu_object( $name ) ) {
			return $name;
		}

		$i = 2;
		do {
			$candidate = $name . ' ' . $i;
			$i++;
		} while ( wp_get_nav_menu_object( $candidate ) && $i < 100 );

		return $candidate;
	}
}
