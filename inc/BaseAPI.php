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
	 * @var \ThemeIsle\HyveLite\DB_Table
	 */
	protected $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->table = DB_Table::instance();
	}

	/**
	 * Get Error Message.
	 * 
	 * @param \WP_Error $error Error.
	 * 
	 * @return string
	 */
	public function get_error_message( $error ) {
		$message = OpenAI::get_error_message_for_code( $error->get_error_code() );

		if ( null !== $message ) {
			return $message;
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
	 * Run a paginated query and detect whether more rows exist.
	 *
	 * Fetches one row beyond the page size to tell whether there is a next page,
	 * which avoids the found-rows count (SQL_CALC_FOUND_ROWS) that
	 * WP_Query::$found_posts otherwise requires. The extra row is trimmed off the
	 * returned set. Pass `$with_total` when the caller drives a numbered pager;
	 * that trades the trick for the found-rows count and adds `total`.
	 *
	 * @param array<string, mixed> $args       WP_Query arguments, without paging.
	 * @param int                  $per_page   Page size.
	 * @param bool                 $with_total Also count all matching rows.
	 *
	 * @return array{posts: array<int, mixed>, more: bool, total?: int}
	 */
	protected function query_page( $args, $per_page = 20, $with_total = false ) {
		if ( $with_total ) {
			$args['posts_per_page'] = $per_page;
			$args['no_found_rows']  = false;

			$query = new \WP_Query( $args );
			$total = intval( $query->found_posts );

			return [
				'posts' => $query->posts,
				'more'  => intval( $args['offset'] ?? 0 ) + count( $query->posts ) < $total,
				'total' => $total,
			];
		}

		$args['posts_per_page'] = $per_page + 1;
		$args['no_found_rows']  = true;

		$query = new \WP_Query( $args );
		$posts = $query->posts;
		$more  = count( $posts ) > $per_page;

		return [
			'posts' => array_slice( $posts, 0, $per_page ),
			'more'  => $more,
		];
	}
}
