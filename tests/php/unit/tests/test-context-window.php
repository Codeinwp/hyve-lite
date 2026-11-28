<?php
/**
 * Test_Context_Window class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Hyve_Connect;
use ThemeIsle\HyveLite\OpenAI;
use ThemeIsle\HyveLite\Threads;

/**
 * Class Test_Context_Window.
 *
 * A self-hosted conversation keeps every turn on OpenAI's side, so a long
 * chat outgrows the model's context window. These tests cover the requests
 * that let it keep answering and the failures that must stay visible.
 */
class Test_Context_Window extends WP_UnitTestCase {

	/**
	 * Use self-hosted mode with a test key and a fresh OpenAI client.
	 */
	public function set_up() {
		parent::set_up();

		update_option(
			'hyve_settings',
			[
				'ai_mode' => Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			]
		);

		$this->reset_openai();
	}

	/**
	 * Drop the cached OpenAI client and the request fakes.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		$this->reset_openai();

		parent::tear_down();
	}

	/**
	 * Drop the cached OpenAI client, so the next call reads the settings.
	 */
	private function reset_openai(): void {
		$instance = new ReflectionProperty( OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Answer every OpenAI request with the given body and record what was sent.
	 *
	 * @param array<string, mixed> $body The decoded response body.
	 *
	 * @return array<int, array<string, mixed>> The decoded request bodies, by reference.
	 */
	private function &intercept( array $body ): array {
		$requests = [];

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$requests, $body ) {
				$requests[] = [
					'url'  => $url,
					'body' => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode( $body ),
				];
			},
			10,
			3
		);

		return $requests;
	}

	/**
	 * Every chat request asks OpenAI to drop the oldest turns once the
	 * conversation outgrows the window, instead of failing every later turn.
	 */
	public function test_chat_requests_truncate_a_conversation_past_the_window() {
		$requests = &$this->intercept(
			[
				'id'     => 'resp_1',
				'status' => 'queued',
			]
		);
		$items    = OpenAI::build_chat_items( 'Context', 'Question' );

		$this->assertSame( 'resp_1', OpenAI::instance()->create_response( $items, 'conv_1' ) );
		OpenAI::instance()->create_response_sync( $items, 'conv_1' );

		$this->assertCount( 2, $requests );

		foreach ( $requests as $request ) {
			$this->assertStringEndsWith( '/responses', $request['url'] );
			$this->assertSame( 'conv_1', $request['body']['conversation'] );
			$this->assertSame( 'auto', $request['body']['truncation'] );
		}

		// The streaming flow builds its request from the same parameters.
		$params = new ReflectionMethod( OpenAI::class, 'get_chat_response_params' );
		$params->setAccessible( true );

		$this->assertSame( 'auto', $params->invoke( OpenAI::instance(), $items, 'conv_1' )['truncation'] );
	}

	/**
	 * A background run that fails (OpenAI reports a context-window overflow
	 * only once the run is polled) is recorded on the thread with its code,
	 * and the widget gets an error.
	 */
	public function test_failed_background_run_records_the_provider_error() {
		$record_id = Threads::create_thread(
			'Hi',
			[
				'thread_id' => 'conv_1',
				'sender'    => 'user',
				'message'   => 'Hi',
			]
		);

		$this->intercept(
			[
				'id'     => 'resp_1',
				'status' => 'failed',
				'error'  => [
					'code'    => 'context_length_exceeded',
					'message' => 'Your input exceeds the context window of this model. Please adjust your input and try again.',
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/hyve/v1/chat' );
		$request->set_param( 'run_id', 'resp_1' );
		$request->set_param( 'thread_id', 'conv_1' );
		$request->set_param( 'record_id', $record_id );
		$request->set_param( 'message', 'Hi' );

		$data = rest_ensure_response( API::instance()->get_chat( $request ) )->get_data();

		$this->assertArrayHasKey( 'error', $data );

		$entries = get_post_meta( $record_id, '_hyve_thread_data', true );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'event', $entries[1]['sender'] );
		$this->assertSame( 'chat_error', $entries[1]['message'] );
		$this->assertSame( 'context_length_exceeded', $entries[1]['debug']['code'] );
		$this->assertStringStartsWith( 'Your input exceeds the context window', $entries[1]['debug']['detail'] );
	}

	/**
	 * A run still in progress records nothing.
	 */
	public function test_pending_background_run_records_nothing() {
		$record_id = Threads::create_thread(
			'Hi',
			[
				'thread_id' => 'conv_1',
				'sender'    => 'user',
				'message'   => 'Hi',
			]
		);

		$this->intercept(
			[
				'id'     => 'resp_1',
				'status' => 'in_progress',
			]
		);

		$request = new WP_REST_Request( 'GET', '/hyve/v1/chat' );
		$request->set_param( 'run_id', 'resp_1' );
		$request->set_param( 'thread_id', 'conv_1' );
		$request->set_param( 'record_id', $record_id );

		$this->assertSame( [ 'status' => 'in_progress' ], rest_ensure_response( API::instance()->get_chat( $request ) )->get_data() );
		$this->assertCount( 1, get_post_meta( $record_id, '_hyve_thread_data', true ) );
	}

	/**
	 * A streamed failure keeps the provider's code and message, which OpenAI
	 * nests under `error` on an `error` event and under `response.error` on
	 * `response.failed`.
	 */
	public function test_stream_error_events_keep_the_provider_code() {
		$message = 'Your input exceeds the context window of this model. Please adjust your input and try again.';

		$this->assertSame(
			[
				'code'    => 'context_length_exceeded',
				'message' => $message,
			],
			OpenAI::stream_event_error(
				json_decode(
					wp_json_encode(
						[
							'type'  => 'error',
							'error' => [
								'type'    => 'invalid_request_error',
								'code'    => 'context_length_exceeded',
								'message' => $message,
							],
						]
					)
				)
			)
		);

		$this->assertSame(
			[
				'code'    => 'context_length_exceeded',
				'message' => $message,
			],
			OpenAI::stream_event_error(
				json_decode(
					wp_json_encode(
						[
							'type'     => 'response.failed',
							'response' => [
								'status' => 'failed',
								'error'  => [
									'code'    => 'context_length_exceeded',
									'message' => $message,
								],
							],
						]
					)
				)
			)
		);

		// The older flat shape still reads, without a code.
		$this->assertSame(
			[
				'code'    => 'stream_error',
				'message' => 'Something broke.',
			],
			OpenAI::stream_event_error(
				(object) [
					'type'    => 'error',
					'message' => 'Something broke.',
				]
			)
		);

		$this->assertNull( OpenAI::stream_event_error( (object) [ 'type' => 'response.output_text.delta' ] ) );
	}
}
