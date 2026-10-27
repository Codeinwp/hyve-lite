<?php
/**
 * Test_Tokenizer class.
 * 
 * @package Codeinwp/HyveLite
 */

use guttedgarden\Tiktoken\EncoderProvider;
use guttedgarden\Tiktoken\Vocab\Vocab;
use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Tokenizer;
use ThemeIsle\HyveLite\Vocab_Loader;

/**
 * Class Test_Tokenizer.
 */
class Test_Tokenizer extends WP_UnitTestCase {
	/**
	 * Test tokenize method with short content.
	 */
	public function testTokenizeShortContent() {
		$post = [
			'ID'      => 1,
			'title'   => 'Test Title',
			'content' => 'This is a short content.',
		];

		$result = Tokenizer::tokenize( $post );

		$this->assertCount( 1, $result );
		$this->assertEquals( $post['ID'], $result[0]['post_id'] );
		$this->assertEquals( $post['title'], $result[0]['post_title'] );
		$this->assertEquals( $post['content'], $result[0]['post_content'] );
		$this->assertArrayHasKey( 'tokens', $result[0] );
		$this->assertArrayHasKey( 'token_count', $result[0] );
	}

	/**
	 * Test tokenize method with long content.
	 */
	public function testTokenizeLongContent() {
		$post = [
			'ID'      => 1,
			'title'   => 'Test Title',
			'content' => str_repeat( 'This is a long content. ', 1000 ),
		];

		$result = Tokenizer::tokenize( $post );

		$this->assertGreaterThan( 1, count( $result ) );
		foreach ( $result as $chunk ) {
			$this->assertEquals( $post['ID'], $chunk['post_id'] );
			$this->assertEquals( $post['title'], $chunk['post_title'] );
			$this->assertArrayHasKey( 'tokens', $chunk );
			$this->assertArrayHasKey( 'token_count', $chunk );
		}
	}

	/**
	 * Table cells keep their label ("Laundry | $32.00/hr") instead of fusing
	 * into "Laundry$32.00/hr", and rows stay on separate lines.
	 */
	public function test_html_to_text_preserves_table_structure() {
		$html = '<figure class="wp-block-table"><table><thead><tr><th>Service</th><th>Rate</th></tr></thead>' .
			'<tbody><tr><td>Laundry</td><td>$32.00/hr</td></tr><tr><td>Ironing</td><td>$28.00/hr</td></tr></tbody></table></figure>';

		$text = Tokenizer::html_to_text( $html );

		$this->assertStringContainsString( 'Service | Rate', $text );
		$this->assertStringContainsString( 'Laundry | $32.00/hr', $text );
		$this->assertStringContainsString( "Laundry | \$32.00/hr\nIroning", $text );
		$this->assertStringNotContainsString( 'Laundry$32', $text );
	}

	/**
	 * Headings do not fuse with the paragraph below, and entities decode so
	 * the stored text matches what a visitor would type.
	 */
	public function test_html_to_text_separates_blocks_and_decodes_entities() {
		$html = '<h2>Our Services</h2><p>Care O&#8217;Clock offers home care &amp; support.</p>';

		$text = Tokenizer::html_to_text( $html );

		$this->assertSame( "Our Services\nCare O’Clock offers home care & support.", $text );
	}

	/**
	 * A table larger than the chunk budget has no ". " sentence boundaries; it
	 * used to be dropped entirely, leaving the content unsearchable. It must
	 * chunk instead, with every row surviving somewhere in the output.
	 */
	public function test_tokenize_keeps_oversized_sentence_less_content() {
		$rows = '';

		for ( $i = 1; $i <= 400; $i++ ) {
			$rows .= sprintf( '<tr><td>Specialized long-running service number %d</td><td>$%d.00/hr</td></tr>', $i, $i );
		}

		$post = [
			'ID'      => 1,
			'title'   => 'Services & Rates',
			'content' => '<table><tbody>' . $rows . '</tbody></table>',
		];

		$result = Tokenizer::tokenize( $post );

		$this->assertGreaterThan( 1, count( $result ) );

		$all_text = implode( "\n", array_column( $result, 'post_content' ) );

		$this->assertStringContainsString( 'service number 1 | $1.00/hr', $all_text );
		$this->assertStringContainsString( 'service number 400 | $400.00/hr', $all_text );

		foreach ( $result as $chunk ) {
			$this->assertLessThanOrEqual( 1100, $chunk['token_count'] );
		}
	}

