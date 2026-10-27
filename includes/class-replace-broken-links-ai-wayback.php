<?php
/**
 * What a dead page used to say, from the Internet Archive's Wayback Machine.
 *
 * The CDX API lists the copies saved before the day the link started
 * failing (the availability API is the fallback), then the raw copy of the
 * page (the "id_" form, without the Wayback toolbar) is read for its title,
 * description and opening text.
 * "What was this page about" makes a much better search than guessing from
 * the address. The copy's address is also offered as a last-resort
 * replacement when no live page matches.
 *
 * Requests go to two fixed hosts (archive.org, web.archive.org) through
 * wp_safe_remote_get(), with a size cap; nothing about the site is sent
 * except the dead link itself, which is public anyway.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wayback Machine client.
 */
final class Replace_Broken_Links_AI_Wayback {

	/**
	 * CDX API (captures of a URL), and the availability API as a fallback.
	 */
	const CDX       = 'https://web.archive.org/cdx/search/cdx';
	const AVAILABLE = 'https://archive.org/wayback/available';

	/**
	 * Most bytes read from an archived page.
	 */
	const MAX_BYTES = 300000;

	/**
	 * The last good copy of a page saved before a time.
	 *
	 * Asks the CDX API for the latest captures that were a 200 before the day
	 * the link started failing (falling back to the availability API if CDX
	 * doesn't answer), then reads them newest first, skipping copies that
	 * are empty or show a parked domain.
	 *
	 * @param string $url    Dead URL.
	 * @param int    $before Unix time the link started failing (0 for now).
	 * @return array{url: string, timestamp: string, date: string, title: string, summary: string}|null
	 */
	public static function snapshot( $url, $before ) {
		$before = ( $before ? (int) $before : time() ) - DAY_IN_SECONDS;
		$stamps = self::captures( $url, gmdate( 'YmdHis', $before ) );

		if ( ! $stamps ) {
			$closest = self::closest( $url, gmdate( 'YmdHis', $before ) );
			$stamps  = '' !== $closest ? array( $closest ) : array();
		}

		foreach ( array_slice( $stamps, 0, 3 ) as $stamp ) {
			$page = self::read( $url, $stamp );

			if ( $page ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Timestamps of the latest captures of a URL that answered 200, saved no
	 * later than a time, newest first.
	 *
	 * @param string $url URL.
	 * @param string $to  YmdHis.
	 * @return string[]
	 */
	private static function captures( $url, $to ) {
		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					'url'    => rawurlencode( $url ),
					'output' => 'json',
					'filter' => 'statuscode:200',
					'to'     => $to,
					'limit'  => '-5',
					'fl'     => 'timestamp',
				),
				self::CDX
			),
			array(
				'timeout'             => 15,
				'limit_response_size' => 65536,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$rows   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$stamps = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$stamp = is_array( $row ) && isset( $row[0] ) ? (string) $row[0] : '';

			if ( preg_match( '/^\d{14}$/', $stamp ) ) {
				$stamps[] = $stamp;
			}
		}

		return array_reverse( array_unique( $stamps ) );
	}

	/**
	 * The timestamp of the saved copy closest to a time, if it was a 200.
	 *
	 * @param string $url       URL.
	 * @param string $timestamp YmdHis.
	 * @return string YmdHis, or '' when there's none.
	 */
	private static function closest( $url, $timestamp ) {
		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					'url'       => rawurlencode( $url ),
					'timestamp' => $timestamp,
				),
				self::AVAILABLE
			),
			array(
				'timeout'             => 15,
				'limit_response_size' => 65536,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$json    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$closest = isset( $json['archived_snapshots']['closest'] ) && is_array( $json['archived_snapshots']['closest'] ) ? $json['archived_snapshots']['closest'] : array();
		$stamp   = isset( $closest['timestamp'] ) ? (string) $closest['timestamp'] : '';

		if ( empty( $closest['available'] ) || ! isset( $closest['status'] ) || '200' !== (string) $closest['status'] || ! preg_match( '/^\d{14}$/', $stamp ) ) {
			return '';
		}

		return $stamp;
	}

