<?php
/**
 * Tests for Block.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Block;

/**
 * Class Test_Block.
 */
class Test_Block extends WP_UnitTestCase {
	/**
	 * Test render_shortcode method with floating attribute.
	 */
	public function test_render_shortcode_with_floating() {
		$block  = new Block();
		$atts   = [ 'floating' => 'true' ];
		$result = $block->render_shortcode( $atts );
		$this->assertStringContainsString( 'id="hyve-chat"', $result );
	}

	/**
	 * Test render_shortcode method without floating attribute.
	 */
	public function test_render_shortcode_without_floating() {
		$block  = new Block();
		$atts   = [];
		$result = $block->render_shortcode( $atts );
		$this->assertStringContainsString( 'id="hyve-inline-chat"', $result );
	}
}
