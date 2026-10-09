<?php
/**
 * Tests for the content that reaches the knowledge base on ingest.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Hyve_Connect;

/**
 * Class IngestContentTest.
 *
 * Both modes must store the rendered, builder-free text of a post, not its raw
 * `post_content`.
 */
class IngestContentTest extends WP_UnitTestCase {

	/**
	 * A page whose content is page-builder markup wrapping real text.
	 *
	 * @var string
	 */
	const BUILDER_CONTENT = '[vc_row css="%7B%22default%22%3A%7B%22margin-top%22%3A%2230px%22%7D%7D"][vc_column width="1/2"]' .
		'[us_iconbox icon="fa-clock" title="Hours"]Open Monday to Friday, 8am to 6pm.[/us_iconbox][/vc_column][/vc_row]';

	/**
	 * Ensure the KB table exists.
	 */
	protected function setUp(): void {
		parent::setUp();
		new DB_Table();
	}

	/**
	 * Capture every document sent to the platform, answering each call with a
	 * stored result so ingest completes.
	 *
	 * @param array<int, array<string, mixed>> $captured Receives the documents, by reference.
	 *
	 * @return void
	 */
	private function capture_upserts( &$captured ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$captured ) {
				$body = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );

				if ( ! isset( $body['documents'] ) ) {
					return $pre;
				}

				$results = [];

				foreach ( $body['documents'] as $document ) {
					$captured[] = $document;

					$results[] = [
						'id'     => $document['id'],
						'status' => 'stored',
						'chunks' => 1,
						'tokens' => 10,
					];
				}

				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'event: job_complete' . "\n" . 'data: ' . wp_json_encode( [ 'results' => $results ] ) . "\n\n",
				];
			},
			10,
			2
		);
	}

	/**
	 * Connect sent raw `post_content`, so page-builder sites embedded their
	 * markup verbatim.
	 */
	public function test_connect_sends_rendered_text_not_raw_post_content() {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Clinic Opening Hours',
				'post_status'  => 'publish',
				'post_content' => self::BUILDER_CONTENT,
			]
		);

		$captured = [];
		$this->capture_upserts( $captured );

		DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $captured[0]['content'] );
		$this->assertStringNotContainsString( '[vc_row', $captured[0]['content'] );
		$this->assertStringNotContainsString( '%22', $captured[0]['content'] );
	}

	/**
	 * The self-hosted path stores the same text, so a site that disconnects
	 * keeps the content it had.
	 */
	public function test_self_hosted_stores_the_same_rendered_text() {
		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Clinic Opening Hours',
				'post_status'  => 'publish',
				'post_content' => self::BUILDER_CONTENT,
			]
		);

		$table = DB_Table::instance();
		$table->add_post( $post_id, 'add' );

		// The chunks table is dropped once per run, not per test, so ids repeat
		// across tests: read back the row this ingest just wrote.
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->prefix}hyve WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $stored );
	}

	/**
	 * A page that renders to nothing but a shortcode is refused, so it never
	 * becomes a near-empty chunk that outranks real pages.
	 */
	public function test_shortcode_only_document_is_refused() {
		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Checkout',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			]
		);

		$result = DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_added', true ) );
	}
}
