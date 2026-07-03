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
	 * Build the chat parameters shared by the background (poll) and streaming
	 * reply flows, so a single prompt and JSON schema drive both paths.
	 *
	 * @param array<array<string, mixed>> $items        Items.
	 * @param string                      $conversation Conversation.
	 *
	 * @return array<string, mixed>
	 */
	private function get_chat_response_params( $items, $conversation ) {
		$settings = Main::get_settings();

		return [
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
	}

	/**
	 * Create a background Response (poll flow).
	 *
	 * @param array<array<string, mixed>> $items        Items.
	 * @param string                      $conversation Conversation.
	 *
	 * @return string|\WP_Error
	 */
	public function create_response( $items, $conversation ) {
		$params               = $this->get_chat_response_params( $items, $conversation );
		$params['background'] = true;

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
	 * Build the chat input items (context + question) for a turn.
	 *
	 * Shared by the background and streaming reply flows so both send an
	 * identical prompt structure.
	 *
	 * @param string $context Knowledge base context.
	 * @param string $message User message.
	 *
	 * @return array<array<string, string>>
	 */
	public static function build_chat_items( $context, $message ) {
		return [
			[
				'type'    => 'message',
				'role'    => 'user',
				'content' => 'START CONTEXT: ' . $context . ' :END CONTEXT',
			],
			[
				'type'    => 'message',
				'role'    => 'user',
				'content' => 'START QUESTION: ' . $message . ' :END QUESTION',
			],
		];
	}

	/**
	 * Interpret a model reply (the structured JSON output) into a decision.
	 *
	 * Shared by the background (get_chat) and streaming (Stream) flows so a model
	 * reply is turned into success/answer text identically on both paths.
	 *
	 * @param string $text            The raw model output (JSON string).
	 * @param string $default_message Fallback shown when there is no answer.
	 *
	 * @return array{decoded:bool,payload:array<string,mixed>,answered:bool,final:string}
	 */
	public static function interpret_chat_payload( $text, $default_message ) {
		$payload = json_decode( $text, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			return [
				'decoded'  => false,
				'payload'  => [],
				'answered' => false,
				'final'    => esc_html( $default_message ),
			];
		}

		if ( isset( $payload['properties'] ) ) {
			$payload = $payload['properties'];
		}

		$answered = isset( $payload['success'] ) && true === $payload['success'] && isset( $payload['response'] );
		$final    = $answered ? $payload['response'] : esc_html( $default_message );

		return [
			'decoded'  => true,
			'payload'  => $payload,
			'answered' => $answered,
			'final'    => $final,
		];
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
	 * Stream a Response from OpenAI.
	 *
	 * Uses a raw cURL request because `wp_remote_*` cannot read the response
	 * body incrementally. Each text delta is passed to `$on_delta` as it
	 * arrives; the fully assembled text and response id are returned at the end.
	 *
	 * @param array<array<string, mixed>> $items        Input items.
	 * @param string                      $conversation Conversation id.
	 * @param callable                    $on_delta     Receives each text delta (string).
	 *
	 * @return array{id:string,text:string}|\WP_Error
	 */
	public function stream_response( $items, $conversation, $on_delta ) {
		if ( ! $this->api_key ) {
			return new \WP_Error( 'no_api_key', __( 'API key is missing.', 'hyve-lite' ) );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error( 'no_curl', __( 'cURL is not available.', 'hyve-lite' ) );
		}

		$params           = $this->get_chat_response_params( $items, $conversation );
		$params['stream'] = true;

		$body = wp_json_encode( apply_filters( 'hyve_create_response_params', $params ) );

		if ( false === $body ) {
			return new \WP_Error( 'invalid_params', __( 'Invalid params.', 'hyve-lite' ) );
		}

		$assembled   = '';
		$response_id = '';
		$sse_buffer  = '';
		$stream_err  = null;

		$write = function ( $ch, $chunk ) use ( &$sse_buffer, &$assembled, &$response_id, &$stream_err, $on_delta ) {
			$sse_buffer .= $chunk;

			while ( false !== ( $pos = strpos( $sse_buffer, "\n\n" ) ) ) {
				$raw        = substr( $sse_buffer, 0, $pos );
				$sse_buffer = substr( $sse_buffer, $pos + 2 );

				$data = [];

				foreach ( explode( "\n", $raw ) as $line ) {
					$line = rtrim( $line, "\r" );

					if ( 0 === strpos( $line, 'data:' ) ) {
						$data[] = ltrim( substr( $line, 5 ), ' ' );
					}
				}

				if ( empty( $data ) ) {
					continue;
				}

				$payload = implode( "\n", $data );

				if ( '[DONE]' === $payload ) {
					continue;
				}

				$event = json_decode( $payload );

				if ( ! is_object( $event ) || ! isset( $event->type ) ) {
					continue;
				}

				if ( isset( $event->response->id ) ) {
					$response_id = $event->response->id;
				}

				if ( 'response.output_text.delta' === $event->type && isset( $event->delta ) && is_string( $event->delta ) ) {
					$assembled .= $event->delta;
					call_user_func( $on_delta, $event->delta );
				}

				if ( 'response.failed' === $event->type || 'error' === $event->type ) {
					$stream_err = isset( $event->response->error->message ) ? $event->response->error->message : ( isset( $event->message ) ? $event->message : __( 'Streaming failed.', 'hyve-lite' ) );
				}
			}

			if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
				return 0;
			}

			return strlen( $chunk );
		};

		// Streaming requires reading the response body incrementally, which
		// wp_remote_* cannot do, so cURL is used directly here.
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close
		$handle = curl_init();

		curl_setopt_array(
			$handle,
			[
				CURLOPT_URL            => self::$base_url . 'responses',
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'Authorization: Bearer ' . $this->api_key,
					'Accept: text/event-stream',
				],
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT        => 120,
				CURLOPT_WRITEFUNCTION  => $write,
			]
		);

		$ok   = curl_exec( $handle );
		$err  = curl_error( $handle );
		$code = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		curl_close( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( null !== $stream_err ) {
			return new \WP_Error( 'stream_error', $stream_err );
		}

		if ( $code >= 400 ) {
			return new \WP_Error( 'stream_http_error', sprintf( 'HTTP %d', $code ) );
		}

		if ( false === $ok && '' === $assembled && ! ( function_exists( 'connection_aborted' ) && connection_aborted() ) ) {
			return new \WP_Error( 'stream_failed', $err ? $err : __( 'Streaming request failed.', 'hyve-lite' ) );
		}

		return [
			'id'   => $response_id,
			'text' => $assembled,
		];
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
