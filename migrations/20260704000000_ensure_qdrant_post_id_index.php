<?php
/**
 * Backfill the Qdrant `post_id` payload index on existing connections.
 *
 * Qdrant requires a keyword index on a payload field before it can be used in a
 * filter. Collections created before the index was introduced cannot delete or
 * update points by post_id (the request 400s), leaving vectors orphaned. New
 * connections get the index from Qdrant_API::init(); this backfills the ones
 * that were already connected when they upgrade.
 *
 * @package Codeinwp\HyveLite
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return new class() extends \ThemeisleSDK\Modules\Abstract_Migration {

	/**
	 * Only relevant when Qdrant is connected; a fresh connect creates the index
	 * on its own, so there is nothing to backfill without an active integration.
	 *
	 * @return bool
	 */
	public function should_run() {
		return \ThemeIsle\HyveLite\Qdrant_API::is_active();
	}

	/**
	 * Create the post_id payload index.
	 *
	 * Throws on failure (e.g. Qdrant briefly unreachable) so the migrator leaves
	 * it unrecorded and retries on the next upgrade rather than marking a broken
	 * collection as done.
	 *
	 * @return void
	 * @throws \Exception If the index could not be ensured.
	 */
	public function up() {
		$result = \ThemeIsle\HyveLite\Qdrant_API::instance()->ensure_payload_index();

		if ( is_wp_error( $result ) ) {
			throw new \Exception( esc_html( $result->get_error_message() ) );
		}
	}
};
