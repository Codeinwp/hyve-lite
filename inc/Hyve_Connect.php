<?php
/**
 * Hyve_Connect class.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\Main;

/**
 * Client for the hosted AI platform (Hyve Connect).
 *
 * Speaks the agents workflow protocol over Server-Sent Events. Bounded actions
 * (`hyve-kb`, `hyve/quota`) are buffered and parsed for their terminal event;
 * `hyve-chat` is relayed incrementally so the widget streams as the platform
 * does. In Connect mode the platform owns the whole RAG pipeline (chunk,
 * moderate, embed, store, retrieve, generate); the plugin never embeds or
 * searches locally.
 */
class Hyve_Connect {
	/**
	 * Hosted AI mode: the platform does everything.
	 *
	 * @var string
	 */
	const MODE_CONNECT = 'hyve_connect';

	/**
	 * Self-hosted mode: own OpenAI key + local table or Qdrant (today's behavior).
	 *
	 * @var string
	 */
	const MODE_SELF = 'self_hosted';

	/**
	 * Knowledge base workflow slug.
	 *
	 * @var string
	 */
	const SLUG_KB = 'hyve-kb';

	/**
	 * Chat workflow slug.
	 *
	 * @var string
	 */
	const SLUG_CHAT = 'hyve-chat';

	/**
	 * Aggregated quota route (the one dashboard call).
	 *
	 * @var string
	 */
	const PATH_QUOTA = 'hyve/quota';

	/**
	 * The service error option key for `wp_options`.
	 *
	 * @var string
	 */
	public const ERROR_OPTION_KEY = 'hyve_connect_api_error';

	/**
	 * The single instance of the class.
	 *
	 * @var Hyve_Connect|null
	 */
	private static $instance = null;

	/**
	 * Ensures only one instance of the class is loaded.
	 *
	 * @return Hyve_Connect An instance of the class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resolve the active AI mode from settings.
	 *
	 * Defaults to self-hosted: Connect is opt-in, never a default a site lands on
	 * without choosing it, so only an explicit `hyve_connect` turns it on.
	 *
	 * @return string One of MODE_CONNECT|MODE_SELF.
	 */
	public static function get_mode() {
		$settings = Main::get_settings();

		return isset( $settings['ai_mode'] ) && self::MODE_CONNECT === $settings['ai_mode']
			? self::MODE_CONNECT
			: self::MODE_SELF;
	}

	/**
	 * Whether Hyve Connect is the active mode.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::MODE_CONNECT === self::get_mode();
	}

	/**
	 * Base URL for the platform workflow API. Filterable for staging/local.
	 *
	 * @return string
	 */
	public function base_url() {
		return apply_filters( 'hyve_connect_base_url', 'https://ai.themeisle.com/api/workflows/' );
	}

	/**
	 * Upsert documents into the hosted knowledge base.
	 *
	 * The platform chunks, moderates, embeds, and stores; the plugin sends
	 * whole documents. Unchanged content is cached server-side and costs
	 * no quota.
	 *
	 * @param array<array<string, mixed>> $documents Normalized documents ({id,type,title,url,content,meta}).
	 *
	 * @return array<string, mixed>|\WP_Error The job_complete payload, or an error.
	 */
	public function kb_upsert( $documents ) {
		return $this->workflow(
			self::SLUG_KB,
			[
				'action'    => 'upsert',
				'documents' => array_values( $documents ),
			]
		);
	}

	/**
	 * Delete documents from the hosted knowledge base by source id.
	 *
	 * @param array<int> $ids Site-scoped source ids.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function kb_delete( $ids ) {
		return $this->workflow(
			self::SLUG_KB,
			[
				'action' => 'delete',
				'ids'    => array_values( array_map( 'intval', $ids ) ),
			]
		);
	}

	/**
	 * Reconcile bucket count. Power of two so the index is a cheap crc32 bitmask.
	 * MUST match the platform's KnowledgeBase::BUCKETS.
	 */
	const KB_BUCKETS = 256;

