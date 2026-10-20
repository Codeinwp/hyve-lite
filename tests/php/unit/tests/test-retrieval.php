<?php
/**
 * Tests for knowledge base retrieval behavior.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Threads;

/**
 * Class RetrievalTest.
 *
 * Covers the WordPress-storage retrieval path (score ordering, the context
 * token budget) and the conversation-aware retrieval query.
 */
class RetrievalTest extends WP_UnitTestCase {

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
	 * Reset filters between tests.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'hyve_chat_context_token_limit' );
		remove_all_filters( 'hyve_context_score_ratio' );
		parent::tearDown();
	}

	/**
	 * Insert a processed, embedded chunk row.
	 *
	 * @param string            $title       Chunk title.
	 * @param array<int, float> $embeddings  Embedding vector.
	 * @param int               $token_count Token count. Large by default, so the
	 *                                       knowledge base outgrows the context
	 *                                       budget and the scored search runs.
	 *
	 * @return int Row ID.
	 */
	private function seed_chunk( $title, $embeddings, $token_count = 1000 ) {
		return $this->table->insert(
			[
				'post_id'      => 1000 + wp_rand( 1, 999 ),
				'post_title'   => $title,
				'post_content' => $title . ' content.',
				'embeddings'   => wp_json_encode( $embeddings ),
				'token_count'  => $token_count,
				'post_status'  => 'processed',
			]
		);
	}

	/**
	 * The strongest match leads the context blob even when everything fits
	 * the budget. It used to be database order, which buried the best match.
	 */
	public function test_wp_search_orders_context_by_score() {
		// Inserted weakest-first on purpose.
		$this->seed_chunk( 'WeakerMatch', [ 0.5, 0.5, 0.0 ] );
		$this->seed_chunk( 'StrongestMatch', [ 1.0, 0.0, 0.0 ] );
		$this->seed_chunk( 'Irrelevant', [ 0.0, 1.0, 0.0 ] );

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertStringContainsString( 'StrongestMatch', $context );
		$this->assertStringContainsString( 'WeakerMatch', $context );
		$this->assertStringNotContainsString( 'Irrelevant', $context );
		$this->assertLessThan(
			strpos( $context, 'WeakerMatch' ),
			strpos( $context, 'StrongestMatch' )
		);
	}

	/**
	 * When the budget is exceeded, the weakest matches are the ones dropped,
	 * and the hyve_chat_context_token_limit filter controls the budget.
	 */
	public function test_context_budget_drops_weakest_and_is_filterable() {
		$this->seed_chunk( 'WeakerMatch', [ 0.5, 0.5, 0.0 ], 150 );
		$this->seed_chunk( 'StrongestMatch', [ 1.0, 0.0, 0.0 ], 150 );

		add_filter(
			'hyve_chat_context_token_limit',
			function () {
				return 200;
			}
		);

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertStringContainsString( 'StrongestMatch', $context );
		$this->assertStringNotContainsString( 'WeakerMatch', $context );
	}

	/**
	 * Chunks scoring far below this query's best match stay out of the
	 * context even when they clear the absolute noise floor, so tangential
	 * pages do not dilute a strong answer.
	 */
	public function test_weak_stragglers_stay_out_of_context() {
		$this->seed_chunk( 'StrongestMatch', [ 1.0, 0.0, 0.0 ] );
		// Above the 0.3 floor, but below 0.6 × the top score (~0.995).
		$this->seed_chunk( 'TangentialMatch', [ 0.4, 0.9, 0.0 ] );
		// Keeps the knowledge base over the budget, so the scored search runs.
		$this->seed_chunk( 'Irrelevant', [ 0.0, 0.0, 1.0 ] );

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertStringContainsString( 'StrongestMatch', $context );
		$this->assertStringNotContainsString( 'TangentialMatch', $context );

		// A ratio of 0 disables the band: everything above the floor is kept.
		add_filter( 'hyve_context_score_ratio', '__return_zero' );

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertStringContainsString( 'TangentialMatch', $context );
	}

	/**
	 * Build the retrieval query through the private method.
	 *
	 * @param string     $message   Current message.
	 * @param int|string $record_id Thread post ID.
	 *
	 * @return string
	 */
	private function build_query( $message, $record_id ) {
		$method = new ReflectionMethod( API::instance(), 'build_retrieval_query' );
		$method->setAccessible( true );

		return $method->invoke( API::instance(), $message, $record_id );
	}

	/**
	 * Follow-up retrieval blends the visitor's earlier turns (so the topic
	 * carries over) but never the bot's replies: fallback apologies drag the
	 * query off-topic and answered replies echo knowledge base text, which
	 * inflates every follow-up's similarity scores.
	 */
	public function test_retrieval_query_blends_visitor_turns_only() {
		$record_id = Threads::create_thread(
			'Do you offer pickleball lessons?',
			[
				'thread_id' => 'conv_q1',
				'sender'    => 'user',
				'message'   => 'Do you offer pickleball lessons?',
			]
		);

		Threads::add_message(
			$record_id,
			[
				'thread_id' => 'conv_q1',
				'sender'    => 'bot',
				'message'   => "Sorry, I'm not able to help with that.",
			]
		);

		$query = $this->build_query( 'How difficult is it?', $record_id );

		$this->assertStringContainsString( 'pickleball', $query );
		$this->assertStringNotContainsString( 'Sorry', $query );

		// The current question comes last, where it weighs the most.
		$this->assertStringEndsWith( 'How difficult is it?', $query );
	}

	/**
	 * The retrieval mode the last search recorded.
	 *
	 * @return string
	 */
	private function retrieval_mode(): string {
		$property = new ReflectionProperty( API::instance(), 'retrieval_mode' );
		$property->setAccessible( true );

		return $property->getValue( API::instance() );
	}

	/**
	 * A knowledge base that fits the context budget goes in whole, so a
	 * question nothing scores for still has every chunk to be answered from.
	 * The scored search still picks the sources.
	 */
	public function test_a_knowledge_base_that_fits_the_budget_is_sent_whole(): void {
		$this->seed_chunk( 'OpeningHours', [ 1.0, 0.0, 0.0 ], 100 );
		$this->seed_chunk( 'Pricing', [ 0.0, 1.0, 0.0 ], 100 );

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertSame( 'kb_full_context', $this->retrieval_mode() );
		$this->assertStringContainsString( 'OpeningHours', $context );
		$this->assertStringContainsString( 'Pricing', $context );

		$retrieval = API::instance()->get_last_retrieval();

		$this->assertCount( 2, $retrieval['chunks'] );
		$this->assertCount( 1, $retrieval['sources'] );
		$this->assertSame( 1.0, round( (float) $retrieval['chunks'][0]['score'], 2 ) + round( (float) $retrieval['chunks'][1]['score'], 2 ) );
	}

	/**
	 * The budget is the limit: a knowledge base one token over it keeps the
	 * scored search.
	 */
	public function test_a_knowledge_base_over_the_budget_keeps_the_scored_search(): void {
		$this->seed_chunk( 'OpeningHours', [ 1.0, 0.0, 0.0 ], 1001 );
		$this->seed_chunk( 'Pricing', [ 0.0, 1.0, 0.0 ], 1000 );

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.3, 2000 );

		$this->assertSame( 'similarity', $this->retrieval_mode() );
		$this->assertStringNotContainsString( 'Pricing', $context );
	}
}
