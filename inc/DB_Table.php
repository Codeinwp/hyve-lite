<?php
/**
 * Database Table Class.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\OpenAI;
use ThemeIsle\HyveLite\Qdrant_API;
use ThemeIsle\HyveLite\Tokenizer;

/**
 * Class DB_Table
 */
class DB_Table {

	/**
	 * The name of our database table.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $table_name;

	/**
	 * The version of our database table.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $version = '1.1.0';

	/**
	 * Cache prefix.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const CACHE_PREFIX = 'hyve-';

	/**
	 * Maximum number of times a chunk is retried after a transient failure
	 * before it is marked failed. See Codeinwp/hyve#199.
	 *
	 * @var int
	 */
	const MAX_PROCESS_ATTEMPTS = 5;

	/**
	 * Source documents pushed to Hyve Connect per migration run.
	 *
	 * @var int
	 */
	const CONNECT_SYNC_BATCH = 10;

	/**
	 * The option key tracking the to-Connect sync job.
	 *
	 * @var string
	 */
	const CONNECT_SYNC_OPTION = 'hyve_connect_migration';

	/**
	 * The cron hook that drives the to-Connect sync job.
	 *
	 * @var string
	 */
	const CONNECT_SYNC_HOOK = 'hyve_lite_connect_sync';

	/**
	 * The single instance of the class.
	 *
	 * @var DB_Table
	 */
	private static $instance = null;

