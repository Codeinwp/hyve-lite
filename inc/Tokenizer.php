<?php
/**
 * Tokenizer class.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use guttedgarden\Tiktoken\EncoderProvider;

/**
 * Tokenizer class.
 */
class Tokenizer {
	/**
	 * Bumped when `html_to_text()` changes what it extracts, so Connect
	 * reconcile rehashes unedited posts instead of trusting a hash of the
	 * old text. 1 was the structured extractor; 2 folds contact links.
	 */
	const EXTRACTOR_VERSION = 2;

	/**
	 * Encoder provider, shared across calls.
	 *
	 * @var EncoderProvider|null
	 */
	private static $provider = null;

	/**
	 * The vocabulary failure of this request, rethrown instead of retried.
	 *
	 * @var \Exception|null
	 */
	private static $failure = null;

	/**
	 * Convert HTML content into retrieval-friendly plain text.
	 *
	 * A plain tag strip destroys the structure that carries meaning: table
	 * cells fuse with their neighbors ("Laundry$32.00/hr"), headings fuse
	 * with the paragraph below ("Our ServicesWe offer…"), and entities stay
	 * encoded so "O&#8217;Clock" never matches a visitor typing "O'Clock".
	 * This keeps that structure as text: cells become "label | value" pairs,
	 * block elements become line breaks, and entities are decoded.
	 *
	 * Shortcode markers are dropped and the text they wrap kept: `the_content`
	 * only expands shortcodes whose plugin is active, so an inactive builder or
	 * content copied from another site leaves tags behind.
	 *
	 * @since 1.5.1
	 *
	 * @param string $content HTML (or plain text) content.
	 *
	 * @return string
	 */
	public static function html_to_text( $content ) {
		$content = (string) $content;

		if ( '' === trim( $content ) ) {
			return '';
		}

		// A name has to start with a letter, and anything after it has to look
		// like a `name="value"` attribute. Between them those keep bracketed
		// prose ("[1]", "[see below]", "[Open 9am to 6pm]") out of the match.
		// Once a tag does carry an attribute, the text up to the next `]` goes
		// with it. The run is possessive only so that an unclosed `[tag attr=`
		// cannot fail on backtracking.
		$stripped = preg_replace( '/\[\/?[a-zA-Z][a-zA-Z0-9_-]*(?:\s+[a-zA-Z0-9_-]+\s*=[^\]]*+)?\s*\/?\]/', '', $content );

		// Keep the text if the engine gave up: an empty document is refused on
		// ingest, so a failed strip would drop the page entirely.
		$content = null === $stripped ? $content : $stripped;

		$content = self::fold_contact_links( $content );

		$content = (string) preg_replace(
			[
				// Line breaks become newlines.
				'/<br\s*\/?>/i',
				// A cell boundary becomes a separator, so a value keeps its label.
				'/<\/(td|th)>/i',
				// Closing a block-level element ends the line.
				'/<\/(tr|table|thead|tbody|tfoot|caption|p|div|h[1-6]|li|ul|ol|dl|dt|dd|blockquote|pre|figure|figcaption|section|article|header|footer|aside|address|details|summary)>/i',
			],
			[ "\n", ' | ', "\n" ],
			$content
		);

		// Drops the remaining tags, plus script/style/comments with their content.
		$content = wp_strip_all_tags( $content );

		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = str_replace( "\xc2\xa0", ' ', $content );

		// Tidy up: no separator dangling at a row's end, no whitespace runs.
		$content = (string) preg_replace(
			[ '/[ \t]*\|[ \t]*(?=\n|$)/', '/[ \t]+/', '/ ?\n ?/', '/\n{2,}/' ],
			[ '', ' ', "\n", "\n" ],
			$content
		);

		return trim( $content );
	}

