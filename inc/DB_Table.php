<?php
/**
 * Database Table Class.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\OpenAI;
use ThemeIsle\HyveLite\Qdrant_API;

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

		if ( ! $this->table_exists() || version_compare( $this->version, get_option( $this->table_name . '_db_version' ), '>' ) ) {
			$this->create_table();
		}
	}

	/**
	 * Create the table.
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
	 * @return array
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
	 * @return array
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
	 * @param array $data The data to insert.
	 *
	 * @return int
	 */
	public function insert( $data ) {
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
	 * @param int   $id The row ID.
	 * @param array $data The data to update.
	 *
	 * @return int
	 */
	public function update( $id, $data ) {
		global $wpdb;

		$column_formats  = $this->get_columns();
		$column_defaults = $this->get_column_defaults();

		$data = array_intersect_key( $data, $column_formats );

		$wpdb->update( $this->table_name, $data, [ 'id' => $id ], $column_formats, [ '%d' ] );

		$this->delete_cache( 'entry_' . $id );
		$this->delete_cache( 'entries_processed' );

		return $wpdb->rows_affected;
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

		$wpdb->delete( $this->table_name, [ 'post_id' => $post_id ], [ '%d' ] );

		$this->delete_cache( 'entry_post_' . $post_id );
		$this->delete_cache( 'entries' );
		$this->delete_cache( 'entries_processed' );
		$this->delete_cache( 'entries_count' );

		return $wpdb->rows_affected;
	}

	/**
	 * Get all rows by status.
	 *
	 * @since 1.2.0
	 *
	 * @param string $status The status.
	 * @param int    $limit The limit.
	 *
	 * @return array
	 */
	public function get_by_status( $status, $limit = 500 ) {
		global $wpdb;

		$cache = $this->get_cache( 'entries_' . $status );

		if ( is_array( $cache ) && false !== $cache ) {
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
	 * @since 1.3.0
	 * 
	 * @param string $storage The storage.
	 * @param int    $limit The limit.
	 * 
	 * @return array
	 */
	public function get_by_storage( $storage, $limit = 100 ) {
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
	 * @return array
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
		$embeddings = $openai->create_embeddings( $content );

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
	 * Delete posts.
	 * 
	 * @since 1.3.0
	 * 
	 * @param array $posts The posts.
	 * 
	 * @return void
	 */
	public function delete_posts( $posts = [] ) {
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
