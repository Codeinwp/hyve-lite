<?php
/**
 * Migrate the legacy `chat_enabled` flag to the `display_mode` setting.
 *
 * @package Codeinwp\HyveLite
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return new class() extends \ThemeisleSDK\Modules\Abstract_Migration {

	/**
	 * Only run until the new setting has been persisted.
	 *
	 * @return bool
	 */
	public function should_run() {
		$settings = get_option( 'hyve_settings', [] );

		return is_array( $settings ) && ! isset( $settings['display_mode'] );
	}

	/**
	 * Persist display_mode derived from the legacy chat_enabled flag.
	 *
	 * A previously disabled chat becomes "manual" (block/shortcode only);
	 * everything else defaults to showing on all pages.
	 *
	 * @return void
	 */
	public function up() {
		$settings = get_option( 'hyve_settings', [] );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$settings['display_mode'] = ( isset( $settings['chat_enabled'] ) && ! $settings['chat_enabled'] ) ? 'manual' : 'all';

		update_option( 'hyve_settings', $settings );
	}
};
