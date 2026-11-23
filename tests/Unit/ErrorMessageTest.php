<?php
namespace Orbit\GravityForms\Tests\Unit;

use Orbit\GravityForms\Api\ErrorMessage;

final class ErrorMessageTest extends TestCase {

	public function test_reads_validation_problem_details(): void {
		$message = ErrorMessage::from_response( 400, array( 'title' => 'One or more validation errors occurred.', 'errors' => array( 'Contact.Email' => array( 'Not a valid email.' ) ) ), '' );
		$this->assertSame( 'Orbit returned HTTP 400: Contact.Email: Not a valid email.', $message );
	}

	public function test_reads_the_detail_of_a_plain_problem(): void {
		$this->assertSame( 'Orbit returned HTTP 400: Contacts must be in at least one category.', ErrorMessage::from_response( 400, array( 'title' => 'Contacts must be in at least one category.', 'detail' => 'Contacts must be in at least one category.' ), '' ) );
	}

	public function test_explains_a_refused_key(): void {
		$this->assertStringContainsString( 'refused the API key', ErrorMessage::from_response( 401, null, '' ) );
	}

	public function test_falls_back_to_trimmed_plain_text(): void {
		$message = ErrorMessage::from_response( 502, null, '<html><body><h1>Bad gateway</h1></body></html>' );
		$this->assertSame( 'Orbit returned HTTP 502: Bad gateway', $message );
	}

	public function test_only_temporary_failures_are_retried(): void {
		foreach ( array( 408, 429, 500, 502, 503 ) as $status ) {
			$this->assertTrue( ErrorMessage::is_retryable( $status ), (string) $status );
		}
		foreach ( array( 400, 401, 403, 404 ) as $status ) {
			$this->assertFalse( ErrorMessage::is_retryable( $status ), (string) $status );
		}
	}
}
