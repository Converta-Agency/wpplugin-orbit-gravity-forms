<?php
namespace Orbit\GravityForms\Tests\Unit;

use Orbit\GravityForms\Mapping\Columns;
use Orbit\GravityForms\Mapping\FieldCatalogue;

final class FieldCatalogueTest extends TestCase {

	private function catalogue(): FieldCatalogue {
		return FieldCatalogue::from_api(
			array(
				array(
					'formTypeId'            => 12,
					'name'                  => 'Enquiry',
					'customFieldDefinition' => array(
						array( 'name' => 'Course', 'customFieldTableColumnName' => 'Text1', 'dataColumnV2' => 'varchar1', 'required' => true ),
						array( 'name' => 'Budget', 'customFieldTableColumnName' => null, 'dataColumnV2' => 'money2' ),
						array( 'name' => 'Broken', 'customFieldTableColumnName' => 'nonsense' ),
					),
				),
				array( 'FormTypeId' => 3, 'Name' => 'Brochure', 'CustomFieldDefinition' => array() ),
				array( 'name' => 'No id' ),
			),
			array( array( 'sourceId' => 2, 'name' => 'Website' ), array( 'sourceId' => 1, 'name' => 'Advert' ) ),
			array( array( 'categoryId' => 5, 'name' => 'Leads' ), array( 'categoryId' => 6, 'name' => '' ) )
		);
	}

	public function test_reads_form_types_and_their_fields(): void {
		$catalogue = $this->catalogue();
		$this->assertSame( array( 3 => 'Brochure', 12 => 'Enquiry' ), $catalogue->form_type_names() );
		$this->assertSame(
			array(
				'Text1'  => array( 'name' => 'Course', 'kind' => Columns::KIND_TEXT, 'required' => true ),
				'Money2' => array( 'name' => 'Budget', 'kind' => Columns::KIND_MONEY, 'required' => false ),
			),
			$catalogue->fields( 12 )
		);
		$this->assertTrue( $catalogue->has_form_type( 3 ) );
		$this->assertFalse( $catalogue->has_form_type( 99 ) );
	}

	public function test_reads_sources_and_categories_sorted_by_name(): void {
		$catalogue = $this->catalogue();
		$this->assertSame( array( 1 => 'Advert', 2 => 'Website' ), $catalogue->sources() );
		$this->assertSame( array( 5 => 'Leads' ), $catalogue->categories() );
	}

	public function test_survives_the_cache_round_trip(): void {
		$catalogue = $this->catalogue();
		$this->assertEquals( $catalogue, FieldCatalogue::from_array( $catalogue->to_array() ) );
	}
}
