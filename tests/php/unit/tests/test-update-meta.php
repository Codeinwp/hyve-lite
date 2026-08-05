<?php
/**
 * Test_Update_Meta class.
 *
 * @package Codeinwp/HyveLite
 */

/**
 * Class Test_Update_Meta.
 *
 * Editing indexed site content flags it for re-indexing; entries owned by the
 * ingest pipeline (non-viewable types) must never be flagged, because the
 * update cron can only see viewable types and would leave the flag stuck.
 */
class Test_Update_Meta extends WP_UnitTestCase {
	/**
	 * An indexed post flags `_hyve_needs_update` when edited.
	 */
	public function testEditedIndexedPostIsFlagged() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_hyve_added', 1 );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Edited title',
			]
		);

		$this->assertEquals( 1, get_post_meta( $post_id, '_hyve_needs_update', true ) );
	}

	/**
	 * Content that was never indexed is not flagged.
	 */
	public function testUnindexedPostIsNotFlagged() {
		$post_id = self::factory()->post->create();

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Edited title',
			]
		);

		$this->assertEmpty( get_post_meta( $post_id, '_hyve_needs_update', true ) );
	}

	/**
	 * Pipeline-owned entries (the non-viewable `hyve_docs` type) are exempt.
	 */
	public function testPipelineEntriesAreNeverFlagged() {
		$post_id = wp_insert_post(
			[
				'post_type'   => 'hyve_docs',
				'post_status' => 'publish',
				'post_title'  => 'Imported page',
			]
		);
		update_post_meta( $post_id, '_hyve_added', 1 );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Refreshed import',
			]
		);

		$this->assertEmpty( get_post_meta( $post_id, '_hyve_needs_update', true ) );
	}

	/**
	 * Editing an indexed post clears a previous moderation failure so it can
	 * be re-checked.
	 */
	public function testEditClearsModerationFlags() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_hyve_added', 1 );
		update_post_meta( $post_id, '_hyve_moderation_failed', 1 );
		update_post_meta( $post_id, '_hyve_moderation_review', [ 'hate' => 0.5 ] );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Edited title',
			]
		);

		$this->assertEmpty( get_post_meta( $post_id, '_hyve_moderation_failed', true ) );
		$this->assertEmpty( get_post_meta( $post_id, '_hyve_moderation_review', true ) );
	}
}
