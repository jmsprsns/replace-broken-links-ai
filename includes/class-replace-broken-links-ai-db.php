<?php
/**
 * The plugin's three tables and every query that touches them.
 *
 * - urls:  one row per checked URL, so a link used on 200 posts is only
 *          requested once per scan. Also tracks how long a URL has been
 *          failing (first_failed), which is what the grace period counts from.
 * - links: one row per broken URL. It's the review queue, the change log and
 *          the ignore list, and holds the AI's search, the results and its pick.
 *          One lookup serves every post that links to the URL.
 * - uses:  one row per post that links to a broken URL (as written there),
 *          with the exact spots a replacement changed, so it can be undone.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access.
 */
final class Replace_Broken_Links_AI_DB {

	/**
	 * Bump when the table definitions change; install() runs again on the
	 * next load (updates don't fire the activation hook).
	 */
	const VERSION = '1';

	/**
	 * Option holding the installed table version.
	 */
	const VERSION_OPTION = 'replace_broken_links_ai_db_version';

	/**
	 * URL check states.
	 */
	const URL_OK      = 'ok';      // Loads (or is protected: 401/403).
	const URL_FAILING = 'failing'; // Gone, still inside the grace period.
	const URL_DEAD    = 'dead';    // Gone for the whole grace period.
	const URL_ERROR   = 'error';   // Couldn't tell (timeout, 5xx, 429). Never counts as dead.

	/**
	 * Why a URL counts as gone.
	 */
	const KIND_GONE   = 'gone';   // 404 Not Found or 410 Gone.
	const KIND_DOMAIN = 'domain'; // The domain changed hands or no longer exists (parked, for sale, taken over, no DNS).

	/**
	 * Broken link statuses.
	 */
	const WATCHING = 'watching'; // Failing, waiting out the grace period.
	const DEAD     = 'dead';     // Confirmed dead, waiting for a lookup.
	const REVIEW   = 'review';   // A replacement was found and waits for approval.
	const REPLACED = 'replaced'; // Swapped in its posts.
	const NONE     = 'none';     // The lookup found nothing suitable.
	const FAILED   = 'failed';   // The lookup hit an error (retried automatically a few times).
	const IGNORED  = 'ignored';  // Left alone on purpose (or undone).

