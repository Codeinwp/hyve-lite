<?php
/**
 * Tests for the Qdrant migration and the retrieval path while it runs.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Qdrant_API;

/**
 * Class QdrantMigrationTest
 */
class QdrantMigrationTest extends WP_UnitTestCase {

	/**
	 * The stub installed as the Qdrant_API singleton: add_points() and
	 * search() return the canned `add_points_result` / `search_result` and
	 * every batch handed to add_points() is kept in `batches`.
	 *
	 * @var Qdrant_API
	 */
	protected $qdrant;

	/**
	 * Chunk table.
	 *
	 * @var DB_Table
	 */
	protected $table;

	/**
	 * Set up: empty table, active Qdrant, stubbed client.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		// Constructing recreates the table the bootstrap dropped.
		$this->table = new DB_Table();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}hyve" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->table->delete_by_post_id( 0 );

		$this->qdrant = new class() extends Qdrant_API {
			/**
			 * What add_points() returns.
			 *
			 * @var bool|WP_Error
			 */
			public $add_points_result = true;

			/**
			 * What search() returns.
			 *
			 * @var array<int, array<string, mixed>>|WP_Error
			 */
			public $search_result = [];

			/**
			 * Batches passed to add_points().
			 *
			 * @var array<int, array<int, array<string, mixed>>>
			 */
			public $batches = [];

			/**
			 * Skip the parent constructor: no settings, no client.
			 */
			public function __construct() {}

			/**
			 * Record the batch and return the canned result.
			 *
			 * @param array<array<string, mixed>> $points Points.
			 *
			 * @return bool|WP_Error
			 */
			public function add_points( $points ) {
				$this->batches[] = $points;

				if ( is_wp_error( $this->add_points_result ) && 404 === $this->add_points_result->get_error_data() ) {
					// Mirror handle_exception() for a terminal error.
					update_option( 'hyve_qdrant_status', 'inactive' );
				}

				return $this->add_points_result;
			}

