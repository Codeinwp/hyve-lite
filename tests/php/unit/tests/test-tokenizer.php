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

	/**
	 * Table cells keep their label ("Laundry | $32.00/hr") instead of fusing
	 * into "Laundry$32.00/hr", and rows stay on separate lines.
	 */
	public function test_html_to_text_preserves_table_structure() {
		$html = '<figure class="wp-block-table"><table><thead><tr><th>Service</th><th>Rate</th></tr></thead>' .
			'<tbody><tr><td>Laundry</td><td>$32.00/hr</td></tr><tr><td>Ironing</td><td>$28.00/hr</td></tr></tbody></table></figure>';

		$text = Tokenizer::html_to_text( $html );

		$this->assertStringContainsString( 'Service | Rate', $text );
		$this->assertStringContainsString( 'Laundry | $32.00/hr', $text );
		$this->assertStringContainsString( "Laundry | \$32.00/hr\nIroning", $text );
		$this->assertStringNotContainsString( 'Laundry$32', $text );
	}

	/**
	 * Headings do not fuse with the paragraph below, and entities decode so
	 * the stored text matches what a visitor would type.
	 */
	public function test_html_to_text_separates_blocks_and_decodes_entities() {
		$html = '<h2>Our Services</h2><p>Care O&#8217;Clock offers home care &amp; support.</p>';

		$text = Tokenizer::html_to_text( $html );

		$this->assertSame( "Our Services\nCare O’Clock offers home care & support.", $text );
	}

	/**
	 * A table larger than the chunk budget has no ". " sentence boundaries; it
	 * used to be dropped entirely, leaving the content unsearchable. It must
	 * chunk instead, with every row surviving somewhere in the output.
	 */
	public function test_tokenize_keeps_oversized_sentence_less_content() {
		$rows = '';

		for ( $i = 1; $i <= 400; $i++ ) {
			$rows .= sprintf( '<tr><td>Specialized long-running service number %d</td><td>$%d.00/hr</td></tr>', $i, $i );
		}

		$post = [
			'ID'      => 1,
			'title'   => 'Services & Rates',
			'content' => '<table><tbody>' . $rows . '</tbody></table>',
		];

		$result = Tokenizer::tokenize( $post );

		$this->assertGreaterThan( 1, count( $result ) );

		$all_text = implode( "\n", array_column( $result, 'post_content' ) );

		$this->assertStringContainsString( 'service number 1 | $1.00/hr', $all_text );
		$this->assertStringContainsString( 'service number 400 | $400.00/hr', $all_text );

		foreach ( $result as $chunk ) {
			$this->assertLessThanOrEqual( 1100, $chunk['token_count'] );
		}
	}

	/**
	 * A single boundary-less blob larger than the chunk size is hard-split by
	 * tokens, not discarded.
	 */
	public function test_create_chunks_hard_splits_oversized_segment() {
		$blob = 'START' . str_repeat( 'x7f9q2 ', 2000 ) . 'END';

		$chunks = Tokenizer::create_chunks( $blob, 100 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertStringContainsString( 'START', $chunks[0] );
		$this->assertStringContainsString( 'END', end( $chunks ) );
	}

	/**
	 * Sentences keep their own punctuation: no doubled periods, no invented
	 * separators.
	 */
	public function test_create_chunks_keeps_punctuation() {
		$chunks = Tokenizer::create_chunks( 'First sentence. Second one! Third?', 1000 );

		$this->assertCount( 1, $chunks );
		$this->assertSame( "First sentence.\nSecond one!\nThird?", $chunks[0] );
	}
}