	/**
	 * Read a saved copy's title and opening text.
	 *
	 * @param string $url       Original URL.
	 * @param string $timestamp YmdHis.
	 * @return array|null Null if it can't be read or isn't a real page.
	 */
	private static function read( $url, $timestamp ) {
		$response = wp_safe_remote_get(
			'https://web.archive.org/web/' . $timestamp . 'id_/' . $url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; ReplaceBrokenLinksAI/' . REPLACE_BROKEN_LINKS_AI_VERSION . ')',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$html = (string) wp_remote_retrieve_body( $response );

		if ( '' === $html || Replace_Broken_Links_AI_Checker::parking_reason( $url, $url, $html ) ) {
			return null;
		}

		$title   = Replace_Broken_Links_AI_Checker::title( $html );
		$summary = self::summary( $html );

		if ( '' === $title && '' === $summary ) {
			return null;
		}

		return array(
			'url'       => 'https://web.archive.org/web/' . $timestamp . '/' . $url,
			'timestamp' => $timestamp,
			'date'      => substr( $timestamp, 0, 4 ) . '-' . substr( $timestamp, 4, 2 ) . '-' . substr( $timestamp, 6, 2 ),
			'title'     => Replace_Broken_Links_AI_Content::cut( $title, 200 ),
			'summary'   => $summary,
		);
	}

	/**
	 * A short summary of a page: its description, its main heading and the
	 * first few real paragraphs.
	 *
	 * Bounded patterns over a capped string, so it stays fast on any page.
	 *
	 * @param string $html Page HTML.
	 * @return string Plain text, at most about 700 characters.
	 */
	private static function summary( $html ) {
		$parts = array();

		if ( preg_match_all( '/<meta\s[^>]{0,1000}>/i', $html, $metas ) ) {
			foreach ( $metas[0] as $tag ) {
				if ( preg_match( '/\b(?:name|property)\s*=\s*["\']?(?:description|og:description)["\']?/i', $tag ) && preg_match( '/\bcontent\s*=\s*(["\'])(.{0,600}?)\1/is', $tag, $m ) ) {
					$parts[] = Replace_Broken_Links_AI_Content::text( $m[2] );
					break;
				}
			}
		}

		if ( preg_match( '/<h1\b[^>]{0,500}>(.{0,500}?)<\/h1>/is', $html, $m ) ) {
			$parts[] = Replace_Broken_Links_AI_Content::text( $m[1] );
		}

		if ( preg_match_all( '/<p\b[^>]{0,500}>(.{0,3000}?)<\/p>/is', $html, $paragraphs ) ) {
			$found = 0;
			foreach ( $paragraphs[1] as $paragraph ) {
				$text = Replace_Broken_Links_AI_Content::text( $paragraph );

				// Skip cookie notices, bylines and other short bits.
				if ( strlen( $text ) < 80 ) {
					continue;
				}

				$parts[] = $text;
				if ( ++$found >= 3 ) {
					break;
				}
			}
		}

		// Descriptions are often the first paragraph cut short: keep only the
		// longer of two pieces that start the same way.
		$kept = array();
		foreach ( array_filter( $parts ) as $part ) {
			$start = strtolower( substr( $part, 0, 50 ) );

			foreach ( $kept as $i => $earlier ) {
				if ( false !== strpos( strtolower( $part ), strtolower( substr( $earlier, 0, 50 ) ) ) ) {
					unset( $kept[ $i ] );
				} elseif ( false !== strpos( strtolower( $earlier ), $start ) ) {
					continue 2;
				}
			}

			$kept[] = $part;
		}

		return Replace_Broken_Links_AI_Content::cut( implode( ' ', $kept ), 700 );
	}
}
