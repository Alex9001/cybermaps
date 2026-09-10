<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\SettingsRegistrar;
use PHPUnit\Framework\TestCase;

final class AdminActionContractAuditTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 2 );
	}

	public function test_every_registered_privileged_action_has_a_reachable_control(): void {
		$registrations = $this->source( 'src/Sitemap/Orchestrator.php' )
			. $this->source( 'src/Admin/ContentAuditManager.php' )
			. $this->source( 'src/Admin/DiscoveryAuditor.php' )
			. $this->source( 'src/Admin/Logs.php' )
			. $this->source( 'src/Admin/MediaAuditor.php' )
			. $this->source( 'src/Admin/NetworkSettings.php' )
			. $this->source( 'src/Admin/Settings.php' );

		$contracts = array(
			'admin_post_cybermaps_regenerate_sitemaps'     => 'src/Admin/SettingsPage.php',
			'admin_post_cybermaps_run_content_audit'       => 'src/Admin/Settings/Tabs/ContentReview.php',
			'admin_post_cybermaps_delete_content_audit'    => 'src/Admin/Settings/Tabs/ContentReview.php',
			'admin_post_cybermaps_export_content_audit'    => 'src/Admin/Settings/Tabs/ContentReview.php',
			'admin_post_cybermaps_export_discovery_report' => 'src/Admin/Settings/Tabs/ContentReview.php',
			'admin_post_cybermaps_clear_logs'              => 'src/Admin/DiscoveryAnalytics.php',
			'admin_post_cybermaps_export_logs'             => 'src/Admin/DiscoveryAnalytics.php',
			'admin_post_cybermaps_export_config'           => 'src/Admin/Settings/SettingsAssets.php',
			'admin_post_cybermaps_resolve_static_intent'   => 'src/Admin/SettingsPage.php',
			'network_admin_edit_cybermaps_save_network_settings' => 'src/Admin/NetworkSettings.php',
			'wp_ajax_cybermaps_preview_config'             => 'assets/js/admin-command-center.js',
			'wp_ajax_cybermaps_import_config'              => 'assets/js/admin-command-center.js',
			'wp_ajax_cybermaps_get_sync_stats'             => 'assets/js/media-auditor.js',
			'wp_ajax_cybermaps_process_sync_batch'         => 'assets/js/media-auditor.js',
			'wp_ajax_cybermaps_scan_blueprint'             => 'assets/js/discovery-matrix-svg.js',
		);

		foreach ( $contracts as $hook => $control_file ) {
			$action = preg_replace( '/^(?:admin_post|wp_ajax|network_admin_edit)_/', '', $hook );
			$this->assertIsString( $action );
			$this->assertStringContainsString( "'" . $hook . "'", $registrations, $hook . ' must remain registered.' );
			$this->assertStringContainsString(
				$action,
				$this->source( $control_file ),
				$hook . ' must remain reachable from its admin control.'
			);
		}
	}

	public function test_destructive_admin_post_actions_enforce_post_and_nonce_contracts(): void {
		$settings_page = $this->source( 'src/Admin/SettingsPage.php' );
		$this->assertStringContainsString( '<form method="post" action="<?php echo esc_url( admin_url( \'admin-post.php\' ) ); ?>">', $settings_page );
		$this->assertStringContainsString( 'name="action" value="cybermaps_regenerate_sitemaps"', $settings_page );
		$this->assertStringContainsString( 'name="cybermaps_return_tab"', $settings_page );
		$this->assertStringContainsString( "wp_nonce_field( 'cybermaps_regenerate', 'cybermaps_regenerate_nonce' )", $settings_page );
		$this->assertStringNotContainsString( 'admin-post.php?action=cybermaps_regenerate_sitemaps', $settings_page );
		$this->assertStringContainsString( 'name="action" value="cybermaps_resolve_static_intent"', $settings_page );

		$settings_source = $this->source( 'src/Admin/Settings.php' );
		$settings        = $this->method_source( $settings_source, 'handle_resolve_static_intent' );
		$static_request  = $this->method_source( $settings_source, 'static_intent_request' );
		$this->assertStringContainsString( 'self::static_intent_request()', $settings );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $static_request );
		$this->assertStringContainsString( "'POST' !== \$request_method", $static_request );
		$this->assertStringContainsString( 'check_admin_referer(', $static_request );
		$this->assertStringContainsString( 'cybermaps_recover_static_intent_', $static_request );
		$this->assertStringContainsString( "'response' => 405", $static_request );

		$regeneration = $this->method_source( $this->source( 'src/Sitemap/Orchestrator.php' ), 'handle_regeneration' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $regeneration );
		$this->assertStringContainsString( "'POST' !== \$request_method", $regeneration );
		$this->assertStringContainsString( "check_admin_referer( 'cybermaps_regenerate', 'cybermaps_regenerate_nonce' )", $regeneration );
		$this->assertStringContainsString( "\$_POST['cybermaps_return_tab']", $regeneration );
		$this->assertStringContainsString( "'tab'  => \$return_tab", $regeneration );
		$this->assertStringContainsString( "is_scalar( \$_SERVER['REQUEST_METHOD'] )", $regeneration );
		$this->assertStringContainsString( "'response' => 405", $regeneration );

		$manager = $this->source( 'src/Admin/ContentAuditManager.php' );
		$delete  = $this->method_source( $manager, 'handle_delete' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $delete );
		$this->assertStringContainsString( "'POST' !== \$request_method", $delete );
		$this->assertStringContainsString( 'cybermaps_delete_content_audit_', $delete );
		$this->assertStringContainsString( "'response' => 405", $delete );

		$content_review = $this->source( 'src/Admin/Settings/Tabs/ContentReview.php' );
		$this->assertStringContainsString( 'value="cybermaps_delete_content_audit"', $content_review );
		$this->assertStringContainsString( 'form="cybermaps-run-content-audit-form"', $content_review );
		$this->assertStringContainsString( 'form="cybermaps-delete-content-audit-form"', $content_review );
		$this->assertStringNotContainsString( 'formaction=', $content_review );

		$this->assertStringContainsString( 'id="cybermaps-run-content-audit-form"', $settings_page );
		$this->assertStringContainsString( 'id="cybermaps-delete-content-audit-form"', $settings_page );
	}

	public function test_sidebar_links_use_canonical_publication_url_resolvers(): void {
		$settings_page = $this->source( 'src/Admin/SettingsPage.php' );

		$this->assertStringContainsString( 'EndpointRegistry::get_instance()', $settings_page );
		foreach (
			array(
				'llms',
				'llms_full',
				'llms_tldr',
				'manifest',
				'ai_sitemap',
				'knowledge_graph',
				'feed',
				'usage_policy',
				'actions',
				'skill',
				'api_catalog',
				'rest_search',
			) as $endpoint_id
		) {
			$this->assertStringContainsString( "\$registry->get_url( '" . $endpoint_id . "' )", $settings_page );
		}
		$this->assertStringNotContainsString( "\$home . '.well-known/", $settings_page );
		$this->assertStringContainsString( 'URLManager::get_home_url', $settings_page );
		$this->assertStringContainsString( '$rest_search_example_url = add_query_arg(', $settings_page );
		$this->assertStringContainsString( "'q'     => 'site'", $settings_page );
		$this->assertStringContainsString( "'limit' => 10", $settings_page );
		$this->assertStringContainsString( 'esc_url( $rest_search_example_url )', $settings_page );

		$mapper = $this->source( 'src/Admin/SVGMapper.php' );
		$this->assertStringContainsString( 'URLManager::get_home_url()', $mapper );
		$this->assertStringNotContainsString( "', '', home_url() )", $mapper );
	}

	public function test_network_and_ajax_mutations_reject_non_post_requests(): void {
		$network = $this->source( 'src/Admin/NetworkSettings.php' );
		$this->assertStringContainsString( '<form method="post" action="edit.php?action=cybermaps_save_network_settings">', $network );
		$network_save = $this->method_source( $network, 'save_network_settings' );
		$this->assertStringContainsString( "current_user_can( 'manage_network_options' )", $network_save );
		$this->assertStringContainsString( "'POST' !== \$request_method", $network_save );
		$this->assertStringContainsString( "check_admin_referer( 'cybermaps_network_settings_save' )", $network_save );

		$settings_ajax = $this->source( 'src/Admin/Settings/SettingsAjax.php' );
		$this->assertStringContainsString( 'self::require_post_request();', $this->method_source( $settings_ajax, 'ajax_preview_config' ) );
		$this->assertStringContainsString( 'self::require_post_request();', $this->method_source( $settings_ajax, 'ajax_import_config' ) );
		$post_guard = $this->method_source( $settings_ajax, 'require_post_request' );
		$this->assertStringContainsString( "'POST' !== \$request_method", $post_guard );
		$this->assertStringContainsString( '405', $post_guard );

		$media_source = $this->source( 'src/Admin/MediaAuditor.php' );
		$media_batch  = $this->method_source( $media_source, 'process_sync_batch' );
		$media_stats  = $this->method_source( $media_source, 'get_sync_stats' );
		$media_access = $this->method_source( $media_source, 'require_ajax_access' );
		$this->assertStringContainsString( 'self::require_ajax_access();', $media_stats );
		$this->assertStringContainsString( 'self::require_ajax_access();', $media_batch );
		$this->assertStringContainsString( "'POST' !== \$request_method", $media_access );
		$this->assertStringContainsString( "check_ajax_referer( 'cybermaps_media_sync', 'nonce' )", $media_access );
		$this->assertStringContainsString( 'null === $total_result || false === $total_result', $media_stats );
		$this->assertStringContainsString( 'self::send_database_error();', $media_stats );
		$this->assertStringContainsString( "is_scalar( \$_POST['cursor'] )", $media_batch );
		$this->assertStringContainsString( '! is_array( $ids )', $media_batch );
		$this->assertStringContainsString( 'self::send_database_error();', $media_batch );
		$this->assertStringContainsString( '405', $media_access );
		$this->assertStringContainsString( 'The media audit database query failed.', $media_source );
		$this->assertStringNotContainsString( '$wpdb->last_error', $media_source );

		$blueprint = $this->method_source( $this->source( 'src/Admin/DiscoveryAuditor.php' ), 'ajax_scan_blueprint' );
		$this->assertStringContainsString( "'POST' !== \$request_method", $blueprint );
		$this->assertStringContainsString( "check_ajax_referer( 'cybermaps_discovery_center', 'nonce' )", $blueprint );
	}

	public function test_media_rescan_cannot_silently_use_an_unsaved_discovery_mode(): void {
		$assets = $this->source( 'src/Admin/Settings/SettingsAssets.php' );
		$script = $this->source( 'assets/js/media-auditor.js' );

		$this->assertStringContainsString( "'savedIntensity'", $assets );
		$this->assertStringContainsString( "'saveFirstNotice'", $assets );
		$this->assertStringContainsString( 'selectedIntensity !== savedIntensity', $script );
		$this->assertStringContainsString( "savedIntensity === 'none'", $script );
	}

	public function test_media_rescan_rejects_malformed_or_unknown_saved_modes(): void {
		$normalizer = new \ReflectionMethod( \Cybermaps\Admin\MediaAuditor::class, 'normalize_intensity' );

		$this->assertSame( 'standard', $normalizer->invoke( null, 'standard' ) );
		$this->assertSame( 'advanced', $normalizer->invoke( null, 'advanced' ) );
		$this->assertSame( 'none', $normalizer->invoke( null, 'unexpected' ) );
		$this->assertSame( 'none', $normalizer->invoke( null, array( 'advanced' ) ) );
		$this->assertSame( 'none', $normalizer->invoke( null, new \stdClass() ) );
	}

	public function test_dashboard_analytics_widget_uses_the_same_capability_as_the_analytics_page(): void {
		$logs = $this->source( 'src/Admin/Logs.php' );

		$this->assertStringContainsString(
			"current_user_can( 'manage_options' )",
			$this->method_source( $logs, 'register_dashboard_widget' )
		);
		$this->assertStringContainsString(
			"current_user_can( 'manage_options' )",
			$this->method_source( $logs, 'render_widget_content' )
		);
	}

	public function test_standalone_analytics_form_cannot_clear_unsubmitted_plugin_options(): void {
		$analytics = $this->source( 'src/Admin/DiscoveryAnalytics.php' );
		$registrar = $this->source( 'src/Admin/Settings/SettingsRegistrar.php' );

		$this->assertStringContainsString( 'SettingsRegistrar::ANALYTICS_OPTIONS_GROUP', $analytics );
		$this->assertStringNotContainsString( "settings_fields( 'cybermaps_options_group' )", $analytics );
		$this->assertStringContainsString(
			"public const ANALYTICS_OPTIONS_GROUP = 'cybermaps_analytics_options_group';",
			$registrar
		);
		$this->assertStringContainsString(
			"add_filter( 'allowed_options', array( self::class, 'allow_analytics_option' ) );",
			$registrar
		);
		$this->assertStringContainsString(
			"\$allowed_options[ self::ANALYTICS_OPTIONS_GROUP ][] = 'cybermaps_settings';",
			$registrar
		);
		$this->assertSame(
			1,
			preg_match_all(
				"/register_setting\\(\\s*'cybermaps_options_group',\\s*'cybermaps_settings',/",
				$registrar,
				$matches
			)
		);
		$this->assertStringNotContainsString(
			"register_setting(\n            self::ANALYTICS_OPTIONS_GROUP",
			$registrar
		);
		$this->assertSame( 1, substr_count( $registrar, "array( SettingsSanitizer::class, 'sanitize' )" ) );
	}

	public function test_analytics_allowed_options_filter_is_additive_and_idempotent(): void {
		$allowed = SettingsRegistrar::allow_analytics_option(
			array(
				'general' => array( 'siteurl' ),
			)
		);

		$this->assertSame( array( 'siteurl' ), $allowed['general'] );
		$this->assertSame(
			array( 'cybermaps_settings' ),
			$allowed[ SettingsRegistrar::ANALYTICS_OPTIONS_GROUP ]
		);
		$this->assertSame(
			$allowed,
			SettingsRegistrar::allow_analytics_option( $allowed )
		);

		$existing = SettingsRegistrar::allow_analytics_option(
			array(
				SettingsRegistrar::ANALYTICS_OPTIONS_GROUP => array( 'another_plugin_option' ),
			)
		);
		$this->assertSame(
			array( 'another_plugin_option', 'cybermaps_settings' ),
			$existing[ SettingsRegistrar::ANALYTICS_OPTIONS_GROUP ]
		);
	}

	public function test_main_settings_page_surfaces_settings_api_feedback(): void {
		$settings_page = $this->source( 'src/Admin/SettingsPage.php' );

		$this->assertStringContainsString( 'settings_errors();', $settings_page );
		$this->assertStringContainsString( "settings_fields( 'cybermaps_options_group' )", $settings_page );
	}

	public function test_admin_copy_matches_the_controls_and_runtime_bounds(): void {
		$sitemaps  = $this->source( 'src/Admin/Settings/Tabs/Sitemaps.php' );
		$sections  = $this->source( 'src/Admin/Settings/Tabs/Sitemaps/SitemapsSections.php' );
		$discovery = $this->source( 'src/Admin/Settings/Tabs/Discovery.php' );

		$this->assertStringContainsString( "esc_html_e( 'Content Discovery Strategy', 'cybermaps' )", $sitemaps );
		$this->assertStringContainsString( 'self::render_discovery_center();', $sections );
		$this->assertStringContainsString( "esc_html_e( 'Content Scope', 'cybermaps' )", $sitemaps );
		$this->assertStringContainsString( "esc_html_e( 'Media Discovery', 'cybermaps' )", $sitemaps );
		$this->assertStringContainsString( "esc_html_e( 'Language & Translation', 'cybermaps' )", $sitemaps );
		$this->assertSame( 6, substr_count( $sitemaps, 'class="cybermaps-panel-card cm-sitemap-panel"' ) );
		$section_positions = array(
			strpos( $sitemaps, 'id="cybermaps_optimization_section"' ),
			strpos( $sitemaps, 'id="cybermaps_scope_section"' ),
			strpos( $sitemaps, 'id="cybermaps_general_section"' ),
			strpos( $sitemaps, 'id="cybermaps_media_section"' ),
			strpos( $sitemaps, 'id="cybermaps_indexing_section"' ),
			strpos( $sitemaps, 'id="cybermaps_international_section"' ),
		);
		$this->assertNotContains( false, $section_positions );
		$sorted_positions = $section_positions;
		sort( $sorted_positions );
		$this->assertSame( $sorted_positions, $section_positions );
		$this->assertStringContainsString( 'Uses public content types and published-item counts', $sections );
		$this->assertStringContainsString( 'Taxonomies—including Post Formats—represent archive pages', $sections );
		$this->assertStringContainsString( 'Send WebSub Feed Updates', $sections );
		$this->assertStringContainsString( "esc_html_e( 'Commercial', 'cybermaps' )", $sections );
		$this->assertStringNotContainsString( 'RFC 7033', $sections );
		$this->assertStringContainsString( 'comma-separated short topic labels', $discovery );
		$this->assertStringNotContainsString( 'characters each', $discovery );
		$this->assertStringContainsString( 'PublicationPostTypes::objects()', $discovery );
		$this->assertMatchesRegularExpression( '/\'options\'\s*=>\s*\$llms_type_options/', $discovery );
		$this->assertStringContainsString( 'every eligible resource in the selected content types', $discovery );
		$this->assertStringNotContainsString( 'every eligible post', $discovery );
		$this->assertStringNotContainsString( 'first-demand scanning', $sections );
		$this->assertStringNotContainsString( 'hide from all sitemaps', $sections );
		$this->assertStringNotContainsString( 'affect every sitemap variant', $sections );
		$this->assertStringNotContainsString( 'network-wide</span>', $sections );

		$analytics = $this->source( 'src/Admin/DiscoveryAnalytics.php' );
		$this->assertStringContainsString( 'Cybermaps diagnostic probes are excluded.', $analytics );
		$this->assertStringContainsString( 'Diagnostic probes are excluded.', $analytics );
		$this->assertStringNotContainsString( 'Records every registered endpoint request', $analytics );
		$this->assertStringNotContainsString( 'Every registered endpoint request observed', $analytics );
	}

	public function test_admin_hash_scrolling_uses_literal_element_ids(): void {
		$script = $this->source( 'assets/js/admin-command-center.js' );

		$this->assertStringContainsString( 'document.getElementById( targetId )', $script );
		$this->assertStringNotContainsString( 'document.querySelector( hash )', $script );
	}

	public function test_shortcode_builder_emits_kind_aware_selection_tokens(): void {
		$builder          = $this->source( 'src/Admin/ShortcodeBuilder.php' );
		$script           = $this->source( 'assets/js/shortcode-builder.js' );
		$post_type_option = $this->method_source( $builder, 'render_post_type_option' );
		$taxonomy_option  = $this->method_source( $builder, 'render_taxonomy_option' );

		$this->assertStringContainsString( "'post_type:' . \$post_type->name", $post_type_option );
		$this->assertStringContainsString( "'taxonomy:' . \$taxonomy->name", $taxonomy_option );
		$this->assertStringContainsString( 'data-label=', $builder );
		$this->assertStringContainsString( 'tokenLabels[token]', $script );
		$this->assertStringContainsString( '<code>post_type:*</code> and <code>taxonomy:*</code> are kind wildcards', $builder );
	}

	public function test_html_sitemap_availability_has_a_nearby_truthful_save_action(): void {
		$builder = $this->source( 'src/Admin/ShortcodeBuilder.php' );

		$this->assertStringContainsString( 'Save HTML Sitemap availability', $builder );
		$this->assertStringContainsString( 'Only this availability toggle is saved here.', $builder );
		$this->assertStringContainsString( 'button button-primary cm-shortcode-save-button', $builder );
	}

	public function test_html_sitemap_builder_uses_dense_accessible_output_and_reference_layouts(): void {
		$builder = $this->source( 'src/Admin/ShortcodeBuilder.php' );
		$styles  = $this->source( 'assets/css/admin-command-center.css' );

		$this->assertStringContainsString( 'class="cm-shortcode-output-card"', $builder );
		$this->assertStringContainsString( 'aria-controls="cm-shortcode-preview"', $builder );
		$this->assertStringContainsString( 'class="cm-shortcode-attribute-grid"', $builder );
		$this->assertSame( 8, substr_count( $builder, 'class="cm-shortcode-attribute-card' ) );
		$this->assertStringNotContainsString( 'cm-shortcode-attributes-table', $builder );
		$this->assertStringContainsString( '.cm-shortcode-copy-button', $styles );
		$this->assertStringContainsString( 'background: #135e96;', $styles );
		$this->assertStringContainsString( '.cm-shortcode-attribute-grid', $styles );
		$this->assertStringContainsString( 'border-inline-start:', $styles );
		$this->assertStringContainsString( 'text-align: start;', $styles );
	}

	public function test_shortcode_builder_preview_numbers_cannot_block_settings_form_validation(): void {
		$builder = $this->source( 'src/Admin/ShortcodeBuilder.php' );
		$script  = $this->source( 'assets/js/shortcode-builder.js' );

		$this->assertStringContainsString( 'type="text" inputmode="numeric" class="cm-sc-limit"', $builder );
		$this->assertStringContainsString( 'type="text" inputmode="numeric" class="cm-sc-depth"', $builder );
		$this->assertStringNotContainsString( 'type="number" class="cm-sc-limit"', $builder );
		$this->assertStringNotContainsString( 'type="number" class="cm-sc-depth"', $builder );
		$this->assertStringContainsString( 'function boundedInteger(input, fallback)', $script );
		$this->assertStringContainsString( "container.addEventListener('focusout'", $script );
	}

	public function test_active_tab_redirect_filter_preserves_server_rendered_workspace(): void {
		$plugin = $this->source( 'src/Core/Plugin.php' );
		$review = $this->source( 'src/Admin/ContentAuditManager.php' );

		$this->assertStringContainsString( "'preserve_settings_tab_query'", $plugin );
		$this->assertStringContainsString( "add_query_arg( 'tab', \$tab, \$location )", $plugin );
		$this->assertStringNotContainsString( ") . '#review'", $review );

		$previous_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture snapshots request data before exercising the redirect filter.
		try {
			$_POST['cybermaps_active_tab'] = 'review';

			$destination = 'https://example.test/wp-admin/admin.php?page=cybermaps-settings&tab=review#review';
			$this->assertSame(
				$destination,
				\Cybermaps\Core\Plugin::preserve_settings_tab_query( $destination )
			);

			$_POST['cybermaps_active_tab'] = 'ai';
			$this->assertSame(
				'https://example.test/wp-admin/admin.php?page=cybermaps-settings&tab=ai',
				\Cybermaps\Core\Plugin::preserve_settings_tab_query(
					'https://example.test/wp-admin/admin.php?page=cybermaps-settings'
				)
			);

			$_POST['cybermaps_active_tab'] = 'shortcode';
			$this->assertSame(
				'https://example.test/wp-admin/admin.php?page=cybermaps-settings&tab=shortcode',
				\Cybermaps\Core\Plugin::preserve_settings_tab_query(
					'https://example.test/wp-admin/admin.php?page=cybermaps-settings'
				)
			);

			$_POST['cybermaps_active_tab'] = 'schema';
			$this->assertSame(
				'https://example.test/wp-admin/admin.php?page=cybermaps-settings&tab=schema',
				\Cybermaps\Core\Plugin::preserve_settings_tab_query(
					'https://example.test/wp-admin/admin.php?page=cybermaps-settings'
				)
			);

			$_POST['cybermaps_active_tab'] = 'not-a-real-tab';
			$this->assertSame(
				'https://example.test/wp-admin/admin.php?page=cybermaps-settings',
				\Cybermaps\Core\Plugin::preserve_settings_tab_query(
					'https://example.test/wp-admin/admin.php?page=cybermaps-settings'
				)
			);
		} finally {
			$_POST = $previous_post;
		}
	}

	public function test_translation_meta_save_is_scoped_to_supported_non_revision_posts(): void {
		$source = $this->source( 'src/Admin/InternationalPanel.php' );
		$save   = $this->method_source( $source, 'save_meta_box_data' );
		$guard  = $this->method_source( $source, 'save_settings_if_allowed' );

		$this->assertStringContainsString( '$this->save_settings_if_allowed( (int) $post_id )', $save );
		$this->assertStringContainsString( 'wp_is_post_revision( $post_id )', $guard );
		$this->assertStringContainsString( 'wp_is_post_autosave( $post_id )', $guard );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$post_id )", $guard );
		$this->assertStringContainsString( 'PublicationPostTypes::contains', $guard );
		$this->assertStringContainsString( "empty( \$settings['enable_translation_integrations'] )", $guard );
	}

	public function test_discovery_scope_meta_save_validates_scalar_controls_and_post_scope(): void {
		$source = $this->source( 'src/Admin/DiscoveryScope.php' );
		$save   = $this->method_source( $source, 'save_meta_box_data' );
		$guard  = $this->method_source( $source, 'can_save_meta_box' );

		$this->assertStringContainsString( 'self::can_save_meta_box( (int) $post_id )', $save );
		$this->assertStringContainsString( "is_scalar( \$_POST['cybermaps_discovery_scope_nonce'] )", $guard );
		$this->assertStringContainsString( 'wp_is_post_revision( $post_id )', $guard );
		$this->assertStringContainsString( 'wp_is_post_autosave( $post_id )', $guard );
		$this->assertStringContainsString( 'PublicationPostTypes::contains', $guard );
		$this->assertStringContainsString( "current_user_can( 'edit_post', \$post_id )", $guard );
		foreach (
			array(
				'cybermaps_include_sitemap',
				'cybermaps_include_ai',
				'cybermaps_intent_override',
				'cybermaps_sitemap_priority',
				'cybermaps_sitemap_changefreq',
			) as $control
		) {
			$this->assertStringContainsString( "is_scalar( \$_POST['" . $control . "'] )", $save );
		}
	}

	private function source( string $relative_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tests read local project source files, not remote resources.
		$source = file_get_contents( $this->root . '/' . $relative_path );
		$this->assertIsString( $source, $relative_path . ' must be readable.' );
		return (string) $source;
	}

	private function method_source( string $source, string $method ): string {
		$start = strpos( $source, 'function ' . $method . '(' );
		$this->assertNotFalse( $start, $method . ' must exist.' );

		$brace = strpos( $source, '{', (int) $start );
		$this->assertNotFalse( $brace, $method . ' must have a body.' );

		$depth  = 0;
		$length = strlen( $source );
		for ( $offset = (int) $brace; $offset < $length; $offset++ ) {
			if ( '{' === $source[ $offset ] ) {
				++$depth;
			} elseif ( '}' === $source[ $offset ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $source, (int) $start, $offset - (int) $start + 1 );
				}
			}
		}

		$this->fail( $method . ' has an unterminated body.' );
	}
}
