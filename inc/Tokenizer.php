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
	 * Convert HTML content into retrieval-friendly plain text.
	 *
	 * A plain tag strip destroys the structure that carries meaning: table
	 * cells fuse with their neighbors ("Laundry$32.00/hr"), headings fuse
	 * with the paragraph below ("Our ServicesWe offer…"), and entities stay
	 * encoded so "O&#8217;Clock" never matches a visitor typing "O'Clock".
	 * This keeps that structure as text: cells become "label | value" pairs,
	 * block elements become line breaks, and entities are decoded.
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
	 * Tokenize data.
	 *
	 * @param array<string, mixed> $post Post data.
	 *
	 * @return array<array<string, mixed>>
	 */
	public static function tokenize( $post ) {
		$provider = new EncoderProvider();
		$provider->setVocabCache( get_temp_dir() );

		$encoder = $provider->get( 'cl100k_base' );

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
		$size = max( 1, (int) $size );

		$provider = new EncoderProvider();
		$provider->setVocabCache( get_temp_dir() );

		$encoder = $provider->get( 'cl100k_base' );

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
}
