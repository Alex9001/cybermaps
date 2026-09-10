<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static ?\Cybermaps\Integration\EdgeCache\Coordinator $edge_cache_coordinator = null;
	private static bool $edge_cache_hooks_registered                                     = false;

	/** @var array<string,bool> */
	private static array $edge_invalidation_signatures = array();

	/**
	 * Detect an environment that needs translation or network integrations.
	 *
	 * Returns true if Multisite, WPML, or Polylang is detected.
	 *
	 * @return bool
	 */
	public static function is_translation_environment() {
		if ( is_multisite() ) {
			return true;
		}

		if ( function_exists( 'pll_get_post_translations' ) ) {
			return true;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Plugin constructor.
	 *
	 * @param Container $container Container instance.
	 */
	public function __construct( private Container $container ) {}

	public function run() {
		$container = $this->container;

		// Preserve the server-rendered workspace across Settings API redirects.
		// The active workspace is submitted explicitly because options.php does
		// not retain the settings page's original query string.
		add_filter( 'wp_redirect', array( self::class, 'preserve_settings_tab_query' ) );
		/*
		 * Legacy sitemap redirects use wp_safe_redirect(). A separately hosted
		 * frontend is therefore valid only when its configured host participates
		 * in WordPress's redirect-host allowlist.
		 */
		add_filter( 'allowed_redirect_hosts', array( URLManager::class, 'allow_configured_public_host' ) );

		$container->set(
			'settings',
			function () {
				return new \Cybermaps\Admin\Settings();
			}
		);
		$container->set(
			'logs',
			function () {
				return new \Cybermaps\Admin\Logs();
			}
		);
		$container->set(
			'auditor',
			function () {
				return new \Cybermaps\Admin\MediaAuditor();
			}
		);
		$container->set(
			'discovery_auditor',
			function () {
				return new \Cybermaps\Admin\DiscoveryAuditor();
			}
		);
		$container->set(
			'discovery_scope',
			function () {
				return new \Cybermaps\Admin\DiscoveryScope();
			}
		);
		$container->set(
			'identity_hub',
			function () {
				return new \Cybermaps\Admin\IdentityHub();
			}
		);
		$container->set(
			'content_audit_manager',
			function () {
				return new \Cybermaps\Admin\ContentAuditManager();
			}
		);

		$container->set(
			'adp',
			function () {
				return new \Cybermaps\Discovery\AIManifest();
			}
		);
		$container->set(
			'robots',
			function () {
				return new \Cybermaps\Discovery\Robots();
			}
		);
		$container->set(
			'indexnow',
			function () {
				return new \Cybermaps\Discovery\IndexNow();
			}
		);
		$container->set( 'mcp', fn() => new \Cybermaps\MCP\WordPressIntegration() );
		$container->set( 'webmcp', fn() => new \Cybermaps\Integration\WebMCP() );
		$container->set( 'rss_sitemap', fn() => new \Cybermaps\Sitemap\RSSProvider() );
		$container->set( 'sitemap_status', fn() => new \Cybermaps\Admin\SitemapStatus() );
		$container->set( 'discovery_analytics', fn() => new \Cybermaps\Admin\DiscoveryAnalytics() );
		$container->set( 'ai_discovery_status', fn() => new \Cybermaps\Admin\AIDiscoveryStatus() );
		$container->set( 'system_status', fn() => new \Cybermaps\Admin\SystemStatus() );
		$container->set( 'edge_optimization', fn() => new \Cybermaps\Admin\EdgeOptimizationController() );
		$container->set( 'diagnostic_logger', fn() => new DiagnosticLogger() );
		$container->set(
			'discovery',
			function ( $c ) {
				return new \Cybermaps\Discovery\Manager( $c->get( 'adp' ), $c->get( 'robots' ) );
			}
		);

		$container->set(
			'orchestrator',
			function () {
				return new \Cybermaps\Sitemap\Orchestrator();
			}
		);
		$container->set(
			'schema',
			function () {
				return new \Cybermaps\Sitemap\Schema();
			}
		);
		$container->set(
			'endpoint_registry',
			function () {
				return EndpointRegistry::get_instance();
			}
		);
		$container->set(
			'rest_api',
			function ( $c ) {
				return new RestAPI( $c->get( 'endpoint_registry' ) );
			}
		);
		$container->set(
			'extension_api',
			function ( $c ) {
				return ExtensionAPI::get_instance( $c->get( 'endpoint_registry' ) );
			}
		);
		$container->set(
			'indexability_invalidator',
			function () {
				return new \Cybermaps\SEO\IndexabilityInvalidator();
			}
		);
		$container->set(
			'publication_notifier',
			function ( $c ) {
				return new \Cybermaps\Discovery\PublicationNotifier(
					$c->get( 'indexnow' ),
					new \Cybermaps\Discovery\WebSub()
				);
			}
		);
		$container->set(
			'rest_response_guard',
			function ( $c ) {
				return new \Cybermaps\Integration\RestResponseGuard( $c->get( 'endpoint_registry' ) );
			}
		);
		$container->set(
			'edge_cache_coordinator',
			function () {
				return new \Cybermaps\Integration\EdgeCache\Coordinator();
			}
		);

		// Register before after_setup_theme so JSON output optimizers and page
		// caches see the correct request type and privacy requirements.
		$container->get( 'rest_response_guard' )->register_hooks();
		ConfigurationStore::register_hooks();
		AbilityKernel::get_instance()->register_hooks();
		NativeRoutingRegistrar::get_instance()->register_hooks();
		\Cybermaps\Discovery\WellKnownRoutingBridge::register_hooks();
		if ( ! self::$edge_cache_coordinator instanceof \Cybermaps\Integration\EdgeCache\Coordinator ) {
			self::$edge_cache_coordinator = $container->get( 'edge_cache_coordinator' );
		}
		if ( ! self::$edge_cache_hooks_registered && self::$edge_cache_coordinator instanceof \Cybermaps\Integration\EdgeCache\Coordinator ) {
			self::$edge_cache_coordinator->register_hooks();
			add_action( 'cybermaps_cache_family_invalidated', array( self::class, 'on_cache_family_invalidated' ), 10, 2 );
			add_action( Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK, array( self::class, 'cleanup_runtime_counters' ) );
			self::$edge_cache_hooks_registered = true;
		}

		// Register editor metadata both for Core types that already exist and for
		// public CPTs registered later by themes or companion plugins.
		add_action( 'registered_post_type', array( self::class, 'register_publication_meta' ), 10, 2 );
		add_action( 'wp_loaded', array( self::class, 'register_existing_publication_meta' ), 0 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );

		add_action( 'init', array( Upgrade::class, 'run' ), -1 );
		add_action( Upgrade::RETRY_HOOK, array( Upgrade::class, 'run' ) );
		add_action( \Cybermaps\Admin\NetworkSetup::RETRY_HOOK, array( \Cybermaps\Admin\NetworkSetup::class, 'maybe_upgrade' ) );

		// Defer readiness until every plugin has finished its plugins_loaded work.
		add_action( 'init', array( $container->get( 'extension_api' ), 'boot' ), 0 );

		// Content-Security-Policy: frame-ancestors on Cybermaps admin screens only.
		add_action(
			'admin_init',
			function () {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing check
				if ( empty( $_GET['page'] ) || ! is_string( $_GET['page'] ) ) {
					return;
				}
				$page = sanitize_key( wp_unslash( $_GET['page'] ) );
			// phpcs:enable
				if ( ! str_starts_with( $page, 'cybermaps' ) ) {
					return;
				}
				/*
				* Multiple CSP fields are enforced together. Append this narrow
				* framing policy so Cybermaps cannot replace a stricter or more
				* complete policy already emitted by WordPress or another plugin.
				*/
				header( "Content-Security-Policy: frame-ancestors 'self'", false );
			}
		);

		add_action(
			'init',
			function () use ( $container ) {
				$container->get( 'settings' )->register_hooks();
				$container->get( 'logs' )->register_hooks();
				$container->get( 'discovery_scope' )->register_hooks();
				$container->get( 'identity_hub' )->register_hooks();
				$container->get( 'content_audit_manager' )->register_hooks();
				$container->get( 'indexnow' )->register_hooks();
				$container->get( 'discovery' )->register_hooks();
				$container->get( 'orchestrator' )->register_hooks();
				$container->get( 'schema' )->register_hooks();
				$container->get( 'indexability_invalidator' )->register_hooks();
				$container->get( 'publication_notifier' )->register_hooks();
					$container->get( 'mcp' )->register_hooks();
					$container->get( 'webmcp' )->register_hooks();

				// AJAX tools and administration pages have no public-request hooks.
				// Resolve them only in WordPress administration contexts so ordinary
				// sitemap, discovery, and content requests avoid five needless class
				// loads and object constructions.
				if ( \is_admin() ) {
					$container->get( 'auditor' )->register_hooks();
					$container->get( 'discovery_auditor' )->register_hooks();
					$container->get( 'sitemap_status' )->register_hooks();
					$container->get( 'ai_discovery_status' )->register_hooks();
					$container->get( 'discovery_analytics' )->register_hooks();
					$container->get( 'system_status' )->register_hooks();
					$container->get( 'edge_optimization' )->register_hooks();
				}
				$container->get( 'diagnostic_logger' )->register_hooks();

				self::register_existing_publication_meta();
				add_action( 'parse_request', array( $container->get( 'rss_sitemap' ), 'handle' ), 1, 1 );
				\Cybermaps\Sitemap\ShortcodeHandler::register_shortcode();
				$container->get( 'rest_api' )->register_hooks();
			},
			5
		);

		// Network settings remain available independently from translation
		// features, but their hooks are exclusively network-administration UI.
		if ( is_multisite() && \is_admin() ) {
			$container->set(
				'network_settings',
				function () {
					return new \Cybermaps\Admin\NetworkSettings();
				}
			);
			add_action(
				'init',
				function () use ( $container ) {
					$container->get( 'network_settings' )->register_hooks();
				},
				5
			);
		}

		// Multisite keeps the lightweight relationship invalidator active even
		// when this site's UI is disabled: another site may still reference a
		// stored cross-site relationship and must not retain stale hreflang.
		$plugin_settings              = ConfigurationStore::settings();
		$translation_features_enabled = isset( $plugin_settings['enable_translation_integrations'] )
			&& is_scalar( $plugin_settings['enable_translation_integrations'] )
			&& '1' === (string) $plugin_settings['enable_translation_integrations'];

		if ( $translation_features_enabled || is_multisite() ) {
			$container->set(
				'registry',
				function () {
					return new \Cybermaps\Core\TranslationRegistry();
				}
			);
			$container->set(
				'translation_manager',
				function ( $c ) {
					return new \Cybermaps\Integration\TranslationManager( $c->get( 'registry' ) );
				}
			);
			add_action(
				'init',
				function () use ( $container ) {
					$container->get( 'translation_manager' )->register_hooks();
				},
				5
			);
		}

		if ( $translation_features_enabled ) {
			$container->set(
				'intl_panel',
				function () {
					return new \Cybermaps\Admin\InternationalPanel();
				}
			);
			add_action(
				'init',
				function () use ( $container ) {
					$container->get( 'intl_panel' )->register_hooks();
				},
				5
			);
		}

		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );

		// Register Core's WP-CLI commands whenever WordPress is running under WP-CLI.
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'cybermaps', new \Cybermaps\CLI\Command() );
		}
	}

	/**
	 * Preserve the active server-rendered settings workspace.
	 *
	 * @param mixed $location Redirect destination.
	 */
	public static function preserve_settings_tab_query( $location ): string {
		$location = (string) $location;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- no state change; preserves tab fragment on redirect
		if (
			! str_contains( $location, 'page=cybermaps-settings' )
			|| empty( $_POST['cybermaps_active_tab'] )
			|| ! is_scalar( $_POST['cybermaps_active_tab'] )
		) {
			return $location;
		}

		$tab = sanitize_key( wp_unslash( (string) $_POST['cybermaps_active_tab'] ) );
		// phpcs:enable
		if (
			! in_array( $tab, array( 'sitemaps', 'shortcode', 'ai', 'schema', 'review', 'advanced' ), true )
		) {
			return $location;
		}

		return add_query_arg( 'tab', $tab, $location );
	}

	/**
	 * Register Cybermaps editor metadata for all currently public publications.
	 */
	public static function register_existing_publication_meta(): void {
		foreach ( PublicationPostTypes::names() as $post_type ) {
			self::register_publication_meta( $post_type );
		}
	}

	/**
	 * Register Cybermaps metadata for one newly registered public post type.
	 *
	 * WordPress exposes registered post meta through a CPT REST controller only
	 * when that type supports custom fields. Add that support to public,
	 * REST-enabled editor types so the Cybermaps document panel can save its
	 * five fields reliably.
	 *
	 * @param string      $post_type        Registered post-type name.
	 * @param object|null $post_type_object Optional object supplied by WordPress.
	 */
	public static function register_publication_meta( $post_type, $post_type_object = null ): void {
		$post_type = sanitize_key( (string) $post_type );
		$object    = is_object( $post_type_object )
			? $post_type_object
			: get_post_type_object( $post_type );

		if (
			'' === $post_type
			|| 'attachment' === $post_type
			|| ! is_object( $object )
			|| empty( $object->public )
		) {
			return;
		}

		if (
			! empty( $object->show_in_rest )
			&& post_type_supports( $post_type, 'editor' )
			&& ! post_type_supports( $post_type, 'custom-fields' )
		) {
			add_post_type_support( $post_type, 'custom-fields' );
		}

		$definitions = array(
			'_cybermaps_exclude_sitemap'    => array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => array( self::class, 'sanitize_binary_meta' ),
			),
			'_cybermaps_exclude_ai'         => array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => array( self::class, 'sanitize_binary_meta' ),
			),
			'_cybermaps_intent_override'    => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_intent_meta' ),
			),
			'_cybermaps_sitemap_priority'   => array(
				'type'              => 'number',
				'default'           => 0,
				'sanitize_callback' => array( self::class, 'sanitize_priority_meta' ),
			),
			'_cybermaps_sitemap_changefreq' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_changefreq_meta' ),
			),
		);

		foreach ( $definitions as $meta_key => $definition ) {
			register_post_meta(
				$post_type,
				$meta_key,
				array_merge(
					array(
						'show_in_rest'  => true,
						'single'        => true,
						'auth_callback' => array( self::class, 'authorize_post_meta' ),
					),
					$definition
				)
			);
		}
	}

	/**
	 * Load the document panel only on a supported publication editor.
	 */
	public static function enqueue_editor_assets(): void {
		$screen    = get_current_screen();
		$post_type = is_object( $screen ) ? (string) ( $screen->post_type ?? '' ) : '';
		if ( ! PublicationPostTypes::contains( $post_type ) ) {
			return;
		}

		wp_enqueue_script(
			'cybermaps-sitemap-exclusion',
			CYBERMAPS_PLUGIN_URL . 'assets/js/sitemap-exclusion.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-element' ),
			CYBERMAPS_VERSION,
			true
		);
		global $post;
		$post_id             = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
		$settings            = ConfigurationStore::settings();
		$translation_enabled = ! empty( $settings['enable_translation_integrations'] ) && $post_id > 0;
		$translation         = array(
			'enabled' => $translation_enabled,
			'postId'  => $post_id,
			'path'    => '/cybermaps/v1/editor/translation/' . $post_id,
			'state'   => array(
				'group_id'    => 0,
				'sync_paused' => false,
			),
		);
		if ( $translation_enabled ) {
			$translation['state'] = ( new \Cybermaps\Admin\InternationalPanel() )->get_editor_state( $post_id );
		}

		wp_localize_script(
			'cybermaps-sitemap-exclusion',
			'cybermapsEditor',
			array(
				'postTypes'             => PublicationPostTypes::names(),
				'translation'           => $translation,
				'legacyComponentSizing' => version_compare( (string) get_bloginfo( 'version' ), '7.1', '<' ),
			)
		);
		wp_set_script_translations(
			'cybermaps-sitemap-exclusion',
			'cybermaps',
			CYBERMAPS_PLUGIN_DIR . 'languages'
		);
	}

	/**
	 * Trigger MediaScanner on post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! is_object( $post ) || 'publish' !== $post->post_status ) {
			return;
		}

		$post_type_name = (string) ( $post->post_type ?? '' );
		if ( ! PublicationPostTypes::contains( $post_type_name ) ) {
			return;
		}

		$settings      = ConfigurationStore::settings();
		$raw_intensity = $settings['media_discovery_intensity'] ?? 'none';
		$intensity     = \Cybermaps\Sitemap\MediaScanner::normalize_intensity( $raw_intensity );

		$scanner = new \Cybermaps\Sitemap\MediaScanner();
		$scanner->run_audit( $post_id, $intensity );

		\Cybermaps\Discovery\AIMetadata::refresh( (int) $post_id );
	}

	public static function cleanup_runtime_counters(): void {
		RuntimeCounterStore::cleanup( 1000 );
	}

	public static function on_cache_family_invalidated( string $family, int $generation ): void {
		$plan = self::edge_invalidation_plan( $family );
		if ( empty( $plan ) || ! isset( $plan['family'], $plan['urls'], $plan['wait_for_static'] ) ) {
			return;
		}

		$signature_payload = wp_json_encode( array( $plan['urls'], $plan['wait_for_static'] ) );
		if ( ! is_string( $signature_payload ) ) {
			$signature_payload = '';
		}
		$signature = (string) $plan['family']
			. ':'
			. $generation
			. ':'
			. md5( $signature_payload );
		if ( isset( self::$edge_invalidation_signatures[ $signature ] ) ) {
			return;
		}

		self::$edge_invalidation_signatures[ $signature ] = true;
		if ( ! self::$edge_cache_coordinator instanceof \Cybermaps\Integration\EdgeCache\Coordinator ) {
			return;
		}

		self::$edge_cache_coordinator->invalidate(
			(string) $plan['family'],
			is_array( $plan['urls'] ) ? $plan['urls'] : array(),
			! empty( $plan['wait_for_static'] )
		);
	}

	/**
	 * @return array{family:string,urls:string[],wait_for_static:bool}|array{}
	 */
	private static function edge_invalidation_plan( string $family ): array {
		$settings    = ConfigurationStore::settings();
		$static_mode = \Cybermaps\Discovery\StaticBridge::get_mode( $settings );

		return match ( $family ) {
			'discovery' => array(
				'family'          => 'discovery',
				'urls'            => self::discovery_invalidation_urls(),
				'wait_for_static' => 'off' !== $static_mode,
			),
			'sitemap'   => array(
				'family'          => 'sitemap',
				'urls'            => self::sitemap_invalidation_urls(),
				'wait_for_static' => 'all' === $static_mode,
			),
			'chunks'    => array(
				'family'          => 'chunks',
				'urls'            => array(),
				'wait_for_static' => 'all' === $static_mode,
			),
			default     => array(),
		};
	}

	/**
	 * @return string[]
	 */
	private static function discovery_invalidation_urls(): array {
		return self::fixed_endpoint_urls(
			array(
				'manifest',
				'adp_discovery',
				'discovery_index',
				'llms',
				'llms_full',
				'llms_tldr',
				'knowledge_graph',
				'feed',
				'updates',
				'adp_news_llms',
				'adp_news_speakable',
				'adp_news_changelog',
				'adp_news_archive',
				'usage_policy',
				'actions',
				'skill',
				'agent_skills',
				'api_catalog',
				'openapi',
				'rest_root',
				'rest_llms_tldr',
				'rest_search',
				'rest_mcp',
			)
		);
	}

	/**
	 * @return string[]
	 */
	private static function sitemap_invalidation_urls(): array {
		$urls = self::fixed_endpoint_urls( array( 'ai_sitemap' ) );
		foreach (
			array(
				\Cybermaps\Sitemap\Orchestrator::get_sitemap_base(),
				\Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base(),
				\Cybermaps\Sitemap\Orchestrator::get_rss_sitemap_base(),
			) as $base
		) {
			$base = sanitize_title( (string) $base );
			if ( '' === $base ) {
				continue;
			}
			$urls[] = URLManager::get_home_url( '/' . $base . '.xml' );
		}

		return array_values( array_unique( array_filter( $urls, 'is_string' ) ) );
	}

	/**
	 * @param string[] $endpoint_ids
	 * @return string[]
	 */
	private static function fixed_endpoint_urls( array $endpoint_ids ): array {
		$registry = EndpointRegistry::get_instance();
		$urls     = array();

		foreach ( $endpoint_ids as $endpoint_id ) {
			$url = $registry->get_url( $endpoint_id );
			if ( '' !== $url ) {
				$urls[] = $url;
			}

			foreach ( $registry->get_aliases( $endpoint_id ) as $alias ) {
				$alias_url = URLManager::get_home_url( $alias );
				if ( '' !== $alias_url ) {
					$urls[] = $alias_url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Allow registered post meta only when the current user can edit its post.
	 *
	 * @param bool   $allowed   Default authorization decision.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public static function authorize_post_meta( $allowed, $meta_key, $object_id ): bool {
		unset( $allowed, $meta_key );
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Normalize a binary editor flag.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_binary_meta( $value ): string {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Normalize the optional discovery intent label.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_intent_meta( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'informational', 'transactional' ), true ) ? $value : '';
	}

	/**
	 * Normalize an optional sitemap priority.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_priority_meta( $value ): float {
		$value = (float) $value;
		if ( $value <= 0 ) {
			return 0.0;
		}
		return min( 1.0, round( $value, 1 ) );
	}

	/**
	 * Normalize an optional sitemap change frequency.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_changefreq_meta( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' );
		return in_array( $value, $allowed, true ) ? $value : '';
	}
}
