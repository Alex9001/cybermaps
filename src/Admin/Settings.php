<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

use Cybermaps\Admin\Settings\SettingsAssets;
use Cybermaps\Admin\Settings\SettingsAjax;
use Cybermaps\Admin\Settings\SettingsRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin facade for Cybermaps admin settings — hooks, registration, and page render.
 */
class Settings {

	/**
	 * Settings that do not affect a cached or materialized publication.
	 *
	 * Some alter admin/runtime behavior; the feed and Site Guide controls alter
	 * uncached, dynamic-only responses. None requires a whole-site static-file
	 * reconciliation.
	 *
	 * @var string[]
	 */
	private const NO_PUBLICATION_RECONCILIATION_KEYS = array(
		'api_secret',
		'ai_feed_full_content',
		'ai_feed_include_authors',
		'ai_feed_limit',
		'audit_page_max_age_days',
		'audit_page_min_words',
		'audit_page_require_media',
		'audit_post_max_age_days',
		'audit_post_min_words',
		'audit_post_require_media',
		'agency_logo',
		'agency_name',
		'agency_url',
		'delete_data_on_uninstall',
		'enable_caching',
		'enable_header_discovery',
		'enable_markdown_negotiation',
		'enable_webmcp',
		'agent_registration_mode',
		'enable_indexnow',
		'enable_shortcode',
		'enable_video_schema',
		'enable_websub',
		'inject_robots',
		'redirect_default_sitemap',
		'redirect_news_sitemap',
		'redirect_wp_sitemap',
		'report_theme',
		'site_name_override',
		'site_guide_instructions',
		'update_comment_page',
		'update_comment_post',
		'websub_hubs',
	);

