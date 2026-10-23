<?php
/**
 * Bounded HTTP transport for Qdrant.
 *
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

use GuzzleHttp\Client;
use Qdrant\Config;
use Qdrant\Http\GuzzleClient;

/**
 * Keep Qdrant requests shorter than the migration lock's lifetime.
 */
class Qdrant_HTTP_Client extends GuzzleClient {

	/**
	 * Maximum request duration in seconds.
	 *
	 * @var int
	 */
	public const REQUEST_TIMEOUT = 60;

	/**
	 * Configure the transport; the upstream constructor exposes no timeouts.
	 *
	 * @param Config $config Qdrant connection configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
		$this->client = new Client(
			[
				'base_uri'        => $config->getDomain(),
				'http_errors'     => true,
				'timeout'         => self::REQUEST_TIMEOUT,
				'connect_timeout' => 10,
			]
		);
	}
}