	/**
	 * Fold `tel:` and `mailto:` link targets into their anchor text.
	 *
	 * "Call us" and "Email the clinic" are often the only place a page keeps
	 * its phone number and address: the fact lives in the `href`, which the
	 * tag strip discards. The target is appended as "(…)" unless the anchor
	 * text already shows it. Other schemes are left alone: navigation is full
	 * of links, and their URLs would swamp every chunk.
	 *
	 * @param string $content HTML content.
	 *
	 * @return string
	 */
	private static function fold_contact_links( $content ) {
		// The body runs to the anchor's own `</a>` and stops at the next `<a`,
		// so an unclosed link neither captures the rest of the page nor
		// rescans it: possessive, and bounded by the next anchor.
		// A quoted target may hold spaces ("tel:01274 998877", as some widgets
		// print a phone field raw); an unquoted one ends at whitespace.
		$folded = preg_replace_callback(
			'/<a\b[^>]*?\shref\s*=\s*(?:"\s*(?<double>(?:tel|mailto):[^"]*)"|\'\s*(?<single>(?:tel|mailto):[^\']*)\'|(?<bare>(?:tel|mailto):[^\s>]*))[^>]*>(?<body>[^<]*+(?:<(?!\/a\s*>|a[\s>])[^<]*+)*+)(?<close><\/a\s*>)/i',
			[ self::class, 'fold_contact_link' ],
			$content
		);

		return null === $folded ? $content : $folded;
	}

	/**
	 * Append one contact link's target to its anchor text.
	 *
	 * @param array<int|string, string> $link The match: the tag, the target under
	 *                                        one of `double`, `single` or `bare`,
	 *                                        then `body` and `close`.
	 *
	 * @return string
	 */
	private static function fold_contact_link( array $link ) {
		$tag    = $link[0];
		$body   = $link['body'];
		$close  = $link['close'];
		$target = '';

		foreach ( [ 'double', 'single', 'bare' ] as $quoting ) {
			if ( '' !== ( $link[ $quoting ] ?? '' ) ) {
				$target = $link[ $quoting ];
				break;
			}
		}

		$target = html_entity_decode( $target, ENT_QUOTES, 'UTF-8' );
		$scheme = strtolower( (string) strstr( $target, ':', true ) );
		$value  = self::contact_value( $scheme, (string) substr( $target, strlen( $scheme ) + 1 ) );

		if ( '' === $value ) {
			return $tag;
		}

		$text = trim( html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES, 'UTF-8' ) );

		if ( 'tel' === $scheme ? self::shows_number( $text, $value ) : false !== stripos( $text, $value ) ) {
			return $tag;
		}

		// The fold goes after the last text, inside any block the anchor wraps
		// (a card, a button), so it stays on the label's line. An icon-only
		// link has no text to attach to, so the target stands alone, spaced
		// off whatever follows the link.
		$fold     = '' === $text ? ' ' . $value . ' ' : ' (' . $value . ')';
		$kept     = (string) preg_replace( '/(?:\s*<\/[a-zA-Z][a-zA-Z0-9]*\s*>)*\s*$/', '', $body );
		$trailing = (string) substr( $body, strlen( $kept ) );

