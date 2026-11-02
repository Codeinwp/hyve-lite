<?php
/**
 * Background ingestion regression tests, with all provider traffic intercepted.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Ingestion_Queue;
use ThemeIsle\HyveLite\OpenAI;
use ThemeIsle\HyveLite\Qdrant_API;

/**
 * Exercise durable progress against WordPress metadata, cron and real SQL.
 */
class IngestionQueueTest extends WP_UnitTestCase {
	/**
	 * Captured transport calls.
	 *
	 * @var array<array<string, mixed>>
	 */
	private $calls = [];

	/**
	 * Optional transport override.
	 *
	 * @var callable|null
	 */
	private $response;

	/**
	 * Isolate the provider and database table. Runs take one step at a
	 * time unless a test restores the real time budget.
	 */
	public function set_up() {
		parent::set_up();
		new DB_Table();
		$property = new ReflectionProperty( OpenAI::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, new OpenAI( 'queue-test-key' ) );
		$this->calls    = [];
		$this->response = null;
		delete_transient( 'hyve_rate_' . hash( 'sha256', 'queue-test-key:moderations' ) );
		delete_transient( 'hyve_rate_' . hash( 'sha256', 'queue-test-key:embeddings' ) );
		add_filter( 'pre_http_request', [ $this, 'http' ], 10, 3 );
		add_filter( 'hyve_ingestion_time_budget', '__return_zero' );
	}

