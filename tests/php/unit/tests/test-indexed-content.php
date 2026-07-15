<?php
/**
 * Test_Indexed_Content class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\DB_Table;

/**
 * Class Test_Indexed_Content.
 *
 * Without Pro, plugin-owned sources (hyve_docs: custom data, URLs, sitemap
 * pages, documents) still appear in the unified "Indexed content" listing so
 * users can see and remove them.
 */
class Test_Indexed_Content extends WP_UnitTestCase {

	/**
	 * Ensure the chunks table exists and act as an admin.
	 */
	public function setUp(): void {
		parent::setUp();
		new DB_Table();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Create an indexed plugin-owned source.
	 *
	 * @param array<string, mixed> $meta  Extra post meta.
	 * @param string               $title Post title.
	 *
	 * @return int
	 */
	private function docs_source( $meta = [], $title = 'Imported page' ) {
		$post_id = self::factory()->post->create(
			[
				'post_type'  => 'hyve_docs',
				'post_title' => $title,
			]
		);

		update_post_meta( $post_id, '_hyve_added', 1 );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Fetch the unified included listing, keyed by post id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function indexed_rows() {
		$request = new WP_REST_Request( 'GET', '/hyve/v1/data' );
		$request->set_query_params(
			[
				'status' => 'included',
				'type'   => 'any',
			]
		);

		$rows = [];

		foreach ( rest_do_request( $request )->get_data()['posts'] as $row ) {
			$rows[ (int) $row['ID'] ] = $row;
		}

		return $rows;
	}

	/**
	 * The unified listing shows plugin-owned sources next to regular posts,
	 * labeled by origin and flagged as permanently removable.
	 */
	public function test_listing_includes_plugin_owned_sources() {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_hyve_added', 1 );

		$sitemap = $this->docs_source( [ '_hyve_type' => 'sitemap' ], 'Imported: Pricing' );
		$custom  = $this->docs_source( [], 'Refund policy' );

		$rows = $this->indexed_rows();

		$this->assertArrayHasKey( $post, $rows );
		$this->assertArrayNotHasKey( 'permanent', $rows[ $post ] );

		$this->assertSame( 'Sitemap', $rows[ $sitemap ]['type'] );
		$this->assertTrue( $rows[ $sitemap ]['permanent'] );
		$this->assertSame( 'Custom Data', $rows[ $custom ]['type'] );
	}

	/**
	 * Link/sitemap sources without a title fall back to their source URL.
	 */
	public function test_untitled_source_lists_its_url() {
		$link = $this->docs_source(
			[
				'_hyve_type'   => 'link',
				'_hyve_source' => 'https://example.com/pricing',
			],
			''
		);

		$this->assertSame( 'https://example.com/pricing', $this->indexed_rows()[ $link ]['title'] );
	}

	/**
	 * In Connect mode each row reports whether it reached the platform, so
	 * the UI can flag over-limit sources instead of calling them indexed.
	 * Self-hosted rows carry no such flag.
	 */
	public function test_connect_mode_reports_sync_state() {
		$self_hosted = $this->docs_source();
		$this->assertArrayNotHasKey( 'synced', $this->indexed_rows()[ $self_hosted ] );

		update_option( 'hyve_settings', [ 'ai_mode' => \ThemeIsle\HyveLite\Hyve_Connect::MODE_CONNECT ] );
		// The Connect listing reads the platform stats; keep it off the network.
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_blocked', 'Blocked in tests.' );
			}
		);

		$synced = $this->docs_source( [ '_hyve_connect_synced_hash' => 'HASH' ] );

		$rows = $this->indexed_rows();

		$this->assertTrue( $rows[ $synced ]['synced'] );
		$this->assertFalse( $rows[ $self_hosted ]['synced'] );
	}

	/**
	 * Removing a plugin-owned source deletes it outright; a regular post only
	 * loses its Knowledge Base markers.
	 */
	public function test_delete_removes_plugin_owned_sources_permanently() {
		$post    = self::factory()->post->create();
		$sitemap = $this->docs_source( [ '_hyve_type' => 'sitemap' ] );
		update_post_meta( $post, '_hyve_added', 1 );

		foreach ( [ $post, $sitemap ] as $id ) {
			$request = new WP_REST_Request( 'DELETE', '/hyve/v1/data' );
			$request->set_query_params( [ 'id' => $id ] );
			rest_do_request( $request );
		}

		$this->assertInstanceOf( 'WP_Post', get_post( $post ) );
		$this->assertSame( '', get_post_meta( $post, '_hyve_added', true ) );
		$this->assertNull( get_post( $sitemap ) );
	}
}
