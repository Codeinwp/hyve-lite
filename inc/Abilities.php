<?php
/**
 * Abilities class.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Class Abilities
 *
 * Registers the administration abilities (knowledge sources, retrieval test,
 * chat settings, leads and unanswered questions) with the WordPress Abilities
 * API. Every ability wraps the code the dashboard REST routes already run and
 * uses the same capability as the matching route.
 */
class Abilities {

	/**
	 * Ability category slug.
	 *
	 * @var string
	 */
	const CATEGORY = 'hyve-admin';

	/**
	 * Page size of the knowledge sources listing (matches the dashboard).
	 *
	 * @var int
	 */
	const SOURCES_PER_PAGE = 20;

	/**
	 * Most sitemap links accepted in one call.
	 *
	 * @var int
	 */
	const MAX_LINKS = 500;

	/**
	 * Settings the chat policy abilities never read or write: credentials,
	 * the connection mode and admin-only preferences.
	 *
	 * @var array<int, string>
	 */
	const EXCLUDED_SETTINGS = [ 'ai_mode', 'api_key', 'qdrant_api_key', 'qdrant_endpoint', 'telemetry_enabled', 'post_row_addon_enabled', 'webhook_url', 'webhook_events' ];

	/**
	 * Abilities constructor.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register the ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Hyve administration', 'hyve-lite' ),
				'description' => __( 'Manage the Hyve knowledge base and chatbot settings.', 'hyve-lite' ),
			]
		);
	}

	/**
	 * Register the abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->get_definitions() as $name => $definition ) {
			$definition['category'] = self::CATEGORY;

			$definition['meta'] = [
				'annotations'  => $definition['annotations'],
				'show_in_rest' => true,
			];

			if ( isset( $definition['task'] ) ) {
				$definition['meta']['task'] = $definition['task'];
			}

			unset( $definition['annotations'], $definition['task'] );

			wp_register_ability( $name, $definition );
		}
	}

	/**
	 * The ability definitions, keyed by ability name.
	 *
	 * @return array<lowercase-string&non-falsy-string, array<string, mixed>>
	 */
	private function get_definitions() {
		$source_schema = [
			'type'       => 'object',
			'properties' => [
				'id'         => [ 'type' => 'integer' ],
				'title'      => [ 'type' => 'string' ],
				'type'       => [ 'type' => 'string' ],
				'type_label' => [ 'type' => 'string' ],
				'ref'        => [ 'type' => 'string' ],
				'state'      => [ 'type' => 'string' ],
				'visibility' => [ 'type' => 'string' ],
				'chunks'     => [ 'type' => 'integer' ],
				'synced'     => [ 'type' => 'boolean' ],
				'error'      => [ 'type' => 'string' ],
			],
		];

		$progress_schema = [
			'type'       => 'object',
			'properties' => [
				'current' => [ 'type' => 'integer' ],
				'total'   => [ 'type' => 'integer' ],
				'message' => [ 'type' => 'string' ],
			],
		];

		return [
			'hyve/list-knowledge-sources'    => [
				'label'               => __( 'List knowledge sources', 'hyve-lite' ),
				'description'         => __( 'List the content in the Hyve knowledge base with its type, indexing state, chunk count and processing error. Filter by post_id or state. Pass the job_id returned by hyve/upsert-knowledge-source to get the state and progress of that import.', 'hyve-lite' ),
				'input_schema'        => [
					'type'       => 'object',
					'default'    => [],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'Return only the source with this post ID.',
						],
						'state'   => [
							'type'        => 'string',
							'enum'        => [ 'indexed', 'pending', 'moderation' ],
							'description' => 'indexed: in the knowledge base. pending: edited since it was indexed and waiting for an update. moderation: rejected by content moderation. Default indexed.',
						],
						'search'  => [
							'type'        => 'string',
							'description' => 'Match the source title.',
						],
						'page'    => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Page number, 20 sources per page. Default 1.',
						],
						'job_id'  => [
							'type'        => 'string',
							'description' => 'job_id returned by hyve/upsert-knowledge-source. Returns the state and progress of that import; the other filters are ignored.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'job_id'       => [ 'type' => 'string' ],
						'state'        => [
							'type'        => 'string',
							'enum'        => [ 'working', 'completed', 'failed', 'cancelled' ],
							'description' => 'Only with job_id.',
						],
						'progress'     => $progress_schema,
						'skipped'      => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Sitemap pages that could not be imported. Only with a sitemap job_id.',
						],
						'sources'      => [
							'type'  => 'array',
							'items' => $source_schema,
						],
						'total'        => [ 'type' => 'integer' ],
						'page'         => [ 'type' => 'integer' ],
						'per_page'     => [ 'type' => 'integer' ],
						'has_more'     => [ 'type' => 'boolean' ],
						'total_chunks' => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'list_knowledge_sources' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'annotations'         => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
			'hyve/upsert-knowledge-source'   => [
				'label'               => __( 'Add or update a knowledge source', 'hyve-lite' ),
				'description'         => __( 'Add a post, URL, document, sitemap or manual text to the Hyve knowledge base, or reprocess an existing source with reindex. Posts work in every edition; the other types need Hyve Pro. Returns a job_id; content may be processed in the background (always for a sitemap): call hyve/list-knowledge-sources with that job_id until state is no longer working.', 'hyve-lite' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'source_id'           => [
							'type'        => 'integer',
							'description' => 'Existing source to update. Leave out to add a new source.',
						],
						'type'                => [
							'type'        => 'string',
							'enum'        => [ 'post', 'url', 'document', 'sitemap', 'manual' ],
							'description' => 'Kind of source.',
						],
						'ref'                 => [
							'type'        => 'string',
							'description' => 'post: the post ID. url: the page URL. document: the media attachment ID. sitemap: the sitemap URL.',
						],
						'title'               => [
							'type'        => 'string',
							'description' => 'Title of a manual source.',
						],
						'content'             => [
							'type'        => 'string',
							'description' => 'Text of a manual source.',
						],
						'links'               => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'sitemap: the page URLs to import. Leave out to import every page the sitemap lists.',
						],
						'reindex'             => [
							'type'        => 'boolean',
							'description' => 'Reprocess the existing source given in source_id. Default false.',
						],
						'override_moderation' => [
							'type'        => 'boolean',
							'description' => 'Add the content even though moderation flagged it. Default false.',
						],
						'confirm_sensitive'   => [
							'type'        => 'boolean',
							'description' => 'document: import even though sensitive data was detected. Default false.',
						],
					],
					'required'   => [ 'type' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'source'  => $source_schema,
						'status'  => [ 'type' => 'string' ],
						'warning' => [ 'type' => 'string' ],
						'job_id'  => [
							'type'        => 'string',
							'description' => 'Reference to pass to hyve/list-knowledge-sources for the import state.',
						],
					],
				],
				'execute_callback'    => [ $this, 'upsert_knowledge_source' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'annotations'         => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
				'task'                => [
					'mode'           => 'poll',
					'status_ability' => 'hyve/list-knowledge-sources',
				],
			],
			'hyve/remove-source'             => [
				'label'               => __( 'Remove a knowledge source', 'hyve-lite' ),
				'description'         => __( 'Remove a source from the Hyve knowledge base. A post stays on the site; a URL, document, sitemap page or manual entry is deleted permanently.', 'hyve-lite' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'source_id' => [
							'type'        => 'integer',
							'description' => 'Post ID of the source.',
						],
					],
					'required'   => [ 'source_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'removed'   => [ 'type' => 'boolean' ],
						'source_id' => [ 'type' => 'integer' ],
						'permanent' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'remove_source' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'annotations'         => [
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				],
			],
			'hyve/test-retrieval'            => [
				'label'               => __( 'Test knowledge base retrieval', 'hyve-lite' ),
				'description'         => __( 'Run a question against the Hyve knowledge base and return the sources and chunks it retrieves. Changes nothing on the site, but calls the OpenAI moderation and embeddings API, which may incur cost.', 'hyve-lite' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query'     => [
							'type'        => 'string',
							'description' => 'The question to test.',
						],
						'threshold' => [
							'type'        => 'number',
							'minimum'     => 0,
							'maximum'     => 1,
							'description' => 'Minimum similarity score. Defaults to the saved chat setting.',
						],
						'limit'     => [
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 20,
							'description' => 'Most chunks to return. Default 10.',
						],
					],
					'required'   => [ 'query' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'query'     => [ 'type' => 'string' ],
						'threshold' => [ 'type' => 'number' ],
						'sources'   => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'    => [ 'type' => 'integer' ],
									'title' => [ 'type' => 'string' ],
									'ref'   => [ 'type' => 'string' ],
								],
							],
						],
						'chunks'    => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'source_id' => [ 'type' => 'integer' ],
									'title'     => [ 'type' => 'string' ],
									'score'     => [ 'type' => 'number' ],
									'tokens'    => [ 'type' => 'integer' ],
									'included'  => [ 'type' => 'boolean' ],
								],
							],
						],
					],
				],
				'execute_callback'    => [ $this, 'test_retrieval' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'annotations'         => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
			'hyve/get-chat-policy'           => [
				'label'               => __( 'Get chatbot settings', 'hyve-lite' ),
				'description'         => __( 'Read the Hyve chatbot display, message, fallback and skill settings. Credentials are never returned.', 'hyve-lite' ),
				'input_schema'        => [
					'type'                 => 'object',
					'default'              => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'settings'   => [ 'type' => 'object' ],
						'pro_active' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'get_chat_policy' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'annotations'         => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
			'hyve/update-chat-policy'        => [
				'label'               => __( 'Update chatbot settings', 'hyve-lite' ),
				'description'         => __( 'Update the Hyve chatbot display, message, fallback and skill settings. Only the given settings change. Settings that belong to Hyve Pro need an active license.', 'hyve-lite' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'settings' => [
							'type'       => 'object',
							'properties' => $this->get_policy_schema(),
						],
					],
					'required'   => [ 'settings' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'updated'  => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'message'  => [ 'type' => 'string' ],
						'settings' => [ 'type' => 'object' ],
					],
				],
				'execute_callback'    => [ $this, 'update_chat_policy' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'annotations'         => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
			'hyve/list-leads'                => [
				'label'               => __( 'List captured leads', 'hyve-lite' ),
				'description'         => __( 'List the leads captured by the Hyve chat contact form, newest first. Returns personal data. Needs Hyve Pro.', 'hyve-lite' ),
				'input_schema'        => [
					'type'       => 'object',
					'default'    => [],
					'properties' => [
						'id'   => [
							'type'        => 'integer',
							'description' => 'Return only this lead.',
						],
						'page' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Page number, 10 leads per page. Default 1.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'leads'    => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'        => [ 'type' => 'integer' ],
									'title'     => [ 'type' => 'string' ],
									'date'      => [ 'type' => 'string' ],
									'fields'    => [ 'type' => 'object' ],
									'thread_id' => [ 'type' => 'integer' ],
									'page_url'  => [ 'type' => 'string' ],
								],
							],
						],
						'total'    => [ 'type' => 'integer' ],
						'page'     => [ 'type' => 'integer' ],
						'per_page' => [ 'type' => 'integer' ],
						'has_more' => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ $this, 'list_leads' ],
				'permission_callback' => [ $this, 'can_read_messages' ],
				'annotations'         => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
			'hyve/list-unanswered-questions' => [
				'label'               => __( 'List unanswered questions', 'hyve-lite' ),
				'description'         => __( 'List the visitor questions the Hyve chatbot could not answer, most frequent first. Needs Hyve Pro.', 'hyve-lite' ),
				'input_schema'        => [
					'type'       => 'object',
					'default'    => [],
					'properties' => [
						'limit' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 20,
							'description' => 'Most questions to return. Default 20.',
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'questions' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'       => [ 'type' => 'string' ],
									'question' => [ 'type' => 'string' ],
									'count'    => [ 'type' => 'integer' ],
								],
							],
						],
						'total'     => [ 'type' => 'integer' ],
					],
				],
				'execute_callback'    => [ $this, 'list_unanswered_questions' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'annotations'         => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		];
	}

	/**
	 * Whether the user can manage Hyve, as the dashboard REST routes check.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether the user can read conversations and leads, as the threads and
	 * leads REST routes check.
	 *
	 * @return bool
	 */
	public function can_read_messages() {
		return current_user_can( 'hyve_read_messages' );
	}

	/**
	 * List the knowledge sources.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_knowledge_sources( $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		if ( isset( $input['job_id'] ) && '' !== $input['job_id'] ) {
			return $this->get_job_status( (string) $input['job_id'] );
		}

		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$page    = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$state   = isset( $input['state'] ) ? sanitize_key( (string) $input['state'] ) : '';
		$states  = [
			'indexed'    => 'included',
			'pending'    => 'pending',
			'moderation' => 'moderation',
		];

		if ( '' !== $state && ! isset( $states[ $state ] ) ) {
			return new \WP_Error( 'hyve_invalid_state', __( 'Unknown state. Use indexed, pending or moderation.', 'hyve-lite' ) );
		}

		if ( $post_id > 0 ) {
			$actual = $this->get_source_state( $post_id );

			if ( '' === $actual || ( '' !== $state && $state !== $actual ) ) {
				return [
					'sources'      => [],
					'total'        => 0,
					'page'         => 1,
					'per_page'     => self::SOURCES_PER_PAGE,
					'has_more'     => false,
					'total_chunks' => 0,
				];
			}

			$state = $actual;
			$page  = 1;
		}

		if ( '' === $state ) {
			$state = 'indexed';
		}

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'type', 'any' );
		$request->set_param( 'status', $states[ $state ] );
		$request->set_param( 'offset', ( $page - 1 ) * self::SOURCES_PER_PAGE );

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', sanitize_text_field( (string) $input['search'] ) );
		}

		$narrow = function ( $args ) use ( $post_id ) {
			if ( $post_id > 0 ) {
				$args['post__in']  = [ $post_id ];
				$args['post_type'] = get_post_type( $post_id );
			}

			return $args;
		};

		add_filter( 'hyve_data_query_args', $narrow, 99 );
		$data = API::instance()->get_data( $request )->get_data();
		remove_filter( 'hyve_data_query_args', $narrow, 99 );

		$sources = [];

		foreach ( (array) $data['posts'] as $row ) {
			$sources[] = $this->format_source( (array) $row );
		}

		return [
			'sources'      => $sources,
			'total'        => (int) $data['total'],
			'page'         => $page,
			'per_page'     => self::SOURCES_PER_PAGE,
			'has_more'     => (bool) $data['more'],
			'total_chunks' => (int) $data['totalChunks'],
		];
	}

	/**
	 * Add, update or reindex a knowledge source.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function upsert_knowledge_source( $input = [] ) {
		$input     = is_array( $input ) ? $input : [];
		$type      = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
		$source_id = isset( $input['source_id'] ) ? absint( $input['source_id'] ) : 0;
		$reindex   = ! empty( $input['reindex'] );
		$override  = ! empty( $input['override_moderation'] );
		$ref       = isset( $input['ref'] ) ? trim( (string) $input['ref'] ) : '';

		if ( ! in_array( $type, [ 'post', 'url', 'document', 'sitemap', 'manual' ], true ) ) {
			return new \WP_Error( 'hyve_invalid_type', __( 'Unknown source type. Use post, url, document, sitemap or manual.', 'hyve-lite' ) );
		}

		if ( $reindex && 0 === $source_id ) {
			return new \WP_Error( 'hyve_missing_source', __( 'source_id is required to reindex a source.', 'hyve-lite' ) );
		}

		if ( $source_id > 0 && $type !== $this->get_source_type( $source_id ) ) {
			return new \WP_Error( 'hyve_source_not_found', __( 'No source of this type has that ID.', 'hyve-lite' ) );
		}

		$created = [];
		$capture = function ( $post_id, $post ) use ( &$created ) {
			if ( 'hyve_docs' === $post->post_type ) {
				$created[] = (int) $post_id;
			}
		};

		add_action( 'wp_insert_post', $capture, 10, 2 );

		try {
			if ( 'post' === $type ) {
				$source_id = $source_id > 0 ? $source_id : absint( $ref );
				$result    = $this->upsert_post( $source_id, $override );
			} else {
				/**
				 * Filters the result of adding or updating a knowledge source
				 * that Hyve Pro owns (url, document, sitemap, manual).
				 *
				 * @param true|\WP_Error|null  $result Null while no edition handled the source.
				 * @param string               $type   Source type.
				 * @param array<string, mixed> $args   Sanitized source arguments.
				 */
				$result = apply_filters(
					'hyve_abilities_upsert_source',
					null,
					$type,
					[
						'source_id'         => $source_id,
						'ref'               => $ref,
						'title'             => isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '',
						'content'           => isset( $input['content'] ) ? wp_kses_post( (string) $input['content'] ) : '',
						'links'             => $this->sanitize_links( $input['links'] ?? [] ),
						'override'          => $override,
						'confirm_sensitive' => ! empty( $input['confirm_sensitive'] ),
					]
				);

				if ( null === $result ) {
					$result = $this->pro_required();
				}
			}
		} catch ( \Exception $e ) {
			$result = new \WP_Error( 'hyve_upsert_failed', $e->getMessage() );
		}

		remove_action( 'wp_insert_post', $capture, 10 );

		if ( is_wp_error( $result ) ) {
			return $this->readable_error( $result );
		}

		if ( 'sitemap' === $type ) {
			return [
				'status' => 'queued',
				'job_id' => 'sitemap:' . md5( $ref ),
			];
		}

		if ( 0 === $source_id && ! empty( $created ) ) {
			$source_id = (int) end( $created );
		}

		$response = [
			'status' => 'saved',
		];

		if ( $source_id > 0 ) {
			$response['job_id'] = 'source:' . $source_id;
		}

		if ( $source_id > 0 && '' !== $this->get_source_state( $source_id ) ) {
			$listed = $this->list_knowledge_sources( [ 'post_id' => $source_id ] );

			if ( ! is_wp_error( $listed ) && ! empty( $listed['sources'] ) ) {
				$response['source'] = $listed['sources'][0];
			}

			$processing_error = get_post_meta( $source_id, '_hyve_processing_error', true );

			if ( ! empty( $processing_error ) && is_string( $processing_error ) ) {
				$response['warning'] = $processing_error;
			}
		}

		return $response;
	}

	/**
	 * The state of an import started by the upsert ability.
	 *
	 * The job ID is a stateless reference: `source:<post ID>` for a source that
	 * was saved in the request, `sitemap:<hash>` for a sitemap queue.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function get_job_status( $job_id ) {
		if ( ! preg_match( '/^(source|sitemap):([a-f0-9]+)$/', $job_id, $matches ) ) {
			return new \WP_Error( 'hyve_invalid_job_id', __( 'Unknown job_id. Use the job_id returned by hyve/upsert-knowledge-source.', 'hyve-lite' ) );
		}

		if ( 'sitemap' === $matches[1] ) {
			/**
			 * Filters the state of a sitemap import, which Hyve Pro owns.
			 *
			 * @param array<string, mixed>|\WP_Error|null $status Null while no edition handled the job.
			 * @param string                              $hash   Sitemap hash.
			 */
			$status = apply_filters( 'hyve_abilities_sitemap_status', null, $matches[2] );

			if ( null === $status ) {
				return $this->pro_required();
			}

			if ( is_wp_error( $status ) ) {
				return $status;
			}

			return array_merge( [ 'job_id' => $job_id ], (array) $status );
		}

		$source_id = absint( $matches[2] );
		$listed    = $this->list_knowledge_sources( [ 'post_id' => $source_id ] );
		$listed    = is_wp_error( $listed ) ? [] : $listed;
		$source    = ! empty( $listed['sources'] ) ? $listed['sources'][0] : [];
		$state     = 'completed';
		$message   = __( 'The source is in the knowledge base.', 'hyve-lite' );

		if ( empty( $source ) ) {
			$state   = 'failed';
			$message = __( 'This content is not in the knowledge base.', 'hyve-lite' );
		} elseif ( 'moderation' === $source['state'] ) {
			$state   = 'failed';
			$message = __( 'The content failed the moderation check.', 'hyve-lite' );
		} elseif ( 'pending' === $source['state'] || $this->has_scheduled_chunks( $source_id ) ) {
			$state   = 'working';
			$message = ! empty( $source['error'] ) ? $source['error'] : __( 'The source is waiting to be processed.', 'hyve-lite' );
		} elseif ( ! empty( $source['error'] ) ) {
			$state   = 'failed';
			$message = $source['error'];
		}

		return array_merge(
			$listed,
			[
				'job_id'   => $job_id,
				'state'    => $state,
				'progress' => [
					'current' => 'completed' === $state ? 1 : 0,
					'total'   => 1,
					'message' => $message,
				],
			]
		);
	}

	/**
	 * Whether a source still has chunks waiting for the background retry.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool
	 */
	private function has_scheduled_chunks( $post_id ) {
		foreach ( DB_Table::instance()->get_by_status( 'scheduled' ) as $row ) {
			if ( (int) $row->post_id === $post_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add a post to the knowledge base or reprocess it.
	 *
	 * @param int  $post_id  Post ID.
	 * @param bool $override Skip the moderation gate.
	 *
	 * @return true|\WP_Error
	 * @throws \Exception If Qdrant API fails.
	 */
	private function upsert_post( $post_id, $override ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post || 'hyve_docs' === $post->post_type || ! in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			return new \WP_Error( 'hyve_post_not_found', __( 'ref must be the ID of a published or private post.', 'hyve-lite' ) );
		}

		$post_types = get_post_types( [ 'public' => true ] );

		if ( ! isset( $post_types[ $post->post_type ] ) ) {
			return new \WP_Error( 'hyve_post_type_unsupported', __( 'This post type cannot be added to the knowledge base.', 'hyve-lite' ) );
		}

		if ( $override ) {
			$action = 'override';
		} else {
			$action = get_post_meta( $post_id, '_hyve_added', true ) ? 'update' : 'add';
		}

		return DB_Table::instance()->add_post( $post_id, $action );
	}

	/**
	 * Remove a source from the knowledge base.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_source( $input = [] ) {
		$input     = is_array( $input ) ? $input : [];
		$source_id = isset( $input['source_id'] ) ? absint( $input['source_id'] ) : 0;

		if ( 0 === $source_id || '' === $this->get_source_state( $source_id ) ) {
			return new \WP_Error( 'hyve_source_not_found', __( 'This content is not in the knowledge base.', 'hyve-lite' ) );
		}

		$permanent = 'hyve_docs' === get_post_type( $source_id );

		$request = new \WP_REST_Request( 'DELETE' );
		$request->set_param( 'id', $source_id );

		try {
			$data = API::instance()->delete_data( $request )->get_data();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'hyve_remove_failed', $e->getMessage() );
		}

		if ( is_array( $data ) && ! empty( $data['error'] ) ) {
			return new \WP_Error( 'hyve_remove_failed', (string) $data['error'] );
		}

		return [
			'removed'   => true,
			'source_id' => $source_id,
			'permanent' => $permanent,
		];
	}

	/**
	 * Run a question against the knowledge base.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function test_retrieval( $input = [] ) {
		$input = is_array( $input ) ? $input : [];
		$query = isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
		$limit = isset( $input['limit'] ) ? min( 20, max( 1, absint( $input['limit'] ) ) ) : 10;

		if ( '' === $query ) {
			return new \WP_Error( 'hyve_missing_query', __( 'A query is required.', 'hyve-lite' ) );
		}

		// Hyve Connect retrieves on the hosted platform, which has no search endpoint.
		if ( Hyve_Connect::is_active() ) {
			return new \WP_Error( 'hyve_retrieval_unavailable', __( 'Retrieval cannot be tested while Hyve Connect is active.', 'hyve-lite' ) );
		}

		if ( ! Main::is_api_key_connected( Main::get_settings() ) ) {
			return new \WP_Error( 'hyve_api_key_missing', __( 'Connect a valid OpenAI API key first.', 'hyve-lite' ) );
		}

		$openai     = OpenAI::instance();
		$moderation = $openai->moderate_chunks( $query );

		if ( is_wp_error( $moderation ) ) {
			return $this->readable_error( $moderation );
		}

		if ( true !== $moderation ) {
			return new \WP_Error( 'hyve_query_flagged', __( 'This message was flagged by OpenAI moderation and was not answered.', 'hyve-lite' ) );
		}

		$vector = $openai->create_embeddings( $query );

		if ( is_wp_error( $vector ) ) {
			return $this->readable_error( $vector );
		}

		$vector = reset( $vector );

		if ( ! is_object( $vector ) || ! isset( $vector->embedding ) ) {
			return new \WP_Error( 'hyve_no_embeddings', __( 'Your message could not be processed. Please try again.', 'hyve-lite' ) );
		}

		if ( isset( $input['threshold'] ) && is_numeric( $input['threshold'] ) ) {
			$threshold = min( 1, max( 0, (float) $input['threshold'] ) );
		} else {
			$threshold = (float) apply_filters( 'hyve_similarity_score_threshold', 0.25 );
		}

		$api = API::instance();
		$api->search_knowledge_base( $vector->embedding, $threshold );

		$retrieval = $api->get_last_retrieval();
		$sources   = [];
		$chunks    = [];

		foreach ( $retrieval['sources'] as $source_id ) {
			$source_id = (int) $source_id;
			$sources[] = [
				'id'    => $source_id,
				'title' => html_entity_decode( get_the_title( $source_id ), ENT_QUOTES, 'UTF-8' ),
				'ref'   => $this->get_source_ref( $source_id ),
			];
		}

		foreach ( array_slice( $retrieval['chunks'], 0, $limit ) as $chunk ) {
			$chunks[] = [
				'source_id' => isset( $chunk['post_id'] ) ? (int) $chunk['post_id'] : 0,
				'title'     => isset( $chunk['title'] ) ? (string) $chunk['title'] : '',
				'score'     => isset( $chunk['score'] ) ? (float) $chunk['score'] : 0.0,
				'tokens'    => isset( $chunk['tokens'] ) ? (int) $chunk['tokens'] : 0,
				'included'  => ! isset( $chunk['included'] ) || (bool) $chunk['included'],
			];
		}

		return [
			'query'     => $query,
			'threshold' => $threshold,
			'sources'   => $sources,
			'chunks'    => $chunks,
		];
	}

	/**
	 * Read the chatbot settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_chat_policy() {
		$settings = array_diff_key( Main::get_settings(), array_flip( self::EXCLUDED_SETTINGS ) );
		$policy   = array_intersect_key( $settings, $this->get_policy_schema() );

		return [
			'settings'   => $policy,
			'pro_active' => $this->is_pro_active(),
		];
	}

	/**
	 * Update the chatbot settings.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_chat_policy( $input = [] ) {
		$input   = is_array( $input ) ? $input : [];
		$changes = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];

		if ( empty( $changes ) ) {
			return new \WP_Error( 'hyve_no_settings', __( 'No settings were given.', 'hyve-lite' ) );
		}

		$schema     = $this->get_policy_schema();
		$pro_schema = $this->get_pro_policy_schema();

		// The keys an edition can save: Hyve Pro adds its own to this filter
		// only while its license is active.
		$pro_keys = array_keys( (array) apply_filters( 'hyve_settings_validation', [] ) );
		$defaults = Main::get_default_settings();
		$data     = [];

		foreach ( $changes as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( ! isset( $schema[ $key ] ) ) {
				return new \WP_Error(
					'hyve_unknown_setting',
					// translators: %s: setting key.
					sprintf( __( 'Unsupported setting: %s', 'hyve-lite' ), $key )
				);
			}

			if ( isset( $pro_schema[ $key ] ) && ! in_array( $key, $pro_keys, true ) ) {
				return new \WP_Error(
					'hyve_pro_required',
					// translators: %s: setting key.
					sprintf( __( 'The %s setting needs Hyve Pro with an active license.', 'hyve-lite' ), $key )
				);
			}

			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			$data[ $key ] = $this->cast_setting( $value, (string) $schema[ $key ]['type'] );
		}

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'data', $data );

		$result = API::instance()->update_settings( $request )->get_data();

		if ( is_array( $result ) && ! empty( $result['error'] ) ) {
			return new \WP_Error( 'hyve_settings_invalid', (string) $result['error'] );
		}

		$message = '';

		if ( is_array( $result ) ) {
			$message = (string) ( $result['warning'] ?? $result['success'] ?? '' );
		}

		$policy = $this->get_chat_policy();

		return [
			'updated'  => array_keys( $data ),
			'message'  => $message,
			'settings' => $policy['settings'],
		];
	}

	/**
	 * List the captured leads.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_leads( $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		/**
		 * Filters the captured leads returned by the list-leads ability.
		 *
		 * @param array<string, mixed>|\WP_Error|null $result Null while no edition handled the request.
		 * @param array<string, int>                  $args   Lead ID and page number.
		 */
		$result = apply_filters(
			'hyve_abilities_list_leads',
			null,
			[
				'id'   => isset( $input['id'] ) ? absint( $input['id'] ) : 0,
				'page' => isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1,
			]
		);

		return null === $result ? $this->pro_required() : $result;
	}

	/**
	 * List the questions the chatbot could not answer.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list_unanswered_questions( $input = [] ) {
		$input = is_array( $input ) ? $input : [];

		/**
		 * Filters the unanswered questions returned by the
		 * list-unanswered-questions ability.
		 *
		 * @param array<string, mixed>|\WP_Error|null $result Null while no edition handled the request.
		 * @param array<string, int>                  $args   Result limit.
		 */
		$result = apply_filters(
			'hyve_abilities_list_unanswered_questions',
			null,
			[
				'limit' => isset( $input['limit'] ) ? min( 20, max( 1, absint( $input['limit'] ) ) ) : 20,
			]
		);

		return null === $result ? $this->pro_required() : $result;
	}

	/**
	 * The chat settings the policy abilities cover, as JSON schema properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_policy_schema() {
		return array_merge( $this->get_lite_policy_schema(), $this->get_pro_policy_schema() );
	}

	/**
	 * The chat settings every edition has, as JSON schema properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_lite_policy_schema() {
		return [
			'display_mode'               => [
				'type'        => 'string',
				'enum'        => [ 'all', 'include', 'exclude', 'manual' ],
				'description' => 'Where the chat shows: everywhere, only on or except on the display_rules paths, or only where the block or shortcode is placed.',
			],
			'display_rules'              => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'path'     => [ 'type' => 'string' ],
						'operator' => [
							'type' => 'string',
							'enum' => [ 'contains', 'matches' ],
						],
					],
				],
			],
			'welcome_message'            => [ 'type' => 'string' ],
			'default_message'            => [
				'type'        => 'string',
				'description' => 'Fallback reply when the knowledge base has no answer.',
			],
			'chat_model'                 => [ 'type' => 'string' ],
			'similarity_score_threshold' => [
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 1,
			],
			'sound_enabled'              => [ 'type' => 'boolean' ],
			'show_timestamp'             => [ 'type' => 'boolean' ],
			'privacy_notice_enabled'     => [ 'type' => 'boolean' ],
			'chat_position'              => [
				'type' => 'string',
				'enum' => [ 'left', 'right' ],
			],
			'show_source_link'           => [ 'type' => 'boolean' ],
		];
	}

	/**
	 * The chat settings Hyve Pro owns, as JSON schema properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_pro_policy_schema() {
		return [
			'chat_name'                    => [ 'type' => 'string' ],
			'system_prompt'                => [ 'type' => 'string' ],
			'predefined_questions'         => [
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
			],
			'follow_up_questions'          => [ 'type' => 'boolean' ],
			'page_context_enabled'         => [ 'type' => 'boolean' ],
			'proactive_message'            => [ 'type' => 'string' ],
			'proactive_trigger'            => [
				'type' => 'string',
				'enum' => [ 'none', 'time', 'exit', 'scroll' ],
			],
			'proactive_delay'              => [
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 600,
			],
			'proactive_scroll_depth'       => [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			],
			'lead_capture_enabled'         => [ 'type' => 'boolean' ],
			'lead_trigger_pre_chat'        => [ 'type' => 'boolean' ],
			'lead_trigger_unanswered'      => [ 'type' => 'boolean' ],
			'lead_trigger_contact_request' => [ 'type' => 'boolean' ],
			'lead_pre_chat_required'       => [ 'type' => 'boolean' ],
			'lead_form_heading'            => [ 'type' => 'string' ],
			'lead_offer_text'              => [ 'type' => 'string' ],
			'lead_thanks_text'             => [ 'type' => 'string' ],
			'lead_already_text'            => [ 'type' => 'string' ],
			'skills_enabled'               => [ 'type' => 'boolean' ],
			'skills_allowed'               => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Names of the read-only abilities the chatbot may call.',
			],
		];
	}

	/**
	 * Cast a setting to the type its validation expects.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  JSON schema type.
	 *
	 * @return mixed
	 */
	private function cast_setting( $value, $type ) {
		switch ( $type ) {
			case 'boolean':
				return ( is_string( $value ) || is_int( $value ) ) ? rest_sanitize_boolean( $value ) : $value;
			case 'integer':
				return is_numeric( $value ) ? (int) $value : $value;
			case 'number':
				return is_numeric( $value ) ? (float) $value : $value;
			case 'array':
				return is_array( $value ) ? array_values( $value ) : $value;
			default:
				return is_scalar( $value ) ? (string) $value : $value;
		}
	}

	/**
	 * Shape a dashboard listing row as a source.
	 *
	 * @param array<string, mixed> $row Row from the data listing.
	 *
	 * @return array<string, mixed>
	 */
	private function format_source( $row ) {
		$post_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;

		$source = [
			'id'         => $post_id,
			'title'      => isset( $row['title'] ) ? (string) $row['title'] : '',
			'type'       => $this->get_source_type( $post_id ),
			'type_label' => isset( $row['type'] ) ? (string) $row['type'] : '',
			'ref'        => $this->get_source_ref( $post_id ),
			'state'      => $this->get_source_state( $post_id ),
			'visibility' => isset( $row['visibility'] ) ? (string) $row['visibility'] : '',
			'chunks'     => isset( $row['chunks'] ) ? (int) $row['chunks'] : 0,
		];

		if ( isset( $row['synced'] ) ) {
			$source['synced'] = (bool) $row['synced'];
		}

		if ( ! empty( $row['error'] ) && is_string( $row['error'] ) ) {
			$source['error'] = $row['error'];
		}

		return $source;
	}

	/**
	 * The ability source type of a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string One of post, url, document, sitemap or manual; empty when the post is missing.
	 */
	private function get_source_type( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( ! $post_type ) {
			return '';
		}

		if ( 'hyve_docs' !== $post_type ) {
			return 'post';
		}

		$types = [
			'link'     => 'url',
			'sitemap'  => 'sitemap',
			'document' => 'document',
		];

		$docs_type = (string) get_post_meta( $post_id, '_hyve_type', true );

		return $types[ $docs_type ] ?? 'manual';
	}

	/**
	 * Where a source comes from: its original URL, or its permalink.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	private function get_source_ref( $post_id ) {
		if ( 'hyve_docs' === get_post_type( $post_id ) ) {
			return (string) get_post_meta( $post_id, '_hyve_source', true );
		}

		$permalink = get_permalink( $post_id );

		return $permalink ? $permalink : '';
	}

	/**
	 * The indexing state of a post, from the meta the dashboard listings use.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string One of moderation, pending or indexed; empty when the post is not a source.
	 */
	private function get_source_state( $post_id ) {
		if ( ! get_post_type( $post_id ) ) {
			return '';
		}

		if ( get_post_meta( $post_id, '_hyve_moderation_failed', true ) ) {
			return 'moderation';
		}

		if ( get_post_meta( $post_id, '_hyve_needs_update', true ) ) {
			return 'pending';
		}

		return get_post_meta( $post_id, '_hyve_added', true ) ? 'indexed' : '';
	}

	/**
	 * Keep the valid URLs of a links input, capped.
	 *
	 * @param mixed $links Raw links.
	 *
	 * @return array<int, string>
	 */
	private function sanitize_links( $links ) {
		if ( ! is_array( $links ) ) {
			return [];
		}

		$links = array_filter( array_map( 'sanitize_url', array_filter( $links, 'is_string' ) ) );

		return array_slice( array_values( array_unique( $links ) ), 0, self::MAX_LINKS );
	}

	/**
	 * Whether Hyve Pro runs with an active license.
	 *
	 * @return bool
	 */
	private function is_pro_active() {
		return in_array( apply_filters( 'product_hyve_license_status', false ), [ 'valid', 'active_expired' ], true );
	}

	/**
	 * The error for a feature that belongs to Hyve Pro.
	 *
	 * @return \WP_Error
	 */
	private function pro_required() {
		return new \WP_Error( 'hyve_pro_required', __( 'This feature needs Hyve Pro with an active license.', 'hyve-lite' ) );
	}

	/**
	 * Swap a service error message for the readable one the dashboard shows.
	 *
	 * @param \WP_Error $error Error.
	 *
	 * @return \WP_Error
	 */
	private function readable_error( $error ) {
		$message = OpenAI::get_error_message_for_code( $error->get_error_code() );

		if ( null === $message ) {
			return $error;
		}

		return new \WP_Error( $error->get_error_code(), $message, $error->get_error_data() );
	}
}
