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

		add_filter( 'hyve_global_chat_enabled', [ $this, 'is_global_chat_enabled' ] );
		add_filter( 'hyve_stats', [ $this, 'get_stats' ] );
		add_filter( 'hyve_options_data', [ $this, 'append_services_error' ] );
		add_filter( 'hyve_similarity_score_threshold', [ $this, 'get_similarity_threshold_score' ] );

		if ( Logger::has_consent() && ! wp_next_scheduled( 'hyve_weekly_stats' ) ) {
			wp_schedule_event( time(), 'weekly', 'hyve_weekly_stats' );
		}

		$settings = self::get_settings();

		if ( isset( $settings['post_row_addon_enabled'] ) && $settings['post_row_addon_enabled'] && current_user_can( 'manage_options' ) ) {
			add_action( 'hyve_register_post_type_row_action_knowledge_base', [ $this, 'register_row_action_filter_shortcut' ] );

			do_action( 'hyve_register_post_type_row_action_knowledge_base', 'post' );
			do_action( 'hyve_register_post_type_row_action_knowledge_base', 'page' );

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_addons_assets' ] );
		}

		if (
			isset( $settings['api_key'] ) && isset( $settings['assistant_id'] ) &&
			! empty( $settings['api_key'] ) && ! empty( $settings['assistant_id'] )
		) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		}

		if ( ! defined( 'E2E_TESTING' ) ) {
			add_filter(
				'themeisle-sdk/survey/' . HYVE_PRODUCT_SLUG,
				function ( $data, $page_slug ) {
					if ( empty( $page_slug ) ) {
						return $data;
					}
					return $this->get_survey_data();
				},
				10,
				2
			);
		}

		add_action( 'admin_init', [ $this, 'admin_init' ] );
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
	 * Init hooks on admin stage.
	 * 
	 * @return void
	 */
	public function admin_init() {
		$settings = self::get_settings();

		$post_types        = get_post_types( [ 'public' => true ], 'objects' );
		$post_types_for_js = [];
	
		foreach ( $post_types as $post_type ) {
			$post_types_for_js[] = [
				'label' => $post_type->labels->name,
				'value' => $post_type->name,
			];
		}

		add_filter(
			'hyve_options_data',
			function ( $data ) use ( $settings, $post_types_for_js ) {
				return array_merge(
					$data,
					[
						'api'            => $this->api->get_endpoint(),
						'rest_url'       => rest_url( $this->api->get_endpoint() ),
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
						'chart'          => $this->get_chart_data(),
						'hasPro'         => apply_filters( 'product_hyve_license_status', false ),
					]
				);
			},
			9
		);
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

		wp_localize_script(
			'hyve-lite-scripts',
			'hyve',
			apply_filters( 'hyve_options_data', [] )
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
				'api_key'                    => '',
				'qdrant_api_key'             => '',
				'qdrant_endpoint'            => '',
				'chat_enabled'               => true,
				'chat_model'                 => 'gpt-4o-mini',
				'temperature'                => 1,
				'top_p'                      => 1,
				'moderation_threshold'       => [
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
				'welcome_message'            => '',
				'default_message'            => '',
				'similarity_score_threshold' => 0.4,
				'post_row_addon_enabled'     => true,
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
		$stats    = $this->get_stats();
		
		/**
		 * Filters whether the chat should be displayed.
		 *
		 * @since 1.4.0
		 *
		 * @param bool $should_show_chat Whether to display the chat. Default true if totalChunks > 0.
		 */
		$should_show_chat = apply_filters( 'hyve_display_chat', 0 < intval( $stats['totalChunks'] ) );

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
					'canShow'   => $should_show_chat,
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
	 * Load assets for option page.
	 * 
	 * @param string $hook The name of the page hook.
	 *
	 * @since 1.4.0
	 * 
	 * @return void
	 */
	public function enqueue_addons_assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		/**
		 * Check if the row actions addon can be loaded for the current post type in `edit.php`.
		 *
		 * @since 1.4.0
		 *
		 * @param bool $registered Whether the post type has registered the row actions addon.
		 */
		$registered_post_type = apply_filters( 'hyve_register_row_action_for_' . $screen->post_type, false );

		if ( ! $registered_post_type ) {
			return;
		}

		// @phpstan-ignore include.fileNotFound
		$asset_file = include HYVE_LITE_PATH . '/build/addons/index.asset.php';
		wp_enqueue_script(
			'hyve-lite-addons',
			HYVE_LITE_URL . 'build/addons/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_set_script_translations( 'hyve-lite-addons', 'hyve-lite' );

		wp_localize_script(
			'hyve-lite-addons',
			'hyveAddons',
			[
				'api' => $this->api->get_endpoint(),
			]
		);
	}

	/**
	 * Register the Knowledge Base row action shortcuts for the given post type.
	 * 
	 * @param string|mixed $post_type The post type.
	 * 
	 * @return void
	 */
	public function register_row_action_filter_shortcut( $post_type ) {
		if ( ! is_string( $post_type ) ) {
			return;
		}

		add_filter( $post_type . '_row_actions', [ $this, 'add_to_knowledge_base_row_action' ], 10, 2 );
		add_filter( 'hyve_register_row_action_for_' . $post_type, '__return_true' );
	}

	/**
	 * Add shortcut via post row actions for adding/removing posts from the Knowledge Base
	 * 
	 * @param array<string, mixed> $actions The row actions.
	 * @param \WP_Post             $post The post object.
	 * 
	 * @return array<string, mixed>
	 */
	public function add_to_knowledge_base_row_action( $actions, $post ) {
		if ( get_post_meta( $post->ID, '_hyve_post_processing', true ) ) {
			$actions['hyve_knowledge_base_processing'] = __( 'Hyve is processing the post', 'hyve-lite' );
			return $actions;
		}
		
		$label  = __( 'Add to Hyve', 'hyve-lite' );
		$action = 'add';
		$class  = '';

		if ( get_post_meta( $post->ID, '_hyve_added', true ) ) {
			$label  = __( 'Remove from Hyve', 'hyve-lite' );
			$action = 'delete';
			$class  = 'button-link-delete';
		}
		
		$actions['add_to_hyve_knowledge_base'] = '<button type="button" data-action="' . $action . '" data-post-id="' . $post->ID . '" class="hyve-row-action-btn button-link ' . $class . '" aria-expanded="false">' . $label . '</button>';

		return $actions;
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
	 * Check if the Chat is enabled globally on all the pages.
	 * 
	 * @return boolean True if the chat is enabled.
	 */
	public function is_global_chat_enabled() {
		$settings = self::get_settings();
		if ( ! isset( $settings['chat_enabled'] ) ) {
			return false;
		}

		return boolval( $settings['chat_enabled'] );
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

	/**
	 * Get chart data.
	 * 
	 * @return array{legend: array{messagesLabel: string, sessionsLabel: string}, data: array{messages: array<int>, sessions: array<int>}, labels: array<string>} The chart data.
	 */
	public function get_chart_data() {
		$data = Threads::get_chart_datasets();
	
		$labels = array_map(
			function ( $date ) {
				return date_i18n(
				// translators: the date format for displaying the chart labels. The value associated with the label is the number of messages per day from users.
					__( 'M j', 'hyve-lite' ),
					strtotime( $date )
				);
			},
			$data['labels']
		);

		return [
			'legend' => [
				'messagesLabel' => _x( 'User Messages per Day', 'chart legend label', 'hyve-lite' ),
				'sessionsLabel' => _x( 'Active Sessions per Day', 'chart legend label', 'hyve-lite' ),
			],
			'data'   => [
				'messages' => $data['messages'],
				'sessions' => $data['sessions'],
			],
			'labels' => $labels,
		];
	}

	/**
	 * Append services errors if they exists.
	 * 
	 * @param mixed|array<string, mixed> $options The dashboard options.
	 * 
	 * @return mixed|array<string, mixed>
	 */
	public function append_services_error( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}

		$errors = [];

		$open_ai_last_error = get_option( OpenAI::ERROR_OPTION_KEY, false );
		if ( is_array( $open_ai_last_error ) ) {
			$errors[] = $open_ai_last_error;
		}

		$qdrant_last_error = get_option( Qdrant_API::ERROR_OPTION_KEY, false );
		if ( is_array( $qdrant_last_error ) ) {
			$qdrant_last_error['message'] = __( 'Invalid credentials.', 'hyve-lite' ) . ' ' . __( 'Please check your API key and endpoint URL.', 'hyve-lite' );
			$errors[]                     = $qdrant_last_error;
		}

		if ( ! empty( $errors ) ) {
			$options['serviceErrors'] = $errors;
		}

		return $options;
	}

	/**
	 * Get the data for Formbricks survey.
	 * 
	 * @return array<string, mixed> The survey data.
	 */
	public function get_survey_data() {

		$options           = apply_filters( 'hyve_options_data', [] );
		$install_time_free = get_option( 'hyve_lite_install', time() );
		$install_time_pro  = get_option( 'hyve_install', time() );
		$settings          = self::get_settings();

		$license_status     = apply_filters( 'product_hyve_license_status', 'invalid' );
		$days_since_install = round( ( time() - min( $install_time_free, $install_time_pro ) ) / DAY_IN_SECONDS );
		
		$survey_data = [
			'environmentId' => 'cmbtdc5s8s7pkuk014jwixs7n',
			'attributes'    => [
				'free_version'              => HYVE_LITE_VERSION,
				'pro_version'               => defined( 'HYVE_VERSION' ) ? HYVE_VERSION : '',
				'install_days_number'       => $days_since_install,
				'license_status'            => $license_status,
				'is_openai_active'          => $options['hasAPIKey'],
				'is_qdrant_active'          => $options['isQdrantActive'],
				'stats_messages'            => $options['stats']['messages'],
				'stats_threads'             => $options['stats']['threads'],
				'stats_total_chunks'        => $options['stats']['totalChunks'],
				'openai_chat_model'         => $settings['chat_model'],
				'chat_on_all_pages_enabled' => $settings['chat_enabled'],
			],
		];

		if ( 'valid' === $license_status ) {
			$survey_data['attributes']['license_key'] = apply_filters( 'themeisle_sdk_secret_masking', apply_filters( 'product_hyve_license_key', '' ) );
		}

		return $survey_data;
	}

	/**
	 * Get the similarity threshold score for Cosine Similarity.
	 * 
	 * @return float The threshold.
	 */
	public function get_similarity_threshold_score() {
		$settings = self::get_settings();

		if ( isset( $settings['similarity_score_threshold'] ) && is_numeric( $settings['similarity_score_threshold'] ) ) {
			return floatval( $settings['similarity_score_threshold'] );
		}

		return 0.4;
	}
}