	/**
	 * Deterministic fingerprint of a whole KB manifest, for the reconcile
	 * fast-path. MUST stay byte-identical with the platform's aggregate
	 * (App\Neuron\Workflows\Hyve\KnowledgeBase::aggregate): sha256 over "id:hash"
	 * lines sorted by id as strings. Equal aggregates mean both sides are in sync.
	 *
	 * @param array<array{id: int|string, hash: string}> $manifest Local sources.
	 *
	 * @return string
	 */
	public static function kb_aggregate( $manifest ) {
		$map = [];

		foreach ( $manifest as $entry ) {
			$map[ (string) $entry['id'] ] = (string) $entry['hash'];
		}

		ksort( $map, SORT_STRING );

		$lines = [];
		foreach ( $map as $id => $hash ) {
			$lines[] = $id . ':' . $hash;
		}

		return hash( 'sha256', implode( "\n", $lines ) );
	}

	/**
	 * The bucket a source id falls in. MUST match KnowledgeBase::bucketOf.
	 *
	 * @param int|string $id Source id.
	 *
	 * @return int
	 */
	public static function kb_bucket_of( $id ) {
		return crc32( (string) $id ) & ( self::KB_BUCKETS - 1 );
	}

	/**
	 * Per-bucket aggregate of a manifest: group sources by bucket, aggregate
	 * each. Only non-empty buckets appear. MUST match KnowledgeBase::bucketHashes.
	 *
	 * @param array<array{id: int|string, hash: string}> $manifest Local sources.
	 *
	 * @return array<int, string> bucket index => aggregate
	 */
	public static function kb_bucket_hashes( $manifest ) {
		$grouped = [];

		foreach ( $manifest as $entry ) {
			$grouped[ self::kb_bucket_of( $entry['id'] ) ][] = $entry;
		}

		$out = [];
		foreach ( $grouped as $idx => $entries ) {
			$out[ $idx ] = self::kb_aggregate( $entries );
		}

		return $out;
	}

	/**
	 * Reconcile the hosted knowledge base against a local manifest.
	 *
	 * Pure read on the platform: returns the diff (orphaned/stale/missing) so the
	 * caller can converge the two sides. Pass only an aggregate for the fast
	 * in-sync check, or the full manifest for the detailed diff.
	 *
	 * @param array<array{id: int|string, hash: string}> $manifest      Local sources, or [] for the fast/bucket paths.
	 * @param string|null                                $aggregate     Local aggregate checksum, or null.
	 * @param array<int, string>                         $bucket_hashes idx => bucket aggregate (bucket-compare mode), or [].
	 * @param array<int>|null                            $buckets       Bucket indices this manifest is scoped to, or null.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function kb_reconcile( $manifest = [], $aggregate = null, $bucket_hashes = [], $buckets = null ) {
		$payload = [ 'action' => 'reconcile' ];

		if ( ! empty( $manifest ) ) {
			$payload['manifest'] = array_values( $manifest );
		}

		if ( null !== $aggregate ) {
			$payload['aggregate'] = (string) $aggregate;
		}

		if ( ! empty( $bucket_hashes ) ) {
			$payload['bucket_hashes'] = $bucket_hashes;
		}

		if ( null !== $buckets ) {
			$payload['buckets'] = array_values( array_map( 'intval', $buckets ) );
		}

		return $this->workflow( self::SLUG_KB, $payload );
	}

	/**
	 * Delete the whole hosted knowledge base for this identity (disconnect/clear).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function kb_delete_all() {
		return $this->workflow(
			self::SLUG_KB,
			[
				'action' => 'delete',
				'all'    => true,
			]
		);
	}

	/**
	 * Fetch the hosted knowledge base state via the dedicated status action.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function kb_status() {
		return $this->workflow( self::SLUG_KB, [ 'action' => 'status' ] );
	}

	/**
	 * Export a batch of stored chunks + vectors for local re-import on disconnect.
	 *
	 * @param string|null $cursor     Pagination cursor; null starts.
	 * @param int         $batch_size Items per batch.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function kb_export( $cursor = null, $batch_size = 50 ) {
		return $this->workflow(
			self::SLUG_KB,
			[
				'action'     => 'export',
				'cursor'     => $cursor,
				'batch_size' => (int) $batch_size,
			]
		);
	}

	/**
	 * Fetch the aggregated dashboard quota (plan, service, kb, chat, indexing).
	 *
	 * This route returns plain JSON, not SSE.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_quota() {
		$response = wp_remote_get(
			$this->base_url() . self::PATH_QUOTA,
			[
				'headers' => $this->get_headers( 'application/json' ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $this->save_error( new \WP_Error( 'hyve_connect_unreachable', $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return $this->map_http_error( $code, $body );
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'hyve_connect_bad_response', __( 'Unexpected response from the hosted AI.', 'hyve-lite' ) );
		}

		delete_option( self::ERROR_OPTION_KEY );

		return $decoded;
	}

	/**
	 * Relay a chat turn from the platform to the browser as it streams.
	 *
	 * Unlike self-hosted (where the plugin extracts the response field from raw
	 * OpenAI deltas), the platform already emits clean `delta` events plus
	 * `kb_state`/`sources`, so those are forwarded straight through `$on_event`.
	 * The terminal `job_complete` payload (reply, answered, sources, thread_id,
	 * usage) is returned to the caller.
	 *
	 * @param array<string, mixed>          $payload  hyve-chat input ({message,thread_id,settings,stream}).
	 * @param callable(string, array): void $on_event Receives each intra-stream event (delta, kb_state, sources).
	 *
	 * @return array<string, mixed>|\WP_Error The job_complete payload, or an error.
	 */
	public function stream_chat( $payload, $on_event ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error( 'no_curl', __( 'cURL is not available.', 'hyve-lite' ) );
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new \WP_Error( 'hyve_connect_invalid_params', __( 'Invalid request.', 'hyve-lite' ) );
		}

