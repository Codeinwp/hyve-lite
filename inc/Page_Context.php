<?php
/**
 * Page context for chat turns.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Tokenizer;

/**
 * Resolves the page a visitor is chatting from and turns it into model
 * context, so questions about "this page" ("how much does this cost?") can be
 * answered even when the query alone would not retrieve it.
 *
 * Indexed pages are pinned from their existing knowledge base chunks; pages
 * missing from the knowledge base fall back to content supplied through the
 * `hyve_page_context_content` filter (the Pro plugin hooks its scraper there,
 * so builder-rendered pages and product data are captured from the final
 * HTML rather than raw post_content). The whole feature is off until
 * `hyve_page_context_enabled` says otherwise.
 */
class Page_Context {
	/**
	 * Cached transient prefix for unindexed page content.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'hyve_page_context_';

	/**
	 * Most tokens the identity line carries for the page title, and for its URL.
	 * A longer title is cut; a longer URL is left out, since a cut URL is a broken link.
	 *
	 * @var int
	 */
	const IDENTITY_TOKEN_LIMIT = 64;

	/**
	 * The single instance of the class.
	 *
	 * @var Page_Context|null
	 */
	private static $instance = null;

	/**
	 * Ensures only one instance of the class is loaded.
	 *
	 * @return Page_Context An instance of the class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Whether page context is enabled for chat turns.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filters whether the current page is fed to the chatbot as context.
		 *
		 * Disabled by default; the Pro plugin enables it from its settings
		 * toggle.
		 *
		 * @since 1.5.0
		 *
		 * @param bool $enabled Whether page context is enabled. Default false.
		 */
		return (bool) apply_filters( 'hyve_page_context_enabled', false );
	}

	/**
	 * The token budget for the page block inside the prompt context.
	 *
	 * @return int
	 */
	public static function token_limit() {
		/**
		 * Filters the maximum tokens of page content added to the chat context.
		 *
		 * @since 1.5.0
		 *
		 * @param int $limit Token budget for the current-page block. Default 1000.
		 */
		return (int) apply_filters( 'hyve_page_context_token_limit', 1000 );
	}

	/**
	 * Resolve the page a chat request was sent from.
	 *
	 * The widget sends the browser URL; only same-origin URLs are accepted
	 * (this is a public endpoint that can trigger a server-side fetch, so
	 * anything else would make the site an SSRF proxy). A URL that resolves
	 * to a post keeps the full post treatment - chunk pinning, indexed check,
	 * post-keyed cache - and only publicly viewable, non-password-protected
	 * content qualifies. Loop pages (home, archives) resolve to no post and
	 * are handled as a plain URL.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Chat request.
	 *
	 * @return array{id:int,title:string,url:string,indexed:bool}|null
	 */
	public function for_request( $request ) {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$url = $request->get_param( 'page_url' );

		if ( empty( $url ) || ! is_string( $url ) ) {
			return null;
		}

		$validated = $this->validate_same_origin( $url );

		if ( null === $validated ) {
			return null;
		}

		$post_id = function_exists( 'wpcom_vip_url_to_postid' )
			? wpcom_vip_url_to_postid( $validated['with_query'] )
			: url_to_postid( $validated['with_query'] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- Cached VIP variant used when available.

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post || ! is_post_publicly_viewable( $post ) || ! empty( $post->post_password ) ) {
				return null;
			}

			return [
				'id'      => $post_id,
				'title'   => get_the_title( $post ),
				'url'     => (string) get_permalink( $post ),
				'indexed' => (bool) get_post_meta( $post_id, '_hyve_added', true ),
			];
		}

		// No post behind the URL: a loop page (home, archive). The query
		// string is dropped so pagination/filter variants share one cache
		// entry and callers cannot mint unbounded cache keys.
		return [
			'id'      => 0,
			'title'   => '',
			'url'     => $validated['clean'],
			'indexed' => false,
		];
	}

	/**
	 * Validate that a visitor-supplied URL points at this site and rebuild it
	 * from its parsed parts.
	 *
	 * The host and port must match the site's own; the scheme is replaced
	 * with the site's scheme rather than compared, so mixed http/https
	 * front-ends behind proxies still validate. Userinfo, fragments and (for
	 * the clean form) query strings never survive the rebuild.
	 *
	 * @param string $url The URL sent by the widget.
	 *
	 * @return array{clean:string,with_query:string}|null Null when the URL is not same-origin.
	 */
	private function validate_same_origin( $url ) {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );

		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || empty( $home['host'] ) ) {
			return null;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return null;
		}

		if ( strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return null;
		}

		$url_port  = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : 0;

		if ( $url_port !== $home_port ) {
			return null;
		}

		$origin = ( isset( $home['scheme'] ) ? $home['scheme'] : 'http' ) . '://' . $parts['host'] . ( $url_port ? ':' . $url_port : '' );
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		$clean  = $origin . $path;

		return [
			'clean'      => $clean,
			'with_query' => $clean . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
		];
	}

	/**
	 * Build the current-page block appended to the self-hosted chat context.
	 *
	 * @param array{id:int,title:string,url:string,indexed:bool} $page             Resolved page.
	 * @param string                                             $existing_context Knowledge base context already assembled.
	 *
	 * @return string Empty when no usable content was found.
	 */
	public function context_block( $page, $existing_context = '' ) {
		$label = $this->label( $page );
		$open  = "\n ===START CURRENT PAGE=== The visitor is currently viewing this page: " . $label . '. ';
		$close = ' ===END CURRENT PAGE===';

		// The identity line and delimiters count toward the page budget too.
		try {
			$budget  = max( 0, self::token_limit() - Tokenizer::count_tokens( $open . $close ) );
			$content = 0 < $budget ? $this->page_content( $page, $existing_context, $budget ) : '';
		} catch ( \Exception $e ) {
			// The encoder could not load its vocabulary; budget the content alone.
			$content = $this->page_content( $page, $existing_context );
		}

		if ( '' === $content ) {
			// The pinned chunks were all already retrieved; still tell the model
			// which page the visitor is on so "this"-style questions resolve.
			if ( $page['indexed'] ) {
				return "\n ===CURRENT PAGE=== The visitor is currently viewing the page: " . $label . ' ===END CURRENT PAGE===';
			}

			return '';
		}

		return $open . $content . $close;
	}

	/**
	 * Name the page for the identity line: its title and URL, each capped so
	 * neither can fill the page budget.
	 *
	 * @param array{id:int,title:string,url:string,indexed:bool} $page Resolved page.
	 *
	 * @return string
	 */
	private function label( array $page ): string {
		$title = $this->short_title( $page['title'] );
		$url   = $this->whole_url( $page['url'] );

		if ( '' !== $title && '' !== $url ) {
			return $title . ' (' . $url . ')';
		}

		// A page with neither is still placed on this site, without passing the
		// home page off as the one being viewed.
		return '' !== $title ? $title : ( '' !== $url ? $url : 'a page on ' . home_url( '/' ) );
	}

	/**
	 * Cap the page title, on one line, so a long one cannot fill the page budget.
	 *
	 * @param string $title Page title.
	 *
	 * @return string
	 */
	private function short_title( string $title ): string {
		$title = trim( (string) preg_replace( '/\s+/', ' ', $title ) );

		try {
			if ( Tokenizer::count_tokens( $title ) <= self::IDENTITY_TOKEN_LIMIT ) {
				return $title;
			}

			// The cut re-joins sentences with line breaks.
			return trim( (string) preg_replace( '/\s+/', ' ', $this->trim_to_limit( $title, self::IDENTITY_TOKEN_LIMIT ) ) );
		} catch ( \Exception $e ) {
			return mb_substr( $title, 0, self::IDENTITY_TOKEN_LIMIT );
		}
	}

	/**
	 * The page URL in its readable form, or nothing when even that is too long:
	 * a cut URL would hand the model a broken link.
	 *
	 * @param string $url Page URL.
	 *
	 * @return string
	 */
	private function whole_url( string $url ): string {
		// Non-ASCII escapes cost several tokens a character and read the same decoded;
		// ASCII escapes keep their meaning only as sent, and bad bytes stay out of the prompt.
		$readable = (string) preg_replace_callback(
			'/(?:%[89a-f][0-9a-f])+/i',
			function ( array $escape ): string {
				return rawurldecode( $escape[0] );
			},
			$url
		);
		// Checked directly: wp_check_invalid_utf8() passes bytes through on a non-UTF-8 site.
		$url = 1 === preg_match( '//u', $readable ) ? $readable : $url;

		try {
			$fits = Tokenizer::count_tokens( $url ) <= self::IDENTITY_TOKEN_LIMIT;
		} catch ( \Exception $e ) {
			$fits = strlen( $url ) <= 4 * self::IDENTITY_TOKEN_LIMIT;
		}

		return $fits ? $url : '';
	}

	/**
	 * The page's content from the source matching how the page resolved:
	 * stored chunks for indexed posts, extracted content otherwise, keyed by
	 * post for posts and by URL for loop pages.
	 *
	 * @param array{id:int,title:string,url:string,indexed:bool} $page             Resolved page.
	 * @param string                                             $existing_context Knowledge base context already assembled.
	 * @param int|null                                           $limit            Token budget. Default the page context limit.
	 *
	 * @return string
	 */
	private function page_content( $page, $existing_context = '', ?int $limit = null ): string {
		$limit = null === $limit ? self::token_limit() : $limit;

		if ( $page['indexed'] ) {
			return $this->pinned_chunks( $page['id'], $existing_context, $limit );
		}

		$content = $page['id'] ? $this->unindexed_content( $page['id'] ) : $this->url_content( $page['url'] );

		// Cached at the full limit, which the Connect payload uses as is.
		return $limit < self::token_limit() ? $this->trim_to_limit( $content, $limit ) : $content;
	}

	/**
	 * Build the page portion of a Hyve Connect chat payload.
	 *
	 * Indexed pages send identity only (the platform holds their chunks and
	 * pins them server-side); unindexed pages also carry the extracted content.
	 * Loop pages resolve without a post id or title, so those keys are omitted.
	 *
	 * @param array{id:int,title:string,url:string,indexed:bool} $page Resolved page.
	 *
	 * @return array{url:string,id?:int,title?:string,content?:string}
	 */
	public function payload( $page ) {
		$payload = [ 'url' => $page['url'] ];

		if ( $page['id'] ) {
			$payload['id'] = $page['id'];
		}

		if ( '' !== $page['title'] ) {
			$payload['title'] = $page['title'];
		}

		if ( ! $page['indexed'] ) {
			$content = $this->page_content( $page );

			if ( '' !== $content ) {
				$payload['content'] = $content;
			}
		}

		return $payload;
	}

	/**
	 * Concatenate the page's existing knowledge base chunks, up to the token
	 * budget, skipping chunks the similarity search already put in context.
	 *
	 * @param int    $post_id          Page post ID.
	 * @param string $existing_context Knowledge base context already assembled.
	 * @param int    $limit            Token budget.
	 *
	 * @return string
	 */
	private function pinned_chunks( $post_id, $existing_context, int $limit ) {
		$chunks = DB_Table::instance()->get_chunks_by_post_id( $post_id );

		if ( empty( $chunks ) ) {
			return '';
		}

		$tokens = 0;
		$parts  = [];

		foreach ( $chunks as $chunk ) {
			if ( '' !== $existing_context && false !== strpos( $existing_context, $chunk->post_content ) ) {
				continue;
			}

			// The newline joining this chunk to the previous one costs a token.
			$count = intval( $chunk->token_count ) + ( empty( $parts ) ? 0 : 1 );

			// Trimmed to what is left rather than dropped, so a first chunk
			// larger than the budget still gives the page its start.
			if ( $limit < $tokens + $count ) {
				$left = $limit - $tokens - ( empty( $parts ) ? 0 : 1 );
				$rest = 0 < $left ? $this->trim_to_limit( $chunk->post_content, $left ) : '';

				if ( '' !== $rest ) {
					$parts[] = $rest;
				}

				break;
			}

			$parts[] = $chunk->post_content;
			$tokens += $count;
		}

		return implode( "\n", $parts );
	}

	/**
	 * Content for a page that is missing from the knowledge base.
	 *
	 * Extraction (a loopback render + scrape in Pro) is slow, so the trimmed
	 * result is cached per post, keyed on the post's modified date with a TTL
	 * on top, because builder output can change without touching the post
	 * itself. Failures are cached briefly so a broken loopback is not hammered
	 * on every chat turn.
	 *
	 * @param int $post_id Page post ID.
	 *
	 * @return string
	 */
	private function unindexed_content( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$cached = get_transient( self::CACHE_PREFIX . $post_id );

		if ( is_array( $cached ) && isset( $cached['modified'], $cached['content'] ) && $cached['modified'] === $post->post_modified_gmt ) {
			return (string) $cached['content'];
		}

		/**
		 * Filters the raw content used as page context for a page that is not
		 * in the knowledge base.
		 *
		 * Returning a non-empty string (HTML or plain text) supplies the page
		 * content directly; the Pro plugin hooks its scraper here to extract
		 * the rendered page, which captures builder and product output that
		 * never reaches post_content.
		 *
		 * @since 1.5.0
		 *
		 * @param string        $content The page content. Default empty.
		 * @param \WP_Post|null $post    The post being resolved, or null for a loop page (home, archive).
		 * @param string        $url     The page URL to extract.
		 */
		$content = (string) apply_filters( 'hyve_page_context_content', '', $post, (string) get_permalink( $post ) );
		// The same extraction as the knowledge base, so the page reads the
		// same whether it is pinned from the index or scraped on the spot.
		$content = $this->trim_to_limit( Tokenizer::html_to_text( $content ) );

		set_transient(
			self::CACHE_PREFIX . $post_id,
			[
				'modified' => $post->post_modified_gmt,
				'content'  => $content,
			],
			'' === $content ? 15 * MINUTE_IN_SECONDS : $this->cache_ttl()
		);

		return $content;
	}

	/**
	 * Content for a loop page (home, archive) that resolves to no single post.
	 *
	 * Same extraction and trimming as posts, but keyed by the normalized URL
	 * since there is no post to key on, and TTL-only invalidation since there
	 * is no modified date to compare.
	 *
	 * @param string $url The normalized, same-origin page URL.
	 *
	 * @return string
	 */
	private function url_content( $url ) {
		$key    = self::CACHE_PREFIX . 'url_' . md5( $url );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['content'] ) ) {
			return (string) $cached['content'];
		}

		/** This filter is documented in inc/Page_Context.php */
		$content = (string) apply_filters( 'hyve_page_context_content', '', null, $url );
		$content = $this->trim_to_limit( Tokenizer::html_to_text( $content ) );

		set_transient(
			$key,
			[ 'content' => $content ],
			'' === $content ? 15 * MINUTE_IN_SECONDS : $this->cache_ttl()
		);

		return $content;
	}

	/**
	 * How long extracted page content stays cached.
	 *
	 * @return int
	 */
	private function cache_ttl() {
		/**
		 * Filters how long extracted page content is cached, in seconds.
		 *
		 * @since 1.5.0
		 *
		 * @param int $ttl Cache lifetime. Default one day.
		 */
		return (int) apply_filters( 'hyve_page_context_cache_ttl', DAY_IN_SECONDS );
	}

	/**
	 * Trim text to the page context token budget, on sentence boundaries.
	 * Without a loadable tokenizer vocabulary, trims to about four characters
	 * per token on a word boundary instead.
	 *
	 * @param string   $text  Plain text.
	 * @param int|null $limit Token budget. Default the page context limit.
	 *
	 * @return string
	 */
	private function trim_to_limit( $text, ?int $limit = null ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $text ) {
			return '';
		}

		$limit = null === $limit ? self::token_limit() : $limit;

		try {
			$chunks = Tokenizer::create_chunks( $text, $limit );
		} catch ( \RuntimeException $e ) {
			$cut = mb_substr( $text, 0, $limit * 4 );

			return trim( mb_strlen( $cut ) < mb_strlen( $text ) ? (string) preg_replace( '/\s+\S*$/u', '', $cut ) : $cut );
		}

		if ( empty( $chunks ) ) {
			return '';
		}

		return trim( $chunks[0] );
	}
}
