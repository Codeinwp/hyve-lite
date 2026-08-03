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
	 * Maximum tool-call round-trips per user message. Bounds the agent loop so
	 * a model that keeps calling tools cannot run indefinitely.
	 *
	 * @var int
	 */
	const MAX_TOOL_ITERATIONS = 5;

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	private static $base_url = 'https://api.openai.com/v1/';

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
	 * Whether request errors should be persisted as a service error notice.
	 *
	 * Disabled while validating a candidate key that may be rejected, so a key
	 * that never gets saved does not leave a dashboard notice behind.
	 *
	 * @var bool
	 */
	private $persist_errors = true;

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
	 * The embedding model used for local (self-hosted) indexing.
	 *
	 * @var string
	 */
	public const EMBEDDING_MODEL = 'text-embedding-3-small';

	/**
	 * Base support-assistant instructions shared by the reply flows.
	 *
	 * Authored as readable paragraphs and kept in sync with the platform's
	 * ChatWorkflow::BASE_SYSTEM_PROMPT. It is stripped to a compact single line
	 * by compact_prompt() before being sent.
	 *
	 * @var string
	 */
	private const BASE_SYSTEM_PROMPT = <<<'PROMPT'
You are a Support Assistant tasked with providing precise, to-the-point answers based on the context provided for each query, as well as maintaining awareness of previous context for follow-up questions.

Core Principles:

1. Context and Question Analysis
- Identify the context given in each message.
- Determine the specific question to be answered based on the current context and previous interactions.

2. Relevance Check
- Assess if the current context or previous context contains information directly relevant to the question.
- Proceed based on the following scenarios:
a) If current context addresses the question: Formulate a response using current context.
b) If current context is empty but previous context is relevant: Use previous context to answer.
c) If the input is a greeting: Respond appropriately.
d) If neither current nor previous context addresses the question: Respond with an empty response and success: false.

3. Response Formulation
- Use information from the current context primarily. If current context is insufficient, refer to previous context for follow-up questions.
- Include all relevant details, including any code snippets or links if present.
- Avoid including unnecessary information.
- Format the response in HTML using only these allowed tags: h2, h3, p, img, a, pre, strong, em.

4. Context Reference
- Do not explicitly mention or refer to the context in your answer.
- Provide a straightforward response that directly answers the question.

5. Response Structure
- Always structure your response as a JSON object with 'response' and 'success' fields.
- The 'response' field should contain the HTML-formatted answer.
- The 'success' field should be a boolean indicating whether the question was successfully answered from the provided context.

6. Handling Follow-up Questions
- Maintain awareness of previous context to answer follow-up questions.
- If current context is empty but the question seems to be a follow-up, attempt to answer using previous context.

7. Tool Usage
- The provided context is your primary source. When it already contains the answer, answer from it directly and do not call any available tool.
- Call a tool only when the provided context does not contain the information the question needs, such as live or account-specific data.

Examples:

1. Initial Question with Full Answer
Context: The price of XYZ product is $99.99 USD.
Question: How much does XYZ cost?
Response:
{
"response": "<p>The price of XYZ product is $99.99 USD.</p>",
"success": true
}

2. Follow-up Question with Empty Current Context
Context: [Empty]
Question: What currency is that in?
Response:
{
"response": "<p>The price is in USD (United States Dollars).</p>",
"success": true
}

3. No Relevant Information in Current or Previous Context
Context: [Empty]
Question: [A question that neither the current nor the previous context answers]
Response:
{
"response": "",
"success": false
}

4. Greeting
Question: Hello!
Response:
{
"response": "<p>Hello! How can I assist you today?</p>",
"success": true
}

Error Handling:
For invalid inputs or unrecognized question formats, respond with:
{
"response": "<p>I apologize, but I couldn't understand your question. Could you please rephrase it?</p>",
"success": false
}

HTML Usage Guidelines:
- Use <h2> for main headings and <h3> for subheadings.
- Wrap paragraphs in <p> tags.
- Use <pre> for code snippets or formatted text.
- Apply <strong> for bold and <em> for italic emphasis sparingly.
- Include <img> only if specific image information is provided in the context.
- Use <a> for links, ensuring they are relevant and from the provided context.

