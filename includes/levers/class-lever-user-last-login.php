<?php
/**
 * Lever: user last login.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records each user's last login timestamp and shows it as a sortable
 * "Last login" column on the Users list table.
 *
 * Storage: a single user-meta row per user (key: levers_user_last_login)
 * holding a Unix timestamp written from the wp_login action.
 *
 * Display: human_time_diff() for the cell text, full localised datetime
 * in the title attribute (tooltip on hover). Users with no recorded
 * login show "Never".
 *
 * Sort: pre_user_query adds a LEFT JOIN so users without the meta still
 * appear and sort to the bottom in DESC order.
 */
class Levers_Lever_User_Last_Login extends Levers_Lever {

	/** User-meta key holding the last-login Unix timestamp. */
	const META_KEY = 'levers_user_last_login';

	/** Sortable-column id used in the Users list table. */
	const COLUMN_ID = 'levers_last_login';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'user-last-login';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'User last login', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds a sortable "Last login" column to the Users screen so you can spot dormant accounts and recent activity at a glance.', 'levers' );
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
		return 'clock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'wp_login', array( $this, 'record_login' ), 10, 2 );

		if ( is_admin() ) {
			add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
			add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
			add_filter( 'manage_users_sortable_columns', array( $this, 'mark_sortable' ) );
			add_action( 'pre_user_query', array( $this, 'apply_sort' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Recording
	 * ------------------------------------------------------------------- */

	/**
	 * Persist the current time against the user that just logged in.
	 *
	 * @param string  $user_login User login (unused).
	 * @param WP_User $user       User that authenticated.
	 * @return void
	 */
	public function record_login( $user_login, $user ) {
		if ( ! ( $user instanceof WP_User ) || empty( $user->ID ) ) {
			return;
		}

		update_user_meta( (int) $user->ID, self::META_KEY, time() );
	}

	/* ---------------------------------------------------------------------
	 * Column rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Inject the "Last login" header into the Users list columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN_ID ] = __( 'Last login', 'levers' );
		return $columns;
	}

	/**
	 * Render a cell for the "Last login" column.
	 *
	 * @param string $output      Existing output for this column.
	 * @param string $column_name Column id being rendered.
	 * @param int    $user_id     User id for this row.
	 * @return string
	 */
	public function render_column( $output, $column_name, $user_id ) {
		if ( self::COLUMN_ID !== $column_name ) {
			return $output;
		}

		$ts = (int) get_user_meta( $user_id, self::META_KEY, true );

		if ( $ts <= 0 ) {
			return '<span style="color:#a7aaad;">' . esc_html__( 'Never', 'levers' ) . '</span>';
		}

		$now      = time();
		$relative = $ts <= $now
			? sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours". */ __( '%s ago', 'levers' ), human_time_diff( $ts, $now ) )
			: human_time_diff( $now, $ts );

		$format   = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$absolute = wp_date( $format, $ts );

		return sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( $absolute ),
			esc_html( $relative )
		);
	}

	/* ---------------------------------------------------------------------
	 * Sorting
	 * ------------------------------------------------------------------- */

	/**
	 * Mark the column sortable.
	 *
	 * @param array $sortable Existing sortable columns.
	 * @return array
	 */
	public function mark_sortable( $sortable ) {
		$sortable[ self::COLUMN_ID ] = self::COLUMN_ID;
		return $sortable;
	}

	/**
	 * Sort by levers_user_last_login when the user clicks the column header.
	 *
	 * Uses a LEFT JOIN so users without the meta still appear in the
	 * result set and sort to the bottom (treating missing as 0).
	 *
	 * @param WP_User_Query $query Query being prepared.
	 * @return void
	 */
	public function apply_sort( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort param on a core admin screen.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';

		if ( self::COLUMN_ID !== $orderby ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort param on a core admin screen.
		$direction = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';

		$meta_key = self::META_KEY;

		$query->query_from   .= $wpdb->prepare( " LEFT JOIN {$wpdb->usermeta} AS levers_lll ON ( {$wpdb->users}.ID = levers_lll.user_id AND levers_lll.meta_key = %s )", $meta_key );
		$query->query_orderby = 'ORDER BY COALESCE( CAST( levers_lll.meta_value AS UNSIGNED ), 0 ) ' . $direction . ', ' . $wpdb->users . '.user_login ASC';
	}
}
