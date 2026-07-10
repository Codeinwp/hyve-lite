<?php
/**
 * Tests for encrypted credential storage.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Encryption;
use ThemeIsle\HyveLite\Main;

/**
 * Encryption tests.
 */
class Test_Encryption extends WP_UnitTestCase {

	/**
	 * Clean up options used by the tests.
	 */
	public function tearDown(): void {
		delete_option( 'hyve_settings' );
		delete_option( Encryption::KEY_CHECK_OPTION );
		parent::tearDown();
	}

	/**
	 * Values can be encrypted and decrypted without storing plaintext.
	 */
	public function test_encrypt_and_decrypt() {
		$encrypted = Encryption::encrypt( 'sk-secret' );

		$this->assertIsString( $encrypted );
		$this->assertTrue( Encryption::is_encrypted( $encrypted ) );
		$this->assertStringNotContainsString( 'sk-secret', $encrypted );
		$this->assertSame( 'sk-secret', Encryption::decrypt( $encrypted ) );
	}

	/**
	 * Legacy plaintext remains readable until migration runs.
	 */
	public function test_plaintext_is_returned_unchanged() {
		$this->assertSame( 'legacy-key', Encryption::decrypt( 'legacy-key' ) );
	}

	/**
	 * Plaintext values resembling the old suffix format are still encrypted.
	 */
	public function test_plaintext_suffix_is_not_treated_as_encrypted() {
		$encrypted = Encryption::encrypt( 'sk-secret_enc' );

		$this->assertNotSame( 'sk-secret_enc', $encrypted );
		$this->assertTrue( Encryption::is_encrypted( $encrypted ) );
		$this->assertSame( 'sk-secret_enc', Encryption::decrypt( $encrypted ) );
	}

	/**
	 * An unreadable key marker detects changed WordPress salts.
	 */
	public function test_changed_key_is_detected() {
		update_option( Encryption::KEY_CHECK_OPTION, 'invalid-payload_enc' );

		$this->assertTrue( Encryption::has_key_changed() );
	}

	/**
	 * Settings expose decrypted credentials to existing consumers.
	 */
	public function test_get_settings_decrypts_credentials() {
		update_option(
			'hyve_settings',
			[
				'api_key'        => Encryption::encrypt( 'sk-openai' ),
				'qdrant_api_key' => Encryption::encrypt( 'qdrant-secret' ),
			]
		);

		$settings = Main::get_settings();

		$this->assertSame( 'sk-openai', $settings['api_key'] );
		$this->assertSame( 'qdrant-secret', $settings['qdrant_api_key'] );
	}

	/**
	 * Saving decrypted settings keeps the credentials encrypted in storage.
	 */
	public function test_save_settings_encrypts_credentials() {
		$this->assertTrue(
			Main::save_settings(
				[
					'api_key'        => 'sk-openai',
					'qdrant_api_key' => '',
				]
			)
		);

		$stored = get_option( 'hyve_settings' );

		$this->assertTrue( Encryption::is_encrypted( $stored['api_key'] ) );
		$this->assertSame( 'sk-openai', Encryption::decrypt( $stored['api_key'] ) );
	}

	/**
	 * A key-change marker remains while an encrypted credential is unreadable.
	 */
	public function test_key_check_is_not_reset_while_credentials_are_unreadable() {
		update_option( Encryption::KEY_CHECK_OPTION, 'invalid-payload' );
		update_option( 'hyve_settings', [ 'api_key' => Encryption::PREFIX . base64_encode( str_repeat( 'x', 29 ) ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertFalse( Main::can_reset_encryption_key_check( true ) );
	}

	/**
	 * The upgrade migration encrypts legacy settings.
	 */
	public function test_migration_encrypts_plaintext_settings() {
		update_option(
			'hyve_settings',
			[
				'api_key'        => 'sk-openai',
				'qdrant_api_key' => 'qdrant-secret',
			]
		);

		if ( ! class_exists( '\ThemeisleSDK\Modules\Abstract_Migration' ) ) {
			require_once HYVE_LITE_PATH . '/vendor/codeinwp/themeisle-sdk/src/Modules/Abstract_Migration.php';
		}

		$migration = require HYVE_LITE_PATH . '/migrations/20260711000000_encrypt_sensitive_settings.php';

		$this->assertTrue( $migration->should_run() );
		$migration->up();

		$stored = get_option( 'hyve_settings' );
		$this->assertTrue( Encryption::is_encrypted( $stored['api_key'] ) );
		$this->assertTrue( Encryption::is_encrypted( $stored['qdrant_api_key'] ) );
		$this->assertFalse( $migration->should_run() );
	}
}
