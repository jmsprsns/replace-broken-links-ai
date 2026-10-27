<?php
/**
 * Finds a replacement for a dead link.
 *
 * 1. What was it? The Wayback Machine's last good copy of the page gives its
 *    title and opening text (when there is one).
 * 2. The AI reads all that, plus the link text and the sentence around the
 *    link, and writes a search.
 * 3. Candidates:
 *    - links to this site: the site's own published posts and pages that
 *      match (no Google search at all);
 *    - other links: the dead link's own website first (a site: search, in
 *      case the page just moved), then Google's top 10 from Serper.dev.
 *    Results from preferred sources (.gov, .edu, domains you list) are marked.
 * 4. The AI answers with the number of the best result (and a backup), how
 *    sure it is, and why.
 * 5. The chosen page is checked: it has to answer 200, not ask search engines
 *    to ignore it (noindex), and not be a parked domain. The link goes to
 *    where it ends up after redirects, so it never starts a redirect chain.
 *    If it fails, the backup is checked instead.
 * 6. Nothing usable? The Wayback Machine's copy can be offered instead.
 *
 * The AI only ever answers with a result's number. The address that ends
 * up in the post comes from the search results (or the archive), after it
 * passes the same URL checks as everything else, so nothing the AI writes
 * can put a link into a post that the search didn't return.
 *
 * This class only finds; the scanner decides what to do with the answer
 * (replace the link, or wait for approval) and saves it.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replacement finder.
 */
final class Replace_Broken_Links_AI_Finder {

	/**
	 * Results kept from the search of the dead link's own website.
	 */
	const ORIGINAL_RESULTS = 5;

	/**
	 * Words too common to find a post by.
	 */
	const STOP_WORDS = array( 'about', 'after', 'also', 'and', 'best', 'blog', 'from', 'guide', 'have', 'here', 'how', 'html', 'into', 'more', 'page', 'post', 'that', 'the', 'their', 'this', 'what', 'when', 'where', 'which', 'with', 'your', 'www', 'http', 'https', 'index', 'php' );

