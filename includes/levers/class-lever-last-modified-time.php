<?php
/**
 * Lever: last modified time column.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a sortable "Modified" column to the Posts, Pages and other post-type
 * list tables, sitting just after the built-in publish "Date" column.
 *
 * Display: human_time_diff() for the cell text, full localised datetime in
 * the title attribute (tooltip on hover).
 *
 * Sort: post_modified is sortable natively in WP_Query, so clicking the
 * column header simply maps the orderby to "modified" via pre_get_posts.
 */
class Levers_Lever_Last_Modified_Time extends Levers_Lever {

	/** Sortable-column id used in the post list tables. */
	const COLUMN_ID = 'levers_last_modified';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'last-modified-time';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Last modified time', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds a sortable "Modified" column beside the publish date on the Posts and Pages screens so you can sort by when content was last edited.', 'levers' );
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
		return 'calendar-clock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'manage_posts_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_pages_columns', array( $this, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'current_screen', array( $this, 'mark_sortable_for_screen' ) );
	}

	/* ---------------------------------------------------------------------
	 * Column rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Insert the "Modified" header directly after the publish "Date" column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'date' === $key ) {
				$new[ self::COLUMN_ID ] = __( 'Modified', 'levers' );
			}
		}

		// Fall back to appending if there was no built-in date column.
		if ( ! isset( $new[ self::COLUMN_ID ] ) ) {
			$new[ self::COLUMN_ID ] = __( 'Modified', 'levers' );
		}

		return $new;
	}

	/**
	 * Render a cell for the "Modified" column.
	 *
	 * @param string $column_name Column id being rendered.
	 * @param int    $post_id     Post id for this row.
	 * @return void
	 */
	public function render_column( $column_name, $post_id ) {
		if ( self::COLUMN_ID !== $column_name ) {
			return;
		}

		$ts = get_post_timestamp( $post_id, 'modified' );

		if ( ! $ts ) {
			echo '<span style="color:#a7aaad;">&mdash;</span>';
			return;
		}

		$now      = time();
		$relative = $ts <= $now
			? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours". */ __( '%s ago', 'levers' ), human_time_diff( $ts, $now ) )
			: human_time_diff( $now, $ts );

		$format   = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$absolute = wp_date( $format, $ts );

		printf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( $absolute ),
			esc_html( $relative )
		);
	}

	/* ---------------------------------------------------------------------
	 * Sorting
	 * ------------------------------------------------------------------- */

	/**
	 * Mark the column sortable on the current edit screen.
	 *
	 * The sortable-columns filter is per post type
	 * (manage_edit-{post_type}_sortable_columns), so we register it against
	 * whichever list table is being shown.
	 *
	 * @param WP_Screen $screen Current admin screen.
	 * @return void
	 */
	public function mark_sortable_for_screen( $screen ) {
		if ( ! ( $screen instanceof WP_Screen ) || 'edit' !== $screen->base ) {
			return;
		}

		add_filter( "manage_{$screen->id}_sortable_columns", array( $this, 'mark_sortable' ) );
	}

	/**
	 * Add our column to the sortable set, mapped to the post's modified date.
	 *
	 * "modified" is a native WP_Query orderby, so clicking the header sorts
	 * by post_modified without any extra query handling on our part.
	 *
	 * @param array $sortable Existing sortable columns.
	 * @return array
	 */
	public function mark_sortable( $sortable ) {
		$sortable[ self::COLUMN_ID ] = 'modified';
		return $sortable;
	}
}
