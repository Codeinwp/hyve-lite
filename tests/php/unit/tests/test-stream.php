<?php
/**
 * Test_Stream class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Stream;
use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Hyve_Connect;
use ThemeIsle\HyveLite\OpenAI;

/**
 * Class Test_Stream.
 */
class Test_Stream extends WP_UnitTestCase {
	/**
	 * Provider markup is allowlisted before a completed response reaches the browser.
	 */
	public function test_completed_response_html_is_sanitized() {
		$result = OpenAI::interpret_chat_payload(
			wp_json_encode(
				[
					'response' => '<p>Hello <strong>there</strong>.</p><img src="x" onerror="alert(1)"><script>alert(2)</script><a href="javascript:alert(3)">bad</a>',
					'success'  => true,
				]
			),
			'No answer.'
		);

		$this->assertTrue( $result['answered'] );
		$this->assertStringContainsString( '<strong>there</strong>', $result['final'] );
		$this->assertStringNotContainsString( 'onerror', $result['final'] );
		$this->assertStringNotContainsString( '<script', $result['final'] );
		$this->assertStringNotContainsString( 'javascript:', $result['final'] );
		$this->assertSame( $result['final'], $result['payload']['response'] );
	}

	/**
	 * Hosted replies cross the same output boundary as self-hosted replies.
	 */
	public function test_connect_response_html_is_sanitized() {
		$result = API::instance()->connect_reply_final(
			[
				'answered' => true,
				'reply'    => '<p>Hosted.</p><svg onload="alert(1)"></svg>',
			],
			[],
			'No answer.'
		);

		$this->assertSame( '<p>Hosted.</p>', $result['reply'] );
		$this->assertSame( $result['reply'], $result['final'] );
	}

	/**
	 * A "<" that cannot open a tag stays text instead of swallowing the words after it.
	 */
	public function test_bare_less_than_stays_text() {
		$this->assertSame(
			'If a &lt; b and c &gt; d then stop. <strong>Note</strong>',
			OpenAI::sanitize_chat_response( 'If a < b and c > d then stop. <strong>Note</strong>' )
		);
	}

	/**
	 * Self-hosted poll: markup an extension adds to the reply data is sanitized.
	 */
	public function test_self_hosted_poll_chat_sanitizes_filtered_reply_markup() {
		update_option(
			'hyve_settings',
			[
				'ai_mode' => Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			]
		);
		$instance = new ReflectionProperty( OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		add_filter(
			'pre_http_request',
			function () {
				$text = wp_json_encode(
					[
						'response' => '<p>Hi</p>',
						'success'  => true,
					]
				);

				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'id'     => 'resp_test',
							'status' => 'completed',
							'output' => [
								[
									'type'    => 'message',
									'status'  => 'completed',
									'role'    => 'assistant',
									'content' => [ [ 'text' => $text ] ],
								],
							],
						]
					),
				];
			}
		);
		add_filter(
			'hyve_chat_reply_data',
			function ( $data ) {
				$data['message'] .= '<img src="x" onerror="alert(1)">';
				return $data;
			}
		);

		$request = new WP_REST_Request( 'GET', '/hyve/v1/chat' );
		$request->set_param( 'run_id', 'resp_test' );
		$request->set_param( 'thread_id', 'conv_local' );
		$request->set_param( 'is_test', true );

		$message = rest_ensure_response( API::instance()->get_chat( $request ) )->get_data()['message'];
		$instance->setValue( null, null );

		$this->assertSame( '<p>Hi</p><img src="x">', $message );
	}

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
