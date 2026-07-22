<?php
/**
 * Test_Tool_Loop class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\OpenAI;
use ThemeIsle\HyveLite\Threads;

/**
 * Class Test_Tool_Loop.
 *
 * Covers the lite side of the tool-calling seam: extracting function calls
 * from a Response, answering calls that will never be executed (pending calls
 * poison the conversation otherwise), forcing a text answer on the closing
 * turn, and carrying a skill display with the stored transcript.
 */
class Test_Tool_Loop extends WP_UnitTestCase {

	/**
	 * Function calls are extracted from a Response output; other item types
	 * are ignored and missing fields default to empty strings.
	 */
	public function test_extract_tool_calls() {
		$response = (object) [
			'output' => [
				(object) [
					'type' => 'message',
					'role' => 'assistant',
				],
				(object) [
					'type'      => 'function_call',
					'call_id'   => 'call_1',
					'name'      => 'acme__thing',
					'arguments' => '{"q":1}',
				],
				(object) [
					'type' => 'function_call',
				],
			],
		];

		$calls = OpenAI::extract_tool_calls( $response );

		$this->assertCount( 2, $calls );
		$this->assertSame( 'call_1', $calls[0]['call_id'] );
		$this->assertSame( 'acme__thing', $calls[0]['name'] );
		$this->assertSame( '{"q":1}', $calls[0]['arguments'] );
		$this->assertSame( '', $calls[1]['call_id'] );

		$this->assertSame( [], OpenAI::extract_tool_calls( (object) [] ) );
	}

	/**
	 * Aborting answers every pending call with an error output, skipping
	 * entries with no call_id.
	 */
	public function test_abort_tool_calls_answers_every_call() {
		$outputs = OpenAI::abort_tool_calls(
			[
				[
					'call_id'   => 'call_1',
					'name'      => 'acme__thing',
					'arguments' => '{}',
				],
				[
					'call_id'   => '',
					'name'      => 'acme__thing',
					'arguments' => '{}',
				],
			]
		);

		$this->assertCount( 1, $outputs );
		$this->assertSame( 'function_call_output', $outputs[0]['type'] );
		$this->assertSame( 'call_1', $outputs[0]['call_id'] );
		$this->assertArrayHasKey( 'error', json_decode( $outputs[0]['output'], true ) );
	}

	/**
	 * Tool selection is disabled only when tools are actually present, so the
	 * closing turn cannot request another round and a tool-less request stays
	 * valid.
	 */
	public function test_suppress_tools() {
		$params = OpenAI::suppress_tools( [ 'tools' => [ [ 'type' => 'function' ] ] ] );
		$this->assertSame( 'none', $params['tool_choice'] );

		$params = OpenAI::suppress_tools( [ 'model' => 'x' ] );
		$this->assertArrayNotHasKey( 'tool_choice', $params );
	}

	/**
	 * A skill display rides with the stored transcript entry, and only when
	 * it is a non-empty array.
	 */
	public function test_thread_entries_carry_display() {
		$display = [
			'type'  => 'list',
			'items' => [ [ 'label' => 'One' ] ],
		];

		$post_id = Threads::create_thread(
			'Hello',
			[
				'thread_id' => 'thread-1',
				'sender'    => 'user',
				'message'   => 'Hello',
			]
		);

		Threads::add_message(
			$post_id,
			[
				'thread_id' => 'thread-1',
				'sender'    => 'bot',
				'message'   => '<p>Cards below.</p>',
				'display'   => $display,
			]
		);

		Threads::add_message(
			$post_id,
			[
				'thread_id' => 'thread-1',
				'sender'    => 'bot',
				'message'   => '<p>No cards.</p>',
				'display'   => null,
			]
		);

		$entries = get_post_meta( $post_id, '_hyve_thread_data', true );

		$this->assertSame( $display, $entries[1]['display'] );
		$this->assertArrayNotHasKey( 'display', $entries[2] );
	}
}
