<?php
namespace Orbit\GravityForms\Tests\Unit;

use Orbit\GravityForms\Mapping\PayloadBuilder;

final class PayloadBuilderTest extends TestCase {

	public function test_builds_the_orbit_forms_body(): void {
		$builder = ( new PayloadBuilder( 42 ) )
			->contact( 'email', ' Jane@Example.COM ' )
			->contact( 'firstName', 'JANE', true )
			->contact( 'postcode', 'HG1 1JX', true )
			->contact( 'telephone', '' )
			->email_subscribe( true )
			->source( 7 )
			->categories( array( 3, '3', 9, 0 ) );
		$builder->custom( 'varchar1', 'Brochure' );
		$builder->custom( 'Money2', '£1,000' );
		$builder->custom( 'bit1', true );
		$builder->custom( 'DateTime1', '2026-12-01' );
		$builder->custom( 'Number1', '' );

		$this->assertSame( array(), $builder->problems() );
		$this->assertSame(
			'{"formTypeId":42,"sourceId":7,"Text1":"Brochure","Money2":1000,"Bit1":true,"DateTime1":"2026-12-01T00:00:00","contact":{"email":"jane@example.com","firstName":"Jane","postcode":"HG1 1JX","emailSubscribe":true,"categoryIds":[3,9],"sourceId":7}}',
			json_encode( $builder->build() )
		);
	}

	public function test_postcodes_are_never_recased(): void {
		$body = ( new PayloadBuilder( 1 ) )->contact( 'email', 'a@b.co' )->contact( 'postcode', 'hg1 1jx', true )->build();
		$this->assertSame( 'hg1 1jx', $body['contact']->postcode );
	}

	public function test_opt_in_is_omitted_when_the_form_does_not_ask(): void {
		$body = ( new PayloadBuilder( 1 ) )->contact( 'email', 'a@b.co' )->build();
		$this->assertObjectNotHasProperty( 'emailSubscribe', $body['contact'] );
	}

	public function test_unknown_columns_are_refused(): void {
		$this->assertFalse( ( new PayloadBuilder( 1 ) )->custom( 'coursetitle', 'x' ) );
	}

	public function test_problems_explain_what_orbit_would_reject(): void {
		$this->assertCount( 1, ( new PayloadBuilder( 1 ) )->problems(), 'no contact details at all' );
		$this->assertCount( 1, ( new PayloadBuilder( 1 ) )->contact( 'email', 'not-an-email' )->problems() );
		$long = str_repeat( 'a', 75 ) . '@b.com';
		$this->assertCount( 1, ( new PayloadBuilder( 1 ) )->contact( 'email', $long )->problems() );
		$this->assertCount( 0, ( new PayloadBuilder( 1 ) )->contact( 'lastName', 'Smith' )->problems(), 'Orbit can match a contact without an email' );
	}

	public function test_an_empty_contact_still_encodes_as_an_object(): void {
		$this->assertSame( '{"formTypeId":1,"contact":{}}', json_encode( ( new PayloadBuilder( 1 ) )->build() ) );
	}
}
