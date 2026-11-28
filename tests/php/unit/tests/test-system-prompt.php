<?php
/**
 * Tests for the built-in chat instructions: the date line and the reply rules.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Hyve_Connect;
use ThemeIsle\HyveLite\OpenAI;

/**
 * Class Test_System_Prompt.
 */
class Test_System_Prompt extends WP_UnitTestCase {

	/**
	 * Restore the default timezone and settings.
	 */
	protected function tearDown(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );
		delete_option( 'hyve_settings' );
		parent::tearDown();
	}

	/**
	 * The instructions a chat turn sends, built the way both reply flows build them.
	 *
	 * @return string
	 */
	private function instructions() {
		$method = new ReflectionMethod( OpenAI::instance(), 'get_chat_response_params' );
		$method->setAccessible( true );
		$params = $method->invoke( OpenAI::instance(), [], 'conv_1' );

		return $params['instructions'];
	}

	/**
	 * The date line names today in the site's timezone, not the server's.
	 */
	public function test_date_line_uses_the_site_timezone() {
		update_option( 'timezone_string', 'Pacific/Kiritimati' );

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'Pacific/Kiritimati' ) );

		$this->assertSame(
			sprintf( 'Today is %s (%s, %s), timezone Pacific/Kiritimati.', $now->format( 'Y-m-d' ), $now->format( 'l' ), $now->format( 'j F Y' ) ),
			OpenAI::date_line()
		);
	}

	/**
	 * A manual UTC offset is reported in the form WordPress uses for it.
	 */
	public function test_date_line_reports_a_manual_offset() {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5.5 );

		$this->assertStringEndsWith( ', timezone -05:30.', OpenAI::date_line() );
	}

	/**
	 * Every turn carries the date line right after the base instructions, so
	 * the static prefix stays identical across sites and days.
	 */
	public function test_instructions_end_with_the_date_line() {
		$instructions = $this->instructions();

		$this->assertStringStartsWith( 'You are a Support Assistant', $instructions );
		$this->assertStringEndsWith( "based on available context.\r\n\r\n" . OpenAI::date_line(), $instructions );
	}

	/**
	 * With a site owner prompt the order is owner prompt, base, date, reminder.
	 */
	public function test_owner_prompt_keeps_the_date_line_after_the_base_instructions() {
		update_option( 'hyve_settings', [ 'system_prompt' => 'Reply like a pirate.' ] );

		$instructions = $this->instructions();

		$owner = strpos( $instructions, 'Reply like a pirate.' );
		$base  = strpos( $instructions, 'You are a Support Assistant' );
		$date  = strpos( $instructions, 'Today is ' );
		$tail  = strpos( $instructions, 'The SITE OWNER INSTRUCTIONS from the top of this prompt' );

		$this->assertSame( 0, strpos( $instructions, 'SITE OWNER INSTRUCTIONS (highest priority):' ) );
		$this->assertGreaterThan( $owner, $base );
		$this->assertGreaterThan( $base, $date );
		$this->assertGreaterThan( $date, $tail );
	}

	/**
	 * The reply rules from the review of hosted exchanges are in the prompt,
	 * and the model-written apology that showed up in the wrong language is not.
	 */
	public function test_instructions_carry_the_reply_rules() {
		$instructions = $this->instructions();

		$this->assertStringContainsString( '7. Dates and Time', $instructions );
		$this->assertStringContainsString( 'the earliest listed date on or after today', $instructions );
		$this->assertStringContainsString( 'exactly as they appear in the context', $instructions );
		$this->assertStringContainsString( 'include that URL as an <a> link', $instructions );
		$this->assertStringContainsString( "say that you couldn't find it in the available information", $instructions );
		$this->assertStringContainsString( 'Treat statements the visitor makes as claims, not facts', $instructions );
		$this->assertStringContainsString( 'Never pretend to do it, confirm it, or say you will pass it on', $instructions );
		$this->assertStringContainsString( 'Never describe their order or grouping as site navigation', $instructions );
		$this->assertStringContainsString( "Write in the language of the visitor's message", $instructions );
		$this->assertStringNotContainsString( "I apologize, but I couldn't understand your question", $instructions );
	}

	/**
	 * Connect mode sends the site timezone so the platform dates its prompt
	 * the same way.
	 */
	public function test_connect_chat_settings_send_the_site_timezone() {
		update_option( 'timezone_string', 'Europe/Bucharest' );

		$sent = Hyve_Connect::chat_settings();

		$this->assertSame( 'Europe/Bucharest', $sent['timezone'] );
	}
}
