<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\Fields\FieldRenderer;
use Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields;
use Cybermaps\Admin\Settings\Tabs\Sitemaps\SitemapsSections;
use Cybermaps\Admin\ShortcodeBuilder;
use PHPUnit\Framework\TestCase;

final class SettingsControlsAccessibilityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'ai_feed_limit'             => 25,
			'ai_sitemap_limit'          => 100,
			'ai_usage_commercial'       => 'forbid',
			'ai_usage_rag'              => 'allow',
			'ai_usage_training'         => 'forbid',
			'llms_filter_taxonomies'    => 'category, post_tag',
			'llms_pinned_ids'           => '12, 45',
			'llms_tldr_token_budget'    => 80000,
		);
		$GLOBALS['cybermaps_mock_post_types']        = array();
		$GLOBALS['cybermaps_mock_taxonomies']        = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects']  = array();
		$GLOBALS['cybermaps_mock_added_inline_scripts'] = array();
	}

	public function test_discovery_composite_fields_associate_visible_labels_with_controls(): void {
		ob_start();
		DiscoveryFields::render_ai_sitemap_controls();
		DiscoveryFields::render_ai_feed_controls();
		DiscoveryFields::render_ai_identity_controls();
		$html = (string) ob_get_clean();

		foreach (
			array(
				'ai_sitemap_limit',
				'ai_feed_limit',
				'llms_pinned_ids',
				'llms_tldr_token_budget',
			) as $control_id
		) {
			self::assertStringContainsString( 'for="' . $control_id . '"', $html );
			self::assertStringContainsString( 'id="' . $control_id . '"', $html );
		}
	}

	public function test_usage_policy_selects_have_ids_consumed_by_settings_api_labels(): void {
		foreach ( array( 'ai_usage_rag', 'ai_usage_training', 'ai_usage_commercial' ) as $control_id ) {
			ob_start();
			FieldRenderer::render_usage_select(
				array(
					'label_for' => $control_id,
					'options'   => array(
						'allow'  => 'Allowed',
						'forbid' => 'Not permitted',
					),
				)
			);
			$html = (string) ob_get_clean();

			self::assertStringContainsString(
				'<select id="' . $control_id . '" name="cybermaps_settings[' . $control_id . ']"',
				$html
			);
		}

		$registration = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Discovery.php'
		);
		foreach ( array( 'ai_usage_rag', 'ai_usage_training', 'ai_usage_commercial' ) as $control_id ) {
			self::assertMatchesRegularExpression(
				"/'label_for'\\s*=>\\s*'" . preg_quote( $control_id, '/' ) . "'/",
				$registration
			);
		}
	}

	public function test_shared_renderers_publish_real_defaults_instead_of_placeholder_only_values(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();

		ob_start();
		FieldRenderer::render_text_field(
			array(
				'label_for'   => 'audit_post_min_words',
				'default'     => '300',
				'placeholder' => '300',
			)
		);
		$text_field = (string) ob_get_clean();
		self::assertStringContainsString( 'value="300"', $text_field );

		ob_start();
		FieldRenderer::render_text_field(
			array(
				'label_for' => 'audit_post_min_words',
				'default'   => '300',
				'type'      => 'number',
				'min'       => 1,
				'max'       => 10000,
				'step'      => 1,
			)
		);
		$number_field = (string) ob_get_clean();
		self::assertStringContainsString( 'type="number"', $number_field );
		self::assertStringContainsString( 'class="small-text cybermaps-settings-input"', $number_field );
		self::assertStringContainsString( ' min="1" max="10000" step="1"', $number_field );

		ob_start();
		FieldRenderer::render_multi_checkbox_field(
			array(
				'label_for' => 'llms_included_types',
				'default'   => array( 'post', 'page' ),
				'options'   => array(
					'post' => 'Posts',
					'page' => 'Pages',
				),
			)
		);
		$multi_checkbox = (string) ob_get_clean();
		self::assertSame( 2, substr_count( $multi_checkbox, 'checked="checked"' ) );

		ob_start();
		FieldRenderer::render_usage_select(
			array(
				'label_for' => 'ai_usage_training',
				'default'   => 'forbid',
				'options'   => array(
					'allow'  => 'Allowed',
					'forbid' => 'Not permitted',
				),
			)
		);
		$usage_select = (string) ob_get_clean();
		self::assertMatchesRegularExpression(
			'/<option value="forbid" selected="selected">/',
			$usage_select
		);
	}

	public function test_api_secret_control_fails_closed_for_a_malformed_stored_value(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['api_secret'] = array( 'not-a-secret' );

		ob_start();
		FieldRenderer::render_api_secret_field();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'id="api_secret"', $html );
		self::assertStringContainsString( 'value=""', $html );
		self::assertStringNotContainsString( 'Array', $html );
	}

	public function test_shared_field_renderers_fail_closed_for_malformed_general_settings(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = 'not-an-array';

		set_error_handler(
			static function ( int $severity, string $message ): bool {
				throw new \ErrorException( $message, 0, $severity );
			}
		);
		ob_start();
		try {
			FieldRenderer::render_textarea_field( array( 'label_for' => 'notes' ) );
			FieldRenderer::render_text_field(
				array(
					'label_for' => 'title',
					'default'   => 'Default title',
				)
			);
			FieldRenderer::render_email_field( array( 'label_for' => 'email' ) );
			FieldRenderer::render_usage_select(
				array(
					'label_for' => 'usage',
					'default'   => 'allow',
					'options'   => array( 'allow' => 'Allowed' ),
				)
			);
			FieldRenderer::render_media_upload_field( array( 'label_for' => 'image' ) );
			FieldRenderer::render_toggle(
				array(
					'label_for' => 'feature',
					'default'   => '1',
				)
			);
			FieldRenderer::render_checkbox_field( array( 'label_for' => 'checkbox' ) );
			FieldRenderer::render_multi_checkbox_field(
				array(
					'label_for' => 'types',
					'default'   => array( 'post' ),
					'options'   => array( 'post' => 'Posts' ),
				)
			);
			FieldRenderer::render_video_schema_toggle();
			FieldRenderer::render_select_field(
				array(
					'label_for' => 'mode',
					'default'   => 'safe',
					'options'   => array( 'safe' => 'Safe' ),
				)
			);
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			restore_error_handler();
		}

		self::assertStringContainsString( 'value="Default title"', $html );
		self::assertStringContainsString( '<input type="email" id="email"', $html );
		self::assertStringContainsString( '<input type="url" id="image"', $html );
		self::assertStringContainsString( '<select id="mode"', $html );
		self::assertStringNotContainsString( 'not-an-array', $html );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'email'  => array( 'bad' ),
			'image'  => new \stdClass(),
			'feature'=> array( '1' ),
			'mode'   => array( 'unsafe' ),
		);

		ob_start();
		FieldRenderer::render_email_field( array( 'label_for' => 'email' ) );
		FieldRenderer::render_media_upload_field( array( 'label_for' => 'image' ) );
		FieldRenderer::render_toggle(
			array(
				'label_for' => 'feature',
				'default'   => '0',
			)
		);
		FieldRenderer::render_select_field(
			array(
				'label_for' => 'mode',
				'default'   => 'safe',
				'options'   => array( 'safe' => 'Safe' ),
			)
		);
		$nested_html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Array', $nested_html );
		self::assertStringNotContainsString( 'unsafe', $nested_html );
		self::assertMatchesRegularExpression(
			'/<option value="safe" selected="selected">/',
			$nested_html
		);
	}

	public function test_sitemap_defaults_match_effective_runtime_routes(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_google_news' => '1',
			'sitemap_url_base'   => 'client-map',
		);

		ob_start();
		SitemapsSections::general_section_callback();
		SitemapsSections::indexing_section_callback();
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/name="cybermaps_settings\\[redirect_wp_sitemap\\]"[^>]*checked="checked"/',
			$html
		);
		self::assertStringContainsString(
			'name="cybermaps_settings[news_sitemap_url_base]" value="client-map-news"',
			$html
		);
		self::assertSame(
			1,
			preg_match(
				'/<input[^>]*name="cybermaps_settings\\[redirect_news_sitemap\\]"[^>]*>/',
				$html,
				$redirect_news_input
			)
		);
		self::assertStringNotContainsString( 'disabled', $redirect_news_input[0] );
	}

	public function test_redundant_redirects_submit_hidden_current_values_and_indexnow_uses_external_form(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'sitemap_url_base'          => 'sitemap',
			'news_sitemap_url_base'     => 'sitemap-news',
			'redirect_default_sitemap'  => '1',
			'redirect_news_sitemap'     => '1',
			'frontend_base_url'         => 'https://frontend.example/app',
		);

		ob_start();
		SitemapsSections::general_section_callback();
		SitemapsSections::indexing_section_callback();
		$html = (string) ob_get_clean();

		self::assertStringContainsString(
			'<input type="hidden" name="cybermaps_settings[redirect_default_sitemap]" value="1">',
			$html
		);
		self::assertStringContainsString(
			'<input type="hidden" name="cybermaps_settings[redirect_news_sitemap]" value="1">',
			$html
		);
		self::assertStringContainsString(
			'<button type="submit" form="cybermaps-verify-indexnow-key-form"',
			$html
		);
		self::assertStringNotContainsString( '<form method="post" action="http://example.org/wp-admin/admin-post.php">', $html );

		$page_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php' );
		self::assertStringContainsString( 'id="cybermaps-verify-indexnow-key-form"', $page_source );
		self::assertStringContainsString( 'name="action" value="cybermaps_verify_indexnow_key"', $page_source );
	}

	public function test_custom_and_sitemap_controls_have_stable_accessible_names(): void {
		$discovery = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Discovery.php'
		);
		$fields = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Discovery/DiscoveryFields.php'
		);
		$sitemaps = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Sitemaps/SitemapsSections.php'
		);
		$advanced = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Advanced.php'
		);

		foreach ( array( 'llms_exclude_ids', 'llms_filter_taxonomies' ) as $control_id ) {
			self::assertMatchesRegularExpression(
				"/'label_for'\\s*=>\\s*'" . preg_quote( $control_id, '/' ) . "'/",
				$discovery
			);
			self::assertStringContainsString( 'id="' . $control_id . '"', $fields );
		}

		foreach ( array( 'media_discovery_intensity', 'news_publication_name', 'websub_hubs' ) as $control_id ) {
			self::assertStringContainsString( 'for="' . $control_id . '"', $sitemaps );
			self::assertStringContainsString( 'id="' . $control_id . '"', $sitemaps );
		}

		self::assertStringContainsString( 'id="cybermaps-matrix-priority-', $sitemaps );
		self::assertStringContainsString( "'%1\$s %2\$s publication weight'", $sitemaps );
		self::assertStringContainsString( 'for="cybermaps-matrix-priority-', $sitemaps );
		self::assertMatchesRegularExpression(
			"/'label_for'\\s*=>\\s*'api_secret'/",
			$advanced
		);

		ob_start();
		DiscoveryFields::render_taxonomy_filter();
		$taxonomy_filter = (string) ob_get_clean();
		self::assertStringContainsString(
			'includes only posts assigned at least one term in any named taxonomy',
			$taxonomy_filter
		);
	}

	public function test_discovery_dynamic_table_rows_keep_accessible_names(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_sitemap_custom_links'] = array(
			array(
				'url'      => 'https://external.example/resource',
				'priority' => '0.5',
			),
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_action_mappings'] = array(
			array(
				'url'  => 'https://example.com/contact',
				'type' => 'ContactAction',
				'desc' => 'Contact the team',
			),
		);

		ob_start();
		DiscoveryFields::render_custom_links_manager();
		DiscoveryFields::render_ai_actions_manager();
		$html = (string) ob_get_clean();

		foreach (
			array(
				'class="widefat fixed striped cybermaps-responsive-editor-table"',
				'aria-label="External resource URL"',
				'aria-label="External resource priority"',
				'aria-label="Action URL"',
				'aria-label="Action type"',
				'aria-label="Action description"',
				'data-label="External Resource URL"',
				'data-label="Priority"',
				'data-label="Action Type"',
				'data-label="Description"',
			) as $accessible_name
		) {
			self::assertStringContainsString( $accessible_name, $html );
		}

		$scripts = implode(
			"\n",
			$GLOBALS['cybermaps_mock_added_inline_scripts']['cybermaps-command-center']['after'] ?? array()
		);
		self::assertStringContainsString(
			'url.setAttribute("aria-label", copy.externalResourceUrl);',
			$scripts
		);
		self::assertStringContainsString(
			'row.cells[3].setAttribute("data-label", copy.action);',
			$scripts
		);
		self::assertStringContainsString(
			'typeSelect.appendChild(new Option(copy.types[value], value));',
			$scripts
		);
		self::assertStringContainsString(
			'desc.setAttribute("maxlength", "256");',
			$scripts
		);
	}

	public function test_license_and_capability_declarations_expose_their_actual_semantics(): void {
		ob_start();
		DiscoveryFields::render_license_select();
		DiscoveryFields::render_capabilities_field();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'role="group" aria-label="Content license assertion"', $html );
		self::assertStringContainsString( 'aria-pressed="true"', $html );
		self::assertStringContainsString( 'aria-controls="license-info-', $html );
		self::assertStringContainsString( 'operator-selected capability labels', $html );
		self::assertStringContainsString( 'do not enable or disable REST search', $html );

		$scripts = implode(
			"\n",
			$GLOBALS['cybermaps_mock_added_inline_scripts']['cybermaps-command-center']['after'] ?? array()
		);
		self::assertStringContainsString( '.attr("aria-pressed", "false")', $scripts );
		self::assertStringContainsString( '.attr("aria-pressed", "true")', $scripts );
	}

	public function test_bounded_publication_inputs_expose_matching_browser_constraints(): void {
		$discovery = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Discovery.php'
		);
		$fields = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Discovery/DiscoveryFields.php'
		);
		$script = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/assets/js/admin-command-center.js'
		);
		$tooltip = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Admin/AccessibleTooltip.php'
		);

		self::assertStringContainsString( "'type'        => 'number'", $discovery );
		self::assertStringContainsString( 'Chunker::MIN_WINDOW_SIZE', $discovery );
		self::assertStringContainsString( 'Chunker::MAX_WINDOW_SIZE', $discovery );
		self::assertMatchesRegularExpression(
			"/'maxlength'\\s*=>\\s*\\\\Cybermaps\\\\Discovery\\\\PublicationConstraints::MISSION_MAX_LENGTH/",
			$discovery
		);
		self::assertStringContainsString( 'IdentityEntityBuilder::MAX_TEXT_LENGTH', $fields );
		self::assertStringContainsString( 'wp_get_toggletip(', $tooltip );
		self::assertStringNotContainsString( "tip.setAttribute( 'tabindex', '0' )", $script );
	}

	public function test_discovery_matrix_renders_same_slug_post_type_and_taxonomy_as_distinct_controls(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_post_type_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'label'  => 'Shared Content',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'label'  => 'Shared Group',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'post_type:shared' => 0.8,
					'taxonomy:shared'  => 0.2,
				),
				'type_intents' => array(
					'post_type:shared' => 'transactional',
					'taxonomy:shared'  => 'informational',
				),
			)
		);

		ob_start();
		SitemapsSections::render_discovery_center();
		$html = (string) ob_get_clean();

		self::assertSame( 2, substr_count( $html, 'class="cm-matrix-row' ) );
		self::assertStringContainsString( 'data-type="post_type:shared" data-base-type="shared"', $html );
		self::assertStringContainsString( 'data-type="taxonomy:shared" data-base-type="shared"', $html );
		self::assertStringContainsString( 'id="cybermaps-matrix-status-post_typeshared"', $html );
		self::assertStringContainsString( 'id="cybermaps-matrix-status-taxonomyshared"', $html );
		self::assertMatchesRegularExpression(
			'/data-type="post_type:shared".*?cm-matrix-slider"[^>]*min="0\.1"[^>]*max="1\.0"[^>]*value="0\.8"/s',
			$html
		);
		self::assertMatchesRegularExpression(
			'/data-type="taxonomy:shared".*?cm-matrix-slider"[^>]*min="0\.1"[^>]*max="1\.0"[^>]*value="0\.2"/s',
			$html
		);
		self::assertMatchesRegularExpression(
			'/data-type="post_type:shared".*?<option value="transactional" selected="selected">Commercial<\/option>/s',
			$html
		);
		self::assertMatchesRegularExpression(
			'/data-type="taxonomy:shared".*?<option value="informational" selected="selected">Informational<\/option>/s',
			$html
		);
		self::assertStringContainsString( 'Post type · shared', $html );
		self::assertStringContainsString( 'Taxonomy · shared', $html );
	}

	public function test_discovery_strategy_uses_concise_accessible_control_help(): void {
		ob_start();
		SitemapsSections::render_discovery_center();
		$html = (string) ob_get_clean();
		$styles = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/assets/css/admin-command-center.css'
		);

		self::assertStringNotContainsString( 'cm-discovery-guide', $html );
		self::assertStringNotContainsString( 'Profiles provide practical starting values', $html );
		self::assertStringContainsString( 'Taxonomies—including Post Formats—represent archive pages', $html );
		self::assertStringContainsString( 'Discovery intent', $html );
		self::assertStringContainsString( 'Publication weight', $html );
		self::assertStringContainsString( 'class="wp-tooltip wp-is-toggletip cybermaps-help-tip"', $html );
		self::assertStringContainsString( 'aria-haspopup="dialog"', $html );
		self::assertStringContainsString( 'popover="auto"', $html );
		self::assertStringContainsString( 'Commercial content supports a purchase', $html );
		self::assertMatchesRegularExpression(
			'/\.cm-matrix-head \.cm-matrix-col-source\s*\{[^}]*flex-direction:\s*row;[^}]*white-space:\s*nowrap;/s',
			$styles
		);
	}

	public function test_language_and_translation_controls_render_together(): void {
		ob_start();
		SitemapsSections::international_section_callback();
		$language_html = (string) ob_get_clean();

		ob_start();
		SitemapsSections::general_section_callback();
		$delivery_html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="cybermaps_settings[site_language]"', $language_html );
		self::assertStringContainsString( 'name="cybermaps_settings[enable_translation_integrations]"', $language_html );
		self::assertStringNotContainsString( 'enable_translation_integrations', $delivery_html );
		self::assertStringContainsString( 'dir="ltr"', $language_html );
	}

	public function test_delivery_and_media_callbacks_embed_their_related_fields(): void {
		ob_start();
		SitemapsSections::general_section_callback();
		$delivery_html = (string) ob_get_clean();

		ob_start();
		SitemapsSections::media_section_callback();
		$media_html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="cybermaps_settings[static_engine_mode]"', $delivery_html );
		self::assertStringContainsString( 'name="cybermaps_settings[enable_video_schema]"', $media_html );
		self::assertStringContainsString( 'Static Publication', $delivery_html );
		self::assertStringContainsString( 'On-Page Video Markup', $media_html );
	}

	public function test_html_sitemap_builder_renders_registered_content_groups(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_shortcode'] = '1';
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
			'labels' => (object) array( 'name' => 'Posts' ),
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects']['post_format'] = (object) array(
			'name'   => 'post_format',
			'public' => true,
			'labels' => (object) array( 'name' => 'Formats' ),
		);
		$GLOBALS['cybermaps_mock_taxonomies'] = array(
			'post_format' => $GLOBALS['cybermaps_mock_taxonomy_objects']['post_format'],
		);

		ob_start();
		ShortcodeBuilder::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'HTML Sitemap Shortcode Builder', $html );
		self::assertStringContainsString( 'data-pt="post_type:post"', $html );
		self::assertStringContainsString( 'data-tax="taxonomy:post_format"', $html );
		self::assertStringContainsString( 'data-label="Post Formats"', $html );
	}
}
