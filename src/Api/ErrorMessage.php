<?php
/**
 * Turns an Orbit error response into a sentence an administrator can act on.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Orbit answers errors as ASP.NET problem details (title, detail, errors), so
 * a top-level "message" key is rarely present.
 */
final class ErrorMessage {

	/**
	 * Build the message for a failed response.
	 *
	 * @param int    $status  HTTP status.
	 * @param mixed  $decoded Decoded JSON body, if any.
	 * @param string $raw     Raw body.
	 */
	public static function from_response( int $status, $decoded, string $raw ): string {
		if ( 401 === $status ) {
			return __( 'Orbit refused the API key. Check the key is copied correctly and has not been revoked in Orbit under Access and API keys.', 'orbit-gf' );
		}
		if ( 403 === $status ) {
			return __( 'Orbit accepted the API key but it is not allowed to do this. Use a full-access key, not a data sync key.', 'orbit-gf' );
		}

		$detail = self::detail( $decoded );
		if ( '' === $detail ) {
			$detail = self::plain_text( $raw );
		}
		if ( '' === $detail ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Orbit returned an error (HTTP %d) without a reason.', 'orbit-gf' ), $status );
		}

		/* translators: 1: HTTP status code, 2: reason returned by Orbit. */
		return sprintf( __( 'Orbit returned HTTP %1$d: %2$s', 'orbit-gf' ), $status, $detail );
	}

	/**
	 * Whether the failure may succeed later without anyone changing anything.
	 *
	 * Timeouts, rate limits and server errors are retried; validation errors
	 * (400) and rejected keys are not, because sending the same entry again
	 * would fail the same way.
	 *
	 * @param int $status HTTP status.
	 */
	public static function is_retryable( int $status ): bool {
		return 408 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * Read the reason from a problem details document.
	 *
	 * @param mixed $decoded Decoded body.
	 */
	private static function detail( $decoded ): string {
		if ( is_string( $decoded ) ) {
			return trim( $decoded );
		}
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			$messages = array();
			foreach ( $decoded['errors'] as $field => $field_errors ) {
				$field_errors = array_filter( (array) $field_errors, 'is_scalar' );
				if ( empty( $field_errors ) ) {
					continue;
				}
				$prefix     = is_string( $field ) && '' !== $field ? $field . ': ' : '';
				$messages[] = $prefix . implode( ' ', array_map( 'strval', $field_errors ) );
			}
			if ( ! empty( $messages ) ) {
				return implode( ' | ', $messages );
			}
		}

		foreach ( array( 'detail', 'title', 'message', 'Message', 'error' ) as $key ) {
			if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
				return trim( $decoded[ $key ] );
			}
		}
		return '';
	}

	/**
	 * A short plain-text version of a non-JSON body (an HTML error page, say).
	 *
	 * @param string $raw Raw body.
	 */
	private static function plain_text( string $raw ): string {
		$plain = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
		if ( strlen( $plain ) > 300 ) {
			$plain = substr( $plain, 0, 300 ) . '...';
		}
		return $plain;
	}
}
