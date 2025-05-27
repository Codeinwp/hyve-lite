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
	private static function dot_product( array $vector_a, array $vector_b ): float {
		$sum = 0.0;
		foreach ( $vector_a as $key => $value ) {
			if ( isset( $vector_b[ $key ] ) ) {
				$sum += $value * $vector_b[ $key ];
			}
		}
		return $sum;
	}

	/**
	 * Calculates the magnitude (length) of a vector.
	 *
	 * @param float[] $vector The vector to calculate the magnitude of.
	 * @return float The magnitude of the vector.
	 */
	private static function magnitude( array $vector ): float {
		$sum = 0.0;
		foreach ( $vector as $component ) {
			$sum += pow( $component, 2 );
		}
		return sqrt( $sum );
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

		// To prevent division by zero.
		if ( 0.0 === $magnitude_a || 0.0 === $magnitude_b ) {
			return 0.0;
		}

		return $dot_product / ( $magnitude_a * $magnitude_b );
	}
}
