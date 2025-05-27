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

		add_action( 'hyve_process_post', [ $this, 'process_post' ], 10, 1 );
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

		// @phpstan-ignore requireOnce.fileNotFound
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
	 * @return object
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
	 * @return array<object>
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
	 * @return array<object>
	 */
	public function get_by_storage( string $storage, int $limit = 100 ): array {
		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE storage = %s LIMIT %d', $this->table_name, $storage, $limit ) );
		return $results;
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
		$data = [
			'ID'      => $post_id,
			'title'   => get_the_title( $post_id ),
			'content' => apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) ),
		];

		$data       = Tokenizer::tokenize( $data );
		$chunks     = array_column( $data, 'post_content' );
		$moderation = OpenAI::instance()->moderate_chunks( $chunks, $post_id );

		if ( is_wp_error( $moderation ) ) {
			return $moderation;
		}

		if ( true !== $moderation && 'override' !== $action ) {
			update_post_meta( $post_id, '_hyve_moderation_failed', 1 );
			update_post_meta( $post_id, '_hyve_moderation_review', $moderation );

			return new \WP_Error(
				'content_failed_moderation',
				__( 'The content failed moderation policies.', 'hyve-lite' ),
				[ 'review' => $moderation ]
			);
		}

		if ( 'update' === $action ) {
			if ( Qdrant_API::is_active() ) {
				try {
					$delete_result = Qdrant_API::instance()->delete_point( $post_id );

					if ( ! $delete_result ) {
						throw new \Exception( __( 'Failed to delete point in Qdrant.', 'hyve-lite' ) );
					}
				} catch ( \Exception $e ) {
					return new \WP_Error( 'qdrant_error', $e->getMessage() );
				}
			}

			$this->delete_by_post_id( $post_id );
		}

		foreach ( $data as $datum ) {
			$id = $this->insert( $datum );
			$this->process_post( $id );
		}

		update_post_meta( $post_id, '_hyve_added', 1 );
		delete_post_meta( $post_id, '_hyve_moderation_failed' );
		delete_post_meta( $post_id, '_hyve_moderation_review' );
		delete_post_meta( $post_id, '_hyve_needs_update' );

		return true;
	}

	/**
	 * Process posts.
	 * 
	 * @since 1.2.0
	 * 
	 * @param int $id The post ID.
	 * 
	 * @return void
	 */
	public function process_post( $id ) {
		$post       = $this->get( $id );
		$content    = $post->post_content;
		$openai     = OpenAI::instance();
		$stripped   = wp_strip_all_tags( $content );
		$embeddings = $openai->create_embeddings( $stripped );

		if ( is_wp_error( $embeddings ) || ! $embeddings ) {
			wp_schedule_single_event( time() + 60, 'hyve_process_post', [ $id ] );
			return;
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
				wp_schedule_single_event( time() + 60, 'hyve_process_post', [ $id ] );
				return;
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
			'post_status'    => 'publish',
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

		foreach ( $twenty as $id ) {
			$this->delete_by_post_id( $id );
	
			delete_post_meta( $id, '_hyve_added' );
			delete_post_meta( $id, '_hyve_needs_update' );
			delete_post_meta( $id, '_hyve_moderation_failed' );
			delete_post_meta( $id, '_hyve_moderation_review' );
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
