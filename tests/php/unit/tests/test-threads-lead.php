<?php
/**
 * Test_Threads_Lead class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Threads;

/**
 * Class Test_Threads_Lead.
 *
 * The threads listing exposes the linked lead id so the dashboard can badge a
 * conversation that produced a lead. Pro stores the meta; lite only surfaces it.
 */
class Test_Threads_Lead extends WP_UnitTestCase {

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Fetch the threads listing.
	 *
	 * @return array<string, mixed>
	 */
	private function fetch_threads() {
		$request = new WP_REST_Request( 'GET', '/hyve/v1/threads' );

		return rest_do_request( $request )->get_data();
	}

	/**
	 * A conversation with a linked lead reports its id; one without reports 0.
	 */
	public function test_threads_expose_lead_id() {
		$linked = Threads::create_thread(
			'Linked',
			[
				'thread_id' => 'thread-linked',
				'sender'    => 'user',
				'message'   => 'Hello',
			]
		);
		update_post_meta( $linked, '_hyve_lead_id', 4242 );

		$plain = Threads::create_thread(
			'Plain',
			[
				'thread_id' => 'thread-plain',
				'sender'    => 'user',
				'message'   => 'Hi',
			]
		);

		$by_id = [];

		foreach ( $this->fetch_threads()['posts'] as $post ) {
			$by_id[ $post['ID'] ] = $post;
		}

		$this->assertArrayHasKey( 'lead_id', $by_id[ $linked ] );
		$this->assertSame( 4242, $by_id[ $linked ]['lead_id'] );
		$this->assertSame( 0, $by_id[ $plain ]['lead_id'] );
	}
}
