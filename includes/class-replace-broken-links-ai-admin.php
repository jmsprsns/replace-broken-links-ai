<?php
/**
 * Admin side: the Content Powered > Replace Broken Links page and the
 * Plugins-screen link.
 *
 * Built from WordPress's own admin pieces: a Site Health style header with
 * tabs, the post editor's two-column box layout (#poststuff, .postbox), a
 * list table and the admin accent colour. Everything needs manage_options
 * and a nonce; changing a post also needs edit_post for that post.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller.
 */
final class Replace_Broken_Links_AI_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'replace-broken-links-ai';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'link-2';

	/**
	 * Capability needed for everything on the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Tabs: id => Lucide icon.
	 */
	const TABS = array(
		'overview'    => 'layout-dashboard',
		'links'       => 'list-checks',
		'settings'    => 'settings-2',
		'connections' => 'plug',
		'advanced'    => 'sliders-horizontal',
	);

	/**
	 * Tabs that are a settings form.
	 */
	const FORM_TABS = array( 'settings', 'connections', 'advanced' );

	/**
	 * Actions a row or a bulk selection can take.
	 */
	const ACTIONS = array( 'approve', 'ignore', 'undo', 'restore', 'retry' );

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'replace_broken_links_ai_admin';

	/**
	 * Plugin instance.
	 *
	 * @var Replace_Broken_Links_AI_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * The list on the Links tab.
	 *
	 * @var Replace_Broken_Links_AI_List_Table|null
	 */
	private $table = null;

	/**
	 * Constructor.
	 *
	 * @param Replace_Broken_Links_AI_Plugin $plugin Plugin instance.
	 */
	public function __construct( Replace_Broken_Links_AI_Plugin $plugin ) {
		$this->plugin = $plugin;

		Replace_Broken_Links_AI_Menu::init();
		Replace_Broken_Links_AI_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		foreach ( array( 'scan', 'test_ai', 'test_search', 'find', 'choose', 'try', 'resume' ) as $action ) {
			add_action( 'wp_ajax_replace_broken_links_ai_' . $action, array( $this, 'ajax_' . $action ) );
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( REPLACE_BROKEN_LINKS_AI_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url( 'settings' ) ),
			esc_html__( 'Settings', 'replace-broken-links-ai' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * URL of a tab on the page.
	 *
	 * @param string $tab  Tab ID.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $tab = 'overview', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Replace_Broken_Links_AI_Icons::get( self::ICON, 17, 'replace-broken-links-ai-menu-icon' );
		$counts     = Replace_Broken_Links_AI_DB::counts();
		$review     = (int) $counts[ Replace_Broken_Links_AI_DB::REVIEW ];
		$badge      = $review ? ' <span class="awaiting-mod rblai-menu-count">' . esc_html( number_format_i18n( $review ) ) . '</span>' : '';
		$menu_title = $icon . '<span class="replace-broken-links-ai-menu-label">' . esc_html__( 'Replace Broken Links', 'replace-broken-links-ai' ) . $badge . '</span>';

		$this->hook_suffix = add_submenu_page(
			Replace_Broken_Links_AI_Menu::SLUG,
			__( 'Replace Broken Links With AI', 'replace-broken-links-ai' ),
			$menu_title,
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'load_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
			add_filter( 'screen_options_show_screen', array( $this, 'hide_screen_options' ), 10, 2 );
		}
	}

	/**
	 * No Screen Options tab: it would sit on top of the page header.
	 *
	 * @param bool      $show   Whether to show the tab.
	 * @param WP_Screen $screen Current screen.
	 * @return bool
	 */
	public function hide_screen_options( $show, $screen ) {
		return $screen instanceof WP_Screen && $screen->id === $this->hook_suffix ? false : $show;
	}

	/**
	 * Mark the page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' rblai-page' : $classes;
	}

	/**
	 * The tab being viewed.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'overview';
	}

	/**
	 * The view being shown on the Links tab.
	 *
	 * @return string
	 */
	private function current_view() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		if ( isset( Replace_Broken_Links_AI_List_Table::VIEWS[ $view ] ) ) {
			return $view;
		}

		// Open on whatever needs attention first.
		$counts = Replace_Broken_Links_AI_DB::counts();

		if ( $counts[ Replace_Broken_Links_AI_DB::REVIEW ] ) {
			return 'review';
		}

		return $counts[ Replace_Broken_Links_AI_DB::REPLACED ] ? 'replaced' : 'broken';
	}

	// Actions -----------------------------------------------------------------

	/**
	 * Before the page is drawn: handle actions, then set up the list.
	 *
	 * @return void
	 */
	public function load_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'replace-broken-links-ai' ), 403 );
		}

		$this->handle_scan_request();

		if ( 'links' !== $this->current_tab() ) {
			return;
		}

		require_once REPLACE_BROKEN_LINKS_AI_DIR . 'includes/class-replace-broken-links-ai-list-table.php';
		$this->table = new Replace_Broken_Links_AI_List_Table( $this->current_view(), $this->plugin );

		$this->handle_row_action();
		$this->handle_bulk_action();

		// Like core's list screens: drop the nonce and referer a plain search
		// or page change leaves in the address bar.
		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
			exit;
		}

		$this->table->prepare_items();
	}

	/**
	 * "Scan now" without JavaScript: start a scan and let WP-Cron run it.
	 *
	 * @return void
	 */
	private function handle_scan_request() {
		if ( empty( $_POST['rblai_scan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
			return;
		}

		check_admin_referer( 'replace_broken_links_ai_scan' );

		Replace_Broken_Links_AI_Scanner::start();

		$this->redirect( $this->current_tab(), array( 'rblai_notice' => 'scan' ) );
	}

	/**
	 * A row action link.
	 *
	 * @return void
	 */
	private function handle_row_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked below.
		if ( empty( $_GET['rblai_action'] ) || empty( $_GET['id'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['rblai_action'] ) );
		$id     = absint( $_GET['id'] );
		// phpcs:enable

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return;
		}

		check_admin_referer( 'replace_broken_links_ai_' . $action . '_' . $id );

		$this->perform( $action, array( $id ) );
	}

	/**
	 * A bulk action from the list.
	 *
	 * @return void
	 */
	private function handle_bulk_action() {
		$action = $this->table->current_action();

		if ( ! $action || ! in_array( $action, Replace_Broken_Links_AI_List_Table::BULK[ $this->current_view() ], true ) ) {
			return;
		}

		check_admin_referer( 'bulk-broken-links' );

		$ids = isset( $_REQUEST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) ) : array();

		if ( ! $ids ) {
			$this->redirect( 'links', array( 'view' => $this->current_view() ) );
		}

		$this->perform( $action, array_slice( $ids, 0, 100 ) );
	}

	/**
	 * Run an action on some rows, then go back to the list with a notice.
	 *
	 * @param string $action An entry of ACTIONS.
	 * @param int[]  $ids    Row IDs.
	 * @return void
	 */
	private function perform( $action, array $ids ) {
		$scanner = $this->plugin->scanner();
		$user_id = get_current_user_id();
		$counts  = array(
			'done'      => 0,
			'gone'      => 0,
			'edited'    => 0,
			'failed'    => 0,
			'waiting'   => 0,
			'recovered' => 0,
		);

		switch ( $action ) {
			case 'approve':
				$counts = array_merge( $counts, $scanner->approve( $ids, $user_id ) );
				break;
			case 'undo':
				$counts = array_merge( $counts, $scanner->undo( $ids, $user_id ) );
				break;
			case 'ignore':
				$counts['done'] = $scanner->ignore( $ids, $user_id );
				break;
			case 'restore':
				$counts['done'] = $scanner->restore( $ids );
				break;
			case 'retry':
				$counts['done'] = $scanner->retry( $ids );
				break;
		}

		$args = array(
			'view'         => $this->current_view(),
			'rblai_notice' => $action,
		);
		foreach ( $counts as $key => $value ) {
			$args[ 'rblai_' . $key ] = (int) $value;
		}

		$this->redirect( 'links', $args );
	}

	/**
	 * Redirect back to a tab and stop.
	 *
	 * @param string $tab  Tab ID.
	 * @param array  $args Query arguments.
	 * @return void
	 */
	private function redirect( $tab, array $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_filter( $args ) ) );
		exit;
	}

	// AJAX --------------------------------------------------------------------

	/**
	 * Check an AJAX request's nonce and the user's capability.
	 *
	 * @return void Sends a 403 and stops if either fails.
	 */
	private function check_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'replace-broken-links-ai' ) ), 403 );
		}
	}

	/**
	 * A POSTed text field.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted( $key ) {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check the nonce first.
	}

	/**
	 * AJAX: start the scan and/or run one short batch, for "Scan now".
	 *
	 * @return void
	 */
	public function ajax_scan() {
		$this->check_ajax();

		// Starting answers straight away, so the progress bar appears at once;
		// the batches follow in the next requests.
		if ( 'start' === self::posted( 'step' ) ) {
			$state = Replace_Broken_Links_AI_Scanner::start();
		} else {
			$state = $this->plugin->scanner()->run( 3, false );
		}

		wp_send_json_success(
			array(
				'running' => 'running' === $state['status'],
				'percent' => $this->percent( $state ),
				'status'  => $this->scan_status_text( $state ),
			)
		);
	}

	/**
	 * AJAX: test an AI key (the one typed in, or the saved one): list the
	 * account's models, then ask the chosen model a one-word question.
	 *
	 * @return void
	 */
	public function ajax_test_ai() {
		$this->check_ajax();

		$provider = 'anthropic' === self::posted( 'provider' ) ? 'anthropic' : 'openai';
		$typed    = self::posted( 'key' );
		$key      = '' !== $typed ? $typed : $this->plugin->ai_key( $provider );
		$model    = self::posted( 'model' );
		$model    = Replace_Broken_Links_AI_AI::valid_model( $model ) ? $model : $this->plugin->model();
		$name     = Replace_Broken_Links_AI_AI::provider_name( $provider );

		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Paste an API key first.', 'replace-broken-links-ai' ) ) );
		}

		$models = Replace_Broken_Links_AI_AI::models( $provider, $key );

		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}

		update_option( 'replace_broken_links_ai_models_' . $provider, $models, false );

		$started = microtime( true );
		$answer  = Replace_Broken_Links_AI_AI::complete( $provider, $key, $model, 'Answer with one word.', 'Reply with the word OK.', 'low' );

		if ( is_wp_error( $answer ) ) {
			wp_send_json_error(
				array(
					'message' => $answer->get_error_message(),
					'models'  => $models,
				)
			);
		}

		Replace_Broken_Links_AI_Usage::add( array( 'cost' => Replace_Broken_Links_AI_AI::cost_micros( $model, $answer['input_tokens'], $answer['output_tokens'] ) ) );

		if ( '' === $typed ) {
			Replace_Broken_Links_AI_Plugin::resume();
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: provider name, 2: model ID, 3: seconds, 4: number of models. */
					__( 'Connected to %1$s. %2$s answered in %3$s seconds. Your account has %4$s models to choose from.', 'replace-broken-links-ai' ),
					$name,
					$model,
					number_format_i18n( microtime( true ) - $started, 1 ),
					number_format_i18n( count( $models ) )
				) . ( '' !== $typed ? ' ' . __( 'Save to keep this key.', 'replace-broken-links-ai' ) : '' ),
				'models'  => $models,
			)
		);
	}

	/**
	 * AJAX: test a Serper.dev key with one search.
	 *
	 * @return void
	 */
	public function ajax_test_search() {
		$this->check_ajax();

		$typed  = self::posted( 'key' );
		$key    = '' !== $typed ? $typed : $this->plugin->serper_key();
		$locale = $this->plugin->search_locale();

		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Paste a Serper.dev API key first.', 'replace-broken-links-ai' ) ) );
		}

		$found = Replace_Broken_Links_AI_Serper::search( $key, 'WordPress', $locale['gl'], $locale['hl'] );

		if ( is_wp_error( $found ) ) {
			wp_send_json_error( array( 'message' => $found->get_error_message() ) );
		}

		Replace_Broken_Links_AI_Usage::add( array( 'cost' => Replace_Broken_Links_AI_Serper::cost_micros() ) );

		if ( '' === $typed ) {
			Replace_Broken_Links_AI_Plugin::resume();
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: number of results. */
					__( 'Connected to Serper.dev. A test search returned %s Google results (it used one search credit).', 'replace-broken-links-ai' ),
					number_format_i18n( count( $found['results'] ) )
				) . ( '' !== $typed ? ' ' . __( 'Save to keep this key.', 'replace-broken-links-ai' ) : '' ),
			)
		);
	}

	/**
	 * AJAX: find a replacement for one link right now.
	 *
	 * @return void
	 */
	public function ajax_find() {
		$this->check_ajax();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on every host.
		}

		$result = $this->plugin->scanner()->lookup_now( absint( self::posted( 'id' ) ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$link = Replace_Broken_Links_AI_DB::get_link( absint( self::posted( 'id' ) ) );

		if ( $link && Replace_Broken_Links_AI_DB::REPLACED === $link['status'] ) {
			$message = __( 'Found a replacement and swapped it in.', 'replace-broken-links-ai' );
		} elseif ( 'found' === $result['status'] ) {
			$message = __( 'Found a replacement. It\'s waiting in Needs review.', 'replace-broken-links-ai' );
		} else {
			$message = __( 'No good replacement was found.', 'replace-broken-links-ai' );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * AJAX: use another search result, or an address typed in, as the replacement.
	 *
	 * @return void
	 */
	public function ajax_choose() {
		$this->check_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
		$url    = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
		$result = $this->plugin->scanner()->choose( absint( self::posted( 'id' ) ), absint( self::posted( 'number' ) ), $url, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( ! $result['done'] && ! $result['waiting'] ) {
			wp_send_json_error( array( 'message' => __( 'The link couldn\'t be changed. Check that you can edit the posts it\'s in.', 'replace-broken-links-ai' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Link replaced.', 'replace-broken-links-ai' ) ) );
	}

	/**
	 * AJAX: try a lookup on any link, without changing anything (Advanced tab).
	 *
	 * @return void
	 */
	public function ajax_try() {
		$this->check_ajax();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on every host.
		}

		$url     = Replace_Broken_Links_AI_Checker::strip_fragment( self::posted( 'url' ) );
		$anchor  = Replace_Broken_Links_AI_Content::cut( self::posted( 'anchor' ), 200 );
		$context = Replace_Broken_Links_AI_Content::cut( self::posted( 'context' ), 600 );

		if ( ! Replace_Broken_Links_AI_Checker::is_http_url( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter the broken link as a full address, starting with https://.', 'replace-broken-links-ai' ) ) );
		}

		if ( ! $this->plugin->ready() ) {
			wp_send_json_error( array( 'message' => __( 'Connect an AI and Serper.dev under Connections first.', 'replace-broken-links-ai' ) ) );
		}

		$facts  = Replace_Broken_Links_AI_Finder::facts(
			$url,
			array(
				array(
					'post_title' => __( 'Test article', 'replace-broken-links-ai' ),
					'anchor'     => $anchor,
					'context'    => '' !== $context ? $context : '[' . $anchor . ']',
				),
			)
		);
		$result = $this->plugin->scanner()->finder()->run( $url, $facts );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		ob_start();
		$this->render_try_result( $result );

		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * AJAX: carry on with lookups after fixing whatever paused them.
	 *
	 * @return void
	 */
	public function ajax_resume() {
		$this->check_ajax();

		Replace_Broken_Links_AI_Plugin::resume();
		Replace_Broken_Links_AI_Scanner::schedule_batch( 0 );

		wp_send_json_success( array( 'message' => __( 'Lookups resumed.', 'replace-broken-links-ai' ) ) );
	}

	// Settings ----------------------------------------------------------------

	/**
	 * Register the setting. Each settings tab saves its own fields and keeps
	 * the rest.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Replace_Broken_Links_AI_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Replace_Broken_Links_AI_Plugin::defaults(),
			)
		);
	}

	/**
	 * Fields saved by each settings tab. Checkboxes missing from a tab's form
	 * mean "off" only for that tab's own fields.
	 *
	 * @return array<string,string[]>
	 */
	private static function section_fields() {
		return array(
			'settings'    => array( 'grace', 'domains', 'post_types', 'internal', 'rescan', 'skip_hosts', 'wayback', 'search_original', 'prefer_hosts', 'block_hosts', 'mode', 'min_confidence', 'archive_fallback', 'daily_limit', 'daily_budget' ),
			'connections' => array( 'provider', 'openai_model', 'anthropic_model', 'country', 'language' ),
			'advanced'    => array( 'openai_query_model', 'anthropic_query_model', 'query_prompt', 'pick_prompt' ),
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * Runs on a tab's form (with "_section" set: only that tab's fields
	 * change), and again on a complete settings array (WordPress sanitizes
	 * twice when the option is first added), which is validated as a whole.
	 *
	 * @param mixed $input Raw form values (already unslashed by options.php).
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$saved   = get_option( Replace_Broken_Links_AI_Plugin::OPTION, array() );
		$current = wp_parse_args( is_array( $saved ) ? $saved : array(), Replace_Broken_Links_AI_Plugin::defaults() );
		$section = isset( $input['_section'] ) && is_string( $input['_section'] ) ? $input['_section'] : '';
		$fields  = self::section_fields();

		if ( ! isset( $fields[ $section ] ) ) {
			return $this->validate( wp_parse_args( $input, $current ), false );
		}

		$merged = $current;

		foreach ( $fields[ $section ] as $key ) {
			$merged[ $key ] = isset( $input[ $key ] ) ? $input[ $key ] : ( is_array( $current[ $key ] ) ? array() : 0 );
		}

		// Models picked as "Other model...": use the typed ID.
		foreach ( array( 'openai_model', 'anthropic_model', 'openai_query_model', 'anthropic_query_model' ) as $key ) {
			if ( in_array( $key, $fields[ $section ], true ) && '__custom' === $merged[ $key ] ) {
				$merged[ $key ] = isset( $input[ $key . '_custom' ] ) && is_string( $input[ $key . '_custom' ] ) ? trim( $input[ $key . '_custom' ] ) : '';
			}
		}

		if ( 'connections' === $section ) {
			foreach ( array( 'openai', 'anthropic', 'serper' ) as $name ) {
				$new = isset( $input[ $name . '_key_new' ] ) && is_string( $input[ $name . '_key_new' ] ) ? trim( $input[ $name . '_key_new' ] ) : '';

				if ( '' !== $new ) {
					if ( preg_match( '/^[\x21-\x7e]{8,300}$/', $new ) ) {
						$merged[ $name . '_key' ] = Replace_Broken_Links_AI_Crypto::encrypt( $new );
						delete_option( 'replace_broken_links_ai_models_' . $name );
					} else {
						add_settings_error( self::PAGE, 'rblai-key-' . $name, __( 'That API key has spaces or characters an API key can\'t have, so it wasn\'t saved.', 'replace-broken-links-ai' ), 'warning' );
					}
				} elseif ( ! empty( $input[ $name . '_key_clear' ] ) ) {
					$merged[ $name . '_key' ] = '';
				}
			}

			// New keys or models may fix whatever paused lookups.
			Replace_Broken_Links_AI_Plugin::resume();
			Replace_Broken_Links_AI_Scanner::schedule_batch( 0 );
		}

		return $this->validate( $merged, true );
	}

	/**
	 * Validate a complete settings array.
	 *
	 * @param array $in     Settings.
	 * @param bool  $notify Report problems with the prompts on the page.
	 * @return array<string,mixed>
	 */
	private function validate( array $in, $notify ) {
		$defaults = Replace_Broken_Links_AI_Plugin::defaults();
		$flag     = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};
		$choice   = function ( $key, array $allowed ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
			return in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
		};
		$hosts    = function ( $key, $callback ) use ( $in ) {
			$lines = preg_split( '/[\s,]+/', isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '' );
			return implode( "\n", array_slice( array_values( array_unique( array_filter( array_map( $callback, (array) $lines ) ) ) ), 0, 200 ) );
		};
		$model    = function ( $key ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? trim( $in[ $key ] ) : '';
			return Replace_Broken_Links_AI_AI::valid_model( $value ) ? $value : $defaults[ $key ];
		};
		$secret   = function ( $key ) use ( $in ) {
			$value = isset( $in[ $key ] ) ? $in[ $key ] : '';
			// Only our own encrypted values, and only ones this site can read.
			return Replace_Broken_Links_AI_Crypto::is_encrypted( $value ) && '' !== Replace_Broken_Links_AI_Crypto::decrypt( $value ) ? $value : '';
		};

		$types  = isset( $in['post_types'] ) && is_array( $in['post_types'] ) ? array_map( 'sanitize_key', array_filter( $in['post_types'], 'is_string' ) ) : array();
		$budget = isset( $in['daily_budget'] ) && is_numeric( $in['daily_budget'] ) ? (float) $in['daily_budget'] : (float) $defaults['daily_budget'];

		$out = array(
			'grace'                 => $choice( 'grace', array_keys( Replace_Broken_Links_AI_Plugin::grace_choices() ) ),
			'domains'               => $flag( 'domains' ),
			'post_types'            => array_values( array_intersect( array_keys( self::scannable_post_types() ), $types ) ),
			'internal'              => $flag( 'internal' ),
			'rescan'                => $choice( 'rescan', array_keys( Replace_Broken_Links_AI_Plugin::rescan_choices() ) ),
			'skip_hosts'            => $hosts( 'skip_hosts', array( 'Replace_Broken_Links_AI_Plugin', 'clean_host' ) ),
			'wayback'               => $flag( 'wayback' ),
			'search_original'       => $flag( 'search_original' ),
			'prefer_hosts'          => $hosts( 'prefer_hosts', array( 'Replace_Broken_Links_AI_Plugin', 'clean_entry' ) ),
			'block_hosts'           => $hosts( 'block_hosts', array( 'Replace_Broken_Links_AI_Plugin', 'clean_host' ) ),
			'mode'                  => $choice( 'mode', array( 'auto', 'review' ) ),
			'min_confidence'        => $choice( 'min_confidence', array( 'high', 'medium' ) ),
			'archive_fallback'      => $choice( 'archive_fallback', array( 'off', 'review', 'auto' ) ),
			'daily_limit'           => isset( $in['daily_limit'] ) && is_numeric( $in['daily_limit'] ) ? max( 0, min( 5000, (int) $in['daily_limit'] ) ) : (int) $defaults['daily_limit'],
			'daily_budget'          => number_format( max( 0, min( 10000, $budget ) ), 2, '.', '' ),
			'provider'              => $choice( 'provider', array_keys( Replace_Broken_Links_AI_Plugin::PROVIDERS ) ),
			'openai_key'            => $secret( 'openai_key' ),
			'anthropic_key'         => $secret( 'anthropic_key' ),
			'serper_key'            => $secret( 'serper_key' ),
			'openai_model'          => $model( 'openai_model' ),
			'anthropic_model'       => $model( 'anthropic_model' ),
			'country'               => $choice( 'country', array_merge( array( '', 'any' ), array_keys( Replace_Broken_Links_AI_Prompts::countries() ) ) ),
			'language'              => $choice( 'language', array_merge( array( '' ), array_keys( Replace_Broken_Links_AI_Prompts::languages() ) ) ),
			'openai_query_model'    => $model( 'openai_query_model' ),
			'anthropic_query_model' => $model( 'anthropic_query_model' ),
			'query_prompt'          => '',
			'pick_prompt'           => '',
		);

		foreach ( array( 'query', 'pick' ) as $step ) {
			$key    = $step . '_prompt';
			$value  = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? trim( sanitize_textarea_field( $in[ $key ] ) ) : '';
			$value  = substr( str_replace( "\r\n", "\n", $value ), 0, Replace_Broken_Links_AI_Prompts::MAX_LENGTH );
			$normal = 'query' === $step ? Replace_Broken_Links_AI_Prompts::default_query() : Replace_Broken_Links_AI_Prompts::default_pick();

			if ( '' === $value || $value === $normal ) {
				continue; // The default.
			}

			$problem = Replace_Broken_Links_AI_Prompts::problem( $value, $step );

			if ( '' === $problem ) {
				$out[ $key ] = $value;
			} elseif ( $notify ) {
				add_settings_error( self::PAGE, 'rblai-' . $key, $problem . ' ' . __( 'The previous prompt was kept.', 'replace-broken-links-ai' ), 'error' );
				$out[ $key ] = (string) $this->plugin->get_setting( $key );
			}
		}

		return $out;
	}

	/**
	 * Public content types that can be scanned: name => label.
	 *
	 * @return array<string,string>
	 */
	private static function scannable_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && post_type_supports( $type->name, 'editor' ) ) {
				$types[ $type->name ] = $type->labels->name;
			}
		}

		return $types;
	}

	// Assets ------------------------------------------------------------------

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="replace-broken-links-ai-menu-icon-style">'
			. '#adminmenu a:has(>.replace-broken-links-ai-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .replace-broken-links-ai-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .replace-broken-links-ai-menu-icon,#adminmenu a:hover .replace-broken-links-ai-menu-icon,#adminmenu a:focus .replace-broken-links-ai-menu-icon{opacity:1}'
			. '#adminmenu .rblai-menu-count{margin-left:4px}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'replace-broken-links-ai-admin', REPLACE_BROKEN_LINKS_AI_URL . 'assets/admin.css', array(), REPLACE_BROKEN_LINKS_AI_VERSION );
		wp_enqueue_script( 'replace-broken-links-ai-admin', REPLACE_BROKEN_LINKS_AI_URL . 'assets/admin.js', array(), REPLACE_BROKEN_LINKS_AI_VERSION, true );

		wp_localize_script(
			'replace-broken-links-ai-admin',
			'replace_broken_links_ai_admin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'defaults' => array(
					'query' => Replace_Broken_Links_AI_Prompts::default_query(),
					'pick'  => Replace_Broken_Links_AI_Prompts::default_pick(),
				),
				'i18n'     => array(
					'scanError' => __( 'The scan stopped responding. It will carry on in the background; reload the page to check on it.', 'replace-broken-links-ai' ),
					'testing'   => __( 'Testing...', 'replace-broken-links-ai' ),
					'finding'   => __( 'Looking up a replacement. This can take a minute...', 'replace-broken-links-ai' ),
					'saving'    => __( 'Replacing the link...', 'replace-broken-links-ai' ),
					'running'   => __( 'Running the lookup. This can take a minute...', 'replace-broken-links-ai' ),
					'failed'    => __( 'The request failed. Check your connection and try again.', 'replace-broken-links-ai' ),
					'reset'     => __( 'Put the default prompt back? Your changes to it will be lost when you save.', 'replace-broken-links-ai' ),
					'needUrl'   => __( 'Enter a full web address, starting with https://.', 'replace-broken-links-ai' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @param string $class Extra class.
	 * @return string
	 */
	private static function icon( $name, $size = 18, $class = '' ) {
		return Replace_Broken_Links_AI_Icons::get( $name, $size, $class );
	}

	// Page --------------------------------------------------------------------

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'overview'    => __( 'Overview', 'replace-broken-links-ai' ),
			'links'       => __( 'Broken Links', 'replace-broken-links-ai' ),
			'settings'    => __( 'Settings', 'replace-broken-links-ai' ),
			'connections' => __( 'Connections', 'replace-broken-links-ai' ),
			'advanced'    => __( 'Advanced', 'replace-broken-links-ai' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * The boxes on each tab: id => tab, icon, title, intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		$ready = $this->plugin->ready();

		$sections = array(
			'setup'   => array( 'overview', 'rocket', __( 'Get started', 'replace-broken-links-ai' ), __( 'Broken links are already being found. To replace them, connect two services; you pay them directly, and only for what\'s used.', 'replace-broken-links-ai' ), 'render_setup' ),
			'stats'   => array( 'overview', 'chart-column', __( 'At a glance', 'replace-broken-links-ai' ), '', 'render_stats' ),
			'how'     => array( 'overview', 'route', __( 'How a broken link gets replaced', 'replace-broken-links-ai' ), '', 'render_how' ),
			'recent'  => array( 'overview', 'history', __( 'Recently replaced', 'replace-broken-links-ai' ), '', 'render_recent' ),
			'usage'   => array( 'overview', 'coins', __( 'API usage', 'replace-broken-links-ai' ), __( 'Estimated from each request\'s token counts and list prices. Your OpenAI, Anthropic and Serper.dev dashboards have the exact figures.', 'replace-broken-links-ai' ), 'render_usage' ),
			'dead'    => array( 'settings', 'hourglass', __( 'When is a link dead?', 'replace-broken-links-ai' ), __( 'Only a page that answers 404 Not Found or 410 Gone counts, twice in a row, to a request that looks like an ordinary browser. Timeouts, server errors, rate limits and "forbidden" never count. It\'s checked again when the wait is over, and once more right before anything is swapped.', 'replace-broken-links-ai' ), 'render_dead' ),
			'scan'    => array( 'settings', 'scan-search', __( 'What to check', 'replace-broken-links-ai' ), '', 'render_scan' ),
			'sources' => array( 'settings', 'search-check', __( 'Where replacements come from', 'replace-broken-links-ai' ), '', 'render_sources' ),
			'replace' => array( 'settings', 'replace', __( 'Replacing links', 'replace-broken-links-ai' ), __( 'Only the link\'s address changes. The link text, rel, target and every other attribute stay exactly as they were, the post\'s "last modified" date doesn\'t move, and each change saves a revision.', 'replace-broken-links-ai' ), 'render_replace' ),
			'limits'  => array( 'settings', 'gauge', __( 'Daily limits', 'replace-broken-links-ai' ), __( 'Lookups stop for the day at whichever limit comes first, and carry on just after midnight.', 'replace-broken-links-ai' ), 'render_limits' ),
			'ai'      => array( 'connections', 'brain', __( 'AI', 'replace-broken-links-ai' ), __( 'Writes the Google search for each dead link, then picks the best replacement from the results. Your key is stored encrypted and only ever sent to the provider you choose.', 'replace-broken-links-ai' ), 'render_ai' ),
			'search'  => array( 'connections', 'search', __( 'Google search (Serper.dev)', 'replace-broken-links-ai' ), __( 'Serper.dev returns Google\'s top 10 results for a search. Each lookup uses one search (two when the dead link\'s own website is searched first). New accounts get free searches to start with. Links to your own site never use a search.', 'replace-broken-links-ai' ), 'render_search' ),
			'query'   => array( 'advanced', 'message-square-text', __( 'Step 1: writing the search', 'replace-broken-links-ai' ), __( 'Writing a search is an easy job, so it can use a cheaper model than choosing the replacement.', 'replace-broken-links-ai' ), 'render_query' ),
			'pick'    => array( 'advanced', 'list-ordered', __( 'Step 2: choosing the replacement', 'replace-broken-links-ai' ), __( 'Uses the model chosen under Connections. The AI only ever answers with a result\'s number, so whatever it writes, the link can only become one of the search results.', 'replace-broken-links-ai' ), 'render_pick' ),
			'try'     => array( 'advanced', 'flask-conical', __( 'Try it on any link', 'replace-broken-links-ai' ), __( 'Runs a full lookup on a link you type in, and shows each step. Nothing on your site changes. It counts as one lookup.', 'replace-broken-links-ai' ), 'render_try' ),
			'dev'     => array( 'advanced', 'code', __( 'For developers', 'replace-broken-links-ai' ), '', 'render_dev' ),
		);

		if ( $ready ) {
			unset( $sections['setup'] );
		}

		return $sections;
	}

	/**
	 * Render the page: a header with the tabs, then WordPress's two-column
	 * box layout (content on the left, scan or save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab    = $this->current_tab();
		$counts = Replace_Broken_Links_AI_DB::counts();
		$form   = in_array( $tab, self::FORM_TABS, true );
		?>
		<div class="rblai-header">
			<div class="rblai-header-title">
				<span class="rblai-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="rblai-version"><?php echo esc_html( 'v' . REPLACE_BROKEN_LINKS_AI_VERSION ); ?></span>
			</div>
			<nav class="rblai-tabs" aria-label="<?php esc_attr_e( 'Replace Broken Links sections', 'replace-broken-links-ai' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>" class="rblai-tab<?php echo $tab === $id ? ' is-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
						<?php if ( 'links' === $id && $counts[ Replace_Broken_Links_AI_DB::REVIEW ] ) : ?>
							<span class="rblai-count"><?php echo esc_html( number_format_i18n( $counts[ Replace_Broken_Links_AI_DB::REVIEW ] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap rblai-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			$this->render_notice();
			$this->render_paused();
			?>

			<?php if ( $form ) : ?>
				<form action="options.php" method="post" class="rblai-form" autocomplete="off">
					<?php settings_fields( self::PAGE ); ?>
					<input type="hidden" name="<?php echo esc_attr( self::name( '_section' ) ); ?>" value="<?php echo esc_attr( $tab ); ?>">
			<?php endif; ?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						if ( 'links' === $tab ) {
							$this->render_links();
						} else {
							foreach ( $this->sections() as $id => $section ) {
								if ( $section[0] === $tab ) {
									$this->render_section( $id, $section );
								}
							}
						}
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="rblai-sidebar">
							<?php
							if ( $form ) {
								$this->render_save_box();
							} else {
								$this->render_scan_box();
							}
							$this->render_status_box();
							$this->render_updates_box();
							?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>

			<?php if ( $form ) : ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One box.
	 *
	 * @param string $id      Section ID.
	 * @param array  $section Tab, icon, title, intro, render method.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox rblai-box" id="rblai-<?php echo esc_attr( $id ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section[2] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section[3] ) : ?>
					<p class="rblai-intro"><?php echo esc_html( $section[3] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section[4] ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The notice after an action.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; numbers only.
		$notice = isset( $_GET['rblai_notice'] ) ? sanitize_key( wp_unslash( $_GET['rblai_notice'] ) ) : '';
		$n      = array();
		foreach ( array( 'done', 'gone', 'edited', 'failed', 'waiting', 'recovered' ) as $key ) {
			$n[ $key ] = isset( $_GET[ 'rblai_' . $key ] ) ? absint( $_GET[ 'rblai_' . $key ] ) : 0;
		}
		// phpcs:enable

		$messages = array();

		switch ( $notice ) {
			case 'scan':
				$messages[] = __( 'Scan started. It carries on in the background, so you can leave this page.', 'replace-broken-links-ai' );
				break;
			case 'approve':
				/* translators: %s: number of posts. */
				$messages[] = sprintf( _n( 'Replaced in %s post.', 'Replaced in %s posts.', $n['done'], 'replace-broken-links-ai' ), number_format_i18n( $n['done'] ) );
				break;
			case 'undo':
				/* translators: %s: number of links. */
				$messages[] = sprintf( _n( '%s replacement undone. That link won\'t be changed again.', '%s replacements undone. Those links won\'t be changed again.', $n['done'], 'replace-broken-links-ai' ), number_format_i18n( $n['done'] ) );
				if ( $n['edited'] ) {
					/* translators: %s: number of posts. */
					$messages[] = sprintf( _n( '%s post was left as it is because it has been edited since. Use its revisions to put the old link back.', '%s posts were left as they are because they have been edited since. Use their revisions to put the old links back.', $n['edited'], 'replace-broken-links-ai' ), number_format_i18n( $n['edited'] ) );
				}
				break;
			case 'ignore':
				/* translators: %s: number of links. */
				$messages[] = sprintf( _n( '%s link ignored. It won\'t be looked up or changed.', '%s links ignored. They won\'t be looked up or changed.', $n['done'], 'replace-broken-links-ai' ), number_format_i18n( $n['done'] ) );
				break;
			case 'restore':
				/* translators: %s: number of links. */
				$messages[] = sprintf( _n( '%s link restored.', '%s links restored.', $n['done'], 'replace-broken-links-ai' ), number_format_i18n( $n['done'] ) );
				break;
			case 'retry':
				/* translators: %s: number of links. */
				$messages[] = sprintf( _n( '%s link will be looked up again in the background.', '%s links will be looked up again in the background.', $n['done'], 'replace-broken-links-ai' ), number_format_i18n( $n['done'] ) );
				break;
			default:
				return;
		}

		if ( $n['waiting'] ) {
			/* translators: %s: number of posts. */
			$messages[] = sprintf( _n( '%s post is open in the editor, so it gets the change in a few minutes.', '%s posts are open in the editor, so they get the change in a few minutes.', $n['waiting'], 'replace-broken-links-ai' ), number_format_i18n( $n['waiting'] ) );
		}
		if ( $n['recovered'] ) {
			/* translators: %s: number of links. */
			$messages[] = sprintf( _n( '%s original page works again, so its link was left alone.', '%s original pages work again, so their links were left alone.', $n['recovered'], 'replace-broken-links-ai' ), number_format_i18n( $n['recovered'] ) );
		}
		if ( $n['gone'] ) {
			/* translators: %s: number of posts. */
			$messages[] = sprintf( _n( 'The link had already been edited out of %s post.', 'The link had already been edited out of %s posts.', $n['gone'], 'replace-broken-links-ai' ), number_format_i18n( $n['gone'] ) );
		}
		if ( $n['failed'] ) {
			/* translators: %s: number of posts. */
			$messages[] = sprintf( _n( '%s post couldn\'t be saved. Check that you can edit it.', '%s posts couldn\'t be saved. Check that you can edit them.', $n['failed'], 'replace-broken-links-ai' ), number_format_i18n( $n['failed'] ) );
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$n['failed'] || $n['edited'] ? 'warning' : 'success',
			esc_html( implode( ' ', $messages ) )
		);
	}

	/**
	 * The notice shown while lookups are paused.
	 *
	 * @return void
	 */
	private function render_paused() {
		$paused = Replace_Broken_Links_AI_Plugin::paused();

		if ( '' === $paused ) {
			return;
		}
		?>
		<div class="notice notice-error rblai-paused">
			<p>
				<strong><?php esc_html_e( 'Lookups are paused.', 'replace-broken-links-ai' ); ?></strong>
				<?php echo esc_html( $paused ); ?>
				<?php esc_html_e( 'Broken links are still found; nothing is looked up until this is fixed.', 'replace-broken-links-ai' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( self::url( 'connections' ) ); ?>"><?php esc_html_e( 'Check the connections', 'replace-broken-links-ai' ); ?></a>
				<button type="button" class="button" data-rblai-resume><?php esc_html_e( 'It\'s fixed: resume lookups', 'replace-broken-links-ai' ); ?></button>
			</p>
		</div>
		<?php
	}

	// Overview ------------------------------------------------------------------

	/**
	 * Overview: the steps to connect the two APIs.
	 *
	 * @return void
	 */
	public function render_setup() {
		$plugin = $this->plugin;
		$state  = Replace_Broken_Links_AI_Scanner::get_state();
		$steps  = array(
			array( (bool) $state['finished'] || 'running' === $state['status'], __( 'Scan your posts for broken links', 'replace-broken-links-ai' ), __( 'Started by itself when the plugin was activated.', 'replace-broken-links-ai' ), '' ),
			array( $plugin->ai_ready(), __( 'Connect an AI: OpenAI (ChatGPT) or Anthropic (Claude)', 'replace-broken-links-ai' ), __( 'Paste an API key and pick a model.', 'replace-broken-links-ai' ), self::url( 'connections' ) . '#rblai-ai' ),
			array( $plugin->search_ready(), __( 'Connect Serper.dev for Google results', 'replace-broken-links-ai' ), __( 'Free to start; about $1 per 1,000 searches after that.', 'replace-broken-links-ai' ), self::url( 'connections' ) . '#rblai-search' ),
		);
		?>
		<ol class="rblai-steps">
			<?php foreach ( $steps as $step ) : ?>
				<li class="<?php echo $step[0] ? 'is-done' : ''; ?>">
					<?php echo self::icon( $step[0] ? 'circle-check' : 'circle-dashed', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span class="rblai-step-text">
						<strong><?php echo esc_html( $step[1] ); ?></strong>
						<span><?php echo esc_html( $step[2] ); ?></span>
					</span>
					<?php if ( ! $step[0] && '' !== $step[3] ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $step[3] ); ?>"><?php esc_html_e( 'Connect', 'replace-broken-links-ai' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Overview: the numbers.
	 *
	 * @return void
	 */
	public function render_stats() {
		$counts = Replace_Broken_Links_AI_DB::counts();
		$broken = $counts[ Replace_Broken_Links_AI_DB::DEAD ] + $counts[ Replace_Broken_Links_AI_DB::NONE ] + $counts[ Replace_Broken_Links_AI_DB::FAILED ];
		$tiles  = array(
			array( 'link', Replace_Broken_Links_AI_DB::count_urls(), __( 'Links checked', 'replace-broken-links-ai' ), '', 'broken' ),
			array( 'hourglass', $counts[ Replace_Broken_Links_AI_DB::WATCHING ], __( 'Waiting to confirm', 'replace-broken-links-ai' ), '', 'watching' ),
			array( 'link-2-off', $broken, __( 'Dead, not replaced yet', 'replace-broken-links-ai' ), $broken ? 'bad' : '', 'broken' ),
			array( 'inbox', $counts[ Replace_Broken_Links_AI_DB::REVIEW ], __( 'Need review', 'replace-broken-links-ai' ), $counts[ Replace_Broken_Links_AI_DB::REVIEW ] ? 'warn' : '', 'review' ),
			array( 'sparkles', $counts[ Replace_Broken_Links_AI_DB::REPLACED ], __( 'Replaced', 'replace-broken-links-ai' ), 'good', 'replaced' ),
		);
		?>
		<div class="rblai-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<a class="rblai-stat<?php echo '' !== $tile[3] ? ' is-' . esc_attr( $tile[3] ) : ''; ?>" href="<?php echo esc_url( self::url( 'links', array( 'view' => $tile[4] ) ) ); ?>">
					<span class="rblai-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="rblai-stat-num"><?php echo esc_html( number_format_i18n( (int) $tile[1] ) ); ?></span>
					<span class="rblai-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Overview: the steps of a replacement, with the current settings.
	 *
	 * @return void
	 */
	public function render_how() {
		$plugin = $this->plugin;
		$grace  = $plugin->grace_seconds();
		$labels = $this->grace_labels();
		$wait   = isset( $labels[ (string) $plugin->get_setting( 'grace' ) ] ) ? $labels[ (string) $plugin->get_setting( 'grace' ) ][0] : '';
		$steps  = array(
			array( 'scan-search', __( 'Find', 'replace-broken-links-ai' ), __( 'Every link in your posts is checked like a browser would: 404 and 410 only, plus domains that changed hands.', 'replace-broken-links-ai' ) ),
			/* translators: %s: grace period, e.g. "7 days". */
			array( 'hourglass', __( 'Confirm', 'replace-broken-links-ai' ), $grace ? sprintf( __( 'It has to keep failing for %s, then fail again.', 'replace-broken-links-ai' ), strtolower( $wait ) ) : __( 'Two failures in a row are enough (your setting).', 'replace-broken-links-ai' ) ),
			array( 'archive', __( 'Remember', 'replace-broken-links-ai' ), __( 'The Wayback Machine says what the page used to be about.', 'replace-broken-links-ai' ) ),
			array( 'message-square-text', __( 'Search', 'replace-broken-links-ai' ), __( 'The AI writes a Google search; the original site is searched first. Your own links match your own posts.', 'replace-broken-links-ai' ) ),
			array( 'list-ordered', __( 'Choose', 'replace-broken-links-ai' ), __( 'The AI picks the best of the top results and says how sure it is.', 'replace-broken-links-ai' ) ),
			array( 'badge-check', __( 'Verify', 'replace-broken-links-ai' ), __( 'The page has to load (200), not be noindexed or parked, and is linked without redirects.', 'replace-broken-links-ai' ) ),
			array( 'replace', __( 'Swap', 'replace-broken-links-ai' ), 'auto' === $plugin->get_setting( 'mode' ) ? __( 'Swapped in when the AI is sure enough; otherwise it waits for you.', 'replace-broken-links-ai' ) : __( 'Waits for your approval (your setting).', 'replace-broken-links-ai' ) ),
		);
		?>
		<ol class="rblai-flow">
			<?php foreach ( $steps as $i => $step ) : ?>
				<li>
					<span class="rblai-flow-icon"><?php echo self::icon( $step[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<strong><?php echo esc_html( ( $i + 1 ) . '. ' . $step[1] ); ?></strong>
					<span><?php echo esc_html( $step[2] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Overview: the latest replacements.
	 *
	 * @return void
	 */
	public function render_recent() {
		$rows = Replace_Broken_Links_AI_DB::recent( array( Replace_Broken_Links_AI_DB::REPLACED ), 6 );

		if ( ! $rows ) {
			echo '<p class="rblai-empty">' . self::icon( 'inbox', 16 ) . '<span>' . esc_html__( 'Nothing replaced yet. Replacements show up here as they happen.', 'replace-broken-links-ai' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}
		?>
		<table class="widefat striped rblai-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Old link', 'replace-broken-links-ai' ); ?></th>
					<th><?php esc_html_e( 'New link', 'replace-broken-links-ai' ); ?></th>
					<th><?php esc_html_e( 'When', 'replace-broken-links-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><code class="rblai-url rblai-url-old"><?php echo self::breakable( $row['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped piece by piece. ?></code></td>
						<td>
							<?php if ( '' !== (string) $row['target_title'] ) : ?>
								<strong class="rblai-target-title"><?php echo esc_html( $row['target_title'] ); ?></strong>
							<?php endif; ?>
							<a class="rblai-url rblai-url-new" href="<?php echo esc_url( $row['target'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo self::breakable( $row['target'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped piece by piece. ?></a>
						</td>
						<td class="rblai-nowrap">
							<?php
							/* translators: %s: time ago, e.g. "2 hours". */
							echo esc_html( sprintf( __( '%s ago', 'replace-broken-links-ai' ), human_time_diff( (int) $row['updated'] ) ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( self::url( 'links', array( 'view' => 'replaced' ) ) ); ?>"><?php esc_html_e( 'See every replacement', 'replace-broken-links-ai' ); ?></a></p>
		<?php
	}

	/**
	 * Overview: what the APIs have been used for.
	 *
	 * @return void
	 */
	public function render_usage() {
		$today = Replace_Broken_Links_AI_Usage::totals( 1 );
		$month = Replace_Broken_Links_AI_Usage::totals( 30 );
		$limit = $this->plugin->daily_limit();
		$cap   = $this->plugin->daily_budget_micros();
		$tiles = array(
			/* translators: %s: daily lookup limit. */
			array( 'sparkles', number_format_i18n( $today['lookups'] ) . ( $limit ? ' / ' . number_format_i18n( $limit ) : '' ), __( 'Lookups today', 'replace-broken-links-ai' ) ),
			array( 'coins', self::money( $today['cost'] ) . ( $cap ? ' / ' . self::money( $cap ) : '' ), __( 'Spent today (est.)', 'replace-broken-links-ai' ) ),
			array( 'search', number_format_i18n( $month['searches'] ), __( 'Searches, 30 days', 'replace-broken-links-ai' ) ),
			array( 'cpu', number_format_i18n( $month['input_tokens'] + $month['output_tokens'] ), __( 'AI tokens, 30 days', 'replace-broken-links-ai' ) ),
			array( 'receipt', self::money( $month['cost'] ), __( 'Spent, 30 days (est.)', 'replace-broken-links-ai' ) ),
		);
		?>
		<div class="rblai-stats is-small">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="rblai-stat">
					<span class="rblai-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="rblai-stat-num"><?php echo esc_html( $tile[1] ); ?></span>
					<span class="rblai-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * A URL, escaped, with line-break chances after its slashes and
	 * separators, so long links wrap at sensible places.
	 *
	 * @param string $url URL.
	 * @return string HTML.
	 */
	public static function breakable( $url ) {
		// Split first, then escape each piece, so "&" never becomes "&<wbr>amp;".
		$parts = preg_split( '#([/?&=]+)#', (string) $url, -1, PREG_SPLIT_DELIM_CAPTURE );
		$html  = '';

		foreach ( (array) $parts as $i => $part ) {
			$html .= esc_html( $part ) . ( $i % 2 ? '<wbr>' : '' );
		}

		return $html;
	}

	/**
	 * Dollars from millionths of a dollar.
	 *
	 * @param int $micros Amount.
	 * @return string
	 */
	private static function money( $micros ) {
		$micros = (int) $micros;

		if ( $micros > 0 && $micros < 10000 ) {
			return '<$0.01';
		}

		return '$' . number_format_i18n( $micros / 1000000, 2 );
	}

	// Links ---------------------------------------------------------------------

	/**
	 * The Links tab: the views and the list.
	 *
	 * @return void
	 */
	private function render_links() {
		if ( ! $this->table ) {
			return;
		}

		$view   = $this->current_view();
		$counts = Replace_Broken_Links_AI_DB::counts();
		$intros = array(
			'review'   => __( 'Replacements waiting for your approval. Check the new page, then approve it, pick another result, paste your own link, or ignore it.', 'replace-broken-links-ai' ),
			'replaced' => __( 'Every link the plugin has replaced. Undo puts the old link back (and stops it being replaced again). Each change also saved a revision of its post.', 'replace-broken-links-ai' ),
			'broken'   => __( 'Dead links waiting for a lookup, and ones without a good match. You can pick a result or paste your own link at any time.', 'replace-broken-links-ai' ),
			'watching' => __( 'Links that are failing but haven\'t failed for your whole grace period yet. Ones that come back are dropped from the list by themselves.', 'replace-broken-links-ai' ),
			'ignored'  => __( 'Links you ignored or undid. They\'re never looked up or changed. Restore one to put it back in line.', 'replace-broken-links-ai' ),
		);
		$sums   = array(
			'review'   => $counts[ Replace_Broken_Links_AI_DB::REVIEW ],
			'replaced' => $counts[ Replace_Broken_Links_AI_DB::REPLACED ],
			'broken'   => $counts[ Replace_Broken_Links_AI_DB::DEAD ] + $counts[ Replace_Broken_Links_AI_DB::NONE ] + $counts[ Replace_Broken_Links_AI_DB::FAILED ],
			'watching' => $counts[ Replace_Broken_Links_AI_DB::WATCHING ],
			'ignored'  => $counts[ Replace_Broken_Links_AI_DB::IGNORED ],
		);
		?>
		<div class="postbox rblai-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'list-checks' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Broken links', 'replace-broken-links-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="subsubsub rblai-views">
					<?php foreach ( array_keys( Replace_Broken_Links_AI_List_Table::VIEWS ) as $i => $id ) : ?>
						<li>
							<a href="<?php echo esc_url( self::url( 'links', array( 'view' => $id ) ) ); ?>"<?php echo $view === $id ? ' class="current" aria-current="page"' : ''; ?>>
								<?php echo esc_html( Replace_Broken_Links_AI_List_Table::view_label( $id ) ); ?>
								<span class="count">(<?php echo esc_html( number_format_i18n( $sums[ $id ] ) ); ?>)</span>
							</a><?php echo $i < count( Replace_Broken_Links_AI_List_Table::VIEWS ) - 1 ? ' |' : ''; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="rblai-intro rblai-clear"><?php echo esc_html( $intros[ $view ] ); ?></p>
				<form method="get" class="rblai-list-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
					<input type="hidden" name="tab" value="links">
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
					<?php
					$this->table->search_box( __( 'Search links', 'replace-broken-links-ai' ), 'rblai-search' );
					$this->table->display();
					?>
				</form>
			</div>
		</div>
		<?php
	}

	// Settings tab --------------------------------------------------------------

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Replace_Broken_Links_AI_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label and help text.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Help text.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="rblai-check"><input type="checkbox" name="%1$s" value="1" %2$s> <span>%3$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Radio cards.
	 *
	 * @param string $key     Setting key.
	 * @param array  $choices Value => array( icon, title, description ).
	 * @param string $class   Extra class for the group.
	 * @return void
	 */
	private function cards( $key, array $choices, $class = '' ) {
		$current = (string) $this->plugin->get_setting( $key );
		?>
		<div class="rblai-options <?php echo esc_attr( $class ); ?>" role="radiogroup">
			<?php foreach ( $choices as $value => $choice ) : ?>
				<label class="rblai-option">
					<input type="radio" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, (string) $value ); ?>>
					<span class="rblai-option-icon"><?php echo self::icon( $choice[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="rblai-option-text">
						<strong><?php echo esc_html( $choice[1] ); ?></strong>
						<?php if ( '' !== $choice[2] ) : ?>
							<span><?php echo esc_html( $choice[2] ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '' ) {
		echo '<tr><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * Grace period choices: value => array( title, description ).
	 *
	 * @return array<string,string[]>
	 */
	private function grace_labels() {
		return array(
			'now' => array( __( 'Right away', 'replace-broken-links-ai' ), __( 'Two failures in a row', 'replace-broken-links-ai' ) ),
			'1d'  => array( __( '24 hours', 'replace-broken-links-ai' ), __( 'Rides out a bad day', 'replace-broken-links-ai' ) ),
			'3d'  => array( __( '3 days', 'replace-broken-links-ai' ), __( 'Rides out a long weekend', 'replace-broken-links-ai' ) ),
			'7d'  => array( __( '7 days', 'replace-broken-links-ai' ), __( 'Recommended', 'replace-broken-links-ai' ) ),
			'14d' => array( __( '14 days', 'replace-broken-links-ai' ), __( 'Extra cautious', 'replace-broken-links-ai' ) ),
			'30d' => array( __( '30 days', 'replace-broken-links-ai' ), __( 'Only long-gone pages', 'replace-broken-links-ai' ) ),
		);
	}

	/**
	 * Settings: when a link counts as dead.
	 *
	 * @return void
	 */
	public function render_dead() {
		$cards = array();
		foreach ( $this->grace_labels() as $value => $label ) {
			$cards[ $value ] = array( 'now' === $value ? 'zap' : ( in_array( $value, array( '14d', '30d' ), true ) ? 'calendar-clock' : 'hourglass' ), $label[0], $label[1] );
		}

		echo '<p class="rblai-label">' . esc_html__( 'Count a link as dead after it has failed for:', 'replace-broken-links-ai' ) . '</p>';
		$this->cards( 'grace', $cards, 'is-grid' );
		echo '<div class="rblai-gap"></div>';
		$this->checkbox(
			'domains',
			__( 'Also flag links whose domain changed hands', 'replace-broken-links-ai' ),
			__( 'Expired domains often answer 200 with a parked or for-sale page, or send visitors to an unrelated (often spammy) site, which is worse for SEO than a 404. These are found by what the page says and where it redirects, get the same waiting period, and their replacements always wait for your approval.', 'replace-broken-links-ai' )
		);
	}

	/**
	 * Settings: what gets checked.
	 *
	 * @return void
	 */
	public function render_scan() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Content to scan', 'replace-broken-links-ai' ),
			function () {
				$selected = (array) $this->plugin->get_setting( 'post_types' );
				echo '<fieldset class="rblai-checks"><legend class="screen-reader-text">' . esc_html__( 'Content to scan', 'replace-broken-links-ai' ) . '</legend>';
				foreach ( self::scannable_post_types() as $name => $label ) {
					printf(
						'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
						esc_attr( self::name( 'post_types' ) ),
						esc_attr( $name ),
						checked( in_array( $name, $selected, true ), true, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset><p class="description">' . esc_html__( 'Published and scheduled items. Each one is also checked about a minute after it\'s saved.', 'replace-broken-links-ai' ) . '</p>';
			}
		);

		$this->row(
			__( 'Links to your site', 'replace-broken-links-ai' ),
			function () {
				$this->checkbox( 'internal', __( 'Check them too', 'replace-broken-links-ai' ), __( 'A dead link to one of your own pages is matched against your own published posts and pages; Google is never searched for these.', 'replace-broken-links-ai' ) );
			}
		);

		$this->row(
			__( 'Rescan everything', 'replace-broken-links-ai' ),
			function () {
				$current = (string) $this->plugin->get_setting( 'rescan' );
				$labels  = array(
					'daily'   => __( 'Daily', 'replace-broken-links-ai' ),
					'weekly'  => __( 'Weekly', 'replace-broken-links-ai' ),
					'monthly' => __( 'Monthly', 'replace-broken-links-ai' ),
					'off'     => __( 'Never (only when I click Scan now)', 'replace-broken-links-ai' ),
				);
				echo '<fieldset class="rblai-checks"><legend class="screen-reader-text">' . esc_html__( 'Rescan everything', 'replace-broken-links-ai' ) . '</legend>';
				foreach ( $labels as $value => $label ) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s> %4$s</label>',
						esc_attr( self::name( 'rescan' ) ),
						esc_attr( $value ),
						checked( $current, $value, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset><p class="description">' . esc_html__( 'Pages you link to can disappear at any time. Failing links are checked again on their own schedule, whatever this is set to.', 'replace-broken-links-ai' ) . '</p>';
			}
		);

		$this->row(
			__( 'Never check', 'replace-broken-links-ai' ),
			function () {
				printf(
					'<textarea id="rblai-skip-hosts" name="%1$s" rows="3" class="large-text code" placeholder="%2$s">%3$s</textarea><p class="description">%4$s</p>',
					esc_attr( self::name( 'skip_hosts' ) ),
					esc_attr( "amzn.to\nshareasale.com" ),
					esc_textarea( (string) $this->plugin->get_setting( 'skip_hosts' ) ),
					esc_html__( 'One domain per line (subdomains included). Links to these are never checked or changed: handy for affiliate links and shorteners.', 'replace-broken-links-ai' )
				);
			},
			'rblai-skip-hosts'
		);

		echo '</table>';
	}

	/**
	 * Settings: where replacements come from.
	 *
	 * @return void
	 */
	public function render_sources() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Wayback Machine', 'replace-broken-links-ai' ),
			function () {
				$this->checkbox( 'wayback', __( 'Read the archived copy of each dead page first', 'replace-broken-links-ai' ), __( 'The Internet Archive\'s last good copy says what the page was about, which makes for a much better search than guessing from its address. Only the dead link is sent to archive.org.', 'replace-broken-links-ai' ) );
			}
		);

		$this->row(
			__( 'Original website', 'replace-broken-links-ai' ),
			function () {
				$this->checkbox( 'search_original', __( 'Search the dead link\'s own website first', 'replace-broken-links-ai' ), __( 'Pages often just move. Up to 5 results from a site: search of the original domain are offered ahead of the web results (one extra search credit). Skipped when the domain changed hands.', 'replace-broken-links-ai' ) );
			}
		);

		$this->row(
			__( 'Preferred sources', 'replace-broken-links-ai' ),
			function () {
				printf(
					'<textarea id="rblai-prefer-hosts" name="%1$s" rows="3" class="large-text code" placeholder="%2$s">%3$s</textarea><p class="description">%4$s</p>',
					esc_attr( self::name( 'prefer_hosts' ) ),
					esc_attr( ".gov\n.edu\nwikipedia.org" ),
					esc_textarea( (string) $this->plugin->get_setting( 'prefer_hosts' ) ),
					esc_html__( 'Domains, or endings like .gov and .edu (country versions like .gov.uk count). When two results are about equally good, the AI is told to choose one of these.', 'replace-broken-links-ai' )
				);
			},
			'rblai-prefer-hosts'
		);

		$this->row(
			__( 'Never link to', 'replace-broken-links-ai' ),
			function () {
				printf(
					'<textarea id="rblai-block-hosts" name="%1$s" rows="3" class="large-text code" placeholder="%2$s">%3$s</textarea><p class="description">%4$s</p>',
					esc_attr( self::name( 'block_hosts' ) ),
					esc_attr( "competitor.com\npinterest.com" ),
					esc_textarea( (string) $this->plugin->get_setting( 'block_hosts' ) ),
					esc_html__( 'One domain per line (subdomains included): competitors, or sites you never want to send readers to. Results from these are removed before the AI sees them.', 'replace-broken-links-ai' )
				);
			},
			'rblai-block-hosts'
		);

		echo '</table>';
	}

	/**
	 * Settings: how replacements are made.
	 *
	 * @return void
	 */
	public function render_replace() {
		$this->cards(
			'mode',
			array(
				'auto'   => array( 'wand-sparkles', __( 'Replace automatically when the AI is sure', 'replace-broken-links-ai' ), __( 'Confident matches are swapped in right away; the rest wait in Needs review.', 'replace-broken-links-ai' ) ),
				'review' => array( 'inbox', __( 'Ask me first', 'replace-broken-links-ai' ), __( 'Every replacement waits in Needs review until you approve it.', 'replace-broken-links-ai' ) ),
			),
			'is-pair'
		);

		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Sure enough means', 'replace-broken-links-ai' ),
			function () {
				$current = (string) $this->plugin->get_setting( 'min_confidence' );
				echo '<select id="rblai-min-confidence" name="' . esc_attr( self::name( 'min_confidence' ) ) . '">';
				echo '<option value="medium"' . selected( $current, 'medium', false ) . '>' . esc_html__( 'Medium or high confidence', 'replace-broken-links-ai' ) . '</option>';
				echo '<option value="high"' . selected( $current, 'high', false ) . '>' . esc_html__( 'High confidence only', 'replace-broken-links-ai' ) . '</option>';
				echo '</select><p class="description">' . esc_html__( 'The AI rates every pick high, medium or low. Low always waits for you, and so does a page that turned the checker away.', 'replace-broken-links-ai' ) . '</p>';
			},
			'rblai-min-confidence'
		);

		$this->row(
			__( 'No live page matches', 'replace-broken-links-ai' ),
			function () {
				$current = (string) $this->plugin->get_setting( 'archive_fallback' );
				$labels  = array(
					'review' => __( 'Suggest the Wayback Machine copy for my approval', 'replace-broken-links-ai' ),
					'auto'   => __( 'Link to the Wayback Machine copy automatically', 'replace-broken-links-ai' ),
					'off'    => __( 'Leave the link as it is', 'replace-broken-links-ai' ),
				);
				echo '<fieldset class="rblai-checks"><legend class="screen-reader-text">' . esc_html__( 'No live page matches', 'replace-broken-links-ai' ) . '</legend>';
				foreach ( $labels as $value => $label ) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s> %4$s</label>',
						esc_attr( self::name( 'archive_fallback' ) ),
						esc_attr( $value ),
						checked( $current, $value, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset><p class="description">' . esc_html__( 'Only when there is a saved copy from before the page died. You can always pick a search result or paste your own link instead.', 'replace-broken-links-ai' ) . '</p>';
			}
		);

		echo '</table>';
	}

	/**
	 * Settings: daily limits.
	 *
	 * @return void
	 */
	public function render_limits() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Lookups a day', 'replace-broken-links-ai' ),
			function () {
				printf(
					'<input type="number" class="small-text" id="rblai-daily-limit" name="%1$s" value="%2$d" min="0" max="5000" step="1"> <span class="rblai-muted">%3$s</span><p class="description">%4$s</p>',
					esc_attr( self::name( 'daily_limit' ) ),
					(int) $this->plugin->get_setting( 'daily_limit' ),
					/* translators: %s: lookups used today. */
					esc_html( sprintf( __( '%s used today', 'replace-broken-links-ai' ), number_format_i18n( Replace_Broken_Links_AI_Usage::lookups_today() ) ) ),
					esc_html__( 'One lookup is one dead link: two AI requests and one or two searches. 0 means no limit.', 'replace-broken-links-ai' )
				);
			},
			'rblai-daily-limit'
		);

		$this->row(
			__( 'Spending a day', 'replace-broken-links-ai' ),
			function () {
				printf(
					'<span class="rblai-money"><span>$</span><input type="number" class="small-text" id="rblai-daily-budget" name="%1$s" value="%2$s" min="0" max="10000" step="0.01"></span> <span class="rblai-muted">%3$s</span><p class="description">%4$s</p>',
					esc_attr( self::name( 'daily_budget' ) ),
					esc_attr( (string) $this->plugin->get_setting( 'daily_budget' ) ),
					/* translators: %s: estimated amount spent today. */
					esc_html( sprintf( __( '%s spent today (estimated)', 'replace-broken-links-ai' ), self::money( Replace_Broken_Links_AI_Usage::spent_today() ) ) ),
					esc_html__( 'In US dollars, across the AI and Serper.dev, estimated from token counts and list prices. Models the plugin doesn\'t know are counted at the highest price, to stay on the safe side. 0 means no limit.', 'replace-broken-links-ai' )
				);
			},
			'rblai-daily-budget'
		);

		echo '</table>';
	}

	// Connections tab -------------------------------------------------------------

	/**
	 * A key field: saved state, a box for a new key, and a way to remove it.
	 *
	 * @param string $name   openai, anthropic or serper.
	 * @param string $label  Field label.
	 * @param string $get    Where to get a key.
	 * @return void
	 */
	private function key_field( $name, $label, $get ) {
		$source   = $this->plugin->key_source( $name );
		$constant = 'serper' === $name ? Replace_Broken_Links_AI_Plugin::SERPER_CONSTANT : Replace_Broken_Links_AI_Plugin::PROVIDERS[ $name ];
		$id       = 'rblai-key-' . $name;

		if ( 'constant' === $source ) {
			echo '<p class="rblai-key-state is-ok">' . self::icon( 'lock-keyhole', 15 ) . '<span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			/* translators: %s: constant name. */
			printf( esc_html__( 'Set in wp-config.php (%s).', 'replace-broken-links-ai' ), '<code>' . esc_html( $constant ) . '</code>' );
			echo '</span></p>';
			return;
		}

		$key  = 'openai' === $name || 'anthropic' === $name ? $this->plugin->ai_key( $name ) : $this->plugin->serper_key();
		$hint = Replace_Broken_Links_AI_Crypto::hint( $key );

		if ( 'saved' === $source ) {
			echo '<p class="rblai-key-state is-ok">' . self::icon( 'key-round', 15 ) . '<span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			echo '' !== $hint
				/* translators: %s: last four characters of the key. */
				? esc_html( sprintf( __( 'Key saved (ends in %s), stored encrypted.', 'replace-broken-links-ai' ), $hint ) )
				: esc_html__( 'Key saved, stored encrypted.', 'replace-broken-links-ai' );
			echo '</span></p>';
		}
		?>
		<div class="rblai-inline">
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="password" id="<?php echo esc_attr( $id ); ?>" class="regular-text code" name="<?php echo esc_attr( self::name( $name . '_key_new' ) ); ?>" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( 'saved' === $source ? __( 'Paste a new key to replace it', 'replace-broken-links-ai' ) : __( 'Paste your API key', 'replace-broken-links-ai' ) ); ?>" data-rblai-key="<?php echo esc_attr( $name ); ?>">
			<a class="button" href="<?php echo esc_url( $get ); ?>" target="_blank" rel="noopener noreferrer"><?php echo self::icon( 'external-link', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Get a key', 'replace-broken-links-ai' ); ?></a>
		</div>
		<?php if ( 'saved' === $source ) : ?>
			<label class="rblai-check rblai-remove"><input type="checkbox" name="<?php echo esc_attr( self::name( $name . '_key_clear' ) ); ?>" value="1"> <span><?php esc_html_e( 'Remove the saved key', 'replace-broken-links-ai' ); ?></span></label>
		<?php endif; ?>
		<?php
	}

	/**
	 * A model picker: the suggested models, the account's own list (after a
	 * test), and "Other model..." for any ID.
	 *
	 * @param string $provider openai or anthropic.
	 * @param string $key      Setting key.
	 * @return void
	 */
	private function model_field( $provider, $key ) {
		$current = (string) $this->plugin->get_setting( $key );
		$options = Replace_Broken_Links_AI_AI::suggested_models( $provider );
		$account = get_option( 'replace_broken_links_ai_models_' . $provider, array() );
		$id      = 'rblai-' . str_replace( '_', '-', $key );

		foreach ( is_array( $account ) ? $account : array() as $model ) {
			if ( Replace_Broken_Links_AI_AI::valid_model( $model ) && ! isset( $options[ $model ] ) ) {
				$options[ $model ] = $model;
			}
		}

		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}
		?>
		<div class="rblai-inline">
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" data-rblai-model="<?php echo esc_attr( $provider ); ?>">
				<?php foreach ( $options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
				<option value="__custom"><?php esc_html_e( 'Other model...', 'replace-broken-links-ai' ); ?></option>
			</select>
			<input type="text" class="regular-text code" name="<?php echo esc_attr( self::name( $key . '_custom' ) ); ?>" placeholder="<?php esc_attr_e( 'Model ID', 'replace-broken-links-ai' ); ?>" data-rblai-custom hidden>
		</div>
		<?php
	}

	/**
	 * Connections: the AI provider, its key and model.
	 *
	 * @return void
	 */
	public function render_ai() {
		$this->cards(
			'provider',
			array(
				'openai'    => array( 'bot', __( 'OpenAI (ChatGPT)', 'replace-broken-links-ai' ), __( 'GPT-6 models', 'replace-broken-links-ai' ) ),
				'anthropic' => array( 'sparkle', __( 'Anthropic (Claude)', 'replace-broken-links-ai' ), __( 'Claude Opus, Sonnet and Haiku', 'replace-broken-links-ai' ) ),
			),
			'is-pair'
		);

		$panels = array(
			'openai'    => array( __( 'OpenAI API key', 'replace-broken-links-ai' ), 'https://platform.openai.com/api-keys' ),
			'anthropic' => array( __( 'Anthropic API key', 'replace-broken-links-ai' ), 'https://console.anthropic.com/settings/keys' ),
		);

		foreach ( $panels as $provider => $panel ) :
			?>
			<div class="rblai-provider" data-rblai-provider="<?php echo esc_attr( $provider ); ?>">
				<table class="form-table" role="presentation">
					<?php
					$this->row(
						$panel[0],
						function () use ( $provider, $panel ) {
							$this->key_field( $provider, $panel[0], $panel[1] );
						},
						'rblai-key-' . $provider
					);
					$this->row(
						__( 'Model', 'replace-broken-links-ai' ),
						function () use ( $provider ) {
							$this->model_field( $provider, $provider . '_model' );
							echo '<p class="description">' . esc_html__( 'Chooses the replacement. A stronger model makes better choices; each lookup costs a few cents at most.', 'replace-broken-links-ai' ) . '</p>';
						},
						'rblai-' . $provider . '-model'
					);
					$this->row(
						'',
						function () use ( $provider ) {
							echo '<button type="button" class="button" data-rblai-test="ai" data-provider="' . esc_attr( $provider ) . '">' . self::icon( 'plug-zap', 15 ) . esc_html__( 'Test connection', 'replace-broken-links-ai' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
							echo '<p class="rblai-test-result" data-rblai-test-result="' . esc_attr( $provider ) . '" aria-live="polite"></p>';
						}
					);
					?>
				</table>
			</div>
			<?php
		endforeach;
	}

	/**
	 * Connections: Serper.dev.
	 *
	 * @return void
	 */
	public function render_search() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Serper.dev API key', 'replace-broken-links-ai' ),
			function () {
				$this->key_field( 'serper', __( 'Serper.dev API key', 'replace-broken-links-ai' ), 'https://serper.dev/api-key' );
			},
			'rblai-key-serper'
		);

		$this->row(
			__( 'Search as if in', 'replace-broken-links-ai' ),
			function () {
				$current = (string) $this->plugin->get_setting( 'country' );
				$locale  = $this->plugin->search_locale();
				echo '<select id="rblai-country" name="' . esc_attr( self::name( 'country' ) ) . '">';
				/* translators: %s: country code from the site's language. */
				echo '<option value=""' . selected( $current, '', false ) . '>' . esc_html( sprintf( __( 'Your site\'s country (%s)', 'replace-broken-links-ai' ), strtoupper( '' !== $locale['gl'] ? $locale['gl'] : 'us' ) ) ) . '</option>';
				echo '<option value="any"' . selected( $current, 'any', false ) . '>' . esc_html__( 'No particular country', 'replace-broken-links-ai' ) . '</option>';
				foreach ( Replace_Broken_Links_AI_Prompts::countries() as $code => $country ) {
					echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $country ) . '</option>';
				}
				echo '</select>';
			},
			'rblai-country'
		);

		$this->row(
			__( 'Search language', 'replace-broken-links-ai' ),
			function () {
				$current = (string) $this->plugin->get_setting( 'language' );
				$locale  = $this->plugin->search_locale();
				echo '<select id="rblai-language" name="' . esc_attr( self::name( 'language' ) ) . '">';
				/* translators: %s: language name. */
				echo '<option value=""' . selected( $current, '', false ) . '>' . esc_html( sprintf( __( 'Your site\'s language (%s)', 'replace-broken-links-ai' ), Replace_Broken_Links_AI_Prompts::language_name( $locale['hl'] ) ) ) . '</option>';
				foreach ( Replace_Broken_Links_AI_Prompts::languages() as $code => $language ) {
					echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $language ) . '</option>';
				}
				echo '</select>';
			},
			'rblai-language'
		);

		$this->row(
			'',
			function () {
				echo '<button type="button" class="button" data-rblai-test="search">' . self::icon( 'plug-zap', 15 ) . esc_html__( 'Test connection', 'replace-broken-links-ai' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				echo '<p class="rblai-test-result" data-rblai-test-result="search" aria-live="polite"></p>';
			}
		);

		echo '</table>';
	}

	// Advanced tab ----------------------------------------------------------------

	/**
	 * A prompt editor: the textarea, its placeholders, and the reply format
	 * that's always added.
	 *
	 * @param string $step query or pick.
	 * @return void
	 */
	private function prompt_field( $step ) {
		$key    = $step . '_prompt';
		$value  = Replace_Broken_Links_AI_Prompts::template( $step, $this->plugin->get_setting( $key ) );
		$format = 'query' === $step ? Replace_Broken_Links_AI_Prompts::query_format() : Replace_Broken_Links_AI_Prompts::pick_format();
		$edited = '' !== (string) $this->plugin->get_setting( $key );
		?>
		<div class="rblai-prompt-head">
			<label class="rblai-label" for="rblai-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Prompt', 'replace-broken-links-ai' ); ?></label>
			<span class="rblai-tag<?php echo $edited ? ' is-edited' : ''; ?>"><?php echo $edited ? esc_html__( 'Edited', 'replace-broken-links-ai' ) : esc_html__( 'Default', 'replace-broken-links-ai' ); ?></span>
			<button type="button" class="button button-small" data-rblai-reset="<?php echo esc_attr( $step ); ?>"><?php echo self::icon( 'rotate-ccw', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Reset to default', 'replace-broken-links-ai' ); ?></button>
		</div>
		<textarea id="rblai-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" rows="<?php echo 'pick' === $step ? 22 : 15; ?>" class="large-text code rblai-prompt" spellcheck="false" data-rblai-prompt="<?php echo esc_attr( $step ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="rblai-label"><?php esc_html_e( 'Placeholders (click to insert):', 'replace-broken-links-ai' ); ?></p>
		<ul class="rblai-tokens">
			<?php foreach ( Replace_Broken_Links_AI_Prompts::placeholders( $step ) as $token => $meaning ) : ?>
				<li><button type="button" class="rblai-token" data-rblai-token="<?php echo esc_attr( $token ); ?>" data-target="rblai-<?php echo esc_attr( $key ); ?>" title="<?php echo esc_attr( $meaning ); ?>"><code><?php echo esc_html( $token ); ?></code></button><span><?php echo esc_html( $meaning ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<div class="rblai-callout">
			<?php echo self::icon( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<span>
				<strong><?php esc_html_e( 'Always added at the end, so the answer can be read:', 'replace-broken-links-ai' ); ?></strong>
				<code class="rblai-format"><?php echo esc_html( $format ); ?></code>
			</span>
		</div>
		<?php
	}

	/**
	 * Advanced: step 1.
	 *
	 * @return void
	 */
	public function render_query() {
		$current = $this->plugin->provider();

		echo '<table class="form-table" role="presentation">';

		$this->row(
			/* translators: %s: provider name. */
			sprintf( __( 'Model (%s)', 'replace-broken-links-ai' ), Replace_Broken_Links_AI_AI::provider_name( $current ) ),
			function () use ( $current ) {
				$this->model_field( $current, $current . '_query_model' );
				echo '<p class="description">' . esc_html__( 'Switch AI providers under Connections.', 'replace-broken-links-ai' ) . '</p>';
			},
			'rblai-' . $current . '-query-model'
		);

		echo '</table>';

		// Keep the other provider's choice as it is.
		foreach ( array_keys( Replace_Broken_Links_AI_Plugin::PROVIDERS ) as $provider ) {
			if ( $provider !== $current ) {
				printf(
					'<input type="hidden" name="%1$s" value="%2$s">',
					esc_attr( self::name( $provider . '_query_model' ) ),
					esc_attr( (string) $this->plugin->get_setting( $provider . '_query_model' ) )
				);
			}
		}

		$this->prompt_field( 'query' );
	}

	/**
	 * Advanced: step 2.
	 *
	 * @return void
	 */
	public function render_pick() {
		$this->prompt_field( 'pick' );
	}

	/**
	 * Advanced: try a lookup.
	 *
	 * @return void
	 */
	public function render_try() {
		?>
		<div class="rblai-try" data-rblai-try>
			<p>
				<label class="rblai-label" for="rblai-try-url"><?php esc_html_e( 'Broken link', 'replace-broken-links-ai' ); ?></label>
				<input type="url" id="rblai-try-url" class="large-text code" placeholder="https://example.com/old-article/" data-rblai-try-url>
			</p>
			<p>
				<label class="rblai-label" for="rblai-try-anchor"><?php esc_html_e( 'Link text', 'replace-broken-links-ai' ); ?></label>
				<input type="text" id="rblai-try-anchor" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Google\'s guide to page experience', 'replace-broken-links-ai' ); ?>" data-rblai-try-anchor>
			</p>
			<p>
				<label class="rblai-label" for="rblai-try-context"><?php esc_html_e( 'The sentence it\'s in (optional)', 'replace-broken-links-ai' ); ?></label>
				<textarea id="rblai-try-context" class="large-text" rows="2" data-rblai-try-context></textarea>
			</p>
			<p>
				<button type="button" class="button button-secondary" data-rblai-try-run><?php echo self::icon( 'flask-conical', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Run a test lookup', 'replace-broken-links-ai' ); ?></button>
				<span class="rblai-muted"><?php esc_html_e( 'Uses the prompts as saved.', 'replace-broken-links-ai' ); ?></span>
			</p>
			<div class="rblai-try-result" data-rblai-try-result aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * The steps of a test lookup.
	 *
	 * @param array $result The finder's result.
	 * @return void
	 */
	private function render_try_result( array $result ) {
		$archive = $result['archive'];
		?>
		<ol class="rblai-try-steps">
			<li>
				<strong><?php esc_html_e( 'Wayback Machine', 'replace-broken-links-ai' ); ?></strong>
				<span><?php echo esc_html( is_array( $archive ) ? Replace_Broken_Links_AI_Prompts::archived( $archive ) : __( 'No archived copy found (or it\'s turned off).', 'replace-broken-links-ai' ) ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'The AI\'s search', 'replace-broken-links-ai' ); ?></strong>
				<span><code><?php echo esc_html( $result['query'] ); ?></code></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Results the AI saw', 'replace-broken-links-ai' ); ?></strong>
				<?php if ( $result['candidates'] ) : ?>
					<ol class="rblai-results">
						<?php foreach ( $result['candidates'] as $i => $candidate ) : ?>
							<li<?php echo (int) $result['choice'] === $i + 1 ? ' class="is-chosen"' : ''; ?>>
								<div class="rblai-result-text">
									<a href="<?php echo esc_url( $candidate['link'] ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html( '' !== $candidate['title'] ? $candidate['title'] : $candidate['link'] ); ?></strong></a>
									<?php if ( ! empty( $candidate['original'] ) ) : ?>
										<span class="rblai-tag"><?php esc_html_e( 'Original site', 'replace-broken-links-ai' ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $candidate['preferred'] ) ) : ?>
										<span class="rblai-tag is-preferred"><?php esc_html_e( 'Preferred', 'replace-broken-links-ai' ); ?></span>
									<?php endif; ?>
									<?php if ( (int) $result['choice'] === $i + 1 ) : ?>
										<span class="rblai-tag is-chosen"><?php esc_html_e( 'AI\'s pick', 'replace-broken-links-ai' ); ?></span>
									<?php endif; ?>
									<span class="rblai-result-url"><?php echo self::breakable( $candidate['link'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped piece by piece. ?></span>
								</div>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php else : ?>
					<span><?php esc_html_e( 'None.', 'replace-broken-links-ai' ); ?></span>
				<?php endif; ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Outcome', 'replace-broken-links-ai' ); ?></strong>
				<?php if ( 'found' === $result['status'] ) : ?>
					<span>
						<?php
						/* translators: 1: replacement URL, 2: confidence. */
						printf( esc_html__( 'Would replace with %1$s (%2$s confidence).', 'replace-broken-links-ai' ), '<a href="' . esc_url( $result['target'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $result['target'] ) . '</a>', esc_html( $result['confidence'] ) );
						?>
					</span>
				<?php else : ?>
					<span><?php esc_html_e( 'No replacement would be made.', 'replace-broken-links-ai' ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== (string) $result['reason'] ) : ?>
					<span class="rblai-reason"><?php echo self::icon( 'sparkles', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $result['reason'] ); ?></span></span>
				<?php endif; ?>
			</li>
		</ol>
		<?php
	}

	/**
	 * Advanced: notes for developers.
	 *
	 * @return void
	 */
	public function render_dev() {
		?>
		<p><?php esc_html_e( 'Keep API keys out of the database by defining them in wp-config.php. A defined key wins over a saved one:', 'replace-broken-links-ai' ); ?></p>
		<pre class="rblai-code">define( '<?php echo esc_html( Replace_Broken_Links_AI_Plugin::PROVIDERS['openai'] ); ?>', 'sk-...' );
define( '<?php echo esc_html( Replace_Broken_Links_AI_Plugin::PROVIDERS['anthropic'] ); ?>', 'sk-ant-...' );
define( '<?php echo esc_html( Replace_Broken_Links_AI_Plugin::SERPER_CONSTANT ); ?>', '...' );</pre>
		<p><?php esc_html_e( 'Filters:', 'replace-broken-links-ai' ); ?></p>
		<table class="widefat striped rblai-table">
			<tbody>
				<tr><td><code>replace_broken_links_ai_skip_post</code></td><td><?php esc_html_e( 'Return true to leave a post alone: its links are never checked or replaced.', 'replace-broken-links-ai' ); ?></td></tr>
				<tr><td><code>replace_broken_links_ai_request_args</code></td><td><?php esc_html_e( 'Arguments for the link checker\'s requests. Safety settings are always re-applied after it.', 'replace-broken-links-ai' ); ?></td></tr>
				<tr><td><code>replace_broken_links_ai_prices</code></td><td><?php esc_html_e( 'Model prices (USD per million tokens) used for the spending estimate.', 'replace-broken-links-ai' ); ?></td></tr>
				<tr><td><code>replace_broken_links_ai_search_cost</code></td><td><?php esc_html_e( 'Estimated cost of one search, in millionths of a dollar.', 'replace-broken-links-ai' ); ?></td></tr>
			</tbody>
		</table>
		<?php
	}

	// Sidebar -------------------------------------------------------------------

	/**
	 * Sidebar on settings tabs: the Save button.
	 *
	 * @return void
	 */
	private function render_save_box() {
		?>
		<div class="postbox rblai-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'replace-broken-links-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'Saves the settings on this tab.', 'replace-broken-links-ai' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'replace-broken-links-ai' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Scan progress, 0-100.
	 *
	 * @param array $state Scan state.
	 * @return int
	 */
	private function percent( array $state ) {
		if ( 'running' !== $state['status'] ) {
			return 100;
		}

		return (int) $state['total'] ? min( 99, (int) floor( 100 * (int) $state['done'] / (int) $state['total'] ) ) : 0;
	}

	/**
	 * One line about the scan: its progress, or how the last one went.
	 *
	 * @param array $state Scan state.
	 * @return string Plain text.
	 */
	private function scan_status_text( array $state ) {
		if ( 'running' === $state['status'] ) {
			return sprintf(
				/* translators: 1: posts scanned, 2: posts to scan. */
				__( 'Scanning: %1$s of %2$s posts checked', 'replace-broken-links-ai' ),
				number_format_i18n( min( (int) $state['done'], (int) $state['total'] ) ),
				number_format_i18n( (int) $state['total'] )
			);
		}

		if ( ! (int) $state['finished'] ) {
			return __( 'No scan has run yet.', 'replace-broken-links-ai' );
		}

		return sprintf(
			/* translators: 1: time ago, e.g. "2 hours", 2: number of posts. */
			_n( 'Last full scan finished %1$s ago (%2$s post checked).', 'Last full scan finished %1$s ago (%2$s posts checked).', (int) $state['done'], 'replace-broken-links-ai' ),
			human_time_diff( (int) $state['finished'] ),
			number_format_i18n( (int) $state['done'] )
		);
	}

	/**
	 * Sidebar: the scan's progress and the Scan now button.
	 *
	 * @return void
	 */
	private function render_scan_box() {
		$state   = Replace_Broken_Links_AI_Scanner::get_state();
		$running = 'running' === $state['status'];
		$days    = $this->plugin->rescan_days();

		if ( ! $days ) {
			$next = __( 'Automatic rescans are off.', 'replace-broken-links-ai' );
		} elseif ( $running || ! (int) $state['finished'] ) {
			$next = __( 'Then rescans automatically on your schedule.', 'replace-broken-links-ai' );
		} else {
			$due  = (int) $state['finished'] + $days * DAY_IN_SECONDS;
			$next = $due <= time()
				? __( 'Next automatic scan: today.', 'replace-broken-links-ai' )
				/* translators: %s: time until the next scan, e.g. "6 days". */
				: sprintf( __( 'Next automatic scan in %s.', 'replace-broken-links-ai' ), human_time_diff( $due ) );
		}
		?>
		<div class="postbox rblai-scan-box" data-rblai-scan data-running="<?php echo $running ? '1' : '0'; ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'radar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Scan', 'replace-broken-links-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p class="rblai-scan-status" data-rblai-scan-status aria-live="polite"><?php echo esc_html( $this->scan_status_text( $state ) ); ?></p>
				<div class="rblai-progress"<?php echo $running ? '' : ' hidden'; ?> data-rblai-progress>
					<span style="width: <?php echo (int) $this->percent( $state ); ?>%"></span>
				</div>
				<form method="post" action="<?php echo esc_url( self::url( $this->current_tab() ) ); ?>">
					<?php wp_nonce_field( 'replace_broken_links_ai_scan' ); ?>
					<button type="submit" name="rblai_scan" value="1" class="button button-primary button-large rblai-scan-button" data-rblai-scan-button<?php disabled( $running ); ?>>
						<span class="rblai-scan-idle"><?php echo self::icon( 'scan-search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Scan now', 'replace-broken-links-ai' ); ?></span>
						<span class="rblai-scan-busy"><?php echo self::icon( 'loader-circle', 16, 'rblai-spin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Scanning…', 'replace-broken-links-ai' ); ?></span>
					</button>
				</form>
				<p class="rblai-scan-next"><?php echo self::icon( 'timer', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $next ); ?></span></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Sidebar: what's connected and how it's set up.
	 *
	 * @return void
	 */
	private function render_status_box() {
		$plugin   = $this->plugin;
		$provider = $plugin->provider();
		$labels   = $this->grace_labels();
		$grace    = (string) $plugin->get_setting( 'grace' );
		$paused   = '' !== Replace_Broken_Links_AI_Plugin::paused();
		$under    = $plugin->scanner()->under_daily_limit();
		$items    = array(
			/* translators: 1: provider name, 2: model ID. */
			array( $plugin->ai_ready(), $plugin->ai_ready() ? sprintf( __( '%1$s connected (%2$s)', 'replace-broken-links-ai' ), Replace_Broken_Links_AI_AI::provider_name( $provider ), $plugin->model() ) : __( 'No AI connected', 'replace-broken-links-ai' ) ),
			array( $plugin->search_ready(), $plugin->search_ready() ? __( 'Serper.dev connected', 'replace-broken-links-ai' ) : __( 'Serper.dev not connected', 'replace-broken-links-ai' ) ),
			array( $plugin->ready() && ! $paused && $under, $paused ? __( 'Lookups paused', 'replace-broken-links-ai' ) : ( ! $under ? __( 'Daily limit reached', 'replace-broken-links-ai' ) : ( $plugin->ready() ? __( 'Looking up replacements', 'replace-broken-links-ai' ) : __( 'Finding broken links only', 'replace-broken-links-ai' ) ) ) ),
			/* translators: %s: grace period, e.g. "7 days". */
			array( true, 'now' === $grace ? __( 'Dead after two failures in a row', 'replace-broken-links-ai' ) : sprintf( __( 'Dead after failing for %s', 'replace-broken-links-ai' ), strtolower( isset( $labels[ $grace ] ) ? $labels[ $grace ][0] : '' ) ) ),
			array( true, 'auto' === $plugin->get_setting( 'mode' ) ? __( 'Replacing automatically when sure', 'replace-broken-links-ai' ) : __( 'Every replacement waits for approval', 'replace-broken-links-ai' ) ),
			array( (bool) $plugin->get_setting( 'wayback' ), $plugin->get_setting( 'wayback' ) ? __( 'Reading Wayback Machine copies', 'replace-broken-links-ai' ) : __( 'Wayback Machine off', 'replace-broken-links-ai' ) ),
		);
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'replace-broken-links-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="rblai-checklist">
					<?php foreach ( $items as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( REPLACE_BROKEN_LINKS_AI_REPO ) );
		$check_url  = Replace_Broken_Links_AI_Updater::check_now_url();
		?>
		<div class="postbox rblai-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'replace-broken-links-ai' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'replace-broken-links-ai' ), '<strong>' . esc_html( REPLACE_BROKEN_LINKS_AI_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Replace_Broken_Links_AI_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'replace-broken-links-ai' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( REPLACE_BROKEN_LINKS_AI_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Replace_Broken_Links_AI_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'replace-broken-links-ai' ),
							'<a href="' . esc_url( REPLACE_BROKEN_LINKS_AI_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>REPLACE_BROKEN_LINKS_AI_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'replace-broken-links-ai' ),
							'<a href="' . esc_url( REPLACE_BROKEN_LINKS_AI_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'replace-broken-links-ai' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
