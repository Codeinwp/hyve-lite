<?php
/**
 * OpenAI class.
 * 
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\Main;

/**
 * OpenAI class.
 */
class OpenAI {
	/**
	 * Base URL.
	 * 
	 * @var string
	 */
	private static $base_url = 'https://api.openai.com/v1/';

	/**
	 * Prompt Version.
	 * 
	 * @var string
	 */
	private $prompt_version = '1.2.0';

	/**
	 * Chat Model.
	 * 
	 * @var string
	 */
	private $chat_model = 'gpt-4o-mini';

	/**
	 * API Key.
	 * 
	 * @var string
	 */
	private $api_key;

	/**
	 * The single instance of the class.
	 *
	 * @var OpenAI
	 */
	private static $instance = null;

	/**
	 * The service error option key for `wp_options`.
	 * 
	 * @var string
	 */
	public const ERROR_OPTION_KEY = 'hyve_open_ai_api_error';

	/**
	 * Ensures only one instance of the class is loaded.
	 *
	 * @return OpenAI An instance of the class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 * 
	 * @param string $api_key API Key.
	 */
	public function __construct( $api_key = '' ) {
		$settings         = Main::get_settings();
		$this->api_key    = ! empty( $api_key ) ? $api_key : ( isset( $settings['api_key'] ) ? $settings['api_key'] : '' );
		$this->chat_model = isset( $settings['chat_model'] ) ? $settings['chat_model'] : $this->chat_model;
	}

	/**
	 * Create Embeddings.
	 * 
	 * @param string|array<string> $content Content.
	 * @param string               $model   Model.
	 * 
	 * @return mixed
	 */
	public function create_embeddings( $content, $model = 'text-embedding-3-small' ) {
		$response = $this->request(
			'embeddings',
			[
				'input' => $content,
				'model' => $model,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->data ) ) {
			return $response->data;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the embeddings.', 'hyve-lite' ) );
	}

	/**
	 * Create a Conversation.
	 * 
	 * @param array<string, mixed> $params Parameters.
	 * 
	 * @return string|\WP_Error
	 */
	public function create_conversation( $params = [] ) {
		$response = $this->request(
			'conversations',
			$params
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->id ) ) {
			return $response->id;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the conversation.', 'hyve-lite' ) );
	}

	/**
	 * Create a Response.
	 * 
	 * @param array<array<string, mixed>> $items        Items.
	 * @param string                      $conversation Conversation.
	 * 
	 * @return object|\WP_Error
	 */
	public function create_response( $items, $conversation ) {
		$settings = Main::get_settings();

		$params = [
			'background'   => true,
			'conversation' => $conversation,
			'model'        => $this->chat_model,
			'temperature'  => $settings['temperature'],
			'top_p'        => $settings['top_p'],
			'input'        => $items,
			'instructions' => "You are a Support Assistant tasked with providing precise, to-the-point answers based on the context provided for each query, as well as maintaining awareness of previous context for follow-up questions.\r\n\r\nCore Principles:\r\n\r\n1. Context and Question Analysis\r\n- Identify the context given in each message.\r\n- Determine the specific question to be answered based on the current context and previous interactions.\r\n\r\n2. Relevance Check\r\n- Assess if the current context or previous context contains information directly relevant to the question.\r\n- Proceed based on the following scenarios:\r\na) If current context addresses the question: Formulate a response using current context.\r\nb) If current context is empty but previous context is relevant: Use previous context to answer.\r\nc) If the input is a greeting: Respond appropriately.\r\nd) If neither current nor previous context addresses the question: Respond with an empty response and success: false.\r\n\r\n3. Response Formulation\r\n- Use information from the current context primarily. If current context is insufficient, refer to previous context for follow-up questions.\r\n- Include all relevant details, including any code snippets or links if present.\r\n- Avoid including unnecessary information.\r\n- Format the response in HTML using only these allowed tags: h2, h3, p, img, a, pre, strong, em.\r\n\r\n4. Context Reference\r\n- Do not explicitly mention or refer to the context in your answer.\r\n- Provide a straightforward response that directly answers the question.\r\n\r\n5. Response Structure\r\n- Always structure your response as a JSON object with 'response' and 'success' fields.\r\n- The 'response' field should contain the HTML-formatted answer.\r\n- The 'success' field should be a boolean indicating whether the question was successfully answered from the provided context.\r\n\r\n6. Handling Follow-up Questions\r\n- Maintain awareness of previous context to answer follow-up questions.\r\n- If current context is empty but the question seems to be a follow-up, attempt to answer using previous context.\r\n\r\nExamples:\r\n\r\n1. Initial Question with Full Answer\r\nContext: The price of XYZ product is $99.99 USD.\r\nQuestion: How much does XYZ cost?\r\nResponse:\r\n{\r\n\"response\": \"<p>The price of XYZ product is $99.99 USD.</p>\",\r\n\"success\": true\r\n}\r\n\r\n2. Follow-up Question with Empty Current Context\r\nContext: [Empty]\r\nQuestion: What currency is that in?\r\nResponse:\r\n{\r\n\"response\": \"<p>The price is in USD (United States Dollars).</p>\",\r\n\"success\": true\r\n}\r\n\r\n3. No Relevant Information in Current or Previous Context\r\nContext: [Empty]\r\nQuestion: Do you offer gift wrapping?\r\nResponse:\r\n{\r\n\"response\": \"\",\r\n\"success\": false\r\n}\r\n\r\n4. Greeting\r\nQuestion: Hello!\r\nResponse:\r\n{\r\n\"response\": \"<p>Hello! How can I assist you today?</p>\",\r\n\"success\": true\r\n}\r\n\r\nError Handling:\r\nFor invalid inputs or unrecognized question formats, respond with:\r\n{\r\n\"response\": \"<p>I apologize, but I couldn't understand your question. Could you please rephrase it?</p>\",\r\n\"success\": false\r\n}\r\n\r\nHTML Usage Guidelines:\r\n- Use <h2> for main headings and <h3> for subheadings.\r\n- Wrap paragraphs in <p> tags.\r\n- Use <pre> for code snippets or formatted text.\r\n- Apply <strong> for bold and <em> for italic emphasis sparingly.\r\n- Include <img> only if specific image information is provided in the context.\r\n- Use <a> for links, ensuring they are relevant and from the provided context.\r\n\r\nRemember:\r\n- Prioritize using the current context for answers.\r\n- For follow-up questions with empty current context, refer to previous context if relevant.\r\n- If information isn't available in current or previous context, indicate this with an empty response and success: false.\r\n- Always strive to provide the most accurate and relevant information based on available context.",
			'text'         => [
				'format' => [
					'type'   => 'json_schema',
					'name'   => 'chatbot_response',
					'strict' => false,
					'schema' => [
						'type'                 => 'object',
						'properties'           => [
							'response' => [
								'type'        => 'string',
								'description' => 'The HTML-formatted response to the user\'s question.',
							],
							'success'  => [
								'type'        => 'boolean',
								'description' => 'Indicates whether the question was successfully answered from the provided context.',
							],
						],
						'required'             => [ 'success' ],
						'additionalProperties' => false,
					],
				],
			],
		];

		$response = $this->request(
			'responses',
			apply_filters( 'hyve_create_response_params', $params )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response->id ) || ( isset( $response->status ) && 'queued' !== $response->status ) ) {
			return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the run.', 'hyve-lite' ) );
		}

		return $response->id;
	}

