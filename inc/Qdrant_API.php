<?php
/**
 * Qdrant_API class.
 * 
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\Main;
use Qdrant\Qdrant;
use Qdrant\Endpoints\Collections;
use Qdrant\Models\PointsStruct;
use Qdrant\Models\PointStruct;
use Qdrant\Models\VectorStruct;
use Qdrant\Models\Filter\Filter;
use Qdrant\Models\Filter\Condition\MatchString;
use Qdrant\Models\Request\CreateCollection;
use Qdrant\Models\Request\CreateIndex;
use Qdrant\Models\Request\SearchRequest;
use Qdrant\Models\Request\VectorParams;

/**
 * Qdrant_API class.
 */
class Qdrant_API {
	/**
	 * Collection name.
	 * 
	 * @var string
	 */
	const COLLECTION_NAME = 'hyve';

	/**
	 * The service error option key for `wp_options`.
	 * 
	 * @var string
	 */
	public const ERROR_OPTION_KEY = 'hyve_qdrant_api_error';

	/**
	 * Cron hook that moves local vectors to Qdrant.
	 *
	 * @var string
	 */
	public const MIGRATE_HOOK = 'hyve_lite_migrate_data';

	/**
	 * Delay before a failed migration batch is tried again, in seconds.
	 *
	 * @var int
	 */
	public const MIGRATE_RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Shared retry deadline, independent of WordPress's cron snapshots.
	 *
	 * @var string
	 */
	public const MIGRATE_RETRY_AT = 'hyve_qdrant_migration_retry_at';

	/**
	 * Option held while a migration batch is uploading, so a second cron run
	 * cannot upload the same rows alongside it. Claimed with INSERT IGNORE;
	 * stale after MIGRATE_LOCK_TTL.
	 *
	 * @var string
	 */
	public const MIGRATE_LOCK = 'hyve_qdrant_migration_lock';

	/**
	 * Seconds after which a migration lock is treated as abandoned.
	 *
	 * @var int
	 */
	public const MIGRATE_LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Qdrant Client. Unset when the stored credentials are incomplete.
	 *
	 * @var Qdrant|null
	 */
	public $client;

	/**
	 * The single instance of the class.
	 *
	 * @var Qdrant_API
	 */
	private static $instance = null;

	/**
	 * Ensures only one instance of the class is loaded.
	 *
	 * @return Qdrant_API An instance of the class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 * 
	 * @param string $api_key  API Key.
	 * @param string $endpoint Endpoint.
	 */
	public function __construct( $api_key = '', $endpoint = '' ) {
		$settings = Main::get_settings();
		$api_key  = ! empty( $api_key ) ? $api_key : $settings['qdrant_api_key'];
		$endpoint = ! empty( $endpoint ) ? $endpoint : $settings['qdrant_endpoint'];

		if ( empty( $api_key ) || empty( $endpoint ) ) {
			return;
		}

		$config = new \Qdrant\Config( $endpoint );
		$config->setApiKey( $api_key );

		$transport    = new Qdrant_HTTP_Client( $config );
		$this->client = new Qdrant( $transport );

		add_action( self::MIGRATE_HOOK, [ $this, 'migrate_data' ] );
	}

	/**
	 * Initialize Qdrant Integration.
	 * 
	 * @return bool|\WP_Error
	 */
	public function init() {
		$collection_exists = $this->collection_exists();

		if ( is_wp_error( $collection_exists ) ) {
			return $collection_exists;
		}

		if ( ! $collection_exists ) {
			$create_collection = $this->create_collection();

			if ( is_wp_error( $create_collection ) ) {
				return $create_collection;
			}
		}

		// Ensure the payload index required to filter/delete by post_id exists,
		// including on collections created before this index was introduced.
		$payload_index = $this->ensure_payload_index();

		if ( is_wp_error( $payload_index ) ) {
			return $payload_index;
		}

		update_option( 'hyve_qdrant_status', 'active' );
		delete_option( self::MIGRATE_RETRY_AT );

		$existing_chunks = DB_Table::instance()->get_unmigrated_count();

		if ( $existing_chunks > 0 ) {
			update_option(
				'hyve_qdrant_migration',
				[
					'total'       => $existing_chunks,
					'current'     => 0,
					'in_progress' => true,
				]
			);

			self::schedule_migration();
		}

		return true;
	}

