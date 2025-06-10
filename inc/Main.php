<?php
/**
 * Plugin Class.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Block;
use ThemeIsle\HyveLite\Cosine_Similarity;
use ThemeIsle\HyveLite\Threads;
use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Qdrant_API;
use ThemeIsle\HyveLite\Logger;

/**
 * Class Main
 */
class Main {

	/**
	 * Instace of DB_Table class.
	 *
	 * @since 1.2.0
	 * @var DB_Table
	 */
	public $table;

	/**
	 * Instace of API class.
	 *
	 * @since 1.2.0
	 * @var API
	 */
	public $api;

	/**
	 * Instace of Qdrant_API class.
	 *
	 * @since 1.2.0
	 * @var Qdrant_API
	 */
	public $qdrant;

	/**
	 * Main constructor.
	 */
	public function __construct() {
		$this->table  = new DB_Table();
		$this->api    = new API();
		$this->qdrant = new Qdrant_API();

		new Block();
		new Threads();

		add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
		add_action( 'save_post', [ $this, 'update_meta' ], 10, 3 );
		add_action( 'delete_post', [ $this, 'delete_post' ] );
		add_action( 'hyve_weekly_stats', [ $this, 'log_stats' ] );

		if ( Logger::has_consent() && ! wp_next_scheduled( 'hyve_weekly_stats' ) ) {
			wp_schedule_event( time(), 'weekly', 'hyve_weekly_stats' );
		}

		$settings = self::get_settings();

		if (
			isset( $settings['api_key'] ) && isset( $settings['assistant_id'] ) &&
			! empty( $settings['api_key'] ) && ! empty( $settings['assistant_id'] )
		) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		}
	}

	/**
	 * Register menu page.
	 *
	 * @since 1.2.0
	 * 
	 * @return void
	 */
	public function register_menu_page() {
		$page_hook_suffix = add_menu_page(
			__( 'Hyve', 'hyve-lite' ),
			__( 'Hyve', 'hyve-lite' ),
			'manage_options',
			'hyve',
			[ $this, 'menu_page' ],
			'dashicons-format-chat',
			99
		);

		add_action( "admin_print_scripts-$page_hook_suffix", [ $this, 'enqueue_options_assets' ] );
	}

	/**
	 * Menu page.
	 *
	 * @since 1.2.0
	 * 
	 * @return void
	 */
	public function menu_page() {
		?>
		<div id="hyve-options"></div>
		<?php
	}

	/**
	 * Load assets for option page.
	 *
	 * @since 1.2.0
	 * 
	 * @return void
	 */
	public function enqueue_options_assets() {

		// @phpstan-ignore include.fileNotFound
		$asset_file = include HYVE_LITE_PATH . '/build/backend/index.asset.php';

		wp_enqueue_style(
			'hyve-styles',
			HYVE_LITE_URL . 'build/backend/style-index.css',
			[ 'wp-components' ],
			$asset_file['version']
		);

		wp_enqueue_script(
			'hyve-lite-scripts',
			HYVE_LITE_URL . 'build/backend/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_set_script_translations( 'hyve-lite-scripts', 'hyve-lite' );

		$post_types        = get_post_types( [ 'public' => true ], 'objects' );
		$post_types_for_js = [];
	
		foreach ( $post_types as $post_type ) {
			$post_types_for_js[] = [
				'label' => $post_type->labels->name,
				'value' => $post_type->name,
			];
		}

		$settings = self::get_settings();

		wp_localize_script(
			'hyve-lite-scripts',
			'hyve',
			apply_filters(
				'hyve_options_data',
				[
					'api'            => $this->api->get_endpoint(),
					'postTypes'      => $post_types_for_js,
					'hasAPIKey'      => isset( $settings['api_key'] ) && ! empty( $settings['api_key'] ),
					'chunksLimit'    => apply_filters( 'hyve_chunks_limit', 500 ),
					'isQdrantActive' => Qdrant_API::is_active(),
					'assets'         => [
						'images' => HYVE_LITE_URL . 'assets/images/',
					],
					'stats'          => $this->get_stats(),
					'docs'           => 'https://docs.themeisle.com/article/2009-hyve-documentation',
					'qdrant_docs'    => 'https://docs.themeisle.com/article/2066-integrate-hyve-with-qdrant',
					'pro'            => 'https://themeisle.com/plugins/hyve/',
				]
			)
		);

		add_filter( 'themeisle_sdk_blackfriday_data', [ $this, 'add_black_friday_data' ] );
		do_action( 'themeisle_internal_page', HYVE_PRODUCT_SLUG, 'dashboard' );
	}

	/**
	 * Get Default Settings.
	 * 
	 * @since 1.1.0
	 * 
	 * @return array<string, mixed>
	 */
	public static function get_default_settings() {
		return apply_filters(
			'hyve_default_settings',
			[
				'api_key'              => '',
				'qdrant_api_key'       => '',
				'qdrant_endpoint'      => '',
				'chat_enabled'         => true,
				'chat_model'           => 'gpt-4o-mini',
				'temperature'          => 1,
				'top_p'                => 1,
				'moderation_threshold' => [
					'sexual'                 => 80,
					'hate'                   => 70,
					'harassment'             => 70,
					'self-harm'              => 50,
					'sexual/minors'          => 50,
					'hate/threatening'       => 60,
					'violence/graphic'       => 80,
					'self-harm/intent'       => 50,
					'self-harm/instructions' => 50,
					'harassment/threatening' => 60,
					'violence'               => 70,
				],
				'welcome_message'      => '',
				'default_message'      => '',
			]
		);
	}

	/**
	 * Get Settings.
	 * 
	 * @since 1.1.0
	 * 
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$settings = get_option( 'hyve_settings', [] );
		return wp_parse_args( $settings, self::get_default_settings() );
	}

	/**
	 * Add the translatable label to the default value.
	 * 
	 * Use this in a context where translations are correctly loaded.
	 * 
	 * @since 1.2
	 * 
	 * @return void
	 */
	public static function add_labels_to_default_settings() {
		add_filter(
			'hyve_default_settings',
			function ( $settings ) {
				if ( ! is_array( $settings ) ) {
					return $settings;
				}

				$settings['welcome_message'] = __( 'Hello! How can I help you today?', 'hyve-lite' );
				$settings['default_message'] = __( 'Sorry, I\'m not able to help with that.', 'hyve-lite' );

				return $settings;
			}
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 1.2.0
	 * 
	 * @return void
	 */
	public function enqueue_assets() {
		if ( is_admin() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		// @phpstan-ignore include.fileNotFound
		$asset_file = include HYVE_LITE_PATH . '/build/frontend/frontend.asset.php';

		wp_register_style(
			'hyve-styles',
			HYVE_LITE_URL . 'build/frontend/style-index.css',
			[],
			$asset_file['version']
		);

		wp_register_script(
			'hyve-lite-scripts',
			HYVE_LITE_URL . 'build/frontend/frontend.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_set_script_translations( 'hyve-lite-scripts', 'hyve-lite' );

		self::add_labels_to_default_settings();
		$settings = self::get_settings();

		wp_localize_script(
			'hyve-lite-scripts',
			'hyveClient',
			apply_filters(
				'hyve_frontend_data',
				[
					'api'       => $this->api->get_endpoint(),
					'audio'     => [
						'click' => HYVE_LITE_URL . 'assets/audio/click.mp3',
						'ping'  => HYVE_LITE_URL . 'assets/audio/ping.mp3',
					],
					'welcome'   => esc_html( $settings['welcome_message'] ?? '' ),
					'isEnabled' => $settings['chat_enabled'],
					'strings'   => [
						'reply'             => __( 'Write a reply…', 'hyve-lite' ),
						'suggestions'       => __( 'Not sure where to start?', 'hyve-lite' ),
						'tryAgain'          => __( 'Sorry, I am not able to process your request at the moment. Please try again.', 'hyve-lite' ),
						'typing'            => __( 'Typing…', 'hyve-lite' ),
						'clearConversation' => __( 'Clear Conversation', 'hyve-lite' ),
					],
					'icons'     => [
						'chat-bubble-oval-left'          => esc_url( HYVE_LITE_URL . 'assets/icons/chat-bubble-oval-left.svg' ),
						'chat-bubble-bottom-center-text' => esc_url( HYVE_LITE_URL . 'assets/icons/chat-bubble-bottom-center-text.svg' ),
						'chat-bubble-bottom-center'      => esc_url( HYVE_LITE_URL . 'assets/icons/chat-bubble-bottom-center.svg' ),
						'chat-bubble-left'               => esc_url( HYVE_LITE_URL . 'assets/icons/chat-bubble-left.svg' ),
						'chat-bubble-left-right'         => esc_url( HYVE_LITE_URL . 'assets/icons/chat-bubble-left-right.svg' ),
					],
				]
			)
		);

		if ( ! isset( $settings['chat_enabled'] ) || false === $settings['chat_enabled'] ) {
			return;
		}

		wp_enqueue_style( 'hyve-styles' );
		wp_enqueue_script( 'hyve-lite-scripts' );

		$has_pro = apply_filters( 'product_hyve_license_status', false );

		if ( $has_pro ) {
			return;
		}

		wp_add_inline_script(
			'hyve-lite-scripts',
			'document.addEventListener("DOMContentLoaded", function() { const c = document.createElement("div"); c.className = "hyve-credits"; c.innerHTML = "<a href=\"https://themeisle.com/plugins/hyve/\" target=\"_blank\">Powered by Hyve</a>"; document.querySelector( ".hyve-input-box" ).before( c ); });'
		);
	}

	/**
	 * Get stats.
	 *
	 * @since 1.3.0
	 * 
	 * @return array<string, mixed>
	 */
	public function get_stats() {
		return [
			'threads'     => Threads::get_thread_count(),
			'messages'    => Threads::get_messages_count(),
			'totalChunks' => $this->table->get_count(),
		];
	}

	/**
	 * Log stats.
	 * 
	 * @since 1.3.0
	 * 
	 * @return void
	 */
	public function log_stats() {
		Logger::track(
			[
				[
					'feature'          => 'system',
					'featureComponent' => 'stats',
					'featureValue'     => $this->get_stats(),
				],
			]
		);
	}

	/**
	 * Update meta.
	 * 
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an existing post being updated.
	 *
	 * @since 1.2.0
	 * 
	 * @return void
	 */
	public function update_meta( $post_id, $post, $update ) {
		if (
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			! $update ||
			isset( $_REQUEST['bulk_edit'] ) || isset( $_REQUEST['_inline_edit'] ) // phpcs:ignore WordPress.Security.NonceVerification
		) {
			return;
		}

		$added = get_post_meta( $post_id, '_hyve_added', true );

		if ( ! $added ) {
			return;
		}

		update_post_meta( $post_id, '_hyve_needs_update', 1 );
		delete_post_meta( $post_id, '_hyve_moderation_failed' );
		delete_post_meta( $post_id, '_hyve_moderation_review' );

		wp_schedule_single_event( time(), 'hyve_update_posts' );
	}

	/**
	 * Delete post.
	 * 
	 * @param int $post_id Post ID.
	 *
	 * @since 1.2.0
	 * 
	 * @return void
	 */
	public function delete_post( $post_id ) {
		$this->table->delete_by_post_id( $post_id );

		if ( Qdrant_API::is_active() ) {
			$this->qdrant->delete_point( $post_id );
		}
	}

	/**
	 * Set Black Friday data.
	 *
	 * @param array<string, mixed> $configs The configuration array for the loaded products.
	 *
	 * @return array<string, mixed>
	 */
	public function add_black_friday_data( $configs ) {
		$config = $configs['default'];

		// translators: %1$s - HTML tag, %2$s - discount, %3$s - HTML tag, %4$s - product name.
		$message_template = __( 'Our biggest sale of the year: %1$sup to %2$s OFF%3$s on %4$s. Don\'t miss this limited-time offer.', 'hyve-lite' );
		$product_label    = 'Hyve';
		$discount         = '70%';
		
		$plan    = apply_filters( 'product_hyve_license_plan', 0 );
		$license = apply_filters( 'product_hyve_license_key', false );
		$is_pro  = 0 < $plan;

		if ( $is_pro ) {
			// translators: %1$s - HTML tag, %2$s - discount, %3$s - HTML tag, %4$s - product name.
			$message_template = __( 'Get %1$sup to %2$s off%3$s when you upgrade your %4$s plan or renew early.', 'hyve-lite' );
			$product_label    = 'Hyve Pro';
			$discount         = '30%';
		}
		
		$product_label = sprintf( '<strong>%s</strong>', $product_label );
		$url_params    = [
			'utm_term' => $is_pro ? 'plan-' . $plan : 'free',
			'lkey'     => ! empty( $license ) ? $license : false,
		];
		
		$config['message']  = sprintf( $message_template, '<strong>', $discount, '</strong>', $product_label );
		$config['sale_url'] = add_query_arg(
			$url_params,
			tsdk_translate_link( tsdk_utmify( 'https://themeisle.link/hyve-bf', 'bfcm', 'hyve' ) )
		);

		$configs[ HYVE_PRODUCT_SLUG ] = $config;

		return $configs;
	}
}
