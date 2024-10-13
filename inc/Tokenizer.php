<?php
/**
 * Tokenizer class.
 * 
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use Yethee\Tiktoken\EncoderProvider;

/**
 * Tokenizer class.
 */
class Tokenizer {
	/**
	 * Tokenize data.
	 * 
	 * @param array $post Post data.
	 * 
	 * @return array
	 */
	public static function tokenize( $post ) {
		$provider = new EncoderProvider();
		$encoder  = $provider->get( 'cl100k_base' );

		$content = preg_replace( '/<[^>]+>/', '', $post['content'] );
		$tokens  = $encoder->encode( $content );

		$article = [
			'post_id'      => $post['ID'] ?? null,
			'post_title'   => $post['title'],
			'post_content' => $post['content'],
			'tokens'       => $tokens,
		];

		$data = [];

		$chunked_token_size = 1000;
		$token_length       = count( $tokens );

		if ( $token_length > $chunked_token_size ) {
			$shortened_sentences = self::create_chunks( $article['post_content'], $chunked_token_size );

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
	 * @param string $text Text to chunk.
	 * @param int    $size Chunk size.
	 * 
	 * @return array
	 */
	public static function create_chunks( $text, $size = 1000 ) {
		$provider = new EncoderProvider();
		$encoder  = $provider->get( 'cl100k_base' );

		$sentences = explode( '. ', $text );

		$chunks        = [];
		$tokens_so_far = 0;
		$chunk         = [];

		foreach ( $sentences as $sentence ) {
			$token_length = count( $encoder->encode( ' ' . $sentence ) );

			if ( $tokens_so_far + $token_length > $size ) {
				$chunks[]      = implode( '. ', $chunk ) . '.';
				$chunk         = [];
				$tokens_so_far = 0;
			}

			if ( $token_length > $size ) {
				continue;
			}

			$chunk[]        = $sentence;
			$tokens_so_far += $token_length + 1;
		}

		if ( 0 < count( $chunk ) ) {
			$chunks[] = implode( '. ', $chunk ) . '.';
		}

		return $chunks;
	}
}