	/**
	 * Schedule the next migration batch unless one is already pending.
	 *
	 * @since 2.0.5
	 *
	 * @param int $delay Seconds from now.
	 *
	 * @return void
	 */
	public static function schedule_migration( int $delay = 0 ) {
		if ( false !== wp_next_scheduled( self::MIGRATE_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 0, $delay ), self::MIGRATE_HOOK );
	}

	/**
	 * Re-arm a migration whose cron event was lost (a fatal mid-batch), or
	 * run a batch at shutdown when the event is overdue because WP-Cron never
	 * fires (blocked by HTTP auth, DISABLE_WP_CRON without a system cron).
	 * Skipped while a batch is uploading: that run schedules the next one
	 * itself, and a parallel run would upload the same rows.
	 *
	 * @since 2.0.5
	 *
	 * @return void
	 */
	private static function rearm_migration() {
		if ( self::is_locked() ) {
			return;
		}

		$next = wp_next_scheduled( self::MIGRATE_HOOK );

		if ( false === $next ) {
			self::schedule_migration();
			return;
		}

		if ( $next <= time() - self::MIGRATE_RETRY_DELAY && ! has_action( 'shutdown', [ self::instance(), 'migrate_data' ] ) ) {
			add_action( 'shutdown', [ self::instance(), 'migrate_data' ] );
		}
	}

	/**
	 * Whether a migration batch holds the lock and it is not stale.
	 *
	 * @since 2.0.5
	 *
	 * @return bool
	 */
	private static function is_locked() {
		$lock = self::get_lock();

		return false !== $lock && $lock['time'] > time() - self::MIGRATE_LOCK_TTL;
	}

	/**
	 * Read a migration lock with a valid token and timestamp.
	 *
	 * @since 2.0.5
	 *
	 * @return array{token: string, time: int}|false
	 */
	private static function get_lock() {
		$lock = get_option( self::MIGRATE_LOCK, false );

		if ( ! is_array( $lock ) || ! isset( $lock['token'], $lock['time'] ) || ! is_string( $lock['token'] ) || ! is_int( $lock['time'] ) ) {
			return false;
		}

		return [
			'token' => $lock['token'],
			'time'  => $lock['time'],
		];
	}

