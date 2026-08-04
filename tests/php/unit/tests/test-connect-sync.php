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
				'_hyve_connect_synced_hash' => 'SENTINEL',
			]
		);
		update_post_meta( $post, '_hyve_connect_synced_modified', (int) get_post_modified_time( 'U', true, $post ) );

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
					$body        = $this->sse( [ [ 'job_complete', [ 'deleted' => [], 'all' => true ] ] ] );
				} else {
					// Root reconcile: the cloud still holds content local does not.
					$body = $this->sse( [ [ 'job_complete', [ 'in_sync' => false, 'aggregate' => 'CLOUD' ] ] ] );
				}

				return [ 'response' => [ 'code' => 200 ], 'body' => $body ];
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
