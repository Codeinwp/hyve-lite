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

	/**
	 * A Connect reply records its debug trace: mode and transport, the latency
	 * measured around the platform call, the visitor's page, the query the
	 * platform searched for, and the sources and usage it reported. The
	 * threshold stays platform-side and is absent by design.
	 */
	public function test_connect_poll_chat_records_debug_trace() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$this->intercept_job_complete(
			[
				'thread_id'       => 'th-2',
				'answered'        => true,
				'reply'           => 'Laundry is $32.00/hr.',
				'retrieval_query' => 'How much does laundry cost?',
				'query_rewritten' => true,
				'sources'         => [
					[
						'id'    => 42,
						'title' => 'Services & Rates',
						'score' => 0.6123,
					],
				],
				'usage'           => [
					'input_tokens'  => 900,
					'output_tokens' => 40,
				],
			]
		);

		$send = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$send->set_param( 'message', 'How much is laundry?' );
		$send->set_param( 'page_url', home_url( '/rates/' ) );

		$sent = API::instance()->send_chat( $send )->get_data();

		$poll = new WP_REST_Request( 'GET', '/hyve/v1/chat' );
		$poll->set_param( 'run_id', $sent['query_run'] );

		API::instance()->get_chat( $poll );

		$entries = get_post_meta( (int) $sent['record_id'], '_hyve_thread_data', true );
		$bot     = end( $entries );

		$this->assertSame( 'bot', $bot['sender'] );
		$this->assertArrayHasKey( 'debug', $bot );

		$debug = $bot['debug'];

		$this->assertTrue( $debug['answered'] );
		$this->assertSame( 'connect', $debug['mode'] );
		$this->assertSame( 'poll', $debug['transport'] );
		$this->assertIsInt( $debug['duration_ms'] );
		$this->assertSame( home_url( '/rates/' ), $debug['page'] );
		$this->assertCount( 1, $debug['context'] );
		$this->assertSame( 42, $debug['context'][0]['post_id'] );
		$this->assertSame( 0.6123, $debug['context'][0]['score'] );
		$this->assertSame(
			[
				'input'  => 900,
				'output' => 40,
			],
			$debug['usage']
		);

		$this->assertSame( 'How much does laundry cost?', $debug['query'] );
		$this->assertTrue( $debug['query_rewritten'] );

		// The platform's threshold is not knowable plugin-side.
		$this->assertArrayNotHasKey( 'threshold', $debug );
	}

	/**
	 * A query the platform searched as sent is recorded without the rewritten
	 * flag, and an empty one is left out rather than recorded.
	 */
	public function test_connect_debug_records_an_unrewritten_platform_query() {
		$debug = API::instance()->connect_debug(
			[
				'reply'           => 'Hi',
				'retrieval_query' => 'Do you sell gift cards?',
			]
		);

		$this->assertSame( 'Do you sell gift cards?', $debug['query'] );
		$this->assertArrayNotHasKey( 'query_rewritten', $debug );

		$empty = API::instance()->connect_debug(
			[
				'reply'           => 'Hi',
				'retrieval_query' => '',
			]
		);

		$this->assertArrayNotHasKey( 'query', $empty );
	}

	/**
	 * Send a message and poll for its reply, the way the widget does.
	 *
	 * @param string $message Visitor message.
	 *
	 * @return void
	 */
	private function run_connect_turn( $message ) {
		$send = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$send->set_param( 'message', $message );

		$sent = API::instance()->send_chat( $send )->get_data();

		$poll = new WP_REST_Request( 'GET', '/hyve/v1/chat' );
		$poll->set_param( 'run_id', $sent['query_run'] );

		API::instance()->get_chat( $poll );
	}

	/**
	 * An unanswered Connect turn caches the query vector the platform returned,
	 * under the same key the self-hosted path uses, so unanswered-question
	 * analytics can group the question.
	 */
	public function test_connect_poll_chat_caches_the_question_vector() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$vector = [ 0.11, 0.22, 0.33 ];

		$this->intercept_job_complete(
			[
				'thread_id'          => 'th-3',
				'answered'           => false,
				'reply'              => '',
				'question_embedding' => $vector,
			]
		);

		$this->run_connect_turn( 'Milyen névnap van március 7-én?' );

		$hash = hash( 'md5', strtolower( 'Milyen névnap van március 7-én?' ) );

		$this->assertSame( $vector, get_transient( 'hyve_message_' . $hash ) );
	}

	/**
	 * An answered turn carries no vector, so nothing is cached for it, while an
	 * unanswered one in the same install is cached.
	 */
	public function test_connect_poll_chat_caches_only_when_a_vector_arrives() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$this->intercept_job_complete(
			[
				'thread_id'          => 'th-4',
				'answered'           => false,
				'reply'              => '',
				'question_embedding' => [ 0.4, 0.5 ],
			]
		);

		$this->run_connect_turn( 'Hol van a bolt?' );

		remove_all_filters( 'pre_http_request' );

		$this->intercept_job_complete(
			[
				'thread_id' => 'th-5',
				'answered'  => true,
				'reply'     => 'Hello there.',
			]
		);

		$this->run_connect_turn( 'Hi' );

		$this->assertSame( [ 0.4, 0.5 ], get_transient( 'hyve_message_' . hash( 'md5', strtolower( 'Hol van a bolt?' ) ) ) );
		$this->assertFalse( get_transient( 'hyve_message_' . hash( 'md5', 'hi' ) ) );
	}

	/**
	 * The cache ignores anything that is not a usable vector, so a malformed
	 * platform payload cannot poison the lookup.
	 */
	public function test_cache_question_vector_ignores_unusable_values() {
		$hash = hash( 'md5', 'q' );

		foreach ( [ null, [], 'not-a-vector', 0 ] as $value ) {
			API::cache_question_vector( 'q', $value );

			$this->assertFalse( get_transient( 'hyve_message_' . $hash ) );
		}
	}

	/**
	 * The key matches the self-hosted writer: the lowercased message, not the
	 * blended retrieval query the vector was embedded from.
	 */
	public function test_cache_question_vector_keys_on_the_lowercased_message() {
		API::cache_question_vector( 'Mennyibe Kerül?', [ 0.5 ] );

		$this->assertSame( [ 0.5 ], get_transient( 'hyve_message_' . hash( 'md5', strtolower( 'Mennyibe Kerül?' ) ) ) );
	}

	/**
	 * Both modes cache the same question under one key, so the unanswered-question
	 * recorder reads one place whichever mode answered. Self-hosted already wrote
	 * this key; Connect joining it there is what makes the key shared.
	 */
	public function test_both_modes_cache_under_the_same_key() {
		$message = 'Mennyibe kerül a szállítás?';
		$key     = 'hyve_message_' . hash( 'md5', strtolower( $message ) );

		// Retrieval reads the knowledge base table on the self-hosted path, and
		// an empty one is answered without a model call.
		\ThemeIsle\HyveLite\DB_Table::instance()->create_table();
		\ThemeIsle\HyveLite\DB_Table::instance()->insert(
			[
				'post_id'      => 1,
				'post_title'   => 'Shipping',
				'post_content' => 'Shipping costs 5 EUR.',
				'embeddings'   => wp_json_encode( [ 0.7, 0.8, 0.9 ] ),
				'token_count'  => 10,
				'post_status'  => 'processed',
			]
		);

		update_option(
			'hyve_settings',
			[
				'ai_mode' => Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			]
		);

		// OpenAI::instance() reads the key once and caches the instance for the
		// process, so an earlier test can leave a keyless one behind.
		$instance = new \ReflectionProperty( \ThemeIsle\HyveLite\OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, '/moderations' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'results' => [ [ 'flagged' => false ] ] ] ),
					];
				}

				if ( false !== strpos( $url, '/embeddings' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'data' => [ [ 'embedding' => [ 0.7, 0.8, 0.9 ] ] ] ] ),
					];
				}

				return $pre;
			},
			10,
			3
		);

		// A supplied thread id keeps the turn off create_conversation(), so the
		// only outbound calls are the two faked above.
		$send = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$send->set_param( 'message', $message );
		$send->set_param( 'thread_id', 'conv_local' );

		API::instance()->send_chat( $send );

		$this->assertSame( [ 0.7, 0.8, 0.9 ], get_transient( $key ) );

		// The same question over Connect lands on that same key, with the vector
		// the platform embedded rather than a local one.
		delete_transient( $key );
		remove_all_filters( 'pre_http_request' );

		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$this->intercept_job_complete(
			[
				'thread_id'          => 'th-6',
				'answered'           => false,
				'reply'              => '',
				'question_embedding' => [ 0.1, 0.2, 0.3 ],
			]
		);

		$this->run_connect_turn( $message );

		$this->assertSame( [ 0.1, 0.2, 0.3 ], get_transient( $key ) );
	}
}
