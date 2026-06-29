<?php
/**
 * Streaming chat endpoint.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\Main;
use ThemeIsle\HyveLite\OpenAI;

/**
 * Streams a chat reply over Server-Sent Events.
 *
 * Real streaming cannot go through the WP REST API (it buffers the whole
 * response), so the reply is delivered from a custom admin-ajax endpoint that
 * proxies OpenAI's stream: browser <-> WordPress <-> OpenAI. The widget falls
 * back to the REST poll flow when a host buffers or cannot stream.
 */
class Stream {
	/**
	 * Nonce action for the streaming endpoint.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'hyve_stream';

	/**
	 * Find the byte offset where the JSON `response` field's value begins.
	 *
	 * @param string $buffer The JSON text received so far.
	 *
	 * @return int|null Offset just after the opening quote, or null if not begun.
	 */
	public static function find_response_value_start( $buffer ) {
		if ( ! preg_match( '/"response"\s*:\s*"/', $buffer, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		return $match[0][1] + strlen( $match[0][0] );
	}

	/**
	 * Decode the JSON `response` string value from a (possibly incomplete) buffer.
	 *
	 * Any incomplete trailing escape / surrogate / UTF-8 sequence is held back so
	 * the result is always valid, whole-character UTF-8. Decoding is delegated to
	 * json_decode so every escape - including surrogate-pair emoji - is handled
	 * correctly and identically to the final authoritative decode.
	 *
	 * @param string $buffer The JSON text received so far.
	 * @param int    $start  Offset of the value (from find_response_value_start()).
	 *
	 * @return string Decoded response text so far (may be empty).
	 */
	public static function decode_response_value( $buffer, $start ) {
		$len = strlen( $buffer );
		$i   = $start;
		$end = $len;

		// Find the end of the string value: the first unescaped quote, else EOF.
		while ( $i < $len ) {
			if ( '\\' === $buffer[ $i ] ) {
				$i += 2;
				continue;
			}

			if ( '"' === $buffer[ $i ] ) {
				$end = $i;
				break;
			}

			++$i;
		}

		$raw = self::trim_incomplete_escape( substr( $buffer, $start, $end - $start ) );

		$decoded = json_decode( '"' . $raw . '"' );

		// Drop trailing bytes that form an incomplete UTF-8 sequence, then retry.
		$guard = 0;
		while ( ! is_string( $decoded ) && '' !== $raw && $guard < 4 ) {
			$raw     = substr( $raw, 0, -1 );
			$decoded = json_decode( '"' . $raw . '"' );
			++$guard;
		}

		return is_string( $decoded ) ? $decoded : '';
	}

	/**
	 * Remove an incomplete trailing escape from a raw (still-escaped) JSON string
	 * value so it can be safely wrapped in quotes and decoded.
	 *
	 * @param string $raw Raw escaped value.
	 *
	 * @return string
	 */
	private static function trim_incomplete_escape( $raw ) {
		// A trailing lone (odd-count) backslash is a half-written escape.
		$backslashes = 0;
		$j           = strlen( $raw ) - 1;

		while ( $j >= 0 && '\\' === $raw[ $j ] ) {
			++$backslashes;
			--$j;
		}

		if ( 1 === $backslashes % 2 ) {
			$raw = substr( $raw, 0, -1 );
		}

		// An incomplete \uXXXX (fewer than 4 hex digits).
		$raw = (string) preg_replace( '/\\\\u[0-9a-fA-F]{0,3}$/', '', $raw );

		// A lone high surrogate (\uD800-\uDBFF) still awaiting its low surrogate.
		$raw = (string) preg_replace( '/\\\\u[dD][89abAB][0-9a-fA-F]{2}$/', '', $raw );

		return $raw;
	}

	/**
	 * Decode the `response` field value from a (possibly incomplete) JSON buffer.
	 *
	 * @param string $buffer The JSON text received so far.
	 *
	 * @return string|null Decoded response text so far, or null if not started.
	 */
	public static function extract_partial_response( $buffer ) {
		$start = self::find_response_value_start( $buffer );

		return null === $start ? null : self::decode_response_value( $buffer, $start );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_hyve_stream', [ $this, 'stream' ] );
		add_action( 'wp_ajax_nopriv_hyve_stream', [ $this, 'stream' ] );
	}

	/**
	 * Handle the streaming request.
	 *
	 * @return void
	 */
	public function stream() {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		// Validate before opening the stream, so no SSE headers or buffer teardown
		// happen for an invalid/unauthorized request. A non-200 response makes the
		// client fall back to the poll flow.
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			status_header( 403 );
			exit;
		}

		$job = $token ? get_transient( 'hyve_stream_job_' . $token ) : false;

		if ( ! is_array( $job ) ) {
			status_header( 410 );
			exit;
		}

		// Consume the job so it cannot be replayed.
		delete_transient( 'hyve_stream_job_' . $token );

		$thread_id = isset( $job['thread_id'] ) ? $job['thread_id'] : '';
		$record_id = isset( $job['record_id'] ) ? $job['record_id'] : null;
		$message   = isset( $job['message'] ) ? $job['message'] : '';
		$context   = isset( $job['context'] ) ? $job['context'] : '';
		$is_test   = ! empty( $job['is_test'] );

		Main::add_labels_to_default_settings();
		$settings        = Main::get_settings();
		$default_message = isset( $settings['default_message'] ) ? $settings['default_message'] : '';

		$this->open_stream();

		$items = OpenAI::build_chat_items( $context, $message );

		// Stream the `response` field of the structured reply as it grows so the
		// browser can paint it progressively. The opener offset is cached once
		// found so the buffer is not re-scanned for it on every delta.
		$raw         = '';
		$sent        = 0;
		$value_start = null;

		$on_delta = function ( $delta ) use ( &$raw, &$sent, &$value_start ) {
			$raw .= $delta;

			if ( null === $value_start ) {
				$value_start = self::find_response_value_start( $raw );

				if ( null === $value_start ) {
					return;
				}
			}

			$current = self::decode_response_value( $raw, $value_start );

			if ( strlen( $current ) > $sent ) {
				$this->send_event( 'delta', [ 'text' => substr( $current, $sent ) ] );
				$sent = strlen( $current );
			}
		};

		$result = OpenAI::instance()->stream_response( $items, $thread_id, $on_delta );

		if ( is_wp_error( $result ) ) {
			$this->send_event( 'error', [ 'message' => $result->get_error_message() ] );
			exit;
		}

		// Best-effort guard: if the client already disconnected (it timed out on a
		// buffering host and fell back to polling), skip recording so the fallback
		// records the turn instead. connection_aborted() is only refreshed on a
		// write to the socket, so a short reply fully buffered from the client can
		// still slip through and be recorded by both paths - a narrow, accepted
		// edge bounded by the client's 24h "streaming unsupported" cache.
		if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
			exit;
		}

		// Authoritative result: interpret the complete JSON exactly as the poll
		// flow (get_chat) does, so the recorded message and the shown reply match.
		$interpreted = OpenAI::interpret_chat_payload( $result['text'], $default_message );

		if ( $interpreted['decoded'] ) {
			$payload  = $interpreted['payload'];
			$answered = $interpreted['answered'];
			$final    = $interpreted['final'];
		} else {
			// The JSON did not parse (e.g. the stream was truncated at the 120s
			// cap). Keep whatever was already streamed rather than wiping the
			// visible answer back to the default message.
			$partial = self::extract_partial_response( $result['text'] );

			if ( null !== $partial && '' !== trim( $partial ) ) {
				$answered = true;
				$final    = $partial;
				$payload  = [
					'success'  => true,
					'response' => $partial,
				];
			} else {
				$answered = false;
				$final    = esc_html( $default_message );
				$payload  = [
					'success'  => false,
					'response' => '',
				];
			}
		}

		// Record the turn once, now that the reply has landed: the user message
		// (hyve_chat_request) then the reply (hyve_chat_response), preserving the
		// poll flow's contract so logging and analytics are unaffected. Test chats
		// from the admin live preview are never recorded.
		if ( ! $is_test ) {
			$record_id = apply_filters( 'hyve_chat_request', $thread_id, $record_id, $message );

			do_action( 'hyve_chat_response', $result['id'], $thread_id, $message, $record_id, $payload, $final );
		}

		$this->send_event(
			'done',
			[
				'success'   => $answered,
				'message'   => $final,
				'record_id' => $record_id ? $record_id : null,
			]
		);

		exit;
	}