	/**
	 * Failed lookups are retried this many times before they wait for a person.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * A table's full name.
	 *
	 * @param string $name urls, links or uses.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'rblai_' . $name;
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
		$urls    = self::table( 'urls' );
		$links   = self::table( 'links' );
		$uses    = self::table( 'uses' );

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$urls} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			url text NOT NULL,
			code smallint(5) unsigned NOT NULL DEFAULT 0,
			state varchar(10) NOT NULL DEFAULT '',
			kind varchar(10) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			first_failed int(10) unsigned NOT NULL DEFAULT 0,
			fails smallint(5) unsigned NOT NULL DEFAULT 0,
			checked int(10) unsigned NOT NULL DEFAULT 0,
			next_check int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY state (state,next_check),
			KEY checked (checked)
			) {$charset};
			CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			url text NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			kind varchar(10) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			code smallint(5) unsigned NOT NULL DEFAULT 0,
			found int(10) unsigned NOT NULL DEFAULT 0,
			archive text NOT NULL,
			query text NOT NULL,
			candidates longtext NOT NULL,
			choice tinyint(3) unsigned NOT NULL DEFAULT 0,
			confidence varchar(10) NOT NULL DEFAULT '',
			reason text NOT NULL,
			target text NOT NULL,
			target_title text NOT NULL,
			target_code smallint(5) unsigned NOT NULL DEFAULT 0,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			error text NOT NULL,
			method varchar(10) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created int(10) unsigned NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status (status,updated)
			) {$charset};
			CREATE TABLE {$uses} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			link_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			href_hash char(32) NOT NULL,
			href text NOT NULL,
			anchor text NOT NULL,
			context text NOT NULL,
			places text NOT NULL,
			content_md5 char(32) NOT NULL DEFAULT '',
			changed tinyint(1) unsigned NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY link_post_href (link_id,post_id,href_hash),
			KEY post_id (post_id)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Install or upgrade the tables if they're missing or out of date.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Key for a URL: md5 of the exact string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function hash( $url ) {
		return md5( (string) $url );
	}

	// URL checks ----------------------------------------------------------------

	/**
	 * The stored check for a URL.
	 *
	 * @param string $url Absolute URL without its fragment.
	 * @return array|null
	 */
	public static function get_url( $url ) {
		global $wpdb;

		$table = self::table( 'urls' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s", self::hash( $url ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		return $row ? $row : null;
	}

	/**
	 * Store a URL's check.
	 *
	 * @param string $url    Absolute URL without its fragment.
	 * @param array  $fields code, state, kind, note, first_failed, fails, next_check.
	 * @return void
	 */
	public static function save_url( $url, array $fields ) {
		global $wpdb;

		$table = self::table( 'urls' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names; every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (url_hash, url, code, state, kind, note, first_failed, fails, checked, next_check) VALUES (%s, %s, %d, %s, %s, %s, %d, %d, %d, %d)
				ON DUPLICATE KEY UPDATE code = VALUES(code), state = VALUES(state), kind = VALUES(kind), note = VALUES(note), first_failed = VALUES(first_failed), fails = VALUES(fails), checked = VALUES(checked), next_check = VALUES(next_check)",
				self::hash( $url ),
				$url,
				(int) $fields['code'],
				$fields['state'],
				(string) $fields['kind'],
				substr( (string) $fields['note'], 0, 250 ),
				(int) $fields['first_failed'],
				(int) $fields['fails'],
				time(),
				(int) $fields['next_check']
			)
		);
		// phpcs:enable
	}

