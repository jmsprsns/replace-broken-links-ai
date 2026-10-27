<?php
/**
 * Main plugin orchestrator: owns the settings and boots the scanner and admin.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Replace_Broken_Links_AI_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'replace_broken_links_ai_settings';

	/**
	 * Option set when lookups stop because a key or account needs attention
	 * (a rejected key, no credit). Cleared when the connections are saved.
	 */
	const PAUSE_OPTION = 'replace_broken_links_ai_paused';

	/**
	 * AI providers: id => wp-config constant that can hold its key.
	 */
	const PROVIDERS = array(
		'openai'    => 'REPLACE_BROKEN_LINKS_AI_OPENAI_KEY',
		'anthropic' => 'REPLACE_BROKEN_LINKS_AI_ANTHROPIC_KEY',
	);

	/**
	 * wp-config constant that can hold the Serper.dev key.
	 */
	const SERPER_CONSTANT = 'REPLACE_BROKEN_LINKS_AI_SERPER_KEY';

	/**
	 * Single shared instance.
	 *
	 * @var Replace_Broken_Links_AI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Scanner instance.
	 *
	 * @var Replace_Broken_Links_AI_Scanner
	 */
	private $scanner;

	/**
	 * Settings cache for this request.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Replace_Broken_Links_AI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		// Updates and network activation don't run the activation hook, so
		// the tables and the daily check are set up here when they're missing.
		if ( is_admin() || wp_doing_cron() ) {
			Replace_Broken_Links_AI_DB::maybe_install();
			Replace_Broken_Links_AI_Scanner::schedule_daily();
		}

		$this->scanner = new Replace_Broken_Links_AI_Scanner( $this );

		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_settings' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_settings' ) );

		if ( is_admin() ) {
			new Replace_Broken_Links_AI_Admin( $this );
		}
	}

	/**
	 * The scanner.
	 *
	 * @return Replace_Broken_Links_AI_Scanner
	 */
	public function scanner() {
		return $this->scanner;
	}

	// Settings ----------------------------------------------------------------

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// When is a link dead, and what gets checked.
			'grace'                 => '7d',
			'domains'               => 1,
			'post_types'            => array( 'post', 'page' ),
			'internal'              => 1,
			'rescan'                => 'weekly',
			'skip_hosts'            => '',
			// Finding replacements.
			'wayback'               => 1,
			'search_original'       => 1,
			'prefer_hosts'          => ".gov\n.edu",
			'block_hosts'           => '',
			// Replacing.
			'mode'                  => 'auto',
			'min_confidence'        => 'medium',
			'archive_fallback'      => 'review',
			'daily_limit'           => 25,
			'daily_budget'          => '2.00',
			// Connections. Keys are stored encrypted (Replace_Broken_Links_AI_Crypto).
			'provider'              => 'openai',
			'openai_key'            => '',
			'anthropic_key'         => '',
			'serper_key'            => '',
			'openai_model'          => 'gpt-6.1-sol',
			'anthropic_model'       => 'claude-opus-5-5',
			'country'               => '',
			'language'              => '',
			// Advanced: the cheaper model and the prompts ('' = the default prompt).
			'openai_query_model'    => 'gpt-6-luna',
			'anthropic_query_model' => 'claude-sonnet-5-5',
			'query_prompt'          => '',
			'pick_prompt'           => '',
		);
	}

	/**
	 * Grace period choices: value => seconds a link has to keep failing.
	 *
	 * @return array<string,int>
	 */
	public static function grace_choices() {
		return array(
			'now' => 0,
			'1d'  => DAY_IN_SECONDS,
			'3d'  => 3 * DAY_IN_SECONDS,
			'7d'  => 7 * DAY_IN_SECONDS,
			'14d' => 14 * DAY_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
		);
	}

	/**
	 * Choices for the automatic rescan setting: value => days between scans.
	 *
	 * @return array<string,int>
	 */
	public static function rescan_choices() {
		return array(
			'daily'   => 1,
			'weekly'  => 7,
			'monthly' => 30,
			'off'     => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * Forget the cached settings after they're saved.
	 *
	 * @return void
	 */
	public function flush_settings() {
		$this->settings = null;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Content types that get scanned: the chosen ones that still exist.
	 *
	 * @return string[]
	 */
	public function post_types() {
		return array_values( array_filter( (array) $this->get_setting( 'post_types' ), 'post_type_exists' ) );
	}

	/**
	 * Seconds a link has to keep returning 404 or 410 before it counts as dead.
	 *
	 * @return int
	 */
	public function grace_seconds() {
		$choices = self::grace_choices();
		$grace   = (string) $this->get_setting( 'grace' );

		return isset( $choices[ $grace ] ) ? $choices[ $grace ] : 7 * DAY_IN_SECONDS;
	}

	/**
	 * Days between automatic rescans (0 when they're off).
	 *
	 * @return int
	 */
	public function rescan_days() {
		$choices = self::rescan_choices();
		$rescan  = (string) $this->get_setting( 'rescan' );

		return isset( $choices[ $rescan ] ) ? $choices[ $rescan ] : 7;
	}

	/**
	 * Most lookups (AI + search) a day.
	 *
	 * @return int 0 for no limit.
	 */
	public function daily_limit() {
		return max( 0, (int) $this->get_setting( 'daily_limit' ) );
	}

	/**
	 * Most estimated API spending a day, in millionths of a dollar.
	 *
	 * @return int 0 for no limit.
	 */
	public function daily_budget_micros() {
		return max( 0, (int) round( (float) $this->get_setting( 'daily_budget' ) * 1000000 ) );
	}

	// Connections -------------------------------------------------------------

	/**
	 * The chosen AI provider.
	 *
	 * @return string openai or anthropic.
	 */
	public function provider() {
		$provider = (string) $this->get_setting( 'provider' );

		return isset( self::PROVIDERS[ $provider ] ) ? $provider : 'openai';
	}

	/**
	 * An AI provider's API key: from wp-config.php if it's defined there,
	 * else the saved (encrypted) one.
	 *
	 * @param string $provider openai or anthropic.
	 * @return string '' when there's none.
	 */
	public function ai_key( $provider ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return '';
		}

		$constant = self::PROVIDERS[ $provider ];
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return trim( (string) constant( $constant ) );
		}

		return Replace_Broken_Links_AI_Crypto::decrypt( $this->get_setting( $provider . '_key' ) );
	}

	/**
	 * The Serper.dev API key, from wp-config.php or the saved one.
	 *
	 * @return string
	 */
	public function serper_key() {
		if ( defined( self::SERPER_CONSTANT ) && is_string( constant( self::SERPER_CONSTANT ) ) && '' !== constant( self::SERPER_CONSTANT ) ) {
			return trim( (string) constant( self::SERPER_CONSTANT ) );
		}

		return Replace_Broken_Links_AI_Crypto::decrypt( $this->get_setting( 'serper_key' ) );
	}

	/**
	 * Where a key comes from.
	 *
	 * @param string $name openai, anthropic or serper.
	 * @return string constant, saved, or '' when there's none.
	 */
	public function key_source( $name ) {
		$constant = 'serper' === $name ? self::SERPER_CONSTANT : ( isset( self::PROVIDERS[ $name ] ) ? self::PROVIDERS[ $name ] : '' );

		if ( '' !== $constant && defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return 'constant';
		}

		return '' !== Replace_Broken_Links_AI_Crypto::decrypt( $this->get_setting( $name . '_key' ) ) ? 'saved' : '';
	}

	/**
	 * Model for a step of the lookup.
	 *
	 * @param string $step pick (choosing the replacement) or query (writing the search).
	 * @return string
	 */
	public function model( $step = 'pick' ) {
		$provider = $this->provider();
		$key      = 'query' === $step ? $provider . '_query_model' : $provider . '_model';
		$model    = (string) $this->get_setting( $key );

		if ( ! Replace_Broken_Links_AI_AI::valid_model( $model ) ) {
			$defaults = self::defaults();
			$model    = $defaults[ $key ];
		}

		return $model;
	}

	/**
	 * Whether the chosen AI provider has a key.
	 *
	 * @return bool
	 */
	public function ai_ready() {
		return '' !== $this->ai_key( $this->provider() );
	}

	/**
	 * Whether Serper.dev has a key.
	 *
	 * @return bool
	 */
	public function search_ready() {
		return '' !== $this->serper_key();
	}

	/**
	 * Whether replacements can be looked up at all.
	 *
	 * @return bool
	 */
	public function ready() {
		return $this->ai_ready() && $this->search_ready();
	}

	/**
	 * Why lookups are paused, if they are.
	 *
	 * @return string Plain text, or '' when they aren't.
	 */
	public static function paused() {
		$paused = get_option( self::PAUSE_OPTION, '' );

		return is_string( $paused ) ? $paused : '';
	}

	/**
	 * Pause lookups (a key was rejected or an account is out of credit), so a
	 * bad key doesn't burn through every broken link's retries.
	 *
	 * @param string $reason Plain text.
	 * @return void
	 */
	public static function pause( $reason ) {
		update_option( self::PAUSE_OPTION, substr( sanitize_text_field( (string) $reason ), 0, 400 ), false );
	}

	/**
	 * Carry on with lookups.
	 *
	 * @return void
	 */
	public static function resume() {
		delete_option( self::PAUSE_OPTION );
	}

	/**
	 * Google country and language for searches: the setting, else the site's locale.
	 *
	 * @return array{gl: string, hl: string}
	 */
	public function search_locale() {
		$locale  = strtolower( str_replace( '-', '_', (string) get_locale() ) );
		$parts   = explode( '_', $locale );
		$lang    = (string) $this->get_setting( 'language' );
		$country = (string) $this->get_setting( 'country' );

		if ( '' === $lang ) {
			$lang = preg_match( '/^[a-z]{2}$/', $parts[0] ) ? $parts[0] : 'en';
		}

		if ( '' === $country ) {
			$country = isset( $parts[1] ) && preg_match( '/^[a-z]{2}$/', $parts[1] ) ? $parts[1] : 'us';
		}

		return array(
			'gl' => 'any' === $country ? '' : $country,
			'hl' => $lang,
		);
	}

	// Domains -----------------------------------------------------------------

	/**
	 * A list setting of domains, cleaned.
	 *
	 * @param string $key skip_hosts or block_hosts.
	 * @return string[] Lower-case hosts without "www.".
	 */
	public function hosts( $key ) {
		$lines = preg_split( '/[\s,]+/', strtolower( (string) $this->get_setting( $key ) ) );

		return array_values( array_filter( array_map( array( __CLASS__, 'clean_host' ), (array) $lines ) ) );
	}

	/**
	 * Whether a URL is on one of the domains in a list setting (or a subdomain of one).
	 *
	 * @param string $url Absolute URL.
	 * @param string $key skip_hosts or block_hosts.
	 * @return bool
	 */
	public function host_listed( $url, $key ) {
		$host = Replace_Broken_Links_AI_Checker::host( $url );

		foreach ( $this->hosts( $key ) as $listed ) {
			if ( $host === $listed || substr( $host, -strlen( '.' . $listed ) ) === '.' . $listed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Preferred sources: domains and endings like ".gov".
	 *
	 * @return string[]
	 */
	public function preferred() {
		$entries = array();

		foreach ( (array) preg_split( '/[\s,]+/', strtolower( (string) $this->get_setting( 'prefer_hosts' ) ) ) as $entry ) {
			$entry = self::clean_entry( $entry );
			if ( '' !== $entry ) {
				$entries[] = $entry;
			}
		}

		return array_values( array_unique( $entries ) );
	}

	/**
	 * A preferred-source entry: a domain, or an ending like ".gov".
	 *
	 * @param string $value As typed.
	 * @return string '' if it isn't usable.
	 */
	public static function clean_entry( $value ) {
		$value = trim( strtolower( (string) $value ) );

		if ( preg_match( '/^\*?\.([a-z0-9-]{2,63})$/', $value, $m ) ) {
			return '.' . $m[1];
		}

		return self::clean_host( $value );
	}

	/**
	 * A domain as typed (or a whole URL) reduced to a bare host: "https://www.Example.com/x" => "example.com".
	 *
	 * @param string $value Domain or URL.
	 * @return string '' if it isn't a usable host.
	 */
	public static function clean_host( $value ) {
		$value = trim( strtolower( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( false === strpos( $value, '//' ) ) {
			$value = 'http://' . $value;
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : '';

		return preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host ) ? $host : '';
	}
}
