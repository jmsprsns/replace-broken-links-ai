<?php
/**
 * The broken links list on the plugin's Links tab.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List of broken links in one view.
 */
final class Replace_Broken_Links_AI_List_Table extends WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Views: id => statuses shown.
	 */
	const VIEWS = array(
		'review'   => array( Replace_Broken_Links_AI_DB::REVIEW ),
		'replaced' => array( Replace_Broken_Links_AI_DB::REPLACED ),
		'broken'   => array( Replace_Broken_Links_AI_DB::DEAD, Replace_Broken_Links_AI_DB::NONE, Replace_Broken_Links_AI_DB::FAILED ),
		'watching' => array( Replace_Broken_Links_AI_DB::WATCHING ),
		'ignored'  => array( Replace_Broken_Links_AI_DB::IGNORED ),
	);

	/**
	 * Bulk actions each view offers.
	 */
	const BULK = array(
		'review'   => array( 'approve', 'retry', 'ignore' ),
		'replaced' => array( 'undo' ),
		'broken'   => array( 'retry', 'ignore' ),
		'watching' => array( 'ignore' ),
		'ignored'  => array( 'restore' ),
	);

	/**
	 * Current view.
	 *
	 * @var string
	 */
	private $view;

	/**
	 * Uses of the rows on this page, by link ID.
	 *
	 * @var array<int,array[]>
	 */
	private $uses = array();

	/**
	 * Plugin instance.
	 *
	 * @var Replace_Broken_Links_AI_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param string                         $view   View ID.
	 * @param Replace_Broken_Links_AI_Plugin $plugin Plugin instance.
	 */
	public function __construct( $view, Replace_Broken_Links_AI_Plugin $plugin ) {
		$this->view   = isset( self::VIEWS[ $view ] ) ? $view : 'review';
		$this->plugin = $plugin;

		parent::__construct(
			array(
				'singular' => 'broken-link',
				'plural'   => 'broken-links',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The view's label.
	 *
	 * @param string $view View ID.
	 * @return string
	 */
	public static function view_label( $view ) {
		$labels = array(
			'review'   => __( 'Needs review', 'replace-broken-links-ai' ),
			'replaced' => __( 'Replaced', 'replace-broken-links-ai' ),
			'broken'   => __( 'Not replaced yet', 'replace-broken-links-ai' ),
			'watching' => __( 'Waiting to confirm', 'replace-broken-links-ai' ),
			'ignored'  => __( 'Ignored', 'replace-broken-links-ai' ),
		);

		return isset( $labels[ $view ] ) ? $labels[ $view ] : $view;
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'link'        => __( 'Broken link', 'replace-broken-links-ai' ),
			'replacement' => __( 'Replacement', 'replace-broken-links-ai' ),
			'used_in'     => __( 'Used in', 'replace-broken-links-ai' ),
			'date'        => __( 'Updated', 'replace-broken-links-ai' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array( 'date' => array( 'date', true ) );
	}

	/**
	 * Bulk actions for this view.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		$labels = array(
			'approve' => __( 'Approve', 'replace-broken-links-ai' ),
			'retry'   => __( 'Look again', 'replace-broken-links-ai' ),
			'ignore'  => __( 'Ignore', 'replace-broken-links-ai' ),
			'undo'    => __( 'Undo', 'replace-broken-links-ai' ),
			'restore' => __( 'Restore', 'replace-broken-links-ai' ),
		);

		return array_intersect_key( $labels, array_flip( self::BULK[ $this->view ] ) );
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$order  = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'asc' : 'desc';
		// phpcs:enable

		$result = Replace_Broken_Links_AI_DB::query_links( self::VIEWS[ $this->view ], $search, self::PER_PAGE, $this->get_pagenum(), $order );

		$this->items           = $result['rows'];
		$this->uses            = Replace_Broken_Links_AI_DB::uses_for_links( wp_list_pluck( $this->items, 'id' ) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'link' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message when the list is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		$messages = array(
			'review'   => array( 'circle-check', __( 'Nothing to review. Replacements the AI wasn\'t sure enough about (or all of them, if you approve every change) wait here.', 'replace-broken-links-ai' ) ),
			'replaced' => array( 'history', __( 'No links have been replaced yet. Every change is listed here and can be undone.', 'replace-broken-links-ai' ) ),
			'broken'   => array( 'link-2', __( 'No dead links are waiting. Links confirmed dead are looked up here, and the ones without a good match stay here for you.', 'replace-broken-links-ai' ) ),
			'watching' => array( 'hourglass', __( 'No links are failing right now. A link that returns 404 or 410 waits here until it has failed for your whole grace period.', 'replace-broken-links-ai' ) ),
			'ignored'  => array( 'eye-off', __( 'No ignored links. Links you ignore or undo are listed here and left alone.', 'replace-broken-links-ai' ) ),
		);

		$message = $messages[ $this->view ];

		echo '<span class="rblai-empty">' . Replace_Broken_Links_AI_Icons::get( $message[0], 18 ) . esc_html( $message[1] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="rblai-cb-%1$d">%2$s</label><input type="checkbox" id="rblai-cb-%1$d" name="ids[]" value="%1$d">',
			(int) $item['id'],
			esc_html__( 'Select link', 'replace-broken-links-ai' )
		);
	}

	/**
	 * Broken link column: why it's dead, the address, its link text and the actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_link( $item ) {
		$domain = Replace_Broken_Links_AI_DB::KIND_DOMAIN === $item['kind'];
		$code   = (int) $item['code'];

		if ( $domain ) {
			$badge = '<span class="rblai-badge is-domain">' . Replace_Broken_Links_AI_Icons::get( 'globe-lock', 13 ) . esc_html__( 'Domain changed hands', 'replace-broken-links-ai' ) . '</span>';
		} else {
			$badge = '<span class="rblai-badge is-gone">' . Replace_Broken_Links_AI_Icons::get( 'link-2-off', 13 ) . esc_html( 410 === $code ? '410 Gone' : '404 Not Found' ) . '</span>';
		}

		if ( Replace_Broken_Links_AI_Checker::is_internal( $item['url'] ) ) {
			$badge .= '<span class="rblai-badge">' . Replace_Broken_Links_AI_Icons::get( 'house', 13 ) . esc_html__( 'Your site', 'replace-broken-links-ai' ) . '</span>';
		}

		$out  = '<div class="rblai-meta">' . $badge . '</div>';
		$out .= '<code class="rblai-url rblai-url-old">' . Replace_Broken_Links_AI_Admin::breakable( $item['url'] ) . '</code>';

		if ( $domain && '' !== (string) $item['note'] ) {
			$out .= '<p class="rblai-note">' . Replace_Broken_Links_AI_Icons::get( 'triangle-alert', 14 ) . '<span>' . esc_html( $item['note'] ) . '</span></p>';
		}

		$uses   = isset( $this->uses[ (int) $item['id'] ] ) ? $this->uses[ (int) $item['id'] ] : array();
		$anchor = '';
		foreach ( $uses as $use ) {
			if ( '' !== (string) $use['anchor'] ) {
				$anchor = $use['anchor'];
				break;
			}
		}

		if ( '' !== $anchor ) {
			/* translators: %s: link text. */
			$out .= '<p class="rblai-anchor">' . sprintf( esc_html__( 'Link text: %s', 'replace-broken-links-ai' ), '<strong>' . esc_html( Replace_Broken_Links_AI_Content::cut( $anchor, 90 ) ) . '</strong>' ) . '</p>';
		}

		if ( (int) $item['found'] ) {
			/* translators: %s: time ago, e.g. "3 days". */
			$out .= '<p class="rblai-muted">' . esc_html( sprintf( __( 'Failing since %s ago', 'replace-broken-links-ai' ), human_time_diff( (int) $item['found'] ) ) ) . '</p>';
		}

		return $out . $this->row_actions( $this->actions( $item, $uses ) );
	}

	/**
	 * Row actions for a row.
	 *
	 * @param array   $item Row.
	 * @param array[] $uses Its uses.
	 * @return array<string,string>
	 */
	private function actions( $item, array $uses ) {
		$id      = (int) $item['id'];
		$actions = array();

		$link = function ( $action, $label, $icon, $class = '' ) use ( $id ) {
			$url = wp_nonce_url(
				Replace_Broken_Links_AI_Admin::url(
					'links',
					array(
						'view'         => $this->view,
						'rblai_action' => $action,
						'id'           => $id,
					)
				),
				'replace_broken_links_ai_' . $action . '_' . $id
			);

			return sprintf( '<a href="%1$s" class="rblai-action %2$s">%3$s%4$s</a>', esc_url( $url ), esc_attr( $class ), Replace_Broken_Links_AI_Icons::get( $icon, 14 ), esc_html( $label ) );
		};

		// Keys become the classes of the action's <span>, so they're prefixed:
		// core hides any ".approve" on every admin page (comment moderation).
		switch ( $item['status'] ) {
			case Replace_Broken_Links_AI_DB::REVIEW:
				$actions['rblai-approve'] = $link( 'approve', __( 'Approve', 'replace-broken-links-ai' ), 'check', 'rblai-action-approve' );
				$actions['rblai-find']    = $this->find_button( $id, __( 'Look again', 'replace-broken-links-ai' ) );
				$actions['rblai-ignore']  = $link( 'ignore', __( 'Ignore', 'replace-broken-links-ai' ), 'eye-off' );
				break;
			case Replace_Broken_Links_AI_DB::REPLACED:
				if ( Replace_Broken_Links_AI_Scanner::can_undo( $uses ) ) {
					$actions['rblai-undo'] = $link( 'undo', __( 'Undo', 'replace-broken-links-ai' ), 'undo-2' );
				} elseif ( $uses ) {
					$actions['rblai-revisions'] = sprintf(
						'<a href="%1$s">%2$s%3$s</a>',
						esc_url( $this->revisions_url( (int) $uses[0]['post_id'] ) ),
						Replace_Broken_Links_AI_Icons::get( 'history', 14 ),
						esc_html__( 'Edited since: view revisions', 'replace-broken-links-ai' )
					);
				}
				break;
			case Replace_Broken_Links_AI_DB::WATCHING:
				$actions['rblai-ignore'] = $link( 'ignore', __( 'Ignore', 'replace-broken-links-ai' ), 'eye-off' );
				break;
			case Replace_Broken_Links_AI_DB::IGNORED:
				$actions['rblai-restore'] = $link( 'restore', __( 'Restore', 'replace-broken-links-ai' ), 'rotate-ccw' );
				break;
			default:
				$actions['rblai-find']   = $this->find_button( $id, __( 'Find a replacement now', 'replace-broken-links-ai' ) );
				$actions['rblai-ignore'] = $link( 'ignore', __( 'Ignore', 'replace-broken-links-ai' ), 'eye-off' );
		}

		$actions['rblai-open'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s%3$s</a>',
			esc_url( $item['url'] ),
			Replace_Broken_Links_AI_Icons::get( 'external-link', 14 ),
			esc_html__( 'Open old link', 'replace-broken-links-ai' )
		);

		return $actions;
	}

	/**
	 * The "Find a replacement now" button (runs the lookup over AJAX).
	 *
	 * @param int    $id    Row ID.
	 * @param string $label Label.
	 * @return string
	 */
	private function find_button( $id, $label ) {
		return sprintf(
			'<button type="button" class="button-link rblai-action" data-rblai-find="%1$d">%2$s<span>%3$s</span></button>',
			(int) $id,
			Replace_Broken_Links_AI_Icons::get( 'sparkles', 14 ),
			esc_html( $label )
		);
	}

	/**
	 * The post's latest revision, to compare and restore from, or its edit screen.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function revisions_url( $post_id ) {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		if ( $revisions ) {
			return admin_url( 'revision.php?revision=' . (int) reset( $revisions ) );
		}

		$edit = get_edit_post_link( $post_id, 'raw' );

		return $edit ? $edit : admin_url();
	}

	/**
	 * Replacement column: what was found (or where the link is up to), and
	 * the other results to choose from.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_replacement( $item ) {
		$status = $item['status'];
		$out    = '';

		if ( '' !== (string) $item['target'] && in_array( $status, array( Replace_Broken_Links_AI_DB::REVIEW, Replace_Broken_Links_AI_DB::REPLACED, Replace_Broken_Links_AI_DB::IGNORED ), true ) ) {
			$archived = 0 === strpos( $item['target'], 'https://web.archive.org/web/' );

			$out .= '<div class="rblai-target">' . Replace_Broken_Links_AI_Icons::get( 'arrow-right', 16, 'rblai-target-arrow' ) . '<span>';
			if ( '' !== (string) $item['target_title'] ) {
				$out .= '<strong class="rblai-target-title">' . esc_html( $item['target_title'] ) . '</strong>';
			}
			$out .= '<a class="rblai-url rblai-url-new" href="' . esc_url( $item['target'] ) . '" target="_blank" rel="noopener noreferrer">' . Replace_Broken_Links_AI_Admin::breakable( $item['target'] ) . '</a></span></div>';

			$pills = '';
			if ( '' !== (string) $item['confidence'] && 'manual' !== $item['method'] ) {
				$labels = array(
					'high'   => __( 'High confidence', 'replace-broken-links-ai' ),
					'medium' => __( 'Medium confidence', 'replace-broken-links-ai' ),
					'low'    => __( 'Low confidence', 'replace-broken-links-ai' ),
				);
				$pills .= '<span class="rblai-pill is-' . esc_attr( $item['confidence'] ) . '">' . esc_html( isset( $labels[ $item['confidence'] ] ) ? $labels[ $item['confidence'] ] : $item['confidence'] ) . '</span>';
			}
			if ( $archived ) {
				$pills .= '<span class="rblai-pill is-archive">' . Replace_Broken_Links_AI_Icons::get( 'archive', 12 ) . esc_html__( 'Wayback Machine copy', 'replace-broken-links-ai' ) . '</span>';
			}
			if ( in_array( (int) $item['target_code'], array( 401, 403 ), true ) ) {
				$pills .= '<span class="rblai-pill is-low">' . Replace_Broken_Links_AI_Icons::get( 'shield-alert', 12 ) . esc_html__( 'Couldn\'t check: open it to make sure', 'replace-broken-links-ai' ) . '</span>';
			}
			if ( '' !== $pills ) {
				$out .= '<div class="rblai-pills">' . $pills . '</div>';
			}

			if ( '' !== (string) $item['reason'] && 'manual' !== $item['method'] ) {
				$out .= '<p class="rblai-reason">' . Replace_Broken_Links_AI_Icons::get( 'sparkles', 14 ) . '<span>' . esc_html( $item['reason'] ) . '</span></p>';
			}
		} else {
			$out .= $this->status_text( $item );
		}

		if ( in_array( $status, array( Replace_Broken_Links_AI_DB::REVIEW, Replace_Broken_Links_AI_DB::NONE, Replace_Broken_Links_AI_DB::FAILED, Replace_Broken_Links_AI_DB::DEAD ), true ) ) {
			$out .= $this->choices( $item );
		}

		return $out;
	}

	/**
	 * Where a link without a replacement is up to.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	private function status_text( $item ) {
		switch ( $item['status'] ) {
			case Replace_Broken_Links_AI_DB::WATCHING:
				$grace = $this->plugin->grace_seconds();
				$due   = (int) $item['found'] + $grace;
				$text  = $due > time()
					/* translators: %s: time left, e.g. "5 days". */
					? sprintf( __( 'Still failing. If it\'s still down in %s, it counts as dead.', 'replace-broken-links-ai' ), human_time_diff( $due ) )
					: __( 'Still failing. One more check confirms it.', 'replace-broken-links-ai' );
				return '<p class="rblai-status">' . Replace_Broken_Links_AI_Icons::get( 'hourglass', 15 ) . '<span>' . esc_html( $text ) . '</span></p>';

			case Replace_Broken_Links_AI_DB::DEAD:
				$scanner = $this->plugin->scanner();
				if ( ! $this->plugin->ready() ) {
					$text = __( 'Dead. Connect an AI and Serper.dev to look up a replacement.', 'replace-broken-links-ai' );
				} elseif ( '' !== Replace_Broken_Links_AI_Plugin::paused() ) {
					$text = __( 'Dead. Lookups are paused: see the Overview tab.', 'replace-broken-links-ai' );
				} elseif ( ! $scanner->under_daily_limit() ) {
					$text = __( 'Dead. Today\'s limit is used up, so it\'s looked up tomorrow.', 'replace-broken-links-ai' );
				} else {
					$text = __( 'Dead. Waiting for a lookup in the background.', 'replace-broken-links-ai' );
				}
				return '<p class="rblai-status">' . Replace_Broken_Links_AI_Icons::get( 'clock', 15 ) . '<span>' . esc_html( $text ) . '</span></p>';

			case Replace_Broken_Links_AI_DB::NONE:
				$out = '<p class="rblai-status is-none">' . Replace_Broken_Links_AI_Icons::get( 'search-x', 15 ) . '<span>' . esc_html__( 'No good replacement found.', 'replace-broken-links-ai' ) . '</span></p>';
				if ( '' !== (string) $item['reason'] ) {
					$out .= '<p class="rblai-reason">' . Replace_Broken_Links_AI_Icons::get( 'sparkles', 14 ) . '<span>' . esc_html( $item['reason'] ) . '</span></p>';
				}
				return $out;

			case Replace_Broken_Links_AI_DB::FAILED:
				$retry = (int) $item['attempts'] < Replace_Broken_Links_AI_DB::MAX_ATTEMPTS;
				return '<p class="rblai-status is-failed">' . Replace_Broken_Links_AI_Icons::get( 'circle-alert', 15 ) . '<span>' . esc_html( $item['error'] ) . ( $retry ? ' ' . esc_html__( 'It will be tried again in a few hours.', 'replace-broken-links-ai' ) : '' ) . '</span></p>';

			case Replace_Broken_Links_AI_DB::IGNORED:
				return '<p class="rblai-status">' . Replace_Broken_Links_AI_Icons::get( 'eye-off', 15 ) . '<span>' . esc_html__( 'Left as it is.', 'replace-broken-links-ai' ) . '</span></p>';
		}

		return '';
	}

	/**
	 * The other search results to pick from, and a box for your own address.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	private function choices( $item ) {
		$candidates = json_decode( (string) $item['candidates'], true );
		$candidates = is_array( $candidates ) ? $candidates : array();
		$archive    = json_decode( (string) $item['archive'], true );
		$id         = (int) $item['id'];
		$count      = count( $candidates );

		$summary = $count
			/* translators: %s: number of search results. */
			? sprintf( _n( 'Choose another (%s result)', 'Choose another (%s results)', $count, 'replace-broken-links-ai' ), number_format_i18n( $count ) )
			: __( 'Use my own link', 'replace-broken-links-ai' );

		$out  = '<details class="rblai-choices"><summary>' . esc_html( $summary ) . '</summary>';
		$out .= '<div class="rblai-choices-body" data-rblai-choices="' . $id . '">';

		if ( '' !== (string) $item['query'] ) {
			/* translators: %s: the search the AI wrote. */
			$out .= '<p class="rblai-muted">' . sprintf( esc_html__( 'Searched for: %s', 'replace-broken-links-ai' ), '<strong>' . esc_html( $item['query'] ) . '</strong>' ) . '</p>';
		}

		if ( is_array( $archive ) && ! empty( $archive['title'] ) ) {
			/* translators: 1: archived page title, 2: date it was saved. */
			$out .= '<p class="rblai-muted">' . Replace_Broken_Links_AI_Icons::get( 'archive', 13 ) . ' ' . sprintf( esc_html__( 'The old page was "%1$s" (Wayback Machine, %2$s).', 'replace-broken-links-ai' ), esc_html( $archive['title'] ), esc_html( $archive['date'] ) ) . '</p>';
		}

		if ( $candidates ) {
			$out .= '<ol class="rblai-results">';
			foreach ( $candidates as $i => $candidate ) {
				$number = $i + 1;
				$chosen = (int) $item['choice'] === $number && '' !== (string) $item['target'];
				$tags   = '';

				if ( ! empty( $candidate['original'] ) ) {
					$tags .= '<span class="rblai-tag">' . esc_html__( 'Original site', 'replace-broken-links-ai' ) . '</span>';
				}
				if ( ! empty( $candidate['preferred'] ) ) {
					$tags .= '<span class="rblai-tag is-preferred">' . esc_html__( 'Preferred', 'replace-broken-links-ai' ) . '</span>';
				}
				if ( $chosen ) {
					$tags .= '<span class="rblai-tag is-chosen">' . esc_html__( 'AI\'s pick', 'replace-broken-links-ai' ) . '</span>';
				}

				$out .= '<li' . ( $chosen ? ' class="is-chosen"' : '' ) . '><div class="rblai-result-text">'
					. '<a href="' . esc_url( $candidate['link'] ) . '" target="_blank" rel="noopener noreferrer"><strong>' . esc_html( '' !== (string) $candidate['title'] ? $candidate['title'] : $candidate['link'] ) . '</strong></a>' . $tags
					. '<span class="rblai-result-url">' . Replace_Broken_Links_AI_Admin::breakable( $candidate['link'] ) . '</span>'
					. ( '' !== (string) $candidate['snippet'] ? '<span class="rblai-result-snippet">' . esc_html( $candidate['snippet'] ) . '</span>' : '' )
					. '</div>';

				if ( ! $chosen ) {
					$out .= '<button type="button" class="button button-small" data-rblai-choose="' . $id . '" data-number="' . (int) $number . '">' . esc_html__( 'Use this', 'replace-broken-links-ai' ) . '</button>';
				}

				$out .= '</li>';
			}
			$out .= '</ol>';
		}

		$out .= '<div class="rblai-own">'
			. '<label class="screen-reader-text" for="rblai-own-' . $id . '">' . esc_html__( 'Your own replacement link', 'replace-broken-links-ai' ) . '</label>'
			. '<input type="url" id="rblai-own-' . $id . '" class="regular-text" placeholder="https://" data-rblai-own="' . $id . '">'
			. '<button type="button" class="button button-small" data-rblai-choose="' . $id . '" data-number="0">' . esc_html__( 'Use my link', 'replace-broken-links-ai' ) . '</button>'
			. '</div>';

		return $out . '<p class="rblai-choice-result" data-rblai-choice-result="' . $id . '" aria-live="polite"></p></div></details>';
	}

	/**
	 * Used in column: the posts with the link.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_used_in( $item ) {
		$uses = isset( $this->uses[ (int) $item['id'] ] ) ? $this->uses[ (int) $item['id'] ] : array();

		if ( ! $uses ) {
			return '<span class="rblai-muted">&ndash;</span>';
		}

		$posts = array();
		foreach ( $uses as $use ) {
			$posts[ (int) $use['post_id'] ] = $use;
		}

		$out = '<ul class="rblai-posts">';
		foreach ( array_slice( $posts, 0, 3, true ) as $post_id => $use ) {
			if ( empty( $use['post_type'] ) ) {
				$out .= '<li class="rblai-muted">' . esc_html__( '(deleted)', 'replace-broken-links-ai' ) . '</li>';
				continue;
			}

			$title = '' !== (string) $use['post_title'] ? $use['post_title'] : __( '(no title)', 'replace-broken-links-ai' );
			$edit  = get_edit_post_link( $post_id );
			$out  .= '<li>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</li>';
		}

		if ( count( $posts ) > 3 ) {
			/* translators: %s: number of other posts. */
			$out .= '<li class="rblai-muted">' . esc_html( sprintf( __( 'and %s more', 'replace-broken-links-ai' ), number_format_i18n( count( $posts ) - 3 ) ) ) . '</li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Date column: when, and how.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_date( $item ) {
		$time = (int) $item['updated'];

		$out = sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			/* translators: %s: time ago, e.g. "2 hours". */
			esc_html( sprintf( __( '%s ago', 'replace-broken-links-ai' ), human_time_diff( $time ) ) )
		);

		$labels = array(
			'auto'     => array( 'sparkles', __( 'Automatically', 'replace-broken-links-ai' ) ),
			'approved' => array( 'check', __( 'Approved', 'replace-broken-links-ai' ) ),
			'manual'   => array( 'mouse-pointer-click', __( 'Chosen', 'replace-broken-links-ai' ) ),
			'ignored'  => array( 'eye-off', __( 'Ignored', 'replace-broken-links-ai' ) ),
			'undone'   => array( 'undo-2', __( 'Undone', 'replace-broken-links-ai' ) ),
		);

		$method = (string) $item['method'];

		if ( in_array( $item['status'], array( Replace_Broken_Links_AI_DB::REPLACED, Replace_Broken_Links_AI_DB::IGNORED ), true ) && isset( $labels[ $method ] ) ) {
			$label = $labels[ $method ][1];
			$user  = (int) $item['user_id'] ? get_userdata( (int) $item['user_id'] ) : false;

			if ( $user ) {
				/* translators: 1: "Approved", "Undone" or "Ignored", 2: user's display name. */
				$label = sprintf( __( '%1$s by %2$s', 'replace-broken-links-ai' ), $label, $user->display_name );
			}

			$out .= '<span class="rblai-method">' . Replace_Broken_Links_AI_Icons::get( $labels[ $method ][0], 13 ) . esc_html( $label ) . '</span>';
		}

		return $out;
	}

	/**
	 * Fallback for columns without their own method.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}
