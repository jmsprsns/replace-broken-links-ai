<?php
/**
 * Finds broken links in posts, waits until they're truly dead, looks up
 * replacements and swaps them in.
 *
 * Work happens in short batches, in the background on WP-Cron and in the
 * foreground while the admin page's "Scan now" is running. Each batch:
 *
 * 1. scans posts saved since the last batch (the queue),
 * 2. carries on with the full scan if one is running,
 * 3. checks failing links again when they're due, and
 * 4. looks up replacements for links that are confirmed dead (WP-Cron only,
 *    a few per batch, within the daily limit).
 *
 * Checking and looking up have separate locks, so a slow AI request never
 * holds up a scan, and two batches never do the same work at once.
 *
 * Every change saves a revision of the post, is logged, and can be undone.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner and replacer.
 */
final class Replace_Broken_Links_AI_Scanner {

	/**
	 * Option holding the full scan's progress.
	 */
	const STATE_OPTION = 'replace_broken_links_ai_scan';

	/**
	 * Option holding IDs of posts saved since the last batch.
	 */
	const QUEUE_OPTION = 'replace_broken_links_ai_queue';

	/**
	 * Options used as the locks for checking and for lookups.
	 */
	const LOCK_OPTION        = 'replace_broken_links_ai_lock';
	const LOOKUP_LOCK_OPTION = 'replace_broken_links_ai_lookup_lock';

	/**
	 * Cron hooks.
	 */
	const BATCH_HOOK = 'replace_broken_links_ai_batch';
	const DAILY_HOOK = 'replace_broken_links_ai_daily';

	/**
	 * Seconds a background batch may spend checking links.
	 */
	const CRON_BUDGET = 20;

	/**
	 * Seconds before a lock is considered abandoned (a batch that crashed).
	 * Lookups wait on two AI requests and a search, so theirs is longer.
	 */
	const LOCK_TTL        = 120;
	const LOOKUP_LOCK_TTL = 600;

	/**
	 * Most lookups in one background batch.
	 */
	const LOOKUPS_PER_BATCH = 3;

	/**
	 * Hours before a lookup that hit an error is tried again.
	 */
	const RETRY_HOURS = 6;

	/**
	 * More queued posts than this starts a full scan instead (bulk edits, imports).
	 */
	const QUEUE_MAX = 200;

	/**
	 * Post statuses that are scanned.
	 */
	const STATUSES = array( 'publish', 'future' );

	/**
	 * Plugin instance.
	 *
	 * @var Replace_Broken_Links_AI_Plugin
	 */
	private $plugin;

	/**
	 * Replacement finder.
	 *
	 * @var Replace_Broken_Links_AI_Finder
	 */
	private $finder;

	/**
	 * True while the plugin itself is saving a post, so it doesn't queue it again.
	 *
	 * @var bool
	 */
	private $saving = false;

	/**
	 * When the current batch has to stop checking (microtime).
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Whether the current batch has made at least one request, so a batch
	 * always gets something done even if one URL is slow.
	 *
	 * @var bool
	 */
	private $requested = false;

	/**
	 * Constructor.
	 *
	 * @param Replace_Broken_Links_AI_Plugin $plugin Plugin instance.
	 */
	public function __construct( Replace_Broken_Links_AI_Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->finder = new Replace_Broken_Links_AI_Finder( $plugin );

		add_action( self::BATCH_HOOK, array( $this, 'cron_batch' ) );
		add_action( self::DAILY_HOOK, array( $this, 'daily' ) );
		add_action( 'save_post', array( $this, 'queue_post' ), 20, 2 );
		add_action( 'deleted_post', array( 'Replace_Broken_Links_AI_DB', 'delete_post_uses' ) );
	}

	/**
	 * The replacement finder.
	 *
	 * @return Replace_Broken_Links_AI_Finder
	 */
	public function finder() {
		return $this->finder;
	}

	// Scan state ---------------------------------------------------------------

	/**
	 * Default scan state.
	 *
	 * @return array<string,int|string>
	 */
	private static function default_state() {
		return array(
			'status'   => 'idle', // idle or running.
			'last_id'  => 0,      // Highest post ID scanned so far.
			'total'    => 0,      // Posts to scan.
			'done'     => 0,      // Posts scanned.
			'started'  => 0,      // Unix time the scan started.
			'finished' => 0,      // Unix time the last scan finished.
			'broken'   => 0,      // Broken links found this scan.
		);
	}

	/**
	 * The full scan's progress, read fresh from the database.
	 *
	 * @return array<string,int|string>
	 */
	public static function get_state() {
		wp_cache_delete( self::STATE_OPTION, 'options' );

		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args( is_array( $state ) ? $state : array(), self::default_state() );
	}

	/**
	 * Save the full scan's progress.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Start a full scan of every post (or restart the one that's running).
	 *
	 * @return array New state.
	 */
	public static function start() {
		$state = array_merge(
			self::get_state(),
			array(
				'status'  => 'running',
				'last_id' => 0,
				'total'   => self::count_posts(),
				'done'    => 0,
				'started' => time(),
				'broken'  => 0,
			)
		);

		self::save_state( $state );

		// The full scan covers every post, including the ones in the queue.
		update_option( self::QUEUE_OPTION, array(), false );

		self::schedule_batch( 0 );

		return $state;
	}

	// Scheduling ---------------------------------------------------------------

	/**
	 * Make sure the daily check is scheduled.
	 *
	 * @return void
	 */
	public static function schedule_daily() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Schedule the next batch, unless one is already due sooner.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function schedule_batch( $delay ) {
		$when = time() + max( 0, (int) $delay );
		$next = wp_next_scheduled( self::BATCH_HOOK );

		if ( $next && $next <= $when ) {
			return;
		}

		if ( $next ) {
			wp_unschedule_event( $next, self::BATCH_HOOK );
		}

		wp_schedule_single_event( $when, self::BATCH_HOOK );
	}

	/**
	 * Clear every scheduled event (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * Daily: tidy up, start the automatic rescan when it's due, and make sure
	 * waiting work has a batch scheduled.
	 *
	 * @return void
	 */
	public function daily() {
		Replace_Broken_Links_AI_DB::prune_urls( time() - 30 * DAY_IN_SECONDS );
		Replace_Broken_Links_AI_DB::delete_orphans();

		$state = self::get_state();

		if ( 'running' === $state['status'] ) {
			self::schedule_batch( 0 );
			return;
		}

		$days = $this->plugin->rescan_days();

		if ( $days && time() - (int) $state['finished'] >= $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			self::start();
			return;
		}

		self::schedule_batch( 0 );
	}

