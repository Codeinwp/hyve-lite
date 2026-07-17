<?php
/**
 * Tests for importing the hosted knowledge base back on disconnect.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Hyve_Connect;

/**
 * Class ConnectImportTest.
 */
class ConnectImportTest extends WP_UnitTestCase {

	/**
	 * Ensure the KB table exists.
	 */
	protected function setUp(): void {
		parent::setUp();
		new DB_Table();
	}

	/**
	 * Reset filters and options between tests.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'hyve_settings' );
		parent::tearDown();
	}

	/**
	 * Return a canned SSE job_complete body for every HTTP call.
	 *
	 * @param array<string, mixed> $data Terminal payload.
	 *
	 * @return void
	 */
	private function intercept( array $data ) {
		$body = 'event: job_complete' . "\n" . 'data: ' . wp_json_encode( $data ) . "\n\n";

		add_filter(
			'pre_http_request',
			function () use ( $body ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $body,
				];
			},
			10,
			3
		);
	}

	/**
	 * Import rebuilds one row set per surviving post: a stale row from an earlier
	 * attempt is cleared (no duplication) and a source whose post is gone is
	 * skipped rather than imported as an unreachable ghost chunk.
	 */
	public function test_import_skips_ghosts_and_does_not_duplicate() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$post = self::factory()->post->create();
		update_post_meta( $post, '_hyve_added', 1 );

		// A leftover row from a previous, failed import attempt.
		DB_Table::instance()->insert(
			[
				'post_id'     => (string) $post,
				'post_status' => 'processed',
				'storage'     => 'WordPress',
			]
		);

		$this->intercept(
			[
				'model'       => 'openai/text-embedding-3-small',
				'dims'        => 1536,
				'items'       => [
					[
						'id'          => $post,
						'chunk_index' => 0,
						'content'     => 'First chunk.',
						'embedding'   => [ 0.1, 0.2 ],
						'token_count' => 3,
					],
					[
						'id'          => $post,
						'chunk_index' => 1,
						'content'     => 'Second chunk.',
						'embedding'   => [ 0.3, 0.4 ],
						'token_count' => 2,
					],
					[
						'id'          => 987654,
						'chunk_index' => 0,
						'content'     => 'Ghost.',
						'embedding'   => [ 0.9 ],
						'token_count' => 1,
					],
				],
				'next_cursor' => null,
			]
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/connect/disconnect' );
		$request->set_param( 'mode', 'import' );

		API::instance()->connect_disconnect( $request );

		// Exactly the two real chunks: the stale row was cleared, the ghost skipped.
		$this->assertSame( 2, (int) DB_Table::instance()->get_count() );
	}

	/**
	 * Disconnecting with "clear" while a sync is still in flight must not leave
	 * not-yet-synced posts' local chunk rows behind: clear wipes the local index.
	 */
	public function test_clear_disconnect_deletes_leftover_local_chunks() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		// A regular post that was indexed locally but not yet synced up: it still
		// carries its local chunk rows (the sync had not reached it).
		$post = self::factory()->post->create();
		update_post_meta( $post, '_hyve_added', 1 );
		DB_Table::instance()->insert(
			[
				'post_id'     => (string) $post,
				'post_status' => 'processed',
				'storage'     => 'WordPress',
			]
		);
		$this->assertSame( 1, (int) DB_Table::instance()->get_count() );

		// kb_delete_all on the platform succeeds.
		$this->intercept(
			[
				'deleted' => [],
				'all'     => true,
			]
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/connect/disconnect' );
		$request->set_param( 'mode', 'clear' );

		API::instance()->connect_disconnect( $request );

		// The leftover local chunk row is gone and the site is self-hosted again.
		$this->assertSame( 0, (int) DB_Table::instance()->get_count() );
		$this->assertSame( Hyve_Connect::MODE_SELF, \ThemeIsle\HyveLite\Main::get_settings()['ai_mode'] );
	}

	/**
	 * A mismatched embedding model aborts the import (its vectors are unusable
	 * locally) and leaves the site in Connect mode rather than half-migrated.
	 */
	public function test_import_rejects_a_mismatched_model() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$post = self::factory()->post->create();
		update_post_meta( $post, '_hyve_added', 1 );

		$this->intercept(
			[
				'model'       => 'cohere/embed-english-v3',
				'dims'        => 1024,
				'items'       => [
					[
						'id'          => $post,
						'chunk_index' => 0,
						'content'     => 'Nope.',
						'embedding'   => [ 0.1 ],
						'token_count' => 1,
					],
				],
				'next_cursor' => null,
			]
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/connect/disconnect' );
		$request->set_param( 'mode', 'import' );

		$response = API::instance()->connect_disconnect( $request )->get_data();

		$this->assertArrayHasKey( 'error', (array) $response );
		// Nothing imported and still connected: the user can retry or clear.
		$this->assertSame( 0, (int) DB_Table::instance()->get_count() );
		$this->assertSame( Hyve_Connect::MODE_CONNECT, ThemeIsle\HyveLite\Main::get_settings()['ai_mode'] );
	}
}
