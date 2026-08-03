<?php
/**
 * Encrypt sensitive Hyve settings stored in plaintext.
 *
 * @package Codeinwp/HyveLite
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return new class() extends \ThemeisleSDK\Modules\Abstract_Migration {

	/**
	 * Run while a sensitive setting is still stored in plaintext.
	 *
	 * @return bool
	 */
	public function should_run() {
		$settings = get_option( 'hyve_settings', [] );

		if ( ! is_array( $settings ) ) {
			return false;
		}

		foreach ( \ThemeIsle\HyveLite\Main::get_encrypted_settings() as $key ) {
			if ( ! empty( $settings[ $key ] ) && ! \ThemeIsle\HyveLite\Encryption::is_encrypted( $settings[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Encrypt existing credentials in place.
	 *
	 * @return void
	 * @throws \Exception If unable to encrypt credentials.
	 */
	public function up() {
		$settings = get_option( 'hyve_settings', [] );

		if ( ! is_array( $settings ) || ! \ThemeIsle\HyveLite\Encryption::ensure_key_check() ) {
			throw new \Exception( 'Unable to prepare credential encryption.' );
		}

		if ( ! \ThemeIsle\HyveLite\Main::save_settings( $settings ) ) {
			throw new \Exception( 'Unable to encrypt credentials.' );
		}
	}
};