		return substr( $tag, 0, strlen( $tag ) - strlen( $close ) - strlen( $trailing ) ) . $fold . $trailing . $close;
	}

	/**
	 * The storable value of a `tel:` or `mailto:` target.
	 *
	 * `mailto:x@y?subject=…` carries a query; the address is before it. A
	 * `tel:` URI carries `;ext=` and `;phone-context=` parameters, of which
	 * the extension is kept as prose.
	 *
	 * @param string $scheme The scheme, `tel` or `mailto`.
	 * @param string $target The target after the scheme.
	 *
	 * @return string Empty when the target carries nothing worth storing.
	 */
	private static function contact_value( $scheme, $target ) {
		$value = trim( rawurldecode( explode( '?', $target, 2 )[0] ) );

		if ( 'tel' !== $scheme ) {
			return $value;
		}

		$parts = explode( ';', $value );
		$value = trim( array_shift( $parts ) );

		// A placeholder such as `tel:+` or `tel:#` has nothing to store.
		if ( ! preg_match( '/[0-9a-zA-Z]/', $value ) ) {
			return '';
		}

		foreach ( $parts as $part ) {
			if ( 0 === stripos( trim( $part ), 'ext=' ) ) {
				$value .= ' ext. ' . trim( substr( trim( $part ), 4 ) );
			}
		}

		return $value;
	}

	/**
	 * Whether anchor text already shows the linked phone number.
	 *
	 * Each number-like run in the text is compared on digits, from the end,
	 * so "01274 998877" matches `tel:+441274998877` despite the national
	 * prefix. A run is also tried group by group, so a neighbouring "(24
	 * hours)" or "Mon-Fri 9-5" does not get in the way. A run written with
	 * its own "+" country code is read from its start and must carry the
	 * whole linked number: "+1 274 998877" is not a UK target, while "+44
	 * 1274 998877" shows `tel:01274998877`.
	 *
	 * @param string $text   The anchor text.
	 * @param string $number The `tel:` value.
	 *
	 * @return bool
	 */
	private static function shows_number( $text, $number ) {
		$linked = ltrim( (string) preg_replace( '/\D+/', '', explode( ' ext.', $number, 2 )[0] ), '0' );

		// Too few digits to compare (a vanity number): fall back to the text.
		if ( strlen( $linked ) < 7 ) {
			return false !== stripos( $text, $number );
		}

		// "+44 (0)1274" shows a trunk prefix the international form omits.
		preg_match_all( '/\+?\d[\d\s().-]*\d/', str_replace( '(0)', '', $text ), $runs );

		foreach ( $runs[0] as $run ) {
			$international = '+' === $run[0];
			$groups        = (array) preg_split( '/\D+/', $run, -1, PREG_SPLIT_NO_EMPTY );
			$count         = count( $groups );

			for ( $start = 0; $start < ( $international ? 1 : $count ); $start++ ) {
				$shown = '';

				for ( $end = $start; $end < $count; $end++ ) {
					$shown .= $groups[ $end ];

					if ( self::same_number( ltrim( $shown, '0' ), $linked, $international ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Whether a shown digit string is the linked number.
	 *
	 * @param string $shown         Digits shown in the text, leading zeros dropped.
	 * @param string $linked        Digits of the `tel:` value, leading zeros dropped.
	 * @param bool   $international Whether the shown digits start with a "+".
	 *
	 * @return bool
	 */
	private static function same_number( $shown, $linked, $international ) {
		$length = min( strlen( $shown ), strlen( $linked ) );

		if ( $length < 7 || ( $international && strlen( $shown ) < strlen( $linked ) ) ) {
			return false;
		}

		return substr( $shown, -$length ) === substr( $linked, -$length );
	}

	/**
	 * Tokenize data.
	 *
	 * @param array<string, mixed> $post Post data.
	 *
	 * @return array<array<string, mixed>>
	 */
	public static function tokenize( $post ) {
		$encoder = self::encoder();

		// Chunks are sized, embedded and sent to the model as this cleaned
		// text, so sizes are measured on what is actually stored rather than
		// on markup that gets stripped later.
		$content = self::html_to_text( $post['content'] );
		$tokens  = $encoder->encode( $content );

		$article = [
			'post_id'      => $post['ID'] ?? null,
			'post_title'   => $post['title'],
			'post_content' => $content,
			'tokens'       => $tokens,
		];

		$data = [];

		/**
		 * Filters the maximum size of a knowledge base chunk, in tokens.
		 *
		 * Content longer than this is split into multiple chunks, each embedded
		 * and retrieved on its own. Smaller chunks give sharper matches for
		 * questions about one detail of a long page, at the cost of more
		 * embeddings; larger chunks keep more surrounding context together.
		 *
		 * @since 1.5.1
		 *
		 * @param int $chunked_token_size Maximum chunk size in tokens. Default 1000.
		 */
		$chunked_token_size = max( 100, (int) apply_filters( 'hyve_chunk_token_size', 1000 ) );
		$token_length       = count( $tokens );

		if ( $token_length > $chunked_token_size ) {
			$shortened_sentences = self::create_chunks( $content, $chunked_token_size );

			foreach ( $shortened_sentences as $shortened_sentence ) {
				$chunked_tokens = $encoder->encode( $post['title'] . ' ' . $shortened_sentence );

				$data[] = [
					'post_id'      => $article['post_id'],
					'post_title'   => $article['post_title'],
					'post_content' => $shortened_sentence,
					'tokens'       => $chunked_tokens,
					'token_count'  => count( $chunked_tokens ),
				];
			}
		} else {
			$chunked_tokens = $encoder->encode( $post['title'] . ' ' . $content );

			$data[] = [
				'post_id'      => $article['post_id'],
				'post_title'   => $article['post_title'],
				'post_content' => $article['post_content'],
				'tokens'       => $chunked_tokens,
				'token_count'  => count( $chunked_tokens ),
			];
		}

		return $data;
	}

	/**
	 * Create Chunks.
	 *
	 * Splits on sentence ends and line breaks, keeping each segment's own
	 * punctuation, and packs segments into chunks of at most `$size` tokens.
	 * A single segment larger than the whole budget (a table flattened to one
	 * line, minified markup) is hard-split by tokens rather than dropped, so
	 * no content silently disappears from the knowledge base.
	 *
	 * @param string $text Text to chunk.
	 * @param int    $size Chunk size.
	 *
	 * @return array<string>
	 */
	public static function create_chunks( $text, $size = 1000 ) {
		$size    = max( 1, (int) $size );
		$encoder = self::encoder();

		$segments = preg_split( '/(?<=[.!?])[ \t]+|\n+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $segments ) ) {
			return [];
		}

		$chunks        = [];
		$tokens_so_far = 0;
		$chunk         = [];

		foreach ( $segments as $segment ) {
			$token_length = count( $encoder->encode( ' ' . $segment ) );

			if ( $token_length > $size ) {
				if ( 0 < count( $chunk ) ) {
					$chunks[]      = implode( "\n", $chunk );
					$chunk         = [];
					$tokens_so_far = 0;
				}

				foreach ( array_chunk( $encoder->encode( $segment ), $size ) as $piece ) {
					// A hard token split can land mid-character; drop the
					// stray bytes at the seam rather than storing broken text.
					$piece_text = wp_check_invalid_utf8( $encoder->decode( $piece ), true );

					if ( '' !== trim( $piece_text ) ) {
						$chunks[] = trim( $piece_text );
					}
				}

				continue;
			}

			if ( $tokens_so_far + $token_length > $size ) {
				$chunks[]      = implode( "\n", $chunk );
				$chunk         = [];
				$tokens_so_far = 0;
			}

			$chunk[]        = $segment;
			$tokens_so_far += $token_length + 1;
		}

		if ( 0 < count( $chunk ) ) {
			$chunks[] = implode( "\n", $chunk );
		}

		return $chunks;
	}

	/**
	 * Count the tokens in a string.
	 *
	 * @param string $text Text to count.
	 *
	 * @return int
	 */
	public static function count_tokens( string $text ): int {
		return count( self::encoder()->encode( $text ) );
	}

	/**
	 * The cl100k_base encoder, built once per request: loading the vocabulary
	 * is the expensive part.
	 *
	 * @return \guttedgarden\Tiktoken\Encoder
	 * @throws \RuntimeException When the vocabulary cannot be loaded.
	 * @throws \Exception The failure remembered from earlier in the request.
	 */
	private static function encoder(): \guttedgarden\Tiktoken\Encoder {
		if ( null !== self::$failure ) {
			throw self::$failure;
		}

		if ( null === self::$provider ) {
			self::$provider = new EncoderProvider();
			self::$provider->setVocabLoader( new Vocab_Loader() );
		}

		try {
			return self::$provider->get( 'cl100k_base' );
		} catch ( \Exception $e ) {
			// Remembered for the request: a cron run over several posts must
			// not wait out one download timeout per post.
			self::$failure = $e;

			throw $e;
		}
	}
}
