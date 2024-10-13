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
}
