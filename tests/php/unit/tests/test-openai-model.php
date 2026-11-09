<?php
/**
 * Tests for OpenAI chat model resolution.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Main;
use ThemeIsle\HyveLite\OpenAI;

/**
 * Class Test_OpenAI_Model.
 */
class Test_OpenAI_Model extends WP_UnitTestCase {

	/**
	 * Clean up stored settings.
	 */
	public function tear_down() {
		delete_option( 'hyve_settings' );
		parent::tear_down();
	}

	/**
	 * Empty or invalid values fall back to the default.
	 */
	public function test_empty_or_invalid_falls_back_to_default() {
		$this->assertEquals( 'gpt-5.4-nano', OpenAI::resolve_chat_model( '' ) );
		$this->assertEquals( 'gpt-5.4-nano', OpenAI::resolve_chat_model( null ) );
		$this->assertEquals( 'gpt-5.4-nano', OpenAI::resolve_chat_model( [] ) );
	}

	/**
	 * Supported models are returned unchanged.
	 */
	public function test_supported_models_pass_through() {
		// gpt-5-mini and gpt-5-nano are sibling families of a retired model, not snapshots of it.
		foreach ( [ 'gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.5', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-5.4-mini-2026-03-17', 'gpt-5-mini', 'gpt-5-nano', 'gpt-5-mini-2025-08-07' ] as $model ) {
			$this->assertEquals( $model, OpenAI::resolve_chat_model( $model ) );
		}
	}

	/**
	 * Retired models and their variants resolve to the nearest successor.
	 */
	public function test_retired_models_resolve_to_successor() {
		$expected = [
			'gpt-4.1-nano'            => 'gpt-5.4-nano',
			'gpt-4.1-mini'            => 'gpt-5.4-nano',
			'gpt-4o-mini'             => 'gpt-5.4-nano',
			'gpt-4.1'                 => 'gpt-5.4-mini',
			'gpt-4o'                  => 'gpt-5.4-mini',
			'gpt-5'                   => 'gpt-5.4-mini',
			'gpt-4o-2024-08-06'       => 'gpt-5.4-mini',
			'gpt-4o-mini-2024-07-18'  => 'gpt-5.4-nano',
			'gpt-4.1-mini-2025-04-14' => 'gpt-5.4-nano',
			'gpt-5-2025-08-07'        => 'gpt-5.4-mini',
			'gpt-3.5-turbo'           => 'gpt-5.4-nano',
			'gpt-3.5-turbo-0125'      => 'gpt-5.4-nano',
			'gpt-3.5-turbo-16k-0613'  => 'gpt-5.4-nano',
		];

		foreach ( $expected as $model => $successor ) {
			$this->assertSame( $successor, OpenAI::resolve_chat_model( $model ), $model );
		}
	}

	/**
	 * Every successor is a model that is itself still supported.
	 */
	public function test_successors_are_not_retired() {
		foreach ( OpenAI::RETIRED_CHAT_MODELS as $successor ) {
			$this->assertSame( $successor, OpenAI::resolve_chat_model( $successor ) );
		}
	}

	/**
	 * A stored retired model reaches settings consumers and the client as its successor.
	 */
	public function test_stored_retired_model_is_resolved_everywhere() {
		update_option( 'hyve_settings', [ 'chat_model' => 'gpt-4.1-nano' ] );

		$this->assertSame( 'gpt-5.4-nano', Main::get_settings()['chat_model'] );
		$this->assertSame( 'gpt-5.4-nano', ( new OpenAI( 'sk-test' ) )->get_chat_model() );

		update_option( 'hyve_settings', [ 'chat_model' => 'gpt-4o' ] );

		$this->assertSame( 'gpt-5.4-mini', Main::get_settings()['chat_model'] );
	}
}