			/**
			 * Return the canned search result.
			 *
			 * @param array<float> $embeddings      Embeddings.
			 * @param float        $score_threshold Threshold.
			 *
			 * @return array<int, array<string, mixed>>|WP_Error
			 */
			public function search( $embeddings, $score_threshold ) {
				return $this->search_result;
			}
		};
		$this->set_singleton( $this->qdrant );

		update_option( 'hyve_qdrant_status', 'active' );
		update_option(
			'hyve_qdrant_migration',
			[
				'total'       => 0,
				'current'     => 0,
				'in_progress' => true,
			]
		);
		delete_option( Qdrant_API::ERROR_OPTION_KEY );
		delete_transient( Qdrant_API::MIGRATE_LOCK );
		wp_clear_scheduled_hook( Qdrant_API::MIGRATE_HOOK );
	}

	/**
	 * Tear down.
	 */
	protected function tearDown(): void {
		$this->set_singleton( null );
		delete_option( 'hyve_qdrant_status' );
		delete_option( 'hyve_qdrant_migration' );
		delete_option( Qdrant_API::ERROR_OPTION_KEY );
		delete_transient( Qdrant_API::MIGRATE_LOCK );
		wp_clear_scheduled_hook( Qdrant_API::MIGRATE_HOOK );
		parent::tearDown();
	}

	/**
	 * Replace the Qdrant_API singleton.
	 *
	 * @param Qdrant_API|null $instance The instance.
	 */
	private function set_singleton( $instance ) {
		$property = new ReflectionProperty( Qdrant_API::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $instance );
	}

	/**
	 * Insert a chunk row.
	 *
	 * @param string            $title      Title.
	 * @param array<int, float> $embeddings Vector.
	 * @param string            $status     Row status.
	 * @param string            $storage    Storage backend.
	 *
	 * @return int Row ID.
	 */
	private function seed( $title, $embeddings = [ 1.0, 0.0, 0.0 ], $status = 'processed', $storage = 'WordPress' ) {
		return $this->table->insert(
			[
				'post_id'      => 1000 + wp_rand( 1, 999 ),
				'post_title'   => $title,
				'post_content' => $title . ' content.',
				'embeddings'   => wp_json_encode( $embeddings ),
				'token_count'  => 100,
				'post_status'  => $status,
				'storage'      => $storage,
			]
		);
	}

	/**
	 * Storage of a row.
	 *
	 * @param int $id Row ID.
	 *
	 * @return string
	 */
	private function storage_of( $id ) {
		return $this->table->get( $id )->storage;
	}

	/**
	 * A successful batch moves every processed chunk, leaves rows that have
	 * nothing to upload yet alone, and ends the migration.
	 */
	public function test_successful_batch_marks_rows_and_finishes() {
		$a         = $this->seed( 'A' );
		$b         = $this->seed( 'B' );
		$scheduled = $this->seed( 'Pending', [], 'scheduled' );

		$this->qdrant->migrate_data();

		$this->assertCount( 1, $this->qdrant->batches );
		$this->assertCount( 2, $this->qdrant->batches[0] );
		$this->assertEquals( 'Qdrant', $this->storage_of( $a ) );
		$this->assertEquals( 'Qdrant', $this->storage_of( $b ) );
		$this->assertEquals( 'WordPress', $this->storage_of( $scheduled ) );
		$this->assertFalse( Qdrant_API::has_pending_migration() );
		$this->assertFalse( Qdrant_API::migration_status()['in_progress'] );
		$this->assertFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
	}

	/**
	 * Point ids derive from the row, so a batch retried after Qdrant stored
	 * it but the response was lost overwrites instead of duplicating.
	 */
	public function test_batch_uses_stable_point_ids() {
		$a = $this->seed( 'A' );

		$this->qdrant->add_points_result = new WP_Error( 'collection_error', 'Timed out' );
		$this->qdrant->migrate_data();
		$this->qdrant->migrate_data();

		$this->assertCount( 2, $this->qdrant->batches );
		$this->assertSame( $this->qdrant->batches[0][0]['id'], $this->qdrant->batches[1][0]['id'] );
		$this->assertSame( Qdrant_API::point_id( 'row-' . $a ), $this->qdrant->batches[0][0]['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->qdrant->batches[0][0]['id'] );
	}

	/**
	 * While a batch is uploading, a second cron run must not upload the same
	 * rows, and reading the status must not schedule one.
	 */
	public function test_lock_blocks_parallel_runs_and_rearming() {
		$this->seed( 'A' );
		set_transient( Qdrant_API::MIGRATE_LOCK, time(), MINUTE_IN_SECONDS );

		$this->qdrant->migrate_data();
		$this->assertCount( 0, $this->qdrant->batches );

		Qdrant_API::migration_status();
		Qdrant_API::has_pending_migration();
		$this->assertFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );

		// The lock is released after a run, even one that failed.
		delete_transient( Qdrant_API::MIGRATE_LOCK );
		$this->qdrant->add_points_result = new WP_Error( 'collection_error', 'Server error', 500 );
		$this->qdrant->migrate_data();
		$this->assertFalse( get_transient( Qdrant_API::MIGRATE_LOCK ) );
	}

	/**
	 * A pending migration with no cron event is re-armed from the chat path,
	 * so a site with blocked WP-Cron recovers without an admin visit.
	 */
	public function test_chat_path_rearms_lost_migration() {
		$this->seed( 'A' );

		$this->assertFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
		$this->assertTrue( Qdrant_API::has_pending_migration() );
		$this->assertNotFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
	}

	/**
	 * A transient upload error keeps the chunks local, keeps the migration
	 * open and schedules another pass instead of silently stopping.
	 */
	public function test_transient_error_keeps_rows_local_and_retries() {
		$a = $this->seed( 'A' );

		$this->qdrant->add_points_result = new WP_Error( 'collection_error', 'Server error', 500 );
		$this->qdrant->migrate_data();

		$this->assertEquals( 'WordPress', $this->storage_of( $a ) );
		$this->assertTrue( Qdrant_API::has_pending_migration() );
		$this->assertTrue( Qdrant_API::migration_status()['in_progress'] );

		$next = wp_next_scheduled( Qdrant_API::MIGRATE_HOOK );
		$this->assertNotFalse( $next );
		$this->assertGreaterThanOrEqual( time() + Qdrant_API::MIGRATE_RETRY_DELAY - 5, $next );
	}

	/**
	 * Qdrant answering without a completed status is a failure too, not a
	 * migrated batch.
	 */
	public function test_unconfirmed_upload_is_not_treated_as_migrated() {
		$a = $this->seed( 'A' );

		$this->qdrant->add_points_result = false;
		$this->qdrant->migrate_data();

		$this->assertEquals( 'WordPress', $this->storage_of( $a ) );
		$this->assertNotFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );

		$status = Qdrant_API::migration_status();
		$this->assertTrue( $status['in_progress'] );
		$this->assertNotEmpty( $status['error'] );
	}

	/**
	 * A terminal error has deactivated Qdrant; the migration ends and nothing
	 * is rescheduled, chat is back on the local table.
	 */
	public function test_terminal_error_ends_migration() {
		$a = $this->seed( 'A' );

		$this->qdrant->add_points_result = new WP_Error( 'collection_error', 'Not Found', 404 );
		$this->qdrant->migrate_data();

		$this->assertEquals( 'WordPress', $this->storage_of( $a ) );
		$this->assertFalse( Qdrant_API::is_active() );
		$this->assertFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
		$this->assertFalse( Qdrant_API::migration_status()['in_progress'] );
	}

	/**
	 * Progress reflects the table, and a migration whose cron event was lost
	 * (blocked WP-Cron) is re-armed when the status is read.
	 */
	public function test_status_reports_remaining_rows_and_rearms_cron() {
		$this->seed( 'A' );
		$this->seed( 'B', [ 0.0, 1.0, 0.0 ], 'processed', 'Qdrant' );

		update_option(
			'hyve_qdrant_migration',
			[
				'total'       => 2,
				'current'     => 0,
				'in_progress' => true,
			]
		);

		$status = Qdrant_API::migration_status();

		$this->assertEquals( 2, $status['total'] );
		$this->assertEquals( 1, $status['current'] );
		$this->assertTrue( $status['in_progress'] );
		$this->assertNotFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
	}

	/**
	 * While chunks are still local, chat must find them: Qdrant only holds
	 * what has been uploaded so far.
	 */
	public function test_search_uses_local_vectors_while_migration_is_pending() {
		$this->seed( 'LocalMatch' );
		$this->qdrant->search_result = [];

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertStringContainsString( 'LocalMatch', $context );
	}

	/**
	 * Once everything is migrated Qdrant answers.
	 */
	public function test_search_uses_qdrant_after_migration() {
		$this->seed( 'LocalMatch', [ 1.0, 0.0, 0.0 ], 'processed', 'Qdrant' );
		$this->qdrant->search_result = [
			[
				'post_id'      => 7,
				'post_title'   => 'QdrantMatch',
				'post_content' => 'From the cluster.',
				'token_count'  => 10,
				'score'        => 0.9,
			],
		];

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertStringContainsString( 'QdrantMatch', $context );
		$this->assertStringNotContainsString( 'LocalMatch', $context );
	}

	/**
	 * An unreachable Qdrant falls back to the local vectors instead of
	 * answering with no knowledge.
	 */
	public function test_search_falls_back_to_local_when_qdrant_fails() {
		$this->seed( 'LocalMatch', [ 1.0, 0.0, 0.0 ], 'processed', 'Qdrant' );
		$this->qdrant->search_result = new WP_Error( 'collection_error', 'Server error' );

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertStringContainsString( 'LocalMatch', $context );
	}
}
