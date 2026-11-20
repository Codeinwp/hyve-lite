<?php
/**
 * Tests for the SDK About Us page metadata.
 *
 * @package Codeinwp/HyveLite
 */

/**
 * Class Test_About_Us.
 */
class Test_About_Us extends WP_UnitTestCase {

	/**
	 * The logo is a file shipped with the plugin, not a remote URL that can 404 (#304).
	 */
	public function test_logo_is_a_bundled_file() {
		$logo = apply_filters( 'hyve_lite_about_us_metadata', [] )['logo'];

		$this->assertStringStartsWith( HYVE_LITE_URL, $logo );
		$this->assertFileExists( trailingslashit( HYVE_LITE_PATH ) . substr( $logo, strlen( HYVE_LITE_URL ) ) );
	}
}
