<?php
/**
 * Names of Orbit form custom field columns.
 *
 * @package Orbit\GravityForms
 */

declare(strict_types=1);

namespace Orbit\GravityForms\Mapping;

/**
 * Orbit stores a form type's custom fields in numbered slots: Text1 to Text50,
 * DateTime1 to DateTime25, Money1 to Money15, Bit1 to Bit9, Number1 to
 * Number10 and IntegerLong1 to IntegerLong3. The database (and the legacy
 * KulaHub plugin) calls the same slots varchar1, datetime1, money1, bit1 and
 * number1, so both spellings are accepted and turned into Orbit's.
 */
final class Columns {

	const KIND_TEXT    = 'text';
	const KIND_DATE    = 'date';
	const KIND_MONEY   = 'money';
	const KIND_BOOLEAN = 'boolean';
	const KIND_NUMBER  = 'number';
	const KIND_INTEGER = 'integer';

	/**
	 * Orbit prefix => [kind, highest slot number].
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	const SLOTS = array(
		'Text'        => array( self::KIND_TEXT, 50 ),
		'DateTime'    => array( self::KIND_DATE, 25 ),
		'Money'       => array( self::KIND_MONEY, 15 ),
		'Bit'         => array( self::KIND_BOOLEAN, 9 ),
		'Number'      => array( self::KIND_NUMBER, 10 ),
		'IntegerLong' => array( self::KIND_INTEGER, 3 ),
	);

	/**
	 * Database (legacy) prefix => Orbit prefix.
	 *
	 * @var array<string, string>
	 */
	const LEGACY_PREFIXES = array(
		'varchar'     => 'Text',
		'text'        => 'Text',
		'datetime'    => 'DateTime',
		'money'       => 'Money',
		'bit'         => 'Bit',
		'number'      => 'Number',
		'integerlong' => 'IntegerLong',
	);

	/**
	 * Turn any spelling of a slot into Orbit's (varchar3 and text3 both give Text3).
	 *
	 * @param string $name Column name in either spelling.
	 * @return string|null Orbit's name, or null when it is not a form slot.
	 */
	public static function normalise( string $name ): ?string {
		if ( 1 !== preg_match( '/^([a-z]+)(\d+)$/i', trim( $name ), $matches ) ) {
			return null;
		}
		$prefix = self::LEGACY_PREFIXES[ strtolower( $matches[1] ) ] ?? null;
		$number = (int) $matches[2];
		if ( null === $prefix || $number < 1 || $number > self::SLOTS[ $prefix ][1] ) {
			return null;
		}
		return $prefix . $number;
	}

	/**
	 * The kind of value a slot holds.
	 *
	 * @param string $column Orbit column name, for example Money2.
	 * @return string|null One of the KIND_ constants, or null when unknown.
	 */
	public static function kind( string $column ): ?string {
		$normalised = self::normalise( $column );
		if ( null === $normalised ) {
			return null;
		}
		$prefix = (string) preg_replace( '/\d+$/', '', $normalised );
		return self::SLOTS[ $prefix ][0];
	}
}
