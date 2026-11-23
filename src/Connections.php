<?php
/**
 * Stores the Orbit accounts (API keys) this site can send entries to.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms;

use Orbit\GravityForms\Support\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * A connection is a named Orbit API key. One site can hold several, so different
 * forms can send to different Orbit accounts.
 */
final class Connections {

	const OPTION = 'orbit_gf_connections';

	/**
	 * Encrypts keys at rest.
	 *
	 * @var Secrets
	 */
	private $secrets;

	/**
	 * Build with the site's secrets helper unless one is given.
	 *
	 * @param Secrets|null $secrets Encryption helper.
	 */
	public function __construct( ?Secrets $secrets = null ) {
		$this->secrets = $secrets ?? new Secrets();
	}

	/**
	 * Every stored connection, keyed by connection ID, without decrypted keys.
	 *
	 * @return array<string, array{name: string, key: string, added: int}>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Connection names keyed by ID, for select fields.
	 *
	 * @return array<string, string>
	 */
	public function names(): array {
		$names = array();
		foreach ( $this->all() as $id => $connection ) {
			$names[ $id ] = (string) ( $connection['name'] ?? $id );
		}
		return $names;
	}

	/**
	 * Whether a connection exists.
	 *
	 * @param string $id Connection ID.
	 */
	public function exists( string $id ): bool {
		return isset( $this->all()[ $id ] );
	}

	/**
	 * The plain API key for a connection.
	 *
	 * @param string $id Connection ID.
	 * @return string|null The key, or null when missing or no longer decryptable.
	 */
	public function api_key( string $id ): ?string {
		$all = $this->all();
		if ( empty( $all[ $id ]['key'] ) ) {
			return null;
		}
		return $this->secrets->decrypt( (string) $all[ $id ]['key'] );
	}

	/**
	 * Masked key for display, or null when the key cannot be read.
	 *
	 * @param string $id Connection ID.
	 */
	public function masked_key( string $id ): ?string {
		$key = $this->api_key( $id );
		return null === $key ? null : Secrets::mask( $key );
	}

	/**
	 * Add a connection or replace an existing one's name and key.
	 *
	 * @param string      $name    Display name.
	 * @param string|null $api_key Plain key; null keeps the existing key.
	 * @param string|null $id      Existing connection ID to update.
	 * @return string The connection ID.
	 */
	public function save( string $name, ?string $api_key, ?string $id = null ): string {
		$all = $this->all();
		$id  = $id ?? 'conn_' . strtolower( wp_generate_password( 10, false, false ) );

		$connection         = $all[ $id ] ?? array( 'added' => time() );
		$connection['name'] = $name;
		if ( null !== $api_key ) {
			$connection['key'] = $this->secrets->encrypt( $api_key );
		}
		$all[ $id ] = $connection;

		update_option( self::OPTION, $all, false );
		return $id;
	}

	/**
	 * Remove a connection. Feeds that used it stop sending until another is chosen.
	 *
	 * @param string $id Connection ID.
	 */
	public function delete( string $id ): void {
		$all = $this->all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		delete_transient( Api\Client::cache_key( $id ) );
	}

	/**
	 * Whether a string looks like an Orbit API key (a GUID).
	 *
	 * @param string $key Candidate key.
	 */
	public static function is_valid_key_format( string $key ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key );
	}
}
