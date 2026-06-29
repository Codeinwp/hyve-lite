<?php
/**
 * Tests for OpenAI chat model resolution.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\OpenAI;

/**
 * Class Test_OpenAI_Model.
 */
class Test_OpenAI_Model extends WP_UnitTestCase {

	/**
	 * GPT-3.5 models fall back to the default.
	 */
	public function test_gpt35_falls_back_to_default() {
		$this->assertEquals( 'gpt-4o-mini', OpenAI::resolve_chat_model( 'gpt-3.5-turbo-0125' ) );
		$this->assertEquals( 'gpt-4o-mini', OpenAI::resolve_chat_model( 'gpt-3.5-turbo' ) );
	}

	/**
	 * Empty or invalid values fall back to the default.
	 */
	public function test_empty_or_invalid_falls_back_to_default() {
		$this->assertEquals( 'gpt-4o-mini', OpenAI::resolve_chat_model( '' ) );
		$this->assertEquals( 'gpt-4o-mini', OpenAI::resolve_chat_model( null ) );
		$this->assertEquals( 'gpt-4o-mini', OpenAI::resolve_chat_model( [] ) );
	}

	/**
	 * Supported models are returned unchanged.
	 */
	public function test_supported_models_pass_through() {
		foreach ( [ 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-4.1', 'gpt-4o-mini', 'gpt-4o' ] as $model ) {
			$this->assertEquals( $model, OpenAI::resolve_chat_model( $model ) );
		}
	}
}