	/**
	 * A single boundary-less blob larger than the chunk size is hard-split by
	 * tokens, not discarded.
	 */
	public function test_create_chunks_hard_splits_oversized_segment() {
		$blob = 'START' . str_repeat( 'x7f9q2 ', 2000 ) . 'END';

		$chunks = Tokenizer::create_chunks( $blob, 100 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertStringContainsString( 'START', $chunks[0] );
		$this->assertStringContainsString( 'END', end( $chunks ) );
	}

	/**
	 * Sentences keep their own punctuation: no doubled periods, no invented
	 * separators.
	 */
	public function test_create_chunks_keeps_punctuation() {
		$chunks = Tokenizer::create_chunks( 'First sentence. Second one! Third?', 1000 );

		$this->assertCount( 1, $chunks );
		$this->assertSame( "First sentence.\nSecond one!\nThird?", $chunks[0] );
	}

	/**
	 * On one hosted practice 304 of 358 chunks were mostly `[vc_row]`
	 * attributes rather than text. The wrapped text survives; the markers go.
	 */
	public function test_html_to_text_strips_leftover_shortcodes(): void {
		$builder = '[vc_row css="%7B%22default%22%3A%7B%22margin-top%22%3A%2230px%22%7D%7D"][vc_column width="1/2"]' .
			'[us_iconbox icon="fa-clock" title="Hours"]Open Monday to Friday, 8am to 6pm.[/us_iconbox][/vc_column][/vc_row]';

		$text = Tokenizer::html_to_text( $builder );

		$this->assertSame( 'Open Monday to Friday, 8am to 6pm.', $text );
		$this->assertStringNotContainsString( '%22', $text );
	}

	/**
	 * `the_content` texturizes attribute quotes into curly ones before the
	 * content reaches here, so the stripper has to match those too.
	 */
	public function test_html_to_text_strips_shortcodes_with_curly_quotes(): void {
		$text = Tokenizer::html_to_text( '[vc_row css=”%7B%22a%22%3A1%7D”]Rates from $9.[/vc_row]' );

		$this->assertSame( 'Rates from $9.', $text );
	}

	/**
	 * A document that is only a shortcode renders to nothing, which is what
	 * lets ingest refuse it instead of storing an 18-character chunk that
	 * outranks real pages.
	 */
	public function test_html_to_text_empties_a_shortcode_only_document(): void {
		$this->assertSame( '', Tokenizer::html_to_text( '[woocommerce_checkout]' ) );
		$this->assertSame( '', Tokenizer::html_to_text( '[ihc-logout-link]' ) );
	}

	/**
	 * Square brackets in prose are not shortcodes: citations, numeric ranges and
	 * bracketed asides have to survive the markup being stripped around them.
	 * Opening hours are the case that matters, being exactly what visitors ask
	 * for.
	 *
	 * @dataProvider provide_bracketed_prose
	 *
	 * @param string $prose The text to keep.
	 */
	public function test_html_to_text_keeps_bracketed_prose( string $prose ): void {
		$wrapped = '[vc_column_text css="%7B%22a%22%3A1%7D"]' . $prose . '[/vc_column_text]';

		$this->assertSame( $prose, Tokenizer::html_to_text( $wrapped ) );
	}

	/**
	 * Bracketed asides that carry no `name="value"` attribute.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function provide_bracketed_prose(): array {
		return [
			'footnotes'       => [ 'See footnote [1] and reference [23]. The study [Note: revised in 2024] priced it at [100] to [200].' ],
			'opening hours'   => [ 'Hours: [Open 9am to 6pm] daily.' ],
			'clinic hours'    => [ 'Clinic [Mon-Fri 9-5] only.' ],
			'citation needed' => [ 'The rate rose [citation needed] last year.' ],
			'cross reference' => [ 'Prices vary [see below] by region.' ],
			'dated aside'     => [ 'The fee is $40 [as of 2024] per visit.' ],
			'markdown link'   => [ 'Read [the docs](https://example.test/guide) first.' ],
			'unclosed tag'    => [ 'Opening hours [see the notes below and ask at the desk.' ],
		];
	}

	/**
	 * An unclosed `[tag ` used to run to whatever bracket came next, taking the
	 * page with it. It carries no `name="value"` attribute, so it is no longer
	 * read as a tag at all and the text between survives.
	 */
	public function test_html_to_text_does_not_swallow_the_page_after_an_unclosed_tag(): void {
		$text = 'Opening hours [see the notes below ' . str_repeat( 'We are open on weekdays. ', 400 ) . '[gallery ids="1,2"] The end.';

		$result = Tokenizer::html_to_text( $text );

		$this->assertStringContainsString( 'Opening hours [see the notes below', $result );
		$this->assertStringContainsString( 'We are open on weekdays.', $result );
		$this->assertStringContainsString( 'The end.', $result );
		$this->assertStringNotContainsString( '[gallery', $result );
	}

	/**
	 * A contact page often keeps its phone number and email only as link
	 * targets. Those are the facts visitors ask for, so they are folded into
	 * the text instead of being lost with the tag.
	 */
	public function test_html_to_text_keeps_tel_and_mailto_targets(): void {
		$html = '<p><a href="tel:+441274998877">Call us</a></p>' .
			'<p><a href="mailto:hello@example.com?subject=Appointment"><i class="fa fa-envelope"></i> Email the clinic</a></p>';

		$this->assertSame(
			"Call us (+441274998877)\nEmail the clinic (hello@example.com)",
			Tokenizer::html_to_text( $html )
		);
	}

	/**
	 * Targets arrive URL-encoded, entity-encoded, single-quoted or unquoted,
	 * and an icon-only link has no text to attach the target to.
	 *
	 * @dataProvider provide_contact_link_forms
	 *
	 * @param string $html     The link markup.
	 * @param string $expected The stored text.
	 */
	public function test_html_to_text_reads_every_contact_link_form( string $html, string $expected ): void {
		$this->assertSame( $expected, Tokenizer::html_to_text( $html ) );
	}

	/**
	 * Link markup variants and the text each should store.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function provide_contact_link_forms(): array {
		return [
			'url-encoded plus'  => [ '<a href="tel:%2B441274998877">Call</a>', 'Call (+441274998877)' ],
			'entity-encoded at' => [ '<a href="mailto:hello&#64;example.com">Write</a>', 'Write (hello@example.com)' ],
			'single quotes'     => [ "<a class='btn' href='tel:0800123456' target='_blank'>Freephone</a>", 'Freephone (0800123456)' ],
			'unquoted'          => [ '<a href=mailto:hello@example.com>Write</a>', 'Write (hello@example.com)' ],
			'upper-case scheme' => [ '<a href="TEL:+441274998877">Call</a>', 'Call (+441274998877)' ],
			'icon only'         => [ '<a href="tel:+441274998877"><span class="icon"></span></a>', '+441274998877' ],
			'empty target'      => [ '<a href="tel:">Call</a>', 'Call' ],
			'digitless target'  => [ '<a href="tel:+">Call</a><a href="tel:#">Now</a>', 'CallNow' ],
			'nested tags'       => [ '<a href="mailto:a@b.co"><strong>Email</strong> <em>us</em></a>', 'Email us (a@b.co)' ],
			'extension'         => [ '<a href="tel:+441274998877;ext=12;phone-context=example.com">Call</a>', 'Call (+441274998877 ext. 12)' ],
			'block in anchor'   => [ '<a href="tel:+441274998877"><div class="card"><h3>Call us</h3></div></a>', 'Call us (+441274998877)' ],
			'button in anchor'  => [ '<p><a href="mailto:a@b.co"><span class="btn">Email</span></a></p><p>Next</p>', "Email (a@b.co)\nNext" ],
		];
	}

	/**
	 * Anchor text that already shows the number or address is not doubled,
	 * even when the visible number is in national format and the target in
	 * international format.
	 *
	 * @dataProvider provide_links_showing_their_target
	 *
	 * @param string $html The link markup.
	 * @param string $text The anchor text, which is all that should be stored.
	 */
	public function test_html_to_text_does_not_repeat_a_shown_target( string $html, string $text ): void {
		$this->assertSame( $text, Tokenizer::html_to_text( $html ) );
	}

	/**
	 * Links whose text already carries the target.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function provide_links_showing_their_target(): array {
		return [
			'same number'       => [ '<a href="tel:+441274998877">+441274998877</a>', '+441274998877' ],
			'spaced number'     => [ '<a href="tel:+441274998877">+44 1274 998877</a>', '+44 1274 998877' ],
			'national format'   => [ '<a href="tel:+441274998877">01274 998877</a>', '01274 998877' ],
			'trunk in brackets' => [ '<a href="tel:+441274998877">+44 (0)1274 998877</a>', '+44 (0)1274 998877' ],
			'number in prose'   => [ '<a href="tel:+441274998877">Call 01274 998877 today</a>', 'Call 01274 998877 today' ],
			'other digits too'  => [ '<a href="tel:+441274998877">24/7 line 01274 998877 (Mon-Fri 9-5)</a>', '24/7 line 01274 998877 (Mon-Fri 9-5)' ],
			'shown extension'   => [ '<a href="tel:+441274998877;ext=12">01274 998877</a>', '01274 998877' ],
			'same email'        => [ '<a href="mailto:hello@example.com">hello@example.com</a>', 'hello@example.com' ],
			'email case'        => [ '<a href="mailto:Hello@Example.com">hello@example.com</a>', 'hello@example.com' ],
			'vanity number'     => [ '<a href="tel:1-800-FLOWERS">1-800-FLOWERS</a>', '1-800-FLOWERS' ],
		];
	}

	/**
	 * A different number in the text is not the linked one.
	 */
	public function test_html_to_text_adds_a_number_the_text_does_not_show(): void {
		$this->assertSame(
			'Call 0800 123 4567 (+441274998877)',
			Tokenizer::html_to_text( '<a href="tel:+441274998877">Call 0800 123 4567</a>' )
		);
	}

	/**
	 * A number shown with its own country code is a different number unless
	 * it matches in full: "+1 274 998877" does not stand in for a UK target
	 * just because the last digits agree.
	 */
	public function test_html_to_text_does_not_take_a_foreign_number_for_the_target(): void {
		$this->assertSame(
			'+1 274 998877 (+441274998877)',
			Tokenizer::html_to_text( '<a href="tel:+441274998877">+1 274 998877</a>' )
		);
	}

	/**
	 * Navigation, headers and footers are full of ordinary links. Their
	 * targets stay out of the text, or every chunk would carry the menu's URLs.
	 */
	public function test_html_to_text_leaves_ordinary_link_targets_out(): void {
		$html = '<nav><ul><li><a href="https://example.test/about">About</a></li>' .
			'<li><a href="/contact" data-href="tel:+441274998877">Contact</a></li>' .
			'<li><a href="#top">Top</a></li></ul></nav>' .
			'<p><a href="https://maps.example.test/?q=clinic">Find us</a></p>';

		$text = Tokenizer::html_to_text( $html );

		$this->assertSame( "About\nContact\nTop\nFind us", $text );
	}

	/**
	 * An unclosed contact link must not stall the extractor or take the rest of
	 * the page with it.
	 */
	public function test_html_to_text_survives_an_unclosed_contact_link(): void {
		$html = '<p><a href="tel:+441274998877">Call us</p>' . str_repeat( '<p>We are open on weekdays.</p>', 2000 ) . '<p>The end.</p>';

		$text = Tokenizer::html_to_text( $html );

		$this->assertStringStartsWith( 'Call us', $text );
		$this->assertStringEndsWith( 'The end.', $text );
	}

	/**
	 * An unclosed contact link must not borrow a later link's `</a>`: the
	 * number would land on an unrelated label, possibly in another chunk.
	 */
	public function test_html_to_text_does_not_attach_an_unclosed_link_to_a_later_one(): void {
		$html = '<p><a href="tel:+441274998877">Call us</p><p>Open daily.</p><p><a href="/about">About</a></p>';

		$text = Tokenizer::html_to_text( $html );

		$this->assertStringNotContainsString( 'About (+441274998877)', $text );
		$this->assertSame( "Call us\nOpen daily.\nAbout", $text );
	}

	/**
	 * A page of unclosed contact links is read in one pass, not one rescan to
	 * the end of the page per link.
	 */
	public function test_html_to_text_reads_many_unclosed_contact_links_in_linear_time(): void {
		$html = str_repeat( '<a href="tel:+441274998877">Call ', 3000 ) . str_repeat( '<p>We are open on weekdays.</p>', 5000 );

		$start = microtime( true );
		$text  = Tokenizer::html_to_text( $html );

		$this->assertLessThan( 0.5, microtime( true ) - $start );
		$this->assertStringEndsWith( 'We are open on weekdays.', $text );
	}

	/**
	 * The sha256 the library expects for cl100k_base.
	 */
	const CL100K_HASH = '223921b76ee99bde995b7ff738513eef100fb51d18c93597a113bcffe865b2a7';

	/**
	 * The URI the library fetches cl100k_base from.
	 */
	const CL100K_URI = 'https://openaipublic.blob.core.windows.net/encodings/cl100k_base.tiktoken';

	/**
	 * Drop the shared encoder and any HTTP fake a test left behind.
	 */
	public function tear_down() {
		$this->set_provider( null );
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * Answer the vocabulary download with a fixed response.
	 *
	 * @param array<string, mixed>|WP_Error $response What wp_remote_get() returns.
	 *
	 * @return void
	 */
	private function fake_download( $response ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, array $args ) use ( $response ) {
				// A streamed request lands in the file, as the HTTP API would.
				if ( is_array( $response ) && ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $response['body'] ?? '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
				}

				return $response;
			},
			10,
			2
		);
	}

