<?php
/**
 * Post Tpe Class.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Class Threads
 */
class Threads {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'hyve_chat_response', array( $this, 'record_message' ), 10, 6 );
		add_action( 'hyve_chat_request', array( $this, 'record_thread' ), 10, 3 );
	}

	/**
	 * Register the post types.
	 * 
	 * @return void
	 */
	public function register() {
		$labels = array(
			'name'               => _x( 'Threads', 'post type general name', 'hyve' ),
			'singular_name'      => _x( 'Thread', 'post type singular name', 'hyve' ),
			'menu_name'          => _x( 'Threads', 'admin menu', 'hyve' ),
			'name_admin_bar'     => _x( 'Thread', 'add new on admin bar', 'hyve' ),
			'add_new'            => _x( 'Add New', 'Thread', 'hyve' ),
			'add_new_item'       => __( 'Add New Thread', 'hyve' ),
			'new_item'           => __( 'New Thread', 'hyve' ),
			'edit_item'          => __( 'Edit Thread', 'hyve' ),
			'view_item'          => __( 'View Thread', 'hyve' ),
			'all_items'          => __( 'All Threads', 'hyve' ),
			'search_items'       => __( 'Search Threads', 'hyve' ),
			'parent_item_colon'  => __( 'Parent Thread:', 'hyve' ),
			'not_found'          => __( 'No Threads found.', 'hyve' ),
			'not_found_in_trash' => __( 'No Threads found in Trash.', 'hyve' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Threads.', 'hyve' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'query_var'          => false,
			'rewrite'            => array( 'slug' => 'threads' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'show_in_rest'       => false,
			'supports'           => array( 'title', 'editor', 'custom-fields', 'comments' ),
		);

		register_post_type( 'hyve_threads', $args );
	}

	/**
	 * Record the message.
	 * 
	 * @param string     $run_id    Run ID.
	 * @param string     $thread_id Thread ID.
	 * @param string     $query     Query.
	 * @param string|int $record_id Record ID.
	 * @param array      $message   Message.
	 * @param string     $response  Response.
	 * 
	 * @return void
	 */
	public function record_message( $run_id, $thread_id, $query, $record_id, $message, $response ) {
		if ( ! $record_id ) {
			return;
		}

		self::add_message(
			$record_id,
			array(
				'thread_id' => $thread_id,
				'sender'    => 'bot',
				'message'   => $response,
			)
		);
	}

	/**
	 * Record the thread.
	 * 
	 * @param string     $thread_id Thread ID.
	 * @param string|int $record_id Record ID.
	 * @param string     $message   Message.
	 * 
	 * @return void
	 */
	public function record_thread( $thread_id, $record_id, $message ) {
		if ( $record_id ) {
			$record_id = self::add_message(
				$record_id,
				array(
					'thread_id' => $thread_id,
					'sender'    => 'user',
					'message'   => $message,
				)
			);
			return;
		}

		$record_id = self::create_thread(
			$message,
			array(
				'thread_id' => $thread_id,
				'sender'    => 'user',
				'message'   => $message,
			)
		);
	}
	

	/**
	 * Create a new thread.
	 * 
	 * @param string $title The title of the thread.
	 * @param array  $data The data of the thread.
	 * 
	 * @return int
	 */
	public static function create_thread( $title, $data ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'hyve_threads',
			)
		);

		$thread_data = array(
			array(
				'time'    => time(),
				'sender'  => $data['sender'],
				'message' => $data['message'],
			),
		);

		update_post_meta( $post_id, '_hyve_thread_data', $thread_data );
		update_post_meta( $post_id, '_hyve_thread_count', 1 );
		update_post_meta( $post_id, '_hyve_thread_id', $data['thread_id'] );

		return $post_id;
	}

	/**
	 * Add a new message to a thread.
	 * 
	 * @param int   $post_id The ID of the thread.
	 * @param array $data The data of the message.
	 * 
	 * @return int
	 */
	public static function add_message( $post_id, $data ) {
		$thread_id = get_post_meta( $post_id, '_hyve_thread_id', true );

		if ( $thread_id !== $data['thread_id'] ) {
			return self::create_thread( $data['message'], $data );
		}

		$thread_data = get_post_meta( $post_id, '_hyve_thread_data', true );

		$thread_data[] = array(
			'time'    => time(),
			'sender'  => $data['sender'],
			'message' => $data['message'],
		);

		update_post_meta( $post_id, '_hyve_thread_data', $thread_data );
		update_post_meta( $post_id, '_hyve_thread_count', count( $thread_data ) );

		return $post_id;
	}

	/**
	 * Get Thread Count.
	 * 
	 * @return int
	 */
	public static function get_thread_count() {
		$threads = wp_count_posts( 'hyve_threads' );
		return $threads->publish;
	}

	/**
	 * Get Messages Count.
	 * 
	 * @return int
	 */
	public static function get_messages_count() {
		$messages = get_transient( 'hyve_messages_count' );

		if ( false === $messages ) {
			$messages = 0;

			global $wpdb;

			$query    = "SELECT SUM( CAST( meta_value AS UNSIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = '_hyve_thread_count'";
			$messages = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			set_transient( 'hyve_messages_count', $messages, HOUR_IN_SECONDS );
		}

		return $messages;
	}
}
