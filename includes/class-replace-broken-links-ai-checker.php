<?php
/**
 * Checks whether a URL still loads, follows its redirects, and tells a page
 * that's really gone from one that only failed to answer this time.
 *
 * Every request is a GET with an ordinary browser's user agent (many sites
 * answer 403 or 404 to anything that looks like a script, and some answer
 * HEAD requests wrongly), through wp_safe_remote_get() with redirects turned
 * off, so WordPress validates each hop before it's requested: http or https
 * only, ports 80, 443 and 8080, and no private, loopback or reserved IP
 * addresses (except this site's own host). A link in a post, or a search
 * result, can't be used to probe the server's internal network, even
 * through a redirect. Bodies are capped at 64 KB.
 *
 * A link counts as gone for one of two reasons:
 * - 404 Not Found or 410 Gone, twice in a row;
 * - the domain no longer belongs to the site that was linked: a parked or
 *   for-sale page, a domain marketplace, a redirect from a deep page to
 *   another website's homepage, a page taken over by spam, or a domain with
 *   no DNS records at all.
 * Timeouts, server errors (5xx), rate limits (429) and "forbidden" (401,
 * 403) never count.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL checker.
 */
final class Replace_Broken_Links_AI_Checker {

	/**
	 * Most redirects to follow before calling it a loop.
	 */
	const MAX_HOPS = 8;

	/**
	 * Seconds to wait for each request.
	 */
	const TIMEOUT = 10;

	/**
	 * Most bytes of a page read.
	 */
	const MAX_BYTES = 65536;

	/**
	 * Result states.
	 */
	const LIVE    = 'live';    // 2xx at the end of any redirects.
	const BLOCKED = 'blocked'; // 401 or 403: the page is there, but not open to the checker.
	const GONE    = 'gone';    // 404 or 410, twice.
	const DOMAIN  = 'domain';  // The domain changed hands or no longer exists.
	const ERROR   = 'error';   // Couldn't tell: timeout, 5xx, 429, a loop, anything else.

	/**
	 * User agent: an ordinary desktop browser.
	 */
	const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

	/**
	 * Words in parked, for-sale and expired domain pages (lower case).
	 */
	const PARKING_SIGNS = array(
		'this domain is for sale',
		'this domain may be for sale',
		'domain name is for sale',
		'buy this domain',
		'make an offer on this domain',
		'inquire about this domain',
		'this domain has expired',
		'the domain has expired',
		'domain has expired and is pending renewal',
		'is parked free',
		'parked free, courtesy of',
		'this web page is parked',
		'this domain is parked',
		'sedoparking.com',
		'parkingcrew.net',
		'bodis.com',
		'parklogic',
		'cashparking',
		'dsnparking',
		'parkingpage.namecheap.com',
		'img.sedoparking',
	);

	/**
	 * Domain marketplaces: a link that ends up here has lost its site.
	 */
	const MARKETPLACES = array( 'sedo.com', 'dan.com', 'afternic.com', 'hugedomains.com', 'buydomains.com', 'domainmarket.com', 'undeveloped.com', 'atom.com', 'squadhelp.com', 'brandbucket.com', 'godaddy.com', 'domains.google', 'parkingcrew.net', 'bodis.com' );

	/**
	 * Words in the title of a page taken over by spam (lower case). Only
	 * counted when the link's own address doesn't contain them.
	 */
	const SPAM_WORDS = array( 'casino', 'slot gacor', 'slot online', 'situs slot', 'togel', 'judi online', 'judi bola', 'sportsbook', 'poker online', 'porn', 'viagra', 'cialis' );

	/**
	 * Check a URL.
	 *
	 * @param string $url Absolute http(s) URL without a fragment.
	 * @return array{state: string, code: int, final: string, note: string, noindex: bool, title: string}
	 *         final is the URL at the end of any redirects; note says why a
	 *         DOMAIN result counts as gone.
	 */
	public static function check( $url ) {
		$result = self::follow( $url );

		if ( self::GONE === $result['state'] ) {
			// Ask once more. Whatever this one says wins: if it loads now the
			// page isn't gone, and if it doesn't answer, nobody can say it is.
			$result = self::follow( $url );
		}

		if ( self::ERROR === $result['state'] && 0 === $result['code'] && ! self::is_internal( $url ) && ! self::has_dns( self::host_of( $url ) ) ) {
			$result['state'] = self::DOMAIN;
			$result['note']  = __( 'The domain no longer exists (it has no DNS records).', 'replace-broken-links-ai' );
		}

		if ( self::LIVE === $result['state'] && ! self::is_internal( $url ) ) {
			$reason = self::parking_reason( $url, $result['final'], $result['body'] );

			if ( '' !== $reason ) {
				$result['state'] = self::DOMAIN;
				$result['note']  = $reason;
			}
		}

		unset( $result['body'] );

		return $result;
	}

