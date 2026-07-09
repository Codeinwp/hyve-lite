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
	 * Source post IDs from the last knowledge base search, ordered by relevance
	 * (highest score first) and de-duplicated per source.
	 *
	 * Used to optionally append source links to the chat response.
	 *
	 * @var array<int, int|string>
	 */
	private $source_post_ids = [];

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
		$this->register_filters();
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
	 * Register filters.
	 *
	 * @return void
	 */
	private function register_filters() {
		add_filter(
			'hyve_search_knowledge_base',
			function ( $result, $message_vector, $similarity_score_threshold, $max_tokens ) {
				$result = $this->search_knowledge_base( $message_vector, $similarity_score_threshold, $max_tokens );

				return $result;
			},
			10,
			4 
		);
	}

	/**
	 * Register REST API route
	 *
	 * @return void
	 */
	public function register_routes() {
		$namespace = $this->get_endpoint();
		if ( '' === $namespace || '0' === $namespace ) {
			return;
		}

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
				[
					'methods'  => \WP_REST_Server::DELETABLE,
					'args'     => [
						'id' => [
							'required' => true,
							'type'     => 'integer',
						],
					],
					'callback' => [ $this, 'delete_thread' ],
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
						'is_test'   => [
							'required' => false,
							'type'     => 'boolean',
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
							'required' => false,
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
						'is_test'   => [
							'required' => false,
							'type'     => 'boolean',
						],
						'mode'      => [
							'required' => false,
							'type'     => 'string',
							'enum'     => [ 'stream', 'background' ],
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
			return $this->settings_response( [ 'success' => __( 'Settings are already up to date.', 'hyve-lite' ) ] );
		}

		$validation = apply_filters(
			'hyve_settings_validation',
			[
				'api_key'                    => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'qdrant_api_key'             => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'qdrant_endpoint'            => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_url',
				],
				'display_mode'               => [
					'validate' => function ( $value ) {
						return in_array( $value, [ 'all', 'include', 'exclude', 'manual' ], true );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'display_rules'              => [
					'validate' => function ( $value ) {
						return is_array( $value );
					},
					'sanitize' => function ( $value ) {
						if ( ! is_array( $value ) ) {
							return [];
						}

						$rules = [];

						foreach ( $value as $rule ) {
							if ( ! is_array( $rule ) || empty( $rule['path'] ) ) {
								continue;
							}

							$operator = ( isset( $rule['operator'] ) && 'matches' === $rule['operator'] ) ? 'matches' : 'contains';

							$rules[] = [
								'path'     => sanitize_text_field( $rule['path'] ),
								'operator' => $operator,
							];
						}

						return $rules;
					},
				],
				'welcome_message'            => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'default_message'            => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'chat_model'                 => [
					'validate' => function ( $value ) {
						return is_string( $value );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'temperature'                => [
					'validate' => function ( $value ) {
						return is_numeric( $value );
					},
					'sanitize' => 'floatval',
				],
				'top_p'                      => [
					'validate' => function ( $value ) {
						return is_numeric( $value );
					},
					'sanitize' => 'floatval',
				],
				'similarity_score_threshold' => [
					'validate' => function ( $value ) {
						return is_numeric( $value );
					},
					'sanitize' => 'floatval',
				],
				'post_row_addon_enabled'     => [
					'validate' => function ( $value ) {
						return is_bool( $value );
					},
					'sanitize' => 'rest_sanitize_boolean',
				],
				'sound_enabled'              => [
					'validate' => function ( $value ) {
						return is_bool( $value );
					},
					'sanitize' => 'rest_sanitize_boolean',
				],
				'show_timestamp'             => [
					'validate' => function ( $value ) {
						return is_bool( $value );
					},
					'sanitize' => 'rest_sanitize_boolean',
				],
				'privacy_notice_enabled'     => [
					'validate' => function ( $value ) {
						return is_bool( $value );
					},
					'sanitize' => 'rest_sanitize_boolean',
				],
				'chat_position'              => [
					'validate' => function ( $value ) {
						return in_array( $value, [ 'left', 'right' ], true );
					},
					'sanitize' => 'sanitize_text_field',
				],
				'show_source_link'           => [
					'validate' => function ( $value ) {
						return is_bool( $value );
					},
					'sanitize' => 'rest_sanitize_boolean',
				],
				'telemetry_enabled'          => [
					'validate' => function ( $value ) {
						return is_bool( $value );
					},
					'sanitize' => function ( $value ) {
						return boolval( $value );
					},
				],
			]
		);

		foreach ( $updated as $key => $value ) {
			if ( ! $validation[ $key ]['validate']( $value ) ) {
				return $this->settings_response(
					[
						// translators: %s: option key.
						'error' => sprintf( __( 'Invalid value: %s', 'hyve-lite' ), $key ),
					]
				);
			}

			$updated[ $key ] = $validation[ $key ]['sanitize']( $value );
		}

		$api_warning   = '';
		$api_key_error = null;
		$key_validated = false;

		foreach ( $updated as $key => $value ) {
			$settings[ $key ] = $value;

			if ( 'api_key' === $key && ! empty( $value ) ) {
				$openai = new OpenAI( $value );

				// Validate against the embeddings endpoint. Suppress automatic
				// persistence: the dashboard notice is reconciled after the save
				// actually lands, so it can never reflect a key that was not
				// stored. See Codeinwp/hyve#149.
				$validation    = $openai->set_error_persistence( false )->create_embeddings( 'Test connection.' );
				$key_validated = true;

				if ( is_wp_error( $validation ) && $this->is_auth_error( $validation->get_error_code() ) ) {
					// The key itself is invalid — block the save.
					return $this->settings_response( [ 'error' => $this->get_error_message( $validation ) ] );
				}

				if ( is_wp_error( $validation ) ) {
					// The key is well-formed but the account is rate-limited or
					// has no credits (new, unfunded accounts return a 429). Save
					// the key but warn; the notice is recorded after the save.
					$api_warning   = $this->get_error_message( $validation );
					$api_key_error = $validation;
				}
			}

			if ( 'telemetry_enabled' === $key ) {
				update_option( 'hyve_lite_logger_flag', boolval( $value ) ? 'yes' : 'no' );
			}
		}

		if ( ( isset( $updated['qdrant_api_key'] ) && ! empty( $updated['qdrant_api_key'] ) ) || ( isset( $updated['qdrant_endpoint'] ) && ! empty( $updated['qdrant_endpoint'] ) ) ) {
			$qdrant = new Qdrant_API( $data['qdrant_api_key'], $data['qdrant_endpoint'] );
			$init   = $qdrant->init();

			if ( is_wp_error( $init ) ) {
				return $this->settings_response( [ 'error' => $this->get_error_message( $init ) ] );
			}
		}

		update_option( 'hyve_settings', $settings );

		// Reconcile the dashboard service-error notice with the key that was just
		// saved — only now that the save has actually landed (no earlier exit can
		// leave a notice for an unsaved key). Clear any stale notice first, then
		// re-record one for the new key when its error is actionable (a no-op for
		// transient codes such as rate limits). See Codeinwp/hyve#149.
		if ( $key_validated ) {
			delete_option( OpenAI::ERROR_OPTION_KEY );

			if ( null !== $api_key_error ) {
				OpenAI::instance()->save_service_error( $api_key_error );
			}
		}

		if ( ! empty( $api_warning ) ) {
			return $this->settings_response( [ 'warning' => $api_warning ] );
		}

		return $this->settings_response( [ 'success' => __( 'Settings updated.', 'hyve-lite' ) ] );
	}

	/**
	 * Build a settings REST response carrying the current service errors.
	 *
	 * Returning the freshly-computed service errors lets the dashboard notice
	 * update immediately after a save, without a page reload. See Codeinwp/hyve#200.
	 *
	 * @param array<string, mixed> $payload The response payload.
	 *
	 * @return \WP_REST_Response
	 */
	private function settings_response( $payload ) {
		$options = apply_filters( 'hyve_options_data', [] );

		$payload['serviceErrors'] = ( is_array( $options ) && isset( $options['serviceErrors'] ) ) ? $options['serviceErrors'] : [];

		return rest_ensure_response( $payload );
	}

	/**
	 * Whether an error code means the API key itself is invalid.
	 *
	 * These block a key from being saved. Account-level problems (no credits,
	 * billing, rate limits) do not: the key is valid, the account just needs
	 * attention, so we save it and warn instead of blocking. See Codeinwp/hyve#149.
	 *
	 * @param int|string $code The error code.
	 *
	 * @return bool
	 */
	private function is_auth_error( $code ) {
		return in_array( $code, OpenAI::AUTH_ERROR_CODES, true );
	}

	/**
	 * Get the visibility of a post for the Knowledge Base UI.
	 *
	 * Content added to the Knowledge Base is surfaced to any chat visitor
	 * regardless of the post's original visibility, so the admin UI flags
	 * restricted content.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string One of 'public', 'private' or 'password'.
	 */
	private function get_post_visibility( $post_id ) {
		if ( 'private' === get_post_status( $post_id ) ) {
			return 'private';
		}

		if ( '' !== get_post_field( 'post_password', $post_id ) ) {
			return 'password';
		}

		return 'public';
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
			'post_type'   => $request->get_param( 'type' ),
			'post_status' => [ 'publish', 'private' ],
			'fields'      => 'ids',
			'offset'      => $request->get_param( 'offset' ),
			'meta_query'  => [
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

		$page = $this->query_page( $args );

		$posts_data = [];

		foreach ( $page['posts'] as $post_id ) {
			/**
			 * The post id.
			 *
			 * @var int $post_id
			 */
			$post_data = [
				'ID'         => $post_id,
				'title'      => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
				'visibility' => $this->get_post_visibility( $post_id ),
			];

			if ( 'moderation' === $status ) {
				$review = get_post_meta( $post_id, '_hyve_moderation_review', true );

				if ( ! is_array( $review ) || empty( $review ) ) {
					$review = [];
				}

				$post_data['review'] = $review;
			}

			$processing_error = get_post_meta( $post_id, '_hyve_processing_error', true );

			if ( ! empty( $processing_error ) && get_post_meta( $post_id, '_hyve_added', true ) ) {
				$post_data['error'] = $processing_error;
			}

			$posts_data[] = $post_data;
		}

		$posts = [
			'posts'       => $posts_data,
			'more'        => $page['more'],
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

		// The content was stored, but the synchronous indexing attempt may have
		// failed (e.g. rate limit, no credits). Surface that as a non-blocking
		// warning so the admin sees it immediately, not only as a list badge.
		$processing_error = get_post_meta( $post_id, '_hyve_processing_error', true );

		if ( ! empty( $processing_error ) ) {
			return rest_ensure_response(
				[
					'success' => true,
					'warning' => $processing_error,
				]
			);
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

				if ( is_wp_error( $delete_result ) || ! $delete_result ) {
					throw new \Exception( is_wp_error( $delete_result ) ? $delete_result->get_error_message() : __( 'Failed to delete point in Qdrant.', 'hyve-lite' ) );
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
		delete_post_meta( $id, '_hyve_processing_error' );
		return rest_ensure_response( true );
	}

	/**
	 * Delete thread.
	 * 
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 * 
	 * @return \WP_REST_Response
	 */
	public function delete_thread( $request ) {
		$id        = $request->get_param( 'id' );
		$post_type = get_post_type( $id );

		if ( ! $post_type || 'hyve_threads' !== $post_type ) {
			return wp_send_json_error( __( 'Thread not found.', 'hyve-lite' ), 404 );
		}
		
		$deleted = wp_delete_post( $id, true );

		if ( ! $deleted ) {
			return wp_send_json_error( __( 'Failed to delete thread.', 'hyve-lite' ), 500 );
		}
		
		return wp_send_json_success(
			__( 'Thread removed from local storage.', 'hyve-lite' ) . ' ' . 
			// translators: this sentence is after 'Thread removed from local storage.'.
			__( 'It remains accessible via the OpenAI API.', 'hyve-lite' )
		);
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
			'post_type'   => 'hyve_threads',
			'post_status' => 'publish',
			'fields'      => 'ids',
			'offset'      => $request->get_param( 'offset' ),
		];

		$page = $this->query_page( $args, $pages );

		$posts_data = [];

		foreach ( $page['posts'] as $post_id ) {
			/**
			 * The post id.
			 *
			 * @var int $post_id
			 */

			$post_data = [
				'ID'        => $post_id,
				'title'     => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
				'date'      => get_the_date( 'c', $post_id ),
				'thread'    => get_post_meta( $post_id, '_hyve_thread_data', true ),
				'thread_id' => get_post_meta( $post_id, '_hyve_thread_id', true ),
			];

			$posts_data[] = $post_data;
		}

		$posts = [
			'posts' => $posts_data,
			'more'  => $page['more'],
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

		$response = $openai->get_response( $run_id );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( [ 'error' => $this->get_error_message( $response ) ] );
		}

		if ( 'completed' !== $response->status ) {
			return rest_ensure_response( [ 'status' => $response->status ] );
		}

		$status = $response->status;

		$message = array_filter(
			$response->output,
			function ( $message ) {
				return (
					isset( $message->type, $message->status, $message->role ) &&
					'message' === $message->type &&
					'completed' === $message->status &&
					'assistant' === $message->role
				);
			}
		);

		if ( empty( $message ) ) {
			return rest_ensure_response( [ 'error' => __( 'No messages found.', 'hyve-lite' ) ] );
		}

		$text = reset( $message )->content[0]->text;

		Main::add_labels_to_default_settings();
		$settings = Main::get_settings();

		$interpreted = OpenAI::interpret_chat_payload( $text, $settings['default_message'] );

		if ( ! $interpreted['decoded'] ) {
			return rest_ensure_response( [ 'error' => __( 'No messages found.', 'hyve-lite' ) ] );
		}

		$payload  = $interpreted['payload'];
		$response = $interpreted['final'];
		$answered = $interpreted['answered'];

		if ( ! empty( $settings['show_source_link'] ) && $answered ) {
			$response = $this->append_source_link( $response, $run_id );
		}
		// Skip recording for admin live-preview test chats (see send_chat).
		if ( ! $request->get_param( 'is_test' ) ) {
			do_action( 'hyve_chat_response', $run_id, $thread_id, $query, $record_id, $payload, $response );
		}

		$data = [
			'status'  => $status,
			'success' => $answered,
			'message' => $response,
		];

		// Let extensions attach extra reply data (e.g. follow-up suggestions from
		// the structured payload). Shared with the streaming flow (Stream) so both
		// paths surface the same data to the widget.
		$reply = apply_filters( 'hyve_chat_reply_data', $data, $payload, $answered );

		if ( is_array( $reply ) ) {
			$data = $reply;
		}

		return rest_ensure_response( $data );
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

		$source_scores = [];

		foreach ( $knowledge_points as $point ) {
			if ( empty( $point['post_title'] ) || empty( $point['post_content'] ) || empty( $point['token_count'] ) ) {
				continue;
			}

			$tokens_count = intval( $point['token_count'] );

			if ( $tokens_threshold <= ( $current_token_count + $tokens_count ) ) {
				continue;
			}

			if ( isset( $point['post_id'], $point['score'] ) ) {
				$post_id = (string) $point['post_id'];

				if ( ! isset( $source_scores[ $post_id ] ) || $point['score'] > $source_scores[ $post_id ] ) {
					$source_scores[ $post_id ] = $point['score'];
				}
			}

			$articles_embedded_data .= "\n ===START POST=== " . $point['post_title'] . ' - ' . $point['post_content'] . ' ===END POST===';
			$current_token_count    += intval( $point['token_count'] );
		}

		$this->source_post_ids = $this->rank_sources( $source_scores );

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

		$source_scores = [];

		foreach ( $matched_articles as $article ) {
			$article_data = $this->table->get_post_data( $article['id'] );
			if ( empty( $article_data ) ) {
				continue;
			}

			$post_id = $this->table->get_post_id( $article['id'] );

			if ( ! empty( $post_id ) && ( ! isset( $source_scores[ $post_id ] ) || $article['score'] > $source_scores[ $post_id ] ) ) {
				$source_scores[ $post_id ] = $article['score'];
			}

			$articles_embedded_data .= "\n ===START POST=== " . $article_data['post_title'] . ' - ' . $article_data['post_content'] . ' ===END POST===';
		}

		$this->source_post_ids = $this->rank_sources( $source_scores );

		return $articles_embedded_data;
	}

	/**
	 * Order de-duplicated sources by relevance, drop weak matches and return
	 * their post IDs.
	 *
	 * A single fixed score cutoff does not travel well — the score of a genuinely
	 * relevant match varies a lot between queries — so sources are kept relative
	 * to the best match: anything scoring within a ratio of the top result is
	 * kept, and the low-scoring stragglers that merely cleared the context
	 * threshold are dropped. The strongest source is always retained.
	 *
	 * @since 1.4.2
	 *
	 * @param array<int|string, float> $source_scores Map of source post ID to its best score.
	 *
	 * @return array<int, int|string> Post IDs ordered by score, highest first.
	 */
	private function rank_sources( $source_scores ) {
		if ( empty( $source_scores ) ) {
			return [];
		}

		arsort( $source_scores );

		$top_score = reset( $source_scores );

		/**
		 * Filters how close to the best match a source must score to be shown as
		 * a source link, as a ratio of the top score (0–1). A higher value keeps
		 * only near-equal matches; 0 keeps everything above the context threshold.
		 *
		 * @since 1.4.2
		 *
		 * @param float $ratio The minimum score ratio relative to the best match. Default 0.8.
		 */
		$ratio     = (float) apply_filters( 'hyve_source_link_score_ratio', 0.8 );
		$threshold = $top_score * $ratio;

		$source_scores = array_filter(
			$source_scores,
			function ( $score ) use ( $threshold ) {
				return $score >= $threshold;
			}
		);

		return array_keys( $source_scores );
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
		$this->source_post_ids = [];

		if ( Qdrant_API::is_active() ) {
			return $this->search_knowledge_base_qdrant( $message_vector, $similarity_score_threshold, $tokens_threshold );
		}

		return $this->search_knowledge_base_wp( $message_vector, $similarity_score_threshold, $tokens_threshold );
	}

	/**
	 * Build the text used for knowledge base retrieval.
	 *
	 * Retrieval embeds this text and searches the knowledge base with it. For the
	 * first message it is just the question. For follow-ups it also blends in the
	 * most recent turns of the conversation, so a topic-less question such as
	 * "How difficult is it?" still carries the subject ("pickleball") into the
	 * search and matches the relevant content, instead of embedding a query with
	 * no topic that finds nothing. The model already receives the conversation
	 * history through the OpenAI conversation; this closes the same gap for
	 * retrieval.
	 *
	 * @param string     $message   The current user message.
	 * @param int|string $record_id The thread post ID, when the conversation exists.
	 * @param string     $thread_id The OpenAI conversation ID, when one exists.
	 *
	 * @return string
	 */
	private function build_retrieval_query( $message, $record_id, $thread_id = '' ) {
		$history = [];

		if ( ! empty( $record_id ) && 'hyve_threads' === get_post_type( (int) $record_id ) ) {
			$thread_data = get_post_meta( (int) $record_id, '_hyve_thread_data', true );

			if ( is_array( $thread_data ) ) {
				$history = $thread_data;
			}
		}

		/**
		 * Filters how many recent messages are blended into the retrieval query.
		 *
		 * Set to 0 to disable conversation-aware retrieval and search with the
		 * current message only.
		 *
		 * @since 1.5.0
		 *
		 * @param int    $count     Number of most recent messages to include. Default 6.
		 * @param string $thread_id The OpenAI conversation ID, when one exists.
		 */
		$count = (int) apply_filters( 'hyve_retrieval_history_count', 6, $thread_id );

		/**
		 * Filters the per-message character cap for the retrieval query.
		 *
		 * Keeps a single long turn from dominating or bloating the embedded query.
		 *
		 * @since 1.5.0
		 *
		 * @param int $length Maximum characters kept per message. Default 500.
		 */
		$length = (int) apply_filters( 'hyve_retrieval_history_message_length', 500 );

		$parts = [];

		if ( $count > 0 && ! empty( $history ) ) {
			$recent = array_slice( $history, - $count );

			foreach ( $recent as $entry ) {
				if ( empty( $entry['message'] ) ) {
					continue;
				}

				$text = trim( wp_strip_all_tags( (string) $entry['message'] ) );

				if ( '' === $text ) {
					continue;
				}

				if ( mb_strlen( $text ) > $length ) {
					$text = mb_substr( $text, 0, $length );
				}

				$parts[] = $text;
			}
		}

		// The current question goes last so it carries the most weight.
		$parts[] = $message;

		$query = implode( "\n", $parts );

		/**
		 * Filters the final text used for knowledge base retrieval.
		 *
		 * Allows replacing the assembled query, for example with a rewritten
		 * standalone question, before it is embedded and searched.
		 *
		 * @since 1.5.0
		 *
		 * @param string                            $query   The assembled retrieval query.
		 * @param string                            $message The current user message.
		 * @param array<int, array<string, mixed>>  $history The thread history considered.
		 */
		return apply_filters( 'hyve_retrieval_query', $query, $message, $history );
	}

	/**
	 * Resolve a public source link for a knowledge base source post.
	 *
	 * Returns a link only for publicly accessible content. Regular WordPress
	 * posts are linked when their visibility is public; other source types
	 * (e.g. the pro plugin's website links or custom data) are handled through
	 * the `hyve_chat_source_link` filter.
	 *
	 * @since 1.4.2
	 *
	 * @param int|string $post_id Source post ID.
	 *
	 * @return string Public URL, or empty string when no link should be shown.
	 */
	private function resolve_source_link( $post_id ) {
		$default   = '';
		$source_id = (int) $post_id;
		$post_type = get_post_type( $source_id );

		if (
			$post_type &&
			'public' === $this->get_post_visibility( $source_id ) &&
			is_post_type_viewable( $post_type )
		) {
			$permalink = get_permalink( $source_id );
			$default   = $permalink ? $permalink : '';
		}

		/**
		 * Filters the public source link appended to a chat answer.
		 *
		 * Return an empty string to omit the link (for content that is not
		 * publicly accessible, such as custom data). The pro plugin uses this
		 * to resolve website-URL sources and to suppress links for custom data.
		 *
		 * @since 1.4.2
		 *
		 * @param string     $default The default resolved URL (empty for non-public content).
		 * @param int|string $post_id The source post ID.
		 */
		$url = apply_filters( 'hyve_chat_source_link', $default, $post_id );

		return ! empty( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Append a "Answer provided based on" source link to a chat response.
	 *
	 * @since 1.4.2
	 *
	 * @param string $response The chat response HTML.
	 * @param string $run_id   The run ID used to look up the stored source.
	 *
	 * @return string The response, with the source link appended when available.
	 */
	private function append_source_link( $response, $run_id ) {
		$transient_key = 'hyve_source_' . $run_id;
		$post_ids      = get_transient( $transient_key );
		delete_transient( $transient_key );

		return $this->maybe_append_source_link( $response, $post_ids );
	}

	/**
	 * Append source links to a chat response for the publicly accessible sources.
	 *
	 * Renders up to a filterable number of links (default 3), keeping the highest
	 * scoring public sources and skipping any that have no public URL.
	 *
	 * @since 1.4.2
	 *
	 * @param string                            $response The chat response HTML.
	 * @param array<int, int|string>|int|string $post_ids Source post IDs, ordered by relevance. A single ID is accepted for convenience.
	 *
	 * @return string
	 */
	public function maybe_append_source_link( $response, $post_ids ) {
		if ( empty( $post_ids ) ) {
			return $response;
		}

		$post_ids = is_array( $post_ids ) ? $post_ids : [ $post_ids ];

		/**
		 * Filters the maximum number of source links appended to a chat answer.
		 *
		 * @since 1.4.2
		 *
		 * @param int $limit The maximum number of source links. Default 3.
		 */
		$limit = (int) apply_filters( 'hyve_source_link_limit', 3 );

		if ( $limit < 1 ) {
			return $response;
		}

		$links = [];

		foreach ( $post_ids as $post_id ) {
			$link = $this->build_source_link( $post_id, count( $links ) + 1 );

			if ( '' === $link ) {
				continue;
			}

			$links[] = $link;

			if ( count( $links ) >= $limit ) {
				break;
			}
		}

		if ( empty( $links ) ) {
			return $response;
		}

		$intro = '<span class="hyve-source__intro">' . esc_html__( 'Sources', 'hyve-lite' ) . '</span>';

		return $response . '<div class="hyve-source">' . $intro . implode( '', $links ) . '</div>';
	}

	/**
	 * Build the markup for a single numbered source link, or an empty string
	 * when the source is not publicly accessible.
	 *
	 * @since 1.4.2
	 *
	 * @param int|string $post_id The source post ID.
	 * @param int        $number  The 1-based position shown on the citation chip.
	 *
	 * @return string
	 */
	private function build_source_link( $post_id, $number ) {
		if ( empty( $post_id ) ) {
			return '';
		}

		$url = $this->resolve_source_link( $post_id );

		if ( empty( $url ) ) {
			return '';
		}

		$title = get_the_title( (int) $post_id );
		$title = ! empty( $title ) ? $title : $url;

		/* translators: 1: citation number, 2: source page title. */
		$label = sprintf( __( 'Source %1$d: %2$s', 'hyve-lite' ), $number, $title );

		return sprintf(
			'<a class="hyve-source__link" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s">%3$d<span class="hyve-source__label">%4$s</span></a>',
			esc_url( $url ),
			esc_attr( $label ),
			(int) $number,
			esc_html( $title )
		);
	}

	/**
	 * Send chat.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function send_chat( $request ) {
		$prepared = $this->prepare_chat( $request );

		if ( is_wp_error( $prepared ) ) {
			return rest_ensure_response(
				[
					'error' => $this->get_error_message( $prepared ),
					'code'  => $prepared->get_error_code(),
				]
			);
		}

		$is_test        = (bool) $request->get_param( 'is_test' );
		$request_record = $request->get_param( 'record_id' );
		$request_record = $request_record ? $request_record : null;

		// Streaming path: stash the prepared turn and hand back a token the
		// streaming endpoint (admin-ajax) will use to generate and record the
		// reply. The user message is recorded there, once, when the reply lands.
		if ( 'stream' === $request->get_param( 'mode' ) ) {
			$token = wp_generate_password( 24, false );

			set_transient(
				'hyve_stream_job_' . $token,
				[
					'thread_id'       => $prepared['thread_id'],
					'record_id'       => $request_record,
					'message'         => $prepared['message'],
					'context'         => $prepared['context'],
					'is_test'         => $is_test,
					'source_post_ids' => $this->source_post_ids,
				],
				5 * MINUTE_IN_SECONDS
			);

			return rest_ensure_response(
				[
					'thread_id'    => $prepared['thread_id'],
					'record_id'    => $request_record,
					'stream_token' => $token,
					'content'      => $prepared['context'],
				]
			);
		}

		// Default path: background run + client polling (unchanged behavior).
		$thread_id = $prepared['thread_id'];
		$query_run = $this->create_background_run( $prepared['context'], $prepared['message'], $thread_id );

		if ( is_wp_error( $query_run ) ) {
			return rest_ensure_response( [ 'error' => $this->get_error_message( $query_run ) ] );
		}

		// Test chats from the admin live preview are not recorded as threads, so
		// they never pollute the conversation history or the analytics charts.
		$record_id = $is_test ? null : apply_filters( 'hyve_chat_request', $thread_id, $request_record, $prepared['message'] );

		return rest_ensure_response(
			[
				'thread_id' => $thread_id,
				'query_run' => $query_run,
				'record_id' => $record_id ? $record_id : null,
				'content'   => $prepared['context'],
			]
		);
	}

	/**
	 * Prepare a chat turn before a model run is created.
	 *
	 * Runs the shared, transport-agnostic work: moderation, embeddings,
	 * knowledge base search and conversation/thread creation. Used by both the
	 * streaming and the background reply flows so each runs once per turn. The
	 * user message is recorded by the caller (so it happens exactly once,
	 * whichever flow ultimately answers).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 *
	 * @return array{thread_id:string,message:string,context:string}|\WP_Error
	 */
	private function prepare_chat( $request ) {
		$message = $request->get_param( 'message' );

		if ( empty( $message ) ) {
			return new \WP_Error( 'missing_message', __( 'Message was flagged.', 'hyve-lite' ) );
		}

		$moderation = OpenAI::instance()->moderate_chunks( $message );

		if ( true !== $moderation ) {
			return new \WP_Error( 'content_flagged', __( 'Message was flagged.', 'hyve-lite' ) );
		}

		$openai          = OpenAI::instance();
		$record_id       = $request->get_param( 'record_id' );
		$record_id       = $record_id ? $record_id : null;
		$retrieval_query = $this->build_retrieval_query( $message, $record_id, $request->get_param( 'thread_id' ) );
		$message_vector  = $openai->create_embeddings( $retrieval_query );

		if ( is_wp_error( $message_vector ) ) {
			return new \WP_Error( 'no_embeddings', __( 'No embeddings found.', 'hyve-lite' ) );
		}

		$message_vector = reset( $message_vector );
		$message_vector = $message_vector->embedding;

		if ( $request->get_param( 'thread_id' ) ) {
			$thread_id = $request->get_param( 'thread_id' );
		} else {
			$thread_id = $openai->create_conversation();
		}

		if ( is_wp_error( $thread_id ) ) {
			return $thread_id;
		}

		/**
		 * Filters the similarity score threshold for knowledge base search.
		 *
		 * The similarity score threshold determines the minimum cosine similarity
		 * required for an article to be considered relevant to the user's query.
		 * A higher value means stricter matching, while a lower value allows for
		 * broader results.
		 *
		 * @since 1.4.0
		 *
		 * @param float $similarity_score_threshold The similarity score threshold. Default 0.4.
		 */
		$similarity_score_threshold = apply_filters( 'hyve_similarity_score_threshold', 0.4 );

		$article_context = $this->search_knowledge_base( $message_vector, $similarity_score_threshold );

		$hash = hash( 'md5', strtolower( $message ) );
		// TTL must outlast the slowest reply (streaming can run up to the 120s
		// cURL cap) so the embedding is still available when hyve_chat_response
		// fires and unanswered-question analytics can read it.
		set_transient( 'hyve_message_' . $hash, $message_vector, 5 * MINUTE_IN_SECONDS );

		return [
			'thread_id' => $thread_id,
			'message'   => $message,
			'context'   => $article_context,
		];
	}

	/**
	 * Create a background model run for the poll-based reply flow.
	 *
	 * Mirrors the original send_chat behavior, including the retry when the
	 * conversation has expired on OpenAI's side.
	 *
	 * @param string $context   Knowledge base context.
	 * @param string $message   User message.
	 * @param string $thread_id Conversation id (by reference; recreated if expired).
	 *
	 * @return string|\WP_Error Run id or error.
	 */
	private function create_background_run( $context, $message, &$thread_id ) {
		$openai = OpenAI::instance();

		$items = OpenAI::build_chat_items( $context, $message );

		$query_run = $openai->create_response( $items, $thread_id );

		if ( is_wp_error( $query_run ) && strpos( $this->get_error_message( $query_run ), 'Conversation with id' ) !== false ) {
			$new_thread = $openai->create_conversation();

			if ( is_wp_error( $new_thread ) ) {
				return $new_thread;
			}

			$thread_id = $new_thread;
			$query_run = $openai->create_response( $items, $thread_id );
		}

		if ( ! empty( $this->source_post_ids ) && is_string( $query_run ) ) {
			set_transient( 'hyve_source_' . $query_run, $this->source_post_ids, HOUR_IN_SECONDS );
		}
		return $query_run;
	}
}
