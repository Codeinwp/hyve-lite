<?php
/**
 * Logger Class.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Class Logger
 */
class Logger {

	/**
	 * Tracking URL.
	 *
	 * @var string
	 */
	const TRACK_URL = 'https://api.themeisle.com/tracking/events';

	/**
	 * Send data to the server if the user has opted in.
	 *
	 * @param array $events  Data to track.
	 * @return void
	 */
	public static function track( $events ) {
		if ( ! self::has_consent() ) {
			return;
		}

		try {
			$payload = [];

			$license = apply_filters( 'product_hyve_license_key', 'free' );

			if ( 'free' !== $license ) {
				$license = wp_hash( $license );
			}

			foreach ( $events as $event ) {
				$payload[] = [
					'slug'    => 'hyve',
					'site'    => get_site_url(),
					'license' => $license,
					'data'    => $event,
				];
			}

			$args = [
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			];

			wp_remote_post( self::TRACK_URL, $args );
		} finally {
			return;
		}
	}

	/**
	 * Check if the user has consented to tracking.
	 *
	 * @return bool
	 */
	public static function has_consent() {
		return 'yes' === get_option( 'hyve_lite_logger_flag', false ) || defined( 'HYVE_BASEFILE' );
	}
}