	/**
	 * Failing URLs whose next check is due.
	 *
	 * @param int $limit How many.
	 * @return string[]
	 */
	public static function due_urls( $limit ) {
		global $wpdb;

		$table = self::table( 'urls' );

		return array_map(
			'strval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT url FROM {$table} WHERE state = %s AND next_check <= %d ORDER BY next_check ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
					self::URL_FAILING,
					time(),
					(int) $limit
				)
			)
		);
	}

	/**
	 * When the next failing URL is due for a check.
	 *
	 * @return int Unix time, or 0 when nothing is waiting.
	 */
	public static function next_due_time() {
		global $wpdb;

		$table = self::table( 'urls' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(next_check) FROM {$table} WHERE state = %s", self::URL_FAILING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	}

	/**
	 * Number of URLs checked.
	 *
	 * @return int
	 */
	public static function count_urls() {
		global $wpdb;

		$table = self::table( 'urls' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	}

	/**
	 * Forget working URLs that haven't been checked since $before, so the
	 * cache can't grow forever. Failing and dead URLs are kept: they carry
	 * the date the grace period counts from.
	 *
	 * @param int $before Unix time.
	 * @return void
	 */
	public static function prune_urls( $before ) {
		global $wpdb;

		$table = self::table( 'urls' );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE checked < %d AND state IN (%s, %s)", (int) $before, self::URL_OK, self::URL_ERROR ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	}

	// Broken links ----------------------------------------------------------------

	/**
	 * One broken link row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get_link( $id ) {
		global $wpdb;

		$table = self::table( 'links' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		return $row ? $row : null;
	}

	/**
	 * The broken link row for a URL.
	 *
	 * @param string $url Absolute URL without its fragment.
	 * @return array|null
	 */
	public static function get_link_by_url( $url ) {
		global $wpdb;

		$table = self::table( 'links' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s", self::hash( $url ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		return $row ? $row : null;
	}

	/**
	 * Add a broken link (or return the one that's already there).
	 *
	 * @param string $url    Absolute URL without its fragment.
	 * @param string $status watching or dead.
	 * @param array  $check  The URL's check: code, kind, note, first_failed.
	 * @return array|null The row.
	 */
	public static function add_link( $url, $status, array $check ) {
		global $wpdb;

		$table = self::table( 'links' );
		$now   = time();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (url_hash, url, status, kind, note, code, found, archive, query, candidates, reason, target, target_title, error, created, updated) VALUES (%s, %s, %s, %s, %s, %d, %d, '', '', '', '', '', '', '', %d, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
				self::hash( $url ),
				$url,
				$status,
				(string) $check['kind'],
				substr( (string) $check['note'], 0, 250 ),
				(int) $check['code'],
				(int) $check['first_failed'],
				$now,
				$now
			)
		);

		return self::get_link_by_url( $url );
	}

	/**
	 * Change fields on a broken link row. Always bumps "updated".
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Column => value.
	 * @return void
	 */
	public static function update_link( $id, array $fields ) {
		global $wpdb;

		$ints    = array( 'code', 'found', 'choice', 'target_code', 'attempts', 'user_id', 'updated' );
		$allowed = array( 'status', 'kind', 'note', 'code', 'found', 'archive', 'query', 'candidates', 'choice', 'confidence', 'reason', 'target', 'target_title', 'target_code', 'attempts', 'error', 'method', 'user_id', 'updated' );
		$data    = array();
		$formats = array();

		$fields['updated'] = isset( $fields['updated'] ) ? $fields['updated'] : time();

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			if ( ( 'candidates' === $key || 'archive' === $key ) && is_array( $value ) ) {
				$value = (string) wp_json_encode( $value );
			}

			$data[ $key ] = in_array( $key, $ints, true ) ? (int) $value : (string) $value;
			$formats[]    = in_array( $key, $ints, true ) ? '%d' : '%s';
		}

		$wpdb->update( self::table( 'links' ), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete broken link rows and their uses.
	 *
	 * @param int[] $ids Row IDs.
	 * @return void
	 */
	public static function delete_links( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return;
		}

		$in    = implode( ',', $ids ); // Integers only, from absint().
		$links = self::table( 'links' );
		$uses  = self::table( 'uses' );

		$wpdb->query( "DELETE FROM {$uses} WHERE link_id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and absint'd IDs.
		$wpdb->query( "DELETE FROM {$links} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and absint'd IDs.
	}

	/**
	 * The next broken link to look up: one newly confirmed dead, or one
	 * whose last lookup failed long enough ago to try again.
	 *
	 * @param int $retry_before Retry failed lookups last tried before this Unix time.
	 * @return array|null
	 */
	public static function next_lookup( $retry_before ) {
		global $wpdb;

		$links = self::table( 'links' );
		$uses  = self::table( 'uses' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names; every value is a placeholder.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT l.* FROM {$links} l WHERE ( l.status = %s OR ( l.status = %s AND l.attempts < %d AND l.updated < %d ) )
				AND EXISTS ( SELECT 1 FROM {$uses} u WHERE u.link_id = l.id AND u.changed = 0 )
				ORDER BY l.status = %s DESC, l.updated ASC LIMIT 1",
				self::DEAD,
				self::FAILED,
				self::MAX_ATTEMPTS,
				(int) $retry_before,
				self::DEAD
			),
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $row : null;
	}

	/**
	 * Row counts by status.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$table  = self::table( 'links' );
		$counts = array_fill_keys( array( self::WATCHING, self::DEAD, self::REVIEW, self::REPLACED, self::NONE, self::FAILED, self::IGNORED ), 0 );

		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
		}

		return $counts;
	}

	/**
	 * A page of broken links for the admin list, with how many posts use each.
	 *
	 * @param string[] $statuses Statuses to include.
	 * @param string   $search   Text to look for in the URL, the replacement or the search.
	 * @param int      $per_page Rows per page.
	 * @param int      $page     1-based page number.
	 * @param string   $order    "asc" or "desc" (by last change).
	 * @return array{rows: array, total: int}
	 */
	public static function query_links( array $statuses, $search, $per_page, $page, $order = 'desc' ) {
		global $wpdb;

		$links = self::table( 'links' );
		$uses  = self::table( 'uses' );
		$where = 'l.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$args  = $statuses;

		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (l.url LIKE %s OR l.target LIKE %s OR l.query LIKE %s)';
			$args   = array_merge( $args, array( $like, $like, $like ) );
		}

		$order  = 'asc' === $order ? 'ASC' : 'DESC';
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names, a fixed WHERE with one placeholder per value in $args, and a whitelisted ORDER.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$links} l WHERE {$where}", $args ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, (SELECT COUNT(*) FROM {$uses} u WHERE u.link_id = l.id) AS uses FROM {$links} l WHERE {$where} ORDER BY l.updated {$order}, l.id {$order} LIMIT %d OFFSET %d",
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
	 * The most recently changed rows with some statuses.
	 *
	 * @param string[] $statuses Statuses.
	 * @param int      $limit    How many.
	 * @return array[]
	 */
	public static function recent( array $statuses, $limit ) {
		$result = self::query_links( $statuses, '', $limit, 1 );

		return $result['rows'];
	}

	/**
	 * Remove broken links that no post uses any more (the link was edited out,
	 * or its posts were deleted), unless they're part of the history.
	 *
	 * @return void
	 */
	public static function delete_orphans() {
		global $wpdb;

		$links = self::table( 'links' );
		$uses  = self::table( 'uses' );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT l.id FROM {$links} l LEFT JOIN {$uses} u ON u.link_id = l.id WHERE u.id IS NULL AND l.status IN (%s, %s, %s, %s, %s) LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names.
				self::WATCHING,
				self::DEAD,
				self::REVIEW,
				self::NONE,
				self::FAILED
			)
		);

		self::delete_links( array_map( 'intval', (array) $ids ) );
	}

	// Uses -------------------------------------------------------------------------

	/**
	 * Record that a post links to a broken URL (or refresh what was recorded).
	 *
	 * @param int    $link_id Broken link row.
	 * @param int    $post_id Post.
	 * @param string $href    The link as written in the post.
	 * @param string $anchor  Link text.
	 * @param string $context Text around the link.
	 * @return void
	 */
	public static function save_use( $link_id, $post_id, $href, $anchor, $context ) {
		global $wpdb;

		$table = self::table( 'uses' );

		// Seeing the old link in a post again means it's there now, even if an
		// earlier replacement was put back by hand or from a revision.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names; every value is a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (link_id, post_id, href_hash, href, anchor, context, places, content_md5, changed, updated) VALUES (%d, %d, %s, %s, %s, %s, '', '', 0, %d)
				ON DUPLICATE KEY UPDATE anchor = VALUES(anchor), context = VALUES(context), changed = 0, updated = VALUES(updated)",
				(int) $link_id,
				(int) $post_id,
				self::hash( $href ),
				$href,
				$anchor,
				$context,
				time()
			)
		);
		// phpcs:enable
	}

	/**
	 * Every post that uses a broken link, with the post's title and type.
	 *
	 * @param int  $link_id        Broken link row.
	 * @param bool $only_unchanged Leave out uses already replaced.
	 * @return array[]
	 */
	public static function get_uses( $link_id, $only_unchanged = false ) {
		global $wpdb;

		$table = self::table( 'uses' );
		$extra = $only_unchanged ? ' AND u.changed = 0' : '';

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT u.*, p.post_title, p.post_type, p.post_status, MD5(p.post_content) AS post_md5 FROM {$table} u LEFT JOIN {$wpdb->posts} p ON p.ID = u.post_id WHERE u.link_id = %d{$extra} ORDER BY u.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and a fixed condition.
				(int) $link_id
			),
			ARRAY_A
		);
	}

	/**
	 * Uses for several links at once (for the admin list), grouped by link.
	 *
	 * @param int[] $link_ids Broken link rows.
	 * @return array<int,array[]>
	 */
	public static function uses_for_links( array $link_ids ) {
		global $wpdb;

		$link_ids = array_filter( array_map( 'absint', $link_ids ) );
		if ( ! $link_ids ) {
			return array();
		}

		$table = self::table( 'uses' );
		$in    = implode( ',', $link_ids ); // Integers only, from absint().
		$rows  = $wpdb->get_results( "SELECT u.id, u.link_id, u.post_id, u.href, u.anchor, u.context, u.changed, u.content_md5, p.post_title, p.post_type, MD5(p.post_content) AS post_md5 FROM {$table} u LEFT JOIN {$wpdb->posts} p ON p.ID = u.post_id WHERE u.link_id IN ({$in}) ORDER BY u.id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and absint'd IDs.

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['link_id'] ][] = $row;
		}

		return $grouped;
	}

	/**
	 * A post's uses that haven't been replaced: use ID => link ID.
	 *
	 * @param int $post_id Post.
	 * @return array<int,int>
	 */
	public static function unchanged_post_uses( $post_id ) {
		global $wpdb;

		$table = self::table( 'uses' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, link_id FROM {$table} WHERE post_id = %d AND changed = 0", (int) $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		$uses = array();
		foreach ( (array) $rows as $row ) {
			$uses[ (int) $row['id'] ] = (int) $row['link_id'];
		}

		return $uses;
	}

	/**
	 * The use row for a link in a post, as written.
	 *
	 * @param int    $link_id Broken link row.
	 * @param int    $post_id Post.
	 * @param string $href    The link as written.
	 * @return int Use ID, or 0.
	 */
	public static function use_id( $link_id, $post_id, $href ) {
		global $wpdb;

		$table = self::table( 'uses' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE link_id = %d AND post_id = %d AND href_hash = %s", (int) $link_id, (int) $post_id, self::hash( $href ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	}

	/**
	 * Record that a use was replaced (or put back).
	 *
	 * @param int    $id      Use row.
	 * @param bool   $changed Whether the post now has the replacement.
	 * @param array  $places  Where the link was replaced (Replace_Broken_Links_AI_Content::replace()).
	 * @param string $md5     md5 of the post content after the change.
	 * @return void
	 */
	public static function mark_use( $id, $changed, array $places = array(), $md5 = '' ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table( 'uses' ),
			array(
				'changed'     => $changed ? 1 : 0,
				'places'      => $changed ? (string) wp_json_encode( $places ) : '',
				'content_md5' => $changed ? (string) $md5 : '',
				'updated'     => time(),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * After the plugin saves a post again, carry its earlier replacements over
	 * to the new content. Only link values changed, so their places still hold.
	 * Replacements recorded against content someone has edited since are left
	 * as they are, and can no longer be undone automatically.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_md5 md5 of the content before the save.
	 * @param string $new_md5 md5 of the content after it.
	 * @return void
	 */
	public static function rebase( $post_id, $old_md5, $new_md5 ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table( 'uses' ),
			array( 'content_md5' => $new_md5 ),
			array(
				'post_id'     => (int) $post_id,
				'changed'     => 1,
				'content_md5' => $old_md5,
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Delete use rows by ID.
	 *
	 * @param int[] $ids Use rows.
	 * @return void
	 */
	public static function delete_uses( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return;
		}

		$table = self::table( 'uses' );
		$in    = implode( ',', $ids ); // Integers only, from absint().

		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and absint'd IDs.
	}

	/**
	 * Delete every use in a post (when the post itself is deleted).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_post_uses( $post_id ) {
		global $wpdb;

		$wpdb->delete( self::table( 'uses' ), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Drop the tables (uninstall only).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		foreach ( array( 'urls', 'links', 'uses' ) as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name.
		}

		delete_option( self::VERSION_OPTION );
	}
}