	/**
	 * Send the SSE headers, disable buffering and emit an initial comment so the
	 * client can quickly detect whether the pipe is flushable.
	 *
	 * @return void
	 */
	private function open_stream() {
		// Streaming can outlast the default execution limit; lift it so long
		// replies are not cut off. Discouraged on managed hosts (where it is a
		// no-op) but required for self-hosted streaming, and guarded so a
		// disabled function is skipped rather than silenced.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		// Stop the host from gzip-buffering the stream. ini_set fails silently
		// when the directive is locked, so no error suppression is needed.
		ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		// Disable output buffering by closing any active buffers (output_buffering
		// itself is PHP_INI_PERDIR and cannot be changed at runtime).
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		echo ': connected' . "\n\n";
		$this->flush();
	}

	/**
	 * Emit a named SSE event with a JSON payload.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data  Payload.
	 *
	 * @return void
	 */
	private function send_event( $event, $data ) {
		// SSE is delivered as text/event-stream, not HTML: the event name is an
		// internal constant and the payload is JSON-encoded, so HTML escaping
		// would corrupt the frame and does not apply here.
		echo 'event: ' . $event . "\n" . 'data: ' . wp_json_encode( $data ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->flush();
	}

	/**
	 * Flush output to the client.
	 *
	 * @return void
	 */
	private function flush() {
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}

		flush();
	}
}
