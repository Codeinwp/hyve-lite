<?php
/**
 * Vocab Loader.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

use guttedgarden\Tiktoken\Vocab\Vocab;
use guttedgarden\Tiktoken\Vocab\VocabLoader;

/**
 * Fetches the tokenizer vocabulary through the WordPress HTTP API.
 *
 * The library's own loader uses `fopen()` on the remote URI and needs a
 * writable temp directory, so hosts with `allow_url_fopen` off or a locked
 * temp directory could never tokenize (Codeinwp/hyve-lite#241). This one
 * streams the download into the first writable cache directory it is given
 * and, when there is none, buffers it in memory instead.
 */
class Vocab_Loader implements VocabLoader {
	/**
	 * Directories to cache in, in order of preference.
	 *
	 * @var list<string>
	 */
	private $cache_dirs;

	/**
	 * Constructor.
	 *
	 * @param list<string>|null $cache_dirs Directories to cache in. Default the temp directory, then uploads.
	 */
	public function __construct( ?array $cache_dirs = null ) {
		$this->cache_dirs = null === $cache_dirs
			? [ untrailingslashit( get_temp_dir() ), wp_get_upload_dir()['basedir'] . '/hyve-lite' ]
			: $cache_dirs;
	}

	/**
	 * Load a vocabulary.
	 *
	 * @param string      $uri      The vocabulary URI.
	 * @param string|null $checksum Expected sha256 of the file.
	 *
	 * @return Vocab
	 * @throws \RuntimeException When the vocabulary cannot be downloaded or fails its checksum.
	 */
	public function load( string $uri, ?string $checksum = null ): Vocab {
		// Same cache file name as the library's loader, so an existing cache is reused.
		$name = sha1( $uri );

		foreach ( $this->cache_dirs as $dir ) {
			$file = $dir . '/' . $name;

			if ( is_readable( $file ) && $this->matches( $file, $checksum ) ) {
				return Vocab::fromFile( $file );
			}
		}

		foreach ( $this->cache_dirs as $dir ) {
			if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				continue;
			}

			return Vocab::fromFile( $this->download_to( $uri, $checksum, $dir . '/' . $name ) );
		}

		return $this->download_to_memory( $uri, $checksum );
	}

	/**
	 * Stream the vocabulary into a cache file. Streaming is the fast path:
	 * buffering a file this size through the HTTP API takes minutes.
	 *
	 * @param string           $uri      The vocabulary URI.
	 * @param string|null      $checksum Expected sha256 of the file.
	 * @param non-empty-string $file     Where to cache it.
	 *
	 * @return non-empty-string The cache file.
	 * @throws \RuntimeException When the download fails or does not match the checksum.
	 */
	private function download_to( string $uri, ?string $checksum, string $file ): string {
		// Written beside the final name, so a reader never sees a partial file.
		$partial = $file . '.' . wp_generate_password( 8, false ) . '.part';

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Failure is handled below.
		$response = wp_remote_get(
			$uri,
			[
				'timeout'  => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- A 1.7 MB file fetched once, then cached.
				'stream'   => true,
				'filename' => $partial,
			]
		);

		try {
			$this->check( $uri, $response );

			if ( ! is_readable( $partial ) || ! $this->matches( $partial, $checksum ) ) {
				throw new \RuntimeException( sprintf( 'Checksum failed for the vocabulary %s', $uri ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- The temp or uploads directory, resolved by WordPress.
			if ( ! rename( $partial, $file ) ) {
				throw new \RuntimeException( sprintf( 'Could not write the vocabulary cache %s', $file ) );
			}
		} finally {
			if ( file_exists( $partial ) ) {
				wp_delete_file( $partial );
			}
		}

		return $file;
	}

	/**
	 * Buffer the vocabulary in memory. The slow path, for a site that can write
	 * nowhere: it downloads again on every request.
	 *
	 * @param string      $uri      The vocabulary URI.
	 * @param string|null $checksum Expected sha256 of the file.
	 *
	 * @return Vocab
	 * @throws \RuntimeException When the download fails or does not match the checksum.
	 */
	private function download_to_memory( string $uri, ?string $checksum ): Vocab {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- The last resort, see above.
		$response = wp_remote_get( $uri, [ 'timeout' => 120 ] );

		$this->check( $uri, $response );

		$body = (string) wp_remote_retrieve_body( $response );

		if ( null !== $checksum && ! hash_equals( $checksum, hash( 'sha256', $body ) ) ) {
			throw new \RuntimeException( sprintf( 'Checksum failed for the vocabulary %s', $uri ) );
		}

		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.

		if ( false === $stream ) {
			throw new \RuntimeException( 'Could not open a memory stream for the vocabulary.' );
		}

		fwrite( $stream, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- In-memory stream.
		rewind( $stream );

		try {
			return Vocab::fromStream( $stream );
		} finally {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream.
		}
	}

	/**
	 * Throw unless the download succeeded.
	 *
	 * @param string                         $uri      The vocabulary URI.
	 * @param array<string, mixed>|\WP_Error $response What wp_remote_get() returned.
	 *
	 * @return void
	 * @throws \RuntimeException When the request failed or was not answered with 200.
	 */
	private function check( string $uri, $response ): void {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( sprintf( 'Could not download the vocabulary %s: %s', $uri, $response->get_error_message() ) );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( sprintf( 'Could not download the vocabulary %s: HTTP %s', $uri, wp_remote_retrieve_response_code( $response ) ) );
		}
	}

	/**
	 * Whether a file has the expected checksum.
	 *
	 * @param string      $file     Path to the file.
	 * @param string|null $checksum Expected sha256, or null to accept any.
	 *
	 * @return bool
	 */
	private function matches( string $file, ?string $checksum ): bool {
		return null === $checksum || hash_equals( $checksum, (string) hash_file( 'sha256', $file ) );
	}
}
