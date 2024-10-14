<?php
/**
 * Test_Tokenizer class.
 * 
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Tokenizer;

/**
 * Class Test_Tokenizer.
 */
class Test_Tokenizer extends WP_UnitTestCase {
	/**
	 * Test tokenize method with short content.
	 */
	public function testTokenizeShortContent() {
		$post = [
			'ID'      => 1,
			'title'   => 'Test Title',
			'content' => 'This is a short content.',
		];

		$result = Tokenizer::tokenize( $post );

		$this->assertCount( 1, $result );
		$this->assertEquals( $post['ID'], $result[0]['post_id'] );
		$this->assertEquals( $post['title'], $result[0]['post_title'] );
		$this->assertEquals( $post['content'], $result[0]['post_content'] );
		$this->assertArrayHasKey( 'tokens', $result[0] );
		$this->assertArrayHasKey( 'token_count', $result[0] );
	}

	/**
	 * Test tokenize method with long content.
	 */
	public function testTokenizeLongContent() {
		$post = [
			'ID'      => 1,
			'title'   => 'Test Title',
			'content' => str_repeat( 'This is a long content. ', 1000 ),
		];

		$result = Tokenizer::tokenize( $post );

		$this->assertGreaterThan( 1, count( $result ) );
		foreach ( $result as $chunk ) {
			$this->assertEquals( $post['ID'], $chunk['post_id'] );
			$this->assertEquals( $post['title'], $chunk['post_title'] );
			$this->assertArrayHasKey( 'tokens', $chunk );
			$this->assertArrayHasKey( 'token_count', $chunk );
		}
	}
}
