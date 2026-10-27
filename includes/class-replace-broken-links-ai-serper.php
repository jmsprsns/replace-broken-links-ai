<?php
/**
 * Google search results from Serper.dev.
 *
 * One HTTPS request to a fixed host (google.serper.dev) per search, with the
 * key in a header. Only the search words go out. The results that come back
 * are untrusted text from other websites: every URL has to pass
 * Replace_Broken_Links_AI_Checker::is_http_url(), and titles and snippets
 * are reduced to short plain text before they go anywhere.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serper.dev client.
 */
final class Replace_Broken_Links_AI_Serper {

	/**
	 * Search endpoint.
	 */
	const ENDPOINT = 'https://google.serper.dev/search';

	/**
	 * Results to ask for: Google's first page.
	 */
	const RESULTS = 10;

	/**
	 * Estimated cost of one search, in millionths of a dollar ($1 per 1,000
	 * searches, Serper.dev's smallest paid pack; bigger packs cost less).
	 *
	 * @return int
	 */
	public static function cost_micros() {
		/**
		 * Filters the estimated cost of one Serper.dev search, in millionths of a dollar.
		 *
		 * @param int $micros Default 1000 ($0.001).
		 */
		return max( 0, (int) apply_filters( 'replace_broken_links_ai_search_cost', 1000 ) );
	}

	/**
	 * Run a Google search.
	 *
	 * @param string $key   Serper.dev API key.
	 * @param string $query Search words.
	 * @param string $gl    Two-letter country code, or '' for none.
	 * @param string $hl    Two-letter language code.
	 * @return array{results: array[], credits: int}|WP_Error results are
	 *         arrays of position, title, link, snippet and date.
	 */
	public static function search( $key, $query, $gl, $hl ) {
		if ( '' === (string) $key ) {
			return self::error( 'rblai_search_key', __( 'No Serper.dev API key is set.', 'replace-broken-links-ai' ), 0, true );
		}

		$body = array(
			'q'   => (string) $query,
			'num' => self::RESULTS,
		);

		if ( preg_match( '/^[a-z]{2}$/', (string) $gl ) ) {
			$body['gl'] = $gl;
		}

		if ( preg_match( '/^[a-z]{2}(-[a-z]{2,4})?$/i', (string) $hl ) ) {
			$body['hl'] = strtolower( $hl );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 30,
				'headers'     => array(
					'X-API-KEY'    => $key,
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error(
				'rblai_search_network',
				/* translators: %s: error message from the HTTP request. */
				sprintf( __( 'Couldn\'t reach Serper.dev: %s', 'replace-broken-links-ai' ), Replace_Broken_Links_AI_AI::clean_message( $response->get_error_message() ) ),
				0,
				false
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$json = is_array( $json ) ? $json : array();

		if ( 200 !== $code ) {
			$detail = isset( $json['message'] ) && is_string( $json['message'] ) ? Replace_Broken_Links_AI_AI::clean_message( $json['message'] ) : '';

			if ( 401 === $code || 403 === $code ) {
				$message = __( 'Serper.dev rejected the API key. Check it under Connections.', 'replace-broken-links-ai' );
				$fatal   = true;
			} elseif ( 402 === $code || preg_match( '/credit|balance|quota|payment/i', $detail ) ) {
				$message = __( 'Your Serper.dev account is out of search credits.', 'replace-broken-links-ai' );
				$fatal   = true;
			} elseif ( 429 === $code ) {
				$message = __( 'Serper.dev is rate limiting searches. The plugin will try again later.', 'replace-broken-links-ai' );
				$fatal   = false;
			} else {
				/* translators: %d: HTTP status. */
				$message = sprintf( __( 'Serper.dev couldn\'t run the search (HTTP %d).', 'replace-broken-links-ai' ), $code );
				$fatal   = false;
			}

			if ( '' !== $detail ) {
				$message .= ' (' . $detail . ')';
			}

			return self::error( 'rblai_search_' . $code, $message, $code, $fatal );
		}

		$results = array();
		$organic = isset( $json['organic'] ) && is_array( $json['organic'] ) ? $json['organic'] : array();

		foreach ( $organic as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['link'] ) || ! is_string( $item['link'] ) ) {
				continue;
			}

			$link = trim( $item['link'] );

			// Search results are other people's text: public web pages only.
			if ( ! Replace_Broken_Links_AI_Checker::is_http_url( $link ) || ! Replace_Broken_Links_AI_Checker::is_public_host( $link ) ) {
				continue;
			}

			$results[] = array(
				'position' => count( $results ) + 1,
				'title'    => self::plain( isset( $item['title'] ) ? $item['title'] : '', 160 ),
				'link'     => esc_url_raw( $link, array( 'http', 'https' ) ),
				'snippet'  => self::plain( isset( $item['snippet'] ) ? $item['snippet'] : '', 320 ),
				'date'     => self::plain( isset( $item['date'] ) ? $item['date'] : '', 40 ),
			);

			if ( count( $results ) >= self::RESULTS ) {
				break;
			}
		}

		return array(
			'results' => $results,
			'credits' => isset( $json['credits'] ) ? (int) $json['credits'] : 1,
		);
	}

	/**
	 * Short plain text from a result field.
	 *
	 * @param mixed $value Field value.
	 * @param int   $chars Most characters.
	 * @return string
	 */
	private static function plain( $value, $chars ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		// Plain text from here on; it's escaped wherever it's shown.
		return Replace_Broken_Links_AI_Content::cut( Replace_Broken_Links_AI_Content::text( (string) $value ), $chars );
	}

	/**
	 * Build an error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param bool   $fatal   Whether retrying can't help.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status, $fatal ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => (int) $status,
				'fatal'  => (bool) $fatal,
			)
		);
	}
}