	public function register_hooks(): void {
		add_action( 'admin_menu', array( SettingsAssets::class, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_cybermaps_settings', array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'add_option_cybermaps_settings', array( $this, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_cybermaps_discovery_center', array( $this, 'on_discovery_center_updated' ), 10, 2 );
		add_action( 'add_option_cybermaps_discovery_center', array( $this, 'on_discovery_center_added' ), 10, 2 );
		add_action( 'update_option_cybermaps_robots_manager', array( $this, 'on_robots_manager_updated' ), 10, 2 );
		add_action( 'add_option_cybermaps_robots_manager', array( $this, 'on_robots_manager_added' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( SettingsAssets::class, 'enqueue_scripts' ) );
		add_filter( 'plugin_action_links_' . CYBERMAPS_PLUGIN_BASENAME, array( SettingsAjax::class, 'add_plugin_action_links' ) );
		add_action( 'admin_notices', array( SettingsAjax::class, 'display_write_alerts' ) );

		add_action( 'wp_ajax_cybermaps_preview_config', array( SettingsAjax::class, 'ajax_preview_config' ) );
		add_action( 'wp_ajax_cybermaps_import_config', array( SettingsAjax::class, 'ajax_import_config' ) );
		add_action( 'admin_post_cybermaps_export_config', array( SettingsAjax::class, 'handle_export_config' ) );
		add_action( 'admin_post_cybermaps_verify_indexnow_key', array( $this, 'handle_verify_indexnow_key' ) );
		add_action( 'admin_post_cybermaps_resolve_static_intent', array( $this, 'handle_resolve_static_intent' ) );
		\Cybermaps\Admin\SetupWizard\SetupWizardController::register_hooks();

		$this->normalize_static_mode_setting();
	}

	/**
	 * Remove the retired boolean and persist the clean publication-mode model.
	 *
	 * This is a one-time data normalization, not a runtime compatibility path.
	 * Persisting the default also schedules stale full-cache files for
	 * ownership-safe reconciliation on installations that ran an earlier build.
	 */
	private function normalize_static_mode_setting(): void {
		$settings = get_option( 'cybermaps_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$changed = false;
		if ( array_key_exists( 'enable_static_engine', $settings ) ) {
			unset( $settings['enable_static_engine'] );
			$changed = true;
		}

		if ( ! isset( $settings['static_engine_mode'] ) || ! in_array( $settings['static_engine_mode'], array( 'off', 'well_known', 'all' ), true ) ) {
			$settings['static_engine_mode'] = 'well_known';
			$changed                        = true;
		}

		if ( $changed ) {
			update_option( 'cybermaps_settings', $settings, false );
		}
	}

	public function register_settings(): void {
		SettingsRegistrar::register_all( new SettingsPage() );
	}

	/**
	 * Adapt WordPress's dynamic add-option arguments to the update handler.
	 *
	 * Unlike update_option_{$option}, add_option_{$option} passes the option
	 * name first and the newly inserted value second.
	 *
	 * @param string $option    Option name supplied by WordPress.
	 * @param mixed  $new_value Newly inserted option value.
	 */
	public function on_settings_added( $option, $new_value ): void {
		unset( $option );
		$this->on_settings_updated( array(), $new_value );
	}

	/**
	 * Reconcile owned publications after any settings change.
	 *
	 * Static files served by Nginx, Apache, or a CDN bypass PHP and remain
	 * intentionally outside Core analytics. Disabling static publication removes
	 * owned copies; it does not guarantee that a server or cache routes requests
	 * through WordPress.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public function on_settings_updated( $old_value, $new_value ): void {
		if ( MigrationHub::is_applying_prepared_import() ) {
			return;
		}
		if ( $old_value === $new_value ) {
			return;
		}

		$old_settings = is_array( $old_value ) ? $old_value : array();
		$new_settings = is_array( $new_value ) ? $new_value : array();
		$old_mode     = \Cybermaps\Discovery\StaticBridge::get_mode( $old_settings );
		$new_mode     = \Cybermaps\Discovery\StaticBridge::get_mode( $new_settings );
		$old_hub      = ! empty( $old_settings['enable_discovery_hub'] );
		$new_hub      = ! empty( $new_settings['enable_discovery_hub'] );

		self::apply_setting_side_effects( $old_settings, $new_settings );
		if ( ! self::publications_changed( $old_settings, $new_settings ) ) {
			return;
		}

		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$bridge->invalidate();
		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		\Cybermaps\Core\CacheManager::clear_family( 'admin' );
		( new \Cybermaps\Sitemap\Orchestrator() )->invalidate_occupancy();
		self::reconcile_static_delivery( $bridge, $old_mode, $new_mode, $old_hub, $new_hub );
		\Cybermaps\Discovery\WellKnownRoutingBridge::request_reconciliation();
	}

	/**
	 * Apply targeted cache, rewrite, and media side effects for persisted changes.
	 *
	 * @param array<string,mixed> $old_settings Previous settings.
	 * @param array<string,mixed> $new_settings Updated settings.
	 */
	private static function apply_setting_side_effects( array $old_settings, array $new_settings ): void {
		self::apply_markdown_negotiation_side_effects( $old_settings, $new_settings );

		$old_routes = \Cybermaps\Sitemap\PublicationRouteSlugs::resolve( $old_settings );
		$new_routes = \Cybermaps\Sitemap\PublicationRouteSlugs::resolve( $new_settings );
		if (
			$old_routes['sitemap_url_base'] !== $new_routes['sitemap_url_base']
			|| $old_routes['news_sitemap_url_base'] !== $new_routes['news_sitemap_url_base']
		) {
			// Rewrite flushing belongs to the persisted-change hook, not the
			// sanitizer: previews and failed Settings API writes must be pure.
			add_action(
				'shutdown',
				static function (): void {
					\Cybermaps\Sitemap\Orchestrator::add_rewrite_rules();
					flush_rewrite_rules();
				}
			);
		}

		if (
			(string) ( $old_settings['media_discovery_intensity'] ?? 'none' )
			!== (string) ( $new_settings['media_discovery_intensity'] ?? 'none' )
		) {
			// Advance one site-wide generation instead of deleting an unbounded
			// postmeta set during a settings request. Consumers fail closed until
			// a post save or explicit rescan records the new generation and mode.
			\Cybermaps\Sitemap\MediaScanner::invalidate_audits();
		}

		$old_analytics = array_intersect_key( $old_settings, array_flip( array( 'enable_analytics', 'log_retention_days', 'anonymize_analytics_ips' ) ) );
		$new_analytics = array_intersect_key( $new_settings, array_flip( array( 'enable_analytics', 'log_retention_days', 'anonymize_analytics_ips' ) ) );
		if ( $old_analytics !== $new_analytics ) {
			\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
		}

		$chunk_keys = array_flip(
			array(
				'enable_rag_chunks',
				'rag_chunk_size',
				'rag_chunk_overlap',
			)
		);
		if (
			array_intersect_key( $old_settings, $chunk_keys )
			!== array_intersect_key( $new_settings, $chunk_keys )
		) {
			\Cybermaps\Core\CacheManager::clear_family( 'chunks' );
		}

		if (
			( $old_settings['enable_caching'] ?? null )
			!== ( $new_settings['enable_caching'] ?? null )
		) {
			// Re-enabling must not resurrect a sitemap transient that became
			// stale while cache reads were disabled.
			\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		}
	}

	/**
	 * Invalidate Markdown negotiation state when the feature changes.
	 *
	 * @param array<string, mixed> $old_settings Previous settings.
	 * @param array<string, mixed> $new_settings Updated settings.
	 */
	private static function apply_markdown_negotiation_side_effects( array $old_settings, array $new_settings ): void {
		if (
			! empty( $old_settings['enable_markdown_negotiation'] )
			=== ! empty( $new_settings['enable_markdown_negotiation'] )
		) {
			return;
		}

		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		\Cybermaps\Core\CacheManager::clear_family( 'admin' );
		( new \Cybermaps\Integration\EdgeCache\Coordinator() )->invalidate(
			'discovery',
			array( \Cybermaps\Core\URLManager::get_home_url( '/' ) )
		);
		\Cybermaps\Integration\EdgeCache\LiteSpeedAdapter::purge_all_for_negotiation_change();
	}

	/**
	 * Compare only settings that affect cached or materialized publications.
	 *
	 * @param array<string,mixed> $old_settings Previous settings.
	 * @param array<string,mixed> $new_settings Updated settings.
	 */
	private static function publications_changed( array $old_settings, array $new_settings ): bool {
		$non_publication_keys = array_merge(
			self::NO_PUBLICATION_RECONCILIATION_KEYS,
			array( 'enable_analytics', 'log_retention_days', 'anonymize_analytics_ips' )
		);
		foreach ( $non_publication_keys as $key ) {
			unset( $old_settings[ $key ], $new_settings[ $key ] );
		}
		return $old_settings !== $new_settings;
	}

	/**
	 * Reconcile physical publications after a publication-relevant change.
	 */
	private static function reconcile_static_delivery( \Cybermaps\Discovery\StaticBridge $bridge, string $old_mode, string $new_mode, bool $old_hub, bool $new_hub ): void {
		if ( 'off' === $new_mode ) {
			$purge = $bridge->purge_all();
			if ( empty( $purge['success'] ) ) {
				$bridge->request_sync( false, true );
			}
			return;
		}

		if ( $old_hub && ! $new_hub ) {
			$bridge->purge_all( '', '', 'discovery' );
		}

		if ( 'well_known' === $new_mode && 'all' === $old_mode ) {
			$bridge->purge_all( '', '', 'web_root' );
		}

		$bridge->request_sync();
	}

	/**
	 * Invalidate publications after the strategy matrix actually changes.
	 *
	 * The option sanitizer is deliberately side-effect free: WordPress may
	 * sanitize a value without persisting a change. Strategy data affects
	 * dynamic sitemap/discovery output and full-mode physical publications.
	 *
	 * @param mixed $old_value Previous encoded strategy.
	 * @param mixed $new_value Updated encoded strategy.
	 */
	public function on_discovery_center_updated( $old_value, $new_value ): void {
		if ( MigrationHub::is_applying_prepared_import() ) {
			return;
		}

		if ( $old_value === $new_value ) {
			return;
		}

		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		\Cybermaps\Core\CacheManager::clear_family( 'chunks' );

		( new \Cybermaps\Sitemap\Orchestrator() )->invalidate_occupancy();

		if ( 'all' !== \Cybermaps\Discovery\StaticBridge::get_mode() ) {
			return;
		}

		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$bridge->invalidate();
		$bridge->request_sync();
	}

	/**
	 * Handle first creation of the encoded discovery strategy.
	 *
	 * @param string $option    Option name supplied by WordPress.
	 * @param mixed  $new_value Newly inserted option value.
	 */
	public function on_discovery_center_added( $option, $new_value ): void {
		unset( $option );
		$this->on_discovery_center_updated( '', $new_value );
	}

	/**
	 * Republish discovery policy only when explicit LLM crawler permissions
	 * change. Robots text, content signals, and request throttles are dynamic
	 * and do not alter a materialized discovery representation.
	 *
	 * @param mixed $old_value Previous robots-manager option.
	 * @param mixed $new_value Updated robots-manager option.
	 */
	public function on_robots_manager_updated( $old_value, $new_value ): void {
		if ( MigrationHub::is_applying_prepared_import() ) {
			return;
		}

		if (
			$old_value === $new_value
			|| self::llm_override_map( $old_value ) === self::llm_override_map( $new_value )
		) {
			return;
		}

		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );

		if ( ! in_array( \Cybermaps\Discovery\StaticBridge::get_mode(), array( 'well_known', 'all' ), true ) ) {
			return;
		}

		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$bridge->invalidate();
		$bridge->request_sync();
	}

	/**
	 * Handle first creation of the structured crawler-policy option.
	 *
	 * @param string $option    Option name supplied by WordPress.
	 * @param mixed  $new_value Newly inserted option value.
	 */
	public function on_robots_manager_added( $option, $new_value ): void {
		unset( $option );
		$this->on_robots_manager_updated( array(), $new_value );
	}

	/**
	 * Extract the only robots-manager values embedded in discovery output.
	 *
	 * @param mixed $value Robots-manager option value.
	 * @return array<string, bool>
	 */
	private static function llm_override_map( $value ): array {
		$value     = is_array( $value ) ? $value : array();
		$overrides = is_array( $value['overrides'] ?? null )
			? $value['overrides']
			: array();
		$map       = array();
		$registry  = \Cybermaps\Core\CrawlerRegistry::get_policy_bots();
		$overrides = \Cybermaps\Core\CrawlerRegistry::normalize_overrides( $overrides );

		foreach ( $overrides as $crawler_id => $permissions ) {
			if ( ! is_array( $permissions ) || ! array_key_exists( 'llm', $permissions ) ) {
				continue;
			}

			$crawler_id = \Cybermaps\Core\CrawlerRegistry::canonicalize_id( sanitize_key( (string) $crawler_id ) );
			if (
				! isset( $registry[ $crawler_id ] )
				|| ! \Cybermaps\Core\CrawlerRegistry::supports_manifest_target( $registry[ $crawler_id ] )
			) {
				continue;
			}

			$map[ $crawler_id ] = (bool) $permissions['llm'];
		}

		ksort( $map );
		return $map;
	}

	/**
	 * Run a cached remote IndexNow key verification for headless frontends.
	 */
	public function handle_verify_indexnow_key(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cybermaps' ) );
		}
		check_admin_referer( 'cybermaps_verify_indexnow_key' );

		\Cybermaps\Discovery\IndexNow::verify_public_key_remote( true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'cybermaps-settings',
					'tab'  => 'sitemaps',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Resolve one exact static-operation journal after an operator quiesces all
	 * workers and supplies the intent-bound nonce plus confirmation phrase.
	 */
	public function handle_resolve_static_intent(): void {
		$request = self::static_intent_request();
		$result  = \Cybermaps\Discovery\StaticBridge::get_instance()->resolve_pending_intent(
			$request['intent_id'],
			$request['nonce'],
			$request['confirmation']
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                                => 'cybermaps-settings',
					'tab'                                 => $request['return_tab'],
					'cybermaps_intent_resolution'         => '1',
					'cybermaps_intent_resolution_success' => empty( $result['success'] ) ? '0' : '1',
					'cybermaps_intent_resolution_code'    => sanitize_key( (string) ( $result['code'] ?? 'recovery_incomplete' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Authorize and normalize one exact static-operation recovery request.
	 *
	 * @return array{intent_id:string,nonce:string,confirmation:string,return_tab:string}
	 */
	private static function static_intent_request(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Unauthorized', 'cybermaps' ),
				'',
				array( 'response' => 403 )
			);
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_die(
				esc_html__( 'Invalid request method.', 'cybermaps' ),
				'',
				array( 'response' => 405 )
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The intent ID selects the action-bound nonce verified immediately below.
		$intent_id = isset( $_POST['cybermaps_static_intent_id'] ) && is_scalar( $_POST['cybermaps_static_intent_id'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cybermaps_static_intent_id'] ) )
			: '';
		check_admin_referer(
			'cybermaps_recover_static_intent_' . $intent_id,
			'cybermaps_static_intent_nonce'
		);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The exact intent-bound nonce was verified immediately above.
		$nonce        = isset( $_POST['cybermaps_static_intent_nonce'] ) && is_scalar( $_POST['cybermaps_static_intent_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cybermaps_static_intent_nonce'] ) )
			: '';
		$confirmation = isset( $_POST['cybermaps_static_intent_confirmation'] ) && is_scalar( $_POST['cybermaps_static_intent_confirmation'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cybermaps_static_intent_confirmation'] ) )
			: '';
		$return_tab   = isset( $_POST['cybermaps_return_tab'] ) && is_scalar( $_POST['cybermaps_return_tab'] )
			? sanitize_key( wp_unslash( (string) $_POST['cybermaps_return_tab'] ) )
			: 'advanced';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $return_tab, array( 'dashboard', 'sitemaps', 'shortcode', 'ai', 'schema', 'review', 'advanced' ), true ) ) {
			$return_tab = 'advanced';
		}

		return array(
			'intent_id'    => $intent_id,
			'nonce'        => $nonce,
			'confirmation' => $confirmation,
			'return_tab'   => $return_tab,
		);
	}
}
