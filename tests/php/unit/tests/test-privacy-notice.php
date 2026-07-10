<?php
/**
 * Tests for the chat privacy notice.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Main;

/**
 * Class PrivacyNoticeTest
 */
class PrivacyNoticeTest extends WP_UnitTestCase {

	/**
	 * Main instance.
	 *
	 * @var Main
	 */
	private $main;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->main = new Main();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		delete_option( 'hyve_settings' );
		delete_option( 'wp_page_for_privacy_policy' );
		parent::tearDown();
	}

	/**
	 * The privacy notice is off by default.
	 */
	public function test_disabled_by_default() {
		$data = $this->main->get_frontend_data();

		$this->assertFalse( $data['privacyNotice']['enabled'] );
	}

	/**
	 * Enabling the setting flags the notice as enabled in the frontend data.
	 */
	public function test_enabled_when_setting_on() {
		update_option( 'hyve_settings', [ 'privacy_notice_enabled' => true ] );

		$data = $this->main->get_frontend_data();

		$this->assertTrue( $data['privacyNotice']['enabled'] );
	}

	/**
	 * The notice URL defaults to the site's Privacy Policy page.
	 */
	public function test_url_uses_privacy_policy_page() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( 'wp_page_for_privacy_policy', $page_id );

		$data = $this->main->get_frontend_data();

		$this->assertSame( get_privacy_policy_url(), $data['privacyNotice']['url'] );
		$this->assertNotEmpty( $data['privacyNotice']['url'] );
	}

	/**
	 * The URL can be overridden with the dedicated filter.
	 */
	public function test_url_filterable() {
		add_filter( 'hyve_privacy_notice_url', fn() => 'https://example.com/privacy' );

		$data = $this->main->get_frontend_data();

		$this->assertSame( 'https://example.com/privacy', $data['privacyNotice']['url'] );

		remove_all_filters( 'hyve_privacy_notice_url' );
	}

	/**
	 * The notice sentence keeps a single %s placeholder for the link.
	 */
	public function test_text_has_link_placeholder() {
		$data = $this->main->get_frontend_data();

		$this->assertStringContainsString( '%s', $data['strings']['privacyNotice'] );
		$this->assertNotEmpty( $data['strings']['privacyPolicy'] );
	}

	/**
	 * The notice text can be customized with a filter.
	 */
	public function test_text_filterable() {
		add_filter( 'hyve_privacy_notice_text', fn() => 'Custom notice %s here.' );

		$data = $this->main->get_frontend_data();

		$this->assertSame( 'Custom notice %s here.', $data['strings']['privacyNotice'] );

		remove_all_filters( 'hyve_privacy_notice_text' );
	}
}