	/**
	 * Reset the singletons, so a mocked key cannot escape this file.
	 */
	public function tear_down() {
		$property = new ReflectionProperty( OpenAI::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		$property = new ReflectionProperty( Qdrant_API::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		parent::tear_down();
	}

	/**
	 * Mock every external request, including unexpected endpoints.
	 *
	 * @param mixed                $pre Previous result.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url Request URL.
	 * @return array<string, mixed>
	 */
	public function http( $pre, $args, $url ) {
		$input         = json_decode( $args['body'] ?? '{}', true );
		$this->calls[] = [
			'url'   => $url,
			'input' => $input,
		];
		if ( is_callable( $this->response ) ) {
			$result = call_user_func( $this->response, $url, $input );
			if ( null !== $result ) {
				return $result;
			}
		}
		if ( false !== strpos( $url, '/moderations' ) ) {
			$body = [ 'results' => array_fill( 0, count( $input['input'] ), [ 'flagged' => false ] ) ];
		} else {
			$this->assertStringContainsString( '/embeddings', $url );
			$data = [];
			foreach ( array_keys( (array) $input['input'] ) as $index ) {
				$data[] = [
					'index'     => $index,
					'embedding' => [ 0.1, 0.2, 0.3 ],
				];
			}
			$body = [ 'data' => $data ];
		}
		return [
			'response' => [ 'code' => 200 ],
			'headers'  => [],
			'body'     => wp_json_encode( $body ),
		];
	}

	/**
	 * Queue synthetic chunks without downloading the tokenizer vocabulary.
	 *
	 * @param int $count Chunk count.
	 * @param int $post_id Existing source, if updating.
	 * @return int
	 */
	private function queue( $count = 13, $post_id = 0 ) {
		$post_id = $post_id ? $post_id : self::factory()->post->create();
		$chunks  = [];
		for ( $index = 0; $index < $count; $index++ ) {
			$chunks[] = [
				'post_title'   => 'Test',
				'post_content' => 'Chunk ' . $index,
				'token_count'  => 1000,
			];
		}
		$this->assertTrue(
			Ingestion_Queue::instance()->enqueue(
				[
					'title'   => 'Test',
					'content' => 'Replacement',
				],
				[ 'post_id' => $post_id ],
				$chunks
			)
		);
		return $post_id;
	}

	/**
	 * Run the worker a number of times, as if every wait had elapsed.
	 *
	 * @param int $post_id Source ID.
	 * @param int $steps Runs.
	 */
	private function step( $post_id, $steps = 1 ) {
		$queue = Ingestion_Queue::instance();
		for ( $step = 0; $step < $steps; $step++ ) {
			if ( ! $queue->is_active( $post_id ) ) {
				return;
			}
			$job = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
			if ( $job['retry_at'] > 0 ) {
				$job['retry_at'] = 0;
				update_post_meta( $post_id, Ingestion_Queue::JOB, $job );
			}
			delete_transient( 'hyve_rate_' . hash( 'sha256', 'queue-test-key:moderations' ) );
			delete_transient( 'hyve_rate_' . hash( 'sha256', 'queue-test-key:embeddings' ) );
			$queue->run( $post_id );
		}
	}

	/**
	 * Manually drive real cron callbacks without waiting on wall time.
	 *
	 * @param int $post_id Source ID.
	 */
	private function drain( $post_id ) {
		$this->step( $post_id, 200 );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
	}

	/**
	 * Chunks counted toward the index for a source.
	 *
	 * @param int $post_id Source ID.
	 * @return int
	 */
	private function indexed( $post_id ) {
		return DB_Table::instance()->get_counts_by_post_ids( [ $post_id ] )[ $post_id ] ?? 0;
	}

	/**
	 * No provider calls in the request, one bounded batch per step, staged
	 * rows hidden from counts and retrieval, embeddings batched.
	 */
	public function test_large_import_is_bounded_and_publishes_only_when_ready() {
		$post_id = $this->queue();
		$this->assertCount( 0, $this->calls );
		$this->assertNotFalse( wp_next_scheduled( Ingestion_Queue::HOOK, [ $post_id ] ) );
		$this->assertSame( 0, $this->indexed( $post_id ) );
		$this->assertSame( 0, (int) DB_Table::instance()->get_count() );
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertCount( 1, $this->calls );
		$this->assertCount( 5, $this->calls[0]['input']['input'] );
		$this->assertSame( 5, get_post_meta( $post_id, '_hyve_ingestion', true )['completed'] );
		$this->assertSame( 0, $this->indexed( $post_id ) );
		$this->drain( $post_id );
		$this->assertSame( 'complete', get_post_meta( $post_id, '_hyve_ingestion', true )['status'] );
		$this->assertSame( 13, $this->indexed( $post_id ) );
		$this->assertCount( 5, $this->calls, 'Three moderation batches and two embedding batches.' );
		$this->assertCount( 10, $this->calls[3]['input']['input'] );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_needs_update', true ) );
		foreach ( DB_Table::instance()->get_by_status( 'processed' ) as $row ) {
			$this->assertSame( '[0.1,0.2,0.3]', $row->embeddings );
			$this->assertSame( OpenAI::EMBEDDING_MODEL, $row->embedding_model );
		}
	}

	/**
	 * One cron execution keeps working within its time budget instead of
	 * waiting a minute between steps.
	 */
	public function test_a_run_completes_the_import_within_its_time_budget() {
		remove_filter( 'hyve_ingestion_time_budget', '__return_zero' );
		$post_id = $this->queue();
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertSame( 13, $this->indexed( $post_id ) );
		$this->assertCount( 5, $this->calls );
	}

	/**
	 * A run stops at a rate limit and resumes at the failed batch, keeping
	 * the endpoint and headers for diagnostics.
	 */
	public function test_429_retains_progress_and_honors_token_reset() {
		remove_filter( 'hyve_ingestion_time_budget', '__return_zero' );
		$post_id        = $this->queue();
		$this->response = function () {
			if ( count( $this->calls ) < 2 ) {
				return null;
			}
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [
					'retry-after'              => '6',
					'x-ratelimit-reset-tokens' => '1m2.5s',
				],
				'body'     => wp_json_encode(
					[
						'error' => [
							'message' => 'Too Many Requests',
							'code'    => null,
						],
					]
				),
			];
		};
		Ingestion_Queue::instance()->run( $post_id );
		$job = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
		$this->assertCount( 2, $this->calls );
		$this->assertSame( 5, $job['offset'] );
		$this->assertGreaterThanOrEqual( time() + 62, $job['retry_at'] );
		$this->assertSame( 'moderations', OpenAI::instance()->get_response_context()['endpoint'] );
		$this->assertSame( '6', OpenAI::instance()->get_response_context()['headers']['retry-after'] );
		$this->assertStringContainsString( 'retried automatically', get_post_meta( $post_id, '_hyve_processing_error', true ) );
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertCount( 2, $this->calls, 'A cooling-down worker must not call the provider.' );
		$this->response = null;
		$this->step( $post_id );
		$this->assertSame( 'Chunk 5', $this->calls[2]['input']['input'][0] );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertSame( 13, $this->indexed( $post_id ) );
	}

	/**
	 * A successful response can defer a later batch before it produces a 429.
	 */
	public function test_success_headers_pace_other_jobs_on_the_same_key() {
		$first          = $this->queue();
		$second         = $this->queue();
		$this->response = function () {
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [
					'x-ratelimit-remaining-tokens' => '1000',
					'x-ratelimit-reset-tokens'     => '45s',
				],
				'body'     => wp_json_encode( [ 'results' => array_fill( 0, 5, [ 'flagged' => false ] ) ] ),
			];
		};
		Ingestion_Queue::instance()->run( $first );
		Ingestion_Queue::instance()->run( $second );
		$this->assertCount( 1, $this->calls );
		$this->assertGreaterThanOrEqual( time() + 44, get_post_meta( $second, '_hyve_ingestion', true )['retry_at'] );
	}

