<?php
/**
 * Tests for the per-turn retrieval diagnostics (`retrieval_stats`).
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Hyve_Connect;

/**
 * Class RetrievalStatsTest.
 *
 * Covers what each search stage kept, so a refusal can be told apart from a
 * retrieval miss after the fact (Codeinwp/hyve#330).
 */
class RetrievalStatsTest extends WP_UnitTestCase {

	/**
	 * DB_Table instance (constructing recreates the dropped table).
	 *
	 * @var DB_Table
	 */
	protected $table;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->table = new DB_Table();
	}

	/**
	 * Remove committed seeds, caches and filters.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'hyve_context_score_ratio' );
		remove_all_filters( 'hyve_hybrid_retrieval' );
		remove_all_filters( 'locale' );
		remove_all_filters( 'pre_http_request' );
		delete_option( 'hyve_qdrant_status' );
		$this->set_qdrant_instance( null );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'hyve' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_hyve-%' OR option_name LIKE '\\_transient\\_timeout\\_hyve-%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_option( 'hyve_settings' );
		wp_cache_flush();
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		parent::tearDown();
	}

	/**
	 * Commit the seeds so the FULLTEXT index sees them.
	 *
	 * @return void
	 */
	private function commit_seeds(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Replace the shared Qdrant client, or reset it with null.
	 *
	 * @param \ThemeIsle\HyveLite\Qdrant_API|null $instance The client to install.
	 *
	 * @return void
	 */
	private function set_qdrant_instance( $instance ): void {
		$property = new ReflectionProperty( \ThemeIsle\HyveLite\Qdrant_API::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $instance );
	}

	/**
	 * Seed a processed chunk.
	 *
	 * @param string            $title       Chunk title.
	 * @param string            $content     Chunk text.
	 * @param array<int, float> $embeddings  Stored vector.
	 * @param int               $token_count Token count.
	 * @param int               $post_id     Source post ID; random when 0.
	 *
	 * @return int The row ID.
	 */
	private function seed_text( string $title, string $content, array $embeddings, int $token_count = 100, int $post_id = 0 ): int {
		return (int) $this->table->insert(
			[
				'post_id'      => $post_id ? $post_id : 1000 + wp_rand( 1, 999 ),
				'post_title'   => $title,
				'post_content' => $content,
				'embeddings'   => wp_json_encode( $embeddings ),
				'token_count'  => $token_count,
				'post_status'  => 'processed',
			]
		);
	}

	/**
	 * An unrelated chunk over the budget, so the scored search builds the context.
	 *
	 * @return void
	 */
	private function pad_past_budget(): void {
		$this->seed_text( 'Unrelated', 'Unrelated content.', [ 0.0, 0.0, 1.0 ], 2001 );
	}

	/**
	 * The stats of the last search.
	 *
	 * @return array<string, mixed>
	 */
	private function stats(): array {
		$retrieval = API::instance()->get_last_retrieval();

		$this->assertArrayHasKey( 'stats', $retrieval );

		return $retrieval['stats'];
	}

	/**
	 * Each vector stage is counted: above the floor, within the relative
	 * cutoff, and in the context. A keyword pass that did not run is unknown.
	 */
	public function test_scored_search_counts_each_vector_stage(): void {
		$this->seed_text( 'Best', 'Best match.', [ 1.0, 0.0, 0.0 ], 1000 );
		$this->seed_text( 'Close', 'Close match.', [ 0.8, 0.6, 0.0 ], 1000 );
		$this->seed_text( 'Weak', 'Weak match.', [ 0.5, 0.866, 0.0 ], 100 );
		$this->seed_text( 'Off', 'Off topic.', [ 0.0, 1.0, 0.0 ], 100 );
		$this->pad_past_budget();

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000 );

		$stats = $this->stats();

		$this->assertSame( 1, $stats['version'] );
		$this->assertSame( 'local', $stats['vector_backend'] );
		$this->assertSame( 20, $stats['vector_limit'] );
		$this->assertSame( 20, $stats['keyword_limit'] );
		$this->assertSame( 0.25, $stats['vector_floor'] );
		$this->assertSame( 3, $stats['vector_above_floor'] );
		$this->assertSame( 2, $stats['vector_within_cutoff'] );
		$this->assertSame( 1.0, $stats['vector_top_score'] );
		$this->assertSame( 0.6, $stats['vector_cutoff'] );
		$this->assertSame( '', $stats['keyword_query'] );
		$this->assertNull( $stats['keyword_hits'] );
		$this->assertNull( $stats['keyword_within_cutoff'] );
		$this->assertNull( $stats['keyword_term_count'] );
		$this->assertNull( $stats['keyword_matched_terms'] );
		$this->assertNull( $stats['keyword_coverage'] );
		$this->assertSame( 2, $stats['scored_used'] );
		$this->assertSame( 2, $stats['used'] );
	}

	/**
	 * A chunk that only fits the budget after a larger one is skipped counts
	 * as used; the skipped one does not.
	 */
	public function test_used_counts_only_chunks_that_fit_the_budget(): void {
		$this->seed_text( 'Best', 'Best match.', [ 1.0, 0.0, 0.0 ], 1500 );
		$this->seed_text( 'Big', 'Big match.', [ 0.95, 0.31, 0.0 ], 1000 );
		$this->seed_text( 'Small', 'Small match.', [ 0.9, 0.43, 0.0 ], 100 );
		$this->pad_past_budget();

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000 );

		$stats = $this->stats();

		$this->assertSame( 3, $stats['vector_within_cutoff'] );
		$this->assertSame( 2, $stats['scored_used'] );
		$this->assertSame( 2, $stats['used'] );
	}

	/**
	 * A score equal to the cutoff is within it.
	 */
	public function test_a_score_at_the_cutoff_is_within_it(): void {
		add_filter( 'hyve_context_score_ratio', fn() => 1.0 );

		$this->seed_text( 'One', 'One.', [ 1.0, 0.0, 0.0 ], 100 );
		$this->seed_text( 'Two', 'Two.', [ 1.0, 0.0, 0.0 ], 100 );
		$this->pad_past_budget();

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000 );

		$stats = $this->stats();

		$this->assertSame( 2, $stats['vector_within_cutoff'] );
		$this->assertSame( $stats['vector_top_score'], $stats['vector_cutoff'] );
	}

	/**
	 * The vector cap is recorded, and the count never exceeds it.
	 */
	public function test_vector_cap_is_recorded(): void {
		add_filter(
			'hyve_hybrid_retrieval',
			fn( $settings ) => array_merge( $settings, [ 'candidates' => 2 ] )
		);

		foreach ( [ 'A', 'B', 'C' ] as $title ) {
			$this->seed_text( $title, $title . '.', [ 1.0, 0.0, 0.0 ], 100 );
		}
		$this->pad_past_budget();

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000 );

		$stats = $this->stats();

		$this->assertSame( 2, $stats['vector_limit'] );
		$this->assertSame( 2, $stats['keyword_limit'] );
		$this->assertSame( 2, $stats['vector_above_floor'] );
	}

	/**
	 * Coverage folds accents by the default locale, as the platform does,
	 * while source links keep the site's locale (German `ß` → `ss`).
	 */
	public function test_coverage_ignores_the_site_locale_but_source_links_keep_it(): void {
		add_filter( 'locale', fn() => 'de_DE' );

		$this->seed_text( 'Adresse', 'Straße Preise 10 EUR', [ 0.0, 1.0, 0.0 ], 100, 5401 );
		$this->commit_seeds();

		API::instance()->search_knowledge_base( [], 0.25, 2000, 'strasse preise' );

		$this->assertSame( 1, $this->stats()['keyword_matched_terms'] );
		$this->assertSame( 0.5, $this->stats()['keyword_coverage'] );

		$this->seed_text( 'Tram', 'Preise strassenbahn.', [ 0.0, 1.0, 0.0 ], 100, 5402 );
		$this->commit_seeds();

		API::instance()->search_knowledge_base( [], 0.25, 2000, 'strasse preise' );

		// Under German folding both chunks cover both terms, so both link.
		$this->assertEqualsCanonicalizing( [ 5401, 5402 ], array_map( 'intval', API::instance()->get_last_retrieval()['sources'] ) );
		$this->assertSame( 2, $this->stats()['keyword_matched_terms'] );
	}

	/**
	 * Keyword hits and their query-term coverage are recorded, and a chunk
	 * both searches found is used once.
	 */
	public function test_keyword_stage_and_overlap(): void {
		$this->seed_text( 'Clan guide', 'ArtIsWar - cercato anche come artiswar.', [ 0.0, 1.0, 0.0 ] );
		$this->seed_text( 'Welcome', 'Welcome to our artiswar community.', [ 1.0, 0.0, 0.0 ] );
		$this->pad_past_budget();
		$this->commit_seeds();

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'artiswar tune' );

		$stats = $this->stats();

		$this->assertSame( 'artiswar tune', $stats['keyword_query'] );
		$this->assertSame( 1, $stats['vector_above_floor'] );
		$this->assertSame( 2, $stats['keyword_hits'] );
		$this->assertSame( 2, $stats['keyword_within_cutoff'] );
		$this->assertSame( 2, $stats['keyword_term_count'] );
		$this->assertSame( 1, $stats['keyword_matched_terms'] );
		$this->assertSame( 0.5, $stats['keyword_coverage'] );
		$this->assertSame( 2, $stats['scored_used'] );
	}

	/**
	 * A question whose terms are nowhere in the knowledge base measures zero
	 * coverage, which is not the same as not measured.
	 */
	public function test_no_keyword_hit_measures_zero_coverage(): void {
		$this->seed_text( 'Welcome', 'Welcome to our community.', [ 1.0, 0.0, 0.0 ] );
		$this->pad_past_budget();
		$this->commit_seeds();

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'artiswar' );

		$stats = $this->stats();

		$this->assertSame( 0, $stats['keyword_hits'] );
		$this->assertSame( 1, $stats['keyword_term_count'] );
		$this->assertSame( 0, $stats['keyword_matched_terms'] );
		$this->assertSame( 0.0, $stats['keyword_coverage'] );
	}

	/**
	 * Without a vector, the vector stage is unknown and the keyword stage
	 * still counts.
	 */
	public function test_keyword_only_search_leaves_the_vector_stage_unknown(): void {
		$this->seed_text( 'Returns', 'You can return any item within 30 days.', [ 1.0, 0.0, 0.0 ] );
		$this->pad_past_budget();
		$this->commit_seeds();

		API::instance()->search_knowledge_base( [], 0.25, 2000, 'return within 30 days' );

		$stats = $this->stats();

		$this->assertNull( $stats['vector_backend'] );
		$this->assertNull( $stats['vector_limit'] );
		$this->assertNull( $stats['vector_above_floor'] );
		$this->assertNull( $stats['vector_within_cutoff'] );
		$this->assertNull( $stats['vector_top_score'] );
		$this->assertNull( $stats['vector_cutoff'] );
		$this->assertSame( 0.25, $stats['vector_floor'] );
		$this->assertSame( 1, $stats['keyword_hits'] );
		$this->assertSame( 1, $stats['scored_used'] );
	}

	/**
	 * A vector search with no hit above the floor is measured as zero, with
	 * no top score or cutoff.
	 */
	public function test_no_vector_hit_above_the_floor(): void {
		$this->seed_text( 'Off', 'Off topic.', [ 0.0, 1.0, 0.0 ], 100 );
		$this->pad_past_budget();

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000 );

		$stats = $this->stats();

		$this->assertSame( 'local', $stats['vector_backend'] );
		$this->assertSame( 0, $stats['vector_above_floor'] );
		$this->assertSame( 0, $stats['vector_within_cutoff'] );
		$this->assertNull( $stats['vector_top_score'] );
		$this->assertNull( $stats['vector_cutoff'] );
		$this->assertSame( 0, $stats['scored_used'] );
		$this->assertSame( 0, $stats['used'] );
	}

	/**
	 * When the whole knowledge base is sent, `used` counts every chunk and
	 * `scored_used` what the scored search picked.
	 */
	public function test_full_context_counts_every_chunk_as_used(): void {
		$this->seed_text( 'OpeningHours', 'Open nine to five.', [ 1.0, 0.0, 0.0 ], 100 );
		$this->seed_text( 'Pricing', 'Prices.', [ 0.0, 1.0, 0.0 ], 100 );
		$this->seed_text( 'Team', 'Our team.', [ 0.0, 0.0, 1.0 ], 100 );

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$stats = $this->stats();

		$this->assertSame( 1, $stats['scored_used'] );
		$this->assertSame( 3, $stats['used'] );
	}

	/**
	 * Qdrant's own result cap is recorded, and a Qdrant failure records the
	 * local fallback that actually ran.
	 */
	public function test_qdrant_backend_and_its_cap(): void {
		update_option( 'hyve_qdrant_status', 'active' );
		$this->seed_text( 'Welcome', 'Welcome.', [ 1.0, 0.0, 0.0 ], 100, 7 );
		$this->pad_past_budget();

		// Unmigrated rows keep the search local, so mark them migrated.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET storage = %s', $wpdb->prefix . 'hyve', 'Qdrant' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush();

		$this->set_qdrant_instance(
			new class() extends \ThemeIsle\HyveLite\Qdrant_API {
				/**
				 * One vector hit.
				 *
				 * @param array<int, float> $embeddings      Ignored.
				 * @param float             $score_threshold Ignored.
				 *
				 * @return array<int, array<string, mixed>>
				 */
				public function search( $embeddings, $score_threshold ) {
					return [
						[
							'post_id'      => 7,
							'post_title'   => 'Welcome',
							'post_content' => 'Welcome.',
							'token_count'  => 100,
							'score'        => 0.9,
						],
					];
				}
			}
		);

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000 );

		$stats = $this->stats();

		$this->assertSame( 'qdrant', $stats['vector_backend'] );
		$this->assertSame( \ThemeIsle\HyveLite\Qdrant_API::SEARCH_LIMIT, $stats['vector_limit'] );
		$this->assertSame( 1, $stats['vector_above_floor'] );
		$this->assertSame( 0.9, $stats['vector_top_score'] );

		$this->set_qdrant_instance(
			new class() extends \ThemeIsle\HyveLite\Qdrant_API {
				/**
				 * Qdrant is down.
				 *
				 * @param array<int, float> $embeddings      Ignored.
				 * @param float             $score_threshold Ignored.
				 *
				 * @return \WP_Error
				 */
				public function search( $embeddings, $score_threshold ) {
					return new \WP_Error( 'qdrant_down', 'Down.' );
				}
			}
		);

		API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000 );

		$stats = $this->stats();

		$this->assertSame( 'local', $stats['vector_backend'] );
		$this->assertSame( 20, $stats['vector_limit'] );
	}

	/**
	 * The shared parity fixtures (also run by the platform).
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function coverage_fixtures(): array {
		$fixtures = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/keyword-coverage.json' ), true );
		$cases    = [];

		foreach ( $fixtures['cases'] as $case ) {
			$cases[ $case['name'] ] = [ $case ];
		}

		return $cases;
	}

	/**
	 * Coverage matches the shared fixtures.
	 *
	 * @dataProvider coverage_fixtures
	 *
	 * @param array<string, mixed> $fixture The case.
	 */
	public function test_keyword_coverage_fixture( array $fixture ): void {
		$candidates = [];

		foreach ( $fixture['candidates'] as $index => $candidate ) {
			$candidates[] = [
				'key'     => 'c' . $index,
				'title'   => $candidate['title'],
				'content' => $candidate['content'],
			];
		}

		$coverage = API::keyword_coverage( $candidates, $fixture['query'] );
		$matched  = empty( $coverage['matches'] ) ? 0 : max( $coverage['matches'] );

		$this->assertSame( $fixture['term_count'], $coverage['terms'] );
		$this->assertSame( $fixture['matched'], $matched );
		$this->assertSame( null === $fixture['coverage'] ? null : (float) $fixture['coverage'], API::coverage_ratio( $coverage ) );
	}

	/**
	 * A self-hosted turn keeps the stats in its debug trace, with the keyword
	 * query that actually ran, and never sends them to the visitor.
	 */
	public function test_self_hosted_turn_keeps_stats_out_of_the_reply(): void {
		update_option(
			'hyve_settings',
			[
				'ai_mode' => Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			]
		);
		$instance = new ReflectionProperty( \ThemeIsle\HyveLite\OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$this->seed_text( 'Returns', 'You can return any item within 30 days.', [ 1.0, 0.0, 0.0 ] );
		$this->pad_past_budget();
		$this->commit_seeds();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, '/moderations' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'results' => [ [ 'flagged' => false ] ] ] ),
					];
				}

				if ( false !== strpos( $url, '/embeddings' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'data' => [ [ 'embedding' => [ 1.0, 0.0, 0.0 ] ] ] ] ),
					];
				}

				if ( false !== strpos( $url, '/responses' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'id' => 'resp_stats' ] ),
					];
				}

				return new WP_Error( 'unexpected_request', 'Unfaked outbound request: ' . $url );
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$request->set_param( 'message', 'Can I return an item?' );
		$request->set_param( 'thread_id', 'conv_stats' );

		$response = API::instance()->send_chat( $request )->get_data();
		$debug    = get_transient( 'hyve_debug_resp_stats' );
		delete_transient( 'hyve_debug_resp_stats' );

		$this->assertArrayNotHasKey( 'error', $response );
		$this->assertStringNotContainsString( 'retrieval_stats', (string) wp_json_encode( $response ) );
		$this->assertSame( 'Can I return an item?', $debug['retrieval_stats']['keyword_query'] );
		$this->assertSame( 1, $debug['retrieval_stats']['vector_above_floor'] );
		$this->assertSame( 1, $debug['retrieval_stats']['keyword_hits'] );
	}

	/**
	 * The platform's stats are kept with known keys and types only, even on
	 * an unanswered turn with no sources; its tool flag is kept as well.
	 */
	public function test_connect_debug_keeps_typed_platform_stats(): void {
		$debug = API::instance()->connect_debug(
			[
				'answered'        => false,
				'retrieval'       => 'similarity',
				'retrieval_query' => 'artiswar',
				'sources'         => [],
				'tools_used'      => true,
				'retrieval_stats' => [
					'version'               => 1,
					'keyword_query'         => 'artiswar',
					'vector_backend'        => 'pgvector',
					'vector_limit'          => '5',
					'keyword_limit'         => 5,
					'vector_floor'          => 0.25,
					'vector_above_floor'    => 3,
					'vector_within_cutoff'  => 2,
					'vector_top_score'      => 0.61234,
					'vector_cutoff'         => 0.367404,
					'keyword_hits'          => 4,
					'keyword_within_cutoff' => 2,
					'scored_used'           => 2,
					'used'                  => 2,
					'keyword_term_count'    => 1,
					'keyword_matched_terms' => 1,
					'keyword_coverage'      => 1,
					'injected'              => '<script>',
				],
			]
		);

		$stats = $debug['retrieval_stats'];

		$this->assertTrue( $debug['tools_used'] );
		$this->assertArrayNotHasKey( 'injected', $stats );
		$this->assertSame( 5, $stats['vector_limit'] );
		$this->assertSame( 0.6123, $stats['vector_top_score'] );
		$this->assertSame( 1.0, $stats['keyword_coverage'] );
		$this->assertSame( 'pgvector', $stats['vector_backend'] );
	}

	/**
	 * Unusable platform values are dropped or nulled, and an older platform
	 * that sends no stats leaves no trace.
	 */
	public function test_connect_debug_ignores_missing_or_malformed_stats(): void {
		$this->assertArrayNotHasKey( 'retrieval_stats', API::instance()->connect_debug( [ 'answered' => true ] ) );
		$this->assertArrayNotHasKey( 'tools_used', API::instance()->connect_debug( [ 'answered' => true ] ) );
		$this->assertArrayNotHasKey( 'retrieval_stats', API::instance()->connect_debug( [ 'retrieval_stats' => 'nope' ] ) );

		$stats = API::instance()->connect_debug(
			[
				'retrieval_stats' => [
					'version'          => 1,
					'vector_top_score' => 'high',
					'used'             => [ 1 ],
					'keyword_hits'     => -3,
					'keyword_query'    => str_repeat( 'a', 3000 ),
					'vector_backend'   => [ 'x' ],
				],
			]
		)['retrieval_stats'];

		$this->assertNull( $stats['vector_top_score'] );
		$this->assertNull( $stats['used'] );
		$this->assertNull( $stats['keyword_hits'] );
		$this->assertNull( $stats['vector_backend'] );
		$this->assertSame( 2000, mb_strlen( $stats['keyword_query'] ) );
	}
}
