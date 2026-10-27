<?php
/**
 * Counts what lookups use each day: lookups, AI requests and tokens,
 * searches and the estimated cost (in millionths of a dollar). The daily
 * limits are checked against these counts, and the Overview shows them so
 * the API bills hold no surprises.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily usage counters.
 */
final class Replace_Broken_Links_AI_Usage {

	/**
	 * Option holding the counts: Y-m-d => counts.
	 */
	const OPTION = 'replace_broken_links_ai_usage';

	/**
	 * Days of counts to keep.
	 */
	const KEEP_DAYS = 90;

	/**
	 * What's counted.
	 */
	const FIELDS = array( 'lookups', 'ai_calls', 'input_tokens', 'output_tokens', 'searches', 'replaced', 'cost' );

	/**
	 * Add to today's counts.
	 *
	 * @param array<string,int> $add Field => amount.
	 * @return void
	 */
	public static function add( array $add ) {
		$all = self::all();
		$day = self::day();

		if ( ! isset( $all[ $day ] ) ) {
			$all[ $day ] = array_fill_keys( self::FIELDS, 0 );
		}

		foreach ( self::FIELDS as $field ) {
			if ( isset( $add[ $field ] ) ) {
				$all[ $day ][ $field ] = (int) ( isset( $all[ $day ][ $field ] ) ? $all[ $day ][ $field ] : 0 ) + (int) $add[ $field ];
			}
		}

		// Keep the last KEEP_DAYS days only.
		$oldest = gmdate( 'Y-m-d', strtotime( $day . ' 00:00:00 UTC' ) - self::KEEP_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $all ) as $key ) {
			if ( ! is_string( $key ) || $key < $oldest ) {
				unset( $all[ $key ] );
			}
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Lookups started today (in the site's time zone).
	 *
	 * @return int
	 */
	public static function lookups_today() {
		$today = self::totals( 1 );

		return $today['lookups'];
	}

	/**
	 * Estimated spending today, in millionths of a dollar.
	 *
	 * @return int
	 */
	public static function spent_today() {
		$today = self::totals( 1 );

		return $today['cost'];
	}

	/**
	 * Counts summed over the last few days, today included.
	 *
	 * @param int $days Days.
	 * @return array<string,int>
	 */
	public static function totals( $days ) {
		$all    = self::all();
		$totals = array_fill_keys( self::FIELDS, 0 );
		$today  = strtotime( self::day() . ' 00:00:00 UTC' );

		for ( $i = 0; $i < max( 1, (int) $days ); $i++ ) {
			$key = gmdate( 'Y-m-d', $today - $i * DAY_IN_SECONDS );

			if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
				foreach ( self::FIELDS as $field ) {
					$totals[ $field ] += isset( $all[ $key ][ $field ] ) ? (int) $all[ $key ][ $field ] : 0;
				}
			}
		}

		return $totals;
	}

	/**
	 * Today's date in the site's time zone.
	 *
	 * @return string Y-m-d.
	 */
	private static function day() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Every stored day, read fresh (a background batch may have just written).
	 *
	 * @return array
	 */
	private static function all() {
		wp_cache_delete( self::OPTION, 'options' );

		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}
}