	/**
	 * An actionable account failure stops immediately and stays visible,
	 * without leaving the update cron anything to loop on.
	 */
	public function test_fatal_error_leaves_failed_source_without_retry() {
		$post_id        = $this->queue();
		$this->response = function () {
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => 'insufficient_quota',
							'message' => 'No credit',
						],
					]
				),
			];
		};
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertSame( 'failed', get_post_meta( $post_id, '_hyve_ingestion', true )['status'] );
		$this->assertNotEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_needs_update', true ) );
		$this->assertFalse( wp_next_scheduled( Ingestion_Queue::HOOK, [ $post_id ] ) );
		$this->assertSame( 0, (int) DB_Table::instance()->get_count(), 'Staged rows are removed.' );
	}

	/**
	 * Transient failures stop after five attempts; successful work is retained.
	 */
	public function test_transient_failures_are_bounded() {
		$post_id        = $this->queue();
		$this->response = function () {
			return [
				'response' => [ 'code' => 500 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => 'server_error',
							'message' => 'Unavailable',
						],
					]
				),
			];
		};
		$this->step( $post_id, 5 );
		$this->assertCount( 5, $this->calls );
		$this->assertSame( 'failed', get_post_meta( $post_id, '_hyve_ingestion', true )['status'] );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
	}

	/**
	 * A flagged batch must not expose earlier chunks or replace the index.
	 */
	public function test_moderation_rejection_keeps_the_existing_index() {
		$post_id = $this->queue();
		DB_Table::instance()->insert(
			[
				'post_id'      => $post_id,
				'post_content' => 'Existing safe content',
				'post_status'  => 'processed',
			]
		);
		$this->response = function () {
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'results' => [
							[
								'flagged'         => true,
								'categories'      => [ 'violence' => true ],
								'category_scores' => [ 'violence' => 0.99 ],
							],
						],
					]
				),
			];
		};
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertNotEmpty( get_post_meta( $post_id, '_hyve_moderation_failed', true ) );
		$this->assertSame( 1, $this->indexed( $post_id ) );
		$this->assertCount( 1, $this->calls );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
	}

	/**
	 * Lost cron events can be rebuilt from durable jobs.
	 */
	public function test_watchdog_recovers_an_unscheduled_job() {
		$post_id = $this->queue();
		wp_clear_scheduled_hook( Ingestion_Queue::HOOK, [ $post_id ] );
		Ingestion_Queue::instance()->watchdog();
		$this->assertNotFalse( wp_next_scheduled( Ingestion_Queue::HOOK, [ $post_id ] ) );
	}

	/**
	 * A deleted source cannot be resurrected by an old cron event.
	 */
	public function test_removing_a_source_cancels_its_job() {
		$post_id = $this->queue();
		DB_Table::instance()->delete_by_post_id( $post_id );
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertCount( 0, $this->calls );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_ingestion', true ) );
		$this->assertSame( 0, (int) DB_Table::instance()->get_count_by_storage( 'WordPress' ) );
	}

	/**
	 * A second request cannot overwrite progress on an active source.
	 */
	public function test_duplicate_queue_keeps_the_existing_job() {
		$post_id = $this->queue();
		Ingestion_Queue::instance()->run( $post_id );
		$this->queue( 20, $post_id );
		$job = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
		$this->assertSame( 13, $job['total'] );
		$this->assertSame( 5, $job['offset'] );
	}

	/**
	 * The update cron leaves an owned source alone.
	 */
	public function test_update_cron_skips_an_active_job() {
		$post_id = $this->queue();
		DB_Table::instance()->update_posts();
		$this->assertCount( 0, $this->calls );
		$this->assertSame( 13, get_post_meta( $post_id, Ingestion_Queue::JOB, true )['total'] );
	}

	/**
	 * Exercise the public ingestion seam and its real tokenizer dispatch.
	 */
	public function test_ingest_document_dispatches_large_content_to_the_queue() {
		$post_id = self::factory()->post->create();
		$result  = DB_Table::instance()->ingest_document(
			[
				'title'   => 'Large',
				'content' => str_repeat( 'A useful sentence about opening hours. ', 1500 ),
			],
			[ 'post_id' => $post_id ]
		);
		$this->assertTrue( $result );
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertCount( 0, $this->calls );
		$this->assertSame( 0, $this->indexed( $post_id ) );
	}

	/**
	 * Small imports that hit an already exhausted budget also become resumable.
	 */
	public function test_synchronous_moderation_429_falls_back_to_queue() {
		$post_id        = self::factory()->post->create();
		$this->response = function () {
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [ 'retry-after' => '10' ],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => null,
							'message' => 'Too Many Requests',
						],
					]
				),
			];
		};
		$this->assertTrue(
			DB_Table::instance()->ingest_document(
				[
					'title'   => 'Small',
					'content' => 'Short useful content.',
				],
				[ 'post_id' => $post_id ]
			)
		);
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ) );
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertCount( 1, $this->calls, 'The learned cooldown defers the first background batch.' );
	}

	/**
	 * A created document is visible and reserved before any provider call.
	 */
	public function test_created_source_carries_its_meta_from_enqueue() {
		$this->assertTrue(
			Ingestion_Queue::instance()->enqueue(
				[
					'title'   => 'Import',
					'content' => 'Body',
				],
				[
					'create' => true,
					'meta'   => [ '_hyve_attachment_id' => 42 ],
				],
				[
					[
						'post_content' => 'Body',
						'token_count'  => 2,
					],
				]
			)
		);
		$posts = get_posts(
			[
				'post_type'   => 'hyve_docs',
				'post_status' => 'publish',
				'meta_key'    => '_hyve_attachment_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => 42, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		$this->assertCount( 1, $posts );
		$this->assertSame( '1', get_post_meta( $posts[0]->ID, '_hyve_added', true ) );
		$this->assertSame( 'queued', get_post_meta( $posts[0]->ID, '_hyve_ingestion', true )['status'] );
	}

	/**
	 * An external call returning after cancellation must not restore the job.
	 */
	public function test_cancellation_during_moderation_is_fenced() {
		$post_id        = $this->queue();
		$this->response = function () use ( $post_id ) {
			Ingestion_Queue::instance()->cancel( $post_id );
			return null;
		};
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_ingestion', true ) );
		$this->assertFalse( wp_next_scheduled( Ingestion_Queue::HOOK, [ $post_id ] ) );
		$this->assertSame( 0, (int) DB_Table::instance()->get_count_by_storage( 'WordPress' ) );
	}

	/**
	 * Recovery after a committed swap must preserve the replacement's rows.
	 */
	public function test_recovery_after_commit_keeps_the_published_index() {
		$post_id = $this->queue( 6 );
		$crashed = false;
		add_action(
			'updated_post_meta',
			function ( $meta_id, $id, $key, $value ) use ( $post_id, &$crashed ) {
				if ( ! $crashed && $id === $post_id && '_hyve_ingestion' === $key && 'complete' === $value['status'] ) {
					$crashed = true;
					throw new RuntimeException( 'Simulated interruption immediately after commit' );
				}
			},
			10,
			4
		);
		for ( $step = 0; $step < 20 && ! $crashed; $step++ ) {
			$this->step( $post_id );
		}
		$this->assertTrue( $crashed );
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->drain( $post_id );
		$this->assertSame( 6, $this->indexed( $post_id ) );
		$this->assertCount( 3, $this->calls, 'Recovery repeats no provider call.' );
	}

	/**
	 * Re-entrant enqueue observes the lease before the first job is persisted.
	 */
	public function test_enqueue_claims_the_source_before_writing_chunks() {
		$post_id = self::factory()->post->create();
		$second  = null;
		add_action(
			'added_post_meta',
			function ( $meta_id, $id, $key ) use ( $post_id, &$second ) {
				if ( $id === $post_id && null === $second && '_hyve_added' === $key ) {
					$second = Ingestion_Queue::instance()->enqueue(
						[
							'title'   => 'Concurrent',
							'content' => 'Other input',
						],
						[ 'post_id' => $post_id ],
						[]
					);
				}
			},
			10,
			3
		);
		$this->queue( 6, $post_id );
		$this->assertWPError( $second );
		$this->assertSame( 'ingestion_in_progress', $second->get_error_code() );
		$this->assertSame( 'Test', get_post_meta( $post_id, Ingestion_Queue::SOURCE, true )['title'] );
	}

	/**
	 * Meta storage unslashes; the document must survive unchanged and the
	 * published post must match what the synchronous path would write.
	 */
	public function test_document_with_backslashes_round_trips() {
		$doc     = [
			'title'   => 'Paths',
			'content' => 'Open C:\\Users\\me and type \\alpha \\\\ twice.',
		];
		$post_id = wp_insert_post(
			[
				'post_title'   => 'Old',
				'post_content' => 'Old',
				'post_type'    => 'hyve_docs',
				'post_status'  => 'publish',
			]
		);
		$args    = [
			'post_id' => $post_id,
			'create'  => true,
			'action'  => 'update',
		];
		$chunks  = [
			[
				'post_content' => $doc['content'],
				'token_count'  => 10,
			],
		];
		$this->assertTrue( Ingestion_Queue::instance()->enqueue( $doc, $args, $chunks ) );
		$this->assertSame( $doc, get_post_meta( $post_id, Ingestion_Queue::SOURCE, true ) );
		$this->assertTrue( Ingestion_Queue::instance()->enqueue( $doc, $args, $chunks ), 'The same submission is recognised.' );
		$this->drain( $post_id );
		$expected = wp_insert_post(
			[
				'post_title'   => $doc['title'],
				'post_content' => $doc['content'],
				'post_type'    => 'hyve_docs',
				'post_status'  => 'publish',
			]
		);
		$this->assertSame( get_post( $expected )->post_content, get_post( $post_id )->post_content );
		$this->assertEmpty( get_post_meta( $post_id, Ingestion_Queue::SOURCE, true ) );
	}

	/**
	 * A verdict on a snapshot the admin has already edited is discarded, and
	 * the update cron indexes the current content instead.
	 */
	public function test_edit_during_job_defers_a_terminal_verdict() {
		$post_id = $this->queue();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Corrected while indexing',
			]
		);
		$this->response = function () {
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'results' => [
							[
								'flagged'         => true,
								'categories'      => [ 'violence' => true ],
								'category_scores' => [ 'violence' => 0.99 ],
							],
						],
					] 
				),
			];
		};
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_moderation_failed', true ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_ingestion', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_hyve_needs_update', true ) );
	}

	/**
	 * Waiting for the provider is what the queue is for; it is not a strike.
	 */
	public function test_rate_limits_do_not_consume_attempts() {
		$post_id        = $this->queue();
		$this->response = function () {
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [ 'retry-after' => '3' ],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => null,
							'message' => 'Too Many Requests',
						],
					] 
				),
			];
		};
		$this->step( $post_id, 7 );
		$this->assertCount( 7, $this->calls );
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ) );
		$job = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
		$this->assertSame( 0, $job['attempts'] );
		$this->assertGreaterThanOrEqual( time() + 59, $job['retry_at'], 'A 429 always waits at least a minute.' );
		$this->assertSame( 429, OpenAI::instance()->get_response_context()['http_status'] );
		$this->assertArrayNotHasKey( 'status', OpenAI::instance()->get_response_context(), 'The REST layer would send a `status` key as the HTTP code.' );
	}

	/**
	 * Retry-After may be an HTTP date, and no header may park a job for days.
	 */
	public function test_retry_after_dates_are_parsed_and_delays_are_clamped() {
		$post_id        = $this->queue();
		$this->response = function () {
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [ 'retry-after' => gmdate( 'D, d M Y H:i:s', time() + 90 ) . ' GMT' ],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => null,
							'message' => 'Too Many Requests',
						],
					] 
				),
			];
		};
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertGreaterThanOrEqual( time() + 88, get_post_meta( $post_id, Ingestion_Queue::JOB, true )['retry_at'] );
		$this->response = function () {
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [ 'x-ratelimit-reset-tokens' => '3d' ],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => null,
							'message' => 'Too Many Requests',
						],
					] 
				),
			];
		};
		$this->step( $post_id );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS + 1, get_post_meta( $post_id, Ingestion_Queue::JOB, true )['retry_at'] );
	}

	/**
	 * Every job in one cron request draws on the same budget, so a backlog
	 * cannot stretch the request past the server's patience.
	 */
	public function test_jobs_in_one_cron_request_share_the_time_budget() {
		remove_filter( 'hyve_ingestion_time_budget', '__return_zero' );
		add_filter( 'wp_doing_cron', '__return_true' );
		$started                       = $_SERVER['REQUEST_TIME_FLOAT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Restored below.
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 100;
		$post_id                       = $this->queue();
		Ingestion_Queue::instance()->run( $post_id );
		$_SERVER['REQUEST_TIME_FLOAT'] = $started;
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertCount( 1, $this->calls, 'A spent request still makes progress, one step at a time.' );
	}

	/**
	 * Chat traffic must not pay for learning budgets it does not use.
	 */
	public function test_chat_requests_do_not_persist_budget_headers() {
		$this->response = function () {
			return [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'x-ratelimit-remaining-tokens' => '1' ],
				'body'     => wp_json_encode(
					[
						'data' => [
							[
								'index'     => 0,
								'embedding' => [ 0.1 ],
							],
						],
					] 
				),
			];
		};
		OpenAI::instance()->create_embeddings( 'hello' );
		$this->assertFalse( get_transient( 'hyve_rate_' . hash( 'sha256', 'queue-test-key:embeddings' ) ) );
		OpenAI::instance()->pace_requests( true );
		OpenAI::instance()->create_embeddings( 'hello' );
		OpenAI::instance()->pace_requests( false );
		$this->assertSame( 1, get_transient( 'hyve_rate_' . hash( 'sha256', 'queue-test-key:embeddings' ) )['remaining'] );
	}

	/**
	 * Staged rows are invisible to every retrieval query, not only to counts.
	 */
	public function test_retrieval_ignores_staged_rows() {
		$post_id = $this->queue( 6 );
		$this->step( $post_id, 3 );
		$this->assertSame( 'embedding', get_post_meta( $post_id, '_hyve_ingestion', true )['phase'] );
		$this->assertSame( [], DB_Table::instance()->get_embeddings( 0, 50 ) );
		$this->assertSame( [], DB_Table::instance()->get_chunks_by_post_id( $post_id ) );
		$this->assertSame( [], DB_Table::instance()->get_by_status( 'processed' ) );
		$this->assertSame( [], DB_Table::instance()->get_posts_over_limit() );
	}

	/**
	 * The update cron's page is never filled by sources a job already owns.
	 */
	public function test_update_cron_reaches_posts_behind_active_jobs() {
		for ( $index = 0; $index < 5; $index++ ) {
			$this->queue();
		}
		$edited = self::factory()->post->create( [ 'post_content' => 'Short edited content.' ] );
		update_post_meta( $edited, '_hyve_added', 1 );
		update_post_meta( $edited, '_hyve_needs_update', 1 );
		DB_Table::instance()->update_posts();
		$this->assertEmpty( get_post_meta( $edited, '_hyve_needs_update', true ) );
	}

	/**
	 * The REST layer reports a queued source and carries its progress on rows.
	 */
	public function test_rest_add_reports_queued_and_rows_carry_progress() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create( [ 'post_content' => str_repeat( 'A useful sentence about opening hours. ', 1500 ) ] );
		$request = new WP_REST_Request( 'POST', '/hyve/v1/data' );
		$request->set_param( 'data', [ 'ID' => $post_id ] );
		$request->set_param( 'action', 'add' );
		$added = \ThemeIsle\HyveLite\API::instance()->add_data( $request )->get_data();
		$this->assertTrue( $added['queued'] );
		$this->assertSame( 'queued', $added['ingestion']['status'] );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$listing = new WP_REST_Request( 'GET', '/hyve/v1/data' );
		$listing->set_param( 'status', 'included' );
		$listing->set_param( 'type', 'any' );
		$rows = \ThemeIsle\HyveLite\API::instance()->get_data( $listing )->get_data()['posts'];
		$row  = array_values( array_filter( $rows, fn( $item ) => (int) $item['ID'] === $post_id ) )[0];
		$this->assertSame( 'queued', $row['ingestion']['status'] );
		$removal = new WP_REST_Request( 'DELETE', '/hyve/v1/data' );
		$removal->set_param( 'id', $post_id );
		\ThemeIsle\HyveLite\API::instance()->delete_data( $removal );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertSame( 0, (int) DB_Table::instance()->get_count_by_storage( 'WordPress' ) );
	}

	/**
	 * Switching to the platform drops self-hosted jobs instead of showing
	 * them as processing forever.
	 */
	public function test_connect_mode_cancels_leftover_jobs() {
		$post_id = $this->queue();
		update_option( 'hyve_settings', [ 'ai_mode' => \ThemeIsle\HyveLite\Hyve_Connect::MODE_CONNECT ] );
		Ingestion_Queue::instance()->watchdog();
		delete_option( 'hyve_settings' );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_ingestion', true ) );
		$this->assertCount( 0, $this->calls );
	}

	/**
	 * An account that never gets a budget answers 429 forever; the job must
	 * end with the credits message instead of polling for eternity.
	 */
	public function test_persistent_429_eventually_fails() {
		$post_id        = $this->queue();
		$this->response = function () {
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => null,
							'message' => 'Too Many Requests',
						],
					] 
				),
			];
		};
		$this->step( $post_id, Ingestion_Queue::MAX_RATE_WAITS + 5 );
		$this->assertCount( Ingestion_Queue::MAX_RATE_WAITS, $this->calls );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertSame( 'failed', get_post_meta( $post_id, '_hyve_ingestion', true )['status'] );
		$this->assertStringContainsString( 'HTTP 429', get_post_meta( $post_id, '_hyve_processing_error', true ) );
	}

	/**
	 * A failed reveal must neither remove the old rows nor discard the new ones.
	 */
	public function test_failed_publish_write_is_retried_without_losing_rows() {
		global $wpdb;
		$post_id = $this->queue( 6 );
		DB_Table::instance()->insert(
			[
				'post_id'      => $post_id,
				'post_content' => 'Existing safe content',
				'post_status'  => 'processed',
			]
		);
		$broken = 0;
		add_filter(
			'query',
			function ( $sql ) use ( &$broken ) {
				if ( false !== strpos( $sql, "SET post_status = 'processed'" ) && 0 === $broken ) {
					$broken++;
					return 'UPDATE hyve_missing_table SET x = 1';
				}
				return $sql;
			}
		);
		$wpdb->suppress_errors( true );
		$this->step( $post_id, 4 );
		$wpdb->suppress_errors( false );
		$this->assertSame( 1, $broken );
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ), 'A publish write failure is retried.' );
		$this->assertSame( 1, $this->indexed( $post_id ), 'The old index still answers.' );
		$this->assertSame( 6, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d AND post_status = %s', DB_Table::instance()->table_name, $post_id, 'staging' ) ) );
		$this->drain( $post_id );
		$this->assertSame( 6, $this->indexed( $post_id ) );
		$this->assertCount( 3, $this->calls, 'The retry repeats no provider call.' );
	}

	/**
	 * Managed sources are never re-indexed by the update cron, so they must
	 * not carry its flag.
	 */
	public function test_created_sources_do_not_carry_the_update_flag() {
		$this->assertTrue(
			Ingestion_Queue::instance()->enqueue(
				[
					'title'   => 'Import',
					'content' => 'Body',
				],
				[ 'create' => true ],
				[
					[
						'post_content' => 'Body',
						'token_count'  => 2,
					],
				]
			)
		);
		$posts = get_posts(
			[
				'post_type'   => 'hyve_docs',
				'post_status' => 'publish',
				'fields'      => 'ids',
			]
		);
		$this->assertCount( 1, $posts );
		$this->assertEmpty( get_post_meta( $posts[0], '_hyve_needs_update', true ) );
		$this->assertSame( '1', get_post_meta( $posts[0], '_hyve_added', true ) );
	}

	/**
	 * Waiting for a budget another caller spent is not progress: the
	 * counters that end a hopeless job must survive it.
	 */
	public function test_waiting_for_a_shared_budget_is_not_progress() {
		$post_id        = $this->queue();
		$this->response = function () {
			return [
				'response' => [ 'code' => 429 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => null,
							'message' => 'Too Many Requests',
						],
					] 
				),
			];
		};
		$this->step( $post_id );
		$this->assertSame( 1, get_post_meta( $post_id, Ingestion_Queue::JOB, true )['rate_waits'] );
		// A visitor's chat message hits the same key's limit between runs.
		OpenAI::instance()->moderate( 'hello' );
		$this->assertCount( 2, $this->calls );
		$job             = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
		$job['retry_at'] = 0;
		update_post_meta( $post_id, Ingestion_Queue::JOB, $job );
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertCount( 2, $this->calls, 'The worker waits for the learned budget.' );
		$job = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
		$this->assertGreaterThan( time(), $job['retry_at'] );
		$this->assertSame( 1, $job['rate_waits'] );
		$this->assertStringContainsString( 'retried automatically', get_post_meta( $post_id, '_hyve_processing_error', true ) );
	}

	/**
	 * A wait that has elapsed must not throttle the rest of the run. Only the
	 * publish phase leaves the old timestamp in place, so the failure is
	 * provoked there.
	 */
	public function test_an_elapsed_wait_does_not_throttle_publishing() {
		$post_id = $this->queue( 6 );
		$points  = [];
		$failed  = 0;
		$this->qdrant(
			$points,
			function ( $path, $body ) use ( &$failed ) {
				if ( isset( $body['points'][0]['vector'] ) && 0 === $failed ) {
					$failed++;
					return new GuzzleHttp\Psr7\Response( 500, [ 'Content-Type' => 'application/json' ], '{"status":{"error":"Unavailable"}}' );
				}
				return null;
			}
		);
		// Two moderation batches, one embedding batch, then the failed upload.
		for ( $step = 0; $step < 4; $step++ ) {
			$this->step( $post_id );
		}
		$job = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
		$this->assertSame( 1, $failed );
		$this->assertSame( 'publish', $job['phase'] );
		$this->assertGreaterThan( time(), $job['retry_at'] );
		$job['retry_at'] = time() - 5;
		update_post_meta( $post_id, Ingestion_Queue::JOB, $job );
		remove_filter( 'hyve_ingestion_time_budget', '__return_zero' );
		Ingestion_Queue::instance()->run( $post_id );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ), 'One run finishes the upload, the reveal and the swap.' );
		$this->assertCount( 6, $points );
		$this->assertSame( 6, $this->indexed( $post_id ) );
	}

	/**
	 * A prune that keeps failing after the reveal is retried for a day, then
	 * the import completes locally rather than hanging forever.
	 */
	public function test_prune_failures_after_the_reveal_give_up_after_a_day() {
		$post_id = $this->queue( 6 );
		$points  = [ 'old' => [ 'payload' => [ 'post_id' => (string) $post_id ] ] ];
		$this->qdrant(
			$points,
			function ( $path, $body ) {
				if ( false !== strpos( $path, '/delete' ) && ! empty( $body['filter']['must_not'] ) ) {
					return new GuzzleHttp\Psr7\Response( 500, [ 'Content-Type' => 'application/json' ], '{"status":{"error":"Unavailable"}}' );
				}
				return null;
			}
		);
		$this->step( $post_id, 12 );
		$job = get_post_meta( $post_id, Ingestion_Queue::JOB, true );
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ), 'Within a day the prune is retried.' );
		$this->assertNotEmpty( $job['remote_activated'] );
		$this->assertGreaterThan( DB_Table::MAX_PROCESS_ATTEMPTS, $job['attempts'], 'The attempt limit does not apply after the reveal.' );
		$this->assertStringContainsString( 'retried automatically', get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$job['remote_activated'] = time() - DAY_IN_SECONDS - 1;
		update_post_meta( $post_id, Ingestion_Queue::JOB, $job );
		$this->step( $post_id );
		$this->assertFalse( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertSame( 6, $this->indexed( $post_id ) );
		$this->assertArrayHasKey( 'old', $points, 'The stale generation is left for the next re-index to prune.' );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$this->assertSame( 'complete', get_post_meta( $post_id, '_hyve_ingestion', true )['status'] );
	}

	/**
	 * A recovered job no longer announces that it will be retried.
	 */
	public function test_recovery_clears_the_retry_notice() {
		$post_id        = $this->queue();
		$this->response = function () {
			if ( 1 !== count( $this->calls ) ) {
				return null;
			}
			return [
				'response' => [ 'code' => 500 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'error' => [
							'code'    => 'server_error',
							'message' => 'Unavailable',
						],
					] 
				),
			];
		};
		$this->step( $post_id );
		$this->assertStringContainsString( 'retried automatically', get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$this->step( $post_id );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$this->assertSame( 5, get_post_meta( $post_id, Ingestion_Queue::JOB, true )['offset'] );
	}

	/**
	 * An edit re-arms a failed post, without cron endlessly retrying before it.
	 */
	public function test_edit_clears_terminal_queue_state() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_hyve_added', 1 );
		update_post_meta( $post_id, '_hyve_ingestion', [ 'status' => 'failed' ] );
		update_post_meta( $post_id, '_hyve_processing_error', 'Old failure' );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Corrected content',
			]
		);
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_ingestion', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_hyve_needs_update', true ) );
		$this->assertTrue( DB_Table::instance()->add_post( $post_id, 'update' ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_needs_update', true ) );
	}

	/**
	 * Install a fake transport under the real Qdrant API and client models.
	 *
	 * @param array<string, array<string, mixed>> $points Simulated remote store.
	 * @param callable|null                       $before Optional request interleaving.
	 * @return Qdrant_API
	 */
	private function qdrant( &$points, $before = null ) {
		$qdrant   = new Qdrant_API( 'test-key', 'https://example.invalid' );
		$property = new ReflectionProperty( Qdrant\Qdrant::class, 'client' );
		$property->setAccessible( true );
		$transport = $property->getValue( $qdrant->client );
		$http      = new ReflectionProperty( Qdrant\Http\GuzzleClient::class, 'client' );
		$http->setAccessible( true );
		$indexes = [];
		$handler = function ( $request ) use ( &$points, &$indexes, $before ) {
			$body = json_decode( (string) $request->getBody(), true );
			$path = $request->getUri()->getPath();
			if ( $before ) {
				$reply = $before( $path, $body );
				if ( $reply ) {
					return new GuzzleHttp\Promise\FulfilledPromise( $reply );
				}
			}
			if ( false !== strpos( $path, '/index' ) ) {
				$indexes[] = $body['field_name'];
				return new GuzzleHttp\Promise\FulfilledPromise( new GuzzleHttp\Psr7\Response( 200, [ 'Content-Type' => 'application/json' ], wp_json_encode( [ 'result' => [ 'status' => 'completed' ] ] ) ) );
			}
			// Hosted clusters refuse a filter on a field without a payload index.
			foreach ( array_merge( $body['filter']['must'] ?? [], $body['filter']['must_not'] ?? [] ) as $condition ) {
				if ( ! in_array( $condition['key'], $indexes, true ) ) {
					return new GuzzleHttp\Promise\FulfilledPromise( new GuzzleHttp\Psr7\Response( 400, [ 'Content-Type' => 'application/json' ], wp_json_encode( [ 'status' => [ 'error' => 'Index required but not found for "' . $condition['key'] . '"' ] ] ) ) );
				}
			}
			$matches = function ( $point, $filter ) {
				foreach ( $filter['must'] ?? [] as $condition ) {
					if ( ( $point['payload'][ $condition['key'] ] ?? null ) !== $condition['match']['value'] ) {
						return false;
					}
				}
				foreach ( $filter['must_not'] ?? [] as $condition ) {
					if ( ( $point['payload'][ $condition['key'] ] ?? null ) === $condition['match']['value'] ) {
						return false;
					}
				}
				return true;
			};
			$result  = [ 'status' => 'completed' ];
			if ( isset( $body['points'][0]['vector'] ) ) {
				foreach ( $body['points'] as $point ) {
					$points[ $point['id'] ] = $point;
				}
			} elseif ( false !== strpos( $path, '/payload' ) ) {
				foreach ( $body['points'] as $id ) {
					$points[ $id ]['payload'] = array_merge( $points[ $id ]['payload'], $body['payload'] );
				}
			} elseif ( false !== strpos( $path, '/delete' ) ) {
				foreach ( $points as $id => $point ) {
					if ( $matches( $point, $body['filter'] ) ) {
						unset( $points[ $id ] );
					}
				}
				$result = [ 'status' => 'acknowledged' ];
			} elseif ( false !== strpos( $path, '/search' ) ) {
				$result = [];
				foreach ( $points as $point ) {
					if ( $matches( $point, $body['filter'] ) ) {
						$result[] = [
							'payload' => $point['payload'],
							'score'   => 1.0,
						];
					}
				}
			}
			return new GuzzleHttp\Promise\FulfilledPromise( new GuzzleHttp\Psr7\Response( 200, [ 'Content-Type' => 'application/json' ], wp_json_encode( [ 'result' => $result ] ) ) );
		};
		$http->setValue( $transport, new GuzzleHttp\Client( [ 'handler' => $handler ] ) );
		$instance = new ReflectionProperty( Qdrant_API::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, $qdrant );
		update_option( 'hyve_qdrant_status', 'active' );
		// As on connect and on upgrade: every filtered field gets its index.
		$this->assertTrue( $qdrant->ensure_payload_index() );
		$this->assertEqualsCanonicalizing( [ 'post_id', '_hyve_ingestion_generation', '_hyve_ingestion_pending' ], $indexes );
		return $qdrant;
	}

	/**
	 * Uploads stay hidden until every batch exists, then replace and delete cleanly.
	 */
	public function test_qdrant_stages_replaces_and_deletes_string_source_ids() {
		$post_id = $this->queue( 6 );
		$points  = [
			'old' => [
				'payload' => [
					'post_id'      => (string) $post_id,
					'post_content' => 'Old',
				],
			],
		];
		$qdrant  = $this->qdrant( $points );
		// Two moderation batches, one embedding batch, one hidden upload.
		$this->step( $post_id, 4 );
		$this->assertTrue( Ingestion_Queue::instance()->is_active( $post_id ) );
		$this->assertCount( 7, $points );
		$this->assertCount( 1, $qdrant->search( [ 0.1 ], 0.0 ), 'Only the old generation is visible during staging.' );
		$this->drain( $post_id );
		$this->assertCount( 6, $points );
		$this->assertArrayNotHasKey( 'old', $points );
		$this->assertCount( 6, $qdrant->search( [ 0.1 ], 0.0 ) );
		foreach ( $points as $point ) {
			$this->assertSame( (string) $post_id, $point['payload']['post_id'] );
		}
		foreach ( DB_Table::instance()->get_by_status( 'processed' ) as $row ) {
			$this->assertSame( 'Qdrant', $row->storage );
		}
		$this->assertTrue( $qdrant->delete_point( $post_id ) );
		$this->assertCount( 0, $points );
	}

	/**
	 * A failed remote upload leaves the old knowledge searchable.
	 */
	public function test_qdrant_failure_cleans_hidden_generation_and_keeps_old_index() {
		$post_id = $this->queue( 6 );
		$points  = [ 'old' => [ 'payload' => [ 'post_id' => (string) $post_id ] ] ];
		$qdrant  = $this->qdrant(
			$points,
			function ( $path, $body ) {
				if ( isset( $body['points'][0]['vector'] ) ) {
					return new GuzzleHttp\Psr7\Response( 500, [ 'Content-Type' => 'application/json' ], '{"status":{"error":"Unavailable"}}' );
				}
				return null;
			}
		);
		$this->step( $post_id, 30 );
		$this->assertSame( 'failed', get_post_meta( $post_id, '_hyve_ingestion', true )['status'] );
		$this->assertSame( [ 'old' ], array_keys( $points ) );
		$this->assertCount( 1, $qdrant->search( [ 0.1 ], 0.0 ) );
	}

	/**
	 * The response can arrive after cancellation has already deleted the source.
	 */
	public function test_qdrant_late_upload_is_cleaned_after_cancellation() {
		$post_id = $this->queue( 6 );
		$points  = [];
		$this->qdrant(
			$points,
			function ( $path, $body ) use ( $post_id ) {
				if ( isset( $body['points'][0]['vector'] ) ) {
					Ingestion_Queue::instance()->cancel( $post_id );
				}
				return null;
			}
		);
		$this->drain( $post_id );
		$this->assertCount( 0, $points );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_ingestion', true ) );
		$this->assertSame( 0, $this->indexed( $post_id ) );
	}
}
