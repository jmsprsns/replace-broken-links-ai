<?php
/**
 * Finds links in post content, reads the text around them, and swaps them
 * for new ones.
 *
 * Links are the href of every <a> tag. Replacing one changes that href and,
 * for blocks that keep a copy of the URL in their attributes (the JSON in
 * the <!-- wp:... --> comment), that copy too, so the block editor doesn't
 * flag the block as changed outside the editor. Nothing else in the post
 * changes: not the link text, not the other attributes.
 *
 * Each replacement reports the exact spots it changed, so an undo can put
 * back those spots only, and not other links that happen to point at the
 * same page.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content helper.
 */
final class Replace_Broken_Links_AI_Content {

	/**
	 * The href of every <a> tag: 1 is everything before the value, 2 the quote, 3 the value.
	 */
	const HREF = '/(<a\s[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is';

	/**
	 * A block comment's attributes, as WordPress's own block parser reads them.
	 */
	const BLOCK = '/<!--\s+wp:(?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*\s+(\{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?\})\s+\/?-->/s';

	/**
	 * Every distinct link in a piece of HTML, as written (entities decoded).
	 *
	 * @param string $html Post content.
	 * @return string[]
	 */
	public static function links( $html ) {
		if ( ! preg_match_all( self::HREF, (string) $html, $matches ) ) {
			return array();
		}

		$links = array();
		foreach ( $matches[3] as $raw ) {
			$link = self::decode( $raw );
			if ( '' !== $link ) {
				$links[ $link ] = true;
			}
		}

		return array_keys( $links );
	}

	/**
	 * Characters of text kept on each side of a link for its context.
	 */
	const CONTEXT_CHARS = 280;

	/**
	 * Every distinct link in a piece of HTML with its link text and the text
	 * around it (from its first appearance), for the AI to read.
	 *
	 * @param string $html Post content.
	 * @return array<string,array{anchor: string, context: string}> Link (as links() returns it) => details.
	 */
	public static function occurrences( $html ) {
		$html = (string) $html;

		if ( ! preg_match_all( self::HREF, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$found = array();

		foreach ( $matches[0] as $i => $match ) {
			$link = self::decode( $matches[3][ $i ][0] );

			if ( '' === $link || isset( $found[ $link ] ) ) {
				continue;
			}

			$start   = (int) $match[1];
			$tag_end = strpos( $html, '>', $start + strlen( $match[0] ) );
			$close   = false === $tag_end ? false : stripos( $html, '</a>', $tag_end );
			$anchor  = '';
			$end     = $start + strlen( $match[0] );

			// Link text, unless the tag never closes nearby (broken markup).
			if ( false !== $close && $close - $tag_end < 3000 ) {
				$anchor = self::text( substr( $html, $tag_end + 1, $close - $tag_end - 1 ) );
				$end    = $close + 4;
			}

			$found[ $link ] = array(
				'anchor'  => self::cut( $anchor, 200 ),
				'context' => self::context( $html, $start, $end, $anchor ),
			);
		}

		return $found;
	}

	/**
	 * The text around a link: the end of what comes before it, the link text
	 * in [brackets], then the start of what follows.
	 *
	 * @param string $html   Post content.
	 * @param int    $start  Offset of the <a> tag.
	 * @param int    $end    Offset just after the closing </a>.
	 * @param string $anchor Link text.
	 * @return string Plain text.
	 */
	private static function context( $html, $start, $end, $anchor ) {
		$window = 1500;
		$before = substr( $html, max( 0, $start - $window ), min( $window, $start ) );
		$after  = substr( $html, $end, $window );

		// The window can start halfway through a tag: drop that piece.
		$gt = strpos( $before, '>' );
		$lt = strpos( $before, '<' );
		if ( false !== $gt && ( false === $lt || $gt < $lt ) ) {
			$before = substr( $before, $gt + 1 );
		}

		$before = self::text( $before );
		$after  = self::text( $after );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $before ) > self::CONTEXT_CHARS ) {
			$before = mb_substr( $before, -self::CONTEXT_CHARS );
			$space  = strpos( $before, ' ' );
			$before = '…' . ( false === $space ? $before : substr( $before, $space + 1 ) );
		}

		$after = self::cut( $after, self::CONTEXT_CHARS );
		$space = '' !== $after && preg_match( '/^[\p{L}\p{N}(“"\'‘]/u', $after ) ? ' ' : '';
		$text  = trim( $before . ' [' . $anchor . ']' . $space . $after );

		return '[]' === $text ? '' : $text;
	}

	/**
	 * Plain text from a piece of HTML: no tags, comments or shortcodes, entities
	 * decoded, whitespace collapsed.
	 *
	 * Only PHP's strip_tags() and bounded patterns, so it stays fast on any input.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function text( $html ) {
		$text = strip_tags( (string) $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Linear time on any input; wp_strip_all_tags() isn't (its regex is slow on unclosed <style> tags).
		$text = (string) preg_replace( '/\[\/?[a-z][a-z0-9_-]{0,50}(?:\s[^\]\[]{0,300})?\]/i', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Shorten text to a length, at a word boundary, with an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Most characters.
	 * @return string
	 */
	public static function cut( $text, $chars ) {
		$text = trim( (string) $text );

		if ( ! function_exists( 'mb_strlen' ) || mb_strlen( $text ) <= $chars ) {
			return $text;
		}

		$text  = mb_substr( $text, 0, $chars );
		$space = strrpos( $text, ' ' );

		return ( false !== $space && $space > $chars / 2 ? substr( $text, 0, $space ) : $text ) . '…';
	}

