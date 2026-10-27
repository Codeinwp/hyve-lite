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
		remove_all_filters( 'hyve_hybrid_retrieval' );
		remove_all_filters( 'pre_http_request' );
		delete_option( 'hyve_qdrant_status' );
		$this->set_qdrant_instance( null );

		// Committed seeds, and the caches written after the commit, outlive
		// the test transaction; see commit_seeds().
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'hyve' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_hyve-%' OR option_name LIKE '\\_transient\\_timeout\\_hyve-%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		delete_option( 'hyve_settings' );

		foreach ( get_posts(
			[
				'post_type'   => 'hyve_threads',
				'fields'      => 'ids',
				'numberposts' => 100,
			] 
		) as $thread_id ) {
			wp_delete_post( $thread_id, true );
		}

		wp_cache_flush();

		// The cleanup ran in a new transaction after commit_seeds(); the parent
		// rolls back next, so commit it or the seeds and options stay.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		parent::tearDown();
	}

	/**
	 * InnoDB's FULLTEXT index only sees committed rows, and the test case
	 * wraps every test in a transaction: commit the seeds so the keyword
	 * search can find them. tearDown() removes them.
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
	 * Seed a processed chunk with its own title and content.
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
	 * Insert a processed, embedded chunk row.
	 *
	 * @param string            $title       Chunk title.
	 * @param array<int, float> $embeddings  Embedding vector.
	 * @param int               $token_count Token count.
	 *
	 * @return int Row ID.
	 */
	private function seed_chunk( $title, $embeddings, $token_count = 100 ) {
		return $this->seed_text( $title, $title . ' content.', $embeddings, $token_count );
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
	 * A term that appears verbatim in a chunk retrieves it even when its
	 * vector score is below the noise floor: the keyword list carries it in.
	 */
	public function test_exact_term_below_the_vector_floor_is_retrieved() {
		$this->seed_text( 'Clan guide', 'ArtIsWar - cercato anche come Art Is War, ArtisWar o artiswar.', [ 0.0, 1.0, 0.0 ] );
		$this->seed_text( 'Welcome', 'Welcome to our gaming community site.', [ 1.0, 0.0, 0.0 ] );
		$this->commit_seeds();

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'artiswar' );

		$this->assertStringContainsString( 'ArtIsWar - cercato', $context );
		$this->assertStringContainsString( 'Welcome to our gaming', $context );

		$chunks = API::instance()->get_last_retrieval()['chunks'];
		$clan   = array_values( array_filter( $chunks, fn( $chunk ) => 'Clan guide' === $chunk['title'] ) );

		$this->assertNull( $clan[0]['score'] );
		$this->assertGreaterThan( 0, $clan[0]['lexical'] );
	}

	/**
	 * A stronger keyword hit must not remove an exact answer before fusion;
	 * only the context budget may exclude it, and the trace must show it.
	 */
	public function test_competing_keyword_hit_keeps_the_exact_answer() {
		$this->seed_text( 'Overview', str_repeat( 'Silextra ', 30 ), [ 0.0, 1.0, 0.0 ] );
		$this->seed_text( 'Price answer', 'Silextra costs 73 euros per visit.', [ 0.0, 1.0, 0.0 ] );
		$this->seed_text( 'Welcome', 'Welcome to the clinic.', [ 1.0, 0.0, 0.0 ] );
		$this->commit_seeds();

		foreach ( [ [ 1.0, 0.0, 0.0 ], [] ] as $vector ) {
			$context = API::instance()->search_knowledge_base( $vector, 0.25, 2000, 'silextra' );
			$this->assertStringContainsString( '73 euros', $context );
			$chunks = API::instance()->get_last_retrieval()['chunks'];
			$answer = array_values( array_filter( $chunks, fn( $chunk ) => 'Price answer' === $chunk['title'] ) );
			$this->assertCount( 1, $answer );
			$this->assertNull( $answer[0]['score'] );
			$this->assertGreaterThan( 0, $answer[0]['lexical'] );
		}

		$context = API::instance()->search_knowledge_base( [], 0.25, 100, 'silextra' );
		$this->assertStringNotContainsString( '73 euros', $context );
		$chunks = API::instance()->get_last_retrieval()['chunks'];
		$answer = array_values( array_filter( $chunks, fn( $chunk ) => 'Price answer' === $chunk['title'] ) );
		$this->assertCount( 1, $answer );
		$this->assertFalse( $answer[0]['included'] );
	}

	/**
	 * Case does not matter to the keyword stage: `morpheus8` finds `Morpheus8`.
	 */
	public function test_keyword_match_ignores_case() {
		$this->seed_text( 'Treatments', 'Morpheus8 is a fractional skin treatment.', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'morpheus8' );

		$this->assertStringContainsString( 'Morpheus8 is a fractional', $context );
	}

	/**
	 * With equal vector standing, the chunk whose title carries the term
	 * outranks the one that only mentions it in the body.
	 */
	public function test_title_match_outranks_a_body_match() {
		$this->seed_text( 'Studio news', 'We now offer Morpheus8 alongside our other treatments.', [ 1.0, 0.0, 0.0 ] );
		$this->seed_text( 'Morpheus8', 'A fractional radiofrequency skin treatment.', [ 1.0, 0.0, 0.0 ] );
		$this->commit_seeds();

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'morpheus8' );

		$this->assertLessThan( strpos( $context, 'Studio news' ), strpos( $context, 'Morpheus8 - ' ) );
	}

	/**
	 * Scripts without word boundaries match as substrings, since the index
	 * tokenizes a run of them as one word.
	 */
	public function test_no_space_script_matches_as_a_substring() {
		$this->seed_text( 'お知らせ', '営業時間は月曜から金曜の9時から17時です。', [ 0.0, 1.0, 0.0 ] );
		$this->seed_text( 'เวลาทำการ', 'ร้านเปิดวันจันทร์ถึงวันศุกร์ เวลา 9 โมงถึง 5 โมงเย็น', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		$japanese = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, '営業時間' );
		$thai     = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'เวลาทำการ' );

		$this->assertStringContainsString( '営業時間は月曜', $japanese );
		$this->assertStringContainsString( 'ร้านเปิดวันจันทร์', $thai );
	}

	/**
	 * Combining marks belong to their word, including Bengali vowel signs
	 * and viramas; they must not leave the exact question with no terms.
	 */
	public function test_query_keeps_combining_marks() {
		$this->assertSame( [ 'মূল্য' ], DB_Table::query_terms( 'মূল্য কত' ) );
		$this->assertSame( [ 'মূল্য' ], DB_Table::query_segments( 'মূল্য কত' ) );
		$this->assertContains( 'कीमत', DB_Table::query_segments( 'कीमत क्या है?' ) );
		$this->assertContains( "cafe\u{0301}", DB_Table::query_segments( "cafe\u{0301}" ) );
		$this->assertSame( [], DB_Table::query_segments( "\u{0301} !" ) );
	}

	/**
	 * Adding marked words must preserve the script runs around digits.
	 */
	public function test_mixed_thai_digit_query_keeps_script_segments() {
		$this->assertSame( [ 'เปิด', 'โมง', 'เปิด9โมง' ], DB_Table::query_segments( 'เปิด9โมง' ) );
		$this->seed_text( 'Hours', 'ร้านเปิดวันจันทร์ถึงวันศุกร์ เวลา 9 โมงถึง 5 โมงเย็น', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		$context = API::instance()->search_knowledge_base( [], 0.25, 2000, 'เปิด9โมง' );
		$this->assertStringContainsString( 'ร้านเปิดวันจันทร์', $context );
	}

	/**
	 * A long unmarked word must not hide a later marked word.
	 */
	public function test_long_unmarked_query_keeps_later_marked_word() {
		$this->assertSame( [ 'মূল্য' ], DB_Table::query_segments( str_repeat( 'a', 20000 ) . ' মূল্য' ) );
	}

	/**
	 * Decomposed input (`cafe` + U+0301) must still reach precomposed
	 * content: the mark-free form of a Latin word goes to the index too,
	 * after the whole words so it never displaces one. Other scripts keep
	 * their marks.
	 */
	public function test_decomposed_accent_matches_precomposed_content() {
		$this->assertSame( [ "cafe\u{0301}", 'cafe' ], DB_Table::query_terms( "cafe\u{0301}" ) );
		$this->assertSame( [ "mo\u{0323}t", "cu\u{031B}\u{0309}a", 'mot', 'cua' ], DB_Table::query_terms( "mo\u{0323}t cu\u{031B}\u{0309}a" ) );
		$this->assertSame( [ 'เปิด9โมง' ], DB_Table::query_terms( 'เปิด9โมง' ) );
		$this->assertSame( [ "ne\u{0301}" ], DB_Table::query_terms( "ne\u{0301}" ) );

		$words = array_map( fn( $i ) => "wo\u{0301}rd{$i}", range( 1, 9 ) );
		$terms = DB_Table::query_terms( implode( ' ', $words ) );
		$this->assertSame( $words, array_slice( $terms, 0, 9 ) );
		$this->assertCount( 10, $terms );

		$this->seed_text( 'Café menu prices', 'Espresso is 3 dollars.', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		$rows = $this->table->lexical_search( "cafe\u{0301}" );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Café menu prices', $rows[0]->post_title );
	}

	/**
	 * FULLTEXT can split at combining marks too: the substring pass must
	 * find the whole Bengali word, both below the floor and without vectors.
	 */
	public function test_bengali_exact_query_retrieves_matching_chunk() {
		$this->seed_text( 'Welcome', 'Welcome to our shop.', [ 1.0, 0.0, 0.0 ] );
		$this->seed_text( 'Prices', 'মূল্য কত? মূল্য ৭৩ টাকা।', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		foreach ( [ [ 1.0, 0.0, 0.0 ], [] ] as $vector ) {
			$context = API::instance()->search_knowledge_base( $vector, 0.25, 2000, 'মূল্য কত' );
			$this->assertStringContainsString( 'মূল্য ৭৩ টাকা', $context );
			$chunks = API::instance()->get_last_retrieval()['chunks'];
			$price  = array_values( array_filter( $chunks, fn( $chunk ) => 'Prices' === $chunk['title'] ) );
			$this->assertNull( $price[0]['score'] );
			$this->assertGreaterThan( 0, $price[0]['lexical'] );
		}
	}

	/**
	 * Two-character Arabic, Hebrew and Hangul words never reach the
	 * FULLTEXT index, so a short verbatim question (`سن من`) must match as
	 * substrings; Latin and Cyrillic two-letter words stay out.
	 */
	public function test_short_abjad_and_hangul_words_match_as_substrings() {
		$this->assertSame( [ 'سن', 'من' ], DB_Table::query_short_words( 'سن من' ) );
		$this->assertSame( [], DB_Table::query_segments( 'سن من' ) );
		$this->assertSame( [ '나이' ], DB_Table::query_short_words( '나이' ) );
		$this->assertSame( [ '내', '집' ], DB_Table::query_short_words( '내 집' ) );
		$this->assertSame( [], DB_Table::query_short_words( '한국어' ) );
		$this->assertSame( [], DB_Table::query_short_words( 'ㅋ ㅋㅋ' ) );
		$this->assertSame( [], DB_Table::query_short_words( 'ᄀ ᄀᄂ' ) );
		$this->assertSame( [], DB_Table::query_short_words( '가ㅋ 가ᄀ ᄀ가 ㅋ가' ) );
		$this->assertSame( [], DB_Table::query_short_words( 'is ok' ) );
		$this->assertSame( [], DB_Table::query_short_words( 'как он' ) );
		$this->assertSame( [], DB_Table::query_short_words( 'و ممنون' ) );
		$this->assertSame( [], DB_Table::query_short_words( '۱۰ روز' ) );
		$this->assertSame( [ 'من' ], DB_Table::query_short_words( 'من من' ) );
		$this->assertSame( [ 'سن', 'من', 'در', 'به', 'از' ], DB_Table::query_short_words( 'سن من در به از تا' ) );

		$this->seed_text( 'QA welcome', 'Welcome to our shop.', [ 1.0, 0.0, 0.0 ] );
		$this->seed_text( 'QA Persian thanks', 'ممنون از شما', [ 0.0, 0.0, 1.0 ] );
		$this->seed_text( 'QA Persian short', 'سن من سی سال است', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		// `ممنون` contains `من` and ranks below the chunk matching both words.
		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'سن من' );
		$this->assertStringContainsString( 'سن من سی سال است', $context );
		$this->assertStringContainsString( 'ممنون', $context );
		$this->assertLessThan( strpos( $context, 'ممنون' ), strpos( $context, 'سن من سی سال است' ) );
	}

	/**
	 * Single Hangul syllables are whole words (`내 집`, "my house"); the
	 * chunk holding both must win over one that only contains `집` inside
	 * another word.
	 */
	public function test_single_syllable_korean_words_match_as_substrings() {
		$this->seed_text( 'Artiswar welcome', 'Welcome to the guild.', [ 1.0, 0.0, 0.0 ] );
		$this->seed_text( 'QA Korean rent', '집세는 매달 납부합니다.', [ 0.0, 0.0, 1.0 ] );
		$this->seed_text( 'QA Korean', '내 집 정보 안내.', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, '내 집' );
		$this->assertStringContainsString( '내 집 정보 안내', $context );
		$this->assertStringContainsString( '집세는', $context );
		$this->assertLessThan( strpos( $context, '집세는' ), strpos( $context, '내 집 정보 안내' ) );
	}

	/**
	 * Index scores scale with the corpus (a term scores ~0.09 among two
	 * rows, ~9 among a thousand) and a substring hit does not, so a
	 * two-letter preposition (`در`) must stay out whenever a word reaches
	 * the index, or it evicts the chunk carrying the indexed term.
	 */
	public function test_short_word_stays_out_when_a_term_reaches_the_index() {
		$this->seed_text( 'درباره ما', 'ما در تهران هستیم.', [ 1.0, 0.0, 0.0 ] );
		$this->seed_text( 'Shipping', 'ارسال به سراسر ایران انجام می‌شود.', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		$rows = $this->table->lexical_search( 'ارسال در ایران' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Shipping', $rows[0]->post_title );

		$context = API::instance()->search_knowledge_base( [], 0.25, 2000, 'ارسال در ایران' );
		$this->assertStringContainsString( 'ارسال به سراسر ایران', $context );
	}

	/**
	 * Without a vector at all the keyword stage still builds a context, so
	 * an embeddings outage degrades the turn instead of failing it.
	 */
	public function test_keyword_only_retrieval_without_a_vector() {
		$this->seed_text( 'Returns', 'You can return any item within 30 days of delivery.', [ 1.0, 0.0, 0.0 ] );
		$this->commit_seeds();

		$context = API::instance()->search_knowledge_base( [], 0.25, 2000, 'return within 30 days' );

		$this->assertStringContainsString( 'return any item', $context );
	}

	/**
	 * A failed embeddings call no longer fails the chat turn: retrieval runs
	 * keyword-only and the turn records why.
	 *
	 * @dataProvider embedding_failure_queries
	 *
	 * @param string $message Visitor's question.
	 * @param string $content Matching chunk content.
	 */
	public function test_embedding_failure_degrades_to_keyword_retrieval( string $message, string $content ) {
		update_option(
			'hyve_settings',
			[
				'ai_mode' => \ThemeIsle\HyveLite\Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			] 
		);

		$instance = new ReflectionProperty( \ThemeIsle\HyveLite\OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$this->seed_text( 'Answer', $content, [ 1.0, 0.0, 0.0 ] );
		$this->seed_text( 'Overview', str_repeat( $message . ' ', 30 ), [ 0.0, 1.0, 0.0 ] );
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
					return new WP_Error( 'http_request_failed', 'Embeddings are down.' );
				}

				if ( false !== strpos( $url, '/responses' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'id' => 'resp_test' ] ),
					];
				}

				return new WP_Error( 'unexpected_request', 'Unfaked outbound request: ' . $url );
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$request->set_param( 'message', $message );
		$request->set_param( 'thread_id', 'conv_local' );

		$response = API::instance()->send_chat( $request )->get_data();

		$this->assertArrayNotHasKey( 'error', $response );
		$this->assertStringContainsString( $content, $response['content'] );

		$debug = new ReflectionProperty( API::class, 'chat_debug' );
		$debug->setAccessible( true );
		$this->assertTrue( $debug->getValue( API::instance() )['embedding_failed'] );
	}

	/**
	 * Questions whose answer must survive an embeddings outage.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function embedding_failure_queries(): array {
		return [
			'english' => [ 'Can I return an item?', 'You can return any item within 30 days of delivery.' ],
			'bengali' => [ 'মূল্য কত', 'মূল্য কত? মূল্য ৭৩ টাকা।' ],
		];
	}

	/**
	 * With Qdrant holding the vectors, the keyword stage still runs on the
	 * local table and its hits join the vector hits.
	 */
	public function test_qdrant_path_fuses_local_keyword_hits() {
		update_option( 'hyve_qdrant_status', 'active' );

		$this->seed_text( 'Clan guide', 'ArtIsWar - cercato anche come Art Is War, ArtisWar o artiswar.', [ 0.0, 1.0, 0.0 ], 100, 4242 );
		$this->commit_seeds();

		$this->set_qdrant_instance(
			new class() extends \ThemeIsle\HyveLite\Qdrant_API {
				/**
				 * One vector hit, as Qdrant would return it.
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
							'post_content' => 'Welcome to our gaming community site.',
							'token_count'  => 10,
							'score'        => 0.9,
						],
					];
				}
			}
		);

		$context = API::instance()->search_knowledge_base( [ 1.0, 0.0, 0.0 ], 0.25, 2000, 'artiswar' );

		$this->assertStringContainsString( 'Welcome to our gaming', $context );
		$this->assertStringContainsString( 'ArtIsWar - cercato', $context );
		// Vector sources first, then the posts only the keyword pass found.
		$this->assertSame( [ 7, 4242 ], array_map( 'intval', API::instance()->get_last_retrieval()['sources'] ) );
	}

	/**
	 * The test-retrieval ability runs the same keyword pass as chat, and
	 * keeps going on it when the embeddings call fails.
	 */
	public function test_retrieval_ability_includes_keyword_only_chunks() {
		update_option(
			'hyve_settings',
			[
				'ai_mode' => \ThemeIsle\HyveLite\Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			]
		);

		$instance = new ReflectionProperty( \ThemeIsle\HyveLite\OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$this->seed_text( 'Clan guide', 'ArtIsWar - cercato anche come artiswar.', [ 0.0, 1.0, 0.0 ], 100, 4242 );
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

				return new WP_Error( 'http_request_failed', 'Embeddings are down.' );
			},
			10,
			3
		);

		$result = ( new \ThemeIsle\HyveLite\Abilities() )->test_retrieval( [ 'query' => 'artiswar' ] );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['embedding_failed'] );
		$this->assertSame( [ 4242 ], array_column( $result['sources'], 'id' ) );
		$this->assertCount( 1, $result['chunks'] );
		$this->assertSame( 4242, $result['chunks'][0]['source_id'] );
		$this->assertSame( 0.0, $result['chunks'][0]['score'] );
		$this->assertTrue( $result['chunks'][0]['included'] );
	}

	/**
	 * The `hyve_hybrid_retrieval` filter merges over the defaults, and its
	 * `candidates` cap limits each list.
	 */
	public function test_hybrid_settings_filter_caps_each_list() {
		$this->seed_text( 'Returns policy', 'Returns are accepted within 30 days with a receipt.', [ 0.0, 1.0, 0.0 ] );
		$this->seed_text( 'Returns note', 'Mentions returns once.', [ 0.0, 0.0, 1.0 ] );
		$this->commit_seeds();

		add_filter( 'hyve_hybrid_retrieval', fn() => [ 'candidates' => 1 ] );

		$this->assertSame( 60, API::hybrid_settings()['rrf_k'] );

		$context = API::instance()->search_knowledge_base( [], 0.25, 2000, 'returns receipt' );

		$this->assertStringContainsString( 'accepted within 30 days', $context );
		$this->assertStringNotContainsString( 'Mentions returns once', $context );
	}

	/**
	 * The keyword pass reads a capped number of terms, so the current
	 * question must come before the blended history or a long thread crowds
	 * its words out.
	 */
	public function test_keyword_pass_keeps_the_current_question_after_a_long_history() {
		update_option(
			'hyve_settings',
			[
				'ai_mode' => \ThemeIsle\HyveLite\Hyve_Connect::MODE_SELF,
				'api_key' => 'sk-test',
			]
		);

		$instance = new ReflectionProperty( \ThemeIsle\HyveLite\OpenAI::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );

		$this->seed_text( 'Treatments', 'We offer Morpheus8 skin tightening on Fridays.', [ 0.0, 1.0, 0.0 ] );
		$this->commit_seeds();

		$thread = self::factory()->post->create( [ 'post_type' => 'hyve_threads' ] );
		update_post_meta(
			$thread,
			'_hyve_thread_data',
			[
				[
					'sender'  => 'user',
					'message' => 'Hi there, I would like to know about your opening hours',
				],
				[
					'sender'  => 'bot',
					'message' => 'We are open every weekday.',
				],
				[
					'sender'  => 'user',
					'message' => 'Thanks. Also what payment methods do you accept today',
				],
				[
					'sender'  => 'bot',
					'message' => 'Cards and cash.',
				],
			]
		);

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
					return new WP_Error( 'http_request_failed', 'Embeddings are down.' );
				}

				if ( false !== strpos( $url, '/responses' ) ) {
					return [
						'response' => [ 'code' => 200 ],
						'body'     => wp_json_encode( [ 'id' => 'resp_test' ] ),
					];
				}

				return new WP_Error( 'unexpected_request', 'Unfaked outbound request: ' . $url );
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/hyve/v1/chat' );
		$request->set_param( 'message', 'Do you offer Morpheus8?' );
		$request->set_param( 'record_id', $thread );
		$request->set_param( 'thread_id', 'conv_local' );

		$response = API::instance()->send_chat( $request )->get_data();

		$this->assertArrayNotHasKey( 'error', $response );
		$this->assertStringContainsString( 'Morpheus8 skin tightening', $response['content'] );
	}
}
