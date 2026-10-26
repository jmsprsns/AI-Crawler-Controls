<?php
/**
 * The plugin's two tables and every query that touches them.
 *
 * - hits:  the crawl log, one row per request from an AI crawler. It keeps
 *          up to LOG_CAP requests per crawler and 10 times that in all per
 *          day, and is pruned after the retention period, so a flood of
 *          requests (real or spoofed) can't fill the database.
 * - daily: one row per crawler per day with exact totals, which the stats
 *          and the chart read. Kept for KEEP_DAYS days.
 *
 * @package AI_Crawler_Controls
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access.
 */
final class AI_Crawler_Controls_DB {

	/**
	 * Bump when the table definitions change; install() runs again on the
	 * next load (updates don't fire the activation hook).
	 */
	const VERSION = '1';

	/**
	 * Option holding the installed table version.
	 */
	const VERSION_OPTION = 'ai_crawler_controls_db_version';

	/**
	 * Most log rows per crawler per day; all crawlers together get 10 times
	 * this. The daily totals count every request.
	 */
	const LOG_CAP = 1000;

	/**
	 * Days of daily totals to keep.
	 */
	const KEEP_DAYS = 400;

	/**
	 * What happened to a request.
	 */
	const ALLOWED = 'allowed'; // Served normally.
	const IGNORED = 'ignored'; // Disallowed in robots.txt, but it asked for the page anyway.
	const BLOCKED = 'blocked'; // Refused with a 403.
	const ROBOTS  = 'robots';  // Read robots.txt.

	/**
	 * Crawl log table name.
	 *
	 * @return string
	 */
	public static function hits_table() {
		global $wpdb;

		return $wpdb->prefix . 'ai_crawler_controls_hits';
	}

	/**
	 * Daily totals table name.
	 *
	 * @return string
	 */
	public static function daily_table() {
		global $wpdb;

		return $wpdb->prefix . 'ai_crawler_controls_daily';
	}

	/**
	 * Every result, in display order.
	 *
	 * @return string[]
	 */
	public static function results() {
		return array( self::ALLOWED, self::IGNORED, self::BLOCKED, self::ROBOTS );
	}

	/**
	 * Create or upgrade the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$hits    = self::hits_table();
		$daily   = self::daily_table();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$hits} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bot varchar(80) NOT NULL DEFAULT '',
			hit_time int(10) unsigned NOT NULL DEFAULT 0,
			result varchar(10) NOT NULL DEFAULT '',
			status smallint(5) unsigned NOT NULL DEFAULT 0,
			method varchar(10) NOT NULL DEFAULT '',
			path varchar(255) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY hit_time (hit_time),
			KEY bot_time (bot,hit_time)
			) {$charset};
			CREATE TABLE {$daily} (
			day date NOT NULL,
			bot varchar(80) NOT NULL DEFAULT '',
			hits int(10) unsigned NOT NULL DEFAULT 0,
			blocked int(10) unsigned NOT NULL DEFAULT 0,
			ignored int(10) unsigned NOT NULL DEFAULT 0,
			logged int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,bot)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Install or upgrade the tables if they're missing or out of date.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( ! self::is_installed() ) {
			self::install();
		}
	}

	/**
	 * Whether the tables are in place. The option is autoloaded, so this costs
	 * nothing on the requests that log.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return self::VERSION === get_option( self::VERSION_OPTION );
	}

	/**
	 * Most log rows per crawler per day.
	 *
	 * @return int
	 */
	public static function log_cap() {
		/**
		 * Filter how many requests per crawler per day the crawl log keeps
		 * (all crawlers together keep 10 times this). The totals and the
		 * chart count every request either way.
		 *
		 * @param int $cap Rows per crawler per day.
		 */
		return max( 0, (int) apply_filters( 'ai_crawler_controls_log_cap', self::LOG_CAP ) );
	}

	/**
	 * Record one request: add it to today's totals and, under the day's caps,
	 * to the crawl log.
	 *
	 * @param array $hit bot, result, status, method, path, ip, user_agent.
	 * @return void
	 */
	public static function record( array $hit ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return;
		}

