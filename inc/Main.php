<?php
/**
 * Plugin Class.
 *
 * @package Codeinwp\HyveLite
 */

namespace ThemeIsle\HyveLite;

use ThemeIsle\HyveLite\DB_Table;
use ThemeIsle\HyveLite\Block;
use ThemeIsle\HyveLite\Threads;
use ThemeIsle\HyveLite\API;
use ThemeIsle\HyveLite\Qdrant_API;
use ThemeIsle\HyveLite\Stream;

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
		new Stream();

		add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
		add_filter( 'user_has_cap', [ $this, 'grant_message_capabilities' ] );
		add_action( 'save_post', [ $this, 'update_meta' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'delete_post' ] );
		add_action( DB_Table::CONNECT_SYNC_HOOK, [ $this->table, 'connect_run_sync' ] );
		add_filter( 'themeisle_sdk_enable_telemetry', '__return_true' );

		add_filter( 'hyve_global_chat_enabled', [ $this, 'is_global_chat_enabled' ] );
		add_filter( 'hyve_stats', [ $this, 'get_stats' ] );
		add_filter( 'hyve_chart_data', [ $this, 'get_chart_data' ] );
		add_filter( 'hyve_options_data', [ $this, 'append_services_error' ] );
		add_filter( 'hyve_similarity_score_threshold', [ $this, 'get_similarity_threshold_score' ] );

		$settings = self::get_settings();

		add_filter( 'hyve_lite_logger_data', [ $this, 'plugin_usage' ] );

		if ( isset( $settings['post_row_addon_enabled'] ) && $settings['post_row_addon_enabled'] && current_user_can( 'manage_options' ) ) {
			add_action( 'hyve_register_post_type_row_action_knowledge_base', [ $this, 'register_row_action_filter_shortcut' ] );

			do_action( 'hyve_register_post_type_row_action_knowledge_base', 'post' );
			do_action( 'hyve_register_post_type_row_action_knowledge_base', 'page' );

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_addons_assets' ] );
		}

		// The chat can run on a local OpenAI key or on Hyve Connect; either one
		// makes the frontend assets meaningful.
		if (
			( isset( $settings['api_key'] ) && ! empty( $settings['api_key'] ) )
			|| Hyve_Connect::is_active()
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

		add_filter( 'themeisle_sdk_blackfriday_data', [ $this, 'add_black_friday_data' ] );
		add_filter( 'hyve_lite_about_us_metadata', [ $this, 'about_us_metadata' ] );
		add_action( 'admin_init', [ $this, 'admin_init' ] );
		add_action( 'admin_init', [ $this, 'add_privacy_policy_content' ] );
		add_action( 'admin_notices', [ $this, 'encryption_key_notice' ] );
		add_filter( 'hyve_encryption_key_check_can_reset', [ __CLASS__, 'can_reset_encryption_key_check' ] );
	}

	/**
	 * Warn administrators when encrypted credentials cannot be decrypted.
	 *
	 * @return void
	 */
	public function encryption_key_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! Encryption::has_key_changed() ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Hyve encryption keys have changed. Please update your OpenAI and Qdrant connection settings and regenerate API access tokens to avoid service disruption.', 'hyve-lite' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Register suggested privacy policy content.
	 *
	 * Surfaces Hyve's third-party data processing in the core Privacy Policy
	 * guide at Settings → Privacy. The disclosed data flow depends on the active
	 * mode: Hyve Connect (hosted) or self-hosted with the site's own OpenAI key.
	 *
	 * @since 1.4.2
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content =
			'<p class="privacy-policy-tutorial">' .
			__( 'This information is provided to help you disclose how the Hyve chat assistant processes visitor data. Review it and adapt it to your site before publishing.', 'hyve-lite' ) .
			'</p>' .
			'<p>' . __( 'When visitors use the Hyve chat assistant on this site, the messages they send are stored on this website so the site administrator can review chat history. No account is required to use the chat.', 'hyve-lite' ) . '</p>';

		// The AI provider differs by mode: Hyve Connect is the hosted service, otherwise the site uses its own OpenAI key. Disclose only the flow that is actually in use.
		if ( Hyve_Connect::is_active() ) {
			$content .=
				'<p>' . __( 'To generate replies, the messages are also sent to Hyve Connect, a hosted service operated by ThemeIsle. Hyve Connect processes the messages on its servers, including through third-party AI providers, to moderate their content, to create numerical representations (embeddings) used to find relevant information, and to generate the assistant\'s responses.', 'hyve-lite' ) . '</p>' .
				'<p>' . __( 'To answer questions about this site, the content of the pages selected for indexing is also sent to Hyve Connect and stored there in a vector database so it can be searched when visitors chat.', 'hyve-lite' ) . '</p>' .
				'<p>' . __( 'For details on how ThemeIsle handles data, see ThemeIsle\'s privacy policy at https://themeisle.com/privacy-policy/.', 'hyve-lite' ) . '</p>';
		} else {
			$content .=
				'<p>' . __( 'To generate replies, the messages are also sent to OpenAI, L.L.C. — a third-party service based in the United States. OpenAI processes the messages to moderate their content, to create numerical representations (embeddings) used to find relevant information, and to generate the assistant\'s responses.', 'hyve-lite' ) . '</p>' .
				'<p>' . __( 'For details on how OpenAI handles data, see OpenAI\'s privacy policy at https://openai.com/policies/privacy-policy/.', 'hyve-lite' ) . '</p>';

			// Only disclose Qdrant when it is actually connected, so the suggested text reflects the site's real data flows.
			if ( Qdrant_API::is_active() ) {
				$content .= '<p>' . __( 'This site also uses Qdrant, a third-party vector database. A numerical representation (embedding) of your message is sent to Qdrant to look up relevant information. See Qdrant\'s privacy policy at https://qdrant.tech/legal/privacy-policy/.', 'hyve-lite' ) . '</p>';
			}
		}

		wp_add_privacy_policy_content( 'Hyve', wp_kses_post( $content ) );
	}

	/**
	 * Grant the Messages capabilities to administrators.
	 *
	 * The two capabilities are custom, so nobody has them by default. Granting
	 * them to anyone who can `manage_options` keeps the Messages submenu and its
	 * REST endpoints working for admins. Doing it here, instead of persisting to
	 * the role, means there is nothing to clean up on uninstall.
	 *
	 * To give access to other roles, add the capabilities to them with a
	 * role-editor plugin or WP_Role::add_cap():
	 *  - `hyve_read_messages`   view the Messages page and read conversations.
	 *  - `hyve_manage_messages` delete conversations and export them.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, bool> $allcaps All capabilities of the current user.
	 *
	 * @return array<string, bool>
	 */
	public function grant_message_capabilities( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['hyve_read_messages']   = true;
			$allcaps['hyve_manage_messages'] = true;
		}

		return $allcaps;
	}

	/**
	 * Register menu page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_menu_page() {
		$hook = add_menu_page(
			__( 'Hyve', 'hyve-lite' ),
			__( 'Hyve', 'hyve-lite' ),
			'hyve_read_messages',
			'hyve',
			[ $this, 'menu_page' ],
			'dashicons-format-chat',
			99
		);

		if ( $hook ) {
			add_action( "admin_print_scripts-$hook", [ $this, 'enqueue_options_assets' ] );
		}

		global $submenu;

		foreach ( $this->get_submenu_pages() as $submenu_page ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress requires submenu entries to be registered through this global.
			$submenu['hyve'][] = [
				$submenu_page['label'],
				$submenu_page['capability'],
				add_query_arg(
					[
						'page' => 'hyve',
						'nav'  => $submenu_page['route'],
					],
					admin_url( 'admin.php' )
				),
				$submenu_page['label'],
			];
		}

		/*
		 * Keep the dashboard tab active when WordPress renders a canonical
		 * `page=hyve&nav=...` submenu URL.
		 */
		add_filter(
			'submenu_file',
			function ( $submenu_file, $parent_file ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading a sanitized admin URL parameter to identify the active menu item; no state change.
				$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
				if ( 'hyve' !== $parent_file || 'hyve' !== $current_page ) {
					return $submenu_file;
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a sanitized admin URL parameter to identify the active menu item; no state change.
				$nav = isset( $_GET['nav'] ) ? sanitize_key( wp_unslash( $_GET['nav'] ) ) : 'dashboard';

				return add_query_arg(
					[
						'page' => 'hyve',
						'nav'  => $nav,
					],
					admin_url( 'admin.php' )
				);
			},
			10,
			2
		);
	}

	/**
	 * Get the Hyve submenu pages.
	 *
	 * Each entry mirrors a top-level section of the dashboard app and deep-links
	 * into it. The `route` is passed to the app so the matching screen opens.
	 * Messages is gated on `hyve_read_messages` so support staff can reach it
	 * without seeing the rest of the dashboard.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, array{label: string, capability: string, route: string}>
	 */
	public function get_submenu_pages() {
		return [
			'hyve'                => [
				'label'      => __( 'Dashboard', 'hyve-lite' ),
				'capability' => 'manage_options',
				'route'      => 'dashboard',
			],
			'hyve-knowledge-base' => [
				'label'      => __( 'Knowledge Base', 'hyve-lite' ),
				'capability' => 'manage_options',
				'route'      => 'kb',
			],
			'hyve-messages'       => [
				'label'      => __( 'Messages', 'hyve-lite' ),
				'capability' => 'hyve_read_messages',
				'route'      => 'messages',
			],
			'hyve-settings'       => [
				'label'      => __( 'Settings', 'hyve-lite' ),
				'capability' => 'manage_options',
				'route'      => 'settings',
			],
		];
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

		if ( Hyve_Connect::is_active() ) {
			if ( false === get_transient( 'hyve_connect_recovery_check' ) ) {
				set_transient( 'hyve_connect_recovery_check', 1, HOUR_IN_SECONDS );
				$this->table->connect_check_recovery();
			}

			$this->table->connect_check_identity();
			$this->table->connect_maybe_resume_blocked();
			$this->table->connect_sync_watchdog();
		}

		$post_types        = get_post_types( [ 'public' => true ], 'objects' );
		$post_types_for_js = [];

		foreach ( $post_types as $post_type ) {
			$post_types_for_js[] = [
				'label' => $post_type->labels->name,
				'value' => $post_type->name,
			];
		}

		$submenu_pages = $this->get_submenu_pages();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page to set the document title, no state change.
		$current_nav = isset( $_GET['nav'] ) ? sanitize_key( wp_unslash( $_GET['nav'] ) ) : 'dashboard';
		global $title;
		foreach ( $submenu_pages as $submenu_page ) {
			if ( $submenu_page['route'] === $current_nav ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress reads the current admin page title from this global.
				$title = $submenu_page['label'];
				break;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the current admin page to pick the initial app screen, no state change.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'hyve';
		$current_view = 'hyve' === $current_page ? $current_nav : ( $submenu_pages[ $current_page ]['route'] ?? 'home' );

		add_filter(
			'hyve_options_data',
			/**
			 * Localize the dashboard data.
			 *
			 * @param array<string, mixed> $data Localized dashboard data.
			 *
			 * @return array<string, mixed>
			 */
			function ( $data ) use ( $settings, $post_types_for_js, $current_view ) {
				/**
				 * PHPStan false positive: the return type is an array, but PHPStan cannot infer it because of the dynamic nature of the filter.
				 * 
				 * @phpstan-ignore return.type
				 */
				return array_merge(
					$data,
					[
						'view'              => $current_view,
						'canManage'         => current_user_can( 'manage_options' ),
						'canReadMessages'   => current_user_can( 'hyve_read_messages' ),
						'canManageMessages' => current_user_can( 'hyve_manage_messages' ),
						'api'               => $this->api->get_endpoint(),
						'version'           => HYVE_LITE_VERSION,
						'rest_url'          => rest_url( $this->api->get_endpoint() ),
						'postTypes'         => $post_types_for_js,
						'hasAPIKey'         => isset( $settings['api_key'] ) && ! empty( $settings['api_key'] ),
						'isApiKeyConnected' => self::is_api_key_connected( $settings ),
						'chunksLimit'       => apply_filters( 'hyve_chunks_limit', 500 ),
						'aiMode'            => Hyve_Connect::get_mode(),
						'connect'           => Hyve_Connect::is_active() ? Hyve_Connect::instance()->stats() : null,
						'connectSync'       => Hyve_Connect::is_active() ? $this->table->connect_sync_status() : null,
						'isQdrantActive'    => Qdrant_API::is_active(),
						'assets'            => [
							'images' => HYVE_LITE_URL . 'assets/images/',
						],
						'stats'             => $this->get_stats(),
						'privacySettings'   => admin_url( 'options-privacy.php' ),
						'hasPrivacyPage'    => '' !== get_privacy_policy_url(),
						'docs'              => 'https://docs.themeisle.com/article/2009-hyve-documentation',
						'qdrant_docs'       => 'https://docs.themeisle.com/article/2066-integrate-hyve-with-qdrant',
						'pro'               => 'https://themeisle.com/plugins/hyve/',
						'chart'             => $this->get_chart_data(),
						'hasPro'            => apply_filters( 'product_hyve_license_status', false ),
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

		/**
		 * Fires before the Hyve dashboard assets are enqueued,
		 * 
		 * @since 1.5.0
		 */
		do_action( 'hyve_enqueue_options_assets' );

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

		$this->enqueue_chat_preview();

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
				'ai_mode'                    => 'self_hosted',
				'api_key'                    => '',
				'qdrant_api_key'             => '',
				'qdrant_endpoint'            => '',
				'chat_model'                 => 'gpt-5.4-nano',
				'welcome_message'            => '',
				'default_message'            => '',
				'similarity_score_threshold' => 0.4,
				'post_row_addon_enabled'     => true,
				'sound_enabled'              => true,
				'show_timestamp'             => true,
				'privacy_notice_enabled'     => false,
				'chat_position'              => 'right',
				'show_source_link'           => false,
				'display_mode'               => 'all',
				'display_rules'              => [],
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
		$saved = get_option( 'hyve_settings', [] );

		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		foreach ( self::get_encrypted_settings() as $key ) {
			if ( ! isset( $saved[ $key ] ) ) {
				continue;
			}

			$decrypted     = Encryption::decrypt( $saved[ $key ] );
			$saved[ $key ] = false === $decrypted ? '' : $decrypted;
		}

		$settings                      = $saved;
		$settings['telemetry_enabled'] = 'yes' === get_option( 'hyve_lite_logger_flag', 'no' );

		$settings = wp_parse_args( $settings, self::get_default_settings() );

		/*
		 * Backward compatibility: derive the visibility mode from the legacy
		 * chat_enabled boolean until the migration persists display_mode. Only
		 * fires on the front-end window before the SDK migration runs (admin_init
		 * after an upgrade), so it can be removed once all installs migrated.
		 */
		if ( ! isset( $saved['display_mode'] ) ) {
			$settings['display_mode'] = ( isset( $saved['chat_enabled'] ) && ! $saved['chat_enabled'] ) ? 'manual' : 'all';
		}

		return $settings;
	}

	/**
	 * Get settings that must be encrypted at rest.
	 *
	 * @return string[]
	 */
	public static function get_encrypted_settings() {
		return [ 'api_key', 'qdrant_api_key' ];
	}

	/**
	 * Persist settings while keeping sensitive values encrypted at rest.
	 *
	 * @param mixed $settings Settings to persist.
	 * @return bool Whether the settings were saved successfully.
	 */
	public static function save_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		foreach ( self::get_encrypted_settings() as $key ) {
			if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] ) {
				continue;
			}

			$encrypted = Encryption::encrypt( $settings[ $key ] );

			if ( false === $encrypted ) {
				return false;
			}

			$settings[ $key ] = $encrypted;
		}

		return update_option( 'hyve_settings', $settings ) || get_option( 'hyve_settings' ) === $settings;
	}

	/**
	 * Keep the changed-key marker until Lite's unreadable credentials are replaced.
	 *
	 * @param bool $can_reset Whether other plugin components are recovered.
	 * @return bool Whether Lite's credentials are recovered too.
	 */
	public static function can_reset_encryption_key_check( $can_reset ) {
		if ( ! $can_reset ) {
			return false;
		}

		$settings = get_option( 'hyve_settings', [] );

		if ( ! is_array( $settings ) ) {
			return true;
		}

		foreach ( self::get_encrypted_settings() as $key ) {
			if ( isset( $settings[ $key ] ) && Encryption::is_encrypted( $settings[ $key ] ) && false === Encryption::decrypt( $settings[ $key ] ) ) {
				return false;
			}
		}

		return true;
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
	 * The built-in chat icon slugs that can be inlined.
	 *
	 * @return string[]
	 */
	public static function get_known_icon_slugs() {
		return [
			'chat-bubble-left-ellipsis',
			'chat-bubble-oval-left',
			'chat-bubble-bottom-center-text',
			'chat-bubble-bottom-center',
			'chat-bubble-left',
			'chat-bubble-left-right',
		];
	}

	/**
	 * Read and inline the given built-in chat icon SVGs.
	 *
	 * Only known bundled icons are read (allowlist), and only the plugin's own
	 * asset files — never remote data.
	 *
	 * @param string[] $slugs Icon slugs to inline.
	 *
	 * @return array<string, string> Map of slug => SVG markup.
	 */
	public static function get_inline_icons( $slugs ) {
		$known = self::get_known_icon_slugs();
		$icons = [];

		foreach ( array_unique( $slugs ) as $slug ) {
			if ( ! in_array( $slug, $known, true ) ) {
				continue;
			}

			$icon_path = HYVE_LITE_PATH . '/assets/icons/' . $slug . '.svg';

			if ( is_readable( $icon_path ) ) {
				// Reading a bundled plugin asset, not remote data.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				$icons[ $slug ] = trim( (string) file_get_contents( $icon_path ) );
			}
		}

		return $icons;
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

		wp_localize_script( 'hyve-lite-scripts', 'hyveClient', $this->get_frontend_data() );

		$settings            = self::get_settings();
		$should_display_chat = $this->should_display_chat();

		if ( ! $should_display_chat ) {
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
			'document.addEventListener("DOMContentLoaded", function() { const box = document.querySelector( ".hyve-input-box" ); if ( ! box ) { return; } const c = document.createElement("div"); c.className = "hyve-credits"; c.innerHTML = "<a href=\"https://themeisle.com/plugins/hyve/?utm_source=hyve&utm_medium=chatbot&utm_campaign=copyright\" target=\"_blank\">Powered by Hyve</a>"; if ( document.querySelector( ".hyve-privacy-notice" ) ) { c.hidden = true; } box.before( c ); });'
		);
	}

	/**
	 * Build the data localized for the chat widget.
	 *
	 * Shared by the public frontend and the dashboard test preview so the two
	 * stay in sync. The `hyve_frontend_data` filter lets the Pro plugin layer
	 * appearance (name, icon, colors) on top.
	 *
	 * @param array<string, mixed> $overrides Values merged over the defaults (e.g. preview flags).
	 *
	 * @return array<string, mixed>
	 */
	public function get_frontend_data( $overrides = [] ) {
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

		// Inline the icon SVGs so the chat button renders instantly, without an
		// extra runtime fetch (which causes an icon flash on load). Only the
		// icons the chat can actually use are read: the default, plus the
		// selected built-in icon — not every bundled file on each request.
		$selected_icon = ( isset( $settings['chat_icon']['type'], $settings['chat_icon']['value'] ) && 'svg' === $settings['chat_icon']['type'] )
			? (string) $settings['chat_icon']['value']
			: '';

		$icon_slugs = [ 'chat-bubble-left-ellipsis' ];

		if ( in_array( $selected_icon, self::get_known_icon_slugs(), true ) ) {
			$icon_slugs[] = $selected_icon;
		}

		$data = apply_filters(
			'hyve_frontend_data',
			[
				'api'           => $this->api->get_endpoint(),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'streamNonce'   => wp_create_nonce( Stream::NONCE_ACTION ),
				'audio'         => [
					'ping' => HYVE_LITE_URL . 'assets/audio/ping.mp3',
				],
				'welcome'       => esc_html( $settings['welcome_message'] ?? '' ),
				'isEnabled'     => $this->should_display_chat(),
				'soundEnabled'  => boolval( $settings['sound_enabled'] ?? true ),
				'showTimestamp' => boolval( $settings['show_timestamp'] ?? true ),
				'chatPosition'  => 'left' === ( $settings['chat_position'] ?? 'right' ) ? 'left' : 'right',
				'privacyNotice' => [
					'enabled' => boolval( $settings['privacy_notice_enabled'] ?? false ),
					/**
					 * Filters the URL the chat privacy notice links to.
					 *
					 * Defaults to the site's Privacy Policy page (Settings → Privacy).
					 * Return an empty string to render the notice without a link.
					 *
					 * @since 1.5.0
					 *
					 * @param string $url The privacy policy URL.
					 */
					'url'     => (string) apply_filters( 'hyve_privacy_notice_url', get_privacy_policy_url() ),
				],
				'strings'       => [
					'title'             => __( 'AI Assistant', 'hyve-lite' ),
					'status'            => __( 'Online', 'hyve-lite' ),
					'reply'             => __( 'Write a reply…', 'hyve-lite' ),
					'suggestions'       => __( 'Not sure where to start?', 'hyve-lite' ),
					'tryAgain'          => __( 'Sorry, I am not able to process your request at the moment. Please try again.', 'hyve-lite' ),
					'typing'            => __( 'Typing…', 'hyve-lite' ),
					'clearConversation' => __( 'Clear Conversation', 'hyve-lite' ),
					'muteSound'         => __( 'Mute Sound', 'hyve-lite' ),
					'unmuteSound'       => __( 'Unmute Sound', 'hyve-lite' ),
					'openChat'          => __( 'Open chat', 'hyve-lite' ),
					'closeChat'         => __( 'Close chat', 'hyve-lite' ),
					'sendMessage'       => __( 'Send message', 'hyve-lite' ),
					'previewNotice'     => __( 'Preview mode — test your assistant here. These messages aren\'t saved.', 'hyve-lite' ),
					/**
					 * Filters the chat privacy notice text. Use a single %s where the
					 * privacy policy link should appear.
					 *
					 * @since 1.5.0
					 *
					 * @param string $text The notice text.
					 */
					// translators: %s: Privacy Policy link.
					'privacyNotice'     => (string) apply_filters( 'hyve_privacy_notice_text', __( 'By chatting, you agree to our %s.', 'hyve-lite' ) ),
					/**
					 * Filters the linked label inside the chat privacy notice.
					 *
					 * @since 1.5.0
					 *
					 * @param string $label The link label.
					 */
					'privacyPolicy'     => (string) apply_filters( 'hyve_privacy_notice_link_text', __( 'Privacy Policy', 'hyve-lite' ) ),
					'dismissNotice'     => __( 'Dismiss', 'hyve-lite' ),
					'leadIntro'         => __( 'Leave your details and we will get back to you.', 'hyve-lite' ),
					'leadOffer'         => __( 'Would you like to leave your contact details instead?', 'hyve-lite' ),
					'leadOfferButton'   => __( 'Leave your details', 'hyve-lite' ),
					'leadNoThanks'      => __( 'No thanks', 'hyve-lite' ),
					'leadSubmit'        => __( 'Send', 'hyve-lite' ),
					'leadSkip'          => __( 'Not now', 'hyve-lite' ),
					'leadThanks'        => __( 'Thanks! Your details have been sent. We will get back to you soon.', 'hyve-lite' ),
					'leadRequired'      => __( 'Please fill in the required fields.', 'hyve-lite' ),
					'leadEvent'         => __( 'You shared your contact details.', 'hyve-lite' ),
					'leadAlready'       => __( 'We already have your details. We will get back to you as soon as possible.', 'hyve-lite' ),
				],
				'icons'         => self::get_inline_icons( $icon_slugs ),
				'canShow'       => $should_show_chat,
			]
		);

		return array_merge( $data, $overrides );
	}

	/**
	 * Enqueue the chat widget on the Hyve dashboard as a live test preview.
	 *
	 * @return void
	 */
	public function enqueue_chat_preview() {
		$settings = self::get_settings();

		if ( empty( $settings['api_key'] ) && ! Hyve_Connect::is_active() ) {
			return;
		}

		// @phpstan-ignore include.fileNotFound
		$asset_file = include HYVE_LITE_PATH . '/build/frontend/frontend.asset.php';

		wp_enqueue_style(
			'hyve-chat-preview',
			HYVE_LITE_URL . 'build/frontend/style-index.css',
			[],
			$asset_file['version']
		);

		wp_enqueue_script(
			'hyve-chat-preview',
			HYVE_LITE_URL . 'build/frontend/frontend.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_set_script_translations( 'hyve-chat-preview', 'hyve-lite' );

		wp_localize_script(
			'hyve-chat-preview',
			'hyveClient',
			$this->get_frontend_data(
				[
					'isPreview' => true,
					'canShow'   => true,
					'isEnabled' => true,
					'icons'     => self::get_inline_icons( self::get_known_icon_slugs() ),
				]
			)
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
		$processing = (int) get_post_meta( $post->ID, '_hyve_post_processing', true );

		if ( $processing ) {
			if ( ( time() - $processing ) < DB_Table::PROCESSING_STALL ) {
				$actions['hyve_knowledge_base_processing'] = __( 'Hyve is processing the post', 'hyve-lite' );
				return $actions;
			}

			// A leaked flag from an add interrupted mid-request; clear it and
			// fall through to the normal add/remove action.
			delete_post_meta( $post->ID, '_hyve_post_processing' );
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
		// In Connect mode the knowledge base lives on the platform, so the chunk
		// count comes from the hosted aggregate, not the (dormant) local table.
		if ( Hyve_Connect::is_active() ) {
			$connect      = Hyve_Connect::instance()->stats();
			$total_chunks = isset( $connect['kb']['chunks'] ) ? (int) $connect['kb']['chunks'] : 0;
		} else {
			$total_chunks = $this->table->get_count();
		}

		return [
			'threads'     => Threads::get_thread_count(),
			'messages'    => Threads::get_messages_count(),
			'totalChunks' => $total_chunks,
		];
	}

	/**
	 * Check if the Chat is enabled globally on all the pages.
	 *
	 * @return boolean True if the chat is enabled.
	 */
	public function is_global_chat_enabled() {
		$settings = self::get_settings();

		return isset( $settings['display_mode'] ) && 'all' === $settings['display_mode'];
	}

	/**
	 * Whether the chat should be auto-displayed on the current request.
	 *
	 * Evaluates the visibility rules against the current page. Manual placement
	 * via the block or shortcode is unaffected by this.
	 *
	 * @since 1.4.2
	 *
	 * @return bool
	 */
	public function should_display_chat() {
		$settings = self::get_settings();
		$mode     = isset( $settings['display_mode'] ) ? $settings['display_mode'] : 'all';

		if ( 'all' === $mode ) {
			return true;
		}

		if ( 'include' !== $mode && 'exclude' !== $mode ) {
			// 'manual' or any unknown mode: no automatic display.
			return false;
		}

		$rules   = ( isset( $settings['display_rules'] ) && is_array( $settings['display_rules'] ) ) ? $settings['display_rules'] : [];
		$matches = $this->path_matches( $rules );

		return 'include' === $mode ? $matches : ! $matches;
	}

	/**
	 * Check the current request URI against a set of path rules.
	 *
	 * Mirrors the exact/contains matching used by other ThemeIsle plugins.
	 *
	 * @since 1.4.2
	 *
	 * @param array<int, array<string, string>> $rules List of { path, operator } rules.
	 *
	 * @return bool True when any rule matches the current request.
	 */
	private function path_matches( $rules ) {
		if ( empty( $rules ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		foreach ( $rules as $rule ) {
			$path = isset( $rule['path'] ) ? trim( $rule['path'] ) : '';

			if ( '' === $path ) {
				continue;
			}

			$operator = isset( $rule['operator'] ) ? $rule['operator'] : 'contains';

			if ( 'matches' === $operator ) {
				if ( $uri === $path ) {
					return true;
				}
			} elseif ( false !== strpos( $uri, $path ) ) {
				return true;
			}
		}

		return false;
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

		// The edited-since-indexing flag is for site content edited by
		// people. Non-viewable types are the ingest pipeline's own entries
		// (crawled pages, documents, custom data); it re-indexes them itself,
		// and the update cron could never see them to clear the flag.
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		update_post_meta( $post_id, '_hyve_needs_update', 1 );
		delete_post_meta( $post_id, '_hyve_moderation_failed' );
		delete_post_meta( $post_id, '_hyve_moderation_review' );
		// An edit may fix whatever failed indexing (e.g. no text content).
		delete_post_meta( $post_id, '_hyve_processing_error' );

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
		} elseif ( Hyve_Connect::is_active() && get_post_meta( $post_id, '_hyve_added', true ) ) {
			Hyve_Connect::instance()->kb_delete( [ (int) $post_id ] );
			Hyve_Connect::flush_stats();
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
		$plan   = apply_filters( 'product_hyve_license_plan', 0 );
		$is_pro = 0 < $plan;

		// NOTE: Currently, only lifetime plan is available for Hyve Pro.
		if ( $is_pro ) {
			return $configs;
		}

		$config = $configs['default'];

		// translators: %s - discount.
		$config['title']     = sprintf( __( 'Hyve Pro: %s off this week', 'hyve-lite' ), '60%' );
		$config['cta_label'] = __( 'Get Hyve Pro', 'hyve-lite' );
		$config['message']   = __( 'Chat history, missed question tracking, appearance customization. Make your chatbot actually useful. Exclusively for existing Hyve users.', 'hyve-lite' );
		// translators: %s is the discount percentage.
		$config['plugin_meta_message'] = sprintf( __( 'Black Friday Sale - %s off', 'hyve-lite' ), '60%' );
		$config['sale_url']            = add_query_arg(
			[
				'utm_term' => 'free',
			],
			tsdk_translate_link( tsdk_utmify( 'https://themeisle.link/hyve-bf', 'bfcm', 'hyve' ) )
		);

		$configs[ HYVE_PRODUCT_SLUG ] = $config;

		return $configs;
	}

	/**
	 * Provide metadata for the ThemeIsle SDK About Us page.
	 *
	 * @return array<string, mixed>
	 */
	public function about_us_metadata() {
		return [
			'location'         => 'hyve',
			'logo'             => 'https://ps.w.org/hyve-lite/assets/icon-256x256.png',
			'has_upgrade_menu' => 'valid' !== apply_filters( 'product_hyve_license_status', false ),
			'upgrade_link'     => tsdk_utmify( 'https://themeisle.com/plugins/hyve/', 'about-us' ),
			'upgrade_text'     => __( 'Get Pro Version', 'hyve-lite' ),
			'review_link'      => 'https://wordpress.org/support/plugin/hyve-lite/reviews/',
		];
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
	 * Determine whether the saved OpenAI API key is connected.
	 *
	 * The key is validated against OpenAI whenever it is saved, and any
	 * key-related failure during use is stored in the error option. The key is
	 * considered connected when it is set and the last stored error (if any) is
	 * not one that invalidates the key itself.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 *
	 * @return bool
	 */
	public static function is_api_key_connected( $settings ) {
		if ( empty( $settings['api_key'] ) ) {
			return false;
		}

		$last_error = get_option( OpenAI::ERROR_OPTION_KEY, false );

		if ( ! is_array( $last_error ) || empty( $last_error['code'] ) ) {
			return true;
		}

		$key_error_codes = [
			'invalid_api_key',
			'invalid_authentication',
			'account_deactivated',
			'billing_not_active',
			'organization_not_found',
			'organization_deactivated',
			'permission_denied',
			'insufficient_quota',
		];

		return ! in_array( $last_error['code'], $key_error_codes, true );
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
		if ( is_array( $open_ai_last_error ) && $this->is_recent_error( $open_ai_last_error ) ) {
			if ( ! empty( $open_ai_last_error['code'] ) ) {
				$friendly_message = OpenAI::get_error_message_for_code( $open_ai_last_error['code'] );

				if ( null !== $friendly_message ) {
					$open_ai_last_error['message'] = $friendly_message;
				}
			}

			$errors[] = $open_ai_last_error;
		}

		$qdrant_last_error = get_option( Qdrant_API::ERROR_OPTION_KEY, false );
		if ( is_array( $qdrant_last_error ) && $this->is_recent_error( $qdrant_last_error ) ) {
			$friendly_message = ! empty( $qdrant_last_error['code'] ) ? Qdrant_API::get_error_message_for_code( $qdrant_last_error['code'] ) : null;

			if ( null === $friendly_message ) {
				$friendly_message = __( 'Hyve could not connect to Qdrant.', 'hyve-lite' ) . ' ' . __( 'Please check your API key and endpoint URL in the Integrations settings.', 'hyve-lite' );
			}

			$qdrant_last_error['message'] = $friendly_message;
			$errors[]                     = $qdrant_last_error;
		}

		if ( ! empty( $errors ) ) {
			$options['serviceErrors'] = $errors;
		}

		return $options;
	}

	/**
	 * Whether a saved service error is recent enough to surface to the admin.
	 *
	 * A successful request already clears the saved error, so this only guards
	 * against a stale failure lingering on a site with no traffic since: we only
	 * show errors from the last 24 hours that still have no subsequent success.
	 *
	 * @param array<string, mixed> $error The saved error.
	 *
	 * @return bool
	 */
	private function is_recent_error( $error ) {
		if ( empty( $error['date'] ) ) {
			return false;
		}

		$timestamp = strtotime( $error['date'] );

		if ( false === $timestamp ) {
			return false;
		}

		return $timestamp >= ( time() - DAY_IN_SECONDS );
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
				'chat_on_all_pages_enabled' => 'all' === $settings['display_mode'],
				'chat_display_mode'         => $settings['display_mode'],
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

	/**
	 * Get the plugin usage.
	 *
	 * @param mixed $data The data.
	 *
	 * @return mixed The plugin data.
	 */
	public function plugin_usage( $data ) {

		$settings = $this->get_settings();

		$settings['api_key']        = ! empty( $settings['api_key'] ) ? 'yes' : 'no';
		$settings['qdrant_api_key'] = ! empty( $settings['qdrant_api_key'] ) ? 'yes' : 'no';

		if ( isset( $settings['qdrant_endpoint'] ) ) {
			unset( $settings['qdrant_endpoint'] );
		}

		// We no longer use assistant_id but in case the setting exists,
		// it is private and we omit it from the usage data.
		if ( isset( $settings['assistant_id'] ) ) {
			unset( $settings['assistant_id'] );
		}

		$data['settings'] = $settings;
		$data['stats']    = $this->get_stats();

		return $data;
	}
}
