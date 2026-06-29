<?php
/**
 * Test_Api_Key_Connected class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Main;
use ThemeIsle\HyveLite\OpenAI;

/**
 * Class Test_Api_Key_Connected.
 */
class Test_Api_Key_Connected extends WP_UnitTestCase {
	/**
	 * Clean up the stored error between tests.
	 */
	public function tear_down() {
		delete_option( OpenAI::ERROR_OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * An empty key is never connected.
	 */
	public function testEmptyKeyIsNotConnected() {
		$this->assertFalse( Main::is_api_key_connected( [] ) );
		$this->assertFalse( Main::is_api_key_connected( [ 'api_key' => '' ] ) );
	}

	/**
	 * A key with no stored error is connected.
	 */
	public function testKeyWithoutErrorIsConnected() {
		delete_option( OpenAI::ERROR_OPTION_KEY );

		$this->assertTrue( Main::is_api_key_connected( [ 'api_key' => 'sk-test' ] ) );
	}

	/**
	 * A key-invalidating error reports as not connected.
	 */
	public function testKeyErrorIsNotConnected() {
		$codes = [
			'invalid_api_key',
			'invalid_authentication',
			'account_deactivated',
			'billing_not_active',
			'organization_not_found',
			'organization_deactivated',
			'permission_denied',
			'insufficient_quota',
		];

		foreach ( $codes as $code ) {
			update_option( OpenAI::ERROR_OPTION_KEY, [ 'code' => $code ] );

			$this->assertFalse(
				Main::is_api_key_connected( [ 'api_key' => 'sk-test' ] ),
				"Code {$code} should mark the key as not connected."
			);
		}
	}

	/**
	 * A transient error (e.g. rate limiting) keeps the key connected.
	 */
	public function testTransientErrorStaysConnected() {
		update_option( OpenAI::ERROR_OPTION_KEY, [ 'code' => 'rate_limit_exceeded' ] );

		$this->assertTrue( Main::is_api_key_connected( [ 'api_key' => 'sk-test' ] ) );
	}

	/**
	 * A stored error without a code does not invalidate the key.
	 */
	public function testErrorWithoutCodeStaysConnected() {
		update_option( OpenAI::ERROR_OPTION_KEY, [ 'message' => 'Something happened.' ] );

		$this->assertTrue( Main::is_api_key_connected( [ 'api_key' => 'sk-test' ] ) );
	}
}
