<?php
/**
 * Encrypts API keys before they are stored in the options table.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption (libsodium secretbox) for secrets at rest.
 *
 * The key comes from ORBIT_GF_ENCRYPTION_KEY when defined, otherwise from the
 * site's auth salt. A database dump on its own therefore never reveals an API
 * key. Changing the salt makes stored keys unreadable; the connections page then
 * asks for the key to be entered again rather than failing silently.
 */
final class Secrets {

	const PREFIX = 'orbitgf1:';

	/**
	 * The secret the encryption key is derived from.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Build with an explicit secret (tests) or the site's own.
	 *
	 * @param string|null $secret Secret to derive the key from.
	 */
	public function __construct( ?string $secret = null ) {
		if ( null === $secret ) {
			$secret = defined( 'ORBIT_GF_ENCRYPTION_KEY' ) ? (string) ORBIT_GF_ENCRYPTION_KEY : wp_salt( 'auth' );
		}
		$this->secret = $secret;
	}

	/**
	 * Encrypt a value for storage.
	 *
	 * @param string $plain Value to encrypt.
	 * @return string Prefixed, base64-encoded nonce and ciphertext.
	 */
	public function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->key() );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext, not obfuscation.
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Value produced by encrypt().
	 * @return string|null The plain value, or null when it cannot be decrypted.
	 */
	public function decrypt( string $stored ): ?string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext, not obfuscation.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );

		return false === $plain ? null : $plain;
	}

	/**
	 * Show only the last four characters of a key, for the admin screen.
	 *
	 * @param string $key Plain API key.
	 * @return string Masked key.
	 */
	public static function mask( string $key ): string {
		return strlen( $key ) <= 4 ? '••••' : '••••••••' . substr( $key, -4 );
	}

	/**
	 * Derive a 32-byte key from the secret.
	 */
	private function key(): string {
		return hash( 'sha256', 'orbit-gf|' . $this->secret, true );
	}
}
