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
}
