<?php
/**
 * Tests for the Qdrant migration and the retrieval path while it runs.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Main;
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
			 * What disconnect() returns.
			 *
			 * @var bool|WP_Error
			 */
			public $disconnect_result = true;

			/**
			 * Runs once inside add_points(), while the upload is "in flight".
			 *
			 * @var callable|null
			 */
			public $during_upload = null;

			/**
			 * Number of remote disconnect attempts.
			 *
			 * @var int
			 */
			public $disconnect_calls = 0;

			/**
			 * Return the canned disconnect result.
			 *
			 * @return bool|WP_Error
			 */
			public function disconnect() {
				++$this->disconnect_calls;
				return $this->disconnect_result;
			}

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

				if ( is_callable( $this->during_upload ) ) {
					$callback            = $this->during_upload;
					$this->during_upload = null;
					$callback();
				}

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
		delete_option( Qdrant_API::MIGRATE_RETRY_AT );
		delete_option( Qdrant_API::MIGRATE_LOCK );
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
		delete_option( Qdrant_API::MIGRATE_RETRY_AT );
		delete_option( Qdrant_API::MIGRATE_LOCK );
		remove_action( 'shutdown', [ $this->qdrant, 'migrate_data' ] );
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
		update_option( Qdrant_API::MIGRATE_RETRY_AT, time() - 1, false );
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
		add_option(
			Qdrant_API::MIGRATE_LOCK,
			[
				'token' => 'other-run',
				'time'  => time(),
			],
			'',
			false
		);

		$this->qdrant->migrate_data();
		$this->assertCount( 0, $this->qdrant->batches );

		Qdrant_API::migration_status();
		Qdrant_API::has_pending_migration();
		$this->assertFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );

		// Another run's lock is never released by this one.
		$this->assertSame( 'other-run', get_option( Qdrant_API::MIGRATE_LOCK )['token'] );
	}

	/**
	 * An upload that outlives the lock TTL (a dripping response on a host
	 * without cURL) lets the next run take the lock while the first is still
	 * uploading. The overlap is harmless: both runs upsert the same stable
	 * point ids, and every row ends up migrated exactly once.
	 */
	public function test_upload_outliving_lock_overlaps_without_duplicating() {
		$a = $this->seed( 'A' );
		$b = $this->seed( 'B' );

		$this->qdrant->during_upload = function () {
			// The first upload has been running longer than the TTL.
			$lock         = get_option( Qdrant_API::MIGRATE_LOCK );
			$lock['time'] = time() - Qdrant_API::MIGRATE_LOCK_TTL - 1;
			update_option( Qdrant_API::MIGRATE_LOCK, $lock, false );

			$this->qdrant->migrate_data();
		};

		$this->qdrant->migrate_data();

		$this->assertCount( 2, $this->qdrant->batches );
		$this->assertSame(
			array_column( $this->qdrant->batches[0], 'id' ),
			array_column( $this->qdrant->batches[1], 'id' )
		);
		$this->assertEquals( 'Qdrant', $this->storage_of( $a ) );
		$this->assertEquals( 'Qdrant', $this->storage_of( $b ) );
		$this->assertFalse( Qdrant_API::has_pending_migration() );
		$this->assertFalse( get_option( Qdrant_API::MIGRATE_LOCK ) );
	}

	/**
	 * A lock left behind by a run that died mid-batch is stale after its TTL
	 * and does not block the migration; the lock is released after a run,
	 * even one that failed.
	 */
	public function test_stale_lock_is_reclaimed_and_released() {
		$this->seed( 'A' );
		add_option(
			Qdrant_API::MIGRATE_LOCK,
			[
				'token' => 'dead-run',
				'time'  => time() - Qdrant_API::MIGRATE_LOCK_TTL - 1,
			],
			'',
			false
		);

		$this->qdrant->add_points_result = new WP_Error( 'collection_error', 'Server error', 500 );
		$this->qdrant->migrate_data();

		$this->assertCount( 1, $this->qdrant->batches );
		$this->assertFalse( get_option( Qdrant_API::MIGRATE_LOCK ) );
	}

	/**
	 * A concurrent claim between the option read and insert must win.
	 */
	public function test_concurrent_insert_does_not_overwrite_the_lock() {
		$this->seed( 'A' );
		$other = [
			'token' => 'concurrent-run',
			'time'  => time(),
		];
		$claim = function ( $query ) use ( &$claim, $other ) {
			if ( false !== strpos( $query, 'INSERT' ) && false !== strpos( $query, Qdrant_API::MIGRATE_LOCK ) ) {
				remove_filter( 'query', $claim );
				add_option( Qdrant_API::MIGRATE_LOCK, $other, '', false );
			}
			return $query;
		};
		add_filter( 'query', $claim );

		try {
			$this->qdrant->migrate_data();
		} finally {
			remove_filter( 'query', $claim );
		}

		$this->assertCount( 0, $this->qdrant->batches );
		$this->assertSame( $other, get_option( Qdrant_API::MIGRATE_LOCK ) );
	}

	/**
	 * Reclaiming a stale lock cannot delete a newer run's replacement.
	 */
	public function test_stale_lock_replacement_is_preserved() {
		$this->seed( 'A' );
		add_option(
			Qdrant_API::MIGRATE_LOCK,
			[
				'token' => 'dead-run',
				'time'  => time() - Qdrant_API::MIGRATE_LOCK_TTL - 1,
			],
			'',
			false
		);
		$other   = [
			'token' => 'replacement-run',
			'time'  => time(),
		];
		$replace = function ( $query ) use ( &$replace, $other ) {
			if ( false !== strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, Qdrant_API::MIGRATE_LOCK ) ) {
				remove_filter( 'query', $replace );
				update_option( Qdrant_API::MIGRATE_LOCK, $other, false );
			}
			return $query;
		};
		add_filter( 'query', $replace );

		try {
			$this->qdrant->migrate_data();
		} finally {
			remove_filter( 'query', $replace );
		}

		$this->assertCount( 0, $this->qdrant->batches );
		$this->assertSame( $other, get_option( Qdrant_API::MIGRATE_LOCK ) );
	}

	/**
	 * When WP-Cron never fires, the event sits overdue in the cron array, so
	 * re-scheduling would do nothing. The chat path runs a batch at shutdown
	 * instead, so the site still recovers from visitor traffic.
	 */
	public function test_overdue_event_runs_a_batch_at_shutdown() {
		$a = $this->seed( 'A' );
		wp_schedule_single_event( time() - 2 * Qdrant_API::MIGRATE_RETRY_DELAY, Qdrant_API::MIGRATE_HOOK );

		$this->assertTrue( Qdrant_API::has_pending_migration() );
		$this->assertNotFalse( has_action( 'shutdown', [ $this->qdrant, 'migrate_data' ] ) );

		// Core's own shutdown callback would close PHPUnit's output buffer.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
		do_action( 'shutdown' );
		add_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		$this->assertCount( 1, $this->qdrant->batches );
		$this->assertEquals( 'Qdrant', $this->storage_of( $a ) );
	}

	/**
	 * Shutdown recovery consumes the overdue event and respects a failed
	 * batch's retry time, including callbacks queued before the first run.
	 */
	public function test_shutdown_recovery_preserves_retry_delay() {
		$a = $this->seed( 'A' );
		wp_schedule_single_event( time() - 2 * Qdrant_API::MIGRATE_RETRY_DELAY, Qdrant_API::MIGRATE_HOOK );
		$this->qdrant->add_points_result = new WP_Error( 'collection_error', 'Server error', 500 );
		$overdue                         = get_option( 'cron' );

		Qdrant_API::has_pending_migration();
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		try {
			do_action( 'shutdown' );
			$this->assertCount( 1, $this->qdrant->batches );
			$this->assertGreaterThanOrEqual( time() + Qdrant_API::MIGRATE_RETRY_DELAY - 1, wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );

			// Simulate another visitor's option cache from before the first run.
			$alloptions         = wp_load_alloptions();
			$alloptions['cron'] = maybe_serialize( $overdue );
			wp_cache_set( 'alloptions', $alloptions, 'options' );
			wp_cache_set( 'cron', $overdue, 'options' );
			do_action( 'shutdown' );
			$this->assertCount( 1, $this->qdrant->batches );
			$this->assertEquals( 'WordPress', $this->storage_of( $a ) );

			// A captured cron job can overwrite the retry event before dispatching.
			wp_clear_scheduled_hook( Qdrant_API::MIGRATE_HOOK );
			$this->qdrant->migrate_data();
			$this->assertCount( 1, $this->qdrant->batches );
			$this->assertGreaterThanOrEqual( time() + Qdrant_API::MIGRATE_RETRY_DELAY - 1, wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
		} finally {
			add_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
		}
	}

	/**
	 * Rows with no usable embedding have nothing to upload; they are skipped
	 * and marked migrated so the migration can finish.
	 */
	public function test_rows_without_embeddings_are_skipped_but_marked() {
		$a     = $this->seed( 'A' );
		$empty = $this->seed( 'Empty', [] );

		$this->qdrant->migrate_data();

		$this->assertCount( 1, $this->qdrant->batches );
		$this->assertCount( 1, $this->qdrant->batches[0] );
		$this->assertEquals( 'Qdrant', $this->storage_of( $a ) );
		$this->assertEquals( 'Qdrant', $this->storage_of( $empty ) );
		$this->assertFalse( Qdrant_API::has_pending_migration() );
	}

	/**
	 * A disconnect that Qdrant rejects must not report success and wipe the
	 * credentials: the site's points would stay in the collection and a
	 * reconnect would duplicate them.
	 */
	public function test_failed_disconnect_keeps_settings_and_storage() {
		$a        = $this->seed( 'A', [ 1.0, 0.0, 0.0 ], 'processed', 'Qdrant' );
		$settings = Main::get_settings();

		$settings['qdrant_api_key']  = 'key';
		$settings['qdrant_endpoint'] = 'https://example.qdrant.io';
		Main::save_settings( $settings );

		$this->qdrant->disconnect_result = new WP_Error( 'collection_error', 'Hyve could not connect to Qdrant.' );

		$response = API::instance()->qdrant_deactivate();

		$this->assertSame( 'Hyve could not connect to Qdrant.', $response->get_data()['error'] );
		$this->assertTrue( Qdrant_API::is_active() );
		$this->assertSame( 'key', Main::get_settings()['qdrant_api_key'] );
		$this->assertEquals( 'Qdrant', $this->storage_of( $a ) );

		$settings['qdrant_api_key']  = '';
		$settings['qdrant_endpoint'] = '';
		Main::save_settings( $settings );
	}

	/**
	 * A local-only disconnect gives an unreachable cluster a local exit.
	 */
	public function test_forced_disconnect_skips_remote_and_cleans_up() {
		$a                           = $this->seed( 'A', [ 1.0, 0.0, 0.0 ], 'processed', 'Qdrant' );
		$settings                    = Main::get_settings();
		$settings['qdrant_api_key']  = 'key';
		$settings['qdrant_endpoint'] = 'https://example.qdrant.io';
		Main::save_settings( $settings );
		update_option( Qdrant_API::ERROR_OPTION_KEY, [ 'message' => 'Unreachable' ] );
		update_option( Qdrant_API::MIGRATE_RETRY_AT, time() + 300, false );
		Qdrant_API::schedule_migration();
		$this->qdrant->disconnect_result = new WP_Error( 'collection_error', 'Unreachable' );
		$request                         = new WP_REST_Request( 'POST' );
		$request->set_param( 'force', true );

		$response = API::instance()->qdrant_deactivate( $request );

		$this->assertSame( 'Qdrant deactivated.', $response->get_data() );
		$this->assertSame( 0, $this->qdrant->disconnect_calls );
		$this->assertFalse( Qdrant_API::is_active() );
		$this->assertSame( '', Main::get_settings()['qdrant_api_key'] );
		$this->assertSame( '', Main::get_settings()['qdrant_endpoint'] );
		$this->assertEquals( 'WordPress', $this->storage_of( $a ) );
		$this->assertFalse( get_option( 'hyve_qdrant_migration' ) );
		$this->assertFalse( get_option( Qdrant_API::ERROR_OPTION_KEY ) );
		$this->assertFalse( get_option( Qdrant_API::MIGRATE_RETRY_AT ) );
		$this->assertFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
	}

	/**
	 * Even at its HTTP deadline, an upload still holds a live migration lock.
	 */
	public function test_upload_timeout_cannot_outlive_the_lock() {
		$a                  = $this->seed( 'A' );
		$qdrant             = new Qdrant_API( 'test-key', 'https://example.invalid' );
		$transport_property = new ReflectionProperty( Qdrant\Qdrant::class, 'client' );
		$transport_property->setAccessible( true );
		$transport     = $transport_property->getValue( $qdrant->client );
		$http_property = new ReflectionProperty( Qdrant\Http\GuzzleClient::class, 'client' );
		$http_property->setAccessible( true );
		$config = $http_property->getValue( $transport )->getConfig();
		$this->assertGreaterThan( 0, $config['timeout'] );
		$this->assertLessThan( Qdrant_API::MIGRATE_LOCK_TTL, $config['timeout'] );
		$calls             = 0;
		$config['handler'] = function ( $request, $options ) use ( $qdrant, &$calls ) {
			++$calls;
			$lock         = get_option( Qdrant_API::MIGRATE_LOCK );
			$lock['time'] = time() - $options['timeout'];
			update_option( Qdrant_API::MIGRATE_LOCK, $lock, false );
			$qdrant->migrate_data();
			throw new GuzzleHttp\Exception\ConnectException( 'Operation timed out', $request );
		};
		$http_property->setValue( $transport, new GuzzleHttp\Client( $config ) );

		try {
			$qdrant->migrate_data();
		} finally {
			remove_action( Qdrant_API::MIGRATE_HOOK, [ $qdrant, 'migrate_data' ] );
		}

		$this->assertSame( 1, $calls );
		$this->assertEquals( 'WordPress', $this->storage_of( $a ) );
		$this->assertFalse( get_option( Qdrant_API::MIGRATE_LOCK ) );
		$this->assertNotFalse( wp_next_scheduled( Qdrant_API::MIGRATE_HOOK ) );
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
	 * Qdrant answers only once the last chunk has moved: before that the
	 * local table is the only place that holds every vector.
	 */
	public function test_search_switches_to_qdrant_once_migration_completes() {
		$this->seed( 'LocalMatch' );
		$this->seed( 'Migrated', [ 0.0, 1.0, 0.0 ], 'processed', 'Qdrant' );
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

		$this->assertStringContainsString( 'LocalMatch', $context );
		$this->assertStringNotContainsString( 'QdrantMatch', $context );

		$this->qdrant->migrate_data();

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
