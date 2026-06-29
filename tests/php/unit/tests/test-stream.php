<?php
/**
 * Test_Stream class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Stream;

/**
 * Class Test_Stream.
 */
class Test_Stream extends WP_UnitTestCase {
	/**
	 * A complete JSON object yields the full response value.
	 */
	public function testCompleteResponse() {
		$json = '{"response":"<p>Hi</p>","success":true}';
		$this->assertSame( '<p>Hi</p>', Stream::extract_partial_response( $json ) );
	}

	/**
	 * A response field that has begun but not closed yields what is available.
	 */
	public function testPartialResponse() {
		$this->assertSame( '<p>Hel', Stream::extract_partial_response( '{"response":"<p>Hel' ) );
	}

	/**
	 * The response key may appear after other fields.
	 */
	public function testResponseAfterOtherKeys() {
		$json = '{"success":true,"response":"<p>Hi</p>"}';
		$this->assertSame( '<p>Hi</p>', Stream::extract_partial_response( $json ) );
	}

	/**
	 * JSON escapes inside the response are decoded.
	 */
	public function testDecodesEscapes() {
		$this->assertSame( "a\nb", Stream::extract_partial_response( '{"response":"a\nb"}' ) );
		$this->assertSame( 'say "hi"', Stream::extract_partial_response( '{"response":"say \"hi\""}' ) );
		$this->assertSame( 'café', Stream::extract_partial_response( '{"response":"café"}' ) );
	}

	/**
	 * Surrogate-pair characters (emoji) decode to the real character.
	 */
	public function testDecodesSurrogatePairEmoji() {
		$this->assertSame( '😀', Stream::extract_partial_response( '{"response":"😀"}' ) );
		$this->assertSame( 'hi 😀', Stream::extract_partial_response( '{"response":"hi 😀","success":true}' ) );
	}

	/**
	 * A lone high surrogate (awaiting its low half) is held back, not emitted broken.
	 */
	public function testHoldsLoneHighSurrogate() {
		$this->assertSame( 'hi ', Stream::extract_partial_response( '{"response":"hi \uD83D' ) );
	}

	/**
	 * An incomplete \u escape is held back.
	 */
	public function testHoldsIncompleteUnicodeEscape() {
		$this->assertSame( 'a', Stream::extract_partial_response( '{"response":"a\u00' ) );
	}

	/**
	 * A literal multibyte character split across the buffer is held back, not
	 * emitted as a broken byte sequence.
	 */
	public function testHoldsSplitMultibyte() {
		// 'café' as raw UTF-8, cut after the first byte of 'é' (0xC3).
		$this->assertSame( 'caf', Stream::extract_partial_response( "{\"response\":\"caf\xc3" ) );
	}

	/**
	 * A trailing, incomplete escape is held back rather than emitted broken.
	 */
	public function testIncompleteTrailingEscape() {
		$this->assertSame( 'a', Stream::extract_partial_response( '{"response":"a\\' ) );
	}

	/**
	 * Null until the response field has started.
	 */
	public function testNullBeforeResponseField() {
		$this->assertNull( Stream::extract_partial_response( '{"succ' ) );
		$this->assertNull( Stream::extract_partial_response( '' ) );
	}
}
