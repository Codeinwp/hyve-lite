<?php
/**
 * Tests for DB_Table class.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\DB_Table;

/**
 * Class DB_TableTest
 */
class DB_TableTest extends WP_UnitTestCase {

	/**
	 * DB_Table instance.
	 * 
	 * @var DB_Table
	 */
	protected $db_table;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$this->db_table = new DB_Table();
	}

	/**
	 * Test insert.
	 */
	public function test_insert() {
		$data = [
			'post_content' => 'Test content.',
			'post_id'      => 42,
		];

		$insert_id = $this->db_table->insert( $data );
		$this->assertEquals( 1, $insert_id );
	}

	/**
	 * Test get.
	 */
	public function test_get() {
		$row = $this->db_table->get( 1 );

		$this->assertIsObject( $row );
		$this->assertEquals( 1, $row->id );
	}

	/**
	 * Test update.
	 */
	public function test_update() {
		$data = [
			'post_content' => 'Updated content.',
		];

		$rows_affected = $this->db_table->update( 1, $data );
		$row           = $this->db_table->get( 1 );

		$this->assertEquals( 1, $rows_affected );
		$this->assertEquals( 'Updated content.', $row->post_content );
	}

	/**
	 * Test delete.
	 */
	public function test_delete_by_post_id() {
		$rows_affected = $this->db_table->delete_by_post_id( 42 );
		$this->assertEquals( 1, $rows_affected );
	}

	/**
	 * Test get_posts_over_limit.
	 */
	public function test_get_posts_over_limit() {
		for ( $i = 0; $i < 600; $i++ ) {
			$data = [
				'post_content' => 'Test content.',
				'post_id'      => $i,
			];

			$this->db_table->insert( $data );
		}

		$posts = $this->db_table->get_posts_over_limit();
		$this->assertCount( 100, $posts );
	}

	/**
	 * Test get_post_id.
	 */
	public function test_get_post_id() {
		$insert_id = $this->db_table->insert(
			[
				'post_content' => 'Source content.',
				'post_id'      => 777,
			]
		);

		$this->assertEquals( '777', (string) $this->db_table->get_post_id( $insert_id ) );
	}

	/**
	 * Content with no extractable text is refused before anything is stored,
	 * moderated, or embedded.
	 */
	public function test_ingest_document_rejects_empty_content() {
		$result = $this->db_table->ingest_document(
			[
				'title'   => 'Media only',
				'content' => '<img src="test.jpg" /> ',
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
	}

	/**
	 * A post edited down to empty content leaves the update queue with a
	 * terminal processing error instead of being retried forever.
	 */
	public function test_update_posts_marks_empty_content_terminal() {
		$post_id = self::factory()->post->create( [ 'post_content' => '' ] );
		update_post_meta( $post_id, '_hyve_added', 1 );
		update_post_meta( $post_id, '_hyve_needs_update', 1 );

		$this->db_table->update_posts();

		$this->assertNotSame( '', get_post_meta( $post_id, '_hyve_processing_error', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_hyve_needs_update', true ) );
	}
}
