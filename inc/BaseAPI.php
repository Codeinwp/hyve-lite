<?php
/**
 * BaseAPI class.
 * 
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\Main;
use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\OpenAI;
use Yethee\Tiktoken\EncoderProvider;

/**
 * BaseAPI class.
 */
class BaseAPI {
	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private $namespace = 'hyve';

	/**
	 * API version.
	 *
	 * @var string
	 */
	private $version = 'v1';

	/**
	 * Instance of DB_Table class.
	 *
	 * @var object
	 */
	protected $table;

	/**
	 * Error messages.
	 * 
	 * @var array
	 */
	private $errors = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->table = DB_Table::instance();

		$this->errors = [
			'invalid_api_key' => __( 'Incorrect API key provided.', 'hyve-lite' ),
			'missing_scope'   => __( ' You have insufficient permissions for this operation.', 'hyve-lite' ),
		];
	}

	/**
	 * Get Error Message.
	 * 
	 * @param \WP_Error $error Error.
	 * 
	 * @return string
	 */
	public function get_error_message( $error ) {
		if ( isset( $this->errors[ $error->get_error_code() ] ) ) {
			return $this->errors[ $error->get_error_code() ];
		}

		return $error->get_error_message();
	}

	/**
	 * Get endpoint.
	 * 
	 * @return string
	 */
	public function get_endpoint() {
		return $this->namespace . '/' . $this->version;
	}

	/**
	 * Moderate data.
	 * 
	 * @param array|string $chunks Data to moderate.
	 * @param int          $id     Post ID.
	 * 
	 * @return true|array|\WP_Error
	 */
	public function moderate( $chunks, $id = null ) {
		if ( $id ) {
			$moderated = get_transient( 'hyve_moderate_post_' . $id );

			if ( false !== $moderated ) {
				return is_array( $moderated ) ? $moderated : true;
			}
		}

		$openai               = OpenAI::instance();
		$results              = [];
		$return               = true;
		$settings             = Main::get_settings();
		$moderation_threshold = $settings['moderation_threshold'];

		if ( ! is_array( $chunks ) ) {
			$chunks = [ $chunks ];
		}

		foreach ( $chunks as $chunk ) {
			$moderation = $openai->moderate( $chunk );

			if ( is_wp_error( $moderation ) ) {
				return $moderation;
			}

			if ( true !== $moderation && is_object( $moderation ) ) {
				$results[] = $moderation;
			}
		}

		if ( ! empty( $results ) ) {
			$flagged = [];
	
			foreach ( $results as $result ) {
				$categories = $result->categories;
	
				foreach ( $categories as $category => $flag ) {
					if ( ! $flag ) {
						continue;
					}

					if ( ! isset( $moderation_threshold[ $category ] ) || $result->category_scores->$category < ( $moderation_threshold[ $category ] / 100 ) ) {
						continue;
					}

					if ( ! isset( $flagged[ $category ] ) ) {
						$flagged[ $category ] = $result->category_scores->$category;
						continue;
					}
	
					if ( $result->category_scores->$category > $flagged[ $category ] ) {
						$flagged[ $category ] = $result->category_scores->$category;
					}
				}
			}

			if ( empty( $flagged ) ) {
				$return = true;
			} else {
				$return = $flagged;
			}
		}

		if ( $id ) {
			set_transient( 'hyve_moderate_post_' . $id, $return, MINUTE_IN_SECONDS );
		}

		return $return;
	}

	/**
	 * Tokenize data.
	 * 
	 * @param array $post Post data.
	 * 
	 * @return array
	 */
	public function tokenize( $post ) {
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
			$shortened_sentences = $this->create_chunks( $content, $chunked_token_size );

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
	public function create_chunks( $text, $size = 1000 ) {
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
