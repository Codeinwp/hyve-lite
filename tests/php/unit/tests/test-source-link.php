<?php
/**
 * Tests for chat source link resolution.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\API;

/**
 * Class SourceLinkTest
 */
class SourceLinkTest extends WP_UnitTestCase {

	/**
	 * Invoke the private resolve_source_link() method.
	 *
	 * @param int|string $post_id Source post ID.
	 *
	 * @return string
	 */
	private function resolve( $post_id ) {
		$api    = API::instance();
		$method = new ReflectionMethod( $api, 'resolve_source_link' );
		$method->setAccessible( true );

		return $method->invoke( $api, $post_id );
	}

	/**
	 * Public posts resolve to their permalink.
	 */
	public function test_public_post_returns_permalink() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertSame( get_permalink( $post_id ), $this->resolve( $post_id ) );
	}

	/**
	 * Private posts are not linked.
	 */
	public function test_private_post_returns_empty() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'private' ] );

		$this->assertSame( '', $this->resolve( $post_id ) );
	}

	/**
	 * Password-protected posts are not linked.
	 */
	public function test_password_protected_post_returns_empty() {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
			]
		);

		$this->assertSame( '', $this->resolve( $post_id ) );
	}

	/**
	 * The hyve_chat_source_link filter can override the resolved link,
	 * e.g. for the pro plugin's website-URL sources.
	 */
	public function test_filter_overrides_link() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'private' ] );

		add_filter(
			'hyve_chat_source_link',
			function () {
				return 'https://example.com/source';
			}
		);

		$this->assertSame( 'https://example.com/source', $this->resolve( $post_id ) );

		remove_all_filters( 'hyve_chat_source_link' );
	}

	/**
	 * The filter can suppress a link, e.g. for custom data.
	 */
	public function test_filter_can_suppress_link() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		add_filter( 'hyve_chat_source_link', '__return_empty_string' );

		$this->assertSame( '', $this->resolve( $post_id ) );

		remove_all_filters( 'hyve_chat_source_link' );
	}

	/**
	 * Count the rendered source links in a response.
	 *
	 * @param string $html The response HTML.
	 *
	 * @return int
	 */
	private function count_links( $html ) {
		return substr_count( $html, 'hyve-source__link' );
	}

	/**
	 * Multiple public sources render multiple links, up to the default of 3.
	 */
	public function test_appends_multiple_links_up_to_default_limit() {
		$post_ids = self::factory()->post->create_many( 5, [ 'post_status' => 'publish' ] );

		$response = API::instance()->maybe_append_source_link( 'Answer.', $post_ids );

		$this->assertStringContainsString( 'hyve-source', $response );
		$this->assertSame( 3, $this->count_links( $response ) );
	}

	/**
	 * The hyve_source_link_limit filter controls how many links are shown.
	 */
	public function test_limit_filter_controls_link_count() {
		$post_ids = self::factory()->post->create_many( 4, [ 'post_status' => 'publish' ] );

		add_filter( 'hyve_source_link_limit', fn() => 2 );
		$response = API::instance()->maybe_append_source_link( 'Answer.', $post_ids );
		$this->assertSame( 2, $this->count_links( $response ) );
		remove_all_filters( 'hyve_source_link_limit' );
	}

	/**
	 * A limit of zero suppresses the source links entirely.
	 */
	public function test_zero_limit_suppresses_links() {
		$post_ids = self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		add_filter( 'hyve_source_link_limit', '__return_zero' );
		$response = API::instance()->maybe_append_source_link( 'Answer.', $post_ids );
		$this->assertSame( 'Answer.', $response );
		remove_all_filters( 'hyve_source_link_limit' );
	}

	/**
	 * Non-public sources are skipped; the limit counts only rendered links.
	 */
	public function test_skips_non_public_sources() {
		$public  = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$private = self::factory()->post->create( [ 'post_status' => 'private' ] );
		$public2 = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$response = API::instance()->maybe_append_source_link( 'Answer.', [ $public, $private, $public2 ] );

		$this->assertSame( 2, $this->count_links( $response ) );
	}

	/**
	 * A single scalar ID is still accepted for convenience.
	 */
	public function test_accepts_single_id() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$response = API::instance()->maybe_append_source_link( 'Answer.', $post_id );

		$this->assertSame( 1, $this->count_links( $response ) );
	}

	/**
	 * Invoke the private rank_sources() method.
	 *
	 * @param array<int|string, float> $scores Source score map.
	 *
	 * @return array<int, int|string>
	 */
	private function rank( $scores ) {
		$api    = API::instance();
		$method = new ReflectionMethod( $api, 'rank_sources' );
		$method->setAccessible( true );

		return $method->invoke( $api, $scores );
	}

	/**
	 * A single dominant match drops the low-scoring stragglers.
	 */
	public function test_ranking_drops_low_scoring_stragglers() {
		$ranked = $this->rank(
			[
				'a' => 0.70,
				'b' => 0.42,
				'c' => 0.41,
			]
		);

		$this->assertSame( [ 'a' ], $ranked );
	}

	/**
	 * Genuinely close matches are all kept, ordered by score.
	 */
	public function test_ranking_keeps_close_matches_ordered() {
		$ranked = $this->rank(
			[
				'b' => 0.66,
				'a' => 0.70,
				'c' => 0.30,
			]
		);

		$this->assertSame( [ 'a', 'b' ], $ranked );
	}

	/**
	 * The score-ratio filter controls how aggressively stragglers are dropped;
	 * a ratio of 0 keeps everything that cleared the context threshold.
	 */
	public function test_score_ratio_filter_can_keep_all() {
		add_filter( 'hyve_source_link_score_ratio', '__return_zero' );

		$ranked = $this->rank(
			[
				'a' => 0.70,
				'b' => 0.42,
				'c' => 0.41,
			]
		);

		$this->assertSame( [ 'a', 'b', 'c' ], $ranked );

		remove_all_filters( 'hyve_source_link_score_ratio' );
	}
}
