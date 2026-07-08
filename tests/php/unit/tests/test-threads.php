<?php
/**
 * Test_Threads class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Threads;

/**
 * Class Test_Threads.
 *
 * Covers the dashboard stats cache invalidation so the Overview message total
 * does not go stale after a new message is recorded.
 */
class Test_Threads extends WP_UnitTestCase {

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		delete_transient( Threads::MESSAGES_COUNT_TRANSIENT );
		delete_transient( Threads::CHART_DATA_TRANSIENT );
		parent::tearDown();
	}

	/**
	 * Creating a thread invalidates a primed messages-count cache.
	 */
	public function test_create_thread_invalidates_count_cache() {
		// Prime the cache with the current (empty) total.
		$this->assertSame( 0, (int) Threads::get_messages_count() );
		$this->assertNotFalse( get_transient( Threads::MESSAGES_COUNT_TRANSIENT ) );

		Threads::create_thread(
			'Hello',
			[
				'thread_id' => 'thread-1',
				'sender'    => 'user',
				'message'   => 'Hello',
			]
		);

		// The stale cache must be gone so the next read recomputes.
		$this->assertFalse( get_transient( Threads::MESSAGES_COUNT_TRANSIENT ) );
		$this->assertSame( 1, (int) Threads::get_messages_count() );
	}

	/**
	 * Adding a message to a thread keeps the messages count current.
	 */
	public function test_add_message_updates_count() {
		$post_id = Threads::create_thread(
			'Hello',
			[
				'thread_id' => 'thread-1',
				'sender'    => 'user',
				'message'   => 'Hello',
			]
		);

		// Prime the cache at 1 (the user message).
		$this->assertSame( 1, (int) Threads::get_messages_count() );

		Threads::add_message(
			$post_id,
			[
				'thread_id' => 'thread-1',
				'sender'    => 'bot',
				'message'   => 'Hi there',
			]
		);

		// The bot reply must be reflected immediately, not after the cache TTL.
		$this->assertSame( 2, (int) Threads::get_messages_count() );
	}
}
