<?php
/**
 * Tests for the to-Connect content migration and purge auto-recovery.
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
 * Class ConnectMigrationTest
 */
class ConnectMigrationTest extends WP_UnitTestCase {

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
		delete_option( 'hyve_settings' );
		delete_option( DB_Table::CONNECT_SYNC_OPTION );
		delete_transient( 'hyve_connect_stats' );
		delete_transient( 'hyve_connect_recovery_check' );
		wp_clear_scheduled_hook( DB_Table::CONNECT_SYNC_HOOK );
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
	 * @param bool $synced Whether to mark it `_hyve_connect_synced`.
	 *
	 * @return int
	 */
	private function indexed_post( $synced = false ) {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_hyve_added', 1 );

		if ( $synced ) {
			update_post_meta( $post_id, '_hyve_connect_synced', 1 );
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
	 * Starting the migration seeds the progress option and schedules the cron.
	 */
	public function test_start_migration_seeds_progress_and_schedules() {
		$this->enable_connect();
		$this->indexed_post( false );
		$this->indexed_post( false );

		DB_Table::instance()->connect_start_migration();

		$status = DB_Table::instance()->connect_migration_status();
		$this->assertSame( 2, $status['total'] );
		$this->assertTrue( $status['in_progress'] );
		$this->assertNotFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * With nothing indexed, starting the migration is a no-op.
	 */
	public function test_start_migration_noop_when_nothing_pending() {
		$this->enable_connect();

		DB_Table::instance()->connect_start_migration();

		$this->assertSame( [], DB_Table::instance()->connect_migration_status() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}

	/**
	 * A run pushes the batch, marks each source synced, and finishes when drained.
	 */
	public function test_migrate_data_syncs_batch_and_finishes() {
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

		DB_Table::instance()->connect_start_migration();
		DB_Table::instance()->connect_migrate_data();

		$this->assertSame( 1, (int) get_post_meta( $a, '_hyve_connect_synced', true ) );
		$this->assertSame( 1, (int) get_post_meta( $b, '_hyve_connect_synced', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );

		$status = DB_Table::instance()->connect_migration_status();
		$this->assertFalse( $status['in_progress'] );
		$this->assertSame( 2, $status['current'] );
	}

	/**
	 * A rejected source is marked handled (so the batch advances) and flagged.
	 */
	public function test_migrate_data_records_rejection_and_advances() {
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

		DB_Table::instance()->connect_start_migration();
		DB_Table::instance()->connect_migrate_data();

		// Rejected content is flagged and leaves the pending set, but is not
		// marked synced (it never reached the platform).
		$this->assertSame( 1, (int) get_post_meta( $post, '_hyve_moderation_failed', true ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced', true ) );
		$this->assertSame( 0, DB_Table::instance()->connect_pending_count() );
	}

	/**
	 * Hitting the plan cap stops the job, records the block, and leaves the
	 * sources pending (retrying would not help until the user upgrades).
	 */
	public function test_migrate_data_blocks_on_quota_exceeded() {
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
						],
					],
				]
			)
		);

		DB_Table::instance()->connect_start_migration();
		DB_Table::instance()->connect_migrate_data();

		$status = DB_Table::instance()->connect_migration_status();
		$this->assertTrue( $status['blocked'] );
		$this->assertFalse( $status['in_progress'] );
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

		$this->assertSame( '', get_post_meta( $post, '_hyve_connect_synced', true ) );

		$status = DB_Table::instance()->connect_migration_status();
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

		$this->assertSame( 1, (int) get_post_meta( $post, '_hyve_connect_synced', true ) );
		$this->assertSame( [], DB_Table::instance()->connect_migration_status() );
	}

	/**
	 * Regression: a direct ingest (e.g. a sitemap import) grows the KB without the
	 * migration bookkeeping, so a stale "empty" aggregate can linger in the cache.
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

		$this->assertSame( 1, (int) get_post_meta( $post, '_hyve_connect_synced', true ) );
		$this->assertSame( [], DB_Table::instance()->connect_migration_status() );
		$this->assertFalse( wp_next_scheduled( DB_Table::CONNECT_SYNC_HOOK ) );
	}
}
