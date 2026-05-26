<?php
/**
 * Lever: optimize database tables.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Weekly OPTIMIZE TABLE pass across the site's WP tables - but selective.
 *
 * On InnoDB, OPTIMIZE TABLE is an exclusive lock + full-table rebuild,
 * so blanket-optimizing every wp_* table is wasteful and can hurt more
 * than it helps. This lever:
 *
 *   - Queries information_schema for `DATA_FREE` per table and only
 *     touches the ones with meaningful fragmentation (default >= 5 MiB).
 *   - Walks them one at a time, never in parallel, ordered by who has
 *     the most free space first.
 *   - Schedules the weekly run at 2 AM in the site's own timezone, so
 *     the lock window lands during the lowest-traffic hour for most
 *     sites instead of at whatever moment the lever was switched on.
 */
class Levers_Lever_Optimize_Db_Tables extends Levers_Lever {

	/** Cron hook for the weekly run. */
	const CRON_HOOK = 'levers_optimize_db_tables';

	/** Minimum DATA_FREE (bytes) before a table is considered worth optimizing. */
	const DATA_FREE_THRESHOLD = 5242880; // 5 MiB.

	/** Hour-of-day (site timezone) for the weekly run. */
	const RUN_HOUR = 2;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'optimize-db-tables';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Optimize database tables', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Weekly OPTIMIZE TABLE pass on wp_* tables with real fragmentation. Runs at 2 AM site time, one table at a time.', 'levers' );
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
		return 'database';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( self::CRON_HOOK, array( $this, 'optimize_tables' ) );

		$next = wp_next_scheduled( self::CRON_HOOK );

		// If never scheduled - or scheduled at a non-2 AM time from an
		// older version of this lever - (re)pin it to the off-peak slot.
		if ( ! $next || ! $this->lands_on_run_hour( $next ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_event( $this->next_run_timestamp(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_disable() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * The sweep
	 * ------------------------------------------------------------------- */

	/**
	 * Optimize the wp_* tables that actually need it.
	 *
	 * @return void
	 */
	public function optimize_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled maintenance.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = %s
				   AND TABLE_NAME LIKE %s
				   AND DATA_FREE >= %d
				 ORDER BY DATA_FREE DESC",
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%',
				self::DATA_FREE_THRESHOLD
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$table  = isset( $row['TABLE_NAME'] ) ? (string) $row['TABLE_NAME'] : '';
			if ( '' === $table ) {
				continue;
			}

			$quoted = '`' . str_replace( '`', '``', $table ) . '`';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier, sanitised above.
			$wpdb->query( "OPTIMIZE TABLE {$quoted}" );
		}
	}

	/* ---------------------------------------------------------------------
	 * Off-peak scheduling
	 * ------------------------------------------------------------------- */

	/**
	 * Next 2 AM in the site's timezone, as a Unix timestamp.
	 *
	 * @return int
	 */
	private function next_run_timestamp() {
		try {
			$tz     = wp_timezone();
			$now    = new DateTime( 'now', $tz );
			$target = clone $now;
			$target->setTime( self::RUN_HOUR, 0, 0 );

			if ( $target <= $now ) {
				$target->modify( '+1 day' );
			}

			return $target->getTimestamp();
		} catch ( Exception $e ) {
			return time() + DAY_IN_SECONDS;
		}
	}

	/**
	 * Whether an existing schedule timestamp falls in the configured hour
	 * (in site-local time).
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return bool
	 */
	private function lands_on_run_hour( $timestamp ) {
		try {
			$dt = new DateTime( '@' . (int) $timestamp );
			$dt->setTimezone( wp_timezone() );

			return self::RUN_HOUR === (int) $dt->format( 'G' );
		} catch ( Exception $e ) {
			return false;
		}
	}
}
