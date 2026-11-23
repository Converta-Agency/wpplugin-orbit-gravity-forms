<?php
namespace Orbit\GravityForms\Tests\Unit;

use Orbit\GravityForms\Mapping\Columns;
use Orbit\GravityForms\Mapping\ValueConverter;

final class ValueConverterTest extends TestCase {

	/**
	 * @dataProvider affirmative_answers
	 */
	public function test_affirmative_answers_are_true( $answer ): void {
		$this->assertTrue( ValueConverter::to_bool( $answer ) );
	}

	public function affirmative_answers(): array {
		return array_map(
			static function ( $a ) {
				return array( $a );
			},
			array( 'Yes', 'yes', 'YES', 'True', '1', 'On', 'Yes please', 'Opt in', 'Checked', 'I agree to be contacted', true, array( 'Keep me informed' ) )
		);
	}

	/**
	 * @dataProvider negative_answers
	 */
	public function test_negative_answers_are_false( $answer ): void {
		$this->assertFalse( ValueConverter::to_bool( $answer ) );
	}

	public function negative_answers(): array {
		return array_map(
			static function ( $a ) {
				return array( $a );
			},
			array( '', '  ', 'No', 'no', 'NO', 'False', '0', 'Off', 'No thanks', 'Not right now', 'Not Checked', 'Opt out', 'opt-out please', 'Unsubscribe', "Don't contact me", false, array(), array( '' ) )
		);
	}

	public function test_numbers_ignore_currency_and_thousands_separators(): void {
		$this->assertSame( 1250.5, ValueConverter::to_number( '£1,250.50' ) );
		$this->assertSame( 12.0, ValueConverter::to_number( '12' ) );
		$this->assertSame( -3.5, ValueConverter::to_number( '-3.5' ) );
		$this->assertNull( ValueConverter::to_number( '' ) );
		$this->assertNull( ValueConverter::to_number( 'twelve' ) );
	}

	public function test_dates_are_sent_as_iso_8601(): void {
		$this->assertSame( '2026-11-23T00:00:00', ValueConverter::to_date( '2026-11-23' ) );
		$this->assertSame( '2026-11-03T00:00:00', ValueConverter::to_date( '03/11/2026' ), 'dd/mm/yyyy is read the UK way' );
		$this->assertNull( ValueConverter::to_date( '' ) );
		$this->assertNull( ValueConverter::to_date( 'not a date' ) );
	}

	public function test_text_is_trimmed_and_empty_text_is_null(): void {
		$this->assertSame( 'Hello', ValueConverter::to_text( '  Hello ' ) );
		$this->assertSame( 'Red, Blue', ValueConverter::to_text( array( 'Red', '', 'Blue' ) ) );
		$this->assertNull( ValueConverter::to_text( '   ' ) );
	}

	public function test_integer_slots_round_to_whole_numbers(): void {
		$this->assertSame( 4, ValueConverter::for_kind( Columns::KIND_INTEGER, '3.6' ) );
	}

	public function test_tidy_name_fixes_only_single_case_names(): void {
		$this->assertSame( 'Jane Smith', ValueConverter::tidy_name( 'JANE SMITH' ) );
		$this->assertSame( 'Mary-Jane', ValueConverter::tidy_name( 'mary-jane' ) );
		$this->assertSame( "O'Brien", ValueConverter::tidy_name( "o'brien" ) );
		$this->assertSame( 'McDonald', ValueConverter::tidy_name( 'McDonald' ) );
		$this->assertSame( 'van der Berg', ValueConverter::tidy_name( 'van der Berg' ) );
		$this->assertSame( 'Zoë', ValueConverter::tidy_name( 'ZOË' ) );
	}
}
