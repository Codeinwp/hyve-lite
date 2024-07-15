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
		add_action( 'init', array( $this, 'register_block' ) );
		add_shortcode( 'hyve', array( $this, 'render_shortcode' ) );
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
	 * Render shortcode.
	 * 
	 * @param array $atts Shortcode attributes.
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
