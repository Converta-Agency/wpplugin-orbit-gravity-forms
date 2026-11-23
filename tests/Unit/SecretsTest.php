<?php
namespace Orbit\GravityForms\Tests\Unit;

use Orbit\GravityForms\Support\Secrets;

final class SecretsTest extends TestCase {

	public function test_round_trips_and_never_stores_the_plain_key(): void {
		$secrets = new Secrets( 'site-salt' );
		$key     = '1b4e28ba-2fa1-11d2-883f-0016d3cca427';
		$stored  = $secrets->encrypt( $key );

		$this->assertStringStartsWith( Secrets::PREFIX, $stored );
		$this->assertStringNotContainsString( $key, $stored );
		$this->assertNotSame( $stored, $secrets->encrypt( $key ), 'a fresh nonce each time' );
		$this->assertSame( $key, $secrets->decrypt( $stored ) );
	}

	public function test_a_changed_salt_or_tampered_value_cannot_be_read(): void {
		$stored = ( new Secrets( 'old-salt' ) )->encrypt( 'secret' );
		$this->assertNull( ( new Secrets( 'new-salt' ) )->decrypt( $stored ) );
		$this->assertNull( ( new Secrets( 'old-salt' ) )->decrypt( $stored . 'x' ) );
		$this->assertNull( ( new Secrets( 'old-salt' ) )->decrypt( 'plain-text' ) );
	}

	public function test_mask_shows_only_the_last_four_characters(): void {
		$this->assertSame( '••••••••a427', Secrets::mask( '1b4e28ba-2fa1-11d2-883f-0016d3cca427' ) );
	}
}
