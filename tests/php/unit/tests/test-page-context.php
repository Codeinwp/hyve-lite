<?php
/**
 * Test_Page_Context class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Hyve_Connect;
use ThemeIsle\HyveLite\Page_Context;
use ThemeIsle\HyveLite\Tokenizer;
use ThemeIsle\HyveLite\Vocab_Loader;
use guttedgarden\Tiktoken\EncoderProvider;

/**
 * Tests for the page context fed to chat turns.
 */
class Test_Page_Context extends WP_UnitTestCase {
	/**
	 * Published post used across tests.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Set up a published post and enable the feature.
	 */
	public function set_up() {
		parent::set_up();

		// The unit bootstrap drops the chunks table; constructing recreates it.
		new DB_Table();

		$this->post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Blue Widget',
				'post_status'  => 'publish',
				'post_content' => 'A widget.',
			]
		);

		add_filter( 'hyve_page_context_enabled', '__return_true' );
	}

	/**
	 * Reset filters and cached content between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'hyve_page_context_enabled' );
		remove_all_filters( 'hyve_page_context_content' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'hyve_page_context_token_limit' );
		$this->set_tokenizer_provider( null );
		delete_transient( Page_Context::CACHE_PREFIX . $this->post_id );
		delete_transient( Page_Context::CACHE_PREFIX . 'url_' . md5( $this->home_root_url() ) );

		parent::tear_down();
	}

	/**
	 * The site root as the normalized resolver produces it.
	 *
	 * @return string
	 */
	private function home_root_url() {
		return home_url( '/' );
	}

	/**
	 * Build a chat request carrying a page URL.
	 *
	 * @param string $url The page_url param.
	 *
	 * @return WP_REST_Request
	 */
	private function chat_request( $url ) {
		$request = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$request->set_param( 'page_url', $url );

		return $request;
	}

	/**
	 * The feature is off unless the filter enables it (Pro's toggle).
	 */
	public function test_disabled_by_default() {
		remove_all_filters( 'hyve_page_context_enabled' );

		$this->assertNull( Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) ) );
	}

	/**
	 * A published post's URL resolves with identity and indexed state.
	 */
	public function test_resolves_public_post() {
		$page = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );

		$this->assertSame( $this->post_id, $page['id'] );
		$this->assertSame( 'Blue Widget', $page['title'] );
		$this->assertFalse( $page['indexed'] );

		update_post_meta( $this->post_id, '_hyve_added', 1 );

		$page = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$this->assertTrue( $page['indexed'] );
	}

	/**
	 * Non-public content must never resolve: the chat endpoint is public, so
	 * drafts, private and password-protected posts would leak otherwise.
	 */
	public function test_rejects_non_public_posts() {
		$draft    = $this->factory()->post->create( [ 'post_status' => 'draft' ] );
		$private  = $this->factory()->post->create( [ 'post_status' => 'private' ] );
		$guarded  = $this->factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
			]
		);
		$instance = Page_Context::instance();

		$this->assertNull( $instance->for_request( $this->chat_request( home_url( '/?p=' . $draft ) ) ) );
		$this->assertNull( $instance->for_request( $this->chat_request( home_url( '/?p=' . $private ) ) ) );
		$this->assertNull( $instance->for_request( $this->chat_request( home_url( '/?p=' . $guarded ) ) ) );
		$this->assertNull( $instance->for_request( $this->chat_request( home_url( '/?p=999999' ) ) ) );
		$this->assertNull( $instance->for_request( $this->chat_request( '' ) ) );
	}

	/**
	 * Only same-origin URLs are accepted: the endpoint is public and triggers
	 * a server-side fetch, so foreign hosts, ports and schemes are rejected.
	 */
	public function test_rejects_cross_origin_urls() {
		$instance = Page_Context::instance();

		$this->assertNull( $instance->for_request( $this->chat_request( 'https://evil.com/?p=' . $this->post_id ) ) );
		$this->assertNull( $instance->for_request( $this->chat_request( 'http://example.org:8443/' ) ) );
		$this->assertNull( $instance->for_request( $this->chat_request( 'ftp://example.org/' ) ) );
		$this->assertNull( $instance->for_request( $this->chat_request( 'not a url' ) ) );
	}

	/**
	 * Unindexed pages use the content filter, and the trimmed result is cached
	 * so extraction does not rerun on every chat turn.
	 */
	public function test_unindexed_content_is_cached() {
		$runs = 0;

		add_filter(
			'hyve_page_context_content',
			function () use ( &$runs ) {
				++$runs;

				return '<p>Plans start at $9 per month.</p>';
			}
		);

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'Plans start at $9 per month.', $block );
		$this->assertStringContainsString( '===START CURRENT PAGE===', $block );
		$this->assertStringContainsString( 'Blue Widget', $block );
		$this->assertSame( 1, $runs );

		Page_Context::instance()->context_block( $page );
		$this->assertSame( 1, $runs );
	}

	/**
	 * An unindexed page is extracted like a knowledge base page: a contact
	 * link keeps its number, and a table keeps its labels, instead of being
	 * flattened by a plain tag strip.
	 */
	public function test_unindexed_content_is_extracted_like_the_knowledge_base() {
		add_filter(
			'hyve_page_context_content',
			fn() => '<h2>Contact</h2><p><a href="tel:+441274998877">Call us</a></p>' .
				'<table><tr><td>Laundry</td><td>$32.00/hr</td></tr></table>'
		);

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'Call us (+441274998877)', $block );
		$this->assertStringContainsString( 'Laundry | $32.00/hr', $block );
	}

	/**
	 * A loop page (home, archive) is extracted the same way as a post.
	 */
	public function test_loop_page_content_is_extracted_like_the_knowledge_base() {
		add_filter(
			'hyve_page_context_content',
			fn() => '<h2>Contact</h2><p><a href="tel:+441274998877">Call us</a></p>' .
				'<table><tr><td>Laundry</td><td>$32.00/hr</td></tr></table>'
		);

		$page  = Page_Context::instance()->for_request( $this->chat_request( home_url( '/' ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertSame( 0, $page['id'] );
		$this->assertStringContainsString( 'Call us (+441274998877)', $block );
		$this->assertStringContainsString( 'Laundry | $32.00/hr', $block );
	}

	/**
	 * Replace the encoder provider the tokenizer shares across calls.
	 *
	 * @param EncoderProvider|null $provider The provider, or null to rebuild on next use.
	 *
	 * @return void
	 */
	private function set_tokenizer_provider( ?EncoderProvider $provider ): void {
		$values = [
			'provider' => $provider,
			'failure'  => null,
		];

		foreach ( $values as $name => $value ) {
			$property = new ReflectionProperty( Tokenizer::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * Make the vocabulary unloadable: no cache directory and no download.
	 *
	 * @return void
	 */
	private function break_tokenizer(): void {
		add_filter( 'pre_http_request', fn() => new WP_Error( 'http_request_failed', 'No route to host' ) );

		$provider = new EncoderProvider();
		$provider->setVocabLoader( new Vocab_Loader( [ __FILE__ . '/nope' ] ) );
		$this->set_tokenizer_provider( $provider );
	}

	/**
	 * Without a loadable vocabulary an unindexed page's content is trimmed by
	 * characters instead of crashing the chat request.
	 */
	public function test_page_content_is_trimmed_by_characters_when_the_vocabulary_is_unavailable(): void {
		$this->break_tokenizer();
		add_filter( 'hyve_page_context_token_limit', fn() => 5 );

		add_filter( 'hyve_page_context_content', fn() => '<p>' . str_repeat( 'lorem ipsum ', 50 ) . '</p>' );

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'lorem ipsum', $block );
		$this->assertStringNotContainsString( str_repeat( 'lorem ipsum ', 5 ), $block );
	}

	/**
	 * Editing the post invalidates the cached content.
	 */
	public function test_cache_invalidated_on_post_update() {
		$runs = 0;

		add_filter(
			'hyve_page_context_content',
			function () use ( &$runs ) {
				++$runs;

				return 'Version ' . $runs;
			}
		);

		$page = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );

		$this->assertStringContainsString( 'Version 1', Page_Context::instance()->context_block( $page ) );

		// Stale the cached record's modified marker, as a post edit would.
		$key    = Page_Context::CACHE_PREFIX . $this->post_id;
		$cached = get_transient( $key );

		$cached['modified'] = '2000-01-01 00:00:00';
		set_transient( $key, $cached, DAY_IN_SECONDS );

		$this->assertStringContainsString( 'Version 2', Page_Context::instance()->context_block( $page ) );
		$this->assertSame( 2, $runs );
	}

	/**
	 * A failed extraction produces no block and the chat turn proceeds.
	 */
	public function test_no_content_yields_empty_block() {
		$page = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );

		$this->assertSame( '', Page_Context::instance()->context_block( $page ) );
	}

	/**
	 * A loop page (home, archive) resolves without a post: the extractor gets
	 * the normalized URL, the result is cached by URL, and query strings do
	 * not fragment the cache.
	 */
	public function test_loop_page_resolves_by_url() {
		$runs      = 0;
		$seen_url  = null;
		$seen_post = 'unset';

		add_filter(
			'hyve_page_context_content',
			function ( $content, $post, $url ) use ( &$runs, &$seen_url, &$seen_post ) {
				++$runs;
				$seen_url  = $url;
				$seen_post = $post;

				return 'Latest widget news and reviews.';
			},
			10,
			3
		);

		$page = Page_Context::instance()->for_request( $this->chat_request( home_url( '/' ) ) );

		$this->assertSame( 0, $page['id'] );
		$this->assertSame( '', $page['title'] );
		$this->assertSame( $this->home_root_url(), $page['url'] );
		$this->assertFalse( $page['indexed'] );

		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'Latest widget news and reviews.', $block );
		$this->assertStringContainsString( $this->home_root_url(), $block );
		$this->assertSame( $this->home_root_url(), $seen_url );
		$this->assertNull( $seen_post );

		// Query-string variants normalize onto the same cache entry.
		$paged = Page_Context::instance()->for_request( $this->chat_request( home_url( '/?s=widgets&paged=2' ) ) );

		$this->assertSame( $this->home_root_url(), $paged['url'] );

		Page_Context::instance()->context_block( $paged );
		$this->assertSame( 1, $runs );
	}

	/**
	 * Indexed pages pin their stored chunks, skipping chunks the similarity
	 * search already placed in context.
	 */
	public function test_indexed_page_pins_chunks() {
		update_post_meta( $this->post_id, '_hyve_added', 1 );

		$table = DB_Table::instance();
		$table->insert(
			[
				'post_id'      => $this->post_id,
				'post_title'   => 'Blue Widget',
				'post_content' => 'The widget costs $19.',
				'token_count'  => 10,
				'post_status'  => 'processed',
			]
		);
		$table->insert(
			[
				'post_id'      => $this->post_id,
				'post_title'   => 'Blue Widget',
				'post_content' => 'Free shipping worldwide.',
				'token_count'  => 10,
				'post_status'  => 'processed',
			]
		);

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'The widget costs $19.', $block );
		$this->assertStringContainsString( 'Free shipping worldwide.', $block );

		// A chunk already in the retrieved context is not pinned twice.
		$existing = '===START POST=== Blue Widget - The widget costs $19. ===END POST===';
		$block    = Page_Context::instance()->context_block( $page, $existing );

		$this->assertStringNotContainsString( 'The widget costs $19.', $block );
		$this->assertStringContainsString( 'Free shipping worldwide.', $block );
	}

	/**
	 * Store a chunk of the test page with its real token count.
	 *
	 * @param string $content Chunk text.
	 *
	 * @return int The chunk's token count.
	 */
	private function insert_page_chunk( string $content ): int {
		$count = Tokenizer::count_tokens( $content );

		DB_Table::instance()->insert(
			[
				'post_id'      => $this->post_id,
				'post_title'   => 'Blue Widget',
				'post_content' => $content,
				'token_count'  => $count,
				'post_status'  => 'processed',
			]
		);

		return $count;
	}

	/**
	 * The tokens the block's wrapper costs for the test page.
	 *
	 * @param array{id:int,title:string,url:string,indexed:bool} $page Resolved page.
	 *
	 * @return int
	 */
	private function wrapper_tokens( array $page ): int {
		$label = $page['title'] . ' (' . $page['url'] . ')';

		return Tokenizer::count_tokens( "\n ===START CURRENT PAGE=== The visitor is currently viewing this page: " . $label . '.  ===END CURRENT PAGE===' );
	}

	/**
	 * The joined block, newlines included, has to fit the budget: each newline
	 * between two chunks costs a token.
	 */
	public function test_pinned_chunks_budget_covers_the_joining_newlines(): void {
		update_post_meta( $this->post_id, '_hyve_added', 1 );

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$first = $this->insert_page_chunk( 'The first chunk describes the widget in plain words' );
		$last  = $this->insert_page_chunk( 'The second chunk ends with ENDMARK' );
		$limit = $this->wrapper_tokens( $page ) + $first + $last;

		add_filter( 'hyve_page_context_token_limit', fn () => $limit );

		$block = Page_Context::instance()->context_block( $page );

		// The chunks fill what the wrapper leaves exactly, so the newline
		// between them costs the second chunk its last token.
		$this->assertStringContainsString( 'The first chunk', $block );
		$this->assertStringContainsString( 'The second chunk', $block );
		$this->assertStringNotContainsString( 'ENDMARK', $block );
	}

	/**
	 * Stored chunks run up to the full limit, so after the wrapper the first
	 * one often does not fit; it is trimmed rather than dropped.
	 */
	public function test_a_first_chunk_larger_than_the_budget_keeps_its_start(): void {
		update_post_meta( $this->post_id, '_hyve_added', 1 );

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$limit = $this->insert_page_chunk( 'START1 ' . str_repeat( 'Members get unlimited studio time and two firings a month. ', 40 ) . 'END1' );

		add_filter( 'hyve_page_context_token_limit', fn () => $limit );

		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'START1', $block );
		$this->assertStringNotContainsString( 'END1', $block );
		$this->assertLessThanOrEqual( $limit, Tokenizer::count_tokens( $block ) );
	}

	/**
	 * Without an encoder the chunk that overruns the budget is trimmed by
	 * characters, rather than dropped or the chat turn failing.
	 */
	public function test_encoder_failure_trims_the_overrunning_chunk_by_characters(): void {
		update_post_meta( $this->post_id, '_hyve_added', 1 );
		$this->break_tokenizer();

		// 600 + 400 fills the 1000 limit exactly, so only the joining newline
		// makes the second chunk need a trim. Its text runs past the 399 tokens
		// left, at four characters each, so the trim has to cut it.
		$chunks = [
			'FIRSTCHUNK'  => [ 600, 'alpha beta gamma delta ' ],
			'SECONDCHUNK' => [ 400, str_repeat( 'alpha beta gamma delta ', 80 ) . 'END2' ],
		];

		foreach ( $chunks as $marker => [ $count, $text ] ) {
			DB_Table::instance()->insert(
				[
					'post_id'      => $this->post_id,
					'post_title'   => 'Blue Widget',
					'post_content' => $marker . ' ' . $text,
					'token_count'  => $count,
					'post_status'  => 'processed',
				]
			);
		}

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'FIRSTCHUNK', $block );
		$this->assertStringContainsString( 'SECONDCHUNK', $block );
		$this->assertStringNotContainsString( 'END2', $block );
	}

	/**
	 * The whole block, identity line and delimiters included, fits the budget.
	 */
	public function test_page_block_fits_its_budget_including_the_wrapper(): void {
		add_filter( 'hyve_page_context_token_limit', fn () => 200 );
		add_filter(
			'hyve_page_context_content',
			fn () => str_repeat( 'The studio runs evening classes in throwing and glazing. ', 60 )
		);

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'evening classes', $block );
		$this->assertLessThanOrEqual( 200, Tokenizer::count_tokens( $block ) );
	}

	/**
	 * When every chunk is already in context, the page is still identified.
	 */
	public function test_indexed_page_identity_marker_when_all_deduped() {
		update_post_meta( $this->post_id, '_hyve_added', 1 );

		DB_Table::instance()->insert(
			[
				'post_id'      => $this->post_id,
				'post_title'   => 'Blue Widget',
				'post_content' => 'The widget costs $19.',
				'token_count'  => 10,
				'post_status'  => 'processed',
			]
		);

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page, 'The widget costs $19.' );

		$this->assertStringContainsString( '===CURRENT PAGE===', $block );
		$this->assertStringContainsString( 'Blue Widget', $block );
		$this->assertStringNotContainsString( 'costs $19', $block );
	}

	/**
	 * A very long title is capped so the block stays within the page budget.
	 */
	public function test_long_title_stays_within_the_budget(): void {
		$title = str_repeat( '陶芸工房🏺', 120 );

		wp_update_post(
			[
				'ID'         => $this->post_id,
				'post_title' => $title,
			]
		);

		add_filter(
			'hyve_page_context_content',
			function () {
				return str_repeat( 'Kiln firing is included in every membership. ', 200 );
			}
		);

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertGreaterThan( 1000, Tokenizer::count_tokens( $title ) );
		$this->assertStringContainsString( 'Kiln firing', $block );
		$this->assertLessThanOrEqual( 1000, Tokenizer::count_tokens( $block ) );

		update_post_meta( $this->post_id, '_hyve_added', 1 );

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( '===CURRENT PAGE===', $block );
		$this->assertStringContainsString( 'viewing the page: 陶芸工房', $block );
		$this->assertLessThanOrEqual( 1000, Tokenizer::count_tokens( $block ) );
	}

	/**
	 * A very long URL is capped too, so an unindexed page keeps its content and
	 * an indexed one its identity, both within the page budget.
	 */
	public function test_long_url_stays_within_the_budget(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$parent = 0;

		for ( $level = 0; $level < 8; $level++ ) {
			$parent = $this->factory()->post->create(
				[
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Deep URL Page',
					'post_name'   => str_repeat( '陶芸工房', 15 ) . $level,
					'post_parent' => $parent,
				]
			);
		}

		add_filter(
			'hyve_page_context_content',
			function () {
				return str_repeat( 'Kiln firing is included in every membership. ', 200 );
			}
		);

		$url  = (string) get_permalink( $parent );
		$page = Page_Context::instance()->for_request( $this->chat_request( $url ) );

		$this->assertSame( $parent, $page['id'] );
		$this->assertGreaterThan( 1000, Tokenizer::count_tokens( $url ) );

		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'viewing this page: Deep URL Page. ', $block );
		$this->assertStringNotContainsString( '陶芸工房', $block );
		$this->assertStringContainsString( 'Kiln firing', $block );
		$this->assertLessThanOrEqual( 1000, Tokenizer::count_tokens( $block ) );

		update_post_meta( $parent, '_hyve_added', 1 );

		$page  = Page_Context::instance()->for_request( $this->chat_request( $url ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'viewing the page: Deep URL Page ===END', $block );
		$this->assertLessThanOrEqual( 1000, Tokenizer::count_tokens( $block ) );
	}

	/**
	 * An ordinary non-Latin URL is carried whole, in its readable form, never
	 * cut in the middle of a percent escape.
	 */
	public function test_a_non_latin_url_is_carried_whole(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$post_id = $this->factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => '陶芸教室のご案内',
				'post_name'   => '陶芸教室のご案内',
			]
		);

		update_post_meta( $post_id, '_hyve_added', 1 );

		$url  = (string) get_permalink( $post_id );
		$page = Page_Context::instance()->for_request( $this->chat_request( $url ) );

		$this->assertGreaterThan( Page_Context::IDENTITY_TOKEN_LIMIT, Tokenizer::count_tokens( $url ) );

		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( '陶芸教室のご案内 (' . rawurldecode( $url ) . ')', $block );
		$this->assertStringNotContainsString( '%', $block );
	}

	/**
	 * Escapes that are not valid text, or that stand for ASCII, stay as sent:
	 * decoding them would corrupt the prompt or change the link.
	 */
	public function test_ascii_and_invalid_escapes_in_a_url_stay_encoded(): void {
		add_filter(
			'hyve_page_context_content',
			function () {
				return 'Latest widget news.';
			}
		);

		foreach ( [ '/%ff%fe/', '/a%3fb/', '/a%0ab/' ] as $path ) {
			$page  = Page_Context::instance()->for_request( $this->chat_request( home_url( $path ) ) );
			$block = Page_Context::instance()->context_block( $page );

			$this->assertStringContainsString( 'viewing this page: ' . home_url( $path ) . '. ', $block );
		}
	}

	/**
	 * A title-less loop page whose URL is too long to carry is still placed on
	 * the site, without the home page being passed off as the one viewed.
	 */
	public function test_a_title_less_page_with_a_long_url_is_placed_on_the_site(): void {
		add_filter(
			'hyve_page_context_content',
			function (): string {
				return 'Latest widget news.';
			}
		);

		$url  = home_url( '/' . str_repeat( '%e9%99%b6%e8%8a%b8%e5%b7%a5%e6%88%bf/', 10 ) . 'page/2/' );
		$page = Page_Context::instance()->for_request( $this->chat_request( $url ) );

		$this->assertSame( '', $page['title'] );
		$this->assertGreaterThan( Page_Context::IDENTITY_TOKEN_LIMIT, Tokenizer::count_tokens( rawurldecode( $url ) ) );

		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'viewing this page: a page on ' . home_url( '/' ) . '. Latest widget news.', $block );
		$this->assertStringNotContainsString( '陶芸工房', $block );
	}

	/**
	 * A title with line breaks and runs of spaces is carried on one line, even
	 * when it is short enough to be carried whole.
	 */
	public function test_a_multiline_title_is_carried_on_one_line(): void {
		wp_update_post(
			[
				'ID'         => $this->post_id,
				'post_title' => "Quartz  jinx\n\nvexes dwarfs.",
			]
		);
		update_post_meta( $this->post_id, '_hyve_added', 1 );

		$page  = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$block = Page_Context::instance()->context_block( $page );

		$this->assertStringContainsString( 'viewing the page: Quartz jinx vexes dwarfs. (', $block );
		$this->assertStringNotContainsString( "\n", trim( $block ) );
		$this->assertStringNotContainsString( '  ', $block );
	}

	/**
	 * The Connect payload carries identity always, content only when unindexed,
	 * and no post id for loop pages.
	 */
	public function test_connect_payload_shape() {
		add_filter(
			'hyve_page_context_content',
			function () {
				return 'Plans start at $9.';
			}
		);

		$page    = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$payload = Page_Context::instance()->payload( $page );

		$this->assertSame( $this->post_id, $payload['id'] );
		$this->assertSame( 'Blue Widget', $payload['title'] );
		$this->assertSame( 'Plans start at $9.', $payload['content'] );

		update_post_meta( $this->post_id, '_hyve_added', 1 );

		$page    = Page_Context::instance()->for_request( $this->chat_request( get_permalink( $this->post_id ) ) );
		$payload = Page_Context::instance()->payload( $page );

		$this->assertArrayNotHasKey( 'content', $payload );

		$page    = Page_Context::instance()->for_request( $this->chat_request( home_url( '/' ) ) );
		$payload = Page_Context::instance()->payload( $page );

		$this->assertArrayNotHasKey( 'id', $payload );
		$this->assertArrayNotHasKey( 'title', $payload );
		$this->assertSame( $this->home_root_url(), $payload['url'] );
		$this->assertSame( 'Plans start at $9.', $payload['content'] );
	}

	/**
	 * The page title stays out of the embedded retrieval query: it biases
	 * retrieval toward the current page, which context_block() already attaches.
	 */
	public function test_page_title_is_not_embedded_in_the_retrieval_query() {
		update_option(
			'hyve_settings',
			[
				'ai_mode' => Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			]
		);

		// OpenAI::instance() reads the key once and caches the instance for the
		// process, so an earlier test can leave a keyless one behind.
		$instance = new \ReflectionProperty( \ThemeIsle\HyveLite\OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$embedded = [];

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$embedded ) {
				if ( false !== strpos( $url, '/moderations' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'results' => [ [ 'flagged' => false ] ] ] ),
					];
				}

				if ( false !== strpos( $url, '/embeddings' ) ) {
					$body       = json_decode( $args['body'], true );
					$embedded[] = $body['input'];

					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'data' => [ [ 'embedding' => [ 0.1, 0.2, 0.3 ] ] ] ] ),
					];
				}

				// The turn ends by queueing a background run.
				if ( false !== strpos( $url, '/responses' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'id' => 'resp_test' ] ),
					];
				}

				// Fail any unfaked request instead of letting it reach the network.
				return new \WP_Error( 'unexpected_request', 'Unfaked outbound request: ' . $url );
			},
			10,
			3
		);

		// A supplied thread id keeps the turn off create_conversation().
		$request = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$request->set_param( 'message', 'what is your address?' );
		$request->set_param( 'page_url', get_permalink( $this->post_id ) );
		$request->set_param( 'thread_id', 'conv_local' );

		API::instance()->send_chat( $request );

		$this->assertSame( [ 'what is your address?' ], $embedded );
	}
}
