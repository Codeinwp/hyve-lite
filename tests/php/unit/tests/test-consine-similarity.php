<?php
/**
 * Tests for Cosine Similarity Calculator.
 *
 * @package Codeinwp\HyveLite
 */

use ThemeIsle\HyveLite\Cosine_Similarity;

/**
 * Class Test_Cosine_Similarity.
 */
class Test_Cosine_Similarity extends WP_UnitTestCase {
	/**
	 * Test the dot product calculation.
	 */
	public function test_dot_product() {
		$vector_a = [ 1, 2, 3 ];
		$vector_b = [ 4, 5, 6 ];
		$expected = 32.0; // 1*4 + 2*5 + 3*6.
		$result   = $this->invoke_rrivate_method( 'dot_product', $vector_a, $vector_b );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Test the magnitude calculation.
	 */
	public function test_magnitude() {
		$vector   = [ 1, 2, 3 ];
		$expected = sqrt( 14 ); // sqrt(1^2 + 2^2 + 3^2).
		$result   = $this->invoke_rrivate_method( 'magnitude', $vector );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Test the cosine similarity calculation.
	 */
	public function test_calculate() {
		$vector_a = [ 1, 2, 3 ];
		$vector_b = [ 4, 5, 6 ];
		$expected = 32.0 / ( sqrt( 14 ) * sqrt( 77 ) ); // dot_product / (magnitude_a * magnitude_b).
		$result   = Cosine_Similarity::calculate( $vector_a, $vector_b );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Test cosine similarity with zero magnitude vector.
	 */
	public function test_calculate_with_zero_magnitude() {
		$vector_a = [ 0, 0, 0 ];
		$vector_b = [ 4, 5, 6 ];
		$expected = 0.0; // Should return 0.0 due to zero magnitude.
		$result   = Cosine_Similarity::calculate( $vector_a, $vector_b );
		$this->assertEquals( $expected, $result );
	}

	/**
	 * Helper method to invoke private methods for testing.
	 *
	 * @param string $method_name The name of the private method.
	 * @param mixed  ...$args The arguments to pass to the method.
	 * @return mixed The result of the method call.
	 */
	private function invoke_rrivate_method( string $method_name, ...$args ) {
		$reflection = new \ReflectionClass( Cosine_Similarity::class );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( null, $args );
	}
}