	/**
	 * Replace links, everywhere they appear.
	 *
	 * @param string               $html   Post content.
	 * @param array<string,string> $map    Old link (as links() returns it) => new link.
	 * @param array                $places Set to where each old link was replaced: old link =>
	 *                                     list of places, each array( 'a', n ) for the nth link
	 *                                     in the content or array( 'b', n, path ) for an attribute
	 *                                     of the nth block comment with attributes.
	 * @return string The content, unchanged if none of the links are in it or
	 *                it couldn't be processed safely.
	 */
	public static function replace( $html, array $map, &$places = array() ) {
		$original = (string) $html;
		$places   = array();

		if ( ! $map ) {
			return $original;
		}

		$index = -1;
		$html  = preg_replace_callback(
			self::HREF,
			function ( $m ) use ( $map, &$index, &$places ) {
				$index++;
				$link = self::decode( $m[3] );

				if ( ! isset( $map[ $link ] ) ) {
					return $m[0];
				}

				$places[ $link ][] = array( 'a', $index );

				return $m[1] . $m[2] . esc_attr( $map[ $link ] ) . $m[2];
			},
			$original
		);

		// A regex error (e.g. the backtrack limit on a huge post) returns null:
		// leave the post alone rather than risk saving it empty.
		if ( ! is_string( $html ) ) {
			$places = array();
			return $original;
		}

		$html = self::edit_blocks(
			$html,
			function ( $attrs, $block ) use ( $map, &$places ) {
				return self::walk(
					$attrs,
					array(),
					function ( $value, $path ) use ( $map, $block, &$places ) {
						if ( ! isset( $map[ $value ] ) ) {
							return $value;
						}

						$places[ $value ][] = array( 'b', $block, $path );

						return $map[ $value ];
					}
				);
			}
		);

		if ( ! is_string( $html ) ) {
			$places = array();
			return $original;
		}

		return $html;
	}

	/**
	 * Put a replaced link back: only in the places replace() reported, and
	 * only where the link is still the new one.
	 *
	 * @param string $html   Post content.
	 * @param string $new    The link as it is now.
	 * @param string $old    The link to put back.
	 * @param array  $places Places from replace().
	 * @return string The content, unchanged if nothing matched.
	 */
	public static function revert( $html, $new, $old, array $places ) {
		$original = (string) $html;
		$links    = array();
		$blocks   = array();

		foreach ( $places as $place ) {
			if ( ! is_array( $place ) || ! isset( $place[0], $place[1] ) ) {
				continue;
			}

			if ( 'a' === $place[0] ) {
				$links[ (int) $place[1] ] = true;
			} elseif ( 'b' === $place[0] && isset( $place[2] ) && is_array( $place[2] ) ) {
				$blocks[ (int) $place[1] ][] = $place[2];
			}
		}

		$index = -1;
		$html  = preg_replace_callback(
			self::HREF,
			function ( $m ) use ( $new, $old, $links, &$index ) {
				$index++;

				if ( ! isset( $links[ $index ] ) || self::decode( $m[3] ) !== $new ) {
					return $m[0];
				}

				return $m[1] . $m[2] . esc_attr( $old ) . $m[2];
			},
			$original
		);

		if ( ! is_string( $html ) ) {
			return $original;
		}

		if ( ! $blocks ) {
			return $html;
		}

		$html = self::edit_blocks(
			$html,
			function ( $attrs, $block ) use ( $new, $old, $blocks ) {
				if ( ! isset( $blocks[ $block ] ) ) {
					return $attrs;
				}

				return self::walk(
					$attrs,
					array(),
					function ( $value, $path ) use ( $new, $old, $blocks, $block ) {
						return $value === $new && in_array( $path, $blocks[ $block ], true ) ? $old : $value;
					}
				);
			}
		);

		return is_string( $html ) ? $html : $original;
	}

	/**
	 * Run a callback over the attributes of each block comment that has
	 * them, re-encoding only the ones it changes.
	 *
	 * @param string   $html Post content.
	 * @param callable $edit Gets ( array $attrs, int $block ), returns the attributes.
	 * @return string|null Null on a regex error.
	 */
	private static function edit_blocks( $html, $edit ) {
		if ( false === strpos( $html, '<!-- wp:' ) ) {
			return $html;
		}

		$index = -1;

		return preg_replace_callback(
			self::BLOCK,
			function ( $m ) use ( $edit, &$index ) {
				$index++;
				$attrs = json_decode( $m[1], true );

				if ( ! is_array( $attrs ) ) {
					return $m[0];
				}

				$edited = call_user_func( $edit, $attrs, $index );

				if ( $edited === $attrs ) {
					return $m[0];
				}

				// Encoded the way the block editor encodes them, so the comment
				// reads back exactly as if the editor had saved it.
				$json = function_exists( 'serialize_block_attributes' ) ? serialize_block_attributes( $edited ) : wp_json_encode( $edited, JSON_UNESCAPED_SLASHES );

				return str_replace( $m[1], $json, $m[0] );
			},
			$html
		);
	}

	/**
	 * Pass every string in a nested array through a callback, with its key path.
	 *
	 * @param array    $attrs    Attributes.
	 * @param array    $path     Keys leading to $attrs.
	 * @param callable $callback Gets ( string $value, array $path ), returns the new value.
	 * @return array
	 */
	private static function walk( array $attrs, array $path, $callback ) {
		foreach ( $attrs as $key => $value ) {
			$here = array_merge( $path, array( $key ) );

			if ( is_array( $value ) ) {
				$attrs[ $key ] = self::walk( $value, $here, $callback );
			} elseif ( is_string( $value ) ) {
				$attrs[ $key ] = call_user_func( $callback, $value, $here );
			}
		}

		return $attrs;
	}

	/**
	 * An href value as the browser reads it: entities decoded, spaces trimmed.
	 *
	 * @param string $raw Attribute value from the HTML.
	 * @return string
	 */
	public static function decode( $raw ) {
		return trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
