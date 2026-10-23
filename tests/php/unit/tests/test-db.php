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
	 * The table is real in the test database (see bootstrap.php), so the IDs
	 * these tests assert start from one only after a reset.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		new DB_Table();

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'hyve' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();
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
		$insert_id = $this->db_table->insert(
			[
				'post_content' => 'Test content.',
				'post_id'      => 42,
			] 
		);
		$row       = $this->db_table->get( $insert_id );

		$this->assertIsObject( $row );
		$this->assertEquals( $insert_id, $row->id );
	}

	/**
	 * Test update.
	 */
	public function test_update() {
		$data = [
			'post_content' => 'Updated content.',
		];

		$insert_id     = $this->db_table->insert(
			[
				'post_content' => 'Test content.',
				'post_id'      => 42,
			] 
		);
		$rows_affected = $this->db_table->update( $insert_id, $data );
		$row           = $this->db_table->get( $insert_id );

		$this->assertEquals( 1, $rows_affected );
		$this->assertEquals( 'Updated content.', $row->post_content );
	}

	/**
	 * Test delete.
	 */
	public function test_delete_by_post_id() {
		$this->db_table->insert(
			[
				'post_content' => 'Test content.',
				'post_id'      => 42,
			] 
		);
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

	/**
	 * A table from before 1.3.0 gains the keyword index when the plugin
	 * constructs the table at the new version.
	 */
	public function test_upgrade_adds_fulltext_index() {
		global $wpdb;
		$table = $wpdb->prefix . 'hyve';

		// The ALTERs commit implicitly, so the 1.2.0 version write is committed
		// too; the upgrade is redone and committed in `finally` so the next
		// test does not start on a downgraded table.
		try {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX content_search" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			update_option( $table . '_db_version', '1.2.0' );

			$this->assertEmpty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'content_search'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			new DB_Table();

			$this->assertNotEmpty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'content_search'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( '1.3.0', get_option( $table . '_db_version' ) );
		} finally {
			new DB_Table();
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * The keyword parsers' edges: short words and single no-space characters
	 * are dropped, the term list is capped, and a query with nothing left
	 * runs no SQL.
	 */
	public function test_query_terms_and_segments_boundaries() {
		global $wpdb;

		$this->assertSame( [], DB_Table::query_terms( 'is it ok' ) );
		$this->assertSame( [ 'alpha' ], DB_Table::query_terms( 'Alpha ALPHA alpha' ) );
		$this->assertCount( 10, DB_Table::query_terms( 'one1 two2 three four five six seven eight nine ten1 eleven' ) );
		$this->assertSame( [], DB_Table::query_segments( '日' ) );
		$this->assertSame( [ '日本語' ], DB_Table::query_segments( 'hello 日本語 and 日本語' ) );

		$queries = $wpdb->num_queries;

		$this->assertSame( [], $this->db_table->lexical_search( 'is it ok' ) );
		$this->assertSame( $queries, $wpdb->num_queries );
	}
}
