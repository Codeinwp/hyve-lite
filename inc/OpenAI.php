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
	private $prompt_version = '1.1.0';

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
	 * Constructor.
	 * 
	 * @param string $api_key API Key.
	 */
	public function __construct( $api_key = '' ) {
		$settings           = Main::get_settings();
		$this->api_key      = ! empty( $api_key ) ? $api_key : ( isset( $settings['api_key'] ) ? $settings['api_key'] : '' );
		$this->assistant_id = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : '';

		if ( $this->assistant_id && version_compare( $this->prompt_version, get_option( 'hyve_prompt_version', '1.0.0' ), '>' ) ) {
			$this->update_assistant();
		}
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
			array(
				'instructions' => "Assistant Role & Concise Response Guidelines: As a Support Assistant, provide precise, to-the-point answers based exclusively on the previously provided context.\r\n\r\nSET OF PRINCIPLES TO FOLLOW:\r\n\r\n1. **Identify the Context and Question**:\r\n1.1. **START CONTEXT**: Identify the context provided in the message. **: END CONTEXT**\r\n1.2. **START QUESTION**: Identify the question that needs to be answered based on the context.. **: END QUESTION**\r\n\r\n2. **Check the Context for Relevance**:\r\n2.1. Determine if the context contains information directly relevant to the question.\r\n2.2. If the context addresses the user's question, proceed to the next step.\r\n2.3. If the question is a greeting, respond appropriately with the greeting.\r\n2.4. If the context does not address the user's question, respond with: `{\"response\": \"\", \"success\": false}`.\r\n\r\n3. **Formulate the Response**:\r\n3.1. If the context is sufficient, formulate a clear and concise response using only the information provided in the context.\r\n3.2. Ensure the response includes all important details covered in the context, but avoid any extraneous information.\r\n\r\n4. **Avoid Referring to the Context**:\r\n4.1. Do not refer to the context or state that the response is based on the context in your answer.\r\n4.2. Ensure the response is straightforward and directly answers the question.\r\n\r\n5. **Generate the JSON Response**:\r\n5.1. Structure the response according to the following JSON schema:\r\n\r\n\r\n{\r\n  \"\$schema\": \"http:\/\/json-schema.org\/draft-07\/schema#\",\r\n  \"type\": \"object\",\r\n  \"properties\": {\r\n    \"response\": {\r\n      \"type\": \"string\",\r\n      \"description\": \"Contains the response to the question. Do not include it if the answer wasn't available in the context.\"\r\n    },\r\n    \"success\": {\r\n      \"type\": \"boolean\",\r\n      \"description\": \"Indicates whether the question was successfully answered from provided context.\"\r\n    }\r\n  },\r\n  \"required\": [\"success\"]\r\n}\r\n\r\nExample Usage:\r\n\r\nContext: [Provide context here]\r\nQuestion: [Provide question here]\r\n\r\nExpected Behavior:\r\n\r\n- If the question is fully covered by the context, provide a response using the provided JSON schema.\r\n- If the question is not fully covered by the context, respond with: {\"response\": \"\", \"success\": false}.\r\n\r\nExample Responses:\r\n\r\n- Context covers the question: {\"response\": \"Here is the information you requested.\", \"success\": true}\r\n- Context does not cover the question: {\"response\": \"\", \"success\": false}\r\n- Context does not cover the question but is a greeting: {\"response\": \"Hello, what can I help you with?.\", \"success\": true}",
				'name'         => 'Chatbot by Hyve',
				'model'        => 'gpt-3.5-turbo-0125',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response->id ) ) {
			$this->assistant_id = $response->id;
			return $response->id;
		}

		return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the assistant.', 'hyve' ) );
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
				array(
					'instructions' => "Assistant Role & Concise Response Guidelines: As a Support Assistant, provide precise, to-the-point answers based exclusively on the previously provided context.\r\n\r\nSET OF PRINCIPLES TO FOLLOW:\r\n\r\n1. **Identify the Context and Question**:\r\n1.1. **START CONTEXT**: Identify the context provided in the message. **: END CONTEXT**\r\n1.2. **START QUESTION**: Identify the question that needs to be answered based on the context.. **: END QUESTION**\r\n\r\n2. **Check the Context for Relevance**:\r\n2.1. Determine if the context contains information directly relevant to the question.\r\n2.2. If the context addresses the user's question, proceed to the next step.\r\n2.3. If the question is a greeting, respond appropriately with the greeting.\r\n2.4. If the context does not address the user's question, respond with: `{\"response\": \"\", \"success\": false}`.\r\n\r\n3. **Formulate the Response**:\r\n3.1. If the context is sufficient, formulate a clear and concise response using only the information provided in the context.\r\n3.2. Ensure the response includes all important details covered in the context, but avoid any extraneous information.\r\n\r\n4. **Avoid Referring to the Context**:\r\n4.1. Do not refer to the context or state that the response is based on the context in your answer.\r\n4.2. Ensure the response is straightforward and directly answers the question.\r\n\r\n5. **Generate the JSON Response**:\r\n5.1. Structure the response according to the following JSON schema:\r\n\r\n\r\n{\r\n  \"\$schema\": \"http:\/\/json-schema.org\/draft-07\/schema#\",\r\n  \"type\": \"object\",\r\n  \"properties\": {\r\n    \"response\": {\r\n      \"type\": \"string\",\r\n      \"description\": \"Contains the response to the question. Do not include it if the answer wasn't available in the context.\"\r\n    },\r\n    \"success\": {\r\n      \"type\": \"boolean\",\r\n      \"description\": \"Indicates whether the question was successfully answered from provided context.\"\r\n    }\r\n  },\r\n  \"required\": [\"success\"]\r\n}\r\n\r\nExample Usage:\r\n\r\nContext: [Provide context here]\r\nQuestion: [Provide question here]\r\n\r\nExpected Behavior:\r\n\r\n- If the question is fully covered by the context, provide a response using the provided JSON schema.\r\n- If the question is not fully covered by the context, respond with: {\"response\": \"\", \"success\": false}.\r\n\r\nExample Responses:\r\n\r\n- Context covers the question: {\"response\": \"Here is the information you requested.\", \"success\": true}\r\n- Context does not cover the question: {\"response\": \"\", \"success\": false}\r\n- Context does not cover the question but is a greeting: {\"response\": \"Hello, what can I help you with?.\", \"success\": true}",
				)
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

		return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the embeddings.', 'hyve' ) );
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

		return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the thread.', 'hyve' ) );
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

		return new \WP_Error( 'unknown_error', __( 'An error occurred while sending the message.', 'hyve' ) );
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
			return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the run.', 'hyve' ) );
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

		return new \WP_Error( 'unknown_error', __( 'An error occurred while getting the run status.', 'hyve' ) );
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

		return new \WP_Error( 'unknown_error', __( 'An error occurred while getting the messages.', 'hyve' ) );
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

				return new \WP_Error( 'unknown_error', __( 'An error occurred while processing the request.', 'hyve' ) );
			}

			return $body;
		}
	}
}
