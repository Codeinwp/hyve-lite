<?php
/**
 * Encryption helper.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Encrypts sensitive values stored by Hyve.
 */
class Encryption {
	const PREFIX           = 'hyve:v1:';
	const CIPHER           = 'aes-256-gcm';
	const KEY_CHECK_OPTION = 'hyve_encryption_key_check';
	const KEY_CHECK_VALUE  = 'hyve_enc_test';
	const AUTH_TAG_LENGTH  = 16;

	/**
	 * Encrypt a value using the WordPress secure authentication key.
	 *
	 * @param mixed $value Plaintext value.
	 * @return string|false Encrypted value, or false on failure.
	 */
	public static function encrypt( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		if ( '' === $value || self::is_encrypted( $value ) ) {
			return $value;
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );

		if ( false === $iv_length || $iv_length < 1 ) {
			return false;
		}

		try {
			$iv = random_bytes( $iv_length );
		} catch ( \Exception $exception ) {
			return false;
		}

		$tag        = '';
		$ciphertext = openssl_encrypt( $value, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::AUTH_TAG_LENGTH );

		if ( false === $ciphertext ) {
			return false;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt an encrypted value. Legacy plaintext is returned unchanged.
	 *
	 * @param mixed $value Stored value.
	 * @return string|false Plaintext value, or false when encrypted data cannot be decrypted.
	 */
	public static function decrypt( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		$encoded = substr( $value, strlen( self::PREFIX ) );
		$payload = base64_decode( $encoded, true );
		$iv_size = openssl_cipher_iv_length( self::CIPHER );

		if ( false === $payload || false === $iv_size || strlen( $payload ) <= $iv_size + self::AUTH_TAG_LENGTH ) {
			return false;
		}

		$iv         = substr( $payload, 0, $iv_size );
		$tag        = substr( $payload, $iv_size, self::AUTH_TAG_LENGTH );
		$ciphertext = substr( $payload, $iv_size + self::AUTH_TAG_LENGTH );

		return openssl_decrypt( $ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
	}

	/**
	 * Determine whether a value uses Hyve's encrypted storage format.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return false;
		}

		$encoded = substr( $value, strlen( self::PREFIX ) );
		$payload = base64_decode( $encoded, true );
		$iv_size = openssl_cipher_iv_length( self::CIPHER );

		return false !== $payload && false !== $iv_size && strlen( $payload ) > $iv_size + self::AUTH_TAG_LENGTH;
	}

	/**
	 * Create the encrypted key check marker if this is a new installation.
	 *
	 * @return bool Whether the active key is usable.
	 */
	public static function ensure_key_check() {
		$check = get_option( self::KEY_CHECK_OPTION, '' );

		if ( '' === $check ) {
			$encrypted = self::encrypt( self::KEY_CHECK_VALUE );

			return false !== $encrypted && update_option( self::KEY_CHECK_OPTION, $encrypted );
		}

		return self::KEY_CHECK_VALUE === self::decrypt( $check );
	}

	/**
	 * Check whether the configured encryption key has changed.
	 *
	 * @return bool
	 */
	public static function has_key_changed() {
		$check = get_option( self::KEY_CHECK_OPTION, '' );

		return '' !== $check && self::KEY_CHECK_VALUE !== self::decrypt( $check );
	}

	/**
	 * Clear a changed-key marker once every plugin has recovered its secrets.
	 *
	 * @return bool Whether the marker is valid or was successfully recovered.
	 */
	public static function maybe_reset_key_check() {
		if ( ! self::has_key_changed() ) {
			return self::ensure_key_check();
		}

		$can_reset = apply_filters( 'hyve_encryption_key_check_can_reset', true );

		if ( ! $can_reset ) {
			return false;
		}

		delete_option( self::KEY_CHECK_OPTION );

		return self::ensure_key_check();
	}

	/**
	 * Derive a fixed-length encryption key.
	 *
	 * @return string
	 */
	private static function key() {
		$key = defined( 'SECURE_AUTH_KEY' ) ? constant( 'SECURE_AUTH_KEY' ) : wp_salt( 'secure_auth' );

		if ( ! is_string( $key ) ) {
			$key = wp_salt( 'secure_auth' );
		}

		return hash( 'sha256', $key, true );
	}
}
