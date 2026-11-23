<?php
/**
 * Converts submitted text into the types Orbit expects.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Mapping;

/**
 * Pure conversion rules, free of WordPress so they can be unit tested.
 */
final class ValueConverter {

	/**
	 * Answers that mean "no", compared in lower case.
	 *
	 * @var string[]
	 */
	const NEGATIVE = array(
		'no',
		'n',
		'false',
		'0',
		'off',
		'none',
		'not',
		'never',
		'nope',
		'decline',
		'declined',
		'disagree',
		'unsubscribe',
		'unsubscribed',
		'unchecked',
	);

	/**
	 * Convert a value for a slot of the given kind.
	 *
	 * @param string $kind  One of the Columns::KIND_ constants.
	 * @param mixed  $value Submitted value.
	 * @return mixed Converted value, or null when it cannot be read as that kind.
	 */
	public static function for_kind( string $kind, $value ) {
		switch ( $kind ) {
			case Columns::KIND_BOOLEAN:
				return self::to_bool( $value );
			case Columns::KIND_MONEY:
			case Columns::KIND_NUMBER:
				return self::to_number( $value );
			case Columns::KIND_INTEGER:
				$number = self::to_number( $value );
				return null === $number ? null : (int) round( $number );
			case Columns::KIND_DATE:
				return self::to_date( $value );
			default:
				return self::to_text( $value );
		}
	}

	/**
	 * Read an answer as a Boolean.
	 *
	 * "Yes", "True", "1", "On" and phrases such as "Yes please" are true; "No",
	 * "No thanks", "Opt out", "Not checked" and an empty answer are false.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$value = implode( ' ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		$normalised = strtolower( trim( (string) $value ) );
		if ( '' === $normalised || in_array( $normalised, self::NEGATIVE, true ) ) {
			return false;
		}

		$words = preg_split( "/[^a-z0-9']+/", $normalised, -1, PREG_SPLIT_NO_EMPTY );
		$first = is_array( $words ) && ! empty( $words ) ? $words[0] : '';
		if ( in_array( $first, self::NEGATIVE, true ) || 'dont' === $first || "don't" === $first ) {
			return false;
		}
		if ( 0 === strpos( $normalised, 'opt out' ) || 0 === strpos( $normalised, 'opt-out' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Read a number, ignoring currency symbols, thousands separators and spaces.
	 *
	 * Gravity Forms stores a number or product price as "£1,250.50" or "1250.5"
	 * depending on the field, so both give 1250.5.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function to_number( $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		$clean = preg_replace( '/[^\d.\-]/', '', (string) $value );
		if ( null === $clean || '' === $clean || ! is_numeric( $clean ) ) {
			return null;
		}
		return (float) $clean;
	}

	/**
	 * Read a date as an ISO 8601 date and time (2026-11-23T00:00:00).
	 *
	 * Gravity Forms date fields store Y-m-d whatever the display format, so
	 * that is tried first; dd/mm/yyyy is read the UK way.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function to_date( $value ): ?string {
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return null;
		}
		if ( 1 === preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $text, $m ) ) {
			$text = sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
		}
		$timestamp = strtotime( $text . ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ? ' 00:00:00 UTC' : '' ) );
		if ( false === $timestamp ) {
			return null;
		}
		return gmdate( 'Y-m-d\TH:i:s', $timestamp );
	}

	/**
	 * Trim text; an empty answer is sent as null so it does not blank a value.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function to_text( $value ): ?string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		$text = trim( (string) $value );
		return '' === $text ? null : $text;
	}

	/**
	 * Tidy the capitalisation of a name typed all in capitals or all in lower case.
	 *
	 * Mixed-case names are left alone, so "McDonald" and "van der Berg" survive.
	 * Only name-like fields go through this; the legacy plugin title-cased every
	 * field and turned postcodes such as "HG1 1JX" into "Hg1 1jx".
	 *
	 * @param string $value Name as typed.
	 */
	public static function tidy_name( string $value ): string {
		$is_upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value ) === $value : strtoupper( $value ) === $value;
		$is_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) === $value : strtolower( $value ) === $value;
		if ( ! $is_upper && ! $is_lower ) {
			return $value;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
		return function_exists( 'mb_convert_case' )
			? (string) preg_replace_callback(
				"/(^|[\s\-'])(\p{L})/u",
				static function ( $m ) {
					return $m[1] . mb_strtoupper( $m[2] );
				},
				$lower
			)
			: ucwords( $lower, " -'" );
	}
}
