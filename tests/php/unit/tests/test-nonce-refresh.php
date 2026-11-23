<?php
/**
 * Test_Nonce_Refresh class.
 *
 * @package Codeinwp/HyveLite
 */

use ThemeIsle\HyveLite\Stream;

/**
 * Class Test_Nonce_Refresh.
 *
 * Cached pages can outlive the nonces they embed, and core only renews the
 * REST nonce for logged-in users. The widget renews both through
 * `hyve_refresh_nonce`, for visitors too.
 */
class Test_Nonce_Refresh extends WP_Ajax_UnitTestCase {

	/**
	 * Reset the request globals the handler and the cookie check read.
	 */
	public function tear_down() {
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['REQUEST_METHOD'] );
		$GLOBALS['wp_rest_auth_cookie'] = null;

		parent::tear_down();
	}

	/**
	 * Run the refresh action as the given user and decode its JSON reply.
	 *
	 * @param int    $user_id The user to run as (0 for a visitor).
	 * @param string $method  The request method.
	 *
	 * @return mixed
	 */
	private function refresh( $user_id, $method = 'POST' ) {
		wp_set_current_user( $user_id );
		$_SERVER['REQUEST_METHOD'] = $method;
		$this->_last_response      = '';

		// The die handler collects the output and closes the buffer.
		ob_start();

		try {
			do_action( ( $user_id ? 'wp_ajax_' : 'wp_ajax_nopriv_' ) . 'hyve_refresh_nonce' );
			$this->_last_response .= ob_get_clean();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Dispatch a chat poll as the current user with the given nonce.
	 *
	 * @param string $nonce The X-WP-Nonce header value.
	 *
	 * @return \WP_REST_Response
	 */
	private function chat( $nonce ) {
		$request = new WP_REST_Request( 'GET', '/hyve/v1/chat' );
		$request->set_header( 'x_wp_nonce', $nonce );
		$request->set_param( 'run_id', 'run_1' );
		$request->set_param( 'thread_id', 'thread_1' );
		$request->set_param( 'record_id', 0 );

		return rest_do_request( $request );
	}

	/**
	 * Visitors and logged-in users can both renew.
	 */
	public function test_refresh_is_registered_for_visitors_and_users() {
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_hyve_refresh_nonce' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_hyve_refresh_nonce' ) );
	}

	/**
	 * A visitor gets a REST and a stream nonce that verify for user 0.
	 */
	public function test_visitor_refresh_returns_valid_nonces() {
		$nonces = $this->refresh( 0 );

		$this->assertIsArray( $nonces );
		$this->assertSame( 1, wp_verify_nonce( $nonces['rest'], 'wp_rest' ) );
		$this->assertSame( 1, wp_verify_nonce( $nonces['stream'], Stream::NONCE_ACTION ) );
	}

	/**
	 * A logged-in user gets nonces bound to their own account.
	 */
	public function test_user_refresh_returns_nonces_for_that_user() {
		$user_id = self::factory()->user->create();
		$nonces  = $this->refresh( $user_id );

		$this->assertSame( 1, wp_verify_nonce( $nonces['rest'], 'wp_rest' ) );
		$this->assertSame( 1, wp_verify_nonce( $nonces['stream'], Stream::NONCE_ACTION ) );

		wp_set_current_user( 0 );
		$this->assertFalse( wp_verify_nonce( $nonces['rest'], 'wp_rest' ) );
	}

	/**
	 * GET is refused, so no cache keyed on the URL can hold a nonce.
	 */
	public function test_refresh_refuses_get() {
		$nonces = $this->refresh( 0, 'GET' );

		$this->assertArrayNotHasKey( 'rest', (array) $nonces );
		$this->assertStringNotContainsString( wp_create_nonce( 'wp_rest' ), $this->_last_response );
	}

	/**
	 * A stale nonce fails core's cookie check for a visitor; a refreshed one
	 * passes it and the chat route's own check.
	 */
	public function test_refreshed_nonce_replaces_a_stale_one() {
		wp_set_current_user( 0 );

		$_SERVER['HTTP_X_WP_NONCE'] = 'stale1234';
		$stale                      = rest_cookie_check_errors( null );

		$this->assertWPError( $stale );
		$this->assertSame( 'rest_cookie_invalid_nonce', $stale->get_error_code() );

		$nonces = $this->refresh( 0 );

		$_SERVER['HTTP_X_WP_NONCE'] = $nonces['rest'];
		$this->assertTrue( rest_cookie_check_errors( null ) );

		$error = $this->chat( $nonces['rest'] )->as_error();
		$this->assertNotSame( 'rest_forbidden', $error ? $error->get_error_code() : '' );
	}

	/**
	 * A nonce renewed for a logged-in user is refused for a visitor.
	 */
	public function test_chat_refuses_a_nonce_renewed_for_another_user() {
		$nonces = $this->refresh( self::factory()->user->create() );

		$this->assertNotEmpty( $nonces['rest'] ?? '' );

		wp_set_current_user( 0 );

		$this->assertSame( 'rest_forbidden', $this->chat( $nonces['rest'] )->as_error()->get_error_code() );
	}
}
