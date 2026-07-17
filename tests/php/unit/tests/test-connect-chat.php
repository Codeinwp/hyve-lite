<?php
/**
 * Tests for the Hyve Connect chat REST flow (poll path recording).
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Threads;
use ThemeIsle\HyveLite\Hyve_Connect;

/**
 * Class ConnectChatTest.
 */
class ConnectChatTest extends WP_UnitTestCase {

	/**
	 * Reset filters and options between tests.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'hyve_settings' );
		parent::tearDown();
	}

	/**
	 * Intercept the platform call with a buffered SSE job_complete frame.
	 *
	 * @param array<string, mixed> $data Terminal event payload.
	 *
	 * @return void
	 */
	private function intercept_job_complete( array $data ) {
		$body = 'event: job_complete' . "\n" . 'data: ' . wp_json_encode( $data ) . "\n\n";

		add_filter(
			'pre_http_request',
			function () use ( $body ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $body,
				];
			},
			10,
			3
		);
	}

	/**
	 * The poll flow records both the visitor message and the assistant reply on
	 * one thread, and hands the record id back so the next turn threads onto it.
	 */
	public function test_connect_poll_chat_records_the_conversation() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$this->intercept_job_complete(
			[
				'thread_id' => 'th-1',
				'answered'  => true,
				'reply'     => 'Hello there.',
			]
		);

		$send = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$send->set_param( 'message', 'Hi' );

		$sent = API::instance()->send_chat( $send )->get_data();

		// A thread record was created and its id returned (previously null).
		$this->assertNotEmpty( $sent['record_id'] );
		$this->assertNotEmpty( $sent['query_run'] );
		$this->assertSame( 1, (int) Threads::get_thread_count() );

		$poll = new WP_REST_Request( 'GET', '/hyve/v1/chat' );
		$poll->set_param( 'run_id', $sent['query_run'] );

		API::instance()->get_chat( $poll );

		// Still one thread, now holding the visitor message and the bot reply.
		$this->assertSame( 1, (int) Threads::get_thread_count() );
		$this->assertSame( 2, (int) get_post_meta( (int) $sent['record_id'], '_hyve_thread_count', true ) );
	}
}
