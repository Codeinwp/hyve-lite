<?php
/**
 * API class.
 * 
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\Main;
use ThemeIsle\HyveLite\BaseAPI;
use ThemeIsle\HyveLite\Cosine_Similarity;

/**
 * API class.
 */
class API extends BaseAPI {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->register_route();
	}

	/**
	 * Register hooks and actions.
	 * 
	 * @return void
	 */
	private function register_route() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API route
	 * 
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_endpoint();

		$routes = array(
			'settings' => array(
				array(
					'methods'  => \WP_REST_Server::READABLE,
					'callback' => array( $this, 'get_settings' ),
				),
				array(
					'methods'  => \WP_REST_Server::CREATABLE,
					'args'     => array(
						'data' => array(
							'required'          => true,
							'type'              => 'object',
							'validate_callback' => function ( $param ) {
								return is_array( $param );
							},
						),
					),
					'callback' => array( $this, 'update_settings' ),
				),
			),
			'data'     => array(
				array(
					'methods'  => \WP_REST_Server::READABLE,
					'args'     => array(
						'offset' => array(
							'required' => false,
							'type'     => 'integer',
							'default'  => 0,
						),
						'type'   => array(
							'required' => false,
							'type'     => 'string',
							'default'  => 'any',
						),
						'search' => array(
							'required' => false,
							'type'     => 'string',
						),
						'status' => array(
							'required' => false,
							'type'     => 'string',
						),
					),
					'callback' => array( $this, 'get_data' ),
				),
				array(
					'methods'  => \WP_REST_Server::CREATABLE,
					'args'     => array(
						'action' => array(
							'required' => false,
							'type'     => 'string',
						),
						'data'   => array(
							'required' => true,
							'type'     => 'object',
						),
					),
					'callback' => array( $this, 'add_data' ),
				),
				array(
					'methods'  => \WP_REST_Server::DELETABLE,
					'args'     => array(
						'id' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
					'callback' => array( $this, 'delete_data' ),
				),
			),
			'threads'  => array(
				array(
					'methods'  => \WP_REST_Server::READABLE,
					'args'     => array(
						'offset' => array(
							'required' => false,
							'type'     => 'integer',
							'default'  => 0,
						),
					),
					'callback' => array( $this, 'get_threads' ),
				),
			),
			'chat'     => array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'args'                => array(
						'run_id'    => array(
							'required' => true,
							'type'     => 'string',
						),
						'thread_id' => array(
							'required' => true,
							'type'     => 'string',
						),
						'record_id' => array(
							'required' => true,
							'type'     => array(
								'string',
								'integer',
							),
						),
						'message'   => array(
							'required' => false,
							'type'     => 'string',
						),
					),
					'callback'            => array( $this, 'get_chat' ),
					'permission_callback' => function ( $request ) {
						$nonce = $request->get_header( 'x_wp_nonce' );
						return wp_verify_nonce( $nonce, 'wp_rest' );
					},
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'args'                => array(
						'message'   => array(
							'required' => true,
							'type'     => 'string',
						),
						'thread_id' => array(
							'required' => false,
							'type'     => 'string',
						),
						'record_id' => array(
							'required' => false,
							'type'     => array(
								'string',
								'integer',
							),
						),
					),
					'callback'            => array( $this, 'send_chat' ),
					'permission_callback' => function ( $request ) {
						$nonce = $request->get_header( 'x_wp_nonce' );
						return wp_verify_nonce( $nonce, 'wp_rest' );
					},
				),
			),
		);

		foreach ( $routes as $route => $args ) {
			foreach ( $args as $key => $arg ) {
				if ( ! isset( $args[ $key ]['permission_callback'] ) ) {
					$args[ $key ]['permission_callback'] = function () {
						return current_user_can( 'manage_options' );
					};
				}
			}

			register_rest_route( $namespace, '/' . $route, $args );
		}
	}

	/**
	 * Get settings.
	 * 
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		$settings = Main::get_settings();
		return rest_ensure_response( $settings );
	}

	/**
	 * Update settings.
	 * 
	 * @param \WP_REST_Request $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$data     = $request->get_param( 'data' );
		$settings = Main::get_settings();
		$updated  = array();

		foreach ( $data as $key => $datum ) {
			if ( ! array_key_exists( $key, $settings ) || $settings[ $key ] === $datum ) {
				continue;
			}

			$updated[ $key ] = $datum;
		}

		if ( empty( $updated ) ) {
			return rest_ensure_response( array( 'error' => __( 'No settings to update.', 'hyve' ) ) );
		}

		$validation = apply_filters(
			'hyve_settings_validation',
			array(
				'api_key'              => function ( $value ) {
					return is_string( $value );
				},
				'chat_enabled'         => function ( $value ) {
					return is_bool( $value );
				},
				'welcome_message'      => function ( $value ) {
					return is_string( $value );
				},
				'default_message'      => function ( $value ) {
					return is_string( $value );
				},
				'temperature'          => function ( $value ) {
					return is_numeric( $value );
				},
				'top_p'                => function ( $value ) {
					return is_numeric( $value );
				},
				'moderation_threshold' => function ( $value ) {
					return is_array( $value ) && array_reduce(
						$value,
						function ( $carry, $item ) {
							return $carry && is_int( $item );
						},
						true
					);
				},
			)
		);

		foreach ( $updated as $key => $value ) {
			if ( ! $validation[ $key ]( $value ) ) {
				return rest_ensure_response(
					array(
						// translators: %s: option key.
						'error' => sprintf( __( 'Invalid value: %s', 'hyve' ), $key ),
					)
				);
			}
		}

		foreach ( $updated as $key => $value ) {
			$settings[ $key ] = $value;

			if ( 'api_key' === $key && ! empty( $value ) ) {
				$openai    = new OpenAI( $value );
				$valid_api = $openai->setup_assistant();
	
				if ( is_wp_error( $valid_api ) ) {
					return rest_ensure_response( array( 'error' => $this->get_error_message( $valid_api ) ) );
				}

				$settings['assistant_id'] = $valid_api;
			}
		}

		update_option( 'hyve_settings', $settings );

		return rest_ensure_response( __( 'Settings updated.', 'hyve' ) );
	}

	/**
	 * Get data.
	 * 
	 * @param \WP_REST_Request $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function get_data( $request ) {
		$args = array(
			'post_type'      => $request->get_param( 'type' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'offset'         => $request->get_param( 'offset' ),
			'meta_query'     => array(
				array(
					'key'     => '_hyve_added',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_hyve_moderation_failed',
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$search = $request->get_param( 'search' );

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$status = $request->get_param( 'status' );

		if ( 'included' === $status ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'     => '_hyve_added',
					'value'   => '1',
					'compare' => '=',
				),
				array(
					'key'     => '_hyve_moderation_failed',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		if ( 'pending' === $status ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
				array(
					'key'     => '_hyve_needs_update',
					'value'   => '1',
					'compare' => '=',
				),
				array(
					'key'     => '_hyve_moderation_failed',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		if ( 'moderation' === $status ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_hyve_moderation_failed',
					'value'   => '1',
					'compare' => '=',
				),
			);
		}

		$query = new \WP_Query( $args );

		$posts_data = array();
		
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				$post_data = array(
					'ID'      => $post_id,
					'title'   => get_the_title( $post_id ),
					'content' => apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) ),
				);

				if ( 'moderation' === $status ) {
					$review = get_post_meta( $post_id, '_hyve_moderation_review', true );
	
					if ( ! is_array( $review ) || empty( $review ) ) {
						$review = array();
					}

					$post_data['review'] = $review;
				}

				$posts_data[] = $post_data;
			}
		}

		$posts = array(
			'posts'       => $posts_data,
			'more'        => $query->found_posts > 20,
			'totalChunks' => $this->table->get_count(),
		);
		
		return rest_ensure_response( $posts );
	}

	/**
	 * Add data.
	 * 
	 * @param \WP_REST_Request $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function add_data( $request ) {
		$data    = $request->get_param( 'data' );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $data['post_id'] ) );
		$chunks  = str_split( $content, 2000 );

		$moderation = $this->moderate( $chunks, $data['post_id'] );

		if ( is_wp_error( $moderation ) ) {
			return rest_ensure_response( array( 'error' => $this->get_error_message( $moderation ) ) );
		}

		if ( true !== $moderation && 'override' !== $request->get_param( 'action' ) ) {
			update_post_meta( $data['post_id'], '_hyve_moderation_failed', 1 );
			update_post_meta( $data['post_id'], '_hyve_moderation_review', $moderation );

			return rest_ensure_response(
				array(
					'error'  => __( 'The content failed moderation policies.', 'hyve' ),
					'code'   => 'content_failed_moderation',
					'review' => $moderation,
				)
			);
		}

		if ( 'update' === $request->get_param( 'action' ) ) {
			$this->table->delete_by_post_id( $data['post_id'] );
			delete_post_meta( $data['post_id'], '_hyve_needs_update' );
		}

		$this->table->insert( $data );

		update_post_meta( $data['post_id'], '_hyve_added', 1 );
		delete_post_meta( $data['post_id'], '_hyve_moderation_failed' );
		delete_post_meta( $data['post_id'], '_hyve_moderation_review' );

		$this->table->process_posts();

		return rest_ensure_response( true );
	}

	/**
	 * Delete data.
	 * 
	 * @param \WP_REST_Request $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function delete_data( $request ) {
		$id = $request->get_param( 'id' );

		$this->table->delete_by_post_id( $id );

		delete_post_meta( $id, '_hyve_added' );
		delete_post_meta( $id, '_hyve_needs_update' );
		delete_post_meta( $id, '_hyve_moderation_failed' );
		delete_post_meta( $id, '_hyve_moderation_review' );
		return rest_ensure_response( true );
	}

	/**
	 * Get threads.
	 * 
	 * @param \WP_REST_Request $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function get_threads( $request ) {
		$pages = apply_filters( 'hyve_threads_per_page', 3 );

		$args = array(
			'post_type'      => 'hyve_threads',
			'post_status'    => 'publish',
			'posts_per_page' => $pages,
			'fields'         => 'ids',
			'offset'         => $request->get_param( 'offset' ),
		);

		$query = new \WP_Query( $args );

		$posts_data = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				$post_data = array(
					'ID'        => $post_id,
					'title'     => get_the_title( $post_id ),
					'date'      => get_the_date( 'd/m/Y g:i A', $post_id ),
					'thread'    => get_post_meta( $post_id, '_hyve_thread_data', true ),
					'thread_id' => get_post_meta( $post_id, '_hyve_thread_id', true ),
				);

				$posts_data[] = $post_data;
			}
		}

		$posts = array(
			'posts' => $posts_data,
			'more'  => $query->found_posts > $pages,
		);

		return rest_ensure_response( $posts );
	}

	/**
	 * Get chat.
	 * 
	 * @param \WP_REST_Request $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function get_chat( $request ) {
		$run_id    = $request->get_param( 'run_id' );
		$thread_id = $request->get_param( 'thread_id' );
		$query     = $request->get_param( 'message' );
		$record_id = $request->get_param( 'record_id' );

		$openai = new OpenAI();

		$status = $openai->get_status( $run_id, $thread_id );

		if ( is_wp_error( $status ) ) {
			return rest_ensure_response( array( 'error' => $this->get_error_message( $status ) ) );
		}

		if ( 'completed' !== $status ) {
			return rest_ensure_response( array( 'status' => $status ) );
		}

		$messages = $openai->get_messages( $thread_id );

		if ( is_wp_error( $messages ) ) {
			return rest_ensure_response( array( 'error' => $this->get_error_message( $messages ) ) );
		}

		$messages = array_filter(
			$messages,
			function ( $message ) use ( $run_id ) {
				return $message->run_id === $run_id;
			} 
		);

		$message = reset( $messages )->content[0]->text->value;

		$message = json_decode( $message, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return rest_ensure_response( array( 'error' => __( 'No messages found.', 'hyve' ) ) );
		}

		$settings = Main::get_settings();

		$response = ( isset( $message['success'] ) && true === $message['success'] && isset( $message['response'] ) ) ? $message['response'] : $settings['default_message'];

		do_action( 'hyve_chat_response', $run_id, $thread_id, $query, $record_id, $message, $response );

		return rest_ensure_response(
			array(
				'status'  => $status,
				'success' => isset( $message['success'] ) ? $message['success'] : false,
				'message' => $response,
			) 
		);
	}

	/**
	 * Send chat.
	 * 
	 * @param \WP_REST_Request $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function send_chat( $request ) {
		$message    = $request->get_param( 'message' );
		$record_id  = $request->get_param( 'record_id' );
		$moderation = $this->moderate( $message );

		if ( true !== $moderation ) {
			return rest_ensure_response( array( 'error' => __( 'Message was flagged.', 'hyve' ) ) );
		}

		$openai         = new OpenAI();
		$message_vector = $openai->create_embeddings( $message );
		$message_vector = reset( $message_vector );
		$message_vector = $message_vector->embedding;

		if ( is_wp_error( $message_vector ) ) {
			return rest_ensure_response( array( 'error' => __( 'No embeddings found.', 'hyve' ) ) );
		}

		$hash = md5( strtolower( $message ) );
		set_transient( 'hyve_message_' . $hash, $message_vector, MINUTE_IN_SECONDS );

		$posts = $this->table->get_by_status( 'processed' );

		$embeddings_with_cosine_distance_sorted = array_map(
			function ( $row ) use ( $message_vector ) {
				$embeddings = json_decode( $row->embeddings, true );

				if ( ! is_array( $embeddings ) ) {
					return array(
						'post_id'      => $row->post_id,
						'distance'     => 0,
						'token_count'  => $row->token_count,
						'post_title'   => $row->post_title,
						'post_content' => $row->post_content,
					);
				}

				$distance = Cosine_Similarity::calculate( $message_vector, $embeddings );

				return array(
					'post_id'      => $row->post_id,
					'distance'     => $distance,
					'token_count'  => $row->token_count,
					'post_title'   => $row->post_title,
					'post_content' => $row->post_content,
				);
			},
			$posts 
		);

		usort(
			$embeddings_with_cosine_distance_sorted,
			function ( $a, $b ) {
				if ( $a['distance'] < $b['distance'] ) {
					return 1;
				} elseif ( $a['distance'] > $b['distance'] ) {
					return -1;
				} else {
					return 0;
				}
			} 
		);

		$embeddings_with_cosine_distance_sorted = array_filter(
			$embeddings_with_cosine_distance_sorted,
			function ( $row ) {
				return $row['distance'] > 0.4;
			} 
		);

		$max_tokens_length  = 2000;
		$curr_tokens_length = 0;
		$article_context    = '';

		foreach ( $embeddings_with_cosine_distance_sorted as $row ) {
			$curr_tokens_length += $row['token_count'];
			if ( $curr_tokens_length < $max_tokens_length ) {
				$article_context .= "\n ===START POST=== " . $row['post_title'] . ' - ' . $row['post_content'] . ' ===END POST===';
			}
		}

		if ( $request->get_param( 'thread_id' ) ) {
			$thread_id = $request->get_param( 'thread_id' );
		} else {
			$thread_id = $openai->create_thread();
		}

		if ( is_wp_error( $thread_id ) ) {
			return rest_ensure_response( array( 'error' => $this->get_error_message( $thread_id ) ) );
		}

		$query_run = $openai->create_run(
			array(
				array(
					'role'    => 'user',
					'content' => 'START QUESTION: ' . $message . ' :END QUESTION',
				),
				array(
					'role'    => 'user',
					'content' => 'START CONTEXT: ' . $article_context . ' :END CONTEXT',
				),
			),
			$thread_id
		);

		if ( is_wp_error( $query_run ) ) {
			if ( strpos( $this->get_error_message( $query_run ), 'No thread found with id' ) !== false ) {
				$thread_id = $openai->create_thread();

				if ( is_wp_error( $thread_id ) ) {
					return rest_ensure_response( array( 'error' => $this->get_error_message( $thread_id ) ) );
				}

				$query_run = $openai->create_run(
					array(
						array(
							'role'    => 'user',
							'content' => 'Question: ' . $message,
						),
						array(
							'role'    => 'user',
							'content' => 'Context: ' . $article_context,
						),
					),
					$thread_id
				);

				if ( is_wp_error( $query_run ) ) {
					return rest_ensure_response( array( 'error' => $this->get_error_message( $query_run ) ) );
				}
			}
		}

		do_action( 'hyve_chat_request', $thread_id, $record_id, $message );

		return rest_ensure_response(
			array(
				'thread_id' => $thread_id,
				'query_run' => $query_run,
				'record_id' => $record_id ? $record_id : null,
			) 
		);
	}
}