	/**
	 * Plugin instance.
	 *
	 * @var Replace_Broken_Links_AI_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Replace_Broken_Links_AI_Plugin $plugin Plugin instance.
	 */
	public function __construct( Replace_Broken_Links_AI_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Find a replacement for a broken link row.
	 *
	 * @param array   $link Broken link row.
	 * @param array[] $uses The posts that use it (Replace_Broken_Links_AI_DB::get_uses()).
	 * @return array|WP_Error See {@see run()}.
	 */
	public function find( array $link, array $uses ) {
		$exclude = array();
		foreach ( $uses as $use ) {
			$permalink = get_permalink( (int) $use['post_id'] );
			if ( $permalink ) {
				$exclude[] = $permalink;
			}
		}

		return $this->run( $link['url'], self::facts( $link['url'], $uses ), $exclude, (string) $link['kind'], (int) $link['found'] );
	}

	/**
	 * What the prompts get told about a dead link.
	 *
	 * @param string  $url  Dead URL.
	 * @param array[] $uses Posts that use it: post_title, anchor and context each.
	 * @return array<string,string> Placeholder name => plain text.
	 */
	public static function facts( $url, array $uses ) {
		$anchors  = array();
		$contexts = array();
		$title    = '';

		foreach ( array_slice( $uses, 0, 3 ) as $use ) {
			$post_title = isset( $use['post_title'] ) ? Replace_Broken_Links_AI_Content::text( $use['post_title'] ) : '';
			$anchor     = isset( $use['anchor'] ) ? trim( (string) $use['anchor'] ) : '';
			$context    = isset( $use['context'] ) ? trim( (string) $use['context'] ) : '';

			if ( '' === $title ) {
				$title = $post_title;
			}

			if ( '' !== $anchor && ! in_array( $anchor, $anchors, true ) ) {
				$anchors[] = $anchor;
			}

			if ( '' !== $context ) {
				$contexts[] = ( count( $uses ) > 1 && '' !== $post_title ? '(In "' . $post_title . '") ' : '' ) . $context;
			}
		}

		$locale = replace_broken_links_ai_plugin()->search_locale();

		return array(
			'dead_url'    => $url,
			'url_words'   => Replace_Broken_Links_AI_Prompts::url_words( $url ),
			'anchor_text' => $anchors ? implode( ' / ', $anchors ) : '(no link text)',
			'context'     => $contexts ? implode( "\n", $contexts ) : '(none)',
			'post_title'  => '' !== $title ? $title : '(untitled)',
			'site_name'   => Replace_Broken_Links_AI_Content::text( get_bloginfo( 'name' ) ),
			'language'    => Replace_Broken_Links_AI_Prompts::language_name( $locale['hl'] ),
		);
	}

	/**
	 * Run the steps.
	 *
	 * @param string   $dead_url Dead URL.
	 * @param array    $facts    From {@see facts()}.
	 * @param string[] $exclude  Pages a replacement must not be (the posts with the link).
	 * @param string   $kind     Why it's dead: gone (404/410) or domain (the domain changed hands).
	 * @param int      $since    When it started failing (for the Wayback Machine).
	 * @return array|WP_Error On success: status (found or none), query, candidates
	 *         (the results the AI saw), choice, confidence, reason, archive (the
	 *         Wayback copy, or null), and when found: target, target_title,
	 *         target_code, verified (false when the page turned the checker
	 *         away with 401/403, so it can't be confirmed) and archived (true
	 *         when the target is the Wayback copy).
	 */
	public function run( $dead_url, array $facts, array $exclude = array(), $kind = 'gone', $since = 0 ) {
		$plugin   = $this->plugin;
		$internal = Replace_Broken_Links_AI_Checker::is_internal( $dead_url );

		Replace_Broken_Links_AI_Usage::add( array( 'lookups' => 1 ) );

		// 1. What the page used to say.
		$archive = $plugin->get_setting( 'wayback' ) ? Replace_Broken_Links_AI_Wayback::snapshot( $dead_url, $since ) : null;

		$facts['archived'] = Replace_Broken_Links_AI_Prompts::archived( $archive );

		// 2. The search.
		$template = Replace_Broken_Links_AI_Prompts::template( 'query', $plugin->get_setting( 'query_prompt' ) );
		$answer   = $this->ask( 'query', Replace_Broken_Links_AI_Prompts::render( 'query', $template, $facts ), 'low' );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$query = self::clean_query( $answer );

		if ( '' === $query ) {
			return new WP_Error( 'rblai_query_empty', __( 'The AI didn\'t write a usable search.', 'replace-broken-links-ai' ), array( 'fatal' => false ) );
		}

		// 3. Candidates.
		if ( $internal ) {
			$results = $this->own_posts( $query, $facts, $dead_url );
			$label   = $query;
		} else {
			$results = $this->search( $query, $dead_url, Replace_Broken_Links_AI_DB::KIND_DOMAIN !== $kind );
			$label   = $query;

			if ( is_wp_error( $results ) ) {
				return $results;
			}
		}

		$results = $this->usable_results( $results, $dead_url, $exclude );
		$base    = array(
			'query'      => $label,
			'candidates' => $results,
			'choice'     => 0,
			'confidence' => '',
			'reason'     => '',
			'archive'    => $archive,
		);

		if ( ! $results ) {
			return $this->fallback( $base, __( 'The search found no pages that could be used.', 'replace-broken-links-ai' ) );
		}

		// 4. The AI's pick.
		$facts['query']   = $internal ? $query . ' (' . __( 'searching this site\'s own posts and pages', 'replace-broken-links-ai' ) . ')' : $query;
		$facts['results'] = Replace_Broken_Links_AI_Prompts::results_block( $results );

		$template = Replace_Broken_Links_AI_Prompts::template( 'pick', $plugin->get_setting( 'pick_prompt' ) );
		$answer   = $this->ask( 'pick', Replace_Broken_Links_AI_Prompts::render( 'pick', $template, $facts ), 'medium' );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$pick = self::parse_pick( $answer, count( $results ) );

		if ( null === $pick ) {
			return new WP_Error( 'rblai_pick_unreadable', __( 'The AI\'s answer couldn\'t be read. If you\'ve edited the choosing prompt, check it under Advanced.', 'replace-broken-links-ai' ), array( 'fatal' => false ) );
		}

		$base['confidence'] = $pick['confidence'];
		$base['reason']     = $pick['reason'];

		if ( ! $pick['choice'] ) {
			return $this->fallback( $base, $pick['reason'] );
		}

		// 5. The pick has to pass the checks. If it doesn't, try the backup.
		$unverified = null;
		$problems   = array();

		foreach ( array_filter( array( $pick['choice'], $pick['backup'] ) ) as $number ) {
			$result = $results[ $number - 1 ];
			$check  = Replace_Broken_Links_AI_Checker::check( $result['link'] );
			$target = $this->final_url( $result['link'], $check );

			$found = array_merge(
				$base,
				array(
					'status'       => 'found',
					'choice'       => $number,
					'target'       => $target,
					'target_title' => $result['title'],
					'target_code'  => $check['code'],
					'verified'     => true,
					'archived'     => false,
				)
			);

			// The backup is a second choice: never sure enough to go in unchecked.
			if ( $number !== $pick['choice'] ) {
				$found['confidence'] = 'low';
				$found['reason']     = trim( __( 'The first choice didn\'t pass the checks, so this is the AI\'s second choice.', 'replace-broken-links-ai' ) . ' ' . $pick['reason'] );
			}

			if ( Replace_Broken_Links_AI_Checker::LIVE === $check['state'] && '' !== $target && ! $check['noindex'] ) {
				return $found;
			}

			if ( $check['noindex'] ) {
				/* translators: %d: result number. */
				$problems[] = sprintf( __( 'Result %d asks search engines not to index it.', 'replace-broken-links-ai' ), $number );
			} elseif ( Replace_Broken_Links_AI_Checker::DOMAIN === $check['state'] ) {
				/* translators: %d: result number. */
				$problems[] = sprintf( __( 'Result %d is a parked domain.', 'replace-broken-links-ai' ), $number );
			} elseif ( Replace_Broken_Links_AI_Checker::BLOCKED === $check['state'] && null === $unverified ) {
				// Bot protection: the page is probably fine, but a person has to look.
				$found['verified'] = false;
				$found['target']   = $result['link'];
				$unverified        = $found;
			} else {
				/* translators: 1: result number, 2: HTTP status or 0. */
				$problems[] = sprintf( __( 'Result %1$d didn\'t load (%2$d).', 'replace-broken-links-ai' ), $number, $check['code'] );
			}
		}

		if ( null !== $unverified ) {
			return $unverified;
		}

		return $this->fallback( $base, trim( implode( ' ', $problems ) . ' ' . $pick['reason'] ) );
	}

	/**
	 * No live replacement: offer the Wayback Machine's copy instead, if that's
	 * turned on and there is one, else report that nothing was found.
	 *
	 * @param array  $base   Result so far.
	 * @param string $reason Why nothing live was found.
	 * @return array
	 */
	private function fallback( array $base, $reason ) {
		$archive = $base['archive'];

		if ( is_array( $archive ) && 'off' !== $this->plugin->get_setting( 'archive_fallback' ) && Replace_Broken_Links_AI_Checker::is_http_url( $archive['url'] ) ) {
			return array_merge(
				$base,
				array(
					'status'       => 'found',
					'confidence'   => 'low',
					/* translators: 1: date the copy was saved, 2: why no live page was used. */
					'reason'       => trim( sprintf( __( 'No live page matched, so this is the copy the Wayback Machine saved on %1$s. %2$s', 'replace-broken-links-ai' ), $archive['date'], $reason ) ),
					'target'       => $archive['url'],
					/* translators: %s: the archived page's title. */
					'target_title' => sprintf( __( 'Archived copy: %s', 'replace-broken-links-ai' ), '' !== $archive['title'] ? $archive['title'] : $archive['date'] ),
					'target_code'  => 200,
					'verified'     => true,
					'archived'     => true,
				)
			);
		}

		return array_merge(
			$base,
			array(
				'status' => 'none',
				'reason' => $reason,
			)
		);
	}

	/**
	 * Ask the AI one of the two questions, and count it.
	 *
	 * @param string $step   query or pick.
	 * @param string $prompt Filled-in prompt.
	 * @param string $effort Effort level.
	 * @return string|WP_Error The answer.
	 */
	private function ask( $step, $prompt, $effort ) {
		$plugin   = $this->plugin;
		$provider = $plugin->provider();
		$model    = $plugin->model( $step );
		$answer   = Replace_Broken_Links_AI_AI::complete(
			$provider,
			$plugin->ai_key( $provider ),
			$model,
			Replace_Broken_Links_AI_Prompts::system(),
			$prompt,
			$effort
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		Replace_Broken_Links_AI_Usage::add(
			array(
				'ai_calls'      => 1,
				'input_tokens'  => $answer['input_tokens'],
				'output_tokens' => $answer['output_tokens'],
				'cost'          => Replace_Broken_Links_AI_AI::cost_micros( $model, $answer['input_tokens'], $answer['output_tokens'] ),
			)
		);

		return $answer['text'];
	}

	/**
	 * Google results for a dead link on another website: its own website
	 * first (in case the page just moved), then the whole web.
	 *
	 * @param string $query    The AI's search.
	 * @param string $dead_url Dead URL.
	 * @param bool   $original Search the dead link's own website too (not when the domain changed hands).
	 * @return array[]|WP_Error
	 */
	private function search( $query, $dead_url, $original ) {
		$plugin  = $this->plugin;
		$locale  = $plugin->search_locale();
		$key     = $plugin->serper_key();
		$results = array();

		if ( $original && $plugin->get_setting( 'search_original' ) ) {
			$site  = Replace_Broken_Links_AI_Checker::site( $dead_url );
			$found = Replace_Broken_Links_AI_Serper::search( $key, $query . ' site:' . $site, $locale['gl'], $locale['hl'] );

			if ( is_wp_error( $found ) ) {
				$data = $found->get_error_data();
				if ( is_array( $data ) && ! empty( $data['fatal'] ) ) {
					return $found;
				}
			} else {
				$this->count_search();

				foreach ( array_slice( $found['results'], 0, self::ORIGINAL_RESULTS ) as $result ) {
					$result['original'] = true;
					$results[]          = $result;
				}
			}
		}

		$found = Replace_Broken_Links_AI_Serper::search( $key, $query, $locale['gl'], $locale['hl'] );

		if ( is_wp_error( $found ) ) {
			// The site search alone is still something to choose from.
			$data = $found->get_error_data();
			return $results && ! ( is_array( $data ) && ! empty( $data['fatal'] ) ) ? $results : $found;
		}

		$this->count_search();

		return array_merge( $results, $found['results'] );
	}

	/**
	 * Count a search and its cost.
	 *
	 * @return void
	 */
	private function count_search() {
		Replace_Broken_Links_AI_Usage::add(
			array(
				'searches' => 1,
				'cost'     => Replace_Broken_Links_AI_Serper::cost_micros(),
			)
		);
	}

	/**
	 * Candidates for a dead link to this site: its own published posts and
	 * pages, best matches first. Scored on the words of the AI's search, the
	 * old address and the link text, with title matches counting most, plus a
	 * strong bonus for a post whose slug matches the old one (a page that
	 * moved).
	 *
	 * The posts that contain the link are dropped afterwards, in usable_results().
	 *
	 * @param string $query    The AI's search.
	 * @param array  $facts    Facts about the link.
	 * @param string $dead_url Dead URL.
	 * @return array[] Results in the same shape as search results.
	 */
	private function own_posts( $query, array $facts, $dead_url ) {
		global $wpdb;

		$types = array_values( get_post_types( array( 'public' => true ) ) );
		$types = array_values( array_diff( $types, array( 'attachment' ) ) );
		$path  = trim( (string) wp_parse_url( $dead_url, PHP_URL_PATH ), '/' );
		$slug  = sanitize_title( basename( $path ) );
		$words = self::words( $query . ' ' . str_replace( '/', ' ', $path ) . ' ' . $facts['anchor_text'] );
		$score = array();

		// The same slug (or one that contains it): most likely the page moved.
		if ( strlen( $slug ) >= 4 ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_name LIKE %s ORDER BY post_date DESC LIMIT 5",
					'%' . $wpdb->esc_like( $slug ) . '%'
				)
			);
			foreach ( (array) $ids as $id ) {
				$score[ (int) $id ] = 20;
			}
		}

		foreach ( array_slice( $words, 0, 8 ) as $word ) {
			$query_obj = new WP_Query(
				array(
					's'                      => $word,
					'post_type'              => $types,
					'post_status'            => 'publish',
					'posts_per_page'         => 20,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'suppress_filters'       => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $query_obj->posts as $id ) {
				$id           = (int) $id;
				$title        = strtolower( get_the_title( $id ) );
				$score[ $id ] = ( isset( $score[ $id ] ) ? $score[ $id ] : 0 ) + ( false !== strpos( $title, $word ) ? 3 : 1 );
			}
		}

		arsort( $score );

		$results = array();
		foreach ( array_keys( $score ) as $id ) {
			$post = get_post( $id );
			$link = get_permalink( $id );

			if ( ! $post || 'publish' !== $post->post_status || ! $link || ! in_array( $post->post_type, $types, true ) || ! empty( $post->post_password ) ) {
				continue;
			}

			$excerpt = '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content;

			$results[] = array(
				'position' => count( $results ) + 1,
				'title'    => Replace_Broken_Links_AI_Content::cut( Replace_Broken_Links_AI_Content::text( get_the_title( $post ) ), 160 ),
				'link'     => $link,
				'snippet'  => Replace_Broken_Links_AI_Content::cut( Replace_Broken_Links_AI_Content::text( substr( $excerpt, 0, 4000 ) ), 320 ),
				'date'     => get_the_date( 'Y-m-d', $post ),
			);

			if ( count( $results ) >= Replace_Broken_Links_AI_Serper::RESULTS ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Distinct search words from some text: lower case, four letters or more,
	 * no common words, longest first.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function words( $text ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();
		$words = array_unique(
			array_filter(
				$words,
				function ( $word ) {
					return strlen( $word ) >= 4 && ! in_array( $word, self::STOP_WORDS, true ) && ! ctype_digit( $word );
				}
			)
		);

		usort(
			$words,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return array_values( $words );
	}

	/**
	 * Results that may be offered: not the dead page itself, not one of the
	 * posts with the link, not a domain on the "never link to" list, no
	 * duplicates. Preferred sources are marked. Numbered from 1 again.
	 *
	 * @param array[]  $results  Results.
	 * @param string   $dead_url Dead URL.
	 * @param string[] $exclude  Pages to leave out.
	 * @return array[]
	 */
	private function usable_results( array $results, $dead_url, array $exclude ) {
		$usable    = array();
		$seen      = array();
		$preferred = $this->plugin->preferred();

		foreach ( $results as $result ) {
			$link = $result['link'];

			if ( ! Replace_Broken_Links_AI_Checker::is_http_url( $link ) || Replace_Broken_Links_AI_Checker::same_page( $link, $dead_url ) || $this->plugin->host_listed( $link, 'block_hosts' ) ) {
				continue;
			}

			foreach ( $exclude as $page ) {
				if ( Replace_Broken_Links_AI_Checker::same_page( $link, $page ) ) {
					continue 2;
				}
			}

			$key = untrailingslashit( strtolower( Replace_Broken_Links_AI_Checker::strip_fragment( $link ) ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$result['position']  = count( $usable ) + 1;
			$result['preferred'] = $preferred && Replace_Broken_Links_AI_Checker::host_matches( $link, $preferred );
			$result['original']  = ! empty( $result['original'] );
			$usable[]            = $result;
		}

		return $usable;
	}

	/**
	 * The address to link to for a checked result: where it ends up after
	 * redirects (so the new link doesn't start a redirect chain), unless that
	 * looks like a removed page sent to the homepage, or isn't a safe URL.
	 *
	 * @param string $link  Result URL.
	 * @param array  $check Replace_Broken_Links_AI_Checker::check() result.
	 * @return string '' when the result shouldn't be used.
	 */
	private function final_url( $link, array $check ) {
		$final = (string) $check['final'];

		if ( '' === $final || $final === $link ) {
			return $link;
		}

		$old_path = trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' );
		$new_path = trim( (string) wp_parse_url( $final, PHP_URL_PATH ), '/' );

		if ( '' !== $old_path && '' === $new_path && '' === (string) wp_parse_url( $final, PHP_URL_QUERY ) ) {
			return ''; // Redirected to a homepage: the page is gone.
		}

		if ( ! Replace_Broken_Links_AI_Checker::is_http_url( $final ) || $this->plugin->host_listed( $final, 'block_hosts' ) ) {
			return '';
		}

		return esc_url_raw( $final, array( 'http', 'https' ) );
	}

	/**
	 * The search from the AI's answer: its first line, without quotes,
	 * labels or search operators, at most 200 characters.
	 *
	 * @param string $answer AI answer.
	 * @return string
	 */
	public static function clean_query( $answer ) {
		$lines = preg_split( '/\R/', trim( strip_tags( (string) $answer ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Linear time on any input, unlike wp_strip_all_tags().
		$line  = '';

		foreach ( (array) $lines as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $candidate && '```' !== substr( $candidate, 0, 3 ) ) {
				$line = $candidate;
				break;
			}
		}

		$line = (string) preg_replace( '/^(?:[-*•\d.)\s]+)?(?:search(?: query)?|query|google)\s*:\s*/iu', '', $line );
		$line = (string) preg_replace( '/\b(?:site|inurl|intitle|intext|filetype|related|cache):\S*/iu', ' ', $line );
		$line = (string) preg_replace( '/^[\s"\'`“”‘’*]+|[\s"\'`“”‘’*]+$/u', '', $line );
		$line = (string) preg_replace( '/\s+/u', ' ', $line );

		return Replace_Broken_Links_AI_Content::cut( sanitize_text_field( $line ), 200 );
	}

	/**
	 * Read the AI's pick.
	 *
	 * @param string $answer AI answer (JSON, possibly in a code fence or with words around it).
	 * @param int    $count  Number of results it was shown.
	 * @return array{choice: int, backup: int, confidence: string, reason: string}|null Null if unreadable.
	 */
	public static function parse_pick( $answer, $count ) {
		$answer = (string) $answer;
		$start  = strpos( $answer, '{' );
		$end    = strrpos( $answer, '}' );
		$data   = false !== $start && false !== $end && $end > $start ? json_decode( substr( $answer, $start, $end - $start + 1 ), true ) : null;

		if ( ! is_array( $data ) ) {
			// Not JSON, but maybe still says which one.
			if ( ! preg_match( '/"?choice"?\s*[:=]\s*(\d+)/i', $answer, $m ) ) {
				return null;
			}

			$data = array( 'choice' => (int) $m[1] );
		}

		$choice     = isset( $data['choice'] ) && is_numeric( $data['choice'] ) ? (int) $data['choice'] : -1;
		$backup     = isset( $data['backup'] ) && is_numeric( $data['backup'] ) ? (int) $data['backup'] : 0;
		$confidence = isset( $data['confidence'] ) && is_string( $data['confidence'] ) ? strtolower( trim( $data['confidence'] ) ) : '';
		$reason     = isset( $data['reason'] ) && is_string( $data['reason'] ) ? Replace_Broken_Links_AI_Content::cut( sanitize_text_field( $data['reason'] ), 300 ) : '';

		if ( $choice < 0 || $choice > $count ) {
			return null;
		}

		return array(
			'choice'     => $choice,
			'backup'     => $backup > 0 && $backup <= $count && $backup !== $choice ? $backup : 0,
			'confidence' => in_array( $confidence, array( 'high', 'medium', 'low' ), true ) ? $confidence : 'low',
			'reason'     => $reason,
		);
	}
}
