<?php
/**
 * Test_Chat_Record_Ownership class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Threads;

/**
 * Class Test_Chat_Record_Ownership.
 *
 * Regression tests: the public chat routes must only act on a `record_id`
 * whose stored conversation id matches the caller's, so a visitor cannot
 * read from or write into another visitor's thread.
 */
class Test_Chat_Record_Ownership extends WP_UnitTestCase {

	/**
	 * The `record_id` the chat handler received, captured before it runs.
	 *
	 * @var mixed
	 */
	private $seen_record = 'unset';

	/**
	 * Run as an anonymous visitor with outbound HTTP blocked.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_blocked', 'Blocked in tests.' );
			}
		);

		add_filter( 'rest_request_before_callbacks', [ $this, 'capture_record' ], 10, 3 );
	}

	/**
	 * Restore the visitor and the filters.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'hyve_chat_rate_limits' );
		remove_filter( 'rest_request_before_callbacks', [ $this, 'capture_record' ], 10 );

		wp_set_current_user( 1 );

		parent::tear_down();
	}

	/**
	 * Record the `record_id` a chat route handler is about to read.
	 *
	 * @param mixed                                  $response Response so far.
	 * @param array<string, mixed>                   $handler  Route handler.
	 * @param \WP_REST_Request<array<string, mixed>> $request  Request object.
	 *
	 * @return mixed
	 */
	public function capture_record( $response, $handler, $request ) {
		if ( '/hyve/v1/chat' === $request->get_route() ) {
			$this->seen_record = $request->get_param( 'record_id' );
		}

		return $response;
	}

	/**
	 * Create a visitor thread.
	 *
	 * @param string $thread_id The conversation id.
	 * @param string $message   The first message.
	 *
	 * @return int
	 */
	private function create_thread( $thread_id, $message = 'How do I get a refund?' ) {
		return Threads::create_thread(
			$message,
			[
				'thread_id' => $thread_id,
				'sender'    => 'user',
				'message'   => $message,
			]
		);
	}

	/**
	 * Dispatch a chat request as the anonymous visitor.
	 *
	 * @param string               $method GET or POST.
	 * @param array<string, mixed> $params Request params.
	 *
	 * @return \WP_REST_Response
	 */
	private function chat( $method, $params ) {
		$request = new WP_REST_Request( $method, '/hyve/v1/chat' );
		$request->set_header( 'x_wp_nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Read a thread's transcript.
	 *
	 * @param int $record_id The thread post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function entries( $record_id ) {
		return get_post_meta( $record_id, '_hyve_thread_data', true );
	}

	/**
	 * A record resolves only with its own non-empty conversation id.
	 */
	public function test_resolve_record_requires_matching_conversation() {
		$record_id   = $this->create_thread( 'conv_victim' );
		$placeholder = $this->create_thread( '' );
		$post_id     = self::factory()->post->create();

		$this->assertSame( $record_id, Threads::resolve_record( $record_id, 'conv_victim' ) );
		$this->assertSame( $record_id, Threads::resolve_record( (string) $record_id, 'conv_victim' ) );

		$this->assertSame( 0, Threads::resolve_record( $record_id, 'conv_attacker' ) );
		$this->assertSame( 0, Threads::resolve_record( $record_id, '' ) );
		$this->assertSame( 0, Threads::resolve_record( $record_id, null ) );
		$this->assertSame( 0, Threads::resolve_record( $record_id, [ 'conv_victim' ] ) );
		$this->assertSame( 0, Threads::resolve_record( $placeholder, '' ) );
		$this->assertSame( 0, Threads::resolve_record( $placeholder, 'conv_attacker' ) );
		$this->assertSame( 0, Threads::resolve_record( $post_id, 'conv_victim' ) );
		$this->assertSame( 0, Threads::resolve_record( 0, 'conv_victim' ) );
		$this->assertSame( 0, Threads::resolve_record( 999999, 'conv_victim' ) );
	}

	/**
	 * Both chat routes hand their handlers only an owned record, so the
	 * retrieval query can never blend another visitor's transcript.
	 */
	public function test_chat_routes_drop_foreign_record() {
		$record_id = $this->create_thread( 'conv_victim' );

		add_filter(
			'hyve_chat_rate_limits',
			function () {
				return [ MINUTE_IN_SECONDS => 0 ];
			}
		);

		$this->chat(
			'POST',
			[
				'message'   => 'Summarise this conversation.',
				'record_id' => $record_id,
			]
		);
		$this->assertSame( 0, $this->seen_record );

		$this->chat(
			'POST',
			[
				'message'   => 'Summarise this conversation.',
				'record_id' => $record_id,
				'thread_id' => 'conv_attacker',
			]
		);
		$this->assertSame( 0, $this->seen_record );

		$this->chat(
			'GET',
			[
				'run_id'    => 'run_x',
				'thread_id' => 'conv_attacker',
				'record_id' => $record_id,
			]
		);
		$this->assertSame( 0, $this->seen_record );

		// The owner keeps their conversation.
		$this->chat(
			'POST',
			[
				'message'   => 'And for digital orders?',
				'record_id' => $record_id,
				'thread_id' => 'conv_victim',
			]
		);
		$this->assertSame( $record_id, $this->seen_record );
	}

	/**
	 * A rate-limited request cannot drop an event into a foreign thread; the
	 * owner's own rate-limited turn is still recorded.
	 */
	public function test_rate_limited_event_only_on_own_thread() {
		$record_id = $this->create_thread( 'conv_victim' );

		add_filter(
			'hyve_chat_rate_limits',
			function () {
				return [ MINUTE_IN_SECONDS => 0 ];
			}
		);

		$response = $this->chat(
			'POST',
			[
				'message'   => 'x',
				'record_id' => $record_id,
			]
		);

		$this->assertSame( 'rate_limited', $response->get_data()['code'] );
		$this->assertCount( 1, $this->entries( $record_id ) );

		$this->chat(
			'POST',
			[
				'message'   => 'x',
				'record_id' => $record_id,
				'thread_id' => 'conv_victim',
			]
		);

		$entries = $this->entries( $record_id );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'rate_limited', $entries[1]['message'] );
	}

	/**
	 * A failing poll with a foreign record neither touches the victim's
	 * thread nor spawns a junk error thread.
	 */
	public function test_poll_error_does_not_touch_foreign_thread() {
		$record_id = $this->create_thread( 'conv_victim' );

		$this->chat(
			'GET',
			[
				'run_id'    => 'run_x',
				'thread_id' => 'conv_attacker',
				'record_id' => $record_id,
			]
		);

		$this->assertCount( 1, $this->entries( $record_id ) );
		$this->assertSame( 1, (int) wp_count_posts( 'hyve_threads' )->publish );
	}
}