	/**
	 * Cron callback: run one background batch.
	 *
	 * @return void
	 */
	public function cron_batch() {
		// Lookups wait on remote APIs; give them room where the host allows it.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on every host.
		}

		$this->run( self::CRON_BUDGET, true );
	}

	/**
	 * Queue a post for a scan when it's saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function queue_post( $post_id, $post ) {
		if ( $this->saving || ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, self::STATUSES, true ) || ! in_array( $post->post_type, $this->plugin->post_types(), true ) ) {
			return;
		}

		$this->add_to_queue( $post_id );

		// Give the editor a moment to finish saving (meta boxes, a quick second save).
		self::schedule_batch( 30 );
	}

	/**
	 * IDs of posts waiting for a scan.
	 *
	 * @return int[]
	 */
	private static function get_queue() {
		wp_cache_delete( self::QUEUE_OPTION, 'options' );

		return array_values( array_map( 'intval', (array) get_option( self::QUEUE_OPTION, array() ) ) );
	}

	/**
	 * Add a post to the queue.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function add_to_queue( $post_id ) {
		$queue = self::get_queue();

		if ( ! in_array( (int) $post_id, $queue, true ) ) {
			$queue[] = (int) $post_id;
		}

		// A bulk edit or an import: one full scan beats hundreds of queued posts.
		if ( count( $queue ) > self::QUEUE_MAX ) {
			$queue = array();
			if ( 'running' !== self::get_state()['status'] ) {
				self::start();
			}
		}

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	// Batches -----------------------------------------------------------------

	/**
	 * Run one batch.
	 *
	 * @param int  $budget  Seconds to spend checking links.
	 * @param bool $lookups Also look up replacements (background batches only).
	 * @return array Scan state afterwards.
	 */
	public function run( $budget, $lookups = false ) {
		$state    = self::get_state();
		$deferred = array();

		if ( $this->lock( self::LOCK_OPTION, self::LOCK_TTL ) ) {
			$this->deadline  = microtime( true ) + max( 1, (int) $budget );
			$this->requested = false;

			$deferred = $this->run_queue();
			$state    = $this->run_scan();
			$this->run_rechecks();

			$this->unlock( self::LOCK_OPTION );
		}

		if ( $lookups ) {
			$this->run_lookups();
		}

		$this->reschedule( $state, $deferred );

		return $state;
	}

	/**
	 * Schedule the next batch for whatever's waiting soonest.
	 *
	 * @param array $state    Scan state.
	 * @param int[] $deferred Posts left in the queue because they're being edited.
	 * @return void
	 */
	private function reschedule( array $state, array $deferred ) {
		if ( 'running' === $state['status'] || count( self::get_queue() ) > count( $deferred ) ) {
			self::schedule_batch( 0 );
			return;
		}

		$now  = time();
		$when = array();

		if ( $deferred ) {
			// Only posts someone is editing are left: look again in a few minutes.
			$when[] = $now + 5 * MINUTE_IN_SECONDS;
		}

		$due = Replace_Broken_Links_AI_DB::next_due_time();
		if ( $due ) {
			$when[] = max( $now, $due );
		}

		if ( $this->can_look_up() && Replace_Broken_Links_AI_DB::next_lookup( $now - self::RETRY_HOURS * HOUR_IN_SECONDS ) ) {
			// Within today's limit: carry on shortly. Over it: just after midnight.
			$when[] = $this->under_daily_limit() ? $now + 10 : strtotime( 'tomorrow 00:05', $now + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		}

		if ( $when ) {
			self::schedule_batch( min( $when ) - $now );
		}
	}

	/**
	 * Scan the posts saved since the last batch.
	 *
	 * @return int[] Posts left in the queue because they're open in the editor.
	 */
	private function run_queue() {
		$queue    = self::get_queue();
		$original = $queue;
		$deferred = array();
		$since    = time() - DAY_IN_SECONDS;

		while ( $queue && ! $this->out_of_time() ) {
			$post_id = array_shift( $queue );
			$result  = $this->scan_post( $post_id, $since );

			if ( 'timeout' === $result['status'] ) {
				array_unshift( $queue, $post_id );
				break;
			}

			if ( 'locked' === $result['status'] ) {
				$deferred[] = $post_id;
			}
		}

		// Keep what's left, plus anything queued while this batch ran.
		$added = array_diff( self::get_queue(), $original );
		$left  = array_values( array_unique( array_merge( $deferred, $queue, $added ) ) );
		update_option( self::QUEUE_OPTION, $left, false );

		return $deferred;
	}

	/**
	 * Carry on with the full scan, if one is running.
	 *
	 * @return array Scan state.
	 */
	private function run_scan() {
		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		$started = (int) $state['started'];
		$saved   = microtime( true );

		while ( ! $this->out_of_time() ) {
			$ids = $this->next_posts( (int) $state['last_id'], 20 );

			if ( ! $ids ) {
				$state['status']   = 'idle';
				$state['finished'] = time();
				$state['done']     = max( (int) $state['done'], (int) $state['total'] );
				Replace_Broken_Links_AI_DB::delete_orphans();
				break;
			}

			foreach ( $ids as $post_id ) {
				$result = $this->scan_post( $post_id, $started );

				if ( 'timeout' === $result['status'] ) {
					break 2;
				}

				if ( 'locked' === $result['status'] ) {
					$this->add_to_queue( $post_id );
				}

				$state['last_id'] = $post_id;
				$state['done']    = (int) $state['done'] + 1;
				$state['broken']  = (int) $state['broken'] + $result['broken'];

				if ( $this->out_of_time() ) {
					break 2;
				}

				// Save progress as it goes, so the progress bar moves while a
				// background batch is running.
				if ( microtime( true ) - $saved >= 1 ) {
					if ( ! self::save_progress( $state, $started ) ) {
						return self::get_state();
					}
					$saved = microtime( true );
				}
			}
		}

		self::save_progress( $state, $started );

		return $state;
	}

	/**
	 * Save the scan's progress, unless "Scan now" has started a new scan
	 * since this batch began (the new one wins).
	 *
	 * @param array $state   State.
	 * @param int   $started When the scan this batch is working on started.
	 * @return bool Whether it was saved.
	 */
	private static function save_progress( array $state, $started ) {
		if ( (int) self::get_state()['started'] !== (int) $started ) {
			return false;
		}

		self::save_state( $state );

		return true;
	}

	/**
	 * Check failing links whose next check is due.
	 *
	 * @return void
	 */
	private function run_rechecks() {
		while ( ! $this->out_of_time() ) {
			$urls = Replace_Broken_Links_AI_DB::due_urls( 10 );

			if ( ! $urls ) {
				return;
			}

			foreach ( $urls as $url ) {
				if ( $this->requested && $this->out_of_time() ) {
					return;
				}

				$this->requested = true;
				$this->record( $url, Replace_Broken_Links_AI_Checker::check( $url ), Replace_Broken_Links_AI_DB::get_url( $url ) );
			}
		}
	}

	/**
	 * Look up replacements for dead links, a few at a time.
	 *
	 * @return void
	 */
	private function run_lookups() {
		if ( ! $this->can_look_up() || ! $this->lock( self::LOOKUP_LOCK_OPTION, self::LOOKUP_LOCK_TTL ) ) {
			return;
		}

		$started = microtime( true );

		for ( $done = 0; $done < self::LOOKUPS_PER_BATCH; $done++ ) {
			// One lookup always runs; more only while the batch is still young.
			if ( $done && microtime( true ) - $started > 60 ) {
				break;
			}

			if ( ! $this->under_daily_limit() || '' !== Replace_Broken_Links_AI_Plugin::paused() ) {
				break;
			}

			$link = Replace_Broken_Links_AI_DB::next_lookup( time() - self::RETRY_HOURS * HOUR_IN_SECONDS );

			if ( ! $link ) {
				break;
			}

			$this->lookup( $link );
		}

		$this->unlock( self::LOOKUP_LOCK_OPTION );
	}

	/**
	 * Whether lookups can run at all: both APIs connected and not paused.
	 *
	 * @return bool
	 */
	public function can_look_up() {
		return $this->plugin->ready() && '' === Replace_Broken_Links_AI_Plugin::paused();
	}

	/**
	 * Whether today's lookups and estimated spending are both under their
	 * daily limits (0 = no limit).
	 *
	 * @return bool
	 */
	public function under_daily_limit() {
		$limit  = $this->plugin->daily_limit();
		$budget = $this->plugin->daily_budget_micros();

		return ( ! $limit || Replace_Broken_Links_AI_Usage::lookups_today() < $limit )
			&& ( ! $budget || Replace_Broken_Links_AI_Usage::spent_today() < $budget );
	}

	/**
	 * Whether the batch should stop checking.
	 *
	 * @return bool
	 */
	private function out_of_time() {
		return microtime( true ) >= $this->deadline;
	}

	/**
	 * Take a lock. An atomic insert, like core's upgrader lock, so two
	 * batches can't both get it.
	 *
	 * @param string $option Lock option.
	 * @param int    $ttl    Seconds before an abandoned lock can be taken over.
	 * @return bool Whether this batch holds the lock.
	 */
	private function lock( $option, $ttl ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, (string) time() ) );

		if ( ! $taken ) {
			$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );

			if ( $held > time() - (int) $ttl ) {
				return false;
			}

			// Abandoned by a batch that died: take it over, unless another batch just did.
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $option, (string) $held ) );
		}
		// phpcs:enable

		wp_cache_delete( $option, 'options' );

		return (bool) $taken;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $option Lock option.
	 * @return void
	 */
	private function unlock( $option ) {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $option, 'options' );
	}

	// Posts -------------------------------------------------------------------

	/**
	 * SQL for the post types and statuses that get scanned.
	 *
	 * @return string Prepared WHERE conditions.
	 */
	private static function posts_where() {
		global $wpdb;

		$types    = replace_broken_links_ai_plugin()->post_types();
		$types    = $types ? $types : array( '' );
		$statuses = self::STATUSES;

		return $wpdb->prepare(
			'post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built to match.
			array_merge( $types, $statuses )
		);
	}

	/**
	 * Number of posts a full scan covers.
	 *
	 * @return int
	 */
	private static function count_posts() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . self::posts_where() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in posts_where().
	}

	/**
	 * The next posts to scan, by ID.
	 *
	 * @param int $after_id Last post ID scanned.
	 * @param int $limit    How many.
	 * @return int[]
	 */
	private function next_posts( $after_id, $limit ) {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND " . self::posts_where() . ' ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in posts_where().
				(int) $after_id,
				(int) $limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether a post is left out of scanning and replacing altogether.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function skip_post( WP_Post $post ) {
		/**
		 * Filters whether the plugin leaves a post alone: its links are never
		 * checked and never replaced.
		 *
		 * @param bool    $skip Whether to skip the post. Default false.
		 * @param WP_Post $post The post.
		 */
		return (bool) apply_filters( 'replace_broken_links_ai_skip_post', false, $post );
	}

	/**
	 * Whether someone has the post open in the editor right now.
	 *
	 * Same test as core's wp_check_post_lock(), which isn't loaded during cron.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function is_being_edited( WP_Post $post ) {
		$lock = explode( ':', (string) get_post_meta( $post->ID, '_edit_lock', true ) );
		$time = isset( $lock[0] ) ? (int) $lock[0] : 0;
		$user = isset( $lock[1] ) ? (int) $lock[1] : 0;

		// Someone approving from the plugin's page isn't editing the post.
		if ( $user && get_current_user_id() === $user ) {
			return false;
		}

		/** This filter is documented in wp-admin/includes/post.php */
		$window = apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

		return $time && $time > time() - (int) $window;
	}

	/**
	 * Check every link in a post and record the broken ones.
	 *
	 * @param int $post_id Post ID.
	 * @param int $since   Reuse URL checks made after this Unix time.
	 * @return array{status: string, broken: int} status is done, skipped,
	 *               locked (open in the editor) or timeout (the batch ran out
	 *               of time; scan the post again next batch).
	 */
	private function scan_post( $post_id, $since ) {
		$result = array(
			'status' => 'skipped',
			'broken' => 0,
		);

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_status, self::STATUSES, true ) || ! in_array( $post->post_type, $this->plugin->post_types(), true ) || self::skip_post( $post ) ) {
			return $result;
		}

		if ( $this->is_being_edited( $post ) ) {
			$result['status'] = 'locked';
			return $result;
		}

		$seen     = array();
		$replaced = array();

		foreach ( Replace_Broken_Links_AI_Content::occurrences( $post->post_content ) as $href => $info ) {
			$url = $this->link_url( $href, $post );

			if ( '' === $url ) {
				continue;
			}

			$check = $this->check( $url, $since );

			if ( null === $check ) {
				$result['status'] = 'timeout';
				return $result;
			}

			if ( Replace_Broken_Links_AI_DB::URL_FAILING !== $check['state'] && Replace_Broken_Links_AI_DB::URL_DEAD !== $check['state'] ) {
				continue;
			}

			$dead = Replace_Broken_Links_AI_DB::URL_DEAD === $check['state'];
			$link = Replace_Broken_Links_AI_DB::get_link_by_url( $url );

			if ( ! $link ) {
				$link = Replace_Broken_Links_AI_DB::add_link( $url, $dead ? Replace_Broken_Links_AI_DB::DEAD : Replace_Broken_Links_AI_DB::WATCHING, $check );
				++$result['broken'];
			}

			if ( ! $link ) {
				continue;
			}

			Replace_Broken_Links_AI_DB::save_use( $link['id'], $post->ID, $href, $info['anchor'], $info['context'] );
			$seen[] = Replace_Broken_Links_AI_DB::use_id( $link['id'], $post->ID, $href );

			if ( Replace_Broken_Links_AI_DB::REPLACED === $link['status'] && '' !== $link['target'] ) {
				$replaced[ (int) $link['id'] ] = $link;
			}
		}

		// Links that have been edited out of the post since the last scan.
		$stale = array_diff( array_keys( Replace_Broken_Links_AI_DB::unchanged_post_uses( $post->ID ) ), $seen );
		Replace_Broken_Links_AI_DB::delete_uses( $stale );

		// A dead link that's already been replaced in other posts gets the same
		// replacement here (a new post linking to it, or a restored revision).
		foreach ( $replaced as $link ) {
			$this->apply_link( $link, 0, $link['method'] );
		}

		$result['status'] = 'done';

		return $result;
	}

	/**
	 * A link as an absolute http(s) URL without its fragment, or '' for links
	 * that aren't checked (anchors, mailto:, tel:, javascript:, shortcodes,
	 * template tags, admin links, skipped domains, and links to this site
	 * when those are off).
	 *
	 * @param string  $link Link as written in the post.
	 * @param WP_Post $post Post it's in.
	 * @return string
	 */
	private function link_url( $link, WP_Post $post ) {
		if ( '' === $link || '#' === $link[0] || '?' === $link[0] || preg_match( '/[\[\]{}<>\s]/', $link ) ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $link ) ) {
			$absolute = $link;
		} elseif ( 0 === strpos( $link, '//' ) ) {
			$absolute = wp_parse_url( home_url(), PHP_URL_SCHEME ) . ':' . $link;
		} else {
			$base     = get_permalink( $post );
			$absolute = WP_Http::make_absolute_url( $link, $base ? $base : home_url( '/' ) );
		}

		$absolute = Replace_Broken_Links_AI_Checker::strip_fragment( $absolute );

		if ( ! Replace_Broken_Links_AI_Checker::is_http_url( $absolute ) ) {
			return '';
		}

		if ( Replace_Broken_Links_AI_Checker::is_internal( $absolute ) ) {
			// Admin and login links answer differently to the checker than to a
			// logged-in visitor.
			if ( ! $this->plugin->get_setting( 'internal' ) || preg_match( '#/(wp-admin|wp-login\.php|wp-json)#i', (string) wp_parse_url( $absolute, PHP_URL_PATH ) ) ) {
				return '';
			}
		} elseif ( $this->plugin->host_listed( $absolute, 'skip_hosts' ) ) {
			return '';
		}

		return $absolute;
	}

	/**
	 * A URL's check, reusing a recent one when there is one.
	 *
	 * @param string $url   Absolute URL without a fragment.
	 * @param int    $since Reuse checks from after this Unix time.
	 * @return array|null The URL row (state, code, first_failed, ...), or null if the batch is out of time.
	 */
	private function check( $url, $since ) {
		$row = Replace_Broken_Links_AI_DB::get_url( $url );

		if ( $row && (int) $row['checked'] >= (int) $since ) {
			return $row;
		}

		if ( $this->requested && $this->out_of_time() ) {
			return null;
		}

		$this->requested = true;

		return $this->record( $url, Replace_Broken_Links_AI_Checker::check( $url ), $row );
	}

	/**
	 * Store a check, working out how long the URL has been failing, and keep
	 * its broken link row in step.
	 *
	 * A 404 or 410 (or a domain that changed hands) starts, or continues, the
	 * grace period. The link counts as dead once it's still failing when it's
	 * checked again at the end of it: at least two checks, apart from 404/410
	 * with the grace period set to "immediately" (the checker already asked
	 * twice). A page that loads ends it. An answer that proves nothing (a
	 * timeout, a server error) leaves it as it was and tries again later.
	 *
	 * @param string     $url    Absolute URL without a fragment.
	 * @param array      $result Replace_Broken_Links_AI_Checker::check() result.
	 * @param array|null $row    The URL's row before this check.
	 * @return array The URL's row now.
	 */
	private function record( $url, array $result, $row ) {
		$now     = time();
		$grace   = $this->plugin->grace_seconds();
		$was     = $row ? (string) $row['state'] : '';
		$failing = Replace_Broken_Links_AI_DB::URL_FAILING === $was || Replace_Broken_Links_AI_DB::URL_DEAD === $was;
		$fields  = array(
			'code'         => $result['code'],
			'state'        => Replace_Broken_Links_AI_DB::URL_OK,
			'kind'         => '',
			'note'         => '',
			'first_failed' => 0,
			'fails'        => 0,
			'next_check'   => 0,
		);
		$domain  = Replace_Broken_Links_AI_Checker::DOMAIN === $result['state'] && $this->plugin->get_setting( 'domains' );

		if ( Replace_Broken_Links_AI_Checker::GONE === $result['state'] || $domain ) {
			$first = $failing && (int) $row['first_failed'] ? (int) $row['first_failed'] : $now;
			$fails = $failing ? (int) $row['fails'] + 1 : 1;

			$fields['kind']         = $domain ? Replace_Broken_Links_AI_DB::KIND_DOMAIN : Replace_Broken_Links_AI_DB::KIND_GONE;
			$fields['note']         = $domain ? $result['note'] : '';
			$fields['first_failed'] = $first;
			$fields['fails']        = $fails;

			// A changed domain is a judgement call, so it always needs a second look.
			if ( $now - $first >= $grace && ( ( 0 === $grace && ! $domain ) || $fails >= 2 ) ) {
				$fields['state'] = Replace_Broken_Links_AI_DB::URL_DEAD;
			} else {
				$fields['state']      = Replace_Broken_Links_AI_DB::URL_FAILING;
				$fields['next_check'] = max( $now + ( $grace ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS ), min( $first + $grace, $now + DAY_IN_SECONDS ) );
			}
		} elseif ( Replace_Broken_Links_AI_Checker::ERROR === $result['state'] ) {
			if ( $failing ) {
				// Can't tell this time: keep counting from the first failure and look again.
				$fields['state']        = $was;
				$fields['code']         = (int) $row['code'];
				$fields['kind']         = (string) $row['kind'];
				$fields['note']         = (string) $row['note'];
				$fields['first_failed'] = (int) $row['first_failed'];
				$fields['fails']        = (int) $row['fails'];
				$fields['next_check']   = Replace_Broken_Links_AI_DB::URL_FAILING === $was ? $now + 6 * HOUR_IN_SECONDS : 0;
			} else {
				$fields['state'] = Replace_Broken_Links_AI_DB::URL_ERROR;
			}
		}

		Replace_Broken_Links_AI_DB::save_url( $url, $fields );

		$this->sync_link( $url, $fields );

		return array_merge( $fields, array( 'url' => $url ) );
	}

	/**
	 * Keep a URL's broken link row in step with its latest check: confirmed
	 * dead moves it on to a lookup, and a page that loads again takes it off
	 * the list (unless it's already been replaced or ignored).
	 *
	 * @param string $url    URL.
	 * @param array  $fields The URL's check.
	 * @return void
	 */
	private function sync_link( $url, array $fields ) {
		$link = Replace_Broken_Links_AI_DB::get_link_by_url( $url );

		if ( ! $link ) {
			return;
		}

		$failing = Replace_Broken_Links_AI_DB::URL_DEAD === $fields['state'] || Replace_Broken_Links_AI_DB::URL_FAILING === $fields['state'];

		if ( $failing && ( $fields['kind'] !== $link['kind'] || $fields['note'] !== $link['note'] ) ) {
			Replace_Broken_Links_AI_DB::update_link(
				$link['id'],
				array(
					'kind'    => $fields['kind'],
					'note'    => $fields['note'],
					'updated' => (int) $link['updated'],
				)
			);
		}

		if ( Replace_Broken_Links_AI_DB::URL_DEAD === $fields['state'] && Replace_Broken_Links_AI_DB::WATCHING === $link['status'] ) {
			Replace_Broken_Links_AI_DB::update_link(
				$link['id'],
				array(
					'status' => Replace_Broken_Links_AI_DB::DEAD,
					'code'   => $fields['code'],
				)
			);
		} elseif ( Replace_Broken_Links_AI_DB::URL_OK === $fields['state'] && in_array( $link['status'], array( Replace_Broken_Links_AI_DB::WATCHING, Replace_Broken_Links_AI_DB::DEAD, Replace_Broken_Links_AI_DB::REVIEW, Replace_Broken_Links_AI_DB::NONE, Replace_Broken_Links_AI_DB::FAILED ), true ) ) {
			Replace_Broken_Links_AI_DB::delete_links( array( (int) $link['id'] ) );
		}
	}

	// Lookups -----------------------------------------------------------------

	/**
	 * Look up a replacement for one broken link, save what was found and, in
	 * automatic mode, swap it in when the AI is sure enough.
	 *
	 * @param array $link Broken link row.
	 * @return array|WP_Error The finder's result.
	 */
	public function lookup( array $link ) {
		$uses = array_values(
			array_filter(
				Replace_Broken_Links_AI_DB::get_uses( $link['id'], true ),
				function ( $use ) {
					return ! empty( $use['post_type'] );
				}
			)
		);

		if ( ! $uses ) {
			Replace_Broken_Links_AI_DB::delete_orphans();
			return new WP_Error( 'rblai_no_uses', __( 'No post links to this page any more.', 'replace-broken-links-ai' ) );
		}

		// One more check right before spending anything on it.
		if ( ! $this->still_dead( $link['url'] ) ) {
			return new WP_Error( 'rblai_recovered', __( 'The original page works again, so the link was left as it is.', 'replace-broken-links-ai' ) );
		}

		$link   = Replace_Broken_Links_AI_DB::get_link( $link['id'] );
		$result = $link ? $this->finder->find( $link, $uses ) : new WP_Error( 'rblai_gone', __( 'That link is no longer on the list.', 'replace-broken-links-ai' ) );

		if ( is_wp_error( $result ) ) {
			$data  = $result->get_error_data();
			$fatal = is_array( $data ) && ! empty( $data['fatal'] );

			if ( $fatal ) {
				// A rejected key or an empty account: stop every lookup until it's fixed,
				// and leave this link as it was.
				Replace_Broken_Links_AI_Plugin::pause( $result->get_error_message() );
			} else {
				Replace_Broken_Links_AI_DB::update_link(
					$link['id'],
					array(
						'status'   => Replace_Broken_Links_AI_DB::FAILED,
						'attempts' => (int) $link['attempts'] + 1,
						'error'    => $result->get_error_message(),
					)
				);
			}

			return $result;
		}

		$fields = array(
			'archive'    => is_array( $result['archive'] ) ? $result['archive'] : '',
			'query'      => $result['query'],
			'candidates' => $result['candidates'],
			'choice'     => $result['choice'],
			'confidence' => $result['confidence'],
			'reason'     => $result['reason'],
			'attempts'   => 0,
			'error'      => '',
		);

		if ( 'found' !== $result['status'] ) {
			Replace_Broken_Links_AI_DB::update_link(
				$link['id'],
				array_merge(
					$fields,
					array(
						'status'       => Replace_Broken_Links_AI_DB::NONE,
						'target'       => '',
						'target_title' => '',
						'target_code'  => 0,
					)
				)
			);

			return $result;
		}

		Replace_Broken_Links_AI_DB::update_link(
			$link['id'],
			array_merge(
				$fields,
				array(
					'status'       => Replace_Broken_Links_AI_DB::REVIEW,
					'target'       => $result['target'],
					'target_title' => $result['target_title'],
					'target_code'  => $result['target_code'],
				)
			)
		);

		if ( $this->automatic( $link, $result ) ) {
			$this->apply_link( Replace_Broken_Links_AI_DB::get_link( $link['id'] ), 0, 'auto' );
		}

		return $result;
	}

	/**
	 * Whether a found replacement goes in without asking: automatic mode, a
	 * checked page, and an AI sure enough about it. A link whose domain
	 * changed hands always waits for a person (that's a judgement call), and
	 * an archived copy only goes in automatically when that's switched on.
	 *
	 * @param array $link   Broken link row.
	 * @param array $result The finder's result.
	 * @return bool
	 */
	private function automatic( array $link, array $result ) {
		if ( 'auto' !== $this->plugin->get_setting( 'mode' ) || ! $result['verified'] || Replace_Broken_Links_AI_DB::KIND_DOMAIN === $link['kind'] ) {
			return false;
		}

		if ( ! empty( $result['archived'] ) ) {
			return 'auto' === $this->plugin->get_setting( 'archive_fallback' );
		}

		return $this->sure_enough( $result['confidence'] );
	}

	/**
	 * Check a dead link once more, right before it's replaced, and keep its
	 * row in step. A page that works again is taken off the list.
	 *
	 * @param string $url Dead URL.
	 * @return bool Whether it still counts as gone.
	 */
	private function still_dead( $url ) {
		$row = $this->record( $url, Replace_Broken_Links_AI_Checker::check( $url ), Replace_Broken_Links_AI_DB::get_url( $url ) );

		return Replace_Broken_Links_AI_DB::URL_DEAD === $row['state'] || Replace_Broken_Links_AI_DB::URL_FAILING === $row['state'];
	}

	/**
	 * Whether the AI's confidence is enough to replace without asking.
	 *
	 * @param string $confidence high, medium or low.
	 * @return bool
	 */
	private function sure_enough( $confidence ) {
		$allowed = 'high' === $this->plugin->get_setting( 'min_confidence' ) ? array( 'high' ) : array( 'high', 'medium' );

		return in_array( $confidence, $allowed, true );
	}

	// Replacing ---------------------------------------------------------------

	/**
	 * Swap a broken link for its replacement in every post that still has it
	 * (one save per post).
	 *
	 * @param array|null $link    Broken link row with a target.
	 * @param int        $user_id Who approved it (0 for automatic); they need edit_post on each post.
	 * @param string     $method  auto, approved or manual.
	 * @return array{done: int, gone: int, failed: int, waiting: int} waiting counts
	 *         posts open in the editor, which get the change when they're next scanned.
	 */
	public function apply_link( $link, $user_id, $method ) {
		$counts = array(
			'done'    => 0,
			'gone'    => 0,
			'failed'  => 0,
			'waiting' => 0,
		);

		if ( ! $link || ! Replace_Broken_Links_AI_Checker::is_http_url( $link['target'] ) ) {
			return $counts;
		}

		$by_post = array();
		foreach ( Replace_Broken_Links_AI_DB::get_uses( $link['id'], true ) as $use ) {
			$by_post[ (int) $use['post_id'] ][] = $use;
		}

		foreach ( $by_post as $post_id => $uses ) {
			$post = get_post( $post_id );

			if ( ! $post || self::skip_post( $post ) ) {
				Replace_Broken_Links_AI_DB::delete_uses( wp_list_pluck( $uses, 'id' ) );
				$counts['gone'] += count( $uses );
				continue;
			}

			if ( $user_id && ! current_user_can( 'edit_post', $post_id ) ) {
				$counts['failed'] += count( $uses );
				continue;
			}

			if ( $this->is_being_edited( $post ) ) {
				// Saving now would clash with the editor; the next scan of the post does it.
				$this->add_to_queue( $post_id );
				self::schedule_batch( 5 * MINUTE_IN_SECONDS );
				$counts['waiting'] += count( $uses );
				continue;
			}

			$map = array();
			foreach ( $uses as $use ) {
				$map[ $use['href'] ] = self::output_link( $use['href'], $link['target'] );
			}

			$places  = array();
			$content = Replace_Broken_Links_AI_Content::replace( $post->post_content, $map, $places );
			$md5     = $content !== $post->post_content ? $this->commit( $post, $content ) : '';

			foreach ( $uses as $use ) {
				if ( ! isset( $places[ $use['href'] ] ) ) {
					Replace_Broken_Links_AI_DB::delete_uses( array( (int) $use['id'] ) );
					++$counts['gone'];
				} elseif ( '' === $md5 ) {
					++$counts['failed'];
				} else {
					Replace_Broken_Links_AI_DB::mark_use( $use['id'], true, $places[ $use['href'] ], $md5 );
					++$counts['done'];
				}
			}
		}

		if ( $counts['done'] || $counts['waiting'] ) {
			$fields = array( 'status' => Replace_Broken_Links_AI_DB::REPLACED );

			// Keep who decided, when a scan later applies it to another post.
			if ( Replace_Broken_Links_AI_DB::REPLACED !== $link['status'] ) {
				$fields['method']  = $method;
				$fields['user_id'] = (int) $user_id;
			}

			Replace_Broken_Links_AI_DB::update_link( $link['id'], $fields );
			Replace_Broken_Links_AI_Usage::add( array( 'replaced' => $counts['done'] ) );
		}

		return $counts;
	}

	/**
	 * The link to write in place of the old one: the replacement, kept
	 * root-relative ("/about/") when the old link was and the replacement is
	 * on this site.
	 *
	 * @param string $old    Link as written in the post.
	 * @param string $target Absolute replacement URL.
	 * @return string
	 */
	private static function output_link( $old, $target ) {
		$home  = wp_parse_url( home_url() );
		$parts = wp_parse_url( $target );

		if (
			'/' === substr( $old, 0, 1 ) && '//' !== substr( $old, 0, 2 )
			&& is_array( $home ) && is_array( $parts ) && isset( $home['host'], $parts['host'] )
			&& strtolower( $home['host'] ) === strtolower( $parts['host'] )
			&& ( isset( $home['port'] ) ? $home['port'] : null ) === ( isset( $parts['port'] ) ? $parts['port'] : null )
		) {
			return ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}

		return $target;
	}

	/**
	 * Save new content and carry the post's earlier replacements over to it.
	 *
	 * @param WP_Post $post    Post as it is now.
	 * @param string  $content New content.
	 * @return string md5 of the content as saved, or '' if it couldn't be saved.
	 */
	private function commit( WP_Post $post, $content ) {
		if ( ! $this->save_content( $post, $content ) ) {
			return '';
		}

		// Read back what was stored: filters on save can tidy the markup.
		$md5 = md5( (string) get_post_field( 'post_content', $post->ID, 'raw' ) );

		Replace_Broken_Links_AI_DB::rebase( $post->ID, md5( $post->post_content ), $md5 );

		return $md5;
	}

	/**
	 * Save new content for a post.
	 *
	 * Goes through wp_update_post(), so caches are cleared and a revision is
	 * saved, but:
	 * - the content isn't run through kses. Only link URLs have changed, and
	 *   in the background there's no logged-in user, so kses would strip
	 *   embeds and other markup an administrator was allowed to add.
	 * - the "last modified" date stays the same, because updating a link isn't
	 *   a content update (and shouldn't touch every sitemap date).
	 * - a post with no revisions gets one of its old content first, so the
	 *   change can always be rolled back from the Revisions screen.
	 *
	 * @param WP_Post $post    Post as it is now.
	 * @param string  $content New content.
	 * @return bool
	 */
	private function save_content( WP_Post $post, $content ) {
		if ( function_exists( 'wp_revisions_enabled' ) && wp_revisions_enabled( $post ) && function_exists( '_wp_put_post_revision' ) ) {
			$revisions = wp_get_post_revisions(
				$post->ID,
				array(
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			if ( ! $revisions ) {
				_wp_put_post_revision( $post );
			}
		}

		$keep_dates = function ( $data, $postarr ) use ( $post ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === (int) $post->ID ) {
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;
			}
			return $data;
		};

		$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$this->saving = true;
		kses_remove_filters();
		add_filter( 'wp_insert_post_data', $keep_dates, 99, 2 );

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			),
			true
		);

		remove_filter( 'wp_insert_post_data', $keep_dates, 99 );
		if ( $had_kses ) {
			kses_init_filters();
		}
		$this->saving = false;

		return $result && ! is_wp_error( $result );
	}

	// Review actions ----------------------------------------------------------

	/**
	 * Broken link rows with one of some statuses.
	 *
	 * @param int[]    $ids      Row IDs.
	 * @param string[] $statuses Statuses the rows must have.
	 * @return array[]
	 */
	private static function rows( array $ids, array $statuses ) {
		$rows = array();

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$row = Replace_Broken_Links_AI_DB::get_link( $id );
			if ( $row && in_array( $row['status'], $statuses, true ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Apply found replacements (Needs review). Each dead link is checked once
	 * more first; one that works again is left alone and taken off the list.
	 *
	 * @param int[] $ids     Broken link rows.
	 * @param int   $user_id Who approved them.
	 * @return array{done: int, gone: int, failed: int, waiting: int, recovered: int}
	 */
	public function approve( array $ids, $user_id ) {
		$counts = array(
			'done'      => 0,
			'gone'      => 0,
			'failed'    => 0,
			'waiting'   => 0,
			'recovered' => 0,
		);

		foreach ( self::rows( $ids, array( Replace_Broken_Links_AI_DB::REVIEW ) ) as $row ) {
			if ( ! $this->still_dead( $row['url'] ) ) {
				++$counts['recovered'];
				continue;
			}

			foreach ( $this->apply_link( $row, $user_id, 'approved' ) as $key => $n ) {
				$counts[ $key ] += $n;
			}
		}

		return $counts;
	}

	/**
	 * Use one of the other search results, or an address someone typed, as
	 * the replacement, and swap it in.
	 *
	 * @param int    $id      Broken link row.
	 * @param int    $number  Result number (1-based), or 0 to use $url.
	 * @param string $url     Address typed by the user (when $number is 0).
	 * @param int    $user_id Who chose it.
	 * @return array|WP_Error Counts from apply_link().
	 */
	public function choose( $id, $number, $url, $user_id ) {
		$link = Replace_Broken_Links_AI_DB::get_link( $id );

		if ( ! $link || ! in_array( $link['status'], array( Replace_Broken_Links_AI_DB::DEAD, Replace_Broken_Links_AI_DB::REVIEW, Replace_Broken_Links_AI_DB::NONE, Replace_Broken_Links_AI_DB::FAILED, Replace_Broken_Links_AI_DB::WATCHING ), true ) ) {
			return new WP_Error( 'rblai_choose_status', __( 'That link can\'t be changed from here any more. Reload the page.', 'replace-broken-links-ai' ) );
		}

		$title = '';

		if ( $number > 0 ) {
			$candidates = json_decode( (string) $link['candidates'], true );

			if ( ! is_array( $candidates ) || ! isset( $candidates[ $number - 1 ]['link'] ) ) {
				return new WP_Error( 'rblai_choose_missing', __( 'That search result isn\'t there any more.', 'replace-broken-links-ai' ) );
			}

			$url   = (string) $candidates[ $number - 1 ]['link'];
			$title = isset( $candidates[ $number - 1 ]['title'] ) ? (string) $candidates[ $number - 1 ]['title'] : '';
		}

		$url = trim( (string) $url );

		if ( ! Replace_Broken_Links_AI_Checker::is_http_url( $url ) ) {
			return new WP_Error( 'rblai_choose_url', __( 'Enter a full web address, starting with https://.', 'replace-broken-links-ai' ) );
		}

		// A public web page, or a page on this site: never an IP address or a
		// local-only name (a typo, or a link readers couldn't open).
		if ( ! Replace_Broken_Links_AI_Checker::is_internal( $url ) && ! Replace_Broken_Links_AI_Checker::is_public_host( $url ) ) {
			return new WP_Error( 'rblai_choose_host', __( 'Use the address of a public web page (or a page on this site).', 'replace-broken-links-ai' ) );
		}

		if ( Replace_Broken_Links_AI_Checker::same_page( $url, $link['url'] ) ) {
			return new WP_Error( 'rblai_choose_same', __( 'That\'s the broken link itself.', 'replace-broken-links-ai' ) );
		}

		if ( ! $this->still_dead( $link['url'] ) ) {
			return new WP_Error( 'rblai_recovered', __( 'The original page works again, so the link was left as it is.', 'replace-broken-links-ai' ) );
		}

		$check = Replace_Broken_Links_AI_Checker::check( $url );

		if ( Replace_Broken_Links_AI_Checker::DOMAIN === $check['state'] ) {
			return new WP_Error( 'rblai_choose_domain', __( 'That page is on a domain that looks parked or taken over. Pick another.', 'replace-broken-links-ai' ) );
		}

		if ( Replace_Broken_Links_AI_Checker::GONE === $check['state'] ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'rblai_choose_gone', sprintf( __( 'That page doesn\'t load either (%d). Pick another.', 'replace-broken-links-ai' ), $check['code'] ) );
		}

		Replace_Broken_Links_AI_DB::update_link(
			$link['id'],
			array(
				'target'       => esc_url_raw( $url, array( 'http', 'https' ) ),
				'target_title' => sanitize_text_field( $title ),
				'target_code'  => $check['code'],
				'choice'       => max( 0, (int) $number ),
			)
		);

		return $this->apply_link( Replace_Broken_Links_AI_DB::get_link( $link['id'] ), $user_id, 'manual' );
	}

	/**
	 * Put replaced links back the way they were, and stop them being replaced again.
	 *
	 * Only the exact spots the replacement changed are put back, and only if
	 * the post hasn't been edited since; otherwise the post's revisions are
	 * the way back.
	 *
	 * @param int[] $ids     Broken link rows.
	 * @param int   $user_id Who undid them.
	 * @return array{done: int, edited: int, failed: int} edited counts posts
	 *               left alone because they've been edited since.
	 */
	public function undo( array $ids, $user_id ) {
		$counts = array(
			'done'   => 0,
			'edited' => 0,
			'failed' => 0,
		);

		foreach ( self::rows( $ids, array( Replace_Broken_Links_AI_DB::REPLACED ) ) as $row ) {
			$undone = 0;

			foreach ( Replace_Broken_Links_AI_DB::get_uses( $row['id'] ) as $use ) {
				if ( ! (int) $use['changed'] ) {
					continue;
				}

				$post = get_post( (int) $use['post_id'] );

				if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
					++$counts['failed'];
					continue;
				}

				$places  = json_decode( (string) $use['places'], true );
				$content = md5( $post->post_content ) === $use['content_md5'] && is_array( $places )
					? Replace_Broken_Links_AI_Content::revert( $post->post_content, self::output_link( $use['href'], $row['target'] ), $use['href'], $places )
					: $post->post_content;

				if ( $content === $post->post_content ) {
					++$counts['edited'];
					continue;
				}

				if ( '' === $this->commit( $post, $content ) ) {
					++$counts['failed'];
					continue;
				}

				Replace_Broken_Links_AI_DB::mark_use( $use['id'], false );
				++$undone;
			}

			if ( $undone ) {
				Replace_Broken_Links_AI_DB::update_link(
					$row['id'],
					array(
						'status'  => Replace_Broken_Links_AI_DB::IGNORED,
						'method'  => 'undone',
						'user_id' => $user_id,
					)
				);
				++$counts['done'];
			}
		}

		return $counts;
	}

	/**
	 * Whether a replaced link can still be undone automatically: at least one
	 * of its posts hasn't been edited since.
	 *
	 * @param array[] $uses Its uses, with post_md5 (Replace_Broken_Links_AI_DB::uses_for_links()).
	 * @return bool
	 */
	public static function can_undo( array $uses ) {
		foreach ( $uses as $use ) {
			if ( (int) $use['changed'] && '' !== (string) $use['content_md5'] && $use['content_md5'] === $use['post_md5'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Leave broken links alone (they won't be looked up or changed).
	 *
	 * @param int[] $ids     Broken link rows.
	 * @param int   $user_id Who ignored them.
	 * @return int Rows changed.
	 */
	public function ignore( array $ids, $user_id ) {
		$changed = 0;

		foreach ( self::rows( $ids, array( Replace_Broken_Links_AI_DB::WATCHING, Replace_Broken_Links_AI_DB::DEAD, Replace_Broken_Links_AI_DB::REVIEW, Replace_Broken_Links_AI_DB::NONE, Replace_Broken_Links_AI_DB::FAILED ) ) as $row ) {
			Replace_Broken_Links_AI_DB::update_link(
				$row['id'],
				array(
					'status'  => Replace_Broken_Links_AI_DB::IGNORED,
					'method'  => 'ignored',
					'user_id' => $user_id,
				)
			);
			++$changed;
		}

		return $changed;
	}

	/**
	 * Take ignored links off the ignore list. One that already has a
	 * replacement goes back to Needs review; the rest wait for a lookup again.
	 *
	 * @param int[] $ids Broken link rows.
	 * @return int Rows changed.
	 */
	public function restore( array $ids ) {
		$changed = 0;

		foreach ( self::rows( $ids, array( Replace_Broken_Links_AI_DB::IGNORED ) ) as $row ) {
			$url    = Replace_Broken_Links_AI_DB::get_url( $row['url'] );
			$dead   = $url && Replace_Broken_Links_AI_DB::URL_DEAD === $url['state'];
			$status = '' !== $row['target'] ? Replace_Broken_Links_AI_DB::REVIEW : ( $dead ? Replace_Broken_Links_AI_DB::DEAD : Replace_Broken_Links_AI_DB::WATCHING );

			Replace_Broken_Links_AI_DB::update_link(
				$row['id'],
				array(
					'status'  => $status,
					'method'  => '',
					'user_id' => 0,
				)
			);
			++$changed;
		}

		if ( $changed ) {
			self::schedule_batch( 0 );
		}

		return $changed;
	}

	/**
	 * Look again: queue links for a fresh lookup at the next batch.
	 *
	 * @param int[] $ids Broken link rows.
	 * @return int Rows changed.
	 */
	public function retry( array $ids ) {
		$changed = 0;

		foreach ( self::rows( $ids, array( Replace_Broken_Links_AI_DB::DEAD, Replace_Broken_Links_AI_DB::REVIEW, Replace_Broken_Links_AI_DB::NONE, Replace_Broken_Links_AI_DB::FAILED ) ) as $row ) {
			Replace_Broken_Links_AI_DB::update_link(
				$row['id'],
				array(
					'status'   => Replace_Broken_Links_AI_DB::DEAD,
					'attempts' => 0,
					'error'    => '',
				)
			);
			++$changed;
		}

		if ( $changed ) {
			self::schedule_batch( 0 );
		}

		return $changed;
	}

	/**
	 * Look up one link right now (the "Find a replacement now" button), within
	 * the lookup lock and the daily limit.
	 *
	 * @param int $id Broken link row.
	 * @return array|WP_Error The finder's result.
	 */
	public function lookup_now( $id ) {
		$link = Replace_Broken_Links_AI_DB::get_link( $id );

		if ( ! $link || ! in_array( $link['status'], array( Replace_Broken_Links_AI_DB::DEAD, Replace_Broken_Links_AI_DB::REVIEW, Replace_Broken_Links_AI_DB::NONE, Replace_Broken_Links_AI_DB::FAILED ), true ) ) {
			return new WP_Error( 'rblai_lookup_status', __( 'That link isn\'t waiting for a replacement. Reload the page.', 'replace-broken-links-ai' ) );
		}

		if ( ! $this->plugin->ready() ) {
			return new WP_Error( 'rblai_lookup_ready', __( 'Connect an AI and Serper.dev under Connections first.', 'replace-broken-links-ai' ) );
		}

		$paused = Replace_Broken_Links_AI_Plugin::paused();
		if ( '' !== $paused ) {
			return new WP_Error( 'rblai_lookup_paused', $paused );
		}

		if ( ! $this->under_daily_limit() ) {
			return new WP_Error( 'rblai_lookup_limit', __( 'Today\'s lookup limit has been reached. Raise it under Settings, or try again tomorrow.', 'replace-broken-links-ai' ) );
		}

		if ( ! $this->lock( self::LOOKUP_LOCK_OPTION, self::LOOKUP_LOCK_TTL ) ) {
			return new WP_Error( 'rblai_lookup_busy', __( 'Another lookup is running. Try again in a minute.', 'replace-broken-links-ai' ) );
		}

		$result = $this->lookup( $link );

		$this->unlock( self::LOOKUP_LOCK_OPTION );

		return $result;
	}
}
