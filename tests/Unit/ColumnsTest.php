<?php
namespace Orbit\GravityForms\Tests\Unit;

use Orbit\GravityForms\Mapping\Columns;
use Orbit\GravityForms\Mapping\ContactFields;

final class ColumnsTest extends TestCase {

	public function test_both_spellings_normalise_to_orbit_names(): void {
		$this->assertSame( 'Text3', Columns::normalise( 'varchar3' ) );
		$this->assertSame( 'Text3', Columns::normalise( 'Text3' ) );
		$this->assertSame( 'Text50', Columns::normalise( 'VARCHAR50' ) );
		$this->assertSame( 'DateTime2', Columns::normalise( 'datetime2' ) );
		$this->assertSame( 'Money15', Columns::normalise( 'money15' ) );
		$this->assertSame( 'Bit1', Columns::normalise( 'BIT1' ) );
		$this->assertSame( 'Number10', Columns::normalise( 'number10' ) );
		$this->assertSame( 'IntegerLong3', Columns::normalise( 'integerlong3' ) );
	}

	public function test_slots_outside_orbit_limits_are_rejected(): void {
		foreach ( array( 'varchar51', 'bit10', 'money16', 'number11', 'datetime26', 'integerlong4', 'varchar0' ) as $name ) {
			$this->assertNull( Columns::normalise( $name ), $name );
		}
	}

	public function test_non_slot_names_are_rejected(): void {
		foreach ( array( 'firstname', 'bit', 'bitx', 'mybit1', 'bit1a', 'customfield3', '' ) as $name ) {
			$this->assertNull( Columns::normalise( $name ), $name );
		}
	}

	public function test_kinds_follow_the_slot_prefix(): void {
		$this->assertSame( Columns::KIND_TEXT, Columns::kind( 'Text1' ) );
		$this->assertSame( Columns::KIND_BOOLEAN, Columns::kind( 'bit2' ) );
		$this->assertSame( Columns::KIND_MONEY, Columns::kind( 'Money1' ) );
		$this->assertSame( Columns::KIND_DATE, Columns::kind( 'DateTime1' ) );
		$this->assertNull( Columns::kind( 'email' ) );
	}

	public function test_legacy_contact_ids_match_exactly_not_by_substring(): void {
		$this->assertSame( 'firstName', ContactFields::from_legacy( 'FirstName' ) );
		$this->assertSame( 'organisationName', ContactFields::from_legacy( 'organisationname' ) );
		$this->assertNull( ContactFields::from_legacy( 'coursetitle' ), 'the legacy plugin sent this as the contact title' );
		$this->assertNull( ContactFields::from_legacy( 'email_address' ) );
		$this->assertNull( ContactFields::from_legacy( 'firstname2' ) );
	}
}
