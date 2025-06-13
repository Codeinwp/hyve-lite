<?php
/**
 * Cosine Similarity Calculator.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Class Cosine_Similarity
 */
class Cosine_Similarity {
	/**
	 * Calculates the dot product of two vectors.
	 *
	 * @param float[] $vector_a First vector.
	 * @param float[] $vector_b Second vector.
	 * @return float The dot product of the two vectors.
	 */
	public static function dot_product( array $vector_a, array $vector_b ): float {
		$len = min( count( $vector_a ), count( $vector_b ) );
		$sum = 0.0;

		for ( $idx = 0; $idx < $len; $idx++ ) {
			$sum += $vector_a[ $idx ] * $vector_b[ $idx ];
		}
		return $sum;
	}

	/**
	 * Calculates the magnitude (length) of a vector.
	 *
	 * @param float[] $vector The vector to calculate the magnitude of.
	 * @return float The magnitude of the vector.
	 */
	public static function magnitude( array $vector ): float {
		$sum = 0.0;
		foreach ( $vector as $component ) {
			$sum += pow( $component, 2 );
		}
		return sqrt( $sum );
	}

	/**
	 * Calculate the similarity score.
	 * 
	 * @param float $dot_product The dot product of the two vectors.
	 * @param float $magnitude_a The magnitude of the first vector.
	 * @param float $magnitude_b The magnitude of the second vector.
	 * 
	 * @return float The cosine similarity between the two vectors.
	 */
	public static function similarity( $dot_product, $magnitude_a, $magnitude_b ) {
		if ( 0.0 === $magnitude_a || 0.0 === $magnitude_b ) {
			return 0.0;
		} 

		return $dot_product / ( $magnitude_a * $magnitude_b );
	}

	/**
	 * Calculates the cosine similarity between two vectors.
	 *
	 * @param float[] $vector_a First vector.
	 * @param float[] $vector_b Second vector.
	 * @return float The cosine similarity between the two vectors.
	 */
	public static function calculate( array $vector_a, array $vector_b ): float {
		$dot_product = self::dot_product( $vector_a, $vector_b );
		$magnitude_a = self::magnitude( $vector_a );
		$magnitude_b = self::magnitude( $vector_b );

		return self::similarity( $dot_product, $magnitude_a, $magnitude_b );
	}
}
