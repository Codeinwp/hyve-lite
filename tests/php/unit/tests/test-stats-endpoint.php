<?php
/**
 * Test_Stats_Endpoint class.
 *
 * @package Codeinwp/HyveLite
 */

/**
 * Class Test_Stats_Endpoint.
 *
 * The `stats` route feeds the dashboard's live stat cards and usage chart.
 */
class Test_Stats_Endpoint extends WP_UnitTestCase {
	/**
	 * Issue a REST request against the stats route.
	 *
	 * @return WP_REST_Response
	 */
	private function request_stats() {
		$request = new WP_REST_Request( 'GET', '/hyve/v1/stats' );

		return rest_do_request( $request );
	}

	/**
	 * Admins receive the same stats and chart payload the page localizes.
	 */
	public function testReturnsStatsAndChartForAdmins() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->request_stats();
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertArrayHasKey( 'threads', $data['stats'] );
		$this->assertArrayHasKey( 'messages', $data['stats'] );
		$this->assertArrayHasKey( 'totalChunks', $data['stats'] );

		$this->assertArrayHasKey( 'legend', $data['chart'] );
		$this->assertArrayHasKey( 'labels', $data['chart'] );
		$this->assertArrayHasKey( 'messages', $data['chart']['data'] );
		$this->assertArrayHasKey( 'sessions', $data['chart']['data'] );
		$this->assertSameSize( $data['chart']['labels'], $data['chart']['data']['messages'] );
	}

	/**
	 * The route is admin-only.
	 */
	public function testRejectsUnauthorizedUsers() {
		wp_set_current_user( 0 );

		$this->assertEquals( 401, $this->request_stats()->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertEquals( 403, $this->request_stats()->get_status() );
	}
}
