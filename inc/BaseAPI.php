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
	private $errors = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->table = DB_Table::instance();

		$this->errors = array(
			'invalid_api_key' => __( 'Incorrect API key provided.', 'hyve-lite' ),
			'missing_scope'   => __( ' You have insufficient permissions for this operation.', 'hyve-lite' ),
		);
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
		$results              = array();
		$return               = true;
		$settings             = Main::get_settings();
		$moderation_threshold = $settings['moderation_threshold'];

		if ( ! is_array( $chunks ) ) {
			$chunks = array( $chunks );
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
			$flagged = array();
	
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
}