	/**
	 * Whether an HTTP status means the page is gone.
	 *
	 * @param int $code HTTP status.
	 * @return bool
	 */
	public static function is_gone_code( $code ) {
		return 404 === (int) $code || 410 === (int) $code;
	}

	/**
	 * Follow a URL's redirects (any 3xx) to where it ends up.
	 *
	 * @param string $url Absolute URL.
	 * @return array{state: string, code: int, final: string, note: string, noindex: bool, title: string, body: string}
	 */
	private static function follow( $url ) {
		$current = $url;
		$seen    = array();

		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			if ( isset( $seen[ $current ] ) ) {
				return self::result( self::ERROR, 0, $current ); // Redirect loop.
			}
			$seen[ $current ] = true;

			$response = self::request( $current );

			if ( is_wp_error( $response ) ) {
				return self::result( self::ERROR, 0, $current );
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = $code >= 300 && $code < 400 ? self::location( $response, $current ) : '';

			if ( '' !== $location ) {
				$current = $location;
				continue;
			}

			$body   = (string) wp_remote_retrieve_body( $response );
			$result = self::result( self::state( $code ), $code, $current );

			$result['body']    = $body;
			$result['title']   = self::title( $body );
			$result['noindex'] = self::is_noindex( $response, $body );

			return $result;
		}

		return self::result( self::ERROR, 0, $current ); // Runaway chain.
	}

	/**
	 * What an HTTP status means for a link.
	 *
	 * @param int $code HTTP status.
	 * @return string
	 */
	private static function state( $code ) {
		if ( $code >= 200 && $code < 300 ) {
			return self::LIVE;
		}

		if ( self::is_gone_code( $code ) ) {
			return self::GONE;
		}

		if ( 401 === $code || 403 === $code ) {
			return self::BLOCKED;
		}

		return self::ERROR;
	}

	/**
	 * One GET request, without following redirects.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	private static function request( $url ) {
		if ( ! self::is_allowed_port( $url ) ) {
			return new WP_Error( 'replace_broken_links_ai_port', 'Other ports on this site are not checked.' );
		}

		$args = array(
			'redirection'         => 0,
			'timeout'             => self::TIMEOUT,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => self::BROWSER_UA,
			'headers'             => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		);

		// Requests back to this site get the same SSL leniency WordPress's own
		// loopback requests do (local and staging sites often have self-signed
		// certificates).
		if ( self::is_internal( $url ) ) {
			$args['sslverify'] = apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		}

		/**
		 * Filters the arguments for each request the link checker makes.
		 *
		 * The plugin always re-applies redirection = 0 and reject_unsafe_urls =
		 * true afterwards, so this can't turn the safety checks off.
		 *
		 * @param array  $args HTTP API arguments.
		 * @param string $url  URL being requested.
		 */
		$args = (array) apply_filters( 'replace_broken_links_ai_request_args', $args, $url );

		$args['redirection']         = 0;
		$args['reject_unsafe_urls']  = true;
		$args['limit_response_size'] = min( self::MAX_BYTES, isset( $args['limit_response_size'] ) ? (int) $args['limit_response_size'] : self::MAX_BYTES );

