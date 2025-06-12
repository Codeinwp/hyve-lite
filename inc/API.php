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
use ThemeIsle\HyveLite\Qdrant_API;
use ThemeIsle\HyveLite\OpenAI;

/**
 * API class.
 */
class API extends BaseAPI {

	/**
	 * The single instance of the class.
	 *
	 * @var API
	 */
	private static $instance = null;

	/**
	 * Ensures only one instance of the class is loaded.
	 *
	 * @return API An instance of the class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

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
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API route
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_endpoint();

		$routes = [
			'settings' => [
				[
					'methods'  => \WP_REST_Server::READABLE,
					'callback' => [ $this, 'get_settings' ],
				],
				[
					'methods'  => \WP_REST_Server::CREATABLE,
					'args'     => [
						'data' => [
							'required'          => true,
							'type'              => 'object',
							'validate_callback' => function ( $param ) {
								return is_array( $param );
							},
						],
					],
					'callback' => [ $this, 'update_settings' ],
				],
			],
			'data'     => [
				[
					'methods'  => \WP_REST_Server::READABLE,
					'args'     => [
						'offset' => [
							'required' => false,
							'type'     => 'integer',
							'default'  => 0,
						],
						'type'   => [
							'required' => false,
							'type'     => 'string',
							'default'  => 'any',
						],
						'search' => [
							'required' => false,
							'type'     => 'string',
						],
						'status' => [
							'required' => false,
							'type'     => 'string',
						],
					],
					'callback' => [ $this, 'get_data' ],
				],
				[
					'methods'  => \WP_REST_Server::CREATABLE,
					'args'     => [
						'action' => [
							'required' => false,
							'type'     => 'string',
						],
						'data'   => [
							'required' => true,
							'type'     => 'object',
						],
					],
					'callback' => [ $this, 'add_data' ],
				],
				[
					'methods'  => \WP_REST_Server::DELETABLE,
					'args'     => [
						'id' => [
							'required' => true,
							'type'     => 'integer',
						],
					],
					'callback' => [ $this, 'delete_data' ],
				],
			],
			'threads'  => [
				[
					'methods'  => \WP_REST_Server::READABLE,
					'args'     => [
						'offset' => [
							'required' => false,
							'type'     => 'integer',
							'default'  => 0,
						],
					],
					'callback' => [ $this, 'get_threads' ],
				],
			],
			'qdrant'   => [
				[
					'methods'  => \WP_REST_Server::READABLE,
					'callback' => [ $this, 'qdrant_status' ],
				],
				[
					'methods'  => \WP_REST_Server::CREATABLE,
					'callback' => [ $this, 'qdrant_deactivate' ],
				],
			],
			'chat'     => [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'args'                => [
						'run_id'    => [
							'required' => true,
							'type'     => 'string',
						],
						'thread_id' => [
							'required' => true,
							'type'     => 'string',
						],
						'record_id' => [
							'required' => true,
							'type'     => [
								'string',
								'integer',
							],
						],
						'message'   => [
							'required' => false,
							'type'     => 'string',
						],
					],
					'callback'            => [ $this, 'get_chat' ],
					'permission_callback' => function ( $request ) {
						$nonce = $request->get_header( 'x_wp_nonce' );
						return wp_verify_nonce( $nonce, 'wp_rest' );
					},
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'args'                => [
						'message'   => [
							'required' => true,
							'type'     => 'string',
						],
						'thread_id' => [
							'required' => false,
							'type'     => 'string',
						],
						'record_id' => [
							'required' => false,
							'type'     => [
								'string',
								'integer',
							],
						],
					],
					'callback'            => [ $this, 'send_chat' ],
					'permission_callback' => function ( $request ) {
						$nonce = $request->get_header( 'x_wp_nonce' );
						return wp_verify_nonce( $nonce, 'wp_rest' );
					},
				],
			],
		];

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
		Main::add_labels_to_default_settings();
		$settings = Main::get_settings();
		return rest_ensure_response( $settings );
	}

	/**
	 * Update settings.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$data     = $request->get_param( 'data' );
		$settings = Main::get_settings();
		$updated  = [];

		foreach ( $data as $key => $datum ) {
			if ( ! array_key_exists( $key, $settings ) || $settings[ $key ] === $datum ) {
				continue;
			}

			$updated[ $key ] = $datum;
		}

		if ( empty( $updated ) ) {
			return rest_ensure_response( [ 'error' => __( 'No settings to update.', 'hyve-lite' ) ] );
		}

		$validation = apply_filters(
			'hyve_settings_validation',
			[
				'api_key'              => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'qdrant_api_key'       => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'qdrant_endpoint'      => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_url',
				],
				'chat_enabled'         => [
					'validate' => function ( $value ) {
						return is_bool( $value );
					},
					'sanitize' => 'rest_sanitize_boolean',
				],
				'welcome_message'      => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'default_message'      => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'chat_model'           => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'temperature'          => [
					'validate' => function ( $value ) {
						return is_numeric( $value );
					},
					'sanitize' => 'floatval',
				],
				'top_p'                => [
					'validate' => function ( $value ) {
						return is_numeric( $value );
					},
					'sanitize' => 'floatval',
				],
				'moderation_threshold' => [
					'validate' => function ( $value ) {
						return is_array( $value ) && array_reduce(
							$value,
							function ( $carry, $item ) {
								return $carry && is_int( $item );
							},
							true
						);
					},
					'sanitize' => function ( $value ) {
						return array_map( 'intval', $value );
					},
				],
			]
		);

		foreach ( $updated as $key => $value ) {
			if ( ! $validation[ $key ]['validate']( $value ) ) {
				return rest_ensure_response(
					[
						// translators: %s: option key.
						'error' => sprintf( __( 'Invalid value: %s', 'hyve-lite' ), $key ),
					]
				);
			}

			$updated[ $key ] = $validation[ $key ]['sanitize']( $value );
		}

		foreach ( $updated as $key => $value ) {
			$settings[ $key ] = $value;

			if ( 'api_key' === $key && ! empty( $value ) ) {
				$openai    = new OpenAI( $value );
				$valid_api = $openai->setup_assistant();

				if ( is_wp_error( $valid_api ) ) {
					return rest_ensure_response( [ 'error' => $this->get_error_message( $valid_api ) ] );
				}

				$settings['assistant_id'] = $valid_api;
			}
		}

		if ( ( isset( $updated['qdrant_api_key'] ) && ! empty( $updated['qdrant_api_key'] ) ) || ( isset( $updated['qdrant_endpoint'] ) && ! empty( $updated['qdrant_endpoint'] ) ) ) {
			$qdrant = new Qdrant_API( $data['qdrant_api_key'], $data['qdrant_endpoint'] );
			$init   = $qdrant->init();

			if ( is_wp_error( $init ) ) {
				return rest_ensure_response( [ 'error' => $this->get_error_message( $init ) ] );
			}
		}

		update_option( 'hyve_settings', $settings );

		return rest_ensure_response( __( 'Settings updated.', 'hyve-lite' ) );
	}

	/**
	 * Get data.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_data( $request ) {
		$args = [
			'post_type'      => $request->get_param( 'type' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'offset'         => $request->get_param( 'offset' ),
			'meta_query'     => [
				[
					'key'     => '_hyve_added',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_hyve_moderation_failed',
					'compare' => 'NOT EXISTS',
				],
			],
		];

		$search = $request->get_param( 'search' );

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$status = $request->get_param( 'status' );

		if ( 'included' === $status ) {
			$args['meta_query'] = [
				'relation' => 'AND',
				[
					'key'     => '_hyve_added',
					'value'   => '1',
					'compare' => '=',
				],
				[
					'key'     => '_hyve_moderation_failed',
					'compare' => 'NOT EXISTS',
				],
			];
		}

		if ( 'pending' === $status ) {
			$args['meta_query'] = [
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
			];
		}

		if ( 'moderation' === $status ) {
			$args['meta_query'] = [
				[
					'key'     => '_hyve_moderation_failed',
					'value'   => '1',
					'compare' => '=',
				],
			];
		}

		$query = new \WP_Query( $args );

		$posts_data = [];

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				/**
				 * The post id.
				 *
				 * @var int $post_id
				 */
				$post_data = [
					'ID'    => $post_id,
					'title' => get_the_title( $post_id ),
				];

