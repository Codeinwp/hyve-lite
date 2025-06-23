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

	public const CHART_DATA_TRANSIENT = 'hyve_charts_data';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'hyve_chat_response', [ $this, 'record_message' ], 10, 6 );
		add_filter( 'hyve_chat_request', [ $this, 'record_thread' ], 10, 3 );
	}

	/**
	 * Register the post types.
	 * 
	 * @return void
	 */
	public function register() {
		$labels = [
			'name'               => _x( 'Threads', 'post type general name', 'hyve-lite' ),
			'singular_name'      => _x( 'Thread', 'post type singular name', 'hyve-lite' ),
			'menu_name'          => _x( 'Threads', 'admin menu', 'hyve-lite' ),
			'name_admin_bar'     => _x( 'Thread', 'add new on admin bar', 'hyve-lite' ),
			'add_new'            => _x( 'Add New', 'Thread', 'hyve-lite' ),
			'add_new_item'       => __( 'Add New Thread', 'hyve-lite' ),
			'new_item'           => __( 'New Thread', 'hyve-lite' ),
			'edit_item'          => __( 'Edit Thread', 'hyve-lite' ),
			'view_item'          => __( 'View Thread', 'hyve-lite' ),
			'all_items'          => __( 'All Threads', 'hyve-lite' ),
			'search_items'       => __( 'Search Threads', 'hyve-lite' ),
			'parent_item_colon'  => __( 'Parent Thread:', 'hyve-lite' ),
			'not_found'          => __( 'No Threads found.', 'hyve-lite' ),
			'not_found_in_trash' => __( 'No Threads found in Trash.', 'hyve-lite' ),
		];

		$args = [
			'labels'             => $labels,
			'description'        => __( 'Threads.', 'hyve-lite' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'query_var'          => false,
			'rewrite'            => [ 'slug' => 'threads' ],
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'show_in_rest'       => false,
			'supports'           => [ 'title', 'editor', 'custom-fields', 'comments' ],
		];

		register_post_type( 'hyve_threads', $args );
	}

	/**
	 * Record the message.
	 * 
	 * @param string               $run_id    Run ID.
	 * @param string               $thread_id Thread ID.
	 * @param string               $query     Query.
	 * @param string|int           $record_id Record ID.
	 * @param array<string, mixed> $message   Message.
	 * @param string               $response  Response.
	 * 
	 * @return void
	 */
	public function record_message( $run_id, $thread_id, $query, $record_id, $message, $response ) {
		if ( ! $record_id ) {
			return;
		}

		self::add_message(
			intval( $record_id ),
			[
				'thread_id' => $thread_id,
				'sender'    => 'bot',
				'message'   => $response,
			]
		);
	}

	/**
	 * Record the thread.
	 * 
	 * @param string     $thread_id Thread ID.
	 * @param string|int $record_id Record ID.
	 * @param string     $message   Message.
	 * 
	 * @return int
	 */
	public function record_thread( $thread_id, $record_id, $message ) {
		if ( $record_id ) {
			$record_id = self::add_message(
				intval( $record_id ),
				[
					'thread_id' => $thread_id,
					'sender'    => 'user',
					'message'   => $message,
				]
			);
		} else {
			$record_id = self::create_thread(
				$message,
				[
					'thread_id' => $thread_id,
					'sender'    => 'user',
					'message'   => $message,
				]
			);
		}

		return $record_id;
	}
	

	/**
	 * Create a new thread.
	 * 
	 * @param string               $title The title of the thread.
	 * @param array<string, mixed> $data The data of the thread.
	 * 
	 * @return int
	 */
	public static function create_thread( $title, $data ) {
		$post_id = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'hyve_threads',
			]
		);

		$thread_data = [
			[
				'time'    => time(),
				'sender'  => $data['sender'],
				'message' => wp_kses_post( $data['message'] ),
			],
		];

		update_post_meta( $post_id, '_hyve_thread_data', $thread_data );
		update_post_meta( $post_id, '_hyve_thread_count', 1 );
		update_post_meta( $post_id, '_hyve_thread_id', $data['thread_id'] );

		return $post_id;
	}

	/**
	 * Add a new message to a thread.
	 * 
	 * @param int                  $post_id The ID of the thread.
	 * @param array<string, mixed> $data The data of the message.
	 * 
	 * @return int
	 */
	public static function add_message( $post_id, $data ) {
		$thread_id = get_post_meta( $post_id, '_hyve_thread_id', true );

		if ( $thread_id !== $data['thread_id'] ) {
			return self::create_thread( $data['message'], $data );
		}

		$thread_data = get_post_meta( $post_id, '_hyve_thread_data', true );

		$thread_data[] = [
			'time'    => time(),
			'sender'  => $data['sender'],
			'message' => wp_kses_post( $data['message'] ),
		];

		update_post_meta( $post_id, '_hyve_thread_data', $thread_data );
		update_post_meta( $post_id, '_hyve_thread_count', count( $thread_data ) );
		
		wp_update_post(
			[
				'ID'                => $post_id,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			]
		);

		delete_transient( self::CHART_DATA_TRANSIENT );
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

		if ( ! $messages ) {
			global $wpdb;

			$messages = $wpdb->get_var( $wpdb->prepare( "SELECT SUM( CAST( meta_value AS UNSIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_hyve_thread_count' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $messages ) {
				$messages = 0;
			}

			set_transient( 'hyve_messages_count', $messages, HOUR_IN_SECONDS );
		}

		return $messages;
	}

	/**
	 * Get the datasets for charts.
	 * 
	 * @return  array{messages: int[], labels: string[], sessions: int[]} The datasets.
	 */
	public static function get_chart_datasets() {
		$cached_data = get_transient( self::CHART_DATA_TRANSIENT );

		if ( false !== $cached_data ) {
			return $cached_data;
		}
	
		$days                 = 90;
		$current_timestamp    = time();
		$start_date_timestamp = strtotime( "-{$days} days", $current_timestamp );
		$start_date           = gmdate( 'Y-m-d', $start_date_timestamp );

		$messages_per_day = [];
		$sessions_per_day = [];
		$paged            = 1;
		$posts_per_page   = 30; // Process in batches.

		do {
			$args = [
				'post_type'      => 'hyve_threads',
				'posts_per_page' => $posts_per_page,
				'paged'          => $paged,
				'post_status'    => 'publish',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'date_query'     => [
					[
						'column' => 'post_modified_gmt',
						'after'  => $start_date,
					],
				],
				'fields'         => 'ids',
			];

			$query = new \WP_Query( $args );

			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post_id ) {
					/**
					 * The post id.
					 *
					 * @var int $post_id
					 */

					$thread_data = get_post_meta( $post_id, '_hyve_thread_data', true );
					if ( ! is_array( $thread_data ) ) {
						continue;
					}

					// Find the earliest message in this thread within the range.
					$thread_dates = [];
					foreach ( $thread_data as $message_entry ) {
						if (
							isset( $message_entry['sender'], $message_entry['time'] ) &&
							'user' === $message_entry['sender'] &&
							$message_entry['time'] >= $start_date_timestamp
						) {
							$message_date = gmdate( 'Y-m-d', $message_entry['time'] );
							if ( ! isset( $messages_per_day[ $message_date ] ) ) {
								$messages_per_day[ $message_date ] = 0;
							}
							++$messages_per_day[ $message_date ];

							$thread_dates[ $message_date ] = true;
						}
					}
					// Count this thread as a session for the earliest day it appears in the range.
					if ( ! empty( $thread_dates ) ) {
						$first_date = array_key_first( $thread_dates );
						if ( ! isset( $sessions_per_day[ $first_date ] ) ) {
							$sessions_per_day[ $first_date ] = 0;
						}
						++$sessions_per_day[ $first_date ];
					}
				}
			}
			++$paged;
		} while ( $query->max_num_pages >= $paged );

		wp_reset_postdata();

		// Ensure all days in the range are present, even if with 0 messages/sessions.
		$labels   = [];
		$messages = [];
		$sessions = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$timestamp = strtotime( "-{$i} days", $current_timestamp );
			if ( false === $timestamp ) {
				continue;
			}
			$date_key   = gmdate( 'Y-m-d', $timestamp );
			$labels[]   = $date_key;
			$messages[] = isset( $messages_per_day[ $date_key ] ) ? $messages_per_day[ $date_key ] : 0;
			$sessions[] = isset( $sessions_per_day[ $date_key ] ) ? $sessions_per_day[ $date_key ] : 0;
		}

		$output_data = [
			'labels'   => $labels,
			'messages' => $messages,
			'sessions' => $sessions,
		];

		set_transient( self::CHART_DATA_TRANSIENT, $output_data, HOUR_IN_SECONDS );

		return $output_data;
	}
}