		return wp_safe_remote_get( $url, $args );
	}

	/**
	 * The absolute URL a redirect points to, or '' if there's no usable one.
	 *
	 * @param array  $response HTTP response.
	 * @param string $base     URL that was requested, for relative Location headers.
	 * @return string
	 */
	private static function location( $response, $base ) {
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( is_array( $location ) ) {
			$location = reset( $location );
		}

		$location = trim( (string) $location );

		if ( '' === $location ) {
			return '';
		}

		$absolute = WP_Http::make_absolute_url( $location, $base );

		return self::is_http_url( $absolute ) ? self::strip_fragment( $absolute ) : '';
	}

	/**
	 * Build a result array.
	 *
	 * @param string $state State constant.
	 * @param int    $code  Final HTTP status (0 when there was no answer).
	 * @param string $final URL at the end of any redirects.
	 * @return array
	 */
	private static function result( $state, $code, $final ) {
		return array(
			'state'   => $state,
			'code'    => (int) $code,
			'final'   => (string) $final,
			'note'    => '',
			'noindex' => false,
			'title'   => '',
			'body'    => '',
		);
	}

	// What the page says --------------------------------------------------------

	/**
	 * Why a page that loads no longer belongs to the site that was linked,
	 * or '' if it looks fine.
	 *
	 * @param string $url   The link.
	 * @param string $final Where it ended up after redirects.
	 * @param string $body  The page (first 64 KB).
	 * @return string Plain-text reason.
	 */
	public static function parking_reason( $url, $final, $body ) {
		$final_host = self::host( $final );

		foreach ( self::MARKETPLACES as $market ) {
			if ( ( $final_host === $market || substr( $final_host, -strlen( '.' . $market ) ) === '.' . $market ) && self::site( $url ) !== self::site( $final ) ) {
				/* translators: %s: domain marketplace, e.g. sedo.com. */
				return sprintf( __( 'It now goes to a domain marketplace (%s): the domain is for sale.', 'replace-broken-links-ai' ), $final_host );
			}
		}

		$lower = strtolower( substr( (string) $body, 0, self::MAX_BYTES ) );

		foreach ( self::PARKING_SIGNS as $sign ) {
			if ( false !== strpos( $lower, $sign ) ) {
				return __( 'It now shows a parked or for-sale domain page.', 'replace-broken-links-ai' );
			}
		}

		$old_path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$new_path = trim( (string) wp_parse_url( $final, PHP_URL_PATH ), '/' );

		if ( self::site( $url ) !== self::site( $final ) && '' !== $old_path && '' === $new_path && '' === (string) wp_parse_url( $final, PHP_URL_QUERY ) ) {
			/* translators: %s: the other website's domain. */
			return sprintf( __( 'It now redirects to the homepage of a different website (%s).', 'replace-broken-links-ai' ), $final_host );
		}

		$title = strtolower( self::title( $body ) );
		$where = strtolower( rawurldecode( $url ) );

		foreach ( self::SPAM_WORDS as $word ) {
			if ( '' !== $title && false !== strpos( $title, $word ) && false === strpos( $where, str_replace( ' ', '', $word ) ) && false === strpos( $where, str_replace( ' ', '-', $word ) ) ) {
				/* translators: %s: the page's title now. */
				return sprintf( __( 'It now shows unrelated content ("%s"); the domain may have been taken over.', 'replace-broken-links-ai' ), Replace_Broken_Links_AI_Content::cut( self::title( $body ), 80 ) );
			}
		}

		return '';
	}

	/**
	 * A page's <title>, as plain text.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function title( $html ) {
		if ( ! preg_match( '/<title[^>]{0,200}>([^<]{0,500})/i', (string) $html, $m ) ) {
			return '';
		}

		return Replace_Broken_Links_AI_Content::text( $m[1] );
	}

	/**
	 * Whether a page asks search engines not to index it (X-Robots-Tag
	 * header, or a robots / googlebot meta tag).
	 *
	 * @param array  $response HTTP response.
	 * @param string $body     Page HTML.
	 * @return bool
	 */
	private static function is_noindex( $response, $body ) {
		$header = wp_remote_retrieve_header( $response, 'x-robots-tag' );
		$header = strtolower( is_array( $header ) ? implode( ',', $header ) : (string) $header );

		if ( preg_match( '/\b(noindex|none)\b/', $header ) ) {
			return true;
		}

		if ( ! preg_match_all( '/<meta\s[^>]{0,500}>/i', (string) $body, $metas ) ) {
			return false;
		}

		foreach ( $metas[0] as $tag ) {
			if ( preg_match( '/\bname\s*=\s*["\']?(?:robots|googlebot)\b/i', $tag ) && preg_match( '/\bcontent\s*=\s*["\'][^"\']{0,200}\b(?:noindex|none)\b/i', $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a host has any DNS records at all (A, AAAA or CNAME).
	 *
	 * @param string $host Host name.
	 * @return bool True when it does, or when DNS can't be asked from here.
	 */
	private static function has_dns( $host ) {
		if ( '' === $host || ! function_exists( 'checkdnsrr' ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		$host = rtrim( $host, '.' ) . '.';

		// This server can't resolve anything at all: say nothing.
		if ( ! checkdnsrr( 'wordpress.org.', 'A' ) ) {
			return true;
		}

		return checkdnsrr( $host, 'A' ) || checkdnsrr( $host, 'AAAA' ) || checkdnsrr( $host, 'CNAME' );
	}

	// URL helpers ------------------------------------------------------------

	/**
	 * Whether a URL is an absolute http(s) URL that's safe to write into a
	 * post: no credentials, no spaces or quotes, a real host, a sane length.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_http_url( $url ) {
		$url = (string) $url;

		if ( strlen( $url ) > 2000 || ! preg_match( '#^https?://#i', $url ) || preg_match( '/[\s"\'<>\\\\`]/', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return esc_url_raw( $url, array( 'http', 'https' ) ) !== '';
	}

	/**
	 * Whether a URL is on a public domain name: not an IP address, not a
	 * single-label or local-only name (localhost, .local, .internal ...).
	 * Search results must pass this; links to this site don't need to.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_public_host( $url ) {
		$host = trim( self::host_of( $url ), '[].' );

		if ( '' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) ) {
			return false;
		}

		return ! preg_match( '/\.(localhost|local|internal|intranet|lan|home|corp|test|invalid|example|arpa)$/', $host );
	}

	/**
	 * A URL's host, lower-cased, as written.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * A URL's host, lower-cased and without "www.", for comparing sites.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( $url ) {
		return preg_replace( '/^www\./', '', self::host_of( $url ) );
	}

	/**
	 * A URL's registrable domain, roughly: "blog.example.co.uk" => "example.co.uk".
	 * Close enough to tell one website from another.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function site( $url ) {
		$labels = explode( '.', self::host( $url ) );
		$count  = count( $labels );

		if ( $count <= 2 ) {
			return implode( '.', $labels );
		}

		// Two-letter country domains with a second level: co.uk, com.au, ac.jp ...
		$keep = 2 === strlen( $labels[ $count - 1 ] ) && in_array( $labels[ $count - 2 ], array( 'co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ne', 'or', 'go', 'gob', 'gouv' ), true ) ? 3 : 2;

		return implode( '.', array_slice( $labels, -$keep ) );
	}

	/**
	 * Whether a URL is on this site (http or https, with or without www).
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function is_internal( $url ) {
		$host = self::host( $url );

		return '' !== $host && self::host( home_url() ) === $host;
	}

	/**
	 * Whether a URL's port is one the checker may use.
	 *
	 * WordPress lets requests to the site's own host through on ports 80,
	 * 443 and 8080 even when that host is a local address. Links back to
	 * this site are only checked on the port the site itself uses, so a link
	 * (or another site's redirect) can't be used to reach some other service
	 * running on the same server.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private static function is_allowed_port( $url ) {
		$host      = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || ! is_string( $home_host ) || strtolower( $host ) !== strtolower( $home_host ) ) {
			return true; // Other hosts: WordPress's own checks apply.
		}

		return self::port( $url ) === self::port( home_url() );
	}

	/**
	 * A URL's port, filling in the scheme's default.
	 *
	 * @param string $url Absolute URL.
	 * @return int
	 */
	private static function port( $url ) {
		$port = wp_parse_url( $url, PHP_URL_PORT );

		if ( $port ) {
			return (int) $port;
		}

		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80;
	}

	/**
	 * A URL without its #fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function strip_fragment( $url ) {
		$hash = strpos( (string) $url, '#' );

		return false === $hash ? (string) $url : substr( $url, 0, $hash );
	}

	/**
	 * Two URLs that point at the same page (ignoring http/https, "www.",
	 * a trailing slash and the fragment).
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_page( $a, $b ) {
		$normalize = function ( $url ) {
			$url = strtolower( self::strip_fragment( (string) $url ) );
			$url = preg_replace( '#^https?://(www\.)?#', '', $url );

			return untrailingslashit( (string) $url );
		};

		return $normalize( $a ) === $normalize( $b );
	}

	/**
	 * Whether a URL is on one of a list of domains or domain endings: "example.com"
	 * matches it and its subdomains; ".gov" matches any .gov site, including
	 * country ones like gov.uk and service.gov.au.
	 *
	 * @param string   $url     URL.
	 * @param string[] $entries Domains or endings (lower case).
	 * @return bool
	 */
	public static function host_matches( $url, array $entries ) {
		$host = self::host( $url );

		if ( '' === $host ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '' === $entry ) {
				continue;
			}

			// An ending matches the last label or the one before it (.gov.uk, .edu.au).
			if ( '.' === $entry[0] ) {
				if ( in_array( substr( $entry, 1 ), array_slice( explode( '.', $host ), -2 ), true ) ) {
					return true;
				}
				continue;
			}

			if ( $host === $entry || substr( $host, -strlen( '.' . $entry ) ) === '.' . $entry ) {
				return true;
			}
		}

		return false;
	}
}
