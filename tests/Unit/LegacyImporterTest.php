<?php
namespace Orbit\GravityForms\Tests\Unit;

use Orbit\GravityForms\Migration\LegacyImporter;

final class LegacyImporterTest extends TestCase {

	private function legacy_form(): array {
		return array(
			'id'       => 4,
			'formid'   => '118',
			'clientid' => '55',
			'fields'   => array(
				array( 'id' => 1, 'encryptField' => 'firstname' ),
				array( 'id' => 2, 'encryptField' => 'email' ),
				array( 'id' => 3, 'encryptField' => 'emailsubscribe' ),
				array( 'id' => 4, 'encryptField' => 'varchar1' ),
				array( 'id' => 5, 'encryptField' => 'bit2' ),
				array( 'id' => 6, 'encryptField' => 'coursetitle' ),
				array( 'id' => 7, 'encryptField' => '' ),
				array( 'id' => 8 ),
			),
		);
	}

	public function test_detects_legacy_settings(): void {
		$this->assertTrue( LegacyImporter::has_legacy_settings( $this->legacy_form() ) );
		$this->assertFalse( LegacyImporter::has_legacy_settings( array( 'fields' => array( array( 'id' => 1, 'encryptField' => 'email' ) ) ) ), 'no form type' );
		$this->assertFalse( LegacyImporter::has_legacy_settings( array( 'formid' => '3', 'fields' => array() ) ), 'no mapped fields' );
	}

	public function test_plans_a_feed_from_the_legacy_settings(): void {
		$plan = LegacyImporter::plan( $this->legacy_form() );

		$this->assertSame( 118, $plan['form_type_id'] );
		$this->assertSame( '118', $plan['meta']['formTypeId'] );
		$this->assertSame( '1', $plan['meta']['contactMap_firstName'] );
		$this->assertSame( '2', $plan['meta']['contactMap_email'] );
		$this->assertSame( '3', $plan['meta']['emailSubscribeField'] );
		$this->assertSame(
			array(
				array( 'key' => 'Text1', 'custom_key' => '', 'value' => '4', 'custom_value' => '' ),
				array( 'key' => 'Bit2', 'custom_key' => '', 'value' => '5', 'custom_value' => '' ),
			),
			$plan['meta']['formFields']
		);
		$this->assertSame( array( 'coursetitle' ), $plan['skipped'] );
	}
}