	/**
	 * Get Thread Messages.
	 * 
	 * @param string $response_id Response ID.
	 * 
	 * @return mixed
	 */
	public function get_response( $response_id ) {
		$response = $this->request( 'responses/' . $response_id, [], 'GET' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response->id ) || ( isset( $response->error ) && ( is_object( $response->error ) || $response->error ) ) ) {
			return new \WP_Error( 'unknown_error', __( 'An error occurred while getting the messages.', 'hyve-lite' ) );
		}

		return $response;
	}

	/**
	 * Create Moderation Request.
	 * 
	 * @param string $message Message.
	 * 
	 * @return true|object{flagged: bool, categories: array<string, bool>, category_scores: array<string, float>, category_applied_input_types: array<string, string[]>}|\WP_Error Moderation result or error.
	 */
	public function moderate( $message ) {
		$response = $this->request(
			'moderations',
			[
				'input' => $message,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->results ) ) {
			$result = reset( $response->results );

			if ( isset( $result->flagged ) && $result->flagged ) {
				/**
				 * Moderation result or error.
				 * 
				 * @var object{flagged: bool, categories: array<string, bool>, category_scores: array<string, float>, category_applied_input_types: array<string, string[]>} $result */
				return $result;
			}
		}

		return true;
	}

	/**
	 * Moderate data.
	 * 
	 * @param array<string>|string $chunks Data to moderate.
	 * @param int                  $id     Post ID.
	 * 
	 * @return true|array<string, float>|\WP_Error
	 */
	public function moderate_chunks( $chunks, $id = null ) {
		if ( $id ) {
			$moderated = get_transient( 'hyve_moderate_post_' . $id );

			if ( false !== $moderated ) {
				return is_array( $moderated ) ? $moderated : true;
			}
		}

		$openai               = self::instance();
		$results              = [];
		$return               = true;
		$settings             = Main::get_settings();
		$moderation_threshold = $settings['moderation_threshold'];

		if ( ! is_array( $chunks ) ) {
			$chunks = [ $chunks ];
		}

		foreach ( $chunks as $chunk ) {
			$moderation = $openai->moderate( $chunk );

			if ( is_wp_error( $moderation ) ) {
				return $moderation;
			}

			if ( is_object( $moderation ) ) {
				$results[] = $moderation;
			}
		}

		if ( ! empty( $results ) ) {
			$flagged = [];
	
			foreach ( $results as $result ) {
				$categories = $result->categories;
	
				foreach ( $categories as $category => $flag ) {
					if ( ! $flag ) {
						continue;
					}

					if ( ! isset( $moderation_threshold[ $category ] ) || $result->category_scores->$category < ( $moderation_threshold[ $category ] / 100 ) ) {
						continue;
					}

					if ( ! isset( $flagged[ $category ] ) ) {
						$flagged[ $category ] = $result->category_scores->$category;
						continue;
					}
	
					if ( $result->category_scores->$category > $flagged[ $category ] ) {
						$flagged[ $category ] = $result->category_scores->$category;
					}
				}
			}

			if ( ! empty( $flagged ) ) {
				$return = $flagged;
			}
		}

		if ( $id ) {
			set_transient( 'hyve_moderate_post_' . $id, $return, MINUTE_IN_SECONDS );
		}

		return $return;
	}

	/**
	 * Create Request.
	 * 
	 * @param string               $endpoint Endpoint.
	 * @param array<string, mixed> $params   Parameters.
	 * @param string               $method   Method.
	 * 
	 * @return mixed
	 */
	private function request( $endpoint, $params = [], $method = 'POST' ) {
		if ( ! $this->api_key ) {
			return (object) [
				'error'   => true,
				'message' => 'API key is missing.',
			];
		}

		$body = wp_json_encode( $params );

		if ( false === $body ) {
			return (object) [
				'error'   => true,
				'message' => 'Invalid params.',
			];
		}

		$response = '';

		if ( 'POST' === $method ) {
			$response = wp_remote_post(
				self::$base_url . $endpoint,
				[
					'headers'     => [
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->api_key,
					],
					'body'        => $body,
					'method'      => 'POST',
					'data_format' => 'body',
				]
			);
		}

		if ( 'GET' === $method ) {
			$url  = self::$base_url . $endpoint;
			$args = [
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				],
			];

			if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
				$response = vip_safe_wp_remote_get( $url, '', 3, 1, 20, $args );
			} else {
				$response = wp_remote_get( $url, $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		} else {
			$body = wp_remote_retrieve_body( $response );
			$body = json_decode( $body );

			if ( isset( $body->error ) ) {
				if ( 'POST' === $method ) {
					$this->check_and_save_error( (array) $body->error );
				}

				if ( isset( $body->error->message ) ) {
					return new \WP_Error( isset( $body->error->code ) ? $body->error->code : 'unknown_error', $body->error->message );
				}
				return new \WP_Error( 'unknown_error', __( 'An error occurred while processing the request.', 'hyve-lite' ) );
			}
			
			if ( 'POST' === $method ) {
				delete_option( self::ERROR_OPTION_KEY );
			}
			return $body;
		}
	}

	/**
	 * Check the type of error returner by OpenAI and save if it is of interest.
	 * 
	 * Delete the old error if no error is longer present.
	 * 
	 * @param array<string, string> $error The error.
	 * @return void
	 */
	private function check_and_save_error( $error ) {
		if ( empty( $error['code'] ) ) {
			
			return;
		}

		$code = $error['code'];
		
		$errors_codes = [
			// API Key Errors.
			'invalid_api_key',
			'insufficient_quota',
			'invalid_authentication',
			'account_deactivated',
			'billing_not_active',
			'organization_not_found',
			'organization_deactivated',
			'permission_denied',

			// Rate Limiting Errors.
			'rate_limit_exceeded',
			'quota_exceeded ',
		];
		
		if ( in_array( $code, $errors_codes, true ) ) {
			update_option(
				self::ERROR_OPTION_KEY,
				[
					'code'     => $code,
					'message'  => ! empty( $error['message'] ) ? $error['message'] : '',
					'date'     => wp_date( 'c' ),
					'provider' => 'OpenAI',
				] 
			);
		}
	}
}
