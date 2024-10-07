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
	 * Assistant ID.
	 * 
	 * @var string
	 */
	private $assistant_id;

	/**
	 * The single instance of the class.
	 *
	 * @var OpenAI
	 */
	private static $instance = null;

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
		$settings           = Main::get_settings();
		$this->api_key      = ! empty( $api_key ) ? $api_key : ( isset( $settings['api_key'] ) ? $settings['api_key'] : '' );
		$this->assistant_id = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : '';
		$this->chat_model   = isset( $settings['chat_model'] ) ? $settings['chat_model'] : $this->chat_model;

		if ( $this->assistant_id && version_compare( $this->prompt_version, get_option( 'hyve_prompt_version', '1.0.0' ), '>' ) ) {
			$this->update_assistant();
		}
	}

	/**
	 * Get Assistant Properties.
	 * 
	 * @return array
	 */
	public function get_properties() {
		$props = array(
			'instructions' => "You are a Support Assistant tasked with providing precise, to-the-point answers based on the context provided for each query, as well as maintaining awareness of previous context for follow-up questions.\r\n\r\nCore Principles:\r\n\r\n1. Context and Question Analysis\r\n- Identify the context given in each message.\r\n- Determine the specific question to be answered based on the current context and previous interactions.\r\n\r\n2. Relevance Check\r\n- Assess if the current context or previous context contains information directly relevant to the question.\r\n- Proceed based on the following scenarios:\r\na) If current context addresses the question: Formulate a response using current context.\r\nb) If current context is empty but previous context is relevant: Use previous context to answer.\r\nc) If the input is a greeting: Respond appropriately.\r\nd) If neither current nor previous context addresses the question: Respond with an empty response and success: false.\r\n\r\n3. Response Formulation\r\n- Use information from the current context primarily. If current context is insufficient, refer to previous context for follow-up questions.\r\n- Include all relevant details, including any code snippets or links if present.\r\n- Avoid including unnecessary information.\r\n- Format the response in HTML using only these allowed tags: h2, h3, p, img, a, pre, strong, em.\r\n\r\n4. Context Reference\r\n- Do not explicitly mention or refer to the context in your answer.\r\n- Provide a straightforward response that directly answers the question.\r\n\r\n5. Response Structure\r\n- Always structure your response as a JSON object with 'response' and 'success' fields.\r\n- The 'response' field should contain the HTML-formatted answer.\r\n- The 'success' field should be a boolean indicating whether the question was successfully answered.\r\n\r\n6. Handling Follow-up Questions\r\n- Maintain awareness of previous context to answer follow-up questions.\r\n- If current context is empty but the question seems to be a follow-up, attempt to answer using previous context.\r\n\r\nExamples:\r\n\r\n1. Initial Question with Full Answer\r\nContext: The price of XYZ product is $99.99 USD.\r\nQuestion: How much does XYZ cost?\r\nResponse:\r\n{\r\n\"response\": \"<p>The price of XYZ product is $99.99 USD.</p>\",\r\n\"success\": true\r\n}\r\n\r\n2. Follow-up Question with Empty Current Context\r\nContext: [Empty]\r\nQuestion: What currency is that in?\r\nResponse:\r\n{\r\n\"response\": \"<p>The price is in USD (United States Dollars).</p>\",\r\n\"success\": true\r\n}\r\n\r\n3. No Relevant Information in Current or Previous Context\r\nContext: [Empty]\r\nQuestion: Do you offer gift wrapping?\r\nResponse:\r\n{\r\n\"response\": \"\",\r\n\"success\": false\r\n}\r\n\r\n4. Greeting\r\nQuestion: Hello!\r\nResponse:\r\n{\r\n\"response\": \"<p>Hello! How can I assist you today?</p>\",\r\n\"success\": true\r\n}\r\n\r\nError Handling:\r\nFor invalid inputs or unrecognized question formats, respond with:\r\n{\r\n\"response\": \"<p>I apologize, but I couldn't understand your question. Could you please rephrase it?</p>\",\r\n\"success\": false\r\n}\r\n\r\nHTML Usage Guidelines:\r\n- Use <h2> for main headings and <h3> for subheadings.\r\n- Wrap paragraphs in <p> tags.\r\n- Use <pre> for code snippets or formatted text.\r\n- Apply <strong> for bold and <em> for italic emphasis sparingly.\r\n- Include <img> only if specific image information is provided in the context.\r\n- Use <a> for links, ensuring they are relevant and from the provided context.\r\n\r\nRemember:\r\n- Prioritize using the current context for answers.\r\n- For follow-up questions with empty current context, refer to previous context if relevant.\r\n- If information isn't available in current or previous context, indicate this with an empty response and success: false.\r\n- Always strive to provide the most accurate and relevant information based on available context.",
			'model'        => $this->chat_model,
		);

		if ( 'gpt-4o-mini' === $this->chat_model ) {
			$props['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'chatbot_response',
					'strict' => false,
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'response' => array(
								'type'        => 'string',
								'description' => 'The HTML-formatted response to the user\'s question.',
							),
							'success'  => array(
								'type'        => 'boolean',
								'description' => 'Indicates whether the question was successfully answered from the provided context.',
							),
						),
						'required'             => array( 'success' ),
						'additionalProperties' => false,
					),
				),
			);
		}

		return $props;
	}

	/**
	 * Setup Assistant.
	 * 
	 * @return string|\WP_Error
	 */
	public function setup_assistant() {
		$assistant = $this->retrieve_assistant();

		if ( is_wp_error( $assistant ) ) {
			return $assistant;
		}

		if ( ! $assistant ) {
			return $this->create_assistant();
		}

		return $assistant;
	}

	/**
	 * Create Assistant.
	 * 
	 * @return string|\WP_Error
	 */
	public function create_assistant() {
		$response = $this->request(
			'assistants',
			array_merge(
				$this->get_properties(),
				array(
					'name' => 'Chatbot by Hyve',
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->id ) ) {
			$this->assistant_id = $response->id;
			return $response->id;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the assistant.', 'hyve-lite' ) );
	}

	/**
	 * Update Assistant.
	 * 
	 * @return bool|\WP_Error
	 */
	public function update_assistant() {
		$assistant    = $this->retrieve_assistant();
		$settings     = Main::get_settings();
		$assistant_id = '';

		if ( is_wp_error( $assistant ) ) {
			return $assistant;
		}

		if ( ! $assistant ) {
			$assistant_id = $this->create_assistant();

			if ( is_wp_error( $assistant_id ) ) {
				return $assistant_id;
			}
		} else {
			$response = $this->request(
				'assistants/' . $this->assistant_id,
				$this->get_properties()
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! isset( $response->id ) ) {
				return false;
			}

			$this->assistant_id = $response->id;
			$assistant_id       = $response->id;
		}

		$settings['assistant_id'] = $assistant_id;
		update_option( 'hyve_settings', $settings );
		update_option( 'hyve_prompt_version', $this->prompt_version );

		return true;
	}

	/**
	 * Retrieve Assistant.
	 * 
	 * @return string|\WP_Error|false
	 */
	public function retrieve_assistant() {
		if ( ! $this->assistant_id ) {
			return false;
		}

		$response = $this->request( 'assistants/' . $this->assistant_id );

		if ( is_wp_error( $response ) ) {
			if ( strpos( $response->get_error_message(), 'No assistant found' ) !== false ) {
				return false;
			}

			return $response;
		}

		if ( isset( $response->id ) ) {
			return $response->id;
		}

		return false;
	}

	/**
	 * Create Embeddings.
	 * 
	 * @param string|array $content Content.
	 * @param string       $model   Model.
	 * 
	 * @return mixed
	 */
	public function create_embeddings( $content, $model = 'text-embedding-3-small' ) {
		$response = $this->request(
			'embeddings',
			array(
				'input' => $content,
				'model' => $model,
			)
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
	 * Create a Thread.
	 * 
	 * @param array $params Parameters.
	 * 
	 * @return string|\WP_Error
	 */
	public function create_thread( $params = array() ) {
		$response = $this->request(
			'threads',
			$params
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->id ) ) {
			return $response->id;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the thread.', 'hyve-lite' ) );
	}

	/**
	 * Send Message.
	 * 
	 * @param string $message Message.
	 * @param string $thread  Thread.
	 * @param string $role    Role.
	 * 
	 * @return true|\WP_Error
	 */
	public function send_message( $message, $thread, $role = 'assistant' ) {
		$response = $this->request(
			'threads/' . $thread . '/messages',
			array(
				'role'    => $role,
				'content' => $message,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->id ) ) {
			return true;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while sending the message.', 'hyve-lite' ) );
	}

	/**
	 * Create a run
	 * 
	 * @param array  $messages Messages.
	 * @param string $thread  Thread.
	 * 
	 * @return string|\WP_Error
	 */
	public function create_run( $messages, $thread ) {
		$settings = Main::get_settings();

		$response = $this->request(
			'threads/' . $thread . '/runs',
			array(
				'assistant_id'        => $this->assistant_id,
				'additional_messages' => $messages,
				'model'               => $this->chat_model,
				'temperature'         => $settings['temperature'],
				'top_p'               => $settings['top_p'],
				'response_format'     => array(
					'type' => 'json_object',
				),
			)
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
	 * Get Run Status.
	 * 
	 * @param string $run_id Run ID.
	 * @param string $thread Thread.
	 * 
	 * @return string|\WP_Error
	 */
	public function get_status( $run_id, $thread ) {
		$response = $this->request( 'threads/' . $thread . '/runs/' . $run_id, array(), 'GET' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->status ) ) {
			return $response->status;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while getting the run status.', 'hyve-lite' ) );
	}

	/**
	 * Get Thread Messages.
	 * 
	 * @param string $thread Thread.
	 * 
	 * @return mixed
	 */
	public function get_messages( $thread ) {
		$response = $this->request( 'threads/' . $thread . '/messages', array(), 'GET' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->data ) ) {
			return $response->data;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while getting the messages.', 'hyve-lite' ) );
	}

	/**
	 * Create Moderation Request.
	 * 
	 * @param string $message Message.
	 * 
	 * @return true|object|\WP_Error
	 */
	public function moderate( $message ) {
		$response = $this->request(
			'moderations',
			array(
				'input' => $message,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->results ) ) {
			$result = reset( $response->results );

			if ( isset( $result->flagged ) && $result->flagged ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Create Request.
	 * 
	 * @param string $endpoint Endpoint.
	 * @param array  $params   Parameters.
	 * @param string $method   Method.
	 * 
	 * @return mixed
	 */
	private function request( $endpoint, $params = array(), $method = 'POST' ) {
		if ( ! $this->api_key ) {
			return (object) array(
				'error'   => true,
				'message' => 'API key is missing.',
			);
		}

		$body = wp_json_encode( $params );

		$response = '';

		if ( 'POST' === $method ) {
			$response = wp_remote_post(
				self::$base_url . $endpoint,
				array(
					'headers'     => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->api_key,
						'OpenAI-Beta'   => 'assistants=v2',
					), 
					'body'        => $body,
					'method'      => 'POST',
					'data_format' => 'body',
				) 
			);
		}

		if ( 'GET' === $method ) {
			$url  = self::$base_url . $endpoint;
			$args = array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
					'OpenAI-Beta'   => 'assistants=v2',
				),
			);

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
				if ( isset( $body->error->message ) ) {
					return new \WP_Error( isset( $body->error->code ) ? $body->error->code : 'unknown_error', $body->error->message );
				}

				return new \WP_Error( 'unknown_error', __( 'An error occurred while processing the request.', 'hyve-lite' ) );
			}

			return $body;
		}
	}
}