	/**
	 * Claim the migration lock.
	 *
	 * @since 2.0.5
	 *
	 * @return string|false The token to release it with, or false when another run holds it.
	 */
	private static function acquire_lock() {
		global $wpdb;

		$lock = self::get_lock();

		if ( false !== $lock && $lock['time'] > time() - self::MIGRATE_LOCK_TTL ) {
			return false;
		}

		if ( false !== $lock ) {
			self::delete_lock( $lock );
		}

		$token = wp_generate_uuid4();
		$value = [
			'token' => $token,
			'time'  => time(),
		];

		// add_option() can overwrite a concurrent insert through ON DUPLICATE KEY UPDATE.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::MIGRATE_LOCK,
				maybe_serialize( $value )
			)
		);
		wp_cache_delete( self::MIGRATE_LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		if ( 1 !== $claimed ) {
			return false;
		}

		return $token;
	}

	/**
	 * Release the migration lock, only if this run still holds it.
	 *
	 * @since 2.0.5
	 *
	 * @param string $token The token acquire_lock() returned.
	 *
	 * @return void
	 */
	private static function release_lock( $token ) {
		$lock = self::get_lock();

		if ( false !== $lock && $lock['token'] === $token ) {
			self::delete_lock( $lock );
		}
	}

	/**
	 * Delete only the lock that was read, preserving a concurrent replacement.
	 *
	 * @since 2.0.5
	 *
	 * @param array{token: string, time: int} $lock The stored lock.
	 *
	 * @return void
	 */
	private static function delete_lock( array $lock ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s",
				self::MIGRATE_LOCK,
				maybe_serialize( $lock )
			)
		);
		wp_cache_delete( self::MIGRATE_LOCK, 'options' );
	}

	/**
	 * Whether local chunks are still waiting to be moved to Qdrant.
	 *
	 * Chat must keep answering from the local vectors until this is false:
	 * Qdrant only holds the chunks the migration has already uploaded. A
	 * stalled migration is re-armed here so a site recovers from chat
	 * traffic alone.
	 *
	 * @since 2.0.5
	 *
	 * @return bool
	 */
	public static function has_pending_migration() {
		if ( ! self::is_active() || DB_Table::instance()->get_unmigrated_count() < 1 ) {
			return false;
		}

		self::rearm_migration();

		return true;
	}

	/**
	 * Get the configured client, or a WP_Error when it is not available.
	 *
	 * The constructor leaves the client unset when either credential is
	 * missing, so every method that talks to Qdrant must fail gracefully
	 * instead of passing null to the Qdrant library.
	 *
	 * @return Qdrant|\WP_Error
	 */
	private function ensure_client() {
		if ( $this->client instanceof Qdrant ) {
			return $this->client;
		}

		return new \WP_Error( 'qdrant_not_configured', __( 'Qdrant is not connected. Please check your API key and endpoint URL in the Integrations settings.', 'hyve-lite' ) );
	}

	/**
	 * Check if collection exists.
	 *
	 * @return bool|\WP_Error
	 */
	public function collection_exists() {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {

			$response = ( new Collections( $client ) )->list();
			delete_option( self::ERROR_OPTION_KEY );
			
			if ( empty( $response['result'] ) || empty( $response['result']['collections'] ) || ! is_array( $response['result']['collections'] ) ) {
				return false;
			}

			foreach ( $response['result']['collections'] as $collection ) {
				if ( self::COLLECTION_NAME === $collection['name'] ) {
					return true;
				}
			}

			return false;
		} catch ( \Throwable $e ) {
			return $this->handle_exception( $e );
		}
	}

	/**
	 * Create collection.
	 * 
	 * @return bool|\WP_Error
	 */
	public function create_collection() {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$collection = new CreateCollection();
			$collection->addVector( new VectorParams( 1536, VectorParams::DISTANCE_COSINE ), 'embeddings' );
			$response = $client->collections( self::COLLECTION_NAME )->create( $collection );
			delete_option( self::ERROR_OPTION_KEY );

			return $response['result'];
		} catch ( \Throwable $e ) {
			return $this->handle_exception( $e );
		}
	}

	/**
	 * Ensure the payload index needed to filter and delete by post_id exists.
	 *
	 * Qdrant requires a keyword index on a payload field before it can be used in
	 * a filter. Without it, deleting or updating points by post_id fails with a
	 * 400 Bad Request and the vectors are left orphaned. Creating an index that
	 * already exists is a no-op, so this is safe to run on every init.
	 *
	 * @since 1.4.2
	 *
	 * @return bool|\WP_Error
	 */
	public function ensure_payload_index() {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$client->collections( self::COLLECTION_NAME )->index()->create(
				new CreateIndex( 'post_id', 'keyword' )
			);
			delete_option( self::ERROR_OPTION_KEY );

			return true;
		} catch ( \Throwable $e ) {
			return $this->handle_exception( $e );
		}
	}

	/**
	 * Add point to collection.
	 * 
	 * @param array<float>         $embeddings Embeddings.
	 * @param array<string, mixed> $data Data.
	 * 
	 * @return bool|\WP_Error
	 */
	public function add_point( $embeddings, $data ) {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$points = new PointsStruct();

			$points->addPoint(
				new PointStruct(
					self::random_hash(),
					new VectorStruct( $embeddings, 'embeddings' ),
					$data
				)
			);

			$response = $client->collections( self::COLLECTION_NAME )->points()->upsert( $points, [ 'wait' => 'true' ] );

			delete_option( self::ERROR_OPTION_KEY );

			return 'completed' === $response['result']['status'];
		} catch ( \Throwable $e ) {
			return $this->handle_exception( $e );
		}
	}


	/**
	 * Add points to collection.
	 *
	 * @param array<int, array{id?: string, embeddings: array<int, float>, data: array<string, mixed>}> $points Points. A point without an id gets a random one.
	 *
	 * @return bool|\WP_Error
	 */
	public function add_points( $points ) {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$points_struct = new PointsStruct();

			foreach ( $points as $point ) {
				$points_struct->addPoint(
					new PointStruct(
						$point['id'] ?? self::random_hash(),
						new VectorStruct( $point['embeddings'], 'embeddings' ),
						$point['data']
					)
				);
			}

			$response = $client->collections( self::COLLECTION_NAME )->points()->upsert( $points_struct, [ 'wait' => 'true' ] );

			delete_option( self::ERROR_OPTION_KEY );

			return 'completed' === $response['result']['status'];
		} catch ( \Throwable $e ) {
			return $this->handle_exception( $e );
		}
	}

	/**
	 * Delete point from collection.
	 * 
	 * @param int $id ID.
	 * 
	 * @return bool|\WP_Error
	 */
	public function delete_point( $id ) {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->collections( self::COLLECTION_NAME )->points()->deleteByFilter(
				( new Filter() )->addMust(
					new MatchString( 'post_id', (string) $id )
				)
			);

			delete_option( self::ERROR_OPTION_KEY );

			return 'acknowledged' === $response['result']['status'];
		} catch ( \Throwable $e ) {
			return $this->handle_exception( $e );
		}
	}

	/**
	 * Search collection.
	 * 
	 * @param array<float> $embeddings Embeddings.
	 * @param float        $score_threshold Cosine similarity threshold.
	 * 
	 * @return array<array<string, mixed>>|\WP_Error
	 */
	public function search( $embeddings, $score_threshold ) {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$search   = (
				new SearchRequest(
					new VectorStruct( $embeddings, 'embeddings' )
				)
			)
			->setLimit( 10 )
			->setScoreThreshold( $score_threshold )
			->setWithPayload( true );
			$response = $client->collections( self::COLLECTION_NAME )->points()->search( $search );

			// Any completed search means the service recovered.
			delete_option( self::ERROR_OPTION_KEY );

			if ( empty( $response['result'] ) ) {
				return [];
			}

			$results = $response['result'];

			$payload = array_map(
				function ( $result ) {
					$payload          = $result['payload'];
					$payload['score'] = $result['score'];
					return $payload;
				},
				$results
			);

			return $payload;
		} catch ( \Throwable $e ) {
			return $this->handle_exception( $e );
		}
	}

	/**
	 * Disconnect Qdrant Integration.
	 *
	 * @since 1.3.0
	 *  
	 * @return bool|\WP_Error
	 */
	public function disconnect() {
		$client = $this->ensure_client();

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$response = $client->collections( self::COLLECTION_NAME )->points()->deleteByFilter(
				( new Filter() )->addMust(
					new MatchString( 'website_url', get_site_url() )
				)
			);

			return 'acknowledged' === $response['result']['status'];
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'collection_error', self::throwable_message( $e ) );
		}
	}

	/**
	 * Message for a caught throwable.
	 *
	 * The Qdrant client throws a bare Error on a response-less
	 * RequestException (for example, a TLS verification failure), so give
	 * a readable cause.
	 *
	 * @since 2.0.5
	 *
	 * @param \Throwable $e The throwable.
	 *
	 * @return string
	 */
	private static function throwable_message( $e ) {
		return $e instanceof \Exception ? $e->getMessage() : __( 'Hyve could not connect to Qdrant.', 'hyve-lite' );
	}

	/**
	 * Migrate Data to Qdrant.
	 * 
	 * @return void
	 */
	public function migrate_data() {
		$token = self::acquire_lock();

		if ( false === $token ) {
			return;
		}

		try {
			// A second request may still cache the overdue event in autoloaded options.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'cron', 'options' );
			wp_cache_delete( self::MIGRATE_RETRY_AT, 'options' );
			$next     = wp_next_scheduled( self::MIGRATE_HOOK );
			$retry_at = (int) get_option( self::MIGRATE_RETRY_AT, 0 );

			// A cron worker may already have captured the event recovered at shutdown.
			if ( $retry_at > time() ) {
				if ( false !== $next && $next <= time() ) {
					wp_unschedule_event( $next, self::MIGRATE_HOOK );
				}
				self::schedule_migration( $retry_at - time() );
				return;
			}

			// Another request may have recovered the event before this shutdown runs.
			if ( 'shutdown' === current_action() && ( false === $next || $next > time() - self::MIGRATE_RETRY_DELAY ) ) {
				return;
			}

			// WP-Cron consumes its event before dispatch; shutdown recovery must do the same.
			if ( false !== $next && $next <= time() ) {
				wp_unschedule_event( $next, self::MIGRATE_HOOK );
			}

			delete_option( self::MIGRATE_RETRY_AT );
			$this->migrate_batch();
		} finally {
			self::release_lock( $token );
		}
	}

	/**
	 * Upload one batch of local vectors and schedule the next.
	 *
	 * @since 2.0.5
	 *
	 * @return void
	 */
	private function migrate_batch() {
		if ( ! self::is_active() ) {
			$this->finish_migration();
			return;
		}

		$db_table = DB_Table::instance();
		$posts    = $db_table->get_unmigrated();

		if ( empty( $posts ) ) {
			$this->finish_migration();
			return;
		}

		$points = [];

		foreach ( $posts as $post ) {
			$embeddings = json_decode( $post->embeddings, true );

			if ( ! is_array( $embeddings ) || empty( $embeddings ) ) {
				continue;
			}

			// A stable id makes a retried batch overwrite rather than duplicate
			// points Qdrant stored before the first response was lost.
			$points[] = [
				'id'         => self::point_id( 'row-' . $post->id ),
				'embeddings' => $embeddings,
				'data'       => [
					'post_id'      => $post->post_id,
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'token_count'  => $post->token_count,
					'website_url'  => get_site_url(),
				],
			];
		}

		$success = empty( $points ) ? true : $this->add_points( $points );

		if ( is_wp_error( $success ) || true !== $success ) {
			// A transient failure keeps the batch local and tries again later;
			// chat keeps answering from the local vectors meanwhile. A terminal
			// error has already marked Qdrant inactive, which ends the migration.
			if ( ! is_wp_error( $success ) ) {
				update_option(
					self::ERROR_OPTION_KEY,
					[
						'code'     => 'incomplete',
						'message'  => __( 'Qdrant did not confirm the upload.', 'hyve-lite' ),
						'date'     => wp_date( 'c' ),
						'provider' => 'Qdrant',
					]
				);
			}

			if ( self::is_active() ) {
				update_option( self::MIGRATE_RETRY_AT, time() + self::MIGRATE_RETRY_DELAY, false );
				self::schedule_migration( self::MIGRATE_RETRY_DELAY );
			} else {
				$this->finish_migration();
			}

			return;
		}

		foreach ( $posts as $post ) {
			$db_table->update(
				intval( $post->id ),
				[
					'storage' => 'Qdrant',
				]
			);
		}

		if ( $db_table->get_unmigrated_count() > 0 ) {
			self::schedule_migration( 10 );
			return;
		}

		$this->finish_migration();
	}

	/**
	 * Mark the migration as no longer running.
	 *
	 * @since 2.0.5
	 *
	 * @return void
	 */
	private function finish_migration() {
		delete_option( self::MIGRATE_RETRY_AT );
		$migration_status = get_option( 'hyve_qdrant_migration', [] );

		if ( empty( $migration_status ) ) {
			return;
		}

		$migration_status['in_progress'] = false;
		update_option( 'hyve_qdrant_migration', $migration_status );
	}

	/**
	 * Persist a Qdrant service error and reconcile the connection status.
	 *
	 * Surfaces the failure in the dashboard notice and, for errors that mean
	 * the integration is no longer usable (revoked key, or a missing collection
	 * or cluster), marks the connection inactive so the UI stops reporting a
	 * connection that no longer works.
	 *
	 * @since 1.4.2
	 *
	 * @param \Throwable $e The caught exception.
	 *
	 * @return \WP_Error
	 */
	private function handle_exception( $e ) {
		$code    = $e instanceof \Exception ? $e->getCode() : 0;
		$message = self::throwable_message( $e );

		update_option(
			self::ERROR_OPTION_KEY,
			[
				'code'     => $code,
				'message'  => $message,
				'date'     => wp_date( 'c' ),
				'provider' => 'Qdrant',
			]
		);

		if ( in_array( $code, [ 401, 403, 404 ], true ) ) {
			update_option( 'hyve_qdrant_status', 'inactive' );
		}

		return new \WP_Error( 'collection_error', $message );
	}

	/**
	 * Get an actionable message for a Qdrant error code.
	 *
	 * @since 1.4.2
	 *
	 * @param int|string $code The error code.
	 *
	 * @return string|null The message, or null when the code is unmapped.
	 */
	public static function get_error_message_for_code( $code ) {
		$credentials = __( 'Hyve could not authenticate with Qdrant. Please check your API key and endpoint URL in the Integrations settings.', 'hyve-lite' );

		$messages = [
			401 => $credentials,
			403 => $credentials,
			404 => __( 'Your Qdrant collection or cluster could not be reached. It may have been deleted or paused. Please check your Qdrant instance and reconnect.', 'hyve-lite' ),
		];

		return isset( $messages[ $code ] ) ? $messages[ $code ] : null;
	}

	/**
	 * Qdrant Status.
	 *
	 * @since 1.3.0
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	public static function is_active() {
		return 'active' === get_option( 'hyve_qdrant_status', 'inactive' );
	}

	/**
	 * Qdrant Migration Status.
	 * 
	 * @since 1.3.0
	 * 
	 * @return array{}|array{total: int, current: int, in_progress: bool, error?: string}
	 */
	public static function migration_status() {
		$status = get_option( 'hyve_qdrant_migration', [] );

		if ( ! self::is_active() ) {
			return is_array( $status ) ? $status : [];
		}

		// Report what is actually left in the table rather than a counter the
		// cron may never have advanced.
		$remaining = DB_Table::instance()->get_unmigrated_count();
		$total     = max( (int) ( $status['total'] ?? 0 ), $remaining );

		if ( $remaining > 0 ) {
			self::rearm_migration();
		}

		$status = [
			'total'       => $total,
			'current'     => max( 0, $total - $remaining ),
			'in_progress' => $remaining > 0,
		];

		$error = get_option( self::ERROR_OPTION_KEY, false );

		if ( $remaining > 0 && is_array( $error ) && ! empty( $error['date'] ) ) {
			$status['error'] = (string) ( $error['message'] ?? '' );
		}

		return $status;
	}

	/**
	 * Random Hash.
	 * 
	 * @since 1.3.0
	 * 
	 * @return string
	 */
	public static function random_hash() {
		return self::format_uuid( random_bytes( 16 ) );
	}

	/**
	 * Stable point id for a chunk of this site.
	 *
	 * @since 2.0.5
	 *
	 * @param string $key What the point represents, e.g. the chunk row id.
	 *
	 * @return string
	 */
	public static function point_id( $key ) {
		return self::format_uuid( md5( get_site_url() . '|' . $key, true ) );
	}

	/**
	 * Lay 16 bytes out as a version 4 UUID, the id format Qdrant accepts.
	 *
	 * @param string $data 16 bytes.
	 *
	 * @return string
	 */
	private static function format_uuid( $data ) {
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
