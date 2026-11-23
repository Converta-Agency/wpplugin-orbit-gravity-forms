<?php
/**
 * HTTP client for the Orbit REST API.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Api;

use Orbit\GravityForms\Mapping\FieldCatalogue;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Orbit with an API key sent in the X-Api-Key header.
 *
 * The legacy KulaHub plugin put the key in the query string, where it ended up
 * in web server and proxy logs. Orbit reads it from the header only.
 */
final class Client {

	const DEFAULT_BASE_URL = 'https://api.orbitcrm.co.uk';

	/**
	 * How long account lists (form types, sources, categories) are cached.
	 */
	const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Page size for list endpoints; Orbit caps it at 100.
	 */
	const PAGE_SIZE = 100;

	/**
	 * Upper bound on pages fetched for one list, so a misbehaving response cannot loop.
	 */
	const MAX_PAGES = 20;

	/**
	 * Plain API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Build a client for one API key.
	 *
	 * @param string $api_key Plain Orbit API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * The Orbit API base URL: ORBIT_GF_API_URL when defined, else production.
	 */
	public static function base_url(): string {
		$url = defined( 'ORBIT_GF_API_URL' ) ? (string) ORBIT_GF_API_URL : self::DEFAULT_BASE_URL;

		/**
		 * Filters the Orbit API base URL, for example to point a staging site at a test API.
		 *
		 * @param string $url Base URL without a trailing slash.
		 */
		return untrailingslashit( (string) apply_filters( 'orbit_gf_api_url', $url ) );
	}

	/**
	 * Transient key for a connection's cached account lists.
	 *
	 * @param string $connection_id Connection ID.
	 */
	public static function cache_key( string $connection_id ): string {
		return 'orbit_gf_catalogue_' . md5( $connection_id );
	}

	/**
	 * Check the key is accepted by Orbit.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$response = $this->request( 'GET', '/api/FormTypes', array( 'xtop' => 1 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Create a form (and its contact) in Orbit.
	 *
	 * @param array<string, mixed> $payload Body for POST /api/Forms.
	 * @return array{status: int, body: mixed}|WP_Error
	 */
	public function create_form( array $payload ) {
		return $this->request( 'POST', '/api/Forms', array(), $payload );
	}

	/**
	 * Form types, sources and categories for the account, cached briefly.
	 *
	 * @param string $connection_id Connection ID the cache belongs to.
	 * @param bool   $refresh       Bypass the cache.
	 * @return FieldCatalogue|WP_Error
	 */
	public function catalogue( string $connection_id, bool $refresh = false ) {
		$cache_key = self::cache_key( $connection_id );
		$cached    = $refresh ? false : get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return FieldCatalogue::from_array( $cached );
		}

		$form_types = $this->list_all( '/api/FormTypes' );
		if ( is_wp_error( $form_types ) ) {
			return $form_types;
		}
		$sources = $this->list_all( '/api/Sources' );
		if ( is_wp_error( $sources ) ) {
			return $sources;
		}
		$categories = $this->list_all( '/api/Categories' );
		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		$catalogue = FieldCatalogue::from_api( $form_types, $sources, $categories );
		set_transient( $cache_key, $catalogue->to_array(), self::CACHE_TTL );

		return $catalogue;
	}

	/**
	 * Read every page of a list endpoint that pages with xskip/xtop and X-Total-Count.
	 *
	 * @param string $path Endpoint path.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function list_all( string $path ) {
		$items = array();
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$response = $this->request(
				'GET',
				$path,
				array(
					'xskip' => $page * self::PAGE_SIZE,
					'xtop'  => self::PAGE_SIZE,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$batch = is_array( $response['body'] ) ? array_values( $response['body'] ) : array();
			$items = array_merge( $items, $batch );

			$total = isset( $response['total'] ) ? (int) $response['total'] : 0;
			if ( count( $batch ) < self::PAGE_SIZE || ( $total > 0 && count( $items ) >= $total ) ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * Send a request and decode the JSON response.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path starting with /api/.
	 * @param array<string, int|string> $query  Query string arguments.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array{status: int, body: mixed, total: int|null}|WP_Error
	 */
	private function request( string $method, string $path, array $query = array(), ?array $body = null ) {
		$url = self::base_url() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => 20,
			'headers'    => array(
				'X-Api-Key'    => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			'user-agent' => 'OrbitGravityForms/' . ORBIT_GF_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			// Certificate checks stay on; only a local development API may turn them off.
			'sslverify'  => ! ( defined( 'ORBIT_GF_SSL_VERIFY' ) && false === ORBIT_GF_SSL_VERIFY ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'orbit_gf_unreachable',
				/* translators: %s: error from the HTTP library, for example a timeout. */
				sprintf( __( 'Orbit could not be reached: %s', 'orbit-gf' ), $response->get_error_message() ),
				array( 'retryable' => true )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$total   = wp_remote_retrieve_header( $response, 'x-total-count' );

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'status' => $status,
				'body'   => $decoded,
				'total'  => '' === $total ? null : (int) $total,
			);
		}

		return new WP_Error(
			'orbit_gf_http_' . $status,
			ErrorMessage::from_response( $status, $decoded, $raw ),
			array(
				'status'    => $status,
				'retryable' => ErrorMessage::is_retryable( $status ),
			)
		);
	}
}
