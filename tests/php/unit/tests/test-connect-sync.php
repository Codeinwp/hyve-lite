<?php
/**
 * Tests for the to-Connect content sync and purge auto-recovery.
 *
 * HTTP is intercepted with `pre_http_request`, so these assert the sync job's
 * bookkeeping (pending set, progress option, synced markers, cron scheduling)
 * and the recovery trigger without a network or a real cron tick.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Hyve_Connect;
use ThemeIsle\HyveLite\DB_Table;

/**
 * Class ConnectSyncTest
 */
class ConnectSyncTest extends WP_UnitTestCase {

	/**
	 * Builders can change rendered text through post meta without save_post.
	 * Matching the earlier manifest must not acknowledge the changed content.
	 */
	public function test_reconcile_checks_current_builder_text_before_confirming() {
		$this->enable_connect();
		$post = $this->indexed_post();
		$kept = $this->indexed_post();
		update_post_meta( $post, '_hyve_processing_error', 'Response lost' );
		update_post_meta( $kept, '_hyve_processing_error', 'Response lost' );
		update_post_meta( $post, 'builder_text', 'Original builder text' );
		$render = static function ( $content ) use ( $post ) {
			return get_the_ID() === $post ? get_post_meta( $post, 'builder_text', true ) : $content;
		};
		add_filter( 'the_content', $render );
		add_filter(
			'pre_http_request',
			function () use ( $post ) {
				update_post_meta( $post, 'builder_text', 'Changed builder text' );
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse( [ [ 'job_complete', [ 'in_sync' => true ] ] ] ),
				];
			}
		);
		try {
			$this->assertTrue( DB_Table::instance()->connect_reconcile() );
			$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
			$this->assertSame( 'Response lost', get_post_meta( $post, '_hyve_processing_error', true ) );
			// The unchanged batch mate is confirmed by the same answer.
			$this->assertSame( DB_Table::instance()->connect_source_hash( $kept ), get_post_meta( $kept, '_hyve_connect_synced_hash', true ) );
			$this->assertSame( '', get_post_meta( $kept, '_hyve_processing_error', true ) );
		} finally {
			remove_filter( 'the_content', $render );
		}
	}

	/**
	 * Lost upload responses must not leave a confirmed hosted source failed.
	 *
	 * @dataProvider matching_reconcile_levels
	 * @param string $level Reconcile step that confirms matching content.
	 */
	public function test_reconcile_repairs_exhausted_upload_acknowledgement( $level ) {
		$this->enable_connect();
		$post = $this->indexed_post();
		// The platform stored the batch but the response never arrived as a success.
		$this->intercept( '{"message":"Bad request"}', 400 );
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();
		$this->assertNotSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		remove_all_filters( 'pre_http_request' );
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $level, $post ) {
				$payload = json_decode( $args['body'], true );
				if ( isset( $payload['aggregate'] ) ) {
					$result = [ 'in_sync' => 'root' === $level ];
				} elseif ( isset( $payload['bucket_hashes'] ) ) {
					$result = [ 'differing_buckets' => 'bucket' === $level ? [] : [ Hyve_Connect::kb_bucket_of( $post ) ] ];
				} else {
					$result = [
						'missing' => [],
						'stale'   => [],
					];
				}
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse( [ [ 'job_complete', $result ] ] ),
				];
			},
			10,
			2
		);
		$this->assertTrue( DB_Table::instance()->connect_reconcile() );
		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( DB_Table::instance()->connect_source_hash( $post ), get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Each reconcile response can confirm content without another upload.
	 *
	 * @return array<array{string}> Confirmation steps.
	 */
	public function matching_reconcile_levels() {
		return [ [ 'root' ], [ 'bucket' ], [ 'detail' ] ];
	}

	/**
	 * A matching manifest is a snapshot; an edit during the network request
	 * must not be acknowledged as if the platform had received the new text.
	 */
	public function test_reconcile_does_not_acknowledge_a_concurrent_edit() {
		$this->enable_connect();
		$post = $this->indexed_post();
		$kept = $this->indexed_post();
		update_post_meta( $post, '_hyve_processing_error', 'Response lost' );
		update_post_meta( $kept, '_hyve_processing_error', 'Response lost' );
		add_filter(
			'pre_http_request',
			function () use ( $post ) {
				wp_update_post(
					[
						'ID'           => $post,
						'post_content' => 'Changed during reconcile',
					]
				);
				update_post_meta( $post, '_hyve_needs_update', 1 );
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse( [ [ 'job_complete', [ 'in_sync' => true ] ] ] ),
				];
			}
		);
		$this->assertTrue( DB_Table::instance()->connect_reconcile() );
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( '1', get_post_meta( $post, '_hyve_needs_update', true ) );
		// The untouched batch mate is confirmed by the same answer.
		$this->assertSame( DB_Table::instance()->connect_source_hash( $kept ), get_post_meta( $kept, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( '', get_post_meta( $kept, '_hyve_processing_error', true ) );
	}

	/**
	 * A failed source remains authoritative in reconcile, which can explicitly
	 * restart its sync rather than treating its possibly-stored copy as orphaned.
	 */
	public function test_reconcile_preserves_and_restarts_a_failed_source() {
		$this->enable_connect();
		$post = $this->indexed_post();
		$kept = $this->indexed_post();
		update_post_meta( $post, '_hyve_processing_error', 'Embedding failed' );
		update_post_meta( $kept, '_hyve_processing_error', 'Response lost' );
		// Local rows outlive the failure until the platform confirms it holds the source.
		$row    = DB_Table::instance()->insert(
			[
				'post_id'      => $post,
				'post_content' => 'Saved local content',
			]
		);
		$scoped = [];
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $post, $kept, &$scoped ) {
				$payload = json_decode( $args['body'], true );
				if ( ! empty( $payload['manifest'] ) ) {
					$scoped = $payload['manifest'];
					$result = [
						'missing' => [ $post ],
						'stale'   => [],
						'deleted' => [],
					];
				} elseif ( ! empty( $payload['bucket_hashes'] ) ) {
					$result = [ 'differing_buckets' => array_values( array_unique( [ Hyve_Connect::kb_bucket_of( $post ), Hyve_Connect::kb_bucket_of( $kept ) ] ) ) ];
				} else {
					$result = [ 'in_sync' => false ];
				}
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse( [ [ 'job_complete', $result ] ] ),
				];
			},
			10,
			2
		);

		$this->assertTrue( DB_Table::instance()->connect_reconcile() );
		$sent = [];
		foreach ( $scoped as $entry ) {
			$sent[ (int) $entry['id'] ] = $entry['hash'];
		}
		ksort( $sent );
		$expected = [
			$post => DB_Table::instance()->connect_source_hash( $post ),
			$kept => DB_Table::instance()->connect_source_hash( $kept ),
		];
		ksort( $expected );
		$this->assertSame( $expected, $sent );
		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertNotNull( DB_Table::instance()->get( $row ) );
		$this->assertSame( [ $post ], array_map( 'intval', DB_Table::instance()->connect_pending_posts() ) );
		$this->assertTrue( DB_Table::instance()->connect_sync_status()['in_progress'] );
		// The source the platform still holds is confirmed, not re-pushed.
		$this->assertSame( $expected[ $kept ], get_post_meta( $kept, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( '', get_post_meta( $kept, '_hyve_processing_error', true ) );
	}

	/**
	 * Provider failures, transport failures and rate limits have a finite retry
	 * budget, after which the job pauses with its sources still pending and
	 * resumes on its own once the platform answers again.
	 *
	 * @dataProvider temporary_batch_errors
	 * @param string $body   HTTP response body (empty means transport failure).
	 * @param int    $status HTTP response code.
	 */
	public function test_batch_errors_stop_after_bounded_backoff( $body, $status ) {
		$this->enable_connect();
		$post = $this->indexed_post();
		update_post_meta( $post, '_hyve_needs_update', 1 );
		$row      = DB_Table::instance()->insert(
			[
				'post_id'      => $post,
				'post_content' => 'Saved local content',
			]
		);
		$requests = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $status, &$requests ) {
				$is_quota = false !== strpos( $url, Hyve_Connect::PATH_QUOTA );
				if ( '' === $body ) {
					$requests += $is_quota ? 0 : 1;
					return new WP_Error( 'http_request_failed', 'Offline' );
				}
				// The platform's rate limiter and kill switch guard the upload
				// route alone, so the quota route keeps answering.
				if ( $is_quota ) {
					$quota = [
						'used'  => 1,
						'limit' => 1000,
					];
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'kb' => [ 'storage' => $quota ] ] ),
					];
				}
				++$requests;
				return [
					'response' => [ 'code' => $status ],
					'body'     => $body,
				];
			},
			10,
			3
		);
		DB_Table::instance()->connect_start_sync();

		for ( $attempt = 1; $attempt <= DB_Table::MAX_PROCESS_ATTEMPTS; ++$attempt ) {
			// Simulate cron consuming the event before invoking the handler.
			wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
			DB_Table::instance()->connect_run_sync();
			if ( $attempt < DB_Table::MAX_PROCESS_ATTEMPTS ) {
				$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
				$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS * $attempt, wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ), 2 );
			}
		}

		$this->assertSame( DB_Table::MAX_PROCESS_ATTEMPTS, $requests );
		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '1', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertNotNull( DB_Table::instance()->get( $row ) );
		$this->assertSame( 1, DB_Table::instance()->connect_pending_count() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$status = DB_Table::instance()->connect_sync_status();
		$this->assertFalse( $status['in_progress'] );
		$this->assertTrue( $status['blocked'] );
		$this->assertSame( 'unavailable', $status['quota']['kind'] );
		$this->assertStringContainsString( 'could not be reached', $status['message'] );

		$this->assertEqualsWithDelta( time() + DB_Table::CONNECT_PAUSE_RESUME, $status['resume_after'], 2 );

		// Neither the watchdog nor an admin load restarts it during the pause.
		DB_Table::instance()->connect_sync_watchdog();
		delete_transient( 'hyve_connect_stats' );
		DB_Table::instance()->connect_maybe_resume_blocked();
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$this->assertTrue( DB_Table::instance()->connect_sync_status()['blocked'] );

		// Once the pause has elapsed and the platform answers, the next admin
		// load resumes with a fresh budget.
		$status                 = DB_Table::instance()->connect_sync_status();
		$status['resume_after'] = time() - 1;
		update_option( DB_Table::CONNECT_SYNC_OPTION, $status );
		remove_all_filters( 'pre_http_request' );
		delete_transient( 'hyve_connect_stats' );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $post ) {
				$quota  = [
					'used'  => 1,
					'limit' => 1000,
				];
				$stored = [
					'id'     => $post,
					'status' => 'stored',
				];
				$body   = false !== strpos( $url, Hyve_Connect::PATH_QUOTA )
					? wp_json_encode( [ 'kb' => [ 'storage' => $quota ] ] )
					: $this->sse( [ [ 'job_complete', [ 'results' => [ $stored ] ] ] ] );
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $body,
				];
			},
			10,
			3
		);
		DB_Table::instance()->connect_maybe_resume_blocked();
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();
		$this->assertNotSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_sync_status()['attempts'] );
		$this->assertEmpty( DB_Table::instance()->connect_sync_status()['blocked'] );
	}

	/**
	 * A batch that keeps failing while the platform itself answers is the
	 * batch's fault: its sources fail after the retry budget and the job moves
	 * on, instead of pausing and replaying the same batch on every resume.
	 *
	 * @dataProvider repeating_batch_errors
	 * @param string $body   Upsert response body.
	 * @param int    $status Upsert HTTP status.
	 */
	public function test_repeating_batch_failure_fails_its_sources_when_the_platform_answers( $body, $status ) {
		$this->enable_connect();
		$post = $this->indexed_post();
		update_post_meta( $post, '_hyve_needs_update', 1 );
		$requests = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $status, &$requests ) {
				if ( false !== strpos( $url, Hyve_Connect::PATH_QUOTA ) ) {
					$quota = [
						'used'  => 1,
						'limit' => 1000,
					];
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'kb' => [ 'storage' => $quota ] ] ),
					];
				}
				++$requests;
				return [
					'response' => [ 'code' => $status ],
					'body'     => $body,
				];
			},
			10,
			3
		);
		DB_Table::instance()->connect_start_sync();

		for ( $attempt = 1; $attempt <= DB_Table::MAX_PROCESS_ATTEMPTS; ++$attempt ) {
			wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
			DB_Table::instance()->connect_run_sync();
		}

		$this->assertSame( DB_Table::MAX_PROCESS_ATTEMPTS, $requests );
		$this->assertStringContainsString( 're-add this content', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );
		$status = DB_Table::instance()->connect_sync_status();
		$this->assertFalse( $status['in_progress'] );
		$this->assertEmpty( $status['blocked'] );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );

		// An admin load has nothing to resume, so the batch is not replayed.
		delete_transient( 'hyve_connect_stats' );
		DB_Table::instance()->connect_maybe_resume_blocked();
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$this->assertSame( DB_Table::MAX_PROCESS_ATTEMPTS, $requests );
	}

	/**
	 * Failures the platform reports while it is otherwise reachable.
	 *
	 * @return array<string, array{string, int}>
	 */
	public function repeating_batch_errors() {
		return [
			'provider' => [ "event: error\ndata: {\"code\":\"provider_error\",\"message\":\"Embedding failed\"}\n\n", 200 ],
			'timeout'  => [ 'Request timeout', 408 ],
			'conflict' => [ 'Conflict', 409 ],
			'server'   => [ 'Internal error', 500 ],
		];
	}

	/**
	 * A refusal that is about the site's setup rather than the batch (a missing
	 * route, a refused header) pauses the job instead of failing the backlog.
	 */
	public function test_site_setup_refusals_pause_the_job() {
		$this->enable_connect();
		$post    = $this->indexed_post();
		$uploads = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$uploads ) {
				// The quota route is unaffected by a missing upload route.
				if ( false !== strpos( $url, Hyve_Connect::PATH_QUOTA ) ) {
					$quota = [
						'used'  => 1,
						'limit' => 1000,
					];
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'kb' => [ 'storage' => $quota ] ] ),
					];
				}
				++$uploads;
				return [
					'response' => [ 'code' => 404 ],
					'body'     => '{"message":"Workflow not found"}',
				];
			},
			10,
			3
		);
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( 1, $uploads );
		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( 1, DB_Table::instance()->connect_pending_count() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['blocked'] );
		$this->assertSame( 'unavailable', $status['quota']['kind'] );
		$this->assertStringContainsString( 'Workflow not found', $status['message'] );

		// Admin loads during the pause do not replay the refused upload.
		delete_transient( 'hyve_connect_stats' );
		DB_Table::instance()->connect_maybe_resume_blocked();
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$this->assertTrue( DB_Table::instance()->connect_sync_status()['blocked'] );

		// After the pause, one retry per pause.
		$status['resume_after'] = time() - 1;
		update_option( DB_Table::CONNECT_SYNC_OPTION, $status );
		DB_Table::instance()->connect_maybe_resume_blocked();
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();
		$this->assertSame( 2, $uploads );
		$this->assertTrue( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertGreaterThan( time(), DB_Table::instance()->connect_sync_status()['resume_after'] );
	}

	/**
	 * A paused job resumes by cron once its wait has passed: nobody needs to
	 * open wp-admin for the outage to be re-checked.
	 */
	public function test_paused_sync_resumes_by_cron_without_an_admin_load() {
		$this->enable_connect();
		$post    = $this->indexed_post();
		$healthy = false;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $post, &$healthy ) {
				if ( false !== strpos( $url, Hyve_Connect::PATH_QUOTA ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode(
							[
								'kb' => [
									'storage' => [
										'used'  => 1,
										'limit' => 1000,
									],
								],
							]
						),
					];
				}
				if ( ! $healthy ) {
					return [
						'response' => [ 'code' => 404 ],
						'body'     => '{"message":"Workflow not found"}',
					];
				}
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse(
						[
							[
								'job_complete',
								[
									'results' => [
										[
											'id'     => $post,
											'status' => 'stored',
										],
									],
								],
							],
						]
					),
				];
			},
			10,
			3
		);
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['blocked'] );
		$this->assertSame( $status['resume_after'], wp_next_scheduled( DB_Table::CONNECT_RESUME_HOOK ) );

		// The wait ends and the platform is back: the cron event restarts the job.
		$healthy                = true;
		$status['resume_after'] = time() - 1;
		update_option( DB_Table::CONNECT_SYNC_OPTION, $status );
		delete_transient( 'hyve_connect_stats' );
		do_action( DB_Table::CONNECT_RESUME_HOOK );

		$this->assertEmpty( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();
		$this->assertNotSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * Queueing more work during a retry delay folds into the job: the attempt
	 * counter and the single scheduled pass are kept.
	 */
	public function test_enqueue_during_a_retry_delay_keeps_the_backoff() {
		$this->enable_connect();
		$post = $this->indexed_post();
		$this->intercept( 'Service unavailable', 503 );
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();
		$next = wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $next, 2 );

		$other = self::factory()->post->create();
		$this->assertSame( 1, DB_Table::instance()->connect_enqueue_posts( [ $other ] ) );

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertSame( 1, $status['attempts'] );
		$this->assertSame( 2, $status['total'] );
		$this->assertSame( $next, wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$this->assertSame( 2, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * Rejected credentials affect every source alike: the job pauses with the
	 * sources pending instead of writing the backlog off batch by batch.
	 *
	 * @dataProvider auth_batch_errors
	 * @param int $code HTTP status.
	 */
	public function test_auth_failures_pause_the_job( $code ) {
		$this->enable_connect();
		$post = $this->indexed_post();
		$row  = DB_Table::instance()->insert(
			[
				'post_id'      => $post,
				'post_content' => 'Saved local content',
			]
		);
		$this->intercept( '{"message":"Unauthenticated."}', $code );
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertNotNull( DB_Table::instance()->get( $row ) );
		$this->assertSame( 1, DB_Table::instance()->connect_pending_count() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$status = DB_Table::instance()->connect_sync_status();
		$this->assertFalse( $status['in_progress'] );
		$this->assertTrue( $status['blocked'] );
		$this->assertSame( 'auth', $status['quota']['kind'] );
		$this->assertStringContainsString( 'Reconnect', $status['message'] );
		$this->assertEqualsWithDelta( time() + DB_Table::CONNECT_PAUSE_RESUME, $status['resume_after'], 2 );

		// Stats fail with the same credentials, so an admin load cannot resume it.
		delete_transient( 'hyve_connect_stats' );
		DB_Table::instance()->connect_maybe_resume_blocked();
		$this->assertTrue( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * HTTP statuses the platform uses for rejected credentials.
	 *
	 * @return array<array{int}>
	 */
	public function auth_batch_errors() {
		return [ [ 401 ], [ 403 ] ];
	}

	/**
	 * A validation failure naming documents fails only those; their healthy
	 * batch mates stay pending and the job carries on without a retry delay.
	 */
	public function test_invalid_document_fails_alone() {
		$this->enable_connect();
		$this->indexed_post();
		$this->indexed_post();
		$this->indexed_post();
		// Posts created in the same second have no stable query order, so take
		// the batch order the sync will use and reject the document in the middle.
		list( $a, $b, $c ) = array_map( 'intval', DB_Table::instance()->connect_pending_posts() );
		$this->intercept(
			wp_json_encode(
				[
					'message' => 'Validation failed',
					'errors'  => [ 'input.documents.1.content' => [ 'The input.documents.1.content exceeds the 524288-byte limit.' ] ],
				]
			),
			422
		);
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( '', get_post_meta( $a, '_hyve_processing_error', true ) );
		$this->assertStringContainsString( 'exceeds the 524288-byte limit', get_post_meta( $b, '_hyve_processing_error', true ) );
		$this->assertStringContainsString( 're-add this content', get_post_meta( $b, '_hyve_processing_error', true ) );
		$this->assertSame( '', get_post_meta( $c, '_hyve_processing_error', true ) );
		$this->assertSame( [ $a, $c ], array_map( 'intval', DB_Table::instance()->connect_pending_posts() ) );
		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['in_progress'] );
		$this->assertEmpty( $status['blocked'] );
		$this->assertSame( 1, $status['current'] );
		$this->assertSame( 0, $status['attempts'] );
		$this->assertEqualsWithDelta( time() + 10, wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ), 2 );
	}

	/**
	 * An edit saved while the batch was in flight re-flags its post. The stale
	 * attempt's failure must not clear that flag or mark the new content failed.
	 */
	public function test_edit_during_a_failed_upload_stays_pending() {
		$this->enable_connect();
		$post  = $this->indexed_post();
		$other = $this->indexed_post();
		add_filter(
			'pre_http_request',
			function () use ( $post ) {
				update_post_meta( $post, '_hyve_needs_update', 1 );
				return [
					'response' => [ 'code' => 400 ],
					'body'     => '{"message":"Bad request"}',
				];
			}
		);
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '1', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertStringContainsString( 'Bad request', get_post_meta( $other, '_hyve_processing_error', true ) );
		$this->assertSame( [ $post ], array_map( 'intval', DB_Table::instance()->connect_pending_posts() ) );
		$this->assertSame( 1, DB_Table::instance()->connect_sync_status()['current'] );
	}

	/**
	 * An edit saved by another request while the upload runs only reaches the
	 * database; this process's meta cache still shows the post unflagged.
	 */
	public function test_edit_from_another_request_during_a_failed_upload_stays_pending() {
		global $wpdb;

		$this->enable_connect();
		$post = $this->indexed_post();
		add_filter(
			'pre_http_request',
			function () use ( $post, $wpdb ) {
				$wpdb->insert(
					$wpdb->postmeta,
					[
						'post_id'    => $post,
						'meta_key'   => '_hyve_needs_update', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- simulates another request's write.
						'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- simulates another request's write.
					]
				);
				return [
					'response' => [ 'code' => 400 ],
					'body'     => '{"message":"Bad request"}',
				];
			}
		);
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '1', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertSame( [ $post ], array_map( 'intval', DB_Table::instance()->connect_pending_posts() ) );
	}

	/**
	 * A post already flagged when the batch was built, then saved again while
	 * the upload runs, is edited content too and stays pending.
	 */
	public function test_resave_of_a_flagged_post_during_a_failed_upload_stays_pending() {
		global $wpdb;

		$this->enable_connect();
		$post = $this->indexed_post();
		update_post_meta( $post, '_hyve_needs_update', 1 );
		add_filter(
			'pre_http_request',
			function () use ( $post, $wpdb ) {
				$wpdb->update(
					$wpdb->posts,
					[ 'post_modified_gmt' => '2030-01-01 00:00:00' ],
					[ 'ID' => $post ]
				);
				return [
					'response' => [ 'code' => 400 ],
					'body'     => '{"message":"Bad request"}',
				];
			}
		);
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '1', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertSame( [ $post ], array_map( 'intval', DB_Table::instance()->connect_pending_posts() ) );
	}

	/**
	 * Disconnecting while a batch is in flight ends the job without recording
	 * Connect failures against posts that are now self-hosted.
	 */
	public function test_disconnect_during_a_failed_upload_records_nothing() {
		$this->enable_connect();
		$post = $this->indexed_post();
		update_post_meta( $post, '_hyve_needs_update', 1 );
		add_filter(
			'pre_http_request',
			function () {
				update_option( 'hyve_settings', [ 'ai_mode' => 'api' ] );
				return [
					'response' => [ 'code' => 400 ],
					'body'     => '{"message":"Bad request"}',
				];
			}
		);
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '1', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertFalse( DB_Table::instance()->connect_sync_status()['in_progress'] );
		$this->assertEmpty( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Existing HTTP and SSE error shapes require no platform contract change.
	 *
	 * @return array<string, array{string, int}>
	 */
	public function temporary_batch_errors() {
		return [
			'rate'      => [ '{"error":"Too many requests"}', 429 ],
			'early'     => [ 'Too early', 425 ],
			'gateway'   => [ 'Bad gateway', 502 ],
			'server'    => [ 'Service unavailable', 503 ],
			'transport' => [ '', 0 ],
		];
	}

	/**
	 * A request the platform refuses outright, without naming a document,
	 * cannot recover with an unchanged batch: its sources fail at once.
	 *
	 * @dataProvider permanent_batch_errors
	 * @param int $code Permanent HTTP failure.
	 */
	public function test_permanent_batch_error_stops_immediately( $code ) {
		$this->enable_connect();
		$post = $this->indexed_post();
		update_post_meta( $post, '_hyve_needs_update', 1 );
		$this->intercept( '{"message":"Fix the request"}', $code );
		DB_Table::instance()->connect_start_sync();
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		DB_Table::instance()->connect_run_sync();

		$this->assertStringContainsString( 'Fix the request', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$this->assertFalse( DB_Table::instance()->connect_sync_status()['in_progress'] );
		$this->assertEmpty( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertContains( $post, array_column( DB_Table::instance()->connect_local_manifest(), 'id' ) );
	}

	/**
	 * Permanent errors carried by the existing HTTP contract.
	 *
	 * @return array<array{int}> Permanent HTTP errors.
	 */
	public function permanent_batch_errors() {
		return [ [ 400 ], [ 413 ], [ 422 ] ];
	}

	/**
	 * Sync sends decoded builder text and hashes exactly those sent bytes.
	 */
	public function test_raw_html_sync_uses_the_same_content_for_hashing() {
		$this->enable_connect();
		$content = file_get_contents( dirname( __DIR__ ) . '/fixtures/wpbakery-raw-html.txt' );
		$post    = self::factory()->post->create( [ 'post_content' => $content ] );
		update_post_meta( $post, '_hyve_added', 1 );
		$sent = '';
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( $post, &$sent ) {
				$payload = json_decode( $args['body'], true );
				$sent    = $payload['documents'][0]['content'];
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse(
						[
							[
								'job_complete',
								[
									'results' => [
										[
											'id'     => $post,
											'status' => 'stored',
										],
									],
								],
							],
						]
					),
				];
			},
			10,
			2
		);
		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$this->assertStringContainsString( 'iPad Screen Repairs Prices', $sent );
		$this->assertStringNotContainsString( 'JTNDZGl2', $sent );
		$this->assertSame( hash( 'sha256', $sent ), get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( hash( 'sha256', $sent ), DB_Table::instance()->connect_source_hash( $post ) );
	}

	/**
	 * Ensure the KB table exists so the sync job's local-row cleanup runs.
	 */
	protected function setUp(): void {
		parent::setUp();
		new DB_Table();
	}

	/**
	 * Reset options, transients, filters and scheduled events between tests.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'product_hyve_license_key' );
		delete_option( 'hyve_settings' );
		delete_option( DB_Table::CONNECT_SYNC_OPTION );
		delete_option( DB_Table::CONNECT_IDENTITY_OPTION );
		delete_option( DB_Table::CONNECT_DELETE_OPTION );
		delete_transient( 'hyve_connect_stats' );
		delete_transient( 'hyve_connect_recovery_check' );
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
		wp_clear_scheduled_hook( DB_Table::CONNECT_DELETE_HOOK );
		parent::tearDown();
	}

	/**
	 * Turn Connect on for the duration of a test.
	 */
	private function enable_connect() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );
	}

	/**
	 * Intercept every HTTP request and return a canned body.
	 *
	 * @param string $body Response body.
	 * @param int    $code HTTP status.
	 *
	 * @return void
	 */
	private function intercept( $body, $code = 200 ) {
		add_filter(
			'pre_http_request',
			function () use ( $body, $code ) {
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
	 * Create a post that looks indexed, optionally already on the platform.
	 *
	 * @param bool $synced Whether to mark it synced (`_hyve_connect_synced_hash`).
	 *
	 * @return int
	 */
	private function indexed_post( $synced = false ) {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_hyve_added', 1 );

		if ( $synced ) {
			update_post_meta( $post_id, '_hyve_connect_synced_hash', 'HASH' );
		}

		return $post_id;
	}

	/**
	 * The pending set is indexed sources not yet on the platform.
	 */
	public function test_pending_posts_excludes_already_synced() {
		$this->indexed_post( false );
		$this->indexed_post( false );
		$this->indexed_post( true );

		$this->assertSame( 2, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * A stale processing error (e.g. a bad OpenAI key from before the switch)
	 * must not keep a source out of the sync forever: starting a round clears
	 * it so the source re-enters the pending set.
	 */
	public function test_start_sync_reattempts_sources_with_stale_errors() {
		$this->enable_connect();
		$post = $this->indexed_post( false );
		update_post_meta( $post, '_hyve_processing_error', 'Incorrect OpenAI API key.' );

		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );

		DB_Table::instance()->connect_start_sync();

		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( 1, DB_Table::instance()->connect_sync_status()['total'] );
	}

	/**
	 * An embedding retry queued before the switch (hyve_process_post) must not
	 * run the local OpenAI pipeline in Connect mode, where the missing key
	 * would record a bogus error against the source.
	 */
	public function test_process_post_cron_noops_in_connect_mode() {
		$this->enable_connect();
		$post = $this->indexed_post( false );
		$row  = DB_Table::instance()->insert(
			[
				'post_id'      => $post,
				'post_title'   => 'Title',
				'post_content' => 'Content',
			]
		);

		$requests = 0;
		add_filter(
			'pre_http_request',
			function ( $response ) use ( &$requests ) {
				++$requests;
				return $response;
			}
		);

		do_action( 'hyve_process_post', $row );

		$this->assertSame( 0, $requests );
		$this->assertSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( 'scheduled', DB_Table::instance()->get( $row )->post_status );
	}

	/**
	 * Starting the sync seeds the progress option and schedules the cron.
	 */
	public function test_start_sync_seeds_progress_and_schedules() {
		$this->enable_connect();
		$this->indexed_post( false );
		$this->indexed_post( false );

		DB_Table::instance()->connect_start_sync();

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertSame( 2, $status['total'] );
		$this->assertTrue( $status['in_progress'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * With nothing indexed, starting the sync is a no-op.
	 */
	public function test_start_sync_noop_when_nothing_pending() {
		$this->enable_connect();

		DB_Table::instance()->connect_start_sync();

		$this->assertSame( [], DB_Table::instance()->connect_sync_status() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Bulk enqueue durably marks the whole selection pending in one pass and
	 * kicks the sync, so a refresh mid-add cannot drop the waiting items.
	 */
	public function test_enqueue_marks_selection_pending_and_schedules() {
		$this->enable_connect();
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();

		$queued = DB_Table::instance()->connect_enqueue_posts( [ $a, $b ] );

		$this->assertSame( 2, $queued );
		$this->assertSame( '1', get_post_meta( $a, '_hyve_added', true ) );
		$this->assertSame( '1', get_post_meta( $b, '_hyve_added', true ) );
		$this->assertSame( 2, DB_Table::instance()->connect_pending_count() );
		$this->assertSame( 2, DB_Table::instance()->connect_sync_status()['total'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Enqueue skips a source already on the platform and clears the terminal
	 * markers a previous rejected/failed attempt left, so it re-enters the queue.
	 */
	public function test_enqueue_skips_synced_and_clears_stale_markers() {
		$this->enable_connect();
		$synced = $this->indexed_post( true );
		$failed = self::factory()->post->create();
		update_post_meta( $failed, '_hyve_moderation_failed', 1 );
		update_post_meta( $failed, '_hyve_processing_error', 'stale' );

		$queued = DB_Table::instance()->connect_enqueue_posts( [ $synced, $failed ] );

		$this->assertSame( 1, $queued );
		$this->assertSame( '', get_post_meta( $failed, '_hyve_moderation_failed', true ) );
		$this->assertSame( '', get_post_meta( $failed, '_hyve_processing_error', true ) );
		$this->assertContains( $failed, DB_Table::instance()->connect_pending_posts( -1 ) );
		$this->assertNotContains( $synced, DB_Table::instance()->connect_pending_posts( -1 ) );
	}

	/**
	 * Enqueuing into a sync already in flight folds the new work into its total
	 * without resetting the progress already made.
	 */
	public function test_enqueue_folds_into_running_sync() {
		$this->enable_connect();
		update_option(
			DB_Table::CONNECT_SYNC_OPTION,
			[
				'total'       => 5,
				'current'     => 3,
				'in_progress' => true,
				'blocked'     => false,
			]
		);
		$post = self::factory()->post->create();

		DB_Table::instance()->connect_enqueue_posts( [ $post ] );

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertSame( 3, $status['current'] );
		$this->assertSame( 4, $status['total'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * A run pushes the batch, marks each source synced, and finishes when drained.
	 */
	public function test_run_sync_syncs_batch_and_finishes() {
		$this->enable_connect();
		$a = $this->indexed_post( false );
		$b = $this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'     => $a,
									'status' => 'stored',
								],
								[
									'id'     => $b,
									'status' => 'stored',
								],
							],
							'kb'      => [ 'chunks' => 6 ],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$this->assertNotSame( '', get_post_meta( $a, '_hyve_connect_synced_hash', true ) );
		$this->assertNotSame( '', get_post_meta( $b, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertFalse( $status['in_progress'] );
		$this->assertSame( 2, $status['current'] );
	}

	/**
	 * A phone number or email that exists only as a `tel:`/`mailto:` target
	 * reaches the platform as text, and the document shipped to Connect is
	 * the same text self-hosted mode would store for the post.
	 */
	public function test_run_sync_ships_contact_link_targets_as_text() {
		$this->enable_connect();

		$post = self::factory()->post->create(
			[
				'post_content' => '<a href="tel:+441274998877">Call us</a> or <a href="mailto:hello@example.com">email the clinic</a>.',
			]
		);
		update_post_meta( $post, '_hyve_added', 1 );

		$shipped = [];

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$shipped, $post ) {
				// Only the Connect upsert: the tokenizer's vocabulary download
				// must still go through, and remembers a failure for the run.
				if ( false === strpos( (string) $url, '/start' ) ) {
					return $pre;
				}

				$shipped = json_decode( (string) $args['body'], true )['documents'] ?? [];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse(
						[
							[
								'job_complete',
								[
									'results' => [
										[
											'id'     => $post,
											'status' => 'stored',
										],
									],
								],
							],
						]
					),
				];
			},
			10,
			3
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$this->assertCount( 1, $shipped );
		$this->assertSame( 'Call us (+441274998877) or email the clinic (hello@example.com).', $shipped[0]['content'] );
		$this->assertSame( \ThemeIsle\HyveLite\Tokenizer::EXTRACTOR_VERSION, (int) get_post_meta( $post, '_hyve_connect_synced_extractor', true ) );

		$self_hosted = \ThemeIsle\HyveLite\Tokenizer::tokenize(
			[
				'ID'      => $post,
				'title'   => get_the_title( $post ),
				'content' => apply_filters( 'the_content', get_post( $post )->post_content ),
			]
		);

		$this->assertSame( $shipped[0]['content'], $self_hosted[0]['post_content'] );
	}

	/**
	 * A disconnect landing while the upsert is in flight must not let the sync
	 * job delete local rows or write synced markers on the now self-hosted site.
	 */
	public function test_run_sync_aborts_when_disconnected_mid_upsert() {
		$this->enable_connect();
		$post = $this->indexed_post( false );

		DB_Table::instance()->connect_start_sync();

		// The store returns "stored", but a disconnect flips the site to
		// self-hosted from inside the call, exactly as a racing disconnect would.
		$body = $this->sse(
			[
				[
					'job_complete',
					[
						'results' => [
							[
								'id'     => $post,
								'status' => 'stored',
							],
						],
						'kb'      => [ 'chunks' => 3 ],
					],
				],
			]
		);

		add_filter(
			'pre_http_request',
			function () use ( $body ) {
				update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_SELF ] );

				return [
					'response' => [ 'code' => 200 ],
					'body'     => $body,
				];
			},
			10,
			3
		);

		DB_Table::instance()->connect_run_sync();

		// The post-upsert mode re-check aborted before the mutation loop: no
		// synced hash was written (and no local rows were deleted), so the source
		// stays pending for a clean re-push if Connect is re-enabled.
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
	}

	/**
	 * The watchdog reschedules a sync whose run was lost: it still claims to be
	 * in progress, nothing is queued to drive it, and it has not advanced within
	 * the stall window.
	 */
	public function test_watchdog_reschedules_a_stranded_sync() {
		$this->enable_connect();
		update_option(
			DB_Table::CONNECT_SYNC_OPTION,
			[
				'in_progress' => true,
				'heartbeat'   => time() - ( DB_Table::CONNECT_SYNC_STALL + MINUTE_IN_SECONDS ),
			]
		);
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );

		DB_Table::instance()->connect_sync_watchdog();

		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * The watchdog leaves a sync that advanced recently alone (its batch is
	 * likely still executing), so it never double-drives a healthy run.
	 */
	public function test_watchdog_leaves_a_recently_active_sync_alone() {
		$this->enable_connect();
		update_option(
			DB_Table::CONNECT_SYNC_OPTION,
			[
				'in_progress' => true,
				'heartbeat'   => time(),
			]
		);
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );

		DB_Table::instance()->connect_sync_watchdog();

		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * A document the platform omits from its results is treated as failed, never
	 * assumed stored: no synced hash is written (so it is never silently believed
	 * to be on the platform) and the error is surfaced.
	 */
	public function test_run_sync_treats_a_missing_result_as_failed() {
		$this->enable_connect();
		$post = $this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [],
							'kb'      => [ 'chunks' => 0 ],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertNotSame( '', (string) get_post_meta( $post, '_hyve_processing_error', true ) );
	}

	/**
	 * A changed platform account (e.g. free -> paid activation) forgets the stale
	 * synced markers and restarts the sync so the whole KB re-lands on the new
	 * account rather than staying split across two.
	 */
	public function test_identity_change_resets_markers_and_restarts_sync() {
		$this->enable_connect();
		$post = $this->indexed_post( true );
		update_option( DB_Table::CONNECT_IDENTITY_OPTION, hash( 'sha256', 'free' ) );

		add_filter(
			'product_hyve_license_key',
			function () {
				return 'PAID-KEY';
			}
		);

		DB_Table::instance()->connect_check_identity();

		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
		$this->assertSame( hash( 'sha256', 'PAID-KEY' ), get_option( DB_Table::CONNECT_IDENTITY_OPTION ) );
	}

	/**
	 * The first identity observation only records it: a healthy, already-synced
	 * site is never disturbed just because the fingerprint had not been stored.
	 */
	public function test_identity_first_run_records_without_resync() {
		$this->enable_connect();
		$post = $this->indexed_post( true );
		delete_option( DB_Table::CONNECT_IDENTITY_OPTION );

		DB_Table::instance()->connect_check_identity();

		$this->assertNotSame( '', (string) get_option( DB_Table::CONNECT_IDENTITY_OPTION ) );
		$this->assertNotSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * An edit that is over quota clears its per-minute update flag (stopping the
	 * update_posts retry loop) and becomes pending for the background sync, whose
	 * block/resume path carries it once the quota frees.
	 */
	public function test_over_quota_edit_stops_retry_loop_and_defers_to_sync() {
		$this->enable_connect();
		$post = $this->indexed_post( true );
		update_post_meta( $post, '_hyve_needs_update', 1 );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'     => $post,
									'status' => 'skipped',
									'reason' => 'storage',
								],
							],
							'kb'      => [
								'storage' => [
									'limit' => 1000,
									'used'  => 1000,
								],
							],
						],
					],
				]
			)
		);

		$result = DB_Table::instance()->add_post( $post, 'update' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'quota_exceeded', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( $post, '_hyve_needs_update', true ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * A rejected source is marked handled (so the batch advances) and flagged.
	 */
	public function test_run_sync_records_rejection_and_advances() {
		$this->enable_connect();
		$post = $this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'         => $post,
									'status'     => 'rejected',
									'moderation' => [
										'flagged'    => true,
										'categories' => [ 'violence' ],
									],
								],
							],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		// Rejected content is flagged and leaves the pending set, but is not
		// marked synced (it never reached the platform).
		$this->assertSame( 1, (int) get_post_meta( $post, '_hyve_moderation_failed', true ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * A source with no extractable text is never sent: it gets a processing
	 * error (terminal, shown in the listing) while the rest of the batch syncs.
	 */
	public function test_run_sync_marks_empty_content_sources_failed() {
		$this->enable_connect();

		$empty = self::factory()->post->create( [ 'post_content' => '' ] );
		update_post_meta( $empty, '_hyve_added', 1 );
		$post = $this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'     => $post,
									'status' => 'stored',
								],
							],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$this->assertNotSame( '', get_post_meta( $empty, '_hyve_processing_error', true ) );
		$this->assertSame( '', get_post_meta( $empty, '_hyve_connect_synced_hash', true ) );
		$this->assertNotSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );
		$this->assertFalse( DB_Table::instance()->connect_sync_status()['in_progress'] );
	}

	/**
	 * A platform-side per-document failure is terminal: the source records the
	 * error, is not marked synced, and leaves the pending set.
	 */
	public function test_run_sync_records_platform_failure_and_advances() {
		$this->enable_connect();
		$post = $this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'     => $post,
									'status' => 'failed',
									'reason' => 'empty',
								],
							],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$this->assertNotSame( '', get_post_meta( $post, '_hyve_processing_error', true ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * Hitting the plan cap stops the job, records the block, and leaves the
	 * sources pending (retrying would not help until the user upgrades).
	 */
	public function test_run_sync_blocks_on_quota_exceeded() {
		$this->enable_connect();
		$this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'error',
						[
							'code'    => 'quota_exceeded',
							'message' => 'Over the free limit',
							'quota'   => [
								'kind'  => 'storage',
								'limit' => 100,
								'used'  => 100,
							],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['blocked'] );
		$this->assertFalse( $status['in_progress'] );
		// The block-time snapshot lets auto-resume tell "changed" from "still full".
		$this->assertSame( 100, $status['quota']['limit'] );
		$this->assertSame( 1, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * A site whose Connect mode comes from the settings defaults, with nothing
	 * stored, is still in Connect mode after the request: its errors are handled.
	 */
	public function test_run_sync_reads_the_live_mode_through_the_defaults() {
		delete_option( 'hyve_settings' );
		$defaults = static function ( $settings ) {
			$settings['ai_mode'] = Hyve_Connect::MODE_CONNECT;
			return $settings;
		};
		add_filter( 'hyve_default_settings', $defaults );
		$this->indexed_post( false );
		$this->intercept(
			$this->sse(
				[
					[
						'error',
						[
							'code'    => 'quota_exceeded',
							'message' => 'Over the free limit',
							'quota'   => [
								'kind'  => 'storage',
								'limit' => 100,
								'used'  => 100,
							],
						],
					],
				]
			)
		);

		try {
			DB_Table::instance()->connect_start_sync();
			DB_Table::instance()->connect_run_sync();

			$status = DB_Table::instance()->connect_sync_status();
			$this->assertTrue( $status['blocked'] );
			$this->assertSame( 'Over the free limit', $status['message'] );
		} finally {
			remove_filter( 'hyve_default_settings', $defaults );
		}
	}

	/**
	 * A source skipped for quota stays pending while the stored ones advance;
	 * the job keeps going as long as something fits.
	 */
	public function test_run_sync_keeps_skipped_sources_pending() {
		$this->enable_connect();
		$a = $this->indexed_post( false );
		$b = $this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'     => $a,
									'status' => 'stored',
								],
								[
									'id'     => $b,
									'status' => 'skipped',
									'reason' => 'storage',
								],
							],
							'kb'      => [
								'storage' => [
									'used'  => 999,
									'limit' => 1000,
								],
							],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$this->assertNotSame( '', get_post_meta( $a, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( '', get_post_meta( $b, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( 1, DB_Table::instance()->connect_pending_count() );
		$this->assertEmpty( DB_Table::instance()->connect_sync_status()['blocked'] );
	}

	/**
	 * A batch where nothing fits is terminal: block with the quota snapshot,
	 * exactly like a refused batch.
	 */
	public function test_run_sync_blocks_when_nothing_fits() {
		$this->enable_connect();
		$post = $this->indexed_post( false );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'results' => [
								[
									'id'     => $post,
									'status' => 'skipped',
									'reason' => 'storage',
								],
							],
							'kb'      => [
								'storage' => [
									'used'  => 1000,
									'limit' => 1000,
								],
							],
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_sync();
		DB_Table::instance()->connect_run_sync();

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['blocked'] );
		$this->assertSame( 'storage', $status['quota']['kind'] );
		$this->assertSame( 1000, $status['quota']['limit'] );
		$this->assertSame( 1, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * When the platform has purged our content, recovery clears the stale synced
	 * markers and restarts the sync from the posts we still hold.
	 */
	public function test_recovery_restarts_sync_when_platform_empty() {
		$this->enable_connect();
		$post = $this->indexed_post( true );

		// The aggregate the recovery check reads reports an empty hosted KB.
		$this->intercept(
			wp_json_encode(
				[
					'plan'    => 'free',
					'service' => 'ok',
					'kb'      => [ 'state' => 'empty' ],
				]
			)
		);

		DB_Table::instance()->connect_check_recovery();

		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['in_progress'] );
		$this->assertSame( 1, $status['total'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * A healthy hosted KB is left alone: no re-sync, no stale-marker churn.
	 */
	public function test_recovery_noop_when_platform_has_content() {
		$this->enable_connect();
		$post = $this->indexed_post( true );

		$this->intercept(
			wp_json_encode(
				[
					'plan'    => 'free',
					'service' => 'ok',
					'kb'      => [ 'state' => 'ok' ],
				]
			)
		);

		DB_Table::instance()->connect_check_recovery();

		$this->assertNotSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( [], DB_Table::instance()->connect_sync_status() );
	}

	/**
	 * Regression: a direct ingest (e.g. a sitemap import) grows the KB without the
	 * sync bookkeeping, so a stale "empty" aggregate can linger in the cache.
	 * Recovery must read fresh state, not that cache, or it wrongly resets the
	 * markers and re-syncs everything already on the platform.
	 */
	public function test_recovery_ignores_stale_empty_cache() {
		$this->enable_connect();
		$post = $this->indexed_post( true );

		// The cache still holds the pre-ingest snapshot: an empty hosted KB.
		set_transient(
			'hyve_connect_stats',
			[
				'plan'    => 'free',
				'service' => 'ok',
				'kb'      => [ 'state' => 'empty' ],
			],
			5 * MINUTE_IN_SECONDS
		);

		// The platform, freshly queried, reports the content is actually there.
		$this->intercept(
			wp_json_encode(
				[
					'plan'    => 'free',
					'service' => 'ok',
					'kb'      => [ 'state' => 'ok' ],
				]
			)
		);

		DB_Table::instance()->connect_check_recovery();

		$this->assertNotSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( [], DB_Table::instance()->connect_sync_status() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Intercept HTTP requests and return each canned body in turn (last repeats).
	 *
	 * @param array<string> $bodies Ordered response bodies.
	 *
	 * @return void
	 */
	private function intercept_sequence( $bodies ) {
		$i = 0;

		add_filter(
			'pre_http_request',
			function () use ( &$i, $bodies ) {
				$body = $bodies[ min( $i, count( $bodies ) - 1 ) ];
				$i++;

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
	 * The root aggregate matching stops reconcile before any manifest is sent.
	 */
	public function test_reconcile_stops_when_root_in_sync() {
		$this->enable_connect();
		$this->indexed_post( true );

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'in_sync'   => true,
							'aggregate' => 'x',
						],
					],
				]
			)
		);

		$this->assertTrue( DB_Table::instance()->connect_reconcile() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * The drill-down (root -> buckets -> scoped detail) re-pushes a stale source:
	 * it is unmarked so the sync job re-sends it.
	 */
	public function test_reconcile_drilldown_repushes_stale_source() {
		$this->enable_connect();

		$post = self::factory()->post->create( [ 'post_content' => 'New content' ] );
		update_post_meta( $post, '_hyve_added', 1 );
		update_post_meta( $post, '_hyve_connect_synced_hash', 'HASH' );

		$bucket = Hyve_Connect::kb_bucket_of( $post );

		$this->intercept_sequence(
			[
				$this->sse(
					[
						[
							'job_complete',
							[
								'in_sync'   => false,
								'aggregate' => 'x',
							],
						],
					]
				),
				$this->sse(
					[
						[
							'job_complete',
							[
								'in_sync'           => false,
								'differing_buckets' => [ $bucket ],
							],
						],
					]
				),
				$this->sse(
					[
						[
							'job_complete',
							[
								'deleted' => [],
								'stale'   => [ (string) $post ],
								'missing' => [],
							],
						],
					]
				),
			]
		);

		$this->assertTrue( DB_Table::instance()->connect_reconcile() );

		// Unmarked for re-push, and the sync job is scheduled to send it.
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Build an indexed source post with plain content and optional meta.
	 *
	 * @param string               $content Post content (tag-free, so its hash is stable).
	 * @param array<string, mixed> $meta    Extra post meta to set.
	 *
	 * @return int
	 */
	private function source_post( $content, $meta = [] ) {
		// hyve_docs is registered exclude_from_search, so this also guards the
		// manifest query against the post_type "any" pitfall.
		$post_id = self::factory()->post->create(
			[
				'post_content' => $content,
				'post_type'    => 'hyve_docs',
			]
		);
		update_post_meta( $post_id, '_hyve_added', 1 );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Reduce the manifest to an id => hash map for assertions.
	 *
	 * @return array<int, string>
	 */
	private function manifest_map() {
		$map = [];

		foreach ( DB_Table::instance()->connect_local_manifest() as $entry ) {
			$map[ (int) $entry['id'] ] = $entry['hash'];
		}

		return $map;
	}

	/**
	 * A never-synced indexed source is listed at its current content hash.
	 */
	public function test_manifest_lists_unsynced_source_at_current_hash() {
		$post = $this->source_post( 'Alpha content' );

		$this->assertSame(
			hash( 'sha256', 'Alpha content' ),
			$this->manifest_map()[ $post ]
		);
	}

	/**
	 * When the post is unchanged since sync, the cached hash is reused verbatim
	 * (a sentinel that differs from the real content hash proves no recompute).
	 */
	public function test_manifest_reuses_cached_hash_when_unchanged() {
		$post = $this->source_post(
			'Alpha content',
			[
				'_hyve_connect_synced_hash'      => 'SENTINEL',
				'_hyve_connect_synced_extractor' => \ThemeIsle\HyveLite\Tokenizer::EXTRACTOR_VERSION,
			]
		);
		update_post_meta( $post, '_hyve_connect_synced_modified', (int) get_post_modified_time( 'U', true, $post ) );

		$this->assertSame( 'SENTINEL', $this->manifest_map()[ $post ] );
	}

	/**
	 * A post synced before the extractor changed is rehashed even though it
	 * was not edited: its stored hash is of text the extractor no longer
	 * produces, so trusting it would leave the old text on the platform.
	 */
	public function test_manifest_rehashes_a_post_synced_by_an_older_extractor() {
		$post = $this->source_post(
			'<a href="tel:+441274998877">Call us</a>',
			[
				'_hyve_connect_synced_hash' => hash( 'sha256', 'Call us' ),
			]
		);
		update_post_meta( $post, '_hyve_connect_synced_modified', (int) get_post_modified_time( 'U', true, $post ) );

		$this->assertSame( hash( 'sha256', 'Call us (+441274998877)' ), $this->manifest_map()[ $post ] );
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_extractor', true ) );
	}

	/**
	 * When the new extractor produces the same text, the post adopts the
	 * current extractor version so later reconciles reuse its hash again.
	 */
	public function test_manifest_adopts_the_extractor_when_the_text_is_unchanged() {
		$post = $this->source_post(
			'Alpha content',
			[
				'_hyve_connect_synced_hash' => hash( 'sha256', 'Alpha content' ),
			]
		);
		update_post_meta( $post, '_hyve_connect_synced_modified', (int) get_post_modified_time( 'U', true, $post ) );

		$this->assertSame( hash( 'sha256', 'Alpha content' ), $this->manifest_map()[ $post ] );
		$this->assertSame( \ThemeIsle\HyveLite\Tokenizer::EXTRACTOR_VERSION, (int) get_post_meta( $post, '_hyve_connect_synced_extractor', true ) );

		// Now trusted: the stored hash is reused without a recompute.
		update_post_meta( $post, '_hyve_connect_synced_hash', 'SENTINEL' );

		$this->assertSame( 'SENTINEL', $this->manifest_map()[ $post ] );
	}

	/**
	 * A modified-time mismatch forces a recompute of the real content hash.
	 */
	public function test_manifest_recomputes_when_modified_changed() {
		$post = $this->source_post(
			'Alpha content',
			[
				'_hyve_connect_synced_hash'     => 'SENTINEL',
				'_hyve_connect_synced_modified' => 1, // Stale timestamp.
			]
		);

		$this->assertSame(
			hash( 'sha256', 'Alpha content' ),
			$this->manifest_map()[ $post ]
		);
	}

	/**
	 * A moderation-failed source that was previously synced stays in the manifest
	 * at its last-synced hash (its kept cloud copy must not be orphaned).
	 */
	public function test_manifest_keeps_flagged_previously_synced() {
		$post = $this->source_post(
			'Rejected new content',
			[
				'_hyve_moderation_failed'   => 1,
				'_hyve_connect_synced_hash' => 'SENTINEL',
			]
		);

		$this->assertSame( 'SENTINEL', $this->manifest_map()[ $post ] );
	}

	/**
	 * A moderation-failed source that was never synced is left out entirely.
	 */
	public function test_manifest_excludes_flagged_never_synced() {
		$post = $this->source_post( 'Bad content', [ '_hyve_moderation_failed' => 1 ] );

		$this->assertArrayNotHasKey( $post, $this->manifest_map() );
	}

	/**
	 * An in-flight sync refuses a concurrent reconcile.
	 */
	public function test_reconcile_refuses_while_sync_in_flight() {
		$this->enable_connect();
		update_option( DB_Table::CONNECT_SYNC_OPTION, [ 'in_progress' => true ] );

		$result = DB_Table::instance()->connect_reconcile();

		$this->assertWPError( $result );
		$this->assertSame( 'connect_busy', $result->get_error_code() );
	}

	/**
	 * A manual Sync retries a plan-blocked job (cheap under greedy admission:
	 * it stores whatever fits or just re-blocks with fresh numbers).
	 */
	public function test_reconcile_retries_a_blocked_sync() {
		$this->enable_connect();
		update_option(
			DB_Table::CONNECT_SYNC_OPTION,
			[
				'in_progress' => false,
				'blocked'     => true,
				'message'     => 'Over the limit',
			]
		);

		$this->intercept(
			$this->sse(
				[
					[
						'job_complete',
						[
							'in_sync'   => true,
							'aggregate' => 'x',
						],
					],
				]
			)
		);

		$this->assertTrue( DB_Table::instance()->connect_reconcile() );
		$this->assertSame( [], DB_Table::instance()->connect_sync_status() );
	}

	/**
	 * A reconcile that fails its network round must leave the blocked state (and
	 * its banner) intact, so the job is not silently stranded with no way back.
	 */
	public function test_reconcile_keeps_block_when_network_fails() {
		$this->enable_connect();
		update_option(
			DB_Table::CONNECT_SYNC_OPTION,
			[
				'in_progress' => false,
				'blocked'     => true,
				'message'     => 'Over the limit',
			]
		);

		// The store is unreachable, so the reconcile round errors out.
		$this->intercept( '', 500 );

		$result = DB_Table::instance()->connect_reconcile();

		$this->assertWPError( $result );

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['blocked'] );
	}

	/**
	 * Recovery still repairs an empty account while a block is recorded, e.g.
	 * right after an upgrade points the site at a fresh paid account.
	 */
	public function test_recovery_restarts_even_when_blocked() {
		$this->enable_connect();
		$post = $this->indexed_post( true );
		update_option(
			DB_Table::CONNECT_SYNC_OPTION,
			[
				'in_progress' => false,
				'blocked'     => true,
			]
		);

		$this->intercept(
			wp_json_encode(
				[
					'plan'    => 'paid',
					'service' => 'ok',
					'kb'      => [ 'state' => 'empty' ],
				]
			)
		);

		DB_Table::instance()->connect_check_recovery();

		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced_hash', true ) );
		$this->assertTrue( DB_Table::instance()->connect_sync_status()['in_progress'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Seed a blocked sync plus a cached stats payload.
	 *
	 * @param int   $used     Storage chunks used.
	 * @param int   $limit    Storage chunk limit.
	 * @param array $windows  Indexing windows ({remaining} each).
	 * @param array $snapshot Quota snapshot recorded at block time.
	 *
	 * @return void
	 */
	private function seed_blocked_with_stats( $used, $limit, $windows = [], $snapshot = [] ) {
		update_option(
			DB_Table::CONNECT_SYNC_OPTION,
			[
				'in_progress' => false,
				'blocked'     => true,
				'message'     => 'Over the limit',
				'quota'       => $snapshot,
			]
		);

		set_transient(
			'hyve_connect_stats',
			[
				'kb'       => [
					'storage' => [
						'used'  => $used,
						'limit' => $limit,
					],
				],
				'indexing' => [ 'windows' => $windows ],
			],
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * A blocked sync resumes by itself once cached stats show headroom.
	 */
	public function test_blocked_sync_resumes_when_stats_show_headroom() {
		$this->enable_connect();
		$this->indexed_post( false );
		$this->seed_blocked_with_stats(
			100,
			1000,
			[
				'24h' => [ 'remaining' => 500 ],
				'30d' => [ 'remaining' => 500 ],
			]
		);

		DB_Table::instance()->connect_maybe_resume_blocked();

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['in_progress'] );
		$this->assertEmpty( $status['blocked'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * No storage headroom -> the block stays.
	 */
	public function test_blocked_sync_stays_blocked_without_storage_headroom() {
		$this->enable_connect();
		$this->indexed_post( false );
		$this->seed_blocked_with_stats( 1000, 1000 );

		DB_Table::instance()->connect_maybe_resume_blocked();

		$this->assertTrue( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * Leftover room alone does not resume: with the numbers unchanged since
	 * the block, the knowledge base simply does not fit, and resuming would
	 * retry-loop against the cap.
	 */
	public function test_blocked_sync_stays_blocked_when_numbers_unchanged() {
		$this->enable_connect();
		$this->indexed_post( false );
		$this->seed_blocked_with_stats(
			987,
			1000,
			[
				'24h' => [ 'remaining' => 500 ],
				'30d' => [ 'remaining' => 500 ],
			],
			[
				'kind'  => 'storage',
				'limit' => 1000,
				'used'  => 987,
			]
		);

		DB_Table::instance()->connect_maybe_resume_blocked();

		$this->assertTrue( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * A raised cap since the block (upgrade, server-side change) resumes.
	 */
	public function test_blocked_sync_resumes_when_limit_grew_since_block() {
		$this->enable_connect();
		$this->indexed_post( false );
		$this->seed_blocked_with_stats(
			987,
			10000,
			[
				'24h' => [ 'remaining' => 500 ],
				'30d' => [ 'remaining' => 500 ],
			],
			[
				'kind'  => 'storage',
				'limit' => 1000,
				'used'  => 987,
			]
		);

		DB_Table::instance()->connect_maybe_resume_blocked();

		$status = DB_Table::instance()->connect_sync_status();
		$this->assertTrue( $status['in_progress'] );
		$this->assertEmpty( $status['blocked'] );
	}

	/**
	 * Storage headroom but an exhausted indexing window -> the block stays
	 * (resuming would immediately re-block against the churn cap).
	 */
	public function test_blocked_sync_stays_blocked_when_indexing_window_exhausted() {
		$this->enable_connect();
		$this->indexed_post( false );
		$this->seed_blocked_with_stats(
			100,
			1000,
			[
				'24h' => [ 'remaining' => 500 ],
				'30d' => [ 'remaining' => 0 ],
			]
		);

		DB_Table::instance()->connect_maybe_resume_blocked();

		$this->assertTrue( DB_Table::instance()->connect_sync_status()['blocked'] );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * An import-mode disconnect unmarks sources that never reached the
	 * platform: nothing was exported for them, so keeping the KB markers
	 * would list them as indexed with no local chunks behind them.
	 */
	public function test_disconnect_import_drops_never_synced_sources() {
		$this->enable_connect();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$synced   = $this->indexed_post( true );
		$unsynced = $this->indexed_post( false );

		$docs = self::factory()->post->create( [ 'post_type' => 'hyve_docs' ] );
		update_post_meta( $docs, '_hyve_added', 1 );

		// Export returns only the synced post's chunk; delete succeeds.
		add_filter(
			'pre_http_request',
			function ( $response, $args ) use ( $synced ) {
				$payload = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );

				$data = 'export' === ( $payload['action'] ?? '' )
					? [
						'items'       => [
							[
								'id'          => $synced,
								'content'     => 'Synced chunk',
								'token_count' => 3,
								'embedding'   => [ 0.1, 0.2 ],
							],
						],
						'next_cursor' => null,
					]
					: [ 'deleted' => true ];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse( [ [ 'job_complete', $data ] ] ),
				];
			},
			10,
			2
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/connect' );
		$request->set_query_params( [ 'mode' => 'import' ] );
		$response = rest_do_request( $request );

		$this->assertTrue( $response->get_data() );
		$this->assertSame( '1', get_post_meta( $synced, '_hyve_added', true ) );
		$this->assertSame( '', get_post_meta( $synced, '_hyve_connect_synced_hash', true ) );
		$this->assertSame( '', get_post_meta( $unsynced, '_hyve_added', true ) );
		$this->assertNull( get_post( $docs ) );
	}

	/**
	 * Import re-enters the local engine, where the local chunk limit applies:
	 * content over it is pruned (oldest first), mirroring Qdrant deactivation.
	 */
	public function test_disconnect_import_prunes_over_local_limit() {
		$this->enable_connect();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$posts = [
			$this->indexed_post( true ),
			$this->indexed_post( true ),
			$this->indexed_post( true ),
		];

		add_filter(
			'hyve_chunks_limit',
			function () {
				return 2;
			}
		);

		add_filter(
			'pre_http_request',
			function ( $response, $args ) use ( $posts ) {
				$payload = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );

				$data = 'export' === ( $payload['action'] ?? '' )
					? [
						'items'       => array_map(
							function ( $post_id ) {
								return [
									'id'          => $post_id,
									'content'     => 'Chunk for ' . $post_id,
									'token_count' => 3,
									'embedding'   => [ 0.1 ],
								];
							},
							$posts
						),
						'next_cursor' => null,
					]
					: [ 'deleted' => true ];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse( [ [ 'job_complete', $data ] ] ),
				];
			},
			10,
			2
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/connect' );
		$request->set_query_params( [ 'mode' => 'import' ] );
		rest_do_request( $request );

		// Two newest chunks fit the limit; the oldest post's cleanup is scheduled.
		$this->assertNotFalse( wp_next_scheduled( 'hyve_delete_posts', [ [ (string) $posts[0] ] ] ) );
	}

	/**
	 * Disconnect-clear removes the hosted copy with ONE platform call; the
	 * per-post local cleanup must not fire a platform delete for each source
	 * (a thousand-source KB would time out).
	 */
	public function test_disconnect_clear_deletes_hosted_copy_once() {
		$this->enable_connect();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$docs = [];

		for ( $i = 0; $i < 3; $i++ ) {
			$doc    = self::factory()->post->create( [ 'post_type' => 'hyve_docs' ] );
			$docs[] = $doc;
			update_post_meta( $doc, '_hyve_added', 1 );
			update_post_meta( $doc, '_hyve_connect_synced_hash', 'HASH' );
		}

		$requests = 0;

		add_filter(
			'pre_http_request',
			function () use ( &$requests ) {
				++$requests;

				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->sse(
						[
							[
								'job_complete',
								[
									'deleted' => [],
									'all'     => true,
								],
							],
						]
					),
				];
			}
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/connect' );
		$request->set_query_params( [ 'mode' => 'clear' ] );
		$response = rest_do_request( $request );

		$this->assertTrue( $response->get_data() );
		$this->assertSame( 1, $requests );

		foreach ( $docs as $doc ) {
			$this->assertNull( get_post( $doc ) );
		}
	}

	/**
	 * With no local sources, the bucketed reconcile can never detect cloud
	 * orphans (empty manifest -> no bucket hashes -> nothing differs). Reconcile
	 * must instead wipe the hosted copy outright, or the orphans are stranded.
	 */
	public function test_reconcile_wipes_hosted_copy_when_local_kb_is_empty() {
		$this->enable_connect();
		// No `_hyve_added` posts exist, so the local manifest is empty.

		$deleted_all = false;

		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$deleted_all ) {
				$payload = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );
				$action  = is_array( $payload ) ? ( $payload['action'] ?? '' ) : '';

				if ( 'delete' === $action && ! empty( $payload['all'] ) ) {
					$deleted_all = true;
					$body        = $this->sse(
						[
							[
								'job_complete',
								[
									'deleted' => [],
									'all'     => true,
								],
							],
						]
					);
				} else {
					// Root reconcile: the cloud still holds content local does not.
					$body = $this->sse(
						[
							[
								'job_complete',
								[
									'in_sync'   => false,
									'aggregate' => 'CLOUD',
								],
							],
						]
					);
				}

				return [
					'response' => [ 'code' => 200 ],
					'body'     => $body,
				];
			},
			10,
			3
		);

		$result = DB_Table::instance()->connect_reconcile();

		$this->assertTrue( $result );
		$this->assertTrue( $deleted_all, 'An empty local KB must delete the whole hosted copy.' );
	}

	/**
	 * A hosted delete that fails at delete time (server unreachable) must not be
	 * dropped: it is queued and a later cron pass finishes it once the service is
	 * back, so a deleted source never lingers as an orphan.
	 */
	public function test_failed_source_delete_is_queued_then_retried() {
		$this->enable_connect();

		// Delete-time call fails at the transport layer.
		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'down' );
			}
		);

		DB_Table::instance()->connect_delete_source( [ 4242 ] );

		$this->assertSame( [ 4242 ], get_option( DB_Table::CONNECT_DELETE_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_DELETE_HOOK ) );

		// Service back: the retry pass clears the queue.
		remove_all_filters( 'pre_http_request' );
		$this->intercept( $this->sse( [ [ 'job_complete', [ 'deleted' => [ 4242 ] ] ] ] ) );

		DB_Table::instance()->connect_run_deletes();

		$this->assertFalse( get_option( DB_Table::CONNECT_DELETE_OPTION ) );
	}
}
