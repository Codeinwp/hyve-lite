<?php
/**
 * Backfill the Qdrant payload indexes used by background ingestion.
 *
 * Staged imports hide and replace points by `_hyve_ingestion_pending` and
 * `_hyve_ingestion_generation`. Hosted clusters refuse filters on fields
 * without a keyword index, so an existing collection needs them before the
 * search filter can run. New connections get them from Qdrant_API::init().
 *
 * @package Codeinwp\HyveLite
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return new class() extends \ThemeisleSDK\Modules\Abstract_Migration {

	/**
	 * Only relevant when Qdrant is connected.
	 *
	 * @return bool
	 */
	public function should_run() {
		return \ThemeIsle\HyveLite\Qdrant_API::is_active();
	}

	/**
	 * Create the indexes. Throws on failure so the migrator retries later.
	 *
	 * @return void
	 * @throws \Exception If the indexes could not be ensured.
	 */
	public function up() {
		$result = \ThemeIsle\HyveLite\Qdrant_API::instance()->ensure_payload_index();

		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
	}
};