	/**
	 * Ensures only one instance of the class is loaded.
	 *
	 * @return DB_Table An instance of the class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * DB_Table constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'hyve';

		add_action(
			'hyve_process_post',
			function ( $id ) {
				// Discard the return value: a cron/action callback must not return anything.
				$this->process_post( $id );
			},
			10,
			1
		);
		add_action( 'hyve_delete_posts', [ $this, 'delete_posts' ], 10, 1 );
		add_action( 'hyve_update_posts', [ $this, 'update_posts' ] );

		if ( ! wp_next_scheduled( 'hyve_update_posts' ) ) {
			wp_schedule_event( time(), 'hourly', 'hyve_update_posts' );
		}

		if ( ! $this->table_exists() || version_compare( $this->version, get_option( $this->table_name . '_db_version' ), '>' ) ) {
			$this->create_table();
		}
	}

	/**
	 * Create the table.
	 * 
	 * @return void
	 *
	 * @since 1.2.0
	 */
	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = 'CREATE TABLE ' . $this->table_name . ' (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		date datetime NOT NULL,
		modified datetime NOT NULL,
		post_id mediumtext NOT NULL,
		post_title mediumtext NOT NULL,
		post_content longtext NOT NULL,
		embeddings longtext NOT NULL,
		token_count int(11) NOT NULL DEFAULT 0,
		post_status VARCHAR(255) NOT NULL DEFAULT "scheduled",
        storage VARCHAR(255) NOT NULL DEFAULT "WordPress",
		PRIMARY KEY (id)
		) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;';

		dbDelta( $sql );
		update_option( $this->table_name . '_db_version', $this->version );
	}

	/**
	 * Check if the table exists.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function table_exists() {
		global $wpdb;
		$table = sanitize_text_field( $this->table_name );
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Get columns and formats.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return [
			'date'         => '%s',
			'modified'     => '%s',
			'post_id'      => '%s',
			'post_title'   => '%s',
			'post_content' => '%s',
			'embeddings'   => '%s',
			'token_count'  => '%d',
			'post_status'  => '%s',
			'storage'      => '%s',
		];
	}

	/**
	 * Get default column values.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_column_defaults() {
		return [
			'date'         => gmdate( 'Y-m-d H:i:s' ),
			'modified'     => gmdate( 'Y-m-d H:i:s' ),
			'post_id'      => '',
			'post_title'   => '',
			'post_content' => '',
			'embeddings'   => '',
			'token_count'  => 0,
			'post_status'  => 'scheduled',
			'storage'      => 'WordPress',
		];
	}

	/**
	 * Get a row by ID.
	 * 
	 * @since 1.3.0
	 * 
	 * @param int $id The row ID.
	 * 
	 * @return object{
	 *     id: string,
	 *     date: string,
	 *     modified: string,
	 *     post_id: string,
	 *     post_title: string,
	 *     post_content: string,
	 *     embeddings: string,
	 *     token_count: string,
	 *     post_status: string,
	 *     storage: string
	 * }
	 */
	public function get( $id ) {
		global $wpdb;

		$cache = $this->get_cache( 'entry_' . $id );

		if ( false !== $cache ) {
			return $cache;
		}

		$result = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table_name, $id ) );

		$this->set_cache( 'entry_' . $id, $result );

		return $result;
	}

	/**
	 * Insert a new row.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $data The data to insert.
	 *
	 * @return int
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$column_formats  = $this->get_columns();
		$column_defaults = $this->get_column_defaults();

		$data = wp_parse_args( $data, $column_defaults );
		$data = array_intersect_key( $data, $column_formats );

		$wpdb->insert( $this->table_name, $data, $column_formats );

		$this->delete_cache( 'entries' );
		$this->delete_cache( 'entries_count' );
		$this->delete_cache( 'chunk_counts' );
		$this->delete_cache( 'cached_embeddings' );

		return $wpdb->insert_id;
	}

	/**
	 * Update a row.
	 *
	 * @since 1.2.0
	 *
	 * @param int                  $id The row ID.
	 * @param array<string, mixed> $data The data to update.
	 *
	 * @return int
	 */
	public function update( int $id, array $data ): int {
		global $wpdb;

		$column_formats  = $this->get_columns();
		$column_defaults = $this->get_column_defaults();

		$data = array_intersect_key( $data, $column_formats );

		$rows_affected = $wpdb->update( $this->table_name, $data, [ 'id' => $id ], $column_formats, [ '%d' ] );

		$this->delete_cache( 'entry_' . $id );
		$this->delete_cache( 'entries_processed' );
		$this->delete_cache( 'cached_embeddings' );

		return $rows_affected;
	}

	/**
	 * Delete rows by post ID.
	 * 
	 * @since 1.2.0
	 * 
	 * @param int $post_id The post ID.
	 * 
	 * @return int
	 */
	public function delete_by_post_id( $post_id ) {
		global $wpdb;

		$rows_affected = $wpdb->delete( $this->table_name, [ 'post_id' => $post_id ], [ '%d' ] );

		$this->delete_cache( 'entries' );
		$this->delete_cache( 'entries_processed' );
		$this->delete_cache( 'entries_count' );
		$this->delete_cache( 'chunk_counts' );
		$this->delete_cache( 'cached_embeddings' );

		return $rows_affected;
	}

	/**
	 * Get all rows by status.
	 *
	 * @since 1.2.0
	 *
	 * @param string $status The status.
	 * @param int    $limit The limit.
	 *
	 * @return array<object{
	 *     id: string,
	 *     date: string,
	 *     modified: string,
	 *     post_id: string,
	 *     post_title: string,
	 *     post_content: string,
	 *     embeddings: string,
	 *     token_count: string,
	 *     post_status: string,
	 *     storage: string
	 * }>
	 */
	public function get_by_status( string $status, int $limit = 500 ): array {
		global $wpdb;

		$cache = $this->get_cache( 'entries_' . $status );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_status = %s LIMIT %d', $this->table_name, $status, $limit ) );

		if ( 'scheduled' !== $status ) {
			$this->set_cache( 'entries_' . $status, $results );
		}

		return $results;
	}

	/**
	 * Get all rows by storage.
	 *
	 * @since 1.2.0
	 *
	 * @param string $storage The storage.
	 * @param int    $limit The limit.
	 *
	 * @return array<object{
	 *     id: string,
	 *     date: string,
	 *     modified: string,
	 *     post_id: string,
	 *     post_title: string,
	 *     post_content: string,
	 *     embeddings: string,
	 *     token_count: string,
	 *     post_status: string,
	 *     storage: string
	 * }>
	 */
	public function get_by_storage( string $storage, int $limit = 100 ): array {
		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE storage = %s LIMIT %d', $this->table_name, $storage, $limit ) );
		return $results;
	}

	/**
	 * Count rows held in a given storage backend.
	 *
	 * @param string $storage Storage backend (e.g. WordPress|Qdrant).
	 *
	 * @return int
	 */
	public function get_count_by_storage( string $storage ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE storage = %s', $this->table_name, $storage ) );
	}

	/**
	 * Get embeddings with pagination.
	 * 
	 * @param int $offset The offset for pagination.
	 * @param int $limit  The limit of results to return.
	 * 
	 * @return array<object{
	 *     id: string,
	 *     embeddings: string,
	 *     token_count: string
	 * }>
	 */
	public function get_embeddings( $offset, $limit = 50 ) {
		$cache_key = 'hyve_embeddings_' . $offset . '_' . $limit;
		$cache     = $this->get_cache( $cache_key );

		if ( false !== $cache ) {
			return $cache;
		}

		global $wpdb;
		$posts = $wpdb->get_results( $wpdb->prepare( 'SELECT id, embeddings, token_count FROM %i WHERE post_status = %s LIMIT %d OFFSET %d', $this->table_name, 'processed', $limit, $offset ) );
		
		if ( empty( $posts ) ) {
			return [];
		}

		$cached_embeddings = $this->get_cache( 'cached_embeddings' );
		if ( false === $cached_embeddings ) {
			$cached_embeddings = [];
		}
		$cached_embeddings[] = $cache_key;

		$this->set_cache( $cache_key, $posts );
		$this->set_cache( 'cached_embeddings', $cached_embeddings );

		return $posts;
	}

	/**
	 * Get post data by ID.
	 * 
	 * @param int $id The row ID.
	 * 
	 * @return array{post_title: string, post_content: string}|null
	 */
	public function get_post_data( $id ) {
		$cache_key = 'hyve_post_data_' . $id;
		$cache     = $this->get_cache( $cache_key );

		if ( false !== $cache ) {
			return $cache;
		}

		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( 'SELECT post_title, post_content FROM %i WHERE id = %d', $this->table_name, $id ), ARRAY_A );

		if ( empty( $post ) ) {
			return null;
		}

		$this->set_cache( $cache_key, $post );

		return $post;
	}

	/**
	 * Get the source post ID for a chunk row.
	 *
	 * @since 1.4.2
	 *
	 * @param int $id Row ID in the table.
	 *
	 * @return string|null Source post ID, or null if the row does not exist.
	 */
	public function get_post_id( $id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE id = %d', $this->table_name, $id ) );
	}

	/**
	 * Update storage of all rows.
	 * 
	 * @since 1.3.0
	 * 
	 * @param string $to   The storage.
	 * @param string $from The storage.
	 * 
	 * @return int
	 */
	public function update_storage( $to, $from ) {
		global $wpdb;
		$wpdb->update( $this->table_name, [ 'storage' => $to ], [ 'storage' => $from ], [ '%s' ], [ '%s' ] );
		$this->delete_cache( 'entries' );
		$this->delete_cache( 'entries_processed' );
		$this->delete_cache( 'cached_embeddings' );
		return $wpdb->rows_affected;
	}

	/**
	 * Get Posts over limit.
	 * 
	 * @since 1.3.0
	 * 
	 * @return array<integer>
	 */
	public function get_posts_over_limit() {
		$limit = apply_filters( 'hyve_chunks_limit', 500 );

		global $wpdb;
		$posts = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id FROM %i ORDER BY id DESC LIMIT %d, %d', $this->table_name, $limit, $this->get_count() ) );

		if ( ! $posts ) {
			return [];
		}

		$posts = wp_list_pluck( $posts, 'post_id' );
		$posts = array_unique( $posts );

		return $posts;
	}

	/**
	 * Add Post to queue.
	 * 
	 * @since 1.3.1
	 * 
	 * @param int    $post_id The post ID.
	 * @param string $action The action.
	 * 
	 * @return true|\WP_Error
	 * @throws \Exception If Qdrant API fails.
	 */
	public function add_post( $post_id, $action = 'add' ) {
		update_post_meta( $post_id, '_hyve_post_processing', 1 );

		$result = $this->ingest_document(
			[
				'title'   => get_the_title( $post_id ),
				'content' => apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) ),
			],
			[
				'action'             => 'update' === $action ? 'update' : 'add',
				'override'           => 'override' === $action,
				'post_id'            => $post_id,
				'create'             => false,
				'persist_moderation' => true,
				'retry_async'        => 'update' === $action,
			]
		);

		delete_post_meta( $post_id, '_hyve_post_processing' );

		return $result;
	}

	/**
	 * Ingest a document into the knowledge base.
	 *
	 * Shared pipeline for every data source (posts, custom data, links and
	 * sitemap entries): tokenize -> moderate -> (replace old chunks on update)
	 * -> insert chunk rows -> embed. The only things that vary between sources
	 * are how the owning post is resolved and what extra meta it carries, both
	 * expressed through `$args`.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $doc  The document, with `title` and `content` keys.
	 * @param array<string, mixed> $args {
	 *     Optional. Ingestion options.
	 *
	 *     @type string               $action             'add' or 'update'. Default 'add'.
	 *     @type bool                 $override           Skip the moderation gate. Default false.
	 *     @type int|null             $post_id            Existing post to attach chunks to. Required
	 *                                                    for the posts source and for any update.
	 *     @type bool                 $create             Create (add) or update a managed `hyve_docs`
	 *                                                    post. False for the posts source, where the
	 *                                                    WP post already exists and is left untouched.
	 *                                                    Default false.
	 *     @type string               $post_type          Post type to create when `create` is true.
	 *                                                    Default 'hyve_docs'.
	 *     @type array<string, mixed> $meta               Extra post meta to set on success.
	 *     @type bool                 $persist_moderation Store/clear `_hyve_moderation_*` meta on
	 *                                                    `post_id`. Default false.
	 *     @type bool                 $retry_async        Retry a failed embedding in the background
	 *                                                    via cron. When false, failures are terminal
	 *                                                    and recorded for immediate, synchronous
	 *                                                    surfacing to the caller. Default true.
	 * }
	 *
	 * @return true|\WP_Error
	 * @throws \Exception If Qdrant API fails.
	 */
	public function ingest_document( $doc, $args = [] ) {
		// Connect mode ships the whole document to the platform, which chunks,
		// moderates, embeds, and stores it. No local chunking/embedding/rows.
		if ( Hyve_Connect::is_active() ) {
			return $this->ingest_document_connect( $doc, $args );
		}

		$action             = $args['action'] ?? 'add';
		$override           = ! empty( $args['override'] );
		$create             = ! empty( $args['create'] );
		$post_id            = $args['post_id'] ?? null;
		$post_type          = $args['post_type'] ?? 'hyve_docs';
		$extra_meta         = $args['meta'] ?? [];
		$persist_moderation = ! empty( $args['persist_moderation'] );
		$allow_retry        = $args['retry_async'] ?? true;

		$data   = Tokenizer::tokenize(
			[
				'ID'      => $post_id,
				'title'   => $doc['title'],
				'content' => $doc['content'],
			]
		);
		$chunks = array_column( $data, 'post_content' );
		// Only reuse the per-post moderation cache for an existing WP post
		// (the posts source). Freshly submitted content is always re-moderated.
		$moderation = OpenAI::instance()->moderate_chunks( $chunks, $persist_moderation ? $post_id : null );

		if ( is_wp_error( $moderation ) ) {
			return $moderation;
		}

		if ( true !== $moderation && ! $override ) {
			if ( $persist_moderation && $post_id ) {
				update_post_meta( $post_id, '_hyve_moderation_failed', 1 );
				update_post_meta( $post_id, '_hyve_moderation_review', $moderation );
			}

			return new \WP_Error(
				'content_failed_moderation',
				__( 'The content failed moderation policies.', 'hyve-lite' ),
				[ 'review' => $moderation ]
			);
		}

		// Resolve the owning post. Moderation has passed, so creating/updating a
		// managed post here cannot leave an orphan behind on rejection.
		if ( 'update' === $action ) {
			if ( Qdrant_API::is_active() ) {
				try {
					$delete_result = Qdrant_API::instance()->delete_point( $post_id );

					if ( is_wp_error( $delete_result ) || ! $delete_result ) {
						throw new \Exception( is_wp_error( $delete_result ) ? $delete_result->get_error_message() : __( 'Failed to delete point in Qdrant.', 'hyve-lite' ) );
					}
				} catch ( \Exception $e ) {
					return new \WP_Error( 'qdrant_error', $e->getMessage() );
				}
			}

			$this->delete_by_post_id( $post_id );

			if ( $create ) {
				$updated = wp_update_post(
					[
						'ID'           => $post_id,
						'post_title'   => $doc['title'],
						'post_content' => $doc['content'],
					]
				);

				if ( ! $updated ) {
					return new \WP_Error( 'failed_update_post', __( 'Failed to update post.', 'hyve-lite' ) );
				}
			}
		} elseif ( $create ) {
			$post_id = wp_insert_post(
				[
					'post_title'   => $doc['title'],
					'post_content' => $doc['content'],
					'post_status'  => 'publish',
					'post_type'    => $post_type,
				]
			);

			if ( ! $post_id ) {
				return new \WP_Error( 'failed_insert_post', __( 'Failed to insert post.', 'hyve-lite' ) );
			}
		}

		$processing_error = null;

		foreach ( $data as $datum ) {
			$datum['post_id'] = $post_id;

			$id     = $this->insert( $datum );
			$result = $this->process_post( $id, $allow_retry );

			if ( is_wp_error( $result ) && null === $processing_error ) {
				$processing_error = $result;
			}
		}

		update_post_meta( $post_id, '_hyve_added', 1 );

		foreach ( $extra_meta as $meta_key => $meta_value ) {
			update_post_meta( $post_id, $meta_key, $meta_value );
		}

		// When retries are disabled a chunk failure is terminal, so record the
		// reason against the post. This also restores the error if a later
		// chunk's success cleared it mid-loop, keeping the surfaced reason honest.
		if ( ! $allow_retry && null !== $processing_error ) {
			$this->record_processing_error( $post_id, $processing_error, false );
		}

		if ( $persist_moderation ) {
			delete_post_meta( $post_id, '_hyve_moderation_failed' );
			delete_post_meta( $post_id, '_hyve_moderation_review' );
			delete_post_meta( $post_id, '_hyve_needs_update' );
			$this->delete_cache( 'cached_embeddings' );
		}

		return true;
	}

	/**
	 * Ingest a document via Hyve Connect.
	 *
	 * The hosted counterpart to the local pipeline: the whole document goes to
	 * `hyve-kb upsert` (the platform chunks/moderates/embeds/stores), and the
	 * post keeps the same `_hyve_*` bookkeeping the KB listing already reads, so
	 * both modes look identical in the UI. No local chunk rows or vectors.
	 *
	 * @param array<string, mixed> $doc  The document, with `title` and `content`.
	 * @param array<string, mixed> $args Ingestion options (see ingest_document()).
	 *
	 * @return true|\WP_Error
	 */
	private function ingest_document_connect( $doc, $args ) {
		$action             = $args['action'] ?? 'add';
		$create             = ! empty( $args['create'] );
		$post_id            = $args['post_id'] ?? null;
		$post_type          = $args['post_type'] ?? 'hyve_docs';
		$extra_meta         = $args['meta'] ?? [];
		$persist_moderation = ! empty( $args['persist_moderation'] );

		// Resolve the owning post. Managed sources (links, custom text, sitemap)
		// create/update a hyve_docs post; the posts source already has one. A
		// source created here that the platform then rejects is cleaned up so no
		// orphan is left behind, mirroring the local path's moderate-then-create.
		$created_here = false;

		if ( 'update' === $action && $create && $post_id ) {
			$updated = wp_update_post(
				[
					'ID'           => $post_id,
					'post_title'   => $doc['title'],
					'post_content' => $doc['content'],
				]
			);

			if ( ! $updated ) {
				return new \WP_Error( 'failed_update_post', __( 'Failed to update post.', 'hyve-lite' ) );
			}
		} elseif ( $create ) {
			$post_id = wp_insert_post(
				[
					'post_title'   => $doc['title'],
					'post_content' => $doc['content'],
					'post_status'  => 'publish',
					'post_type'    => $post_type,
				]
			);

			if ( ! $post_id ) {
				return new \WP_Error( 'failed_insert_post', __( 'Failed to insert post.', 'hyve-lite' ) );
			}

			$created_here = true;
		}

		if ( ! $post_id ) {
			return new \WP_Error( 'missing_post', __( 'Missing post reference.', 'hyve-lite' ) );
		}

		$document = $this->connect_document( (int) $post_id, $doc );
		$result   = Hyve_Connect::instance()->kb_upsert( [ $document ] );

		if ( is_wp_error( $result ) ) {
			if ( $created_here ) {
				wp_delete_post( $post_id, true );
			}

			return $result;
		}

		$status = isset( $result['results'][0] ) && is_array( $result['results'][0] ) ? $result['results'][0] : [];
		$state  = $status['status'] ?? 'failed';

		// Did not fit the plan's remaining budget (the platform skips instead
		// of refusing); surface it as the usual limit message.
		if ( 'skipped' === $state ) {
			if ( $created_here ) {
				wp_delete_post( $post_id, true );
			}

			return new \WP_Error(
				'hyve_connect_quota_exceeded',
				__( 'You have reached your Hyve Connect limit for now. Upgrade your plan for more.', 'hyve-lite' )
			);
		}

		if ( 'stored' !== $state ) {
			$review = $this->connect_moderation_review( $status );

			// A real post keeps its moderation meta for the listing; an orphan
			// created here for a rejected managed source is removed instead.
			// On a retained rejection the platform kept the previously-synced
			// copy, and the synced hash from that sync already describes it.
			if ( 'rejected' === $state && $persist_moderation && ! $created_here ) {
				update_post_meta( $post_id, '_hyve_moderation_failed', 1 );
				update_post_meta( $post_id, '_hyve_moderation_review', $review );
			}

			if ( $created_here ) {
				wp_delete_post( $post_id, true );
			}

			if ( 'rejected' === $state ) {
				return new \WP_Error(
					'content_failed_moderation',
					__( 'The content failed moderation policies.', 'hyve-lite' ),
					[ 'review' => $review ]
				);
			}

			return new \WP_Error( 'connect_index_failed', __( 'Hyve Connect could not index this content.', 'hyve-lite' ) );
		}

		update_post_meta( $post_id, '_hyve_added', 1 );
		// The synced hash marks the source as on the platform (so the sync job
		// skips it) and feeds cheap reconcile fingerprints.
		$this->connect_store_synced_hash( (int) $post_id, hash( 'sha256', (string) $document['content'] ) );

		foreach ( $extra_meta as $meta_key => $meta_value ) {
			update_post_meta( $post_id, $meta_key, $meta_value );
		}

		if ( $persist_moderation ) {
			delete_post_meta( $post_id, '_hyve_moderation_failed' );
			delete_post_meta( $post_id, '_hyve_moderation_review' );
			delete_post_meta( $post_id, '_hyve_needs_update' );
		}

		Hyve_Connect::flush_stats();

		return true;
	}

	/**
	 * Build the contract-shaped document for a Hyve Connect upsert.
	 *
	 * @param int                  $post_id The owning post id (site-scoped source id).
	 * @param array<string, mixed> $doc     The document, with `title` and `content`.
	 *
	 * @return array<string, mixed>
	 */
	private function connect_document( $post_id, $doc ) {
		$url = get_permalink( $post_id );

		return [
			'id'      => $post_id,
			'type'    => $this->connect_source_type( $post_id ),
			'title'   => (string) $doc['title'],
			'url'     => $url ? $url : null,
			// The plugin extracts text; the platform chunks it.
			'content' => wp_strip_all_tags( (string) $doc['content'] ),
		];
	}

	/**
	 * Map a WordPress post type to a Hyve Connect source type.
	 *
	 * @param int $post_id The post id.
	 *
	 * @return string One of post|page|product|doc.
	 */
	private function connect_source_type( $post_id ) {
		$map = [
			'page'      => 'page',
			'product'   => 'product',
			'hyve_docs' => 'doc',
		];

		$type = (string) get_post_type( $post_id );

		return $map[ $type ] ?? 'post';
	}

	/**
	 * Translate a platform `rejected` result's categories into the local
	 * `_hyve_moderation_review` shape (category => score).
	 *
	 * @param array<string, mixed> $status A single upsert result.
	 *
	 * @return array<string, float>
	 */
	private function connect_moderation_review( $status ) {
		$categories = isset( $status['moderation']['categories'] ) && is_array( $status['moderation']['categories'] )
			? $status['moderation']['categories']
			: [];

		$review = [];

		foreach ( $categories as $category ) {
			$review[ (string) $category ] = 1.0;
		}

		return $review;
	}

	/**
	 * Posts indexed locally but not yet pushed to Hyve Connect.
	 *
	 * These carry the KB bookkeeping meta (`_hyve_added`) but the platform does
	 * not yet hold them (no synced hash): the existing self-hosted content to
	 * migrate on enable, or everything after an inactivity purge.
	 *
	 * @param int $limit Max posts to return (-1 for all).
	 *
	 * @return array<int> Post ids.
	 */
	public function connect_pending_posts( $limit = self::CONNECT_SYNC_BATCH ) {
		return get_posts(
			[
				'post_type'      => $this->connect_indexed_post_types(),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off migration read, not a hot path.
				'meta_query'     => [
					[
						'key'     => '_hyve_added',
						'compare' => 'EXISTS',
					],
					[
						'key'     => '_hyve_connect_synced_hash',
						'compare' => 'NOT EXISTS',
					],
					// A source the platform rejected on moderation, or one that
					// failed to index (no text content), stays out of the pending
					// set so the batch never re-picks it forever.
					[
						'key'     => '_hyve_moderation_failed',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => '_hyve_processing_error',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
	}

	/**
	 * How many sources still need pushing to Hyve Connect.
	 *
	 * @return int
	 */
	public function connect_pending_count() {
		return count( $this->connect_pending_posts( -1 ) );
	}

	/**
	 * Whether any source is currently believed to live on Hyve Connect.
	 *
	 * @return bool
	 */
	public function connect_has_synced() {
		$synced = get_posts(
			[
				'post_type'      => $this->connect_indexed_post_types(),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_hyve_connect_synced_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);

		return ! empty( $synced );
	}

	/**
	 * Begin (or resume) pushing existing local content to Hyve Connect.
	 *
	 * Called when a site with indexed content switches into Connect mode, and by
	 * the purge auto-recovery path. Seeds the progress option and schedules the
	 * first cron run; a no-op when there is nothing pending.
	 *
	 * @return void
	 */
	public function connect_start_migration() {
		$pending = $this->connect_pending_count();

		if ( 0 === $pending ) {
			return;
		}

		update_option(
			self::CONNECT_SYNC_OPTION,
			[
				'total'       => $pending,
				'current'     => 0,
				'in_progress' => true,
				'blocked'     => false,
				'message'     => '',
			]
		);

		Hyve_Connect::flush_stats();
		wp_schedule_single_event( time(), self::CONNECT_SYNC_HOOK );
	}

	/**
	 * Cron handler: push one batch of pending sources to Hyve Connect.
	 *
	 * Builds whole documents from the posts we still hold (title + content) so a
	 * re-sync never depends on local chunk rows, upserts them, then marks each
	 * accepted source synced and drops its now-redundant local chunk rows (the
	 * platform owns the content in Connect mode). Reschedules until drained.
	 *
	 * @return void
	 */
	public function connect_migrate_data() {
		if ( ! Hyve_Connect::is_active() ) {
			$this->connect_finish_migration();
			return;
		}

		$post_ids = $this->connect_pending_posts();

		if ( empty( $post_ids ) ) {
			$this->connect_finish_migration();
			return;
		}

		$documents = [];
		$empty     = 0;

		foreach ( $post_ids as $post_id ) {
			// Keyed by post id so the success loop can recover each sent hash.
			$document = $this->connect_document(
				(int) $post_id,
				[
					'title'   => get_the_title( $post_id ),
					'content' => get_post_field( 'post_content', $post_id ),
				]
			);

			// No text to index (media-only content, empty page): terminal, the
			// platform has nothing to store and moderation rejects empty input.
			if ( '' === trim( (string) $document['content'] ) ) {
				$this->record_processing_error(
					(int) $post_id,
					new \WP_Error( 'connect_empty_content', __( 'There is no text content to index.', 'hyve-lite' ) ),
					false
				);
				++$empty;
				continue;
			}

			$documents[ (int) $post_id ] = $document;
		}

		if ( empty( $documents ) ) {
			$this->connect_advance_migration( $empty );
			return;
		}

		$result = Hyve_Connect::instance()->kb_upsert( $documents );

		if ( is_wp_error( $result ) ) {
			// Over the plan's storage/churn cap: retrying will not help until the
			// user upgrades or trims content, so stop and surface the block.
			if ( false !== strpos( $result->get_error_code(), 'quota_exceeded' ) ) {
				$data = $result->get_error_data();

				$this->connect_block_migration(
					$result->get_error_message(),
					is_array( $data ) && isset( $data['quota'] ) && is_array( $data['quota'] ) ? $data['quota'] : []
				);
				return;
			}

			// Transient failure (unreachable/provider): back off and retry.
			wp_schedule_single_event( time() + 30, self::CONNECT_SYNC_HOOK );
			return;
		}

		$status_by_id = [];

		foreach ( ( isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : [] ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$status_by_id[ (int) $row['id'] ] = $row;
			}
		}

		$handled      = 0;
		$storage_skip = false;

		foreach ( array_keys( $documents ) as $post_id ) {
			$row   = $status_by_id[ (int) $post_id ] ?? [];
			$state = isset( $row['status'] ) ? $row['status'] : 'stored';

			// Did not fit the plan's remaining budget: stays pending for a
			// later batch, or the block below when nothing fits anymore.
			if ( 'skipped' === $state ) {
				$storage_skip = $storage_skip || 'storage' === ( $row['reason'] ?? '' );
				continue;
			}

			++$handled;

			// Either way the local chunk rows are redundant in Connect mode.
			$this->delete_by_post_id( $post_id );

			// Flag the rejection for the KB listing; a retained prior sync keeps
			// its existing hash, a brand-new rejected source stays unsynced.
			if ( 'rejected' === $state ) {
				update_post_meta( $post_id, '_hyve_moderation_failed', 1 );
				update_post_meta( $post_id, '_hyve_moderation_review', $this->connect_moderation_review( $row ) );

				continue;
			}

			// The platform could not index it (nothing extractable, provider
			// failure): surface the error and stop re-picking the source.
			if ( 'failed' === $state ) {
				$this->record_processing_error(
					$post_id,
					new \WP_Error( 'connect_index_failed', __( 'Hyve Connect could not index this content.', 'hyve-lite' ) ),
					false
				);

				continue;
			}

			// The synced hash marks the source as on the platform.
			$doc_content = isset( $documents[ (int) $post_id ]['content'] ) ? (string) $documents[ (int) $post_id ]['content'] : '';
			$this->connect_store_synced_hash( (int) $post_id, hash( 'sha256', $doc_content ) );
		}

		// The platform fills a batch greedily, so a batch where nothing fit
		// means nothing more will: same terminal state as a refused batch.
		// Empty-content sources handled locally still count as progress.
		if ( 0 === $handled && 0 === $empty ) {
			$storage = isset( $result['kb']['storage'] ) && is_array( $result['kb']['storage'] ) ? $result['kb']['storage'] : [];

			$this->connect_block_migration(
				$storage_skip
					? __( 'Knowledge base storage quota exceeded.', 'hyve-lite' )
					: __( 'Knowledge base indexing limit reached for this period.', 'hyve-lite' ),
				$storage_skip
					? [
						'kind'  => 'storage',
						'limit' => isset( $storage['limit'] ) ? (int) $storage['limit'] : 0,
						'used'  => isset( $storage['used'] ) ? (int) $storage['used'] : 0,
					]
					: [ 'kind' => 'indexing' ]
			);
			return;
		}

		$this->connect_advance_migration( $handled + $empty );
	}

	/**
	 * Record progress after a batch and reschedule if any sources remain.
	 *
	 * @param int $done Sources handled in the batch just finished.
	 *
	 * @return void
	 */
	private function connect_advance_migration( $done ) {
		$status = get_option( self::CONNECT_SYNC_OPTION, [] );

		if ( empty( $status ) ) {
			return;
		}

		$status['current']     = (int) ( $status['current'] ?? 0 ) + (int) $done;
		$has_more              = $this->connect_pending_count() > 0;
		$status['in_progress'] = $has_more;

		update_option( self::CONNECT_SYNC_OPTION, $status );
		Hyve_Connect::flush_stats();

		if ( $has_more ) {
			wp_schedule_single_event( time() + 10, self::CONNECT_SYNC_HOOK );
		}
	}

	/**
	 * Mark the sync job complete (nothing left to push).
	 *
	 * @return void
	 */
	private function connect_finish_migration() {
		$status = get_option( self::CONNECT_SYNC_OPTION, [] );

		if ( empty( $status ) ) {
			return;
		}

		$status['in_progress'] = false;
		update_option( self::CONNECT_SYNC_OPTION, $status );
		Hyve_Connect::flush_stats();
	}

	/**
	 * Stop the sync job because the plan's limit was hit, recording the reason
	 * and the quota numbers at block time (so auto-resume can tell "something
	 * changed" from "still does not fit").
	 *
	 * @param string               $message The platform's limit message.
	 * @param array<string, mixed> $quota   The platform's quota snapshot ({kind, limit, used}).
	 *
	 * @return void
	 */
	private function connect_block_migration( $message, $quota = [] ) {
		$status = get_option( self::CONNECT_SYNC_OPTION, [] );

		$status['in_progress'] = false;
		$status['blocked']     = true;
		$status['message']     = (string) $message;
		$status['quota']       = is_array( $quota ) ? $quota : [];

		update_option( self::CONNECT_SYNC_OPTION, $status );
		Hyve_Connect::flush_stats();
	}

	/**
	 * Current to-Connect sync job status (for the UI progress state).
	 *
	 * @return array<string, mixed>
	 */
	public function connect_migration_status() {
		$status = get_option( self::CONNECT_SYNC_OPTION, [] );

		return is_array( $status ) ? $status : [];
	}

	/**
	 * Forget which sources live on Hyve Connect (disconnect, or before re-sync).
	 *
	 * @return void
	 */
	public function connect_reset_sync_markers() {
		$posts = get_posts(
			[
				'post_type'      => $this->connect_indexed_post_types(),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => '_hyve_connect_synced_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);

		foreach ( $posts as $post_id ) {
			delete_post_meta( $post_id, '_hyve_connect_synced_hash' );
			delete_post_meta( $post_id, '_hyve_connect_synced_modified' );
		}
	}

	/**
	 * Resume a plan-blocked sync once the quota numbers have actually changed
	 * (upgrade, freed space on another site, a new usage window).
	 *
	 * Cheap on every admin load: reads the cached stats, so the platform is
	 * consulted at most once per cache window. Both storage and the indexing
	 * windows must have headroom, or resuming would immediately re-block. For
	 * a storage block the numbers must also have MOVED since the block (cap
	 * raised or space freed): leftover room alone means the knowledge base
	 * simply does not fit, and resuming would retry-loop against the cap.
	 *
	 * @return void
	 */
	public function connect_maybe_resume_blocked() {
		$status = $this->connect_migration_status();

		if ( empty( $status['blocked'] ) ) {
			return;
		}

		$stats   = Hyve_Connect::instance()->stats();
		$storage = isset( $stats['kb']['storage'] ) && is_array( $stats['kb']['storage'] ) ? $stats['kb']['storage'] : [];
		$limit   = isset( $storage['limit'] ) ? (int) $storage['limit'] : 0;
		$used    = isset( $storage['used'] ) ? (int) $storage['used'] : 0;

		if ( $limit <= 0 || $used >= $limit ) {
			return;
		}

		$windows = isset( $stats['indexing']['windows'] ) && is_array( $stats['indexing']['windows'] ) ? $stats['indexing']['windows'] : [];

		foreach ( $windows as $window ) {
			if ( isset( $window['remaining'] ) && (int) $window['remaining'] <= 0 ) {
				return;
			}
		}

		$snapshot = isset( $status['quota'] ) && is_array( $status['quota'] ) ? $status['quota'] : [];

		if ( isset( $snapshot['kind'] ) && 'storage' === $snapshot['kind'] ) {
			$snap_limit = isset( $snapshot['limit'] ) ? (int) $snapshot['limit'] : 0;
			$snap_used  = isset( $snapshot['used'] ) ? (int) $snapshot['used'] : 0;

			if ( $limit <= $snap_limit && $used >= $snap_used ) {
				return;
			}
		}

		delete_option( self::CONNECT_SYNC_OPTION );
		$this->connect_start_migration();
	}

	/**
	 * Re-push local content when Hyve Connect has lost it (inactivity purge).
	 *
	 * Compares what we believe is synced against the platform's reported KB
	 * state; if the platform is empty/purged while we still hold synced sources,
	 * clears the stale markers and restarts the sync from the posts we keep.
	 *
	 * @return void
	 */
	public function connect_check_recovery() {
		if ( ! Hyve_Connect::is_active() ) {
			return;
		}

		$status = $this->connect_migration_status();

		// Don't stack recovery on an in-flight migration. A plan-blocked one
		// does not stop it: an empty account (e.g. right after an upgrade)
		// still needs the full re-sync, which re-blocks if the cap holds.
		if ( ! empty( $status['in_progress'] ) ) {
			return;
		}

		if ( ! $this->connect_has_synced() ) {
			return;
		}

		$stats = Hyve_Connect::instance()->stats( true );
		$state = isset( $stats['kb']['state'] ) ? $stats['kb']['state'] : '';

		if ( in_array( $state, [ 'empty', 'purged' ], true ) ) {
			$this->connect_reset_sync_markers();
			$this->connect_start_migration();
		}
	}

	/**
	 * Content hash of one source, computed exactly as the upsert path sends it
	 * (via connect_document), so it matches the platform's stored content_hash.
	 *
	 * @param int $post_id The source post id.
	 *
	 * @return string sha256 of the sent content.
	 */
	public function connect_source_hash( $post_id ) {
		$doc = $this->connect_document(
			(int) $post_id,
			[
				'title'   => get_the_title( $post_id ),
				'content' => get_post_field( 'post_content', $post_id ),
			]
		);

		return hash( 'sha256', (string) $doc['content'] );
	}

	/**
	 * Record the hash a source last synced to the cloud, plus the post's modified
	 * time then, so the manifest can reuse it while the post is unchanged.
	 *
	 * @param int    $post_id The source post id.
	 * @param string $hash    Hash of the content that was synced.
	 *
	 * @return void
	 */
	private function connect_store_synced_hash( $post_id, $hash ) {
		update_post_meta( (int) $post_id, '_hyve_connect_synced_hash', $hash );
		update_post_meta( (int) $post_id, '_hyve_connect_synced_modified', (int) get_post_modified_time( 'U', true, $post_id ) );
	}

	/**
	 * Post types a KB source can live under. Listed explicitly because hyve_docs
	 * is registered exclude_from_search, which the "any" pseudo-type skips.
	 *
	 * @return array<string>
	 */
	public function connect_indexed_post_types() {
		$types              = get_post_types( [ 'exclude_from_search' => false ] );
		$types['hyve_docs'] = 'hyve_docs';

		return array_values( $types );
	}

	/**
	 * Every local source that belongs on Hyve Connect, as a {id, hash} manifest
	 * for reconcile. The hash is the cached last-synced hash while the post is
	 * unchanged (no re-hash), or the current content hash once it changes. A
	 * moderation-failed source is listed at its last-synced hash when it was ever
	 * synced (its kept cloud copy), and skipped otherwise.
	 *
	 * @return array<array{id: int, hash: string}>
	 */
	public function connect_local_manifest() {
		$post_ids = get_posts(
			[
				'post_type'      => $this->connect_indexed_post_types(),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => '_hyve_added', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- reconcile read, not a hot path.
			]
		);

		$manifest = [];

		foreach ( $post_ids as $post_id ) {
			$post_id     = (int) $post_id;
			$synced_hash = (string) get_post_meta( $post_id, '_hyve_connect_synced_hash', true );
			$flagged     = '' !== (string) get_post_meta( $post_id, '_hyve_moderation_failed', true );

			if ( $flagged ) {
				// Keep the last-good copy that is still on the cloud; a never-synced
				// rejected source is not on the cloud, so leave it out.
				if ( '' === $synced_hash ) {
					continue;
				}

				$manifest[] = [
					'id'   => $post_id,
					'hash' => $synced_hash,
				];
				continue;
			}

			$synced_modified  = (int) get_post_meta( $post_id, '_hyve_connect_synced_modified', true );
			$current_modified = (int) get_post_modified_time( 'U', true, $post_id );

			$hash = ( '' !== $synced_hash && $synced_modified === $current_modified )
				? $synced_hash
				: $this->connect_source_hash( $post_id );

			$manifest[] = [
				'id'   => $post_id,
				'hash' => $hash,
			];
		}

		return $manifest;
	}

	/**
	 * Reconcile the hosted KB against local (the source of truth).
	 *
	 * Reconcile the cloud KB with local content via a drill-down: root aggregate
	 * -> differing buckets -> scoped manifest. The cloud deletes orphans; the
	 * plugin re-pushes stale/missing sources through the migration job.
	 *
	 * @return true|\WP_Error True when reconciled (or already in sync).
	 */
	public function connect_reconcile() {
		if ( ! Hyve_Connect::is_active() ) {
			return new \WP_Error( 'connect_inactive', __( 'Hyve Connect is not active.', 'hyve-lite' ) );
		}

		$status = $this->connect_migration_status();

		if ( ! empty( $status['in_progress'] ) ) {
			return new \WP_Error( 'connect_busy', __( 'A sync is already in progress.', 'hyve-lite' ) );
		}

		// A manual Sync retries a plan-blocked job. Since the platform admits
		// documents greedily (skips cost nothing), the retry is one cheap
		// round: it stores whatever fits and re-blocks with fresh numbers if
		// nothing does.
		if ( ! empty( $status['blocked'] ) ) {
			delete_option( self::CONNECT_SYNC_OPTION );
		}

		$manifest = $this->connect_local_manifest();
		$connect  = Hyve_Connect::instance();

		// 1. Root check: one aggregate. Match -> nothing to do.
		$root = $connect->kb_reconcile( [], Hyve_Connect::kb_aggregate( $manifest ) );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		if ( ! empty( $root['in_sync'] ) ) {
			return true;
		}

		// 2. Bucket compare: find which slices differ.
		$compare = $connect->kb_reconcile( [], null, Hyve_Connect::kb_bucket_hashes( $manifest ) );

		if ( is_wp_error( $compare ) ) {
			return $compare;
		}

		$differing = ( isset( $compare['differing_buckets'] ) && is_array( $compare['differing_buckets'] ) )
			? array_map( 'intval', $compare['differing_buckets'] )
			: [];

		if ( empty( $differing ) ) {
			return true;
		}

		// 3. Scoped detail: send only the differing buckets' sources. The cloud
		// deletes orphans within those buckets and returns what to re-push.
		$scope  = array_flip( $differing );
		$scoped = array_values(
			array_filter(
				$manifest,
				function ( $entry ) use ( $scope ) {
					return isset( $scope[ Hyve_Connect::kb_bucket_of( $entry['id'] ) ] );
				}
			)
		);

		$diff = $connect->kb_reconcile( $scoped, null, [], $differing );

		if ( is_wp_error( $diff ) ) {
			return $diff;
		}

		// Stale (changed) + missing (never landed) -> re-push. Unmark them so the
		// migration job picks exactly these up, then kick it.
		$to_push = array_merge(
			isset( $diff['stale'] ) ? $diff['stale'] : [],
			isset( $diff['missing'] ) ? $diff['missing'] : []
		);

		foreach ( $to_push as $id ) {
			delete_post_meta( (int) $id, '_hyve_connect_synced_hash' );
			delete_post_meta( (int) $id, '_hyve_connect_synced_modified' );
		}

		if ( ! empty( $to_push ) ) {
			$this->connect_start_migration();
		}

		return true;
	}

	/**
	 * Process posts.
	 * 
	 * @since 1.2.0
	 * 
	 * @param int  $id          The post ID.
	 * @param bool $allow_retry Whether a transient failure may be retried
	 *                          asynchronously via the hyve_process_post cron.
	 *                          When false, a failure is terminal and returned to
	 *                          the caller for immediate, synchronous handling.
	 *                          Default true.
	 *
	 * @return true|\WP_Error True on success, or the error encountered.
	 */
	public function process_post( $id, $allow_retry = true ) {
		$post     = $this->get( $id );
		$content  = $post->post_content;
		$openai   = OpenAI::instance();
		$stripped = wp_strip_all_tags( $content );

		// Prepend the title to the text sent for embedding so it contributes to the vector.
		if ( ! empty( $post->post_title ) ) {
			$stripped = $post->post_title . ' ' . $stripped;
		}
		$embeddings = $openai->create_embeddings( $stripped );

		if ( is_wp_error( $embeddings ) || ! $embeddings ) {
			$error = is_wp_error( $embeddings )
				? $embeddings
				: new \WP_Error( 'unknown_error', __( 'An unexpected error occurred while indexing this content.', 'hyve-lite' ) );

			$this->handle_processing_failure( $id, (int) $post->post_id, $error, $allow_retry );
			return $error;
		}

		$embeddings = reset( $embeddings );
		$embeddings = $embeddings->embedding;
		$storage    = 'WordPress';

		if ( Qdrant_API::is_active() ) {
			try {
				$success = Qdrant_API::instance()->add_point(
					$embeddings,
					[
						'post_id'      => $post->post_id,
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
						'token_count'  => $post->token_count,
						'website_url'  => get_site_url(),
					]
				);

				$storage = 'Qdrant';
			} catch ( \Exception $e ) {
				$success = new \WP_Error( 'qdrant_error', $e->getMessage() );
			}

			if ( is_wp_error( $success ) ) {
				$this->handle_processing_failure( $id, (int) $post->post_id, $success, $allow_retry );
				return $success;
			}
		}

		$embeddings = wp_json_encode( $embeddings );

		$this->update(
			$id,
			[
				'embeddings'  => $embeddings,
				'post_status' => 'processed',
				'storage'     => $storage,
			]
		);

		// Processing succeeded; clear any error and retry counter from a previous attempt.
		delete_post_meta( (int) $post->post_id, '_hyve_processing_error' );
		delete_transient( self::CACHE_PREFIX . 'process_attempts_' . $id );

		return true;
	}

	/**
	 * Handle a failed processing attempt.
	 *
	 * Fatal errors (bad key, no credits, billing) will not resolve by retrying,
	 * so they stop immediately and the entry is marked failed. Transient errors
	 * (rate limits, network blips) are retried with a linear backoff up to
	 * MAX_PROCESS_ATTEMPTS, then also marked failed. Either way the reason is
	 * recorded for the Knowledge Base UI. See Codeinwp/hyve#199.
	 *
	 * @since 1.4.0
	 *
	 * @param int       $id          The chunk row ID.
	 * @param int       $post_id     The source post ID.
	 * @param \WP_Error $error       The error encountered while processing.
	 * @param bool      $allow_retry Whether an async retry may be scheduled. When
	 *                               false the failure is terminal. Default true.
	 *
	 * @return void
	 */
	private function handle_processing_failure( $id, $post_id, $error, $allow_retry = true ) {
		$transient  = self::CACHE_PREFIX . 'process_attempts_' . $id;
		$attempts   = (int) get_transient( $transient ) + 1;
		$is_fatal   = OpenAI::is_fatal_error_code( $error->get_error_code() );
		$will_retry = $allow_retry && ! $is_fatal && $attempts < self::MAX_PROCESS_ATTEMPTS;

		$this->record_processing_error( $post_id, $error, $will_retry );

		if ( $will_retry ) {
			set_transient( $transient, $attempts, DAY_IN_SECONDS );
			wp_schedule_single_event( time() + ( MINUTE_IN_SECONDS * $attempts ), 'hyve_process_post', [ $id ] );
			return;
		}

		// Terminal: stop retrying and mark the chunk failed.
		delete_transient( $transient );
		$this->update( $id, [ 'post_status' => 'failed' ] );
	}

	/**
	 * Record a processing error against the source post so it can be surfaced
	 * in the Knowledge Base UI instead of failing silently. The stored message
	 * includes whether the entry will be retried or needs the admin to act.
	 * See Codeinwp/hyve#199.
	 *
	 * @since 1.4.0
	 *
	 * @param int       $post_id    The source post ID.
	 * @param \WP_Error $error      The error encountered while processing.
	 * @param bool      $will_retry Whether another attempt is scheduled.
	 *
	 * @return void
	 */
	private function record_processing_error( $post_id, $error, $will_retry ) {
		if ( empty( $post_id ) ) {
			return;
		}

		$message = OpenAI::get_error_message_for_code( $error->get_error_code() );

		if ( null === $message ) {
			$message = $error->get_error_message();
		}

		$suffix = $will_retry
			? __( 'It will be retried automatically.', 'hyve-lite' )
			: __( 'Please fix the problem and re-add this content.', 'hyve-lite' );

		update_post_meta( $post_id, '_hyve_processing_error', $message . ' ' . $suffix );
	}

	/**
	 * Update posts.
	 * 
	 * @since 1.3.1
	 * 
	 * @return void
	 */
	public function update_posts() {
		$args = [
			'post_type'      => 'any',
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => '_hyve_needs_update',
					'value'   => '1',
					'compare' => '=',
				],
				[
					'key'     => '_hyve_moderation_failed',
					'compare' => 'NOT EXISTS',
				],
			],
		];

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return;
		}

		$posts = $query->posts;

		foreach ( $posts as $post_id ) {
			/**
			 * The post id.
			 * 
			 * @var int $post_id
			 */
			$this->add_post( $post_id, 'update' );
		}

		wp_schedule_single_event( time() + 60, 'hyve_update_posts' );
	}

	/**
	 * Delete posts.
	 * 
	 * @since 1.3.0
	 * 
	 * @param array<int> $posts The posts.
	 * 
	 * @return void
	 */
	public function delete_posts( array $posts ): void {
		$twenty = array_slice( $posts, 0, 20 );

		if ( Hyve_Connect::is_active() && ! empty( $twenty ) ) {
			Hyve_Connect::instance()->kb_delete( $twenty );
		}

		foreach ( $twenty as $id ) {
			$this->delete_by_post_id( $id );
	
			delete_post_meta( $id, '_hyve_added' );
			delete_post_meta( $id, '_hyve_needs_update' );
			delete_post_meta( $id, '_hyve_moderation_failed' );
			delete_post_meta( $id, '_hyve_moderation_review' );
		}

		if ( ! empty( $twenty ) ) {
			$this->delete_cache( 'cached_embeddings' );
		}

		$has_more = count( $posts ) > 20;

		if ( $has_more ) {
			wp_schedule_single_event( time() + 10, 'hyve_delete_posts', [ array_slice( $posts, 20 ) ] );
		}
	}

	/**
	 * Get Total Rows Count.
	 * 
	 * @since 1.2.0
	 * 
	 * @return int
	 */
	public function get_count() {
		$cache = $this->get_cache( 'entries_count' );

		if ( false !== $cache ) {
			return $cache;
		}

		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table_name ) );

		$this->set_cache( 'entries_count', $count );

		return $count;
	}

	/**
	 * Get chunk counts for a set of posts.
	 *
	 * The full per-post map is cached and invalidated together with
	 * `entries_count`, so listings do not re-run the aggregate per page.
	 *
	 * @since 1.4.0
	 *
	 * @param array<int> $post_ids The post IDs.
	 *
	 * @return array<int, int> Chunk counts keyed by post ID.
	 */
	public function get_counts_by_post_ids( $post_ids ) {
		$post_ids = array_filter( array_map( 'intval', $post_ids ) );

		if ( empty( $post_ids ) ) {
			return [];
		}

		$counts = $this->get_cache( 'chunk_counts' );

		if ( ! is_array( $counts ) ) {
			global $wpdb;

			$results = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, COUNT(*) AS chunks FROM %i GROUP BY post_id', $this->table_name ) );

			$counts = [];

			foreach ( $results as $row ) {
				$counts[ intval( $row->post_id ) ] = intval( $row->chunks );
			}

			$this->set_cache( 'chunk_counts', $counts );
		}

		return array_intersect_key( $counts, array_flip( $post_ids ) );
	}

	/**
	 * Return cache.
	 * 
	 * @since 1.2.0
	 * 
	 * @param string $key The cache key.
	 * 
	 * @return mixed
	 */
	private function get_cache( $key ) {
		$key = $this->get_cache_key( $key );

		if ( $this->get_cache_key( 'entries_processed' ) === $key ) {
			$total = get_transient( $key . '_total' );

			if ( false === $total ) {
				return false;
			}

			$entries = [];

			for ( $i = 0; $i < $total; $i++ ) {
				$chunk_key = $key . '_' . $i;
				$chunk     = get_transient( $chunk_key );

				if ( false === $chunk ) {
					return false;
				}

				$entries = array_merge( $entries, $chunk );
			}

			return $entries;
		}

		return get_transient( $key );
	}

	/**
	 * Set cache.
	 * 
	 * @since 1.2.0
	 * 
	 * @param string $key The cache key.
	 * @param mixed  $value The cache value.
	 * @param int    $expiration The expiration time.
	 * 
	 * @return bool
	 */
	private function set_cache( $key, $value, $expiration = DAY_IN_SECONDS ) {
		$key = $this->get_cache_key( $key );

		if ( $this->get_cache_key( 'entries_processed' ) === $key ) {
			$chunks = array_chunk( $value, 50 );
			$total  = count( $chunks );

			foreach ( $chunks as $index => $chunk ) {
				$chunk_key = $key . '_' . $index;
				set_transient( $chunk_key, $chunk, $expiration );
			}

			set_transient( $key . '_total', $total, $expiration );
			return true;
		}
		return set_transient( $key, $value, $expiration );
	}

	/**
	 * Delete cache.
	 * 
	 * @since 1.2.0
	 * 
	 * @param string $key The cache key.
	 * 
	 * @return bool
	 */
	private function delete_cache( $key ) {
		if ( 'cached_embeddings' === $key ) {
			$cached_embeddings = $this->get_cache( $key );
			if ( is_array( $cached_embeddings ) ) {
				foreach ( $cached_embeddings as $cached_embedding_key ) {
					$this->delete_cache( $cached_embedding_key );
				}
			}
		}

		$key = $this->get_cache_key( $key );

		if ( $this->get_cache_key( 'entries_processed' ) === $key ) {
			$total = get_transient( $key . '_total' );

			if ( false === $total ) {
				return true;
			}

			for ( $i = 0; $i < $total; $i++ ) {
				$chunk_key = $key . '_' . $i;
				delete_transient( $chunk_key );
			}

			delete_transient( $key . '_total' );
			return true;
		}

		return delete_transient( $key );
	}

	/**
	 * Return cache key.
	 * 
	 * @since 1.2.0
	 * 
	 * @param string $key The cache key.
	 * 
	 * @return string
	 */
	private function get_cache_key( $key ) {
		return self::CACHE_PREFIX . $key;
	}
}
