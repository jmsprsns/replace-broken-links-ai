<?php
/**
 * Encrypts the OpenAI, Anthropic and Serper.dev API keys before they're stored.
 *
 * Uses libsodium's secretbox (XSalsa20-Poly1305), which every WordPress since
 * 5.2 provides through sodium_compat even when the PHP extension is missing.
 * The key comes from the site's AUTH_KEY and AUTH_SALT, so a database dump or
 * backup on its own doesn't give away the API keys. If the salts change,
 * the stored keys can't be read and have to be entered again.
 *
 * @package Replace_Broken_Links_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small encryption helper.
 */
final class Replace_Broken_Links_AI_Crypto {

	/**
	 * Marks an encrypted value (and its format version).
	 */
	const PREFIX = 'rblai1:';

	/**
	 * Encrypt a string for storage.
	 *
	 * @param string $plain Plain text.
	 * @return string Encrypted value, or '' for an empty input or when encryption isn't possible.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain || ! self::available() ) {
			return '';
		}

		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		} catch ( Exception $e ) {
			return '';
		}

		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext.
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param mixed $stored Value from {@see encrypt()}.
	 * @return string Plain text, or '' if it's empty, not ours, or can't be read.
	 */
	public static function decrypt( $stored ) {
		if ( ! self::is_encrypted( $stored ) || ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( Exception $e ) {
			return '';
		}

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a value looks like one of ours.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Whether secretbox is there (always, on WordPress 5.2+).
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * The last four characters of a key, for "Saved key ending in ...".
	 *
	 * @param string $key Plain key.
	 * @return string
	 */
	public static function hint( $key ) {
		$key = (string) $key;

		return strlen( $key ) > 12 ? substr( $key, -4 ) : '';
	}

	/**
	 * 32-byte key derived from the site's auth salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash_hmac( 'sha256', 'replace-broken-links-ai', wp_salt( 'auth' ), true );
	}
}
