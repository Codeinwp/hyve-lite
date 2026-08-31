<?php
/**
 * Test_Threads_Debug class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Threads;

/**
 * Class Test_Threads_Debug.
 *
 * Covers the per-message debug trace recorded with bot replies (issue: event
 * information in messages history), so an answer — or a refusal — can be
 * explained from the Messages screen after the fact.
 */
class Test_Threads_Debug extends WP_UnitTestCase {

	/**
	 * The Threads instance under test.
	 *
	 * @var Threads
	 */
	private $threads;

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->threads = new Threads();
	}

	/**
	 * Create a thread with an initial user message and return its post ID.
	 *
	 * @return int
	 */
	private function create_thread() {
		return Threads::create_thread(
			'Hello',
			[
				'thread_id' => 'thread-1',
				'sender'    => 'user',
				'message'   => 'Hello',
			]
		);
	}

	/**
	 * Read the last transcript entry of a thread.
	 *
	 * @param int $post_id The thread post ID.
	 *
	 * @return array<string, mixed>
	 */
	private function last_entry( $post_id ) {
		$thread_data = get_post_meta( $post_id, '_hyve_thread_data', true );

		$this->assertIsArray( $thread_data );

		return end( $thread_data );
	}

	/**
	 * A bot reply records its debug trace and the answered flag.
	 */
	public function test_record_message_persists_debug_trace() {
		$post_id = $this->create_thread();

		$this->threads->record_message(
			'run-1',
			'thread-1',
			'How much does laundry cost?',
			$post_id,
			[
				'success' => true,
				'debug'   => [
					'mode'      => 'self_hosted',
					'transport' => 'poll',
					'threshold' => 0.4,
					'query'     => 'How much does laundry cost?',
					'context'   => [
						[
							'post_id' => 7,
							'title'   => 'Rates',
							'score'   => 0.6123,
							'tokens'  => 512,
						],
					],
				],
			],
			'Laundry is $32.00/hr.'
		);

		$entry = $this->last_entry( $post_id );

		$this->assertSame( 'bot', $entry['sender'] );
		$this->assertArrayHasKey( 'debug', $entry );
		$this->assertTrue( $entry['debug']['answered'] );
		$this->assertSame( 0.4, $entry['debug']['threshold'] );
		$this->assertSame( 'self_hosted', $entry['debug']['mode'] );
		$this->assertCount( 1, $entry['debug']['context'] );
		$this->assertSame( 7, $entry['debug']['context'][0]['post_id'] );
	}

	/**
	 * An unanswered reply is recorded as such, even when retrieval found nothing.
	 */
	public function test_record_message_marks_unanswered_turn() {
		$post_id = $this->create_thread();

		$this->threads->record_message(
			'run-2',
			'thread-1',
			'What is your email address?',
			$post_id,
			[
				'success' => false,
				'debug'   => [
					'mode'    => 'self_hosted',
					'context' => [],
				],
			],
			'Sorry, I am not able to help with that.'
		);

		$entry = $this->last_entry( $post_id );

		$this->assertFalse( $entry['debug']['answered'] );
		$this->assertSame( [], $entry['debug']['context'] );
	}

	/**
	 * A payload with a success flag but no debug data still records the
	 * answered state, so Connect turns and older flows stay covered.
	 */
	public function test_record_message_records_answered_without_debug() {
		$post_id = $this->create_thread();

		$this->threads->record_message(
			'run-3',
			'thread-1',
			'Hi',
			$post_id,
			[ 'success' => true ],
			'Hi there!'
		);

		$entry = $this->last_entry( $post_id );

		$this->assertSame( [ 'answered' => true ], $entry['debug'] );
	}

	/**
	 * A payload without a success flag or debug data records no debug key,
	 * matching the previous entry shape.
	 */
	public function test_record_message_without_debug_keeps_legacy_shape() {
		$post_id = $this->create_thread();

		$this->threads->record_message(
			'run-4',
			'thread-1',
			'Hi',
			$post_id,
			[],
			'Hi there!'
		);

		$entry = $this->last_entry( $post_id );

		$this->assertArrayNotHasKey( 'debug', $entry );
	}

	/**
	 * Finalizing the debug trace merges reply-time facts over the retrieval
	 * trace and lets extensions extend it through the filter.
	 */
	public function test_finalize_chat_debug_merges_and_filters() {
		$callback = function ( $debug ) {
			$debug['skills'] = [ 'search_products' ];

			return $debug;
		};

		add_filter( 'hyve_chat_debug', $callback );

		$debug = API::finalize_chat_debug(
			[
				'threshold' => 0.4,
				'context'   => [],
			],
			[
				'mode'       => 'self_hosted',
				'transport'  => 'stream',
				'tools_used' => true,
			],
			[ 'success' => true ]
		);

		remove_filter( 'hyve_chat_debug', $callback );

		$this->assertSame( 0.4, $debug['threshold'] );
		$this->assertSame( 'stream', $debug['transport'] );
		$this->assertTrue( $debug['tools_used'] );
		$this->assertSame( [ 'search_products' ], $debug['skills'] );
	}

	/**
	 * Finalizing computes the turn latency from the started timestamp, and
	 * captures follow-ups and the raw model reply on unanswered turns.
	 */
	public function test_finalize_chat_debug_enriches_turn_facts() {
		$debug = API::finalize_chat_debug(
			[ 'started' => microtime( true ) - 1.5 ],
			[ 'mode' => 'self_hosted' ],
			[
				'success'    => false,
				'response'   => 'The model said something the visitor never saw.',
				'follow_ups' => [ 'What are your rates?', '', 42 ],
			]
		);

		$this->assertArrayNotHasKey( 'started', $debug );
		$this->assertGreaterThanOrEqual( 1400, $debug['duration_ms'] );
		$this->assertLessThan( 5000, $debug['duration_ms'] );
		$this->assertSame( [ 'What are your rates?' ], $debug['follow_ups'] );
		$this->assertSame( 'The model said something the visitor never saw.', $debug['raw_reply'] );
	}

	/**
	 * An answered turn records no raw reply: the visitor already saw the text.
	 */
	public function test_finalize_chat_debug_skips_raw_reply_when_answered() {
		$debug = API::finalize_chat_debug(
			[],
			[],
			[
				'success'  => true,
				'response' => 'Personal care costs $35.00 per hour.',
			]
		);

		$this->assertArrayNotHasKey( 'raw_reply', $debug );
	}

	/**
	 * Usage objects from the Responses API (and array equivalents) normalize
	 * into the compact stored shape; unreadable input returns null.
	 */
	public function test_normalize_usage() {
		$usage = API::normalize_usage(
			(object) [
				'input_tokens'  => 1911,
				'output_tokens' => 62,
			]
		);

		$this->assertSame(
			[
				'input'  => 1911,
				'output' => 62,
			],
			$usage 
		);

		$this->assertSame(
			[
				'input'  => 10,
				'output' => 5,
			],
			API::normalize_usage(
				[
					'input'  => 10,
					'output' => 5,
				] 
			)
		);

		$this->assertNull( API::normalize_usage( 'not-usage' ) );
		$this->assertNull( API::normalize_usage( [ 'foo' => 'bar' ] ) );
	}

	/**
	 * The Connect trace maps the platform-reported sources into the stored
	 * trace shape and ignores malformed rows.
	 */
	public function test_connect_debug_maps_platform_sources() {
		$debug = API::instance()->connect_debug(
			[
				'sources' => [
					[
						'id'    => 12,
						'title' => 'Pricing',
						'score' => 0.51234,
					],
					'not-an-array',
				],
			]
		);

		$this->assertCount( 1, $debug['context'] );
		$this->assertSame( 12, $debug['context'][0]['post_id'] );
		$this->assertSame( 'Pricing', $debug['context'][0]['title'] );
		$this->assertSame( 0.5123, $debug['context'][0]['score'] );

		$this->assertSame( [], API::instance()->connect_debug( [] ) );
	}
}
