<?php
/**
 * Tests for the content that reaches the knowledge base on ingest.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Hyve_Connect;
use ThemeIsle\HyveLite\OpenAI;

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
	 * Ensure the KB table exists and OpenAI is answered locally.
	 */
	protected function setUp(): void {
		parent::setUp();
		new DB_Table();
		$this->reset_openai();
		add_filter( 'pre_http_request', [ $this, 'mock_openai' ], 10, 3 );
	}

	/**
	 * Shortcodes are global and the framework does not roll them back.
	 */
	protected function tearDown(): void {
		remove_shortcode( 'hyve_test_hours' );
		$this->reset_openai();

		parent::tearDown();
	}

	/**
	 * OpenAI::instance() keeps the key it read, so a test that set one must not leak it here.
	 */
	private function reset_openai(): void {
		$instance = new ReflectionProperty( OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Answer the moderation and embedding calls the self-hosted ingest makes.
	 *
	 * @param false|array<string, mixed>|\WP_Error $pre  The short-circuit response.
	 * @param array<string, mixed>                 $args The request arguments.
	 * @param string                               $url  The request URL.
	 *
	 * @return false|array<string, mixed>|\WP_Error
	 */
	public function mock_openai( $pre, array $args, string $url ) {
		if ( false !== strpos( $url, '/moderations' ) ) {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'results' => [ [ 'flagged' => false ] ] ] ),
			];
		}

		if ( false !== strpos( $url, '/embeddings' ) ) {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'data' => [ [ 'embedding' => [ 0.7, 0.8, 0.9 ] ] ] ] ),
			];
		}

		return $pre;
	}

	/**
	 * Register a shortcode that renders text, as an active plugin would.
	 *
	 * @param string $output What the shortcode renders.
	 *
	 * @return void
	 */
	private function register_hours_shortcode( string $output = 'Open Monday to Friday, 8am to 6pm.' ): void {
		add_shortcode(
			'hyve_test_hours',
			function () use ( $output ): string {
				return $output;
			}
		);
	}

	/**
	 * Capture every document sent to the platform, answering each call with a
	 * stored result so ingest completes.
	 *
	 * @param array<int, array<string, mixed>> $captured Receives the documents, by reference.
	 *
	 * @return void
	 */
	private function capture_upserts( array &$captured ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, array $args ) use ( &$captured ) {
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
	 * A Connect-mode page whose only content is an inert shortcode, already
	 * refused once for having no text.
	 *
	 * @return int The post ID.
	 */
	private function create_refused_page(): int {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$post_id = $this->factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[hyve_test_hours]',
			]
		);

		DB_Table::instance()->add_post( $post_id, 'add' );

		return $post_id;
	}

	/**
	 * Connect sent raw `post_content`, so page-builder sites embedded their
	 * markup verbatim.
	 */
	public function test_connect_sends_rendered_text_not_raw_post_content(): void {
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
	public function test_self_hosted_stores_the_same_rendered_text(): void {
		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Clinic Opening Hours',
				'post_status'  => 'publish',
				'post_content' => self::BUILDER_CONTENT,
			]
		);

		$this->assertTrue( DB_Table::instance()->add_post( $post_id, 'add' ) );

		// The chunks table is dropped once per run, not per test, so ids repeat
		// across tests: read back the row this ingest just wrote.
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->prefix}hyve WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $stored );
	}

	/**
	 * The text an active shortcode renders only exists after `the_content` runs,
	 * so this is what tells rendering apart from stripping the markers.
	 */
	public function test_connect_sends_what_an_active_shortcode_renders(): void {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$this->register_hours_shortcode();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Clinic Opening Hours',
				'post_status'  => 'publish',
				'post_content' => '[hyve_test_hours]',
			]
		);

		$captured = [];
		$this->capture_upserts( $captured );

		DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $captured[0]['content'] );
	}

	/**
	 * Bulk Add, the Connect sync and the reconcile manifest each used to build
	 * the post's content their own way. The manifest hashed raw content while
	 * the platform held the hash of rendered content, so every edited post
	 * looked stale forever and was pushed again on each reconcile.
	 */
	public function test_every_connect_path_sends_the_same_content(): void {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		$this->register_hours_shortcode();

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Clinic Opening Hours',
				'post_status'  => 'publish',
				'post_content' => '[hyve_test_hours]',
			]
		);

		$table = DB_Table::instance();

		$captured = [];
		$this->capture_upserts( $captured );

		// The single add, which is what the platform stores and hashes.
		$table->add_post( $post_id, 'add' );

		// Drop the synced hash so the post is pending again, then run the sync
		// path behind Bulk Add, enabling Connect, and the requeue.
		delete_post_meta( $post_id, '_hyve_connect_synced_hash' );
		$table->connect_run_sync();

		$this->assertCount( 2, $captured );

		// Asserted outright too: two paths that both sent raw `[hyve_test_hours]` would still be equal.
		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $captured[1]['content'] );
		$this->assertSame( $captured[0]['content'], $captured[1]['content'] );

		// The manifest hash reconcile compares against the platform's.
		$this->assertSame( hash( 'sha256', $captured[0]['content'] ), $table->connect_source_hash( $post_id ) );
	}

	/**
	 * The knowledge base answers anonymous visitors, so content is rendered as
	 * one. A shortcode that checks the current user must not hand the hosted
	 * index what only a signed-in administrator can see.
	 */
	public function test_content_is_rendered_as_a_logged_out_visitor(): void {
		update_option( 'hyve_settings', [ 'ai_mode' => Hyve_Connect::MODE_CONNECT ] );

		add_shortcode(
			'hyve_test_hours',
			function (): string {
				return is_user_logged_in() ? 'Private: staff rota for March.' : 'Open Monday to Friday, 8am to 6pm.';
			}
		);

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = $this->factory()->post->create(
			[
				'post_title'   => 'Clinic Opening Hours',
				'post_status'  => 'publish',
				'post_content' => '[hyve_test_hours]',
			]
		);

		$captured = [];
		$this->capture_upserts( $captured );

		DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $captured[0]['content'] );
		$this->assertTrue( is_user_logged_in(), 'The acting user must be restored.' );
	}

	/**
	 * A page that renders to nothing but a shortcode is refused, so it never
	 * becomes a near-empty chunk that outranks real pages.
	 */
	public function test_shortcode_only_document_is_refused(): void {
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

	/**
	 * The refusal has to outlive the snackbar. A skipped page is never added,
	 * so the listing has to carry its reason on a row that still offers Add,
	 * or the admin reloads and sees nothing explaining the empty result.
	 */
	public function test_a_skipped_page_keeps_a_visible_status_in_the_list(): void {
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post_id = $this->factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'QA Checkout Only',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			]
		);

		DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertNotEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );

		$request  = new WP_REST_Request( 'GET', '/hyve/v1/data' );
		$response = rest_do_request( $request );
		$rows     = $response->get_data()['posts'] ?? [];

		$row = null;

		foreach ( $rows as $candidate ) {
			if ( (int) $candidate['ID'] === $post_id ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row, 'The skipped page is still listed.' );
		$this->assertArrayHasKey( 'error', $row );
		$this->assertStringContainsString( 'no text content to index', $row['error'] );
	}

	/**
	 * The row promises the admin that an edit clears the reason. A refused page
	 * never gets `_hyve_added`, so the clearing has to happen for a page the
	 * knowledge base does not hold.
	 */
	public function test_editing_a_skipped_page_clears_its_status(): void {
		$post_id = $this->factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			]
		);

		DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertNotEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_added', true ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Checkout is open Monday to Friday.',
			]
		);

		$this->assertEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
	}

	/**
	 * A refusal can also be fixed without touching the post: activating the
	 * plugin whose shortcode was inert gives the same page text. The re-add has
	 * to drop the reason, which no edit would have cleared.
	 */
	public function test_a_successful_re_add_clears_the_skipped_status(): void {
		$post_id = $this->create_refused_page();

		$this->assertNotEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );

		$this->register_hours_shortcode();

		$captured = [];
		$this->capture_upserts( $captured );

		DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $captured[0]['content'] );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
	}

	/**
	 * A retry that fails for a new reason has to report that one.
	 */
	public function test_a_failed_retry_reports_its_own_reason(): void {
		$post_id = $this->create_refused_page();

		$refusal = (string) get_post_meta( $post_id, '_hyve_processing_error', true );
		$this->assertStringContainsString( 'no text content to index', $refusal );

		// The shortcode now renders, so the page has text. The platform is what
		// fails this time.
		$this->register_hours_shortcode();

		add_filter(
			'pre_http_request',
			function ( $pre, array $args ) {
				return isset( $args['body'] ) && false !== strpos( (string) $args['body'], '"documents"' )
					? new WP_Error( 'http_request_failed', 'Connection timed out.' )
					: $pre;
			},
			10,
			2
		);

		$result  = DB_Table::instance()->add_post( $post_id, 'add' );
		$current = (string) get_post_meta( $post_id, '_hyve_processing_error', true );

		$this->assertWPError( $result );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_added', true ) );
		$this->assertNotEmpty( $current );
		$this->assertStringNotContainsString( 'no text content to index', $current );
	}

	/**
	 * A retry rejected by moderation drops the refusal; moderation has its own row state.
	 */
	public function test_a_retry_rejected_by_moderation_drops_the_refusal(): void {
		$post_id = $this->create_refused_page();

		$this->assertStringContainsString( 'no text content to index', (string) get_post_meta( $post_id, '_hyve_processing_error', true ) );

		$this->register_hours_shortcode();

		add_filter(
			'pre_http_request',
			function ( $pre, array $args ) {
				$body = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );

				if ( ! isset( $body['documents'][0]['id'] ) ) {
					return $pre;
				}

				$results = [
					[
						'id'     => $body['documents'][0]['id'],
						'status' => 'rejected',
					],
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'event: job_complete' . "\n" . 'data: ' . wp_json_encode( [ 'results' => $results ] ) . "\n\n",
				];
			},
			10,
			2
		);

		$result = DB_Table::instance()->add_post( $post_id, 'add' );

		$this->assertWPError( $result );
		$this->assertSame( 'content_failed_moderation', $result->get_error_code() );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_processing_error', true ) );
	}
}
