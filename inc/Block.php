<?php
/**
 * Block class.
 * 
 * @package Codeinwp/HyveLite
 */

namespace ThemeIsle\HyveLite;

/**
 * Block class.
 */
class Block {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_block' ] );
		add_shortcode( 'hyve', [ $this, 'render_shortcode' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ], 99 );
	}

	/**
	 * Register block.
	 * 
	 * @return void
	 */
	public function register_block() {
		register_block_type( HYVE_LITE_PATH . '/build/block' );
	}

	/**
	 * Enqueue editor assets.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets() {

		$dashboard_url = add_query_arg(
			[
				'page' => 'hyve',
			],
			admin_url( 'admin.php' ) 
		);

		wp_localize_script(
			'hyve-chat-editor-script',
			'hyveChatBlock',
			[
				'globalChatEnabled' => apply_filters( 'hyve_global_chat_enabled', false ),
				'dashboardURL'      => $dashboard_url,
				'knowledgeBaseURL'  => add_query_arg(
					[
						'nav' => 'data',
					],
					$dashboard_url
				),
				'stats'             => apply_filters( 'hyve_stats', [] ),
			] 
		);
	}

	/**
	 * Render shortcode.
	 * 
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * 
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		if ( isset( $atts['floating'] ) && 'true' === $atts['floating'] ) {
			return do_blocks( '<!-- wp:hyve/chat {"variant":"floating"} /-->' );
		}

		return do_blocks( '<!-- wp:hyve/chat /-->' );
	}
}
