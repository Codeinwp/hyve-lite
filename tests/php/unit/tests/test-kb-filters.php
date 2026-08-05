<?php
/**
 * Test_KB_Filters class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\DB_Table;

/**
 * Class Test_KB_Filters.
 *
 * Covers the taxonomy filtering of the Knowledge Base picker: the `tax` query
 * arg on the `data` listing and the `filters` endpoint that enumerates the
 * taxonomies for the selected post type.
 */
class Test_KB_Filters extends WP_UnitTestCase {

	/**
	 * Ensure the chunks table exists and act as an admin.
	 */
	public function setUp(): void {
		parent::setUp();
		new DB_Table();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Run the picker listing and return the matching post ids.
	 *
	 * @param array<string, mixed> $params Extra query params.
	 *
	 * @return array<int, int>
	 */
	private function picker_ids( $params ) {
		$request = new WP_REST_Request( 'GET', '/hyve/v1/data' );
		$request->set_query_params( array_merge( [ 'type' => 'post' ], $params ) );

		$ids = [];

		foreach ( rest_do_request( $request )->get_data()['posts'] as $row ) {
			$ids[] = (int) $row['ID'];
		}

		return $ids;
	}

	/**
	 * Fetch the filter options for a post type.
	 *
	 * @param string $type Post type.
	 *
	 * @return array<string, mixed>
	 */
	private function filters( $type = 'post' ) {
		$request = new WP_REST_Request( 'GET', '/hyve/v1/filters' );
		$request->set_query_params( [ 'type' => $type ] );

		return rest_do_request( $request )->get_data();
	}

	/**
	 * The taxonomy filter narrows the listing to posts in the chosen term.
	 */
	public function test_data_filters_by_taxonomy() {
		$category = self::factory()->category->create();
		$tagged   = self::factory()->post->create();
		$untagged = self::factory()->post->create();

		wp_set_post_categories( $tagged, [ $category ] );

		$ids = $this->picker_ids( [ 'tax' => [ 'category:' . $category ] ] );

		$this->assertContains( $tagged, $ids );
		$this->assertNotContains( $untagged, $ids );
	}

	/**
	 * The filters endpoint enumerates the taxonomies that apply to the
	 * selected post type.
	 */
	public function test_filters_endpoint_reports_available_taxonomies() {
		$post = self::factory()->post->create();

		wp_set_post_categories( $post, [ self::factory()->category->create() ] );

		$this->assertContains( 'category', wp_list_pluck( $this->filters( 'post' )['taxonomies'], 'name' ) );
	}

	/**
	 * Taxonomies only apply to a concrete post type; `any` skips them.
	 */
	public function test_filters_endpoint_omits_taxonomies_for_any() {
		$this->assertSame( [], $this->filters( 'any' )['taxonomies'] );
	}
}
