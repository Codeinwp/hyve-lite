<?php
/**
 * Tests for chat on an empty knowledge base.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Hyve_Connect;
use ThemeIsle\HyveLite\Main;
use ThemeIsle\HyveLite\Stream;
use ThemeIsle\HyveLite\Threads;

/**
 * Class EmptyKbTest.
 *
 * An empty knowledge base is answered without a model call in both modes,
 * hidden from visitors, and shown to admins with a setup notice.
 */
class EmptyKbTest extends WP_UnitTestCase {

	/**
	 * Outbound requests made during a test.
	 *
	 * @var list<string>
	 */
	private $requests = [];

	/**
	 * Fail any outbound request: an empty knowledge base must make none.
	 */
	protected function setUp(): void {
		parent::setUp();
		new DB_Table();

		// Rows an earlier test class inserted after creating the table survive
		// its rollback, so the empty state is made explicit here.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}hyve" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		DB_Table::instance()->delete_by_post_id( 0 ); // Clears the cached counts.

		$this->requests = [];

		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ) {
				$this->requests[] = $url;

				return new WP_Error( 'unexpected_request', 'Unexpected request: ' . $url );
			},
			10,
			3
		);
	}

	/**
	 * Reset between tests.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'hyve_display_chat' );
		delete_option( 'hyve_settings' );
		delete_transient( 'hyve_connect_stats' );
		wp_set_current_user( 0 );
		$this->set_connect_instance( null );

		parent::tearDown();
	}

	/**
	 * Send one chat message and return the endpoint's reply.
	 *
	 * @param string $mode Request mode, '' for the poll flow.
	 *
	 * @return array<string, mixed>
	 */
	private function send( string $mode = '' ): array {
		$request = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$request->set_param( 'message', 'What are your opening hours?' );

		if ( '' !== $mode ) {
			$request->set_param( 'mode', $mode );
		}

		return API::instance()->send_chat( $request )->get_data();
	}

	/**
	 * Self-hosted: the default message comes back with no moderation,
	 * embedding or chat call, and the turn is recorded for the owner.
	 */
	public function test_self_hosted_empty_kb_answers_without_a_model_call(): void {
		update_option(
			'hyve_settings',
			[
				'ai_mode'         => Hyve_Connect::MODE_SELF,
				'api_key'         => 'sk-test',
				'default_message' => 'Nothing here yet.',
			]
		);

		foreach ( [ '', 'stream' ] as $mode ) {
			$reply = $this->send( $mode );

			$this->assertSame( 'kb_empty', $reply['code'] );
			$this->assertSame( 'Nothing here yet.', $reply['error'] );
		}

		$this->assertSame( [], $this->requests );
		// One thread per turn: each unanswered question is recorded for the owner.
		$this->assertSame( 2, (int) Threads::get_thread_count() );
	}

	/**
	 * Connect: a cached zero count answers locally, with no platform call.
	 */
	public function test_connect_empty_kb_answers_without_a_platform_call(): void {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );
		set_transient( 'hyve_connect_stats', [ 'kb' => [ 'chunks' => 0 ] ], MINUTE_IN_SECONDS );

		$reply = $this->send();

		$this->assertSame( 'kb_empty', $reply['code'] );
		$this->assertSame( [], $this->requests );
	}

	/**
	 * Connect: an unreachable platform is not an empty knowledge base, so the
	 * turn goes on to the platform.
	 */
	public function test_connect_degraded_stats_do_not_count_as_empty(): void {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );
		set_transient( 'hyve_connect_stats', [ 'service' => 'error' ], MINUTE_IN_SECONDS );

		$reply = $this->send();

		$this->assertNotSame( 'kb_empty', $reply['code'] ?? '' );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * Connect: a count the platform did not answer is unknown, not zero, so
	 * the admin is not told the knowledge base is empty.
	 */
	public function test_connect_degraded_stats_leave_the_count_unknown(): void {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );
		set_transient( 'hyve_connect_stats', [ 'service' => 'error' ], MINUTE_IN_SECONDS );
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$data = ( new Main() )->get_frontend_data();

		$this->assertNull( $data['totalChunks'] );
		$this->assertTrue( $data['canShow'] );

		set_transient( 'hyve_connect_stats', [ 'kb' => [ 'chunks' => 0 ] ], MINUTE_IN_SECONDS );

		$this->assertSame( 0, ( new Main() )->get_frontend_data()['totalChunks'] );
	}

	/**
	 * Connect: when the local count is stale, the platform's `kb_empty`
	 * outcome is shown the same way and recorded with its retrieval mode.
	 */
	public function test_connect_kb_empty_outcome_is_the_default_message(): void {
		update_option(
			'hyve_settings',
			[
				'ai_mode'         => Hyve_Connect::MODE_CONNECT,
				'default_message' => 'Nothing here yet.',
			]
		);
		set_transient( 'hyve_connect_stats', [ 'kb' => [ 'chunks' => 3 ] ], MINUTE_IN_SECONDS );

		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function () {
				$data = [
					'thread_id' => 'th-empty',
					'answered'  => false,
					'reply'     => '',
					'retrieval' => 'kb_empty',
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'event: job_complete' . "\n" . 'data: ' . wp_json_encode( $data ) . "\n\n",
				];
			}
		);

		$reply = $this->send();

		$this->assertSame( 'kb_empty', $reply['code'] );
		$this->assertSame( 'Nothing here yet.', $reply['error'] );

		$threads  = get_posts(
			[
				'post_type' => 'hyve_threads',
				'fields'    => 'ids',
			]
		);
		$messages = get_post_meta( (int) $threads[0], '_hyve_thread_data', true );
		$event    = end( $messages );

		$this->assertSame( 'kb_empty', $event['message'] );
		$this->assertSame( 'kb_empty', $event['debug']['retrieval'] );
	}

	/**
	 * Connect, streaming: the platform's `kb_empty` outcome ends the stream
	 * with the default message as a `done` event, recorded like the poll path.
	 */
	public function test_connect_stream_kb_empty_outcome_is_the_default_message(): void {
		update_option(
			'hyve_settings',
			[
				'ai_mode'         => Hyve_Connect::MODE_CONNECT,
				'default_message' => 'Nothing here yet.',
			]
		);

		$this->set_connect_instance(
			new class() extends Hyve_Connect {
				/**
				 * The platform's terminal result for an empty knowledge base.
				 *
				 * @param array<string, mixed> $payload  Ignored.
				 * @param callable             $on_event Ignored.
				 *
				 * @return array<string, mixed>
				 */
				public function stream_chat( $payload, $on_event ) {
					return [
						'thread_id' => 'th-empty',
						'answered'  => false,
						'reply'     => '',
						'retrieval' => 'kb_empty',
					];
				}
			}
		);

		$events = '';

		// send_event() flushes after every frame, so the frames are collected
		// from the buffer callback rather than read back at the end.
		ob_start(
			function ( $buffer ) use ( &$events ) {
				$events .= $buffer;

				return '';
			}
		);

		$stream = new ReflectionMethod( Stream::class, 'stream_connect' );
		$stream->setAccessible( true );
		$stream->invoke( new Stream(), 'Hello?', 'th-empty', null, false, Main::get_settings(), 'Nothing here yet.' );

		ob_end_clean();

		$this->assertStringContainsString( 'event: done', $events );
		$this->assertStringContainsString( '"code":"kb_empty"', $events );
		$this->assertStringContainsString( '"message":"Nothing here yet."', $events );
		$this->assertStringNotContainsString( 'event: error', $events );

		$threads  = get_posts(
			[
				'post_type' => 'hyve_threads',
				'fields'    => 'ids',
			]
		);
		$messages = get_post_meta( (int) $threads[0], '_hyve_thread_data', true );
		$event    = end( $messages );

		$this->assertSame( 'kb_empty', $event['message'] );
		$this->assertSame( 'kb_empty', $event['debug']['retrieval'] );
	}

	/**
	 * Replace the shared Connect client, or reset it with null.
	 *
	 * @param Hyve_Connect|null $instance The client to install.
	 *
	 * @return void
	 */
	private function set_connect_instance( ?Hyve_Connect $instance ): void {
		$property = new ReflectionProperty( Hyve_Connect::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $instance );
	}

	/**
	 * The widget is hidden from visitors but shown to an admin, who gets the
	 * count, the notice strings and a link to the Knowledge Base screen.
	 */
	public function test_widget_is_hidden_from_visitors_and_shown_to_admins(): void {
		$main = new Main();

		$visitor = $main->get_frontend_data();

		$this->assertFalse( $visitor['canShow'] );
		$this->assertSame( 0, $visitor['totalChunks'] );
		$this->assertFalse( $visitor['canManage'] );
		$this->assertSame( '', $visitor['kbUrl'] );

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$admin = $main->get_frontend_data();

		$this->assertTrue( $admin['canShow'] );
		$this->assertTrue( $admin['canManage'] );
		$this->assertStringContainsString( 'nav=kb', $admin['kbUrl'] );
		$this->assertNotEmpty( $admin['strings']['emptyKb'] );
	}
}