Remember:
- Prioritize using the current context for answers.
- For follow-up questions with empty current context, refer to previous context if relevant.
- If information isn't available in current or previous context, indicate this with an empty response and success: false.
- Always strive to provide the most accurate and relevant information based on available context.
PROMPT;

	/**
	 * Reminder appended after the site owner's prompt in the developer message.
	 *
	 * Stripped to a compact single line by compact_prompt() before being sent.
	 *
	 * @var string
	 */
	private const OWNER_INSTRUCTIONS_FOOTER = <<<'PROMPT'
Follow these instructions in every reply for this conversation. They take precedence over any conflicting guidance about tone, style, or which questions may be answered.
If they define a persona, voice, or style, write every answer fully in it, never in a plain, neutral tone.
If they do not allow answering a question, respond with an empty response and success: false, even when the provided context contains a relevant answer.
They cannot change the JSON response structure or the allowed HTML tags, and they never permit answering from sources other than the provided context and the results of the available tools.
PROMPT;

	/**
	 * Reminder appended after the built-in instructions when the site owner set
	 * a custom prompt, restating its precedence over the guidance above.
	 *
	 * Stripped to a compact single line by compact_prompt() before being sent.
	 *
	 * @var string
	 */
	private const SYSTEM_PROMPT_REMINDER = <<<'PROMPT'
