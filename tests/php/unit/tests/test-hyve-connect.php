<?php
/**
 * Tests for the Hyve_Connect client.
 *
 * HTTP is intercepted with the core `pre_http_request` filter, so these assert
 * request building, payload shapes, SSE parsing and error mapping without a
 * network.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Hyve_Connect;

/**
 * Class HyveConnectTest
 */
class HyveConnectTest extends WP_UnitTestCase {

	/**
	 * The last intercepted request ({args, url}).
	 *
	 * @var array<string, mixed>
	 */
	private $captured = [];

	/**
	 * Reset options, transients and filters between tests.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'product_hyve_license_key' );
		remove_all_filters( 'hyve_connect_base_url' );
		remove_all_actions( 'before_delete_post' );
		delete_option( 'hyve_settings' );
		delete_option( Hyve_Connect::SITE_TOKEN_OPTION );
		delete_transient( 'hyve_connect_stats' );
		parent::tearDown();
	}

	/**
	 * Intercept the next HTTP request, capture it, and return a canned response.
	 *
	 * @param string $body Response body.
	 * @param int    $code HTTP status.
	 *
	 * @return void
	 */
	private function intercept( $body, $code = 200 ) {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $body, $code ) {
				$this->captured = [
					'args' => $args,
					'url'  => $url,
				];

				return [
					'response' => [ 'code' => $code ],
					'body'     => $body,
				];
			},
			10,
			3
		);
	}

	/**
	 * Build an SSE body from [event, data] frames.
	 *
	 * @param array<array{0:string,1:array<string,mixed>}> $frames Frames.
	 *
	 * @return string
	 */
	private function sse( array $frames ) {
		$out = '';

		foreach ( $frames as $frame ) {
			$out .= 'event: ' . $frame[0] . "\n" . 'data: ' . wp_json_encode( $frame[1] ) . "\n\n";
		}

		return $out;
	}

	/**
	 * Connect is opt-in: with nothing chosen a site stays self-hosted.
	 */
	public function test_mode_defaults_to_self_hosted() {
		delete_option( 'hyve_settings' );

		$this->assertSame( Hyve_Connect::MODE_SELF, Hyve_Connect::get_mode() );
		$this->assertFalse( Hyve_Connect::is_active() );
	}

	/**
	 * Only an explicit hyve_connect opt-in turns Connect on.
	 */
	public function test_mode_connect_only_when_opted_in() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$this->assertSame( Hyve_Connect::MODE_CONNECT, Hyve_Connect::get_mode() );
		$this->assertTrue( Hyve_Connect::is_active() );
	}

	/**
	 * Leaving Connect via a plain settings save is refused: it would strand the
	 * hosted KB and stale local markers, so it must go through disconnect.
	 */
	public function test_update_settings_refuses_leaving_connect() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$request = new WP_REST_Request( 'POST', '/hyve/v1/settings' );
		$request->set_param( 'data', [ 'ai_mode' => Hyve_Connect::MODE_SELF ] );

		$response = \ThemeIsle\HyveLite\API::instance()->update_settings( $request )->get_data();

		$this->assertArrayHasKey( 'error', (array) $response );
		$this->assertSame( Hyve_Connect::MODE_CONNECT, \ThemeIsle\HyveLite\Main::get_settings()['ai_mode'] );
	}

	/**
	 * Parse_sse preserves event order and decodes each frame's data.
	 */
	public function test_parse_sse_orders_events_and_decodes_data() {
		$body = $this->sse(
			[
				[ 'stream_start', [] ],
				[ 'delta', [ 'text' => 'Hi' ] ],
				[
					'job_complete',
					[
						'reply'    => 'Hi',
						'answered' => true,
					],
				],
			]
		);

		$events = Hyve_Connect::parse_sse( $body );

		$this->assertSame( [ 'stream_start', 'delta', 'job_complete' ], array_column( $events, 'event' ) );
		$this->assertSame( 'Hi', $events[1]['data']['text'] );
	}

	/**
	 * SSE comments (": connected") and blank frames are ignored.
	 */
	public function test_parse_sse_skips_comments_and_blanks() {
		$body = ": connected\n\n" . $this->sse( [ [ 'job_complete', [ 'ok' => true ] ] ] );

		$events = Hyve_Connect::parse_sse( $body );

		$this->assertSame( [ 'job_complete' ], array_column( $events, 'event' ) );
	}

	/**
	 * Kb_aggregate must match the platform's aggregate byte for byte: sha256 over
	 * "id:hash" lines sorted by id as strings. This pins the string-sort ("10"
	 * before "2") and the empty-KB value, so the reconcile fast-path stays valid.
	 */
	public function test_kb_aggregate_matches_platform_formula() {
		$manifest = [
			[
				'id'   => 2,
				'hash' => 'b',
			],
			[
				'id'   => 10,
				'hash' => 'a',
			],
		];

		// String sort orders "10" before "2".
		$this->assertSame(
			hash( 'sha256', "10:a\n2:b" ),
			Hyve_Connect::kb_aggregate( $manifest )
		);

		// Order-independent.
		$this->assertSame(
			Hyve_Connect::kb_aggregate( $manifest ),
			Hyve_Connect::kb_aggregate( array_reverse( $manifest ) )
		);

		// Empty KB.
		$this->assertSame(
			hash( 'sha256', '' ),
			Hyve_Connect::kb_aggregate( [] )
		);
	}

	/**
	 * Kb_bucket_of must match the platform's bucketOf: low 8 bits of crc32.
	 */
	public function test_kb_bucket_of_matches_platform_formula() {
		foreach ( [ '1', '2', '12345', 'custom-source' ] as $id ) {
			$this->assertSame(
				crc32( $id ) & 255,
				Hyve_Connect::kb_bucket_of( $id )
			);
			$this->assertGreaterThanOrEqual( 0, Hyve_Connect::kb_bucket_of( $id ) );
			$this->assertLessThan( 256, Hyve_Connect::kb_bucket_of( $id ) );
		}
	}

	/**
	 * Kb_bucket_hashes groups a manifest by bucket and aggregates each group, so
	 * a source's bucket hash equals aggregating just that source.
	 */
	public function test_kb_bucket_hashes_group_by_bucket() {
		$manifest = [
			[
				'id'   => 1,
				'hash' => 'h1',
			],
			[
				'id'   => 2,
				'hash' => 'h2',
			],
		];

		$buckets = Hyve_Connect::kb_bucket_hashes( $manifest );

		$b1 = Hyve_Connect::kb_bucket_of( 1 );
		$this->assertSame(
			Hyve_Connect::kb_aggregate(
				[
					[
						'id'   => 1,
						'hash' => 'h1',
					],
				] 
			),
			$buckets[ $b1 ]
		);
	}

	/**
	 * Kb_upsert POSTs the contract-shaped payload and returns the job_complete data.
	 */
	public function test_kb_upsert_builds_request_and_returns_job_complete() {
		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'     => 12,
									'status' => 'stored',
									'chunks' => 3,
								],
							],
							'kb'      => [ 'chunks' => 3 ],
						],
					],
				]
			)
		);

		$result = Hyve_Connect::instance()->kb_upsert(
			[
				[
					'id'      => 12,
					'type'    => 'post',
					'title'   => 'T',
					'url'     => 'https://x',
					'content' => 'body',
				],
			]
		);

		$this->assertStringEndsWith( 'hyve-kb/start', $this->captured['url'] );

		$body = json_decode( $this->captured['args']['body'], true );
		$this->assertSame( 'upsert', $body['action'] );
		$this->assertSame( 12, $body['documents'][0]['id'] );

		$this->assertSame( get_site_url(), $this->captured['args']['headers']['X-Site-Url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->captured['args']['headers'] );

		$this->assertSame( 'stored', $result['results'][0]['status'] );
	}

	/**
	 * Every request carries the per-site token, generated once and reused, so
	 * the platform can bind a free site's identity without a license key.
	 */
	public function test_site_token_is_sent_and_stable() {
		delete_option( Hyve_Connect::SITE_TOKEN_OPTION );

		$this->intercept( $this->sse( [ [ 'job_complete', [ 'kb' => [] ] ] ] ) );
		Hyve_Connect::instance()->kb_reconcile( [], null );

		$token = $this->captured['args']['headers']['X-Site-Token'];

		$this->assertNotEmpty( $token );
		$this->assertSame( $token, get_option( Hyve_Connect::SITE_TOKEN_OPTION ) );

		// A second request reuses the same stored token, not a fresh one.
		$this->intercept( $this->sse( [ [ 'job_complete', [ 'kb' => [] ] ] ] ) );
		Hyve_Connect::instance()->kb_reconcile( [], null );

		$this->assertSame( $token, $this->captured['args']['headers']['X-Site-Token'] );
	}

	/**
	 * A license key becomes a base64'd bearer; free installs send none.
	 */
	public function test_license_adds_base64_bearer() {
		add_filter( 'product_hyve_license_key', fn() => 'LICENSE123' );
		$this->intercept( $this->sse( [ [ 'job_complete', [ 'kb' => [] ] ] ] ) );

		Hyve_Connect::instance()->kb_reconcile( [], null );

		$this->assertSame(
			'Bearer ' . base64_encode( 'LICENSE123' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$this->captured['args']['headers']['Authorization']
		);
	}

	/**
	 * The base URL is filterable (staging/local).
	 */
	public function test_base_url_is_filterable() {
		add_filter( 'hyve_connect_base_url', fn() => 'https://staging.test/api/workflows/' );
		$this->intercept( $this->sse( [ [ 'job_complete', [] ] ] ) );

		Hyve_Connect::instance()->kb_reconcile( [], null );

		$this->assertStringStartsWith( 'https://staging.test/api/workflows/', $this->captured['url'] );
	}

	/**
	 * The custom instructions sent to the platform come from the same
	 * system_prompt setting that drives self-hosted chat.
	 */
	public function test_chat_settings_sends_system_prompt() {
		update_option( 'hyve_settings', [ 'system_prompt' => 'Reply like a pirate.' ] );

		$sent = Hyve_Connect::chat_settings();

		$this->assertSame( 'Reply like a pirate.', $sent['instructions'] );
	}

	/**
	 * The prompt flows through hyve_system_prompt, so the Pro license gate
	 * (and any programmatic override) applies to Connect mode too.
	 */
	public function test_chat_settings_respects_system_prompt_filter() {
		update_option( 'hyve_settings', [ 'system_prompt' => 'Reply like a pirate.' ] );

		add_filter( 'hyve_system_prompt', '__return_empty_string' );
		$sent = Hyve_Connect::chat_settings();
		remove_filter( 'hyve_system_prompt', '__return_empty_string' );

		$this->assertSame( '', $sent['instructions'] );
	}

	/**
	 * Instructions are capped to the platform's input limit so an oversized
	 * prompt cannot fail the whole chat request server-side.
	 */
	public function test_chat_settings_caps_instructions_length() {
		update_option( 'hyve_settings', [ 'system_prompt' => str_repeat( 'a', 5000 ) ] );

		$sent = Hyve_Connect::chat_settings();

		$this->assertSame( 4000, strlen( $sent['instructions'] ) );
	}

	/**
	 * A terminal SSE error becomes a coded WP_Error.
	 */
	public function test_sse_error_maps_to_wp_error_with_code() {
		$this->intercept(
			$this->sse(
				[
					[
						'error',
						[
							'code'    => 'quota_exceeded',
							'message' => 'Limit reached',
							'quota'   => [ 'kind' => 'messages' ],
						],
					],
				]
			)
		);

		$result = Hyve_Connect::instance()->kb_upsert(
			[
				[
					'id'      => 1,
					'title'   => 'a',
					'content' => 'b',
				],
			] 
		);

		$this->assertWPError( $result );
		$this->assertSame( 'hyve_connect_quota_exceeded', $result->get_error_code() );
	}

	/**
	 * A 429 without a quota snapshot is transient rate limiting (the platform's
	 * per-IP guard), not a plan block, so it maps to a retryable rate_limited.
	 */
	public function test_http_429_without_quota_maps_to_rate_limited() {
		$this->intercept( wp_json_encode( [ 'error' => 'Too many requests from your IP address.' ] ), 429 );

		$result = Hyve_Connect::instance()->kb_reconcile( [], null );

		$this->assertWPError( $result );
		$this->assertSame( 'hyve_connect_rate_limited', $result->get_error_code() );
	}

	/**
	 * A 429 carrying a quota snapshot is a real plan-quota block.
	 */
	public function test_http_429_with_quota_maps_to_quota_exceeded() {
		$this->intercept(
			wp_json_encode(
				[
					'error' => 'Limit reached',
					'quota' => [ 'kind' => 'messages' ],
				] 
			),
			429 
		);

		$result = Hyve_Connect::instance()->kb_reconcile( [], null );

		$this->assertWPError( $result );
		$this->assertSame( 'hyve_connect_quota_exceeded', $result->get_error_code() );
	}

	/**
	 * A stream with no terminal event is an error, not a silent empty result.
	 */
	public function test_missing_terminal_event_is_an_error() {
		$this->intercept( $this->sse( [ [ 'stream_start', [] ], [ 'delta', [ 'text' => 'x' ] ] ] ) );

		$result = Hyve_Connect::instance()->kb_reconcile( [], null );

		$this->assertWPError( $result );
		$this->assertSame( 'hyve_connect_no_result', $result->get_error_code() );
	}

	/**
	 * User_message maps platform codes to visitor/admin wording.
	 */
	public function test_user_message_maps_codes() {
		$quota   = new WP_Error( 'hyve_connect_quota_exceeded', 'x', [ 'code' => 'quota_exceeded' ] );
		$rate    = new WP_Error( 'hyve_connect_rate_limited', 'x', [ 'code' => 'rate_limited' ] );
		$kb      = new WP_Error( 'hyve_connect_kb_unavailable', 'x', [ 'code' => 'kb_unavailable' ] );
		$unknown = new WP_Error( 'hyve_connect_whatever', 'x', [ 'code' => 'something_else' ] );

		$this->assertStringContainsString( 'limit', strtolower( Hyve_Connect::user_message( $quota ) ) );
		// Rate limiting is transient: prompt to retry, never to upgrade.
		$this->assertStringContainsString( 'try again', strtolower( Hyve_Connect::user_message( $rate ) ) );
		$this->assertStringNotContainsString( 'upgrade', strtolower( Hyve_Connect::user_message( $rate ) ) );
		$this->assertStringContainsString( 'knowledge base', strtolower( Hyve_Connect::user_message( $kb ) ) );
		// An unmapped code falls back to the generic unavailable message.
		$this->assertStringContainsString( 'temporarily unavailable', strtolower( Hyve_Connect::user_message( $unknown ) ) );
	}

	/**
	 * Get_quota reads the plain-JSON aggregate over GET.
	 */
	public function test_get_quota_returns_decoded_json() {
		$this->intercept(
			wp_json_encode(
				[
					'plan' => 'free',
					'kb'   => [ 'chunks' => 10 ],
				] 
			) 
		);

		$result = Hyve_Connect::instance()->get_quota();

		$this->assertSame( 'free', $result['plan'] );
		$this->assertSame( 'GET', $this->captured['args']['method'] );
		$this->assertStringEndsWith( 'hyve/quota', $this->captured['url'] );
	}

	/**
	 * Stats() caches the aggregate so a second read does not hit the network.
	 */
	public function test_stats_are_cached_after_first_fetch() {
		$this->intercept(
			wp_json_encode(
				[
					'plan'    => 'free',
					'service' => 'ok',
				] 
			) 
		);

		$first = Hyve_Connect::instance()->stats();
		$this->assertSame( 'free', $first['plan'] );

		// Drop the interceptor; a cached second read still returns the data.
		remove_all_filters( 'pre_http_request' );
		$second = Hyve_Connect::instance()->stats();
		$this->assertSame( 'free', $second['plan'] );
	}

	/**
	 * A degraded service is cached briefly as an error marker.
	 */
	public function test_stats_degraded_on_error() {
		$this->intercept( wp_json_encode( [ 'message' => 'boom' ] ), 500 );

		$stats = Hyve_Connect::instance()->stats();

		$this->assertSame( 'error', $stats['service'] );
	}

	/**
	 * Deleting an indexed post in Connect mode removes it from the platform, so
	 * deleting local content (e.g. a sitemap's pages) never orphans it there.
	 *
	 * Goes through wp_delete_post to prove the real hook wiring: WordPress wipes
	 * the post meta before delete_post fires, so the handler must run earlier
	 * (before_delete_post) or _hyve_added is already gone and nothing propagates.
	 */
	public function test_deleting_indexed_post_removes_it_from_connect() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );
		new \ThemeIsle\HyveLite\Main();

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_hyve_added', 1 );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'deleted' => [ $post_id ],
							'kb'      => [],
						],
					],
				] 
			) 
		);

		wp_delete_post( $post_id, true );

		$this->assertStringEndsWith( 'hyve-kb/start', $this->captured['url'] );

		$body = json_decode( $this->captured['args']['body'], true );
		$this->assertSame( 'delete', $body['action'] );
		$this->assertSame( [ $post_id ], $body['ids'] );
	}

	/**
	 * Deleting a post that was never indexed makes no platform call, so routine
	 * deletions do not hit the API.
	 */
	public function test_deleting_non_indexed_post_skips_connect() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );
		new \ThemeIsle\HyveLite\Main();

		$post_id = self::factory()->post->create();

		$this->intercept( $this->sse( [ [ 'job_complete', [] ] ] ) );
		$this->captured = [];

		wp_delete_post( $post_id, true );

		$this->assertSame( [], $this->captured );
	}
}
