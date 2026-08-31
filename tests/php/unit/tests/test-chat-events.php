<?php
/**
 * Test_Chat_Events class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Threads;

/**
 * Class Test_Chat_Events.
 *
 * Covers the event entries recorded for failed chat turns (moderation flags,
 * rate limits, hard errors): the visitor's message is kept, the failure lands
 * as an `event` divider — never as a bot reply — and abusive rate-limited
 * traffic cannot grow the database.
 */
class Test_Chat_Events extends WP_UnitTestCase {

	/**
	 * Read the transcript of a thread.
	 *
	 * @param int $post_id The thread post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function entries( $post_id ) {
		$thread_data = get_post_meta( $post_id, '_hyve_thread_data', true );

		$this->assertIsArray( $thread_data );

		return $thread_data;
	}

	/**
	 * A moderation-flagged first turn records the visitor message plus a
	 * moderation event with the flagged categories — and no bot reply.
	 */
	public function test_moderation_failure_records_message_and_event() {
		API::instance()->record_chat_failure(
			'',
			null,
			'An innocent question',
			false,
			'content_flagged',
			'This message was flagged.',
			[ 'harassment' => 0.91 ]
		);

		$threads = get_posts(
			[
				'post_type'      => 'hyve_threads',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			]
		);

		$this->assertCount( 1, $threads );

		$entries = $this->entries( $threads[0]->ID );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'user', $entries[0]['sender'] );
		$this->assertSame( 'An innocent question', $entries[0]['message'] );
		$this->assertSame( 'event', $entries[1]['sender'] );
		$this->assertSame( 'moderation_flagged', $entries[1]['message'] );
		$this->assertSame( [ 'harassment' ], $entries[1]['debug']['categories'] );
		$this->assertSame( 'harassment', $entries[1]['debug']['detail'] );
	}

	/**
	 * A later successful turn adopts the real conversation id instead of
	 * forking a second thread off the failed turn's placeholder.
	 */
	public function test_failed_first_turn_thread_adopts_real_conversation_id() {
		API::instance()->record_chat_failure( '', null, 'Hello?', false, 'no_embeddings', 'Embedding failed.' );

		$threads = get_posts(
			[
				'post_type'      => 'hyve_threads',
				'posts_per_page' => 5,
				'post_status'    => 'publish',
			]
		);

		$this->assertCount( 1, $threads );
		$record_id = $threads[0]->ID;

		$result = Threads::add_message(
			$record_id,
			[
				'thread_id' => 'conv_real_123',
				'sender'    => 'user',
				'message'   => 'Hello again',
			]
		);

		// Same thread, no fork; the placeholder id was replaced.
		$this->assertSame( $record_id, $result );
		$this->assertSame( 'conv_real_123', get_post_meta( $record_id, '_hyve_thread_id', true ) );
		$this->assertCount( 3, $this->entries( $record_id ) );
	}

	/**
	 * Rate-limit events only land on existing threads and consecutive
	 * occurrences collapse into a single event.
	 */
	public function test_rate_limited_event_is_bounded() {
		// No thread: nothing recorded, nothing created.
		$this->assertFalse( API::instance()->record_rate_limited_event( 12345 ) );

		$record_id = Threads::create_thread(
			'Hi',
			[
				'thread_id' => 'conv_1',
				'sender'    => 'user',
				'message'   => 'Hi',
			]
		);

		$this->assertTrue( API::instance()->record_rate_limited_event( $record_id ) );
		$this->assertFalse( API::instance()->record_rate_limited_event( $record_id ) );
		$this->assertFalse( API::instance()->record_rate_limited_event( $record_id ) );

		$entries = $this->entries( $record_id );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'event', $entries[1]['sender'] );
		$this->assertSame( 'rate_limited', $entries[1]['message'] );
	}

	/**
	 * An error on an already-recorded turn appends only the event marker,
	 * and admin test chats record nothing.
	 */
	public function test_error_event_appends_marker_only() {
		$record_id = Threads::create_thread(
			'Hi',
			[
				'thread_id' => 'conv_2',
				'sender'    => 'user',
				'message'   => 'Hi',
			]
		);

		API::instance()->record_error_event( 'conv_2', $record_id, 'openai_error', 'HTTP 500 from the API.' );

		$entries = $this->entries( $record_id );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'event', $entries[1]['sender'] );
		$this->assertSame( 'chat_error', $entries[1]['message'] );
		$this->assertSame( 'openai_error', $entries[1]['debug']['code'] );
		$this->assertSame( 'HTTP 500 from the API.', $entries[1]['debug']['detail'] );

		// Test chats never record.
		API::instance()->record_error_event( 'conv_2', $record_id, 'openai_error', 'again', true );
		$this->assertCount( 2, $this->entries( $record_id ) );
	}
}
