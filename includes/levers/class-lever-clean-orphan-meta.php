<?php
/**
 * Lever: clean orphaned metadata.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily sweep that deletes metadata rows whose parent record is gone.
 *
 * Years of plugin churn (and the occasional missed cleanup hook) leave
 * orphaned rows in wp_postmeta, wp_commentmeta and wp_termmeta: meta
 * pointing at posts / comments / terms that no longer exist. They're
 * silent bloat - nothing reads them, but they still sit in the largest
 * table on most sites (wp_postmeta) forever.
 *
 * Each meta table is swept via a LEFT JOIN onto its parent table.
 * Hits are deleted by id in capped batches so the cron run stays
 * bounded even on a long-neglected site.
 */
class Levers_Lever_Clean_Orphan_Meta extends Levers_Lever {

	/** Cron hook for the daily sweep. */
	const CRON_HOOK = 'levers_clean_orphan_meta';

	/** Max orphan rows deleted per meta table per run. */
	const PURGE_LIMIT = 1000;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'clean-orphan-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Clean orphaned metadata', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Daily sweep that deletes postmeta/commentmeta/termmeta rows whose parent record no longer exists. Classic wp_postmeta bloat.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'maintenance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'unplug';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( self::CRON_HOOK, array( $this, 'purge_orphans' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_disable() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run an orphan sweep on each meta table.
	 *
	 * @return void
	 */
	public function purge_orphans() {
		global $wpdb;

		$this->purge_meta_table( $wpdb->postmeta, 'meta_id', 'post_id', $wpdb->posts, 'ID' );
		$this->purge_meta_table( $wpdb->commentmeta, 'meta_id', 'comment_id', $wpdb->comments, 'comment_ID' );
		$this->purge_meta_table( $wpdb->termmeta, 'meta_id', 'term_id', $wpdb->terms, 'term_id' );
	}

	/**
	 * Find orphan rows in one meta table and delete them by id.
	 *
	 * Table and column names come from $wpdb properties and our own static
	 * mapping below - never user input - so direct interpolation here is
	 * intentional and safe.
	 *
	 * @param string $meta_table   Meta table (e.g. wp_postmeta).
	 * @param string $meta_pk      Meta-table PK column (e.g. meta_id).
	 * @param string $fk_column    FK column on the meta table (e.g. post_id).
	 * @param string $parent_table Parent table (e.g. wp_posts).
	 * @param string $parent_pk    Parent table PK column.
	 * @return void
	 */
	private function purge_meta_table( $meta_table, $meta_pk, $fk_column, $parent_table, $parent_pk ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column identifiers from $wpdb.
		$orphan_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta.{$meta_pk}
				 FROM {$meta_table} AS meta
				 LEFT JOIN {$parent_table} AS parent
				     ON meta.{$fk_column} = parent.{$parent_pk}
				 WHERE parent.{$parent_pk} IS NULL
				 LIMIT %d",
				self::PURGE_LIMIT
			)
		);

		if ( empty( $orphan_ids ) ) {
			return;
		}

		$ids = array_map( 'absint', $orphan_ids );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids absint'd above, table identifier from $wpdb.
		$wpdb->query(
			"DELETE FROM {$meta_table} WHERE {$meta_pk} IN (" . implode( ',', $ids ) . ')'
		);
	}
}