		$buffer = '';
		$result = null;
		$error  = null;

		$write = function ( $ch, $chunk ) use ( &$buffer, &$result, &$error, $on_event ) {
			$buffer .= $chunk;

			while ( false !== ( $pos = strpos( $buffer, "\n\n" ) ) ) {
				$frame  = substr( $buffer, 0, $pos );
				$buffer = substr( $buffer, $pos + 2 );
				$parsed = self::parse_frame( $frame );

				if ( null === $parsed ) {
					continue;
				}

				if ( 'job_complete' === $parsed['event'] ) {
					$result = $parsed['data'];
				} elseif ( 'error' === $parsed['event'] ) {
					$error = $parsed['data'];
				} elseif ( '' !== $parsed['event'] && ! in_array( $parsed['event'], [ 'stream_start', 'stream_end' ], true ) ) {
					call_user_func( $on_event, $parsed['event'], $parsed['data'] );
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
				CURLOPT_URL            => $this->base_url() . self::SLUG_CHAT . '/start',
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => $this->curl_headers(),
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

		if ( null !== $error ) {
			return $this->map_sse_error( $error );
		}

		if ( $code >= 400 ) {
			return $this->map_http_error( $code, '' );
		}

		if ( null === $result ) {
			if ( false === $ok && ! ( function_exists( 'connection_aborted' ) && connection_aborted() ) ) {
				return new \WP_Error( 'hyve_connect_stream_failed', $err ? $err : __( 'Streaming request failed.', 'hyve-lite' ) );
			}

			return new \WP_Error( 'hyve_connect_no_result', __( 'The hosted AI did not return a result.', 'hyve-lite' ) );
		}

		delete_option( self::ERROR_OPTION_KEY );

		return $result;
	}

	/**
	 * Run a chat turn without streaming (buffered), for the poll fallback path.
	 *
	 * Same envelope as the stream, minus `delta` events; returns the terminal
	 * `job_complete` payload.
	 *
	 * @param array<string, mixed> $payload hyve-chat input ({message,thread_id,settings,stream:false}).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function chat( $payload ) {
		return $this->workflow( self::SLUG_CHAT, $payload );
	}

	/**
	 * The site persona/config passed through to the platform's prompting.
	 *
	 * Lite ships the basics; pro enriches them through the filter.
	 *
	 * @param array<string, mixed>|null $settings Plugin settings (fetched if null).
	 *
	 * @return array<string, mixed>
	 */
	public static function chat_settings( $settings = null ) {
		if ( null === $settings ) {
			$settings = Main::get_settings();
		}

		return apply_filters(
			'hyve_connect_chat_settings',
			[
				'instructions' => isset( $settings['instructions'] ) ? $settings['instructions'] : '',
			],
			$settings
		);
	}

	/**
	 * The dashboard aggregate, cached in a transient so it is fetched at most
	 * once every few minutes rather than on every admin page load.
	 *
	 * @param bool $force Bypass the cache (e.g. right after connecting).
	 *
	 * @return array<string, mixed> The quota aggregate, or a `{service:error}` marker.
	 */
	public function stats( $force = false ) {
		$key = 'hyve_connect_stats';

		if ( ! $force ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$quota = $this->get_quota();

		if ( is_wp_error( $quota ) ) {
			// Cache a short-lived degraded marker so a down service is not polled
			// on every page load; the UI reads `service` to show the offline state.
			$degraded = [ 'service' => 'error' ];
			set_transient( $key, $degraded, MINUTE_IN_SECONDS );

			return $degraded;
		}

		set_transient( $key, $quota, 5 * MINUTE_IN_SECONDS );

		return $quota;
	}

	/**
	 * Drop the cached stats so the next read re-fetches (connect/disconnect,
	 * license change).
	 *
	 * @return void
	 */
	public static function flush_stats() {
		delete_transient( 'hyve_connect_stats' );
	}

	/**
	 * Last recorded service error, for the dashboard notice.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_last_error() {
		$error = get_option( self::ERROR_OPTION_KEY, [] );

		return is_array( $error ) ? $error : [];
	}

	/**
	 * Turn a Connect WP_Error into a visitor/admin-facing message.
	 *
	 * @param \WP_Error $error The error returned by a client method.
	 *
	 * @return string
	 */
	public static function user_message( $error ) {
		$data = $error->get_error_data();
		$code = isset( $data['code'] ) ? $data['code'] : $error->get_error_code();

		$messages = [
			'quota_exceeded'     => __( 'You have reached your Hyve Connect limit for now. Upgrade your plan for more.', 'hyve-lite' ),
			'moderation_flagged' => __( 'Message was flagged.', 'hyve-lite' ),
			'kb_unavailable'     => __( 'The knowledge base is being prepared. Please try again shortly.', 'hyve-lite' ),
			'provider_error'     => __( 'The hosted AI is temporarily unavailable. Please try again.', 'hyve-lite' ),
		];

		foreach ( $messages as $needle => $message ) {
			if ( false !== strpos( (string) $code, $needle ) ) {
				return $message;
			}
		}

		return __( 'The hosted AI is temporarily unavailable. Please try again.', 'hyve-lite' );
	}

	/**
	 * Run a bounded workflow action over buffered SSE and return its terminal event.
	 *
	 * @param string               $slug    Workflow slug.
	 * @param array<string, mixed> $payload Request body.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function workflow( $slug, $payload ) {
		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new \WP_Error( 'hyve_connect_invalid_params', __( 'Invalid request.', 'hyve-lite' ) );
		}

		$response = wp_remote_post(
			$this->base_url() . $slug . '/start',
			[
				'headers' => $this->get_headers(),
				'body'    => $body,
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $this->save_error( new \WP_Error( 'hyve_connect_unreachable', $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return $this->map_http_error( $code, $body );
		}

		return $this->terminal_result( self::parse_sse( $body ) );
	}

	/**
	 * Reduce a parsed SSE stream to its terminal event.
	 *
	 * An `error` event is terminal with no trailing `stream_end`; success is a
	 * `job_complete` whose data holds the result fields directly (contract).
	 *
	 * @param array<array{event: string, data: array<string, mixed>}> $events Parsed frames.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function terminal_result( $events ) {
		foreach ( $events as $event ) {
			if ( 'error' === $event['event'] ) {
				return $this->map_sse_error( $event['data'] );
			}

			if ( 'job_complete' === $event['event'] ) {
				delete_option( self::ERROR_OPTION_KEY );

				return $event['data'];
			}
		}

		return new \WP_Error( 'hyve_connect_no_result', __( 'The hosted AI did not return a result.', 'hyve-lite' ) );
	}

	/**
	 * Parse a buffered SSE body into an ordered list of events.
	 *
	 * @param string $body Raw SSE body.
	 *
	 * @return array<array{event: string, data: array<string, mixed>}>
	 */
	public static function parse_sse( $body ) {
		$events = [];

		foreach ( explode( "\n\n", (string) $body ) as $frame ) {
			$parsed = self::parse_frame( $frame );

			if ( null !== $parsed ) {
				$events[] = $parsed;
			}
		}

		return $events;
	}

	/**
	 * Parse a single SSE frame into its event name and decoded data.
	 *
	 * @param string $frame Raw frame (lines separated by \n).
	 *
	 * @return array{event: string, data: array<string, mixed>}|null Null for blank/comment-only frames.
	 */
	private static function parse_frame( $frame ) {
		$frame = trim( $frame );

		if ( '' === $frame ) {
			return null;
		}

		$name       = '';
		$data_lines = [];

		foreach ( explode( "\n", $frame ) as $line ) {
			$line = rtrim( $line, "\r" );

			if ( 0 === strpos( $line, ':' ) ) {
				continue; // SSE comment, e.g. ": connected".
			}

			if ( 0 === strpos( $line, 'event:' ) ) {
				$name = trim( substr( $line, 6 ) );
			} elseif ( 0 === strpos( $line, 'data:' ) ) {
				$data_lines[] = ltrim( substr( $line, 5 ), ' ' );
			}
		}

		if ( '' === $name && empty( $data_lines ) ) {
			return null;
		}

		$decoded = empty( $data_lines ) ? null : json_decode( implode( "\n", $data_lines ), true );

		return [
			'event' => $name,
			'data'  => is_array( $decoded ) ? $decoded : [],
		];
	}

	/**
	 * Request headers for wp_remote_* calls.
	 *
	 * Free installs send only `X-Site-Url` (domain identity); licensed installs
	 * add the base64'd license key as the bearer to unlock paid quotas.
	 *
	 * @param string $accept Accept header value.
	 *
	 * @return array<string, string>
	 */
	private function get_headers( $accept = 'text/event-stream' ) {
		$headers = [
			'Content-Type'   => 'application/json',
			'Accept'         => $accept,
			'X-Site-Url'     => get_site_url(),
			'X-Hyve-Version' => defined( 'HYVE_LITE_VERSION' ) ? HYVE_LITE_VERSION : '',
		];

		$license = (string) apply_filters( 'product_hyve_license_key', '' );

		if ( '' !== $license ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- contract requires base64(license_key) as the bearer.
			$headers['Authorization'] = 'Bearer ' . base64_encode( $license );
		}

		return $headers;
	}

	/**
	 * The same headers flattened to the `Key: Value` list cURL expects.
	 *
	 * @return array<int, string>
	 */
	private function curl_headers() {
		$flat = [];

		foreach ( $this->get_headers() as $key => $value ) {
			$flat[] = $key . ': ' . $value;
		}

		return $flat;
	}

	/**
	 * Map an SSE `error` event to a WP_Error, carrying the code + context data.
	 *
	 * @param array<string, mixed> $data The error event data ({message, code, quota?, categories?, kb_state?}).
	 *
	 * @return \WP_Error
	 */
	private function map_sse_error( $data ) {
		$code    = isset( $data['code'] ) ? (string) $data['code'] : 'provider_error';
		$message = isset( $data['message'] ) ? (string) $data['message'] : __( 'The hosted AI request failed.', 'hyve-lite' );
		$error   = new \WP_Error( 'hyve_connect_' . $code, $message, array_merge( $data, [ 'code' => $code ] ) );

		// Persist only the errors that mean the service is degraded; a flagged
		// message or unavailable KB is an expected per-request outcome.
		if ( in_array( $code, [ 'provider_error', 'quota_exceeded' ], true ) ) {
			$this->save_error( $error );
		}

		return $error;
	}

	/**
	 * Map a non-2xx HTTP response to a WP_Error.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body (may be JSON).
	 *
	 * @return \WP_Error
	 */
	private function map_http_error( $code, $body ) {
		$decoded = json_decode( (string) $body, true );
		$message = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : sprintf( 'HTTP %d', $code );

		$slugs = [
			401 => 'auth_failed',
			422 => 'invalid_request',
			429 => 'quota_exceeded',
		];
		$slug  = isset( $slugs[ $code ] ) ? $slugs[ $code ] : 'http_error';

		$error = new \WP_Error(
			'hyve_connect_' . $slug,
			$message,
			[
				'code'   => $slug,
				'status' => $code,
				'quota'  => is_array( $decoded ) && isset( $decoded['quota'] ) ? $decoded['quota'] : null,
			]
		);

		$this->save_error( $error );

		return $error;
	}

	/**
	 * Persist a service error for the dashboard notice.
	 *
	 * @param \WP_Error $error The error.
	 *
	 * @return \WP_Error The same error, for chaining.
	 */
	private function save_error( $error ) {
		update_option(
			self::ERROR_OPTION_KEY,
			[
				'code'     => $error->get_error_code(),
				'message'  => $error->get_error_message(),
				'date'     => wp_date( 'c' ),
				'provider' => 'Hyve Connect',
			]
		);

		return $error;
	}
}