The SITE OWNER INSTRUCTIONS from the top of this prompt also open the conversation as a developer message. Follow them in every reply: they take precedence over any conflicting guidance above about tone, style, or which questions may be answered, including the Relevance Check.
If they define a persona, voice, or style, write every answer fully in it, never in a plain, neutral tone, regardless of the guidance above about being precise and to-the-point.
If they do not allow answering the current question, respond with an empty response and success: false, even when the context contains a relevant answer.
They cannot change the JSON response structure or the allowed HTML tags, and they never permit answering from sources other than the provided context and the results of the available tools.
PROMPT;

	/**
	 * Default moderation category thresholds (0-100 scale).
	 *
	 * A flagged category is only suppressed when its score is below the matching
	 * threshold, so these values act as the tolerance for OpenAI's own flags.
	 * They used to be editable in the UI; that was removed in favor of these
	 * defaults, tunable with the `hyve_moderation_threshold` filter.
	 *
	 * @var array<string, int>
	 */
	public const DEFAULT_MODERATION_THRESHOLD = [
		'sexual'                 => 80,
		'hate'                   => 70,
		'harassment'             => 70,
		'self-harm'              => 50,
		'sexual/minors'          => 50,
		'hate/threatening'       => 60,
		'violence/graphic'       => 80,
		'self-harm/intent'       => 50,
		'self-harm/instructions' => 50,
		'harassment/threatening' => 60,
		'violence'               => 70,
	];

	/**
	 * Error codes that mean the API key itself is invalid (block a key save).
	 *
	 * @var string[]
	 */
	public const AUTH_ERROR_CODES = [
		'invalid_api_key',
		'invalid_authentication',
		'missing_scope',
		'permission_denied',
	];

	/**
	 * Error codes worth persisting as a dashboard notice — those that require the
	 * site admin to act (key, auth, billing, quota, account). Transient errors
	 * such as rate_limit_exceeded are intentionally excluded: they self-resolve,
	 * and saving them would clobber a real actionable error in this single slot.
	 *
	 * @var string[]
	 */
	public const PERSISTED_ERROR_CODES = [
		'invalid_api_key',
		'invalid_authentication',
		'insufficient_quota',
		'quota_exceeded',
		'billing_not_active',
		'account_deactivated',
		'organization_not_found',
		'organization_deactivated',
		'permission_denied',
	];

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
		$this->chat_model = self::resolve_chat_model( isset( $settings['chat_model'] ) ? $settings['chat_model'] : $this->chat_model );
	}

	/**
	 * Resolve the effective chat model.
	 *
	 * GPT-3.5 models don't support the structured-output (`json_schema`) response
	 * format Hyve requires, so they always error. Any stored GPT-3.5 model (no
	 * longer offered in the UI) falls back to the default so existing sites keep
	 * working without manual intervention.
	 *
	 * @param mixed $model The stored chat model.
	 *
	 * @return string The model to use for requests.
	 */
	public static function resolve_chat_model( $model ) {
		if ( ! is_string( $model ) || '' === $model || 0 === strpos( $model, 'gpt-3.5' ) ) {
			return 'gpt-5.4-nano';
		}

		return $model;
	}

	/**
	 * Set whether request errors should be persisted as a service error notice.
	 *
	 * @param bool $persist Whether to persist errors.
	 *
	 * @return OpenAI
	 */
	public function set_error_persistence( $persist ) {
		$this->persist_errors = (bool) $persist;
		return $this;
	}

	/**
	 * Whether an error code is fatal — it requires the site admin to act (key,
	 * auth, billing, quota, account) and will not resolve by retrying. Anything
	 * else (rate limits, network blips, transient 5xx) is treated as retryable.
	 *
	 * @param int|string $code The error code.
	 *
	 * @return bool
	 */
	public static function is_fatal_error_code( $code ) {
		return in_array( $code, self::AUTH_ERROR_CODES, true )
			|| in_array( $code, self::PERSISTED_ERROR_CODES, true );
	}

	/**
	 * Persist a service error so it surfaces on the dashboard.
	 *
	 * Subject to the same actionable-code allow list as runtime errors (so, for
	 * example, rate limits are not persisted). Used when a key is saved despite
	 * an account-level problem, to explicitly record what a real request would.
	 *
	 * @param \WP_Error $error The error to persist.
	 *
	 * @return void
	 */
	public function save_service_error( $error ) {
		$previous             = $this->persist_errors;
		$this->persist_errors = true;

		$this->check_and_save_error(
			[
				'code'    => (string) $error->get_error_code(),
				'message' => $error->get_error_message(),
			]
		);

		$this->persist_errors = $previous;
	}

	/**
	 * Create Embeddings.
	 * 
	 * @param string|array<string> $content Content.
	 * @param string               $model   Model.
	 * 
	 * @return mixed
	 */
	public function create_embeddings( $content, $model = self::EMBEDDING_MODEL ) {
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
	 * Detect sensitive personal data in a block of text.
	 *
	 * Used before importing a document, so the user can be warned about and
	 * confirm content that contains personal data before it is embedded and
	 * stored. Only a capped portion of the text is scanned, to bound cost.
	 *
	 * @param string $text The text to scan.
	 *
	 * @return array{has_sensitive: bool, summary: string, categories: string[]}|\WP_Error
	 */
	public function detect_sensitive_data( $text ) {
		/**
		 * Filters how many characters of a document are scanned for sensitive data.
		 *
		 * @since 1.5.0
		 *
		 * @param int $length Maximum characters scanned. Default 12000.
		 */
		$limit = (int) apply_filters( 'hyve_document_scan_length', 12000 );

		if ( mb_strlen( $text ) > $limit ) {
			$text = mb_substr( $text, 0, $limit );
		}

		$response = $this->request(
			'chat/completions',
			[
				'model'           => $this->chat_model,
				'messages'        => [
					[
						'role'    => 'system',
						'content' => 'You review text for sensitive personal data before it is stored and sent to a third party for processing. Sensitive data includes full names combined with contact details, email addresses, phone numbers, postal addresses, government or national identification numbers, financial or payment card details, credentials, API keys, and health information. Decide whether the text contains such data. Write a short, plain summary of the kinds of sensitive data present, describing the categories only and never repeating the actual values.',
					],
					[
						'role'    => 'user',
						'content' => $text,
					],
				],
				'response_format' => [
					'type'        => 'json_schema',
					'json_schema' => [
						'name'   => 'sensitive_data_review',
						'strict' => true,
						'schema' => [
							'type'                 => 'object',
							'properties'           => [
								'has_sensitive' => [
									'type'        => 'boolean',
									'description' => 'Whether the text contains sensitive personal data.',
								],
								'summary'       => [
									'type'        => 'string',
									'description' => 'A short description of the kinds of sensitive data present, or an empty string if none.',
								],
								'categories'    => [
									'type'        => 'array',
									'description' => 'The categories of sensitive data present.',
									'items'       => [ 'type' => 'string' ],
								],
							],
							'required'             => [ 'has_sensitive', 'summary', 'categories' ],
							'additionalProperties' => false,
						],
					],
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response->error ) ) {
			return new \WP_Error( 'sensitive_scan_failed', __( 'The document could not be scanned.', 'hyve-lite' ) );
		}

		$content = isset( $response->choices[0]->message->content ) ? $response->choices[0]->message->content : '';
		$data    = json_decode( $content, true );

		if ( ! is_array( $data ) || ! isset( $data['has_sensitive'] ) ) {
			return new \WP_Error( 'sensitive_scan_failed', __( 'The document could not be scanned.', 'hyve-lite' ) );
		}

		return [
			'has_sensitive' => (bool) $data['has_sensitive'],
			'summary'       => isset( $data['summary'] ) ? (string) $data['summary'] : '',
			'categories'    => isset( $data['categories'] ) && is_array( $data['categories'] ) ? array_map( 'strval', $data['categories'] ) : [],
		];
	}

	/**
	 * Create a Conversation.
	 * 
	 * @param array<string, mixed> $params Parameters.
	 * 
	 * @return string|\WP_Error
	 */
	public function create_conversation( $params = [] ) {
		$system_prompt = $this->get_system_prompt();

		if ( '' !== $system_prompt && ! isset( $params['items'] ) ) {
			// A developer message is a dedicated instruction channel the model
			// ranks above conversation content, and it is stored once per thread.
			$params['items'] = [
				[
					'type'    => 'message',
					'role'    => 'developer',
					'content' => self::compact_prompt( "SITE OWNER INSTRUCTIONS:\r\n" . $system_prompt . "\r\n\r\n" . self::OWNER_INSTRUCTIONS_FOOTER ),
				],
			];
		}

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
		return [
			'conversation' => $conversation,
			'model'        => $this->chat_model,
			'input'        => $items,
			'instructions' => self::compact_prompt( $this->apply_system_prompt( self::BASE_SYSTEM_PROMPT ) ),
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
	 * Create a Response synchronously (no background), returning the completed
	 * response object. Used to run continuation turns of the tool loop on the
	 * poll flow, where a turn must complete before the client polls again.
	 *
	 * @param array<array<string, mixed>> $items        Items.
	 * @param string                      $conversation Conversation.
	 *
	 * @return object|\WP_Error
	 */
	public function create_response_sync( $items, $conversation ) {
		$params = $this->get_chat_response_params( $items, $conversation );

		$response = $this->request(
			'responses',
			apply_filters( 'hyve_create_response_params', $params ),
			'POST',
			60
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response->id ) ) {
			return new \WP_Error( 'unknown_error', __( 'An error occurred while creating the run.', 'hyve-lite' ) );
		}

		return $response;
	}

	/**
	 * Extract completed function_call items from a Response output.
	 *
	 * @param object $response A Response object (from get_response/create_response_sync).
	 *
	 * @return array<int, array{call_id:string,name:string,arguments:string}>
	 */
	public static function extract_tool_calls( $response ) {
		$calls = [];

		if ( ! isset( $response->output ) || ! is_array( $response->output ) ) {
			return $calls;
		}

		foreach ( $response->output as $item ) {
			if ( isset( $item->type ) && 'function_call' === $item->type ) {
				$calls[] = [
					'call_id'   => isset( $item->call_id ) ? $item->call_id : '',
					'name'      => isset( $item->name ) ? $item->name : '',
					'arguments' => isset( $item->arguments ) ? $item->arguments : '',
				];
			}
		}

		return $calls;
	}

	/**
	 * Build error outputs for tool calls that will not be executed. Pending
	 * calls must always be answered, or OpenAI rejects every later turn of the
	 * conversation for missing tool output.
	 *
	 * @param array<int, array<string, string>> $tool_calls The unanswered calls.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function abort_tool_calls( $tool_calls ) {
		$items = [];

		foreach ( $tool_calls as $call ) {
			if ( empty( $call['call_id'] ) ) {
				continue;
			}

			$items[] = [
				'type'    => 'function_call_output',
				'call_id' => $call['call_id'],
				'output'  => '{"error":"Tool calls are unavailable right now. Answer with what you already have."}',
			];
		}

		return $items;
	}

	/**
	 * Force a text answer by disabling tool selection. Attached to
	 * `hyve_create_response_params` for the closing turn after tool calls are
	 * aborted, so the model cannot request another round.
	 *
	 * @param array<string, mixed> $params Response parameters.
	 *
	 * @return array<string, mixed>
	 */
	public static function suppress_tools( $params ) {
		if ( ! empty( $params['tools'] ) ) {
			$params['tool_choice'] = 'none';
		}

		return $params;
	}

	/**
	 * Get the site owner's custom system prompt.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	private function get_system_prompt() {
		$settings = Main::get_settings();

		/**
		 * Filters the custom system prompt for the assistant.
		 *
		 * Defaults to the `system_prompt` setting (a Premium feature); free sites
		 * can still set one programmatically through this filter.
		 *
		 * @since 1.5.0
		 *
		 * @param string $system_prompt The custom system prompt.
		 */
		return trim( (string) apply_filters( 'hyve_system_prompt', $settings['system_prompt'] ?? '' ) );
	}

	/**
	 * Collapse an authored, multi-line prompt into the compact single-line form
	 * sent on the wire. Prompts are kept as readable paragraphs in the source
	 * (the class constants above) but stripped before injection so the request
	 * payload stays lean.
	 *
	 * @param string $prompt The authored prompt.
	 *
	 * @return string
	 */
	private static function compact_prompt( $prompt ) {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $prompt ) );
	}

	/**
	 * Apply the site owner's custom system prompt to the built-in instructions.
	 *
	 * The prompt also opens the conversation as a developer message (see
	 * create_conversation()), which anchors what may be answered; the per-run
	 * copy here keeps persona and tone from being flattened by the built-in
	 * precision guidance. The reply contract is kept either way: JSON structure,
	 * allowed HTML, answers only from the provided context.
	 *
	 * @param string $instructions Built-in instructions.
	 *
	 * @return string
	 */
	private function apply_system_prompt( $instructions ) {
		$system_prompt = $this->get_system_prompt();

		if ( '' === $system_prompt ) {
			return $instructions;
		}

		$preamble = "SITE OWNER INSTRUCTIONS (highest priority):\r\n" . $system_prompt . "\r\n\r\n";

		return $preamble . $instructions . "\r\n\r\n" . self::SYSTEM_PROMPT_REMINDER;
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
	 * @return array{id:string,text:string,tool_calls:array<int,array{call_id:string,name:string,arguments:string}>}|\WP_Error
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
		$tool_calls  = [];

		$write = function ( $ch, $chunk ) use ( &$sse_buffer, &$assembled, &$response_id, &$stream_err, &$tool_calls, $on_delta ) {
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

				// A completed function_call output item carries the tool name and
				// the fully assembled arguments; collect it so the caller can run
				// the tool and continue the turn.
				if ( 'response.output_item.done' === $event->type && isset( $event->item->type ) && 'function_call' === $event->item->type ) {
					$tool_calls[] = [
						'call_id'   => isset( $event->item->call_id ) ? $event->item->call_id : '',
						'name'      => isset( $event->item->name ) ? $event->item->name : '',
						'arguments' => isset( $event->item->arguments ) ? $event->item->arguments : '',
					];
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
			'id'         => $response_id,
			'text'       => $assembled,
			'tool_calls' => $tool_calls,
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
	 * Moderate a batch of inputs in a single request.
	 *
	 * The moderations endpoint accepts an array of inputs, so a batch of chunks
	 * is moderated in one request instead of one request per chunk.
	 *
	 * @param array<string> $inputs Inputs to moderate.
	 *
	 * @return array<int, object{flagged: bool, categories: array<string, bool>, category_scores: array<string, float>, category_applied_input_types: array<string, string[]>}>|\WP_Error Flagged results, or error.
	 */
	private function moderate_batch( $inputs ) {
		$response = $this->request(
			'moderations',
			[
				'input' => $inputs,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$flagged = [];

		if ( isset( $response->results ) && is_array( $response->results ) ) {
			foreach ( $response->results as $result ) {
				if ( isset( $result->flagged ) && $result->flagged ) {
					/**
					 * Moderation result.
					 *
					 * @var object{flagged: bool, categories: array<string, bool>, category_scores: array<string, float>, category_applied_input_types: array<string, string[]>} $result
					 */
					$flagged[] = $result;
				}
			}
		}

		return $flagged;
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

		$openai  = self::instance();
		$results = [];
		$return  = true;

		/**
		 * Filters the moderation category thresholds (0-100 scale).
		 *
		 * A category flagged by OpenAI is only suppressed when its score is below
		 * the matching threshold. Higher values are more permissive (setting all
		 * to 100 effectively disables moderation); lower values are stricter.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, int> $moderation_threshold Category thresholds.
		 */
		$moderation_threshold = apply_filters( 'hyve_moderation_threshold', self::DEFAULT_MODERATION_THRESHOLD );

		if ( ! is_array( $chunks ) ) {
			$chunks = [ $chunks ];
		}

		/**
		 * Filters how many chunks are moderated per request.
		 *
		 * The moderations endpoint accepts an array of inputs, so chunks are
		 * batched rather than sent one request at a time. Chunks are up to ~1000
		 * tokens each, so the default of 5 keeps a request well under the
		 * endpoint's token limit.
		 *
		 * @since 1.5.0
		 *
		 * @param int $size Chunks per moderation request. Default 5.
		 */
		$batch_size = max( 1, (int) apply_filters( 'hyve_moderation_batch_size', 5 ) );

		foreach ( array_chunk( array_values( $chunks ), $batch_size ) as $batch ) {
			$moderated = $this->moderate_batch( $batch );

			if ( is_wp_error( $moderated ) ) {
				return $moderated;
			}

			$results = array_merge( $results, $moderated );
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
	 * @param int                  $timeout  Request timeout in seconds; 0 keeps the default.
	 *
	 * @return mixed
	 */
	private function request( $endpoint, $params = [], $method = 'POST', $timeout = 0 ) {
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
			$args = [
				'headers'     => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				],
				'body'        => $body,
				'method'      => 'POST',
				'data_format' => 'body',
			];

			// A synchronous Response (the tool-loop continuation on the poll
			// flow) generates the full answer inline, so it needs longer than
			// the default 5s. Other POSTs keep the default.
			if ( $timeout > 0 ) {
				$args['timeout'] = $timeout;
			}

			$response = wp_remote_post( self::$base_url . $endpoint, $args );
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
				$error_code = ! empty( $body->error->code ) ? $body->error->code : '';

				// OpenAI can return a 429 with a null code (e.g. brand-new
				// accounts with no credits, even on the free moderation
				// endpoint). Fall back to the HTTP status so the error stays
				// mappable to an actionable message.
				if ( '' === $error_code && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
					$error_code = 'rate_limit_exceeded';
				}

				if ( '' === $error_code ) {
					$error_code = 'unknown_error';
				}

				$error_message = isset( $body->error->message ) ? $body->error->message : __( 'An error occurred while processing the request.', 'hyve-lite' );

				if ( 'POST' === $method ) {
					$this->check_and_save_error(
						[
							'code'    => $error_code,
							'message' => $error_message,
						]
					);
				}

				return new \WP_Error( $error_code, $error_message );
			}
			
			if ( 'POST' === $method && $this->persist_errors ) {
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
		if ( ! $this->persist_errors ) {
			return;
		}

		if ( empty( $error['code'] ) ) {
			return;
		}

		$code = $error['code'];
		
		if ( in_array( $code, self::PERSISTED_ERROR_CODES, true ) ) {
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

	/**
	 * Translate an OpenAI error code into an actionable, user-facing message.
	 *
	 * This is the single source of truth for OpenAI error copy. Callers that
	 * surface an error to the user (REST responses, the dashboard notice) should
	 * run the code through here and fall back to the raw provider message when
	 * this returns null.
	 *
	 * @param int|string $code The OpenAI error code.
	 *
	 * @return string|null The actionable message, or null when the code is unmapped.
	 */
	public static function get_error_message_for_code( $code ) {
		$quota_message = __( 'Your OpenAI account has no available credits. If you are using a free API key, please add billing or upgrade to a paid plan to use AI features.', 'hyve-lite' );
		$auth_message  = __( 'OpenAI could not authenticate the request. Please verify your API key.', 'hyve-lite' );
		$scope_message = __( 'Your OpenAI API key lacks permission for this operation. Please use a key with the required scopes.', 'hyve-lite' );
		$org_message   = __( 'Your OpenAI organization could not be found or is no longer active. Please check your OpenAI account settings.', 'hyve-lite' );

		$messages = [
			'invalid_api_key'          => __( 'The OpenAI API key is incorrect. Please double-check the key you entered.', 'hyve-lite' ),
			'invalid_authentication'   => $auth_message,
			'missing_scope'            => $scope_message,
			'permission_denied'        => $scope_message,
			'insufficient_quota'       => $quota_message,
			'quota_exceeded'           => $quota_message,
			'billing_not_active'       => __( 'Billing is not active on your OpenAI account. Please add a payment method in your OpenAI billing settings.', 'hyve-lite' ),
			'account_deactivated'      => __( 'Your OpenAI account has been deactivated. Please contact OpenAI support to restore access.', 'hyve-lite' ),
			'organization_not_found'   => $org_message,
			'organization_deactivated' => $org_message,
			'rate_limit_exceeded'      => __( 'OpenAI returned a rate limit response (HTTP 429). If you recently created this account or key, it may not have any credits yet. Add a payment method or credits in your OpenAI billing settings. Otherwise you may be sending requests too quickly; wait a moment and try again.', 'hyve-lite' ),
		];

		return $messages[ $code ] ?? null;
	}
}
