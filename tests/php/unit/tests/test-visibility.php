<?php
/**
 * Tests for chat visibility rules.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Main;

/**
 * Class VisibilityTest
 */
class VisibilityTest extends WP_UnitTestCase {

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
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * Store raw settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function set_settings( $settings ) {
		update_option( 'hyve_settings', $settings );
	}

	/**
	 * A disabled legacy flag derives the manual mode.
	 */
	public function test_derive_manual_from_disabled_chat() {
		$this->set_settings( [ 'chat_enabled' => false ] );

		$this->assertSame( 'manual', Main::get_settings()['display_mode'] );
	}

	/**
	 * An enabled (or unset) legacy flag derives the all-pages mode.
	 */
	public function test_derive_all_from_enabled_chat() {
		$this->set_settings( [ 'chat_enabled' => true ] );
		$this->assertSame( 'all', Main::get_settings()['display_mode'] );

		$this->set_settings( [] );
		$this->assertSame( 'all', Main::get_settings()['display_mode'] );
	}

	/**
	 * A persisted display_mode always wins over the legacy flag.
	 */
	public function test_explicit_display_mode_is_preserved() {
		$this->set_settings(
			[
				'chat_enabled' => false,
				'display_mode' => 'include',
			]
		);

		$this->assertSame( 'include', Main::get_settings()['display_mode'] );
	}

	/**
	 * All-pages mode always displays.
	 */
	public function test_all_mode_displays() {
		$this->set_settings( [ 'display_mode' => 'all' ] );
		$this->go_to( home_url( '/' ) );

		$this->assertTrue( $this->main->should_display_chat() );
	}

	/**
	 * Manual mode never auto-displays.
	 */
	public function test_manual_mode_does_not_display() {
		$this->set_settings( [ 'display_mode' => 'manual' ] );

		$this->assertFalse( $this->main->should_display_chat() );
	}

	/**
	 * A "contains" rule shows the chat on any URL under that path in include mode.
	 */
	public function test_include_path_contains() {
		$this->set_settings(
			[
				'display_mode'  => 'include',
				'display_rules' => [
					[
						'path'     => '/shop',
						'operator' => 'contains',
					],
				],
			]
		);

		$_SERVER['REQUEST_URI'] = '/shop/product-a/';
		$this->assertTrue( $this->main->should_display_chat() );

		$_SERVER['REQUEST_URI'] = '/about/';
		$this->assertFalse( $this->main->should_display_chat() );
	}

	/**
	 * A "matches" rule requires an exact URL in include mode.
	 */
	public function test_include_path_matches_exact() {
		$this->set_settings(
			[
				'display_mode'  => 'include',
				'display_rules' => [
					[
						'path'     => '/contact/',
						'operator' => 'matches',
					],
				],
			]
		);

		$_SERVER['REQUEST_URI'] = '/contact/';
		$this->assertTrue( $this->main->should_display_chat() );

		$_SERVER['REQUEST_URI'] = '/contact/sub/';
		$this->assertFalse( $this->main->should_display_chat() );
	}

	/**
	 * Exclude mode is the inverse of include: matching URLs are hidden.
	 */
	public function test_exclude_inverts_matching() {
		$this->set_settings(
			[
				'display_mode'  => 'exclude',
				'display_rules' => [
					[
						'path'     => '/shop',
						'operator' => 'contains',
					],
				],
			]
		);

		$_SERVER['REQUEST_URI'] = '/shop/product-a/';
		$this->assertFalse( $this->main->should_display_chat() );

		$_SERVER['REQUEST_URI'] = '/about/';
		$this->assertTrue( $this->main->should_display_chat() );
	}

	/**
	 * The SDK migration persists display_mode from the legacy flag, then stops.
	 */
	public function test_migration_persists_display_mode() {
		update_option( 'hyve_settings', [ 'chat_enabled' => false ] );

		if ( ! class_exists( '\ThemeisleSDK\Modules\Abstract_Migration' ) ) {
			require_once HYVE_LITE_PATH . '/vendor/codeinwp/themeisle-sdk/src/Modules/Abstract_Migration.php';
		}

		$migration = require HYVE_LITE_PATH . '/migrations/20260703120000_migrate_chat_display_mode.php';

		$this->assertTrue( $migration->should_run() );

		$migration->up();

		$saved = get_option( 'hyve_settings' );
		$this->assertSame( 'manual', $saved['display_mode'] );
		$this->assertFalse( $migration->should_run() );
	}
}
