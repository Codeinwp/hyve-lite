<?php
/**
 * Test_Page_Context class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Page_Context;

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
}