		$daily   = self::daily_table();
		$day     = current_time( 'Y-m-d' );
		$blocked = self::BLOCKED === $hit['result'] ? 1 : 0;
		$ignored = self::IGNORED === $hit['result'] ? 1 : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
		$logged = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(logged), 0) AS all_bots, COALESCE(SUM(CASE WHEN bot = %s THEN logged ELSE 0 END), 0) AS this_bot FROM {$daily} WHERE day = %s",
				$hit['bot'],
				$day
			),
			ARRAY_A
		);

		$cap = self::log_cap();
		$log = $logged && (int) $logged['this_bot'] < $cap && (int) $logged['all_bots'] < 10 * $cap ? 1 : 0;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily} (day, bot, hits, blocked, ignored, logged) VALUES (%s, %s, 1, %d, %d, %d)
				ON DUPLICATE KEY UPDATE hits = hits + 1, blocked = blocked + %d, ignored = ignored + %d, logged = logged + %d",
				$day,
				$hit['bot'],
				$blocked,
				$ignored,
				$log,
				$blocked,
				$ignored,
				$log
			)
		);
		// phpcs:enable

		if ( ! $log ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::hits_table(),
			array(
				'bot'        => $hit['bot'],
				'hit_time'   => time(),
				'result'     => $hit['result'],
				'status'     => (int) $hit['status'],
				'method'     => $hit['method'],
				'path'       => $hit['path'],
				'ip'         => $hit['ip'],
				'user_agent' => $hit['user_agent'],
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Totals per crawler over the last $days days (today included).
	 *
	 * @param int $days Days.
	 * @return array<string,array{hits: int, blocked: int, ignored: int}>
	 */
	public static function totals_by_bot( $days ) {
		global $wpdb;

		$table = self::daily_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT bot, SUM(hits) AS hits, SUM(blocked) AS blocked, SUM(ignored) AS ignored FROM {$table} WHERE day >= %s GROUP BY bot", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
				self::first_day( $days )
			),
			ARRAY_A
		);

		$totals = array();
		foreach ( (array) $rows as $row ) {
			$totals[ $row['bot'] ] = array(
				'hits'    => (int) $row['hits'],
				'blocked' => (int) $row['blocked'],
				'ignored' => (int) $row['ignored'],
			);
		}

		return $totals;
	}

	/**
	 * Totals per day over the last $days days, oldest first, with empty days
	 * filled in.
	 *
	 * @param int $days Days.
	 * @return array<string,array{hits: int, blocked: int}> Y-m-d => totals.
	 */
	public static function totals_by_day( $days ) {
		global $wpdb;

		$table = self::daily_table();
		$first = self::first_day( $days );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT day, SUM(hits) AS hits, SUM(blocked) AS blocked FROM {$table} WHERE day >= %s GROUP BY day", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
				$first
			),
			ARRAY_A
		);

		$series = array();
		$start  = strtotime( $first . ' 00:00:00 UTC' );
		for ( $i = 0; $i < $days; $i++ ) {
			$series[ gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS ) ] = array(
				'hits'    => 0,
				'blocked' => 0,
			);
		}

		foreach ( (array) $rows as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ] = array(
					'hits'    => (int) $row['hits'],
					'blocked' => (int) $row['blocked'],
				);
			}
		}

		return $series;
	}

	/**
	 * First day (site time) of a window of $days days ending today.
	 *
	 * @param int $days Days.
	 * @return string Y-m-d.
	 */
	private static function first_day( $days ) {
		$today = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00 UTC' );

		return gmdate( 'Y-m-d', $today - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * When each crawler was last logged.
	 *
	 * @return array<string,int> Crawler ID => Unix time.
	 */
	public static function last_seen() {
		global $wpdb;

		$table = self::hits_table();
		$rows  = $wpdb->get_results( "SELECT bot, MAX(hit_time) AS seen FROM {$table} GROUP BY bot", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		$seen = array();
		foreach ( (array) $rows as $row ) {
			$seen[ $row['bot'] ] = (int) $row['seen'];
		}

		return $seen;
	}

	/**
	 * Crawler IDs that appear in the log, for the list's filter.
	 *
	 * @return string[]
	 */
	public static function logged_bots() {
		global $wpdb;

		$table = self::hits_table();

		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT bot FROM {$table}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	}

	/**
	 * A page of the crawl log, newest first.
	 *
	 * @param array $filters  bot, result and search (each '' for any).
	 * @param int   $per_page Rows per page.
	 * @param int   $page     1-based page number.
	 * @return array{rows: array, total: int}
	 */
	public static function query_hits( array $filters, $per_page, $page ) {
		global $wpdb;

		$table = self::hits_table();
		$where = array( 'hit_time >= %d' );
		$args  = array( 0 );

		if ( '' !== $filters['bot'] ) {
			$where[] = 'bot = %s';
			$args[]  = $filters['bot'];
		}

		if ( '' !== $filters['result'] ) {
			$where[] = 'result = %s';
			$args[]  = $filters['result'];
		}

		if ( '' !== $filters['search'] ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '(path LIKE %s OR user_agent LIKE %s OR ip LIKE %s)';
			array_push( $args, $like, $like, $like );
		}

		$where  = implode( ' AND ', $where );
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name and a WHERE built from fixed clauses with placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( (int) $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Delete log rows past the retention period and totals past KEEP_DAYS.
	 *
	 * @param int $retention_days Days of log to keep.
	 * @return void
	 */
	public static function prune( $retention_days ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return;
		}

		$hits  = self::hits_table();
		$daily = self::daily_table();
		$since = time() - max( 1, (int) $retention_days ) * DAY_IN_SECONDS;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$hits} WHERE hit_time < %d", $since ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE day < %s", self::first_day( self::KEEP_DAYS ) ) );
		// phpcs:enable
	}

	/**
	 * Empty both tables ("Clear log").
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . self::hits_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name.
		$wpdb->query( 'DELETE FROM ' . self::daily_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name.
	}
}
