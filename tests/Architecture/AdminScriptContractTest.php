<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class AdminScriptContractTest extends TestCase {
	public function test_sitemap_controls_import_sprintf_and_describe_the_real_default(): void {
		$root = dirname( __DIR__, 2 );
		$script = (string) file_get_contents(
			$root . '/assets/js/sitemap-exclusion.js'
		);
		$classic = (string) file_get_contents( $root . '/src/Admin/DiscoveryScope.php' );

		$this->assertStringContainsString( 'const { __, sprintf } = wp.i18n;', $script );
		$this->assertStringContainsString( 'Uses the default weekly change frequency.', $script );
		$this->assertStringContainsString( 'Default (weekly)', $script );
		$this->assertStringContainsString(
			"setMeta( '_cybermaps_sitemap_priority', val > 0 ? val : 0 )",
			$script
		);
		$this->assertStringNotContainsString(
			"setMeta( '_cybermaps_sitemap_priority', val > 0 ? val.toString() : '' )",
			$script
		);
		$this->assertStringContainsString( 'legacyComponentSizing', $script );
		$this->assertStringContainsString( '__next40pxDefaultSize: true', $script );
		$this->assertSame(
			4,
			substr_count( $script, '...legacyComponentSizeProps' ),
			'Both SelectControls, the RangeControl, and the TextControl must retain the WordPress 7.0 size opt-in.'
		);
		$this->assertStringContainsString( 'const { PluginDocumentSettingPanel } = wp.editPost;', $script );
		$this->assertStringNotContainsString( 'wp.editor', $script );
		$this->assertStringNotContainsString( 'Auto-detected by content type.', $script );
		$this->assertStringContainsString( 'Use content-group default', $script );
		$this->assertStringContainsString( "Uses this content group's publication weight.", $script );
		$this->assertStringNotContainsString( 'Research (informational)', $script );
		$this->assertStringNotContainsString( 'Conversion (transactional)', $script );
		$this->assertStringNotContainsString( 'Auto-calculated from archetype + content signals', $script );
		$this->assertStringContainsString( 'No item-level XML exclusion is set.', $script );
		$this->assertStringContainsString( 'Content-group Publish and other sitemap rules still determine eligibility.', $script );
		$this->assertStringContainsString( 'No item-level AI exclusion is set.', $script );
		$this->assertStringContainsString( 'Content-group Publish and AI publication settings still determine eligibility.', $script );
		$this->assertStringContainsString( 'Allow in XML sitemaps at item level', $classic );
		$this->assertStringContainsString( 'Allow in AI discovery at item level', $classic );
		$this->assertStringContainsString( 'still determine eligibility', $classic );
	}

	public function test_manual_regeneration_clears_all_publication_cache_families(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'src/CLI/Command.php', 'src/Sitemap/Orchestrator.php' ) as $relative ) {
			$source = (string) file_get_contents( $root . '/' . $relative );
			$this->assertStringContainsString(
				"CacheManager::clear_family( 'discovery' )",
				$source,
				$relative
			);
			$this->assertStringContainsString(
				"CacheManager::clear_family( 'chunks' )",
				$source,
				$relative
			);
		}
	}

	public function test_report_logo_picker_accepts_images_only(): void {
		$script = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/assets/js/admin-command-center.js'
		);

		$this->assertStringContainsString( "library: { type: 'image' }", $script );
	}

	public function test_wordpress_70_toggletip_behavior_is_scoped_and_keyboard_accessible(): void {
		$root   = dirname( __DIR__, 2 );
		$script = (string) file_get_contents( $root . '/assets/js/admin-command-center.js' );
		$styles = (string) file_get_contents( $root . '/assets/css/admin-command-center.css' );

		$this->assertStringContainsString( 'initLegacyToggletips', $script );
		$this->assertStringContainsString( "'Escape' !== event.key", $script );
		$this->assertStringContainsString( "setAttribute( 'aria-expanded'", $script );
		$this->assertStringContainsString( 'closeToggletip( bubble, true )', $script );
		$this->assertStringContainsString( '.cybermaps-legacy-toggletip__bubble[hidden]', $styles );
		$this->assertStringContainsString( '.cybermaps-legacy-toggletip__toggle:focus-visible', $styles );
	}

	public function test_settings_page_renders_only_the_requested_tab(): void {
		$root   = dirname( __DIR__, 2 );
		$page   = (string) file_get_contents( $root . '/src/Admin/SettingsPage.php' );
		$script = (string) file_get_contents( $root . '/assets/js/admin-command-center.js' );

		$this->assertStringContainsString( '$this->tabs[ $active_tab ]->render();', $page );
		$this->assertStringContainsString( 'aria-current="page"', $page );
		$this->assertStringNotContainsString(
			'<?php foreach ( $this->tabs as $slug => $tab ) : ?>' . "\n"
				. '                            <div',
			$page
		);
		$this->assertStringNotContainsString( 'useTabManager', $script );
		$this->assertStringNotContainsString( 'cybermaps-tab-content:not(.active)', $script );
	}

	public function test_settings_assets_scope_heavy_helpers_to_their_workspace(): void {
		$assets = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/SettingsAssets.php'
		);
		$identity = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/IdentityHub.php'
		);

		$this->assertStringContainsString( "if ( 'review' === \$active_tab )", $assets );
		$this->assertStringContainsString( "if ( 'advanced' === \$active_tab )", $assets );
		$this->assertStringContainsString( "if ( 'shortcode' === \$active_tab )", $assets );
		$this->assertStringContainsString( "if ( 'sitemaps' !== \$active_tab )", $assets );
		$this->assertStringContainsString( 'wp_enqueue_media();', $assets );
		$this->assertStringContainsString( "'cybermaps-media-auditor'", $assets );
		$this->assertStringContainsString( "if ( 'schema' !== \$active_tab )", $identity );
		$this->assertSame( 2, substr_count( $assets, "'cybermaps-shortcode-builder'" ) );
	}

	public function test_html_sitemap_is_a_first_class_settings_workspace(): void {
		$root      = dirname( __DIR__, 2 );
		$page      = (string) file_get_contents( $root . '/src/Admin/SettingsPage.php' );
		$sitemaps = (string) file_get_contents( $root . '/src/Admin/Settings/Tabs/Sitemaps.php' );
		$builder   = (string) file_get_contents( $root . '/src/Admin/ShortcodeBuilder.php' );

		$this->assertMatchesRegularExpression( "/'shortcode'\\s*=>\\s*new Tabs\\\\Shortcode\\(\\)/", $page );
		$this->assertStringNotContainsString( 'cybermaps-shortcode-builder', $sitemaps );
		$this->assertStringContainsString( 'id="cybermaps-shortcode-builder"', $builder );
		$this->assertStringContainsString( "return 'cybermaps-shortcode';", (string) file_get_contents( $root . '/src/Admin/Settings/Tabs/Shortcode.php' ) );
	}

	public function test_schema_is_a_first_class_settings_workspace(): void {
		$root      = dirname( __DIR__, 2 );
		$page      = (string) file_get_contents( $root . '/src/Admin/SettingsPage.php' );
		$identity  = (string) file_get_contents( $root . '/src/Admin/Settings/Tabs/Identity.php' );
		$discovery = (string) file_get_contents( $root . '/src/Admin/Settings/Tabs/Discovery.php' );
		$script    = (string) file_get_contents( $root . '/assets/js/admin-command-center.js' );

		$this->assertMatchesRegularExpression( "/'schema'\\s*=>\\s*new Tabs\\\\Identity\\(\\)/", $page );
		$this->assertStringContainsString( "return 'schema';", $identity );
		$this->assertStringContainsString( "return __( 'Schema', 'cybermaps' );", $identity );
		$this->assertStringNotContainsString( '( new Identity() )->render()', $discovery );
		$this->assertStringContainsString( 'class="cm-ai-robots-workspace"', $discovery );
		$this->assertStringNotContainsString( '<details class="cm-card cm-mb-25">', $discovery );
		$this->assertStringContainsString( "panel.id === 'cybermaps-tab-schema'", $script );
	}

	public function test_content_strategy_controls_match_the_saved_runtime_model(): void {
		$root     = dirname( __DIR__, 2 );
		$sections = (string) file_get_contents( $root . '/src/Admin/Settings/Tabs/Sitemaps/SitemapsSections.php' );
		$script   = (string) file_get_contents( $root . '/assets/js/discovery-matrix-svg.js' );

		$this->assertStringContainsString( 'Post types — individual content', $sections );
		$this->assertStringContainsString( 'Taxonomies — archive groupings', $sections );
		$this->assertStringContainsString( 'min="0.1" max="1.0"', $sections );
		$this->assertStringContainsString( "esc_html__( 'Baseline', 'cybermaps' )", $sections );
		$this->assertStringContainsString( 'Save content strategy', $sections );
		$this->assertStringContainsString( 'parseFloat(this.value)', $script );
		$this->assertStringNotContainsString( 'parseInt(this.value, 10) / 100', $script );
		$this->assertStringContainsString( 'response.data.reason', $script );
		$this->assertStringContainsString( "window.addEventListener('beforeunload'", $script );
	}

	public function test_ai_structured_options_are_compacted_before_submission(): void {
		$script = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/assets/js/admin-command-center.js'
		);

		$this->assertStringContainsString( 'serializeStructuredOption', $script );
		$this->assertStringContainsString(
			"serializeStructuredOption( 'cybermaps_identity_data', 'cybermaps-identity-json-payload' )",
			$script
		);
		$this->assertStringContainsString(
			"serializeStructuredOption( 'cybermaps_robots_manager', 'cybermaps-robots-json-payload' )",
			$script
		);
		$this->assertStringContainsString( "panel.id === 'cybermaps-tab-schema'", $script );
		$this->assertStringContainsString( "panel.id === 'cybermaps-tab-ai'", $script );
		$this->assertStringContainsString( 'hidden.value = JSON.stringify( payload )', $script );
		$this->assertStringContainsString( "path[ path.length - 1 ] === ''", $script );
		$this->assertStringContainsString( 'cursor[ key ].push( value )', $script );

		$identity = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/IdentityHub.php'
		);
		$this->assertStringNotContainsString(
			'name="cybermaps_identity_data[social_profiles][]"',
			$identity
		);
	}

	public function test_settings_form_ends_with_a_server_side_completeness_marker(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php'
		);

		$marker = strpos( $source, 'name="cybermaps_form_complete"' );
		$close  = strpos( $source, '</form>', false !== $marker ? $marker : 0 );

		$this->assertNotFalse( $marker );
		$this->assertNotFalse( $close );
		$this->assertLessThan( 500, $close - $marker );
	}

	public function test_directional_styles_cover_admin_rtl_and_frontend_sitemaps(): void {
		$root       = dirname( __DIR__, 2 );
		$admin_css  = (string) file_get_contents( $root . '/assets/css/admin-command-center.css' );
		$rtl_css    = (string) file_get_contents( $root . '/assets/css/admin-command-center-rtl.css' );
		$public_css = (string) file_get_contents( $root . '/assets/css/sitemaps/cybermap-shortcode.css' );

		$this->assertStringNotContainsString(
			'.cybermap-shortcode',
			$admin_css,
			'Frontend shortcode rules must not be duplicated in the admin-only bundle.'
		);
		$this->assertStringContainsString( '.cm-activity-feed::before', $rtl_css );
		$this->assertStringContainsString( '.cm-analytics-danger-zone', $rtl_css );
		$this->assertStringNotContainsString( '#cm-floating-tooltip', $admin_css );
		$this->assertStringNotContainsString( '#wp-admin-bar-cybermaps-discovery-health', $admin_css );
		$this->assertStringNotContainsString( '.cm-status-notice-dismiss', $admin_css . $rtl_css );
		$this->assertStringContainsString( 'padding-inline-start: 20px;', $public_css );
		$this->assertStringNotContainsString( 'padding-left: 20px;', $public_css );
	}

	public function test_admin_styles_do_not_ship_retired_interface_components(): void {
		$styles = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/assets/css/admin-command-center.css'
		);

		foreach (
			array(
				'.cybermaps-status-indicator',
				'.cybermaps-badge-extracted',
				'.cm-cap-icon-box',
				'.cm-mode-pill',
				'.cm-group-separator',
				'.cm-recognition-badge--authenticated',
			) as $retired_selector
		) {
			$this->assertStringNotContainsString( $retired_selector, $styles );
		}
	}
}
