<?php
/**
 * Database Table Class.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\OpenAI;

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

		if ( ! wp_next_scheduled( 'hyve_process_posts' ) ) {
			wp_schedule_event( time(), 'daily', 'hyve_process_posts' );
		}

		add_action( 'hyve_process_posts', array( $this, 'process_posts' ) );

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
        source VARCHAR(255) NOT NULL DEFAULT "WordPress",
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
		return array(
			'date'         => '%s',
			'modified'     => '%s',
			'post_id'      => '%s',
			'post_title'   => '%s',
			'post_content' => '%s',
			'embeddings'   => '%s',
			'token_count'  => '%d',
			'post_status'  => '%s',
			'source'       => '%s',
		);
	}

	/**
	 * Get default column values.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function get_column_defaults() {
		return array(
			'date'         => gmdate( 'Y-m-d H:i:s' ),
			'modified'     => gmdate( 'Y-m-d H:i:s' ),
			'post_id'      => '',
			'post_title'   => '',
			'post_content' => '',
			'embeddings'   => '',
			'token_count'  => 0,
			'post_status'  => 'scheduled',
			'source'       => 'wordpress',
		);
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

		$wpdb->update( $this->table_name, $data, array( 'id' => $id ), $column_formats, array( '%d' ) );

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

		$wpdb->delete( $this->table_name, array( 'post_id' => $post_id ), array( '%d' ) );

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
	 * Get all rows by source.
	 * 
	 * @since 1.3.0
	 * 
	 * @param string $source The source.
	 * @param int    $limit The limit.
	 */
	public function get_by_source( $source, $limit = 100 ) {
		global $wpdb;
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s LIMIT %d', $this->table_name, $source, $limit ) );
		return $results;
	}

	/**
	 * Process posts.
	 * 
	 * @since 1.2.0
	 * 
	 * @return void
	 */
	public function process_posts() {
		$posts = $this->get_by_status( 'scheduled' );

		foreach ( $posts as $post ) {
			$id         = $post->id;
			$content    = $post->post_content;
			$openai     = OpenAI::instance();
			$embeddings = $openai->create_embeddings( $content );
			$embeddings = reset( $embeddings );
			$embeddings = $embeddings->embedding;

			if ( is_wp_error( $embeddings ) || ! $embeddings ) {
				continue;
			}

			$embeddings = wp_json_encode( $embeddings );

			$this->update(
				$id,
				array(
					'embeddings'  => $embeddings,
					'post_status' => 'processed',
				) 
			);
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

			$entries = array();

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
