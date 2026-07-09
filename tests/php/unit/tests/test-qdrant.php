<?php
/**
 * Tests for Qdrant_API error handling.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Qdrant_API;

/**
 * Class QdrantTest
 */
class QdrantTest extends WP_UnitTestCase {

	/**
	 * Invoke the private handle_exception() method.
	 *
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 *
	 * @return WP_Error
	 */
	private function handle( $code, $message = 'Error' ) {
		$qdrant = new Qdrant_API();
		$method = new ReflectionMethod( $qdrant, 'handle_exception' );
		$method->setAccessible( true );

		return $method->invoke( $qdrant, new Exception( $message, $code ) );
	}

	/**
	 * Clean up options between tests.
	 */
	protected function tearDown(): void {
		delete_option( Qdrant_API::ERROR_OPTION_KEY );
		delete_option( 'hyve_qdrant_status' );
		parent::tearDown();
	}

	/**
	 * A missing collection/cluster (404) persists the error and marks the
	 * connection inactive so the UI stops reporting a false connection.
	 */
	public function test_not_found_persists_error_and_deactivates() {
		update_option( 'hyve_qdrant_status', 'active' );

		$result = $this->handle( 404, 'Not Found' );
		$saved  = get_option( Qdrant_API::ERROR_OPTION_KEY );

		$this->assertWPError( $result );
		$this->assertEquals( 404, $saved['code'] );
		$this->assertEquals( 'Qdrant', $saved['provider'] );
		$this->assertEquals( 'inactive', get_option( 'hyve_qdrant_status' ) );
	}

	/**
	 * Revoked credentials (403) also deactivate the connection.
	 */
	public function test_forbidden_deactivates() {
		update_option( 'hyve_qdrant_status', 'active' );

		$this->handle( 403 );

		$this->assertEquals( 'inactive', get_option( 'hyve_qdrant_status' ) );
	}

	/**
	 * Transient errors (e.g. 500) surface in the dashboard but must NOT
	 * disconnect a working integration.
	 */
	public function test_transient_error_persists_but_keeps_status() {
		update_option( 'hyve_qdrant_status', 'active' );

		$this->handle( 500, 'Server error' );
		$saved = get_option( Qdrant_API::ERROR_OPTION_KEY );

		$this->assertEquals( 500, $saved['code'] );
		$this->assertEquals( 'active', get_option( 'hyve_qdrant_status' ) );
	}

	/**
	 * Known codes map to actionable messages; unknown codes return null.
	 */
	public function test_error_message_for_code() {
		$this->assertNotNull( Qdrant_API::get_error_message_for_code( 403 ) );
		$this->assertNotNull( Qdrant_API::get_error_message_for_code( 404 ) );
		$this->assertNull( Qdrant_API::get_error_message_for_code( 500 ) );
	}
}