				if ( 'moderation' === $status ) {
					$review = get_post_meta( $post_id, '_hyve_moderation_review', true );

					if ( ! is_array( $review ) || empty( $review ) ) {
						$review = [];
					}

					$post_data['review'] = $review;
				}

				$posts_data[] = $post_data;
			}
		}

		$posts = [
			'posts'       => $posts_data,
			'more'        => $query->found_posts > 20,
			'totalChunks' => $this->table->get_count(),
		];

		return rest_ensure_response( $posts );
	}

	/**
	 * Add data.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception If Qdrant API fails.
	 */
	public function add_data( $request ) {
		$data    = $request->get_param( 'data' );
		$post_id = $data['ID'];
		$action  = $request->get_param( 'action' );
		$process = $this->table->add_post( $post_id, $action );

		if ( is_wp_error( $process ) ) {
			if ( 'content_failed_moderation' === $process->get_error_code() ) {
				$data   = $process->get_error_data();
				$review = isset( $data['review'] ) ? $data['review'] : [];

				return rest_ensure_response(
					[
						'error'  => $process->get_error_message(),
						'code'   => $process->get_error_code(),
						'review' => $review,
					]
				);
			}

			return rest_ensure_response( [ 'error' => $this->get_error_message( $process ) ] );
		}

		return rest_ensure_response( true );
	}

	/**
	 * Delete data.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception If Qdrant API fails.
	 */
	public function delete_data( $request ) {
		$id = $request->get_param( 'id' );

		if ( Qdrant_API::is_active() ) {
			try {
				$delete_result = Qdrant_API::instance()->delete_point( $id );

				if ( ! $delete_result ) {
					throw new \Exception( __( 'Failed to delete point in Qdrant.', 'hyve-lite' ) );
				}
			} catch ( \Exception $e ) {
				return rest_ensure_response( [ 'error' => $e->getMessage() ] );
			}
		}

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
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_threads( $request ) {
		$pages = apply_filters( 'hyve_threads_per_page', 3 );

		$args = [
			'post_type'      => 'hyve_threads',
			'post_status'    => 'publish',
			'posts_per_page' => $pages,
			'fields'         => 'ids',
			'offset'         => $request->get_param( 'offset' ),
		];

		$query = new \WP_Query( $args );

		$posts_data = [];

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post_id ) {
				/**
				 * The post id.
				 *
				 * @var int $post_id
				 */

				$post_data = [
					'ID'        => $post_id,
					'title'     => get_the_title( $post_id ),
					'date'      => get_the_date( 'c', $post_id ),
					'thread'    => get_post_meta( $post_id, '_hyve_thread_data', true ),
					'thread_id' => get_post_meta( $post_id, '_hyve_thread_id', true ),
				];

				$posts_data[] = $post_data;
			}
		}

		$posts = [
			'posts' => $posts_data,
			'more'  => $query->found_posts > $pages,
		];

		return rest_ensure_response( $posts );
	}

	/**
	 * Qdrant status.
	 *
	 * @return \WP_REST_Response
	 */
	public function qdrant_status() {
		return rest_ensure_response(
			[
				'status'    => Qdrant_API::is_active(),
				'migration' => Qdrant_API::instance()->migration_status(),
			]
		);
	}

	/**
	 * Qdrant deactivate.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception If Qdrant API fails.
	 */
	public function qdrant_deactivate() {
		$settings = Main::get_settings();

		try {
			$deactivated = Qdrant_API::instance()->disconnect();

			if ( ! $deactivated ) {
				throw new \Exception( __( 'Failed to deactivate Qdrant.', 'hyve-lite' ) );
			}
		} catch ( \Exception $e ) {
			return rest_ensure_response( [ 'error' => $e->getMessage() ] );
		}

		$over_limit = $this->table->get_posts_over_limit();

		if ( ! empty( $over_limit ) ) {
			wp_schedule_single_event( time(), 'hyve_delete_posts', [ $over_limit ] );
		}

		$this->table->update_storage( 'WordPress', 'Qdrant' );

		$settings['qdrant_api_key']  = '';
		$settings['qdrant_endpoint'] = '';

		update_option( 'hyve_settings', $settings );
		update_option( 'hyve_qdrant_status', 'inactive' );
		delete_option( 'hyve_qdrant_migration' );

		return rest_ensure_response( __( 'Qdrant deactivated.', 'hyve-lite' ) );
	}

	/**
	 * Get chat.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_chat( $request ) {
		$run_id    = $request->get_param( 'run_id' );
		$thread_id = $request->get_param( 'thread_id' );
		$query     = $request->get_param( 'message' );
		$record_id = $request->get_param( 'record_id' );

		$openai = OpenAI::instance();

		$status = $openai->get_status( $run_id, $thread_id );

		if ( is_wp_error( $status ) ) {
			return rest_ensure_response( [ 'error' => $this->get_error_message( $status ) ] );
		}

		if ( 'completed' !== $status ) {
			return rest_ensure_response( [ 'status' => $status ] );
		}

		$messages = $openai->get_messages( $thread_id );

		if ( is_wp_error( $messages ) ) {
			return rest_ensure_response( [ 'error' => $this->get_error_message( $messages ) ] );
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
			return rest_ensure_response( [ 'error' => __( 'No messages found.', 'hyve-lite' ) ] );
		}

		Main::add_labels_to_default_settings();
		$settings = Main::get_settings();

		$response = ( isset( $message['success'] ) && true === $message['success'] && isset( $message['response'] ) ) ? $message['response'] : esc_html( $settings['default_message'] );

		do_action( 'hyve_chat_response', $run_id, $thread_id, $query, $record_id, $message, $response );

		return rest_ensure_response(
			[
				'status'  => $status,
				'success' => isset( $message['success'] ) ? $message['success'] : false,
				'message' => $response,
			]
		);
	}

	/**
	 * Search knowledge base using Qdrant vector database.
	 *
	 * @param array<int, float> $message_vector The embedding vector for the user's message.
	 * @param float             $similarity_score_threshold Minimum cosine similarity score for relevance.
	 * @param int               $tokens_threshold Maximum tokens to include in the response.
	 *
	 * @return string Concatenated article content.
	 */
	private function search_knowledge_base_qdrant( $message_vector, $similarity_score_threshold, $tokens_threshold ) {
		$articles_embedded_data = '';
		$current_token_count    = 0;

		$knowledge_points = Qdrant_API::instance()->search( $message_vector, $similarity_score_threshold );

		if ( is_wp_error( $knowledge_points ) ) {
			return $articles_embedded_data;
		}

		foreach ( $knowledge_points as $point ) {
			if ( empty( $point['post_title'] ) || empty( $point['post_content'] ) || empty( $point['token_count'] ) ) {
				continue;
			}

			if ( $current_token_count > $tokens_threshold ) {
				break;
			}

			$articles_embedded_data .= "\n ===START POST=== " . $point['post_title'] . ' - ' . $point['post_content'] . ' ===END POST===';
			$current_token_count    += intval( $point['token_count'] );
		}

		return $articles_embedded_data;
	}

	/**
	 * Search knowledge base using WordPress database storage.
	 *
	 * @param array<int, float> $message_vector The embedding vector for the user's message.
	 * @param float             $similarity_score_threshold Minimum cosine similarity score for relevance.
	 * @param int               $tokens_threshold Maximum tokens to include in the response.
	 *
	 * @return string Concatenated article content.
	 */
	private function search_knowledge_base_wp( $message_vector, $similarity_score_threshold, $tokens_threshold ) {
		$articles_embedded_data = '';
		$message_vector_mag     = Cosine_Similarity::magnitude( $message_vector );

		if ( 0.0 === $message_vector_mag ) {
			return $articles_embedded_data;
		}

		$current_token_count = 0;
		$offset              = 0;
		$items_per_page      = 50;
		$saved_embeddings    = $this->table->get_embeddings( $offset, $items_per_page );
		$matched_articles    = [];

		do {
			foreach ( $saved_embeddings as $data ) {
				if ( empty( $data->embeddings ) ) {
					continue;
				}

				$embeddings = json_decode( $data->embeddings, true );

				if ( ! is_array( $embeddings ) ) {
					continue;
				}

				$embeddings_mag = Cosine_Similarity::magnitude( $embeddings );
				if ( 0.0 === $embeddings_mag ) {
					continue;
				}

				$score = Cosine_Similarity::similarity( Cosine_Similarity::dot_product( $message_vector, $embeddings ), $message_vector_mag, $embeddings_mag );

				if ( $similarity_score_threshold > $score ) {
					continue;
				}

				$matched_articles[] = [
					'id'          => intval( $data->id ),
					'token_count' => intval( $data->token_count ),
					'score'       => $score,
				];

				$current_token_count += intval( $data->token_count );
				unset( $data );
			}

			if ( $current_token_count > $tokens_threshold ) {
				// Sort by score and drop the ones that do not fit in the context.
				usort(
					$matched_articles,
					function ( $a, $b ) {
						if ( $a['score'] < $b['score'] ) {
							return 1;
						} elseif ( $a['score'] > $b['score'] ) {
							return -1;
						} else {
							return 0;
						}
					}
				);

				while ( $current_token_count > $tokens_threshold ) {
					$article = array_pop( $matched_articles );
					if ( empty( $article ) ) {
						break;
					}
					$current_token_count -= $article['token_count'];
				}
			}

			$offset          += $items_per_page;
			$saved_embeddings = $this->table->get_embeddings( $offset, $items_per_page );
		} while ( ! empty( $saved_embeddings ) );

		if ( empty( $matched_articles ) ) {
			return $articles_embedded_data;
		}

		foreach ( $matched_articles as $article ) {
			$article_data = $this->table->get_post_data( $article['id'] );
			if ( empty( $article_data ) ) {
				continue;
			}

			$articles_embedded_data .= "\n ===START POST=== " . $article_data['post_title'] . ' - ' . $article_data['post_content'] . ' ===END POST===';
		}

		return $articles_embedded_data;
	}

	/**
	 * Get Similarity.
	 *
	 * @param array<int, float> $message_vector Message vector.
	 * @param float             $similarity_score_threshold Cosine similarity score.
	 * @param int               $tokens_threshold Tokens threshold for final data.
	 *
	 * @return string The articles blob data that match the given message vector.
	 */
	public function search_knowledge_base( $message_vector, $similarity_score_threshold = 0.4, $tokens_threshold = 2000 ) {
		if ( Qdrant_API::is_active() ) {
			return $this->search_knowledge_base_qdrant( $message_vector, $similarity_score_threshold, $tokens_threshold );
		}

		return $this->search_knowledge_base_wp( $message_vector, $similarity_score_threshold, $tokens_threshold );
	}

	/**
	 * Send chat.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function send_chat( $request ) {
		$message    = $request->get_param( 'message' );
		$record_id  = $request->get_param( 'record_id' );
		$moderation = OpenAI::instance()->moderate_chunks( $message );

		if ( true !== $moderation ) {
			return rest_ensure_response( [ 'error' => __( 'Message was flagged.', 'hyve-lite' ) ] );
		}

		$openai         = OpenAI::instance();
		$message_vector = $openai->create_embeddings( $message );
		$message_vector = reset( $message_vector );
		$message_vector = $message_vector->embedding;

		if ( is_wp_error( $message_vector ) ) {
			return rest_ensure_response( [ 'error' => __( 'No embeddings found.', 'hyve-lite' ) ] );
		}

		if ( $request->get_param( 'thread_id' ) ) {
			$thread_id = $request->get_param( 'thread_id' );
		} else {
			$thread_id = $openai->create_thread();
		}

		if ( is_wp_error( $thread_id ) ) {
			return rest_ensure_response( [ 'error' => $this->get_error_message( $thread_id ) ] );
		}

		$article_context = $this->search_knowledge_base( $message_vector );
		$query_run       = $openai->create_run(
			[
				[
					'role'    => 'user',
					'content' => 'START QUESTION: ' . $message . ' :END QUESTION',
				],
				[
					'role'    => 'user',
					'content' => 'START CONTEXT: ' . $article_context . ' :END CONTEXT',
				],
			],
			$thread_id
		);

		if ( is_wp_error( $query_run ) ) {
			if ( strpos( $this->get_error_message( $query_run ), 'No thread found with id' ) !== false ) {
				$thread_id = $openai->create_thread();

				if ( is_wp_error( $thread_id ) ) {
					return rest_ensure_response( [ 'error' => $this->get_error_message( $thread_id ) ] );
				}

				$query_run = $openai->create_run(
					[
						[
							'role'    => 'user',
							'content' => 'Question: ' . $message,
						],
						[
							'role'    => 'user',
							'content' => 'Context: ' . $article_context,
						],
					],
					$thread_id
				);

				if ( is_wp_error( $query_run ) ) {
					return rest_ensure_response( [ 'error' => $this->get_error_message( $query_run ) ] );
				}
			}
		}

		$record_id = apply_filters( 'hyve_chat_request', $thread_id, $record_id, $message );

		return rest_ensure_response(
			[
				'thread_id' => $thread_id,
				'query_run' => $query_run,
				'record_id' => $record_id ? $record_id : null,
				'content'   => $article_context,
			]
		);
	}
}
