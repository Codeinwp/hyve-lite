<?php
/**
 * Test_Settings_Save class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Main;

/**
 * Class Test_Settings_Save.
 *
 * Covers the settings save contract: keys the endpoint cannot validate are
 * dropped so stale premium settings do not remain stored in Lite.
 */
class Test_Settings_Save extends WP_UnitTestCase {

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		delete_option( 'hyve_settings' );

		parent::tearDown();
	}

	/**
	 * Build a settings save request.
	 *
	 * @param array<string, mixed> $data The settings payload.
	 *
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function request( $data ) {
		$request = new WP_REST_Request( 'POST', '/hyve/v1/settings' );
		$request->set_param( 'data', $data );

		return $request;
	}

	/**
	 * A changed key without a validation entry is dropped; validated keys in the
	 * same request save.
	 */
	public function test_unsavable_keys_drop_and_do_not_save() {
		update_option(
			'hyve_settings',
			[
				'welcome_message' => 'Hi',
				'mystery_key'     => 'old',
			]
		);

		$response = (array) API::instance()->update_settings(
			$this->request(
				[
					'welcome_message' => 'Hello there',
					'mystery_key'     => 'new',
				]
			)
		)->get_data();

		$this->assertArrayHasKey( 'success', $response );
		$this->assertArrayNotHasKey( 'warning', $response );

		$settings = Main::get_settings();
		$this->assertSame( 'Hello there', $settings['welcome_message'] );
		$this->assertArrayNotHasKey( 'mystery_key', $settings );
	}

	/**
	 * Submitting one Qdrant credential while the other is missing reports a
	 * validation error instead of reaching client initialization (see #236),
	 * and the incomplete credentials are not stored.
	 */
	public function test_incomplete_qdrant_credentials_report_error() {
		$response = (array) API::instance()->update_settings(
			$this->request( [ 'qdrant_endpoint' => 'https://example.qdrant.io' ] )
		)->get_data();

		$this->assertArrayHasKey( 'error', $response );
		$this->assertEmpty( Main::get_settings()['qdrant_endpoint'] );
	}

	/**
	 * A save where every changed key validates reports a plain success.
	 */
	public function test_clean_save_reports_success() {
		update_option( 'hyve_settings', [ 'welcome_message' => 'Hi' ] );

		$response = (array) API::instance()->update_settings(
			$this->request( [ 'welcome_message' => 'Hello there' ] )
		)->get_data();

		$this->assertArrayHasKey( 'success', $response );
		$this->assertArrayNotHasKey( 'warning', $response );
		$this->assertSame( 'Hello there', Main::get_settings()['welcome_message'] );
	}
}