	/**
	 * The real vocabulary bytes, from the cache the default loader keeps.
	 *
	 * @return string
	 */
	private function vocab_body(): string {
		( new Vocab_Loader() )->load( self::CL100K_URI, self::CL100K_HASH );

		return (string) file_get_contents( get_temp_dir() . sha1( self::CL100K_URI ) ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
	}

	/**
	 * A fresh, writable cache directory under the temp directory.
	 *
	 * @return string
	 */
	private function cache_dir(): string {
		$dir = get_temp_dir() . 'hyve-vocab-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );

		return $dir;
	}

	/**
	 * Replace the encoder provider the tokenizer shares across calls.
	 *
	 * @param EncoderProvider|null $provider The provider, or null to rebuild on next use.
	 *
	 * @return void
	 */
	private function set_provider( ?EncoderProvider $provider ): void {
		$values = [
			'provider' => $provider,
			'failure'  => null,
		];

		foreach ( $values as $name => $value ) {
			$property = new ReflectionProperty( Tokenizer::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * The vocabulary is fetched through the HTTP API and cached under the
	 * library's own file name, so a cache the library wrote is reused and one
	 * this loader writes serves the library too.
	 */
	public function test_vocabulary_is_downloaded_with_the_http_api_and_cached(): void {
		$vocab = ( new Vocab_Loader() )->load( self::CL100K_URI, self::CL100K_HASH );

		$this->assertInstanceOf( Vocab::class, $vocab );
		$this->assertSame( self::CL100K_HASH, hash_file( 'sha256', get_temp_dir() . sha1( self::CL100K_URI ) ) );

		// Cached now, so a dead network no longer matters.
		$this->fake_download( new WP_Error( 'http_request_failed', 'No route to host' ) );

		$this->assertInstanceOf( Vocab::class, ( new Vocab_Loader() )->load( self::CL100K_URI, self::CL100K_HASH ) );
	}

	/**
	 * With nowhere to cache, the vocabulary is buffered and parsed from memory.
	 */
	public function test_vocabulary_is_parsed_from_memory_when_nothing_is_writable(): void {
		$this->fake_download(
			[
				'response' => [ 'code' => 200 ],
				'body'     => $this->vocab_body(),
			]
		);

		$vocab = ( new Vocab_Loader( [ __FILE__ . '/nope' ] ) )->load( self::CL100K_URI, self::CL100K_HASH );

		$this->assertInstanceOf( Vocab::class, $vocab );
		$this->assertFileDoesNotExist( __FILE__ . '/nope/' . sha1( self::CL100K_URI ) );
	}

	/**
	 * A download that does not match the expected checksum is refused, whether
	 * it was streamed to a cache file or buffered in memory.
	 */
	public function test_a_download_with_the_wrong_checksum_is_refused(): void {
		$this->fake_download(
			[
				'response' => [ 'code' => 200 ],
				'body'     => 'not a vocabulary',
			]
		);

		foreach ( [ [ get_temp_dir() . 'hyve-vocab-test' ], [ __FILE__ . '/nope' ] ] as $dirs ) {
			try {
				( new Vocab_Loader( $dirs ) )->load( self::CL100K_URI, self::CL100K_HASH );
				$this->fail( 'A wrong checksum must be refused.' );
			} catch ( RuntimeException $e ) {
				$this->assertStringContainsString( 'Checksum failed', $e->getMessage() );
			}
		}

		$this->assertFileDoesNotExist( get_temp_dir() . 'hyve-vocab-test/' . sha1( self::CL100K_URI ) );
	}

	/**
	 * Ingest used to need `fopen()` on a remote URI and a writable temp
	 * directory (Codeinwp/hyve-lite#241). The temp directory is now optional.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_tokenizing_needs_no_vocabulary_cache(): void {
		define( 'WP_TEMP_DIR', __FILE__ . '/nope/' );

		$chunks = Tokenizer::tokenize(
			[
				'ID'      => 1,
				'title'   => 'Hours',
				'content' => 'Open Monday to Friday.',
			]
		);

		$this->assertSame( 'Open Monday to Friday.', $chunks[0]['post_content'] );
		$this->assertGreaterThan( 0, $chunks[0]['token_count'] );
	}

	/**
	 * A vocabulary that cannot be loaded is reported as an error, not an
	 * uncaught exception that crashes the request.
	 */
	public function test_a_failed_vocabulary_load_is_an_ingest_error_not_a_crash(): void {
		$this->fake_download( new WP_Error( 'http_request_failed', 'No route to host' ) );

		$provider = new EncoderProvider();
		$provider->setVocabLoader( new Vocab_Loader( [ __FILE__ . '/nope' ] ) );
		$this->set_provider( $provider );

		$post_id = $this->factory()->post->create( [ 'post_content' => 'Open Monday to Friday.' ] );

		$request = new WP_REST_Request( 'POST', '/hyve/v1/data' );
		$request->set_param( 'data', [ 'ID' => $post_id ] );
		$request->set_param( 'action', 'add' );

		$response = API::instance()->add_data( $request );

		$this->assertStringContainsString( 'could not be tokenized', $response->get_data()['error'] );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_added', true ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_post_processing', true ) );

		// The reason stays on the row after a reload, not only in the notice.
		$listing = new WP_REST_Request( 'GET', '/hyve/v1/data' );
		$listing->set_param( 'type', 'post' );
		$listing->set_param( 'offset', 0 );

		$rows = array_filter( API::instance()->get_data( $listing )->get_data()['posts'], fn( $row ) => $row['ID'] === $post_id );
		$row  = reset( $rows );

		$this->assertStringContainsString( 'could not be tokenized', $row['error'] );
		$this->assertStringContainsString( 'Fix the reported issue', $row['error'] );
	}

	/**
	 * A cache in a later directory, such as uploads after the temp directory
	 * was unusable, is read without a download.
	 */
	public function test_a_cache_in_a_later_directory_is_read(): void {
		$dir = $this->cache_dir();
		file_put_contents( $dir . '/' . sha1( self::CL100K_URI ), $this->vocab_body() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$this->fake_download( new WP_Error( 'http_request_failed', 'No route to host' ) );

		$vocab = ( new Vocab_Loader( [ __FILE__ . '/nope', $dir ] ) )->load( self::CL100K_URI, self::CL100K_HASH );

		$this->assertInstanceOf( Vocab::class, $vocab );
	}

	/**
	 * A cache file that no longer matches its checksum is replaced.
	 */
	public function test_a_stale_cache_file_is_replaced(): void {
		$dir  = $this->cache_dir();
		$file = $dir . '/' . sha1( self::CL100K_URI );
		file_put_contents( $file, 'not a vocabulary' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$this->fake_download(
			[
				'response' => [ 'code' => 200 ],
				'body'     => $this->vocab_body(),
			]
		);

		$vocab = ( new Vocab_Loader( [ $dir ] ) )->load( self::CL100K_URI, self::CL100K_HASH );

		$this->assertInstanceOf( Vocab::class, $vocab );
		$this->assertSame( self::CL100K_HASH, hash_file( 'sha256', $file ) );
	}

	/**
	 * A download answered with anything but 200 is refused, and the partial
	 * file it streamed into is removed.
	 */
	public function test_a_failed_streamed_download_leaves_no_partial_file(): void {
		$dir = $this->cache_dir();

		$this->fake_download(
			[
				'response' => [ 'code' => 404 ],
				'body'     => 'Not found',
			]
		);

		try {
			( new Vocab_Loader( [ $dir ] ) )->load( self::CL100K_URI, self::CL100K_HASH );
			$this->fail( 'A 404 must be refused.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'HTTP 404', $e->getMessage() );
		}

		$this->assertSame( [], glob( $dir . '/*' ) );
	}

	/**
	 * The update cron keeps a post flagged when the vocabulary cannot be
	 * loaded, so a transient failure is retried on the next run.
	 */
	public function test_the_update_cron_retries_a_post_the_vocabulary_failed_for(): void {
		$this->fake_download( new WP_Error( 'http_request_failed', 'No route to host' ) );

		$provider = new EncoderProvider();
		$provider->setVocabLoader( new Vocab_Loader( [ __FILE__ . '/nope' ] ) );
		$this->set_provider( $provider );

		$post_id = $this->factory()->post->create( [ 'post_content' => 'Open Monday to Friday.' ] );
		update_post_meta( $post_id, '_hyve_added', 1 );
		update_post_meta( $post_id, '_hyve_needs_update', 1 );

		DB_Table::instance()->update_posts();

		$this->assertSame( '1', get_post_meta( $post_id, '_hyve_needs_update', true ) );

		$error = get_post_meta( $post_id, '_hyve_processing_error', true );
		$this->assertStringContainsString( 'could not be tokenized', $error );
		$this->assertStringContainsString( 'retried automatically', $error );
	}

	/**
	 * One cron run over several flagged posts tries the download once: a
	 * failed load is remembered for the request, so a slow CDN costs one
	 * timeout, not one per post.
	 */
	public function test_a_failed_vocabulary_load_is_not_retried_within_the_request(): void {
		$requests = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ) use ( &$requests ) {
				if ( str_contains( $url, 'tiktoken' ) ) {
					++$requests;
				}

				return $pre;
			},
			9,
			3
		);
		$this->fake_download( new WP_Error( 'http_request_failed', 'No route to host' ) );

		$provider = new EncoderProvider();
		$provider->setVocabLoader( new Vocab_Loader( [ __FILE__ . '/nope' ] ) );
		$this->set_provider( $provider );

		$posts = $this->factory()->post->create_many( 3, [ 'post_content' => 'Open Monday to Friday.' ] );

		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, '_hyve_added', 1 );
			update_post_meta( $post_id, '_hyve_needs_update', 1 );
		}

		DB_Table::instance()->update_posts();

		$this->assertSame( 1, $requests );

		foreach ( $posts as $post_id ) {
			$this->assertSame( '1', get_post_meta( $post_id, '_hyve_needs_update', true ) );
			$this->assertStringContainsString( 'could not be tokenized', get_post_meta( $post_id, '_hyve_processing_error', true ) );
		}
	}
}
