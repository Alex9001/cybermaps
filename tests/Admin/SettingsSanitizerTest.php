<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer;
use Cybermaps\Admin\Settings\Tabs\Discovery\DiscoveryFields;
use Cybermaps\Discovery\PublicationConstraints;
use PHPUnit\Framework\TestCase;

class SettingsSanitizerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $cybermaps_mock_options;
        $cybermaps_mock_options = array();
        $GLOBALS['wp_hooks'] = array();
        $GLOBALS['cybermaps_mock_settings_errors'] = array();
        $_POST = array();
    }

    protected function tearDown(): void {
        $_POST = array();
        parent::tearDown();
    }

    public function test_static_engine_mode_defaults_to_well_known(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();

        $result = SettingsSanitizer::sanitize( array() );

        $this->assertSame( 'well_known', $result['static_engine_mode'] );
        $this->assertArrayNotHasKey( 'enable_static_engine', $result );
    }

	public function test_markdown_negotiation_is_an_explicit_ai_checkbox(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();
		$_POST = array(
			'option_page'             => 'cybermaps_options_group',
			'cybermaps_active_tab'    => 'ai',
			'cybermaps_form_complete' => '1',
		);

		$enabled = SettingsSanitizer::sanitize( array( 'enable_markdown_negotiation' => '1' ) );
		self::assertSame( '1', $enabled['enable_markdown_negotiation'] );

		$disabled = SettingsSanitizer::sanitize( array() );
		self::assertSame( '0', $disabled['enable_markdown_negotiation'] );
	}

    public function test_truncated_main_form_preserves_every_registered_option(): void {
        $settings  = array( 'static_engine_mode' => 'all', 'llms_custom_instructions' => 'Keep me' );
        $discovery = '{"archetype":"blog","overrides":{"post":0.8}}';
        $robots    = array( 'overrides' => array( 'gptbot' => array( 'robots' => false ) ) );
        $identity  = array( 'type' => 'Organization', 'name' => 'Complete identity' );
        $GLOBALS['cybermaps_mock_options'] = array(
            'cybermaps_settings'         => $settings,
            'cybermaps_discovery_center' => $discovery,
            'cybermaps_robots_manager'   => $robots,
            'cybermaps_identity_data'    => $identity,
        );
        $_POST = array(
            'option_page'         => 'cybermaps_options_group',
            'cybermaps_active_tab'=> 'ai',
            // Deliberately no cybermaps_form_complete marker.
        );

        $this->assertSame( $settings, SettingsSanitizer::sanitize( array( 'static_engine_mode' => 'off' ) ) );
        $this->assertSame( $discovery, \Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer::sanitize( '{}' ) );
        $this->assertSame( $robots, RobotsManagerSanitizer::sanitize( array( 'overrides' => array() ) ) );
        $this->assertSame( $identity, ( new \Cybermaps\Admin\IdentityHub() )->sanitize_identity_data( array( 'name' => 'Partial' ) ) );
        $this->assertCount( 1, $GLOBALS['cybermaps_mock_settings_errors'] );
        $this->assertSame( 'cybermaps_input_truncated', $GLOBALS['cybermaps_mock_settings_errors'][0]['code'] );
    }

    public function test_complete_main_form_marker_allows_intentional_changes(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
            'static_engine_mode' => 'all',
        );
        $_POST = array(
            'option_page'             => 'cybermaps_options_group',
            'cybermaps_active_tab'    => 'advanced',
            'cybermaps_form_complete' => '1',
        );

        $result = SettingsSanitizer::sanitize( array( 'static_engine_mode' => 'off' ) );

        $this->assertSame( 'off', $result['static_engine_mode'] );
        $this->assertSame( array(), $GLOBALS['cybermaps_mock_settings_errors'] );
    }

    public function test_static_engine_mode_is_canonical_and_removes_legacy_boolean(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'enable_static_engine' => '1',
            'static_engine_mode'    => 'all',
        );

        $result = SettingsSanitizer::sanitize(
            array(
                'static_engine_mode' => 'off',
            )
        );

        $this->assertSame( 'off', $result['static_engine_mode'] );
        $this->assertArrayNotHasKey( 'enable_static_engine', $result );
    }

    public function test_legacy_rss_type_key_is_migrated_without_surviving_the_save(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'rss_sitemap_post_types' => 'post, Product, post',
        );

        $result = SettingsSanitizer::sanitize( array() );

        $this->assertSame( array( 'post', 'product' ), $result['rss_sitemap_types'] );
        $this->assertArrayNotHasKey( 'rss_sitemap_post_types', $result );
    }

    public function test_llms_tldr_budget_is_sanitized_without_legacy_scoring_controls(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();

        $result = SettingsSanitizer::sanitize(
            array(
                'llms_tldr_token_budget' => '500',
                'llms_tldr_pool_size'    => '9999',
                'llms_tldr_threshold'    => '0.9',
            )
        );

        $this->assertSame( 1000, $result['llms_tldr_token_budget'] );
        $this->assertArrayNotHasKey( 'llms_tldr_pool_size', $result );
        $this->assertArrayNotHasKey( 'llms_tldr_threshold', $result );
    }

    public function test_briefing_priority_ids_are_unique_and_bounded(): void {
        $ids = array_merge( range( 1, PublicationConstraints::BRIEFING_PINNED_IDS_MAX + 20 ), array( 1, 2 ) );

        $result = SettingsSanitizer::sanitize(
            array( 'llms_pinned_ids' => implode( ',', $ids ) )
        );

        $stored = array_map( 'intval', explode( ',', $result['llms_pinned_ids'] ) );
        $this->assertCount( PublicationConstraints::BRIEFING_PINNED_IDS_MAX, $stored );
        $this->assertSame( range( 1, PublicationConstraints::BRIEFING_PINNED_IDS_MAX ), $stored );
    }

    public function test_rag_chunk_configuration_is_bounded_as_one_pair(): void {
        $result = SettingsSanitizer::sanitize(
            array(
                'rag_chunk_size'    => 1,
                'rag_chunk_overlap' => 9999,
            )
        );

        $this->assertSame( 100, $result['rag_chunk_size'] );
        $this->assertSame( 50, $result['rag_chunk_overlap'] );
    }

    public function test_blank_rendered_numeric_fields_reset_to_documented_runtime_defaults(): void {
        $result = SettingsSanitizer::sanitize(
            array(
                'rag_chunk_size'            => '',
                'rag_chunk_overlap'         => '',
                'audit_post_min_words'      => '',
                'audit_post_max_age_days'   => '',
                'audit_page_min_words'      => '',
                'audit_page_max_age_days'   => '',
                'ai_sitemap_limit'           => '',
                'llms_link_limit'             => '',
                'ai_feed_limit'              => '',
                'llms_tldr_token_budget'     => '',
                'rss_sitemap_limit'          => '',
                'log_retention_days'         => '',
            )
        );

        $this->assertSame( 800, $result['rag_chunk_size'] );
        $this->assertSame( 100, $result['rag_chunk_overlap'] );
        $this->assertSame( 300, $result['audit_post_min_words'] );
        $this->assertSame( 365, $result['audit_post_max_age_days'] );
        $this->assertSame( 150, $result['audit_page_min_words'] );
        $this->assertSame( 0, $result['audit_page_max_age_days'] );
        $this->assertSame( 100, $result['ai_sitemap_limit'] );
        $this->assertSame( PublicationConstraints::LLMS_LINK_LIMIT_DEFAULT, $result['llms_link_limit'] );
        $this->assertSame( 10, $result['ai_feed_limit'] );
        $this->assertSame( 80000, $result['llms_tldr_token_budget'] );
        $this->assertSame( 100, $result['rss_sitemap_limit'] );
        $this->assertSame( 30, $result['log_retention_days'] );
    }

    public function test_route_sanitization_is_side_effect_free(): void {
        SettingsSanitizer::sanitize( array( 'sitemap_url_base' => 'site-map' ) );

        $this->assertNotContains( 'shutdown', array_column( $GLOBALS['wp_hooks'], 'hook' ) );
    }

    public function test_equivalent_optional_xml_suffix_does_not_flush_rewrites(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
            'sitemap_url_base' => 'sitemap',
        );

        SettingsSanitizer::sanitize( array( 'sitemap_url_base' => 'sitemap.xml' ) );

        $this->assertNotContains( 'shutdown', array_column( $GLOBALS['wp_hooks'], 'hook' ) );
    }

    public function test_blank_primary_language_uses_the_wordpress_site_language(): void {
        $result = SettingsSanitizer::sanitize( array( 'site_language' => '' ) );

        $this->assertSame( 'en-US', $result['site_language'] );
    }

    public function test_primary_language_is_normalized_and_invalid_input_falls_back(): void {
        $this->assertSame(
            'pt-BR',
            SettingsSanitizer::sanitize( array( 'site_language' => 'pt_br' ) )['site_language']
        );
        $this->assertSame(
            'en-US',
            SettingsSanitizer::sanitize( array( 'site_language' => 'not a language tag' ) )['site_language']
        );
    }

    public function test_blank_news_base_follows_the_effective_primary_sitemap_base(): void {
        $result = SettingsSanitizer::sanitize(
            array(
                'sitemap_url_base'      => 'client-map.xml',
                'news_sitemap_url_base' => '',
            )
        );

        $this->assertSame( 'client-map-news', $result['news_sitemap_url_base'] );
    }

    public function test_publication_route_collisions_are_resolved_as_one_setting_group(): void {
        $result = SettingsSanitizer::sanitize(
            array(
                'sitemap_url_base'      => 'machine-map.xml',
                'news_sitemap_url_base' => 'machine-map-posts-post-1.xml',
                'rss_sitemap_url_base'  => 'machine-map-misc.xml',
            )
        );

        $this->assertSame( 'machine-map', $result['sitemap_url_base'] );
        $this->assertSame( 'machine-map-news', $result['news_sitemap_url_base'] );
        $this->assertSame( 'sitemap-rss', $result['rss_sitemap_url_base'] );
    }

    public function test_unrelated_save_canonicalizes_unsafe_stored_publication_routes(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'sitemap_url_base'      => 'wp-sitemap',
            'news_sitemap_url_base' => 'ai-sitemap',
            'rss_sitemap_url_base'  => 'sitemap-network',
        );
        $_POST['cybermaps_active_tab'] = 'analytics';

        $result = SettingsSanitizer::sanitize(
            array( 'log_retention_days' => '30' )
        );

        $this->assertSame( 'sitemap', $result['sitemap_url_base'] );
        $this->assertSame( 'sitemap-news', $result['news_sitemap_url_base'] );
        $this->assertSame( 'sitemap-rss', $result['rss_sitemap_url_base'] );
    }

    public function test_manifest_visibility_accepts_only_implemented_endpoint_keys(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();

        $result = SettingsSanitizer::sanitize(
            array(
                'ai_manifest_endpoints' => array(
                    'llms.txt',
                    'llms-full.txt',
                    'skill.md',
                    'not-a-publication',
                    'llms.txt',
                ),
            )
        );

        $this->assertSame(
            array( 'llms.txt', 'llms-full.txt', 'skill.md' ),
            $result['ai_manifest_endpoints']
        );
    }

    public function test_content_license_accepts_only_values_offered_by_the_admin_control(): void {
        $valid = SettingsSanitizer::sanitize(
            array( 'llms_content_license' => 'CC-BY-4.0' )
        );
        $invalid = SettingsSanitizer::sanitize(
            array( 'llms_content_license' => 'invented-license' )
        );

        $this->assertSame( 'CC-BY-4.0', $valid['llms_content_license'] );
        $this->assertSame( '', $invalid['llms_content_license'] );
    }

    public function test_custom_ai_instructions_are_sanitized_and_preserved_across_other_tab_saves(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();
        $_POST['cybermaps_active_tab'] = 'ai';

        $saved = SettingsSanitizer::sanitize(
            array(
                'llms_custom_instructions' => "  Prefer primary sources.\nCite canonical URLs.  ",
            )
        );
        $this->assertSame(
            "Prefer primary sources.\nCite canonical URLs.",
            $saved['llms_custom_instructions']
        );

        $cybermaps_mock_options['cybermaps_settings'] = $saved;
        $_POST['cybermaps_active_tab'] = 'sitemaps';
        $preserved = SettingsSanitizer::sanitize( array( 'enable_caching' => '1' ) );

        $this->assertSame(
            "Prefer primary sources.\nCite canonical URLs.",
            $preserved['llms_custom_instructions']
        );
    }

    public function test_enable_discovery_hub_toggle(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();

        $on = SettingsSanitizer::sanitize( array( 'enable_discovery_hub' => '1' ) );
        $this->assertSame( '1', $on['enable_discovery_hub'] );

        $_POST['cybermaps_active_tab'] = 'ai';
        $off = SettingsSanitizer::sanitize( array() );
        $this->assertSame( '0', $off['enable_discovery_hub'] );
    }

    public function test_discovery_normalizer_preserves_ai_values_when_saving_another_tab(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'enable_llms_full'       => '1',
            'mcp_mode'               => 'read_only',
            'llms_link_limit'        => 42,
            'llms_custom_instructions' => 'Keep this text',
        );
        $_POST['cybermaps_active_tab'] = 'sitemaps';

        $result = SettingsSanitizer::sanitize( array( 'enable_caching' => '1' ) );

        $this->assertSame( '1', $result['enable_llms_full'] );
        $this->assertSame( 'read_only', $result['mcp_mode'] );
        $this->assertSame( 42, $result['llms_link_limit'] );
        $this->assertSame( 'Keep this text', $result['llms_custom_instructions'] );
    }

    public function test_discovery_normalizer_clears_absent_active_ai_checkboxes_but_preserves_selects(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'enable_llms_full' => '1',
            'enable_rag_chunks' => '1',
            'mcp_mode' => 'operations',
        );
        $_POST['cybermaps_active_tab'] = 'ai';

        $result = SettingsSanitizer::sanitize( array( 'mcp_mode' => 'invalid-mode' ) );

        $this->assertSame( '0', $result['enable_llms_full'] );
        $this->assertSame( '0', $result['enable_rag_chunks'] );
        $this->assertSame( 'off', $result['mcp_mode'] );
    }

    public function test_discovery_normalizer_treats_rag_size_and_overlap_as_one_bounded_pair(): void {
        $_POST['cybermaps_active_tab'] = 'ai';

        $result = SettingsSanitizer::sanitize(
            array(
                'rag_chunk_size' => 1600,
                'rag_chunk_overlap' => 1600,
            )
        );

        $this->assertSame( 1600, $result['rag_chunk_size'] );
        $this->assertSame( 800, $result['rag_chunk_overlap'] );
    }

    public function test_discovery_normalizer_migrates_legacy_exclusion_ids_without_duplicate_storage(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'llms_exclude_ids' => '12, 45',
            'ai_sitemap_exclude_ids' => '45, 102',
        );
        $_POST['cybermaps_active_tab'] = 'ai';

        $result = SettingsSanitizer::sanitize(
            array(
                'llms_exclude_ids' => '12, 45, 102',
                'ai_sitemap_exclude_ids' => '45, 102',
            )
        );

        $this->assertSame( '12, 45, 102', $result['llms_exclude_ids'] );
        $this->assertArrayNotHasKey( 'ai_sitemap_exclude_ids', $result );
    }

	public function test_html_sitemap_tab_owns_the_shortcode_checkbox(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_shortcode' => '1',
		);

		$_POST['cybermaps_active_tab'] = 'sitemaps';
		$preserved = SettingsSanitizer::sanitize( array( 'enable_caching' => '1' ) );
		$this->assertSame( '1', $preserved['enable_shortcode'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $preserved;
		$_POST['cybermaps_active_tab'] = 'shortcode';
		$disabled = SettingsSanitizer::sanitize( array() );
		$this->assertSame( '0', $disabled['enable_shortcode'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $disabled;
		$enabled = SettingsSanitizer::sanitize( array( 'enable_shortcode' => '1' ) );
		$this->assertSame( '1', $enabled['enable_shortcode'] );
	}

	public function test_disabled_redundant_redirect_values_survive_a_custom_route_save(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'sitemap_url_base'         => 'sitemap',
			'news_sitemap_url_base'    => 'sitemap-news',
			'redirect_default_sitemap' => '1',
			'redirect_news_sitemap'    => '1',
		);
		$_POST['cybermaps_active_tab'] = 'sitemaps';

		$saved = SettingsSanitizer::sanitize(
			array(
				'sitemap_url_base'         => 'client-map',
				'news_sitemap_url_base'    => 'client-map-news',
				'redirect_default_sitemap' => '1',
				'redirect_news_sitemap'    => '1',
			)
		);

		$this->assertSame( '1', $saved['redirect_default_sitemap'] );
		$this->assertSame( '1', $saved['redirect_news_sitemap'] );
	}

    public function test_external_requests_and_crawler_analytics_are_opt_in(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();
        $_POST['cybermaps_active_tab'] = 'sitemaps';

        $defaults = SettingsSanitizer::sanitize( array() );
        $this->assertSame( '0', $defaults['enable_indexnow'] );
        $this->assertSame( '0', $defaults['enable_websub'] );
        $this->assertSame( '0', $defaults['enable_analytics'] );

        $enabled = SettingsSanitizer::sanitize(
            array(
                'enable_indexnow' => '1',
                'enable_websub'   => '1',
                'enable_analytics' => '1',
            )
        );
        $this->assertSame( '1', $enabled['enable_indexnow'] );
        $this->assertSame( '1', $enabled['enable_websub'] );
        $this->assertSame( '1', $enabled['enable_analytics'] );
    }

    public function test_analytics_form_owns_recording_and_retention_without_erasing_other_settings(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'enable_analytics'        => '1',
            'anonymize_analytics_ips' => '1',
            'log_retention_days'      => 30,
            'enable_caching'          => '1',
            'api_secret'              => 'preserve-me',
        );
        $_POST['cybermaps_active_tab'] = 'analytics';

        $disabled = SettingsSanitizer::sanitize(
            array(
                'log_retention_days' => '999',
            )
        );

        $this->assertSame( '0', $disabled['enable_analytics'] );
        $this->assertSame( '0', $disabled['anonymize_analytics_ips'] );
        $this->assertSame( 365, $disabled['log_retention_days'] );
        $this->assertSame( '1', $disabled['enable_caching'] );
        $this->assertSame( 'preserve-me', $disabled['api_secret'] );
    }

    public function test_analytics_ip_anonymization_defaults_on_and_accepts_explicit_choices(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();
        $_POST['cybermaps_active_tab'] = 'sitemaps';

        $defaulted = SettingsSanitizer::sanitize( array( 'enable_caching' => '1' ) );
        $this->assertSame( '1', $defaulted['anonymize_analytics_ips'] );

        $_POST['cybermaps_active_tab'] = 'analytics';
        $enabled = SettingsSanitizer::sanitize( array( 'anonymize_analytics_ips' => '1' ) );
        $this->assertSame( '1', $enabled['anonymize_analytics_ips'] );

        $disabled = SettingsSanitizer::sanitize( array( 'anonymize_analytics_ips' => '0' ) );
        $this->assertSame( '0', $disabled['anonymize_analytics_ips'] );
    }

    public function test_other_tabs_preserve_disabled_analytics_ip_anonymization(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'anonymize_analytics_ips' => '0',
        );
        $_POST['cybermaps_active_tab'] = 'sitemaps';

        $saved = SettingsSanitizer::sanitize( array( 'enable_caching' => '1' ) );

        $this->assertSame( '0', $saved['anonymize_analytics_ips'] );
    }

    public function test_absent_inactive_tab_payload_preserves_saved_robots_policy(): void {
        $stored = array(
            'takeover_enabled'  => true,
            'overrides'         => array(
                'gptbot' => array(
                    'robots' => false,
                    'llm'    => true,
                    'tpm'    => 20,
                ),
            ),
            'manual_directives' => "User-agent: Example\nDisallow: /private/",
            'content_signals'   => array( 'ai-train' => 'no' ),
        );
        $GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] = $stored;

        $this->assertSame( $stored, RobotsManagerSanitizer::sanitize( null ) );
    }

    public function test_sitemaps_save_preserves_analytics_controls(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'enable_analytics'        => '1',
            'anonymize_analytics_ips' => '1',
            'log_retention_days'      => 45,
        );
        $_POST['cybermaps_active_tab'] = 'sitemaps';

        $saved = SettingsSanitizer::sanitize( array( 'enable_caching' => '1' ) );

        $this->assertSame( '1', $saved['enable_analytics'] );
        $this->assertSame( '1', $saved['anonymize_analytics_ips'] );
        $this->assertSame( 45, $saved['log_retention_days'] );
    }

    public function test_saving_one_tab_preserves_other_tab_settings_and_secrets(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'api_secret'              => 'keep-this-secret',
            'agency_name'             => 'Example Agency',
            'audit_post_min_words'     => 450,
            'delete_data_on_uninstall'=> '1',
            'enable_discovery_hub'    => '1',
            'include_authors'         => '1',
            'rss_sitemap_url_base'    => 'existing-rss',
        );
        $_POST['cybermaps_active_tab'] = 'sitemaps';

        $result = SettingsSanitizer::sanitize(
            array(
                'sitemap_url_base' => 'site-map.xml',
            )
        );

        $this->assertSame( '0', $result['include_authors'], 'An absent checkbox on the active tab must be cleared.' );
        $this->assertSame( '1', $result['enable_discovery_hub'] );
        $this->assertSame( 'keep-this-secret', $result['api_secret'] );
        $this->assertSame( 'Example Agency', $result['agency_name'] );
        $this->assertSame( 450, $result['audit_post_min_words'] );
        $this->assertSame( '1', $result['delete_data_on_uninstall'] );
    }

    public function test_advanced_normalizer_preserves_inactive_values_and_owns_active_checkbox_clearing(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'agency_name' => 'Keep Agency',
            'agency_url' => 'https://agency.example/',
            'api_secret' => 'keep-secret',
            'cdn_enabled' => '1',
            'audit_post_require_media' => '1',
        );

        $_POST['cybermaps_active_tab'] = 'sitemaps';
        $preserved = SettingsSanitizer::sanitize( array( 'enable_caching' => '1' ) );
        $this->assertSame( 'Keep Agency', $preserved['agency_name'] );
        $this->assertSame( 'https://agency.example/', $preserved['agency_url'] );
        $this->assertSame( 'keep-secret', $preserved['api_secret'] );
        $this->assertSame( '1', $preserved['cdn_enabled'] );

        $_POST['cybermaps_active_tab'] = 'advanced';
        $cleared = SettingsSanitizer::sanitize( array() );
        $this->assertSame( '0', $cleared['cdn_enabled'] );
        $this->assertSame( 'Keep Agency', $cleared['agency_name'] );
        $this->assertSame( 'keep-secret', $cleared['api_secret'] );
    }

    public function test_blank_api_secret_does_not_erase_existing_secret(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'api_secret' => 'existing-secret',
        );
        $_POST['cybermaps_active_tab'] = 'advanced';

        $result = SettingsSanitizer::sanitize(
            array(
                'api_secret' => '',
            )
        );

        $this->assertSame( 'existing-secret', $result['api_secret'] );
    }

    public function test_malformed_api_secret_values_fail_closed_without_array_conversion(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'api_secret' => array( 'malformed' ),
        );

        $result = SettingsSanitizer::sanitize(
            array(
                'api_secret' => array( 'also-malformed' ),
            )
        );

        $this->assertIsString( $result['api_secret'] );
        $this->assertSame( 32, strlen( $result['api_secret'] ) );
        $this->assertNotSame( 'Array', $result['api_secret'] );
    }

    public function test_content_review_and_rss_settings_are_sanitized(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array();
        $_POST['cybermaps_active_tab'] = 'sitemaps';

        $result = SettingsSanitizer::sanitize(
            array(
                'enable_rss_sitemap'    => '1',
                'rss_sitemap_url_base'  => 'My RSS.xml',
                'rss_sitemap_limit'     => '5000',
                'rss_sitemap_types'     => array( 'post', 'Page', 'bad type', 'post' ),
                'audit_post_min_words'  => '999999',
                'delete_data_on_uninstall' => '1',
            )
        );

        $this->assertSame( '1', $result['enable_rss_sitemap'] );
        $this->assertSame( 'my-rss', $result['rss_sitemap_url_base'] );
        $this->assertSame( 1000, $result['rss_sitemap_limit'] );
        $this->assertSame( array( 'post', 'page', 'badtype' ), $result['rss_sitemap_types'] );
        $this->assertSame( 10000, $result['audit_post_min_words'] );
        $this->assertSame( '1', $result['delete_data_on_uninstall'] );
    }

    public function test_ai_publication_limits_and_collection_sizes_are_bounded(): void {
        $_POST['cybermaps_active_tab'] = 'ai';
        $links                         = array();
        $actions                       = array();
        for ( $index = 1; $index <= 105; ++$index ) {
            $links[] = array(
                'url'      => 'https://example.com/resource-' . $index,
                'priority' => 2,
            );
            $actions[] = array(
                'url'  => 'https://example.com/contact-' . $index,
                'type' => 'contactaction',
                'desc' => 'Contact ' . $index,
            );
        }

        $result = SettingsSanitizer::sanitize(
            array(
                'ai_sitemap_limit'        => PHP_INT_MAX,
                'llms_link_limit'          => PHP_INT_MAX,
                'ai_feed_limit'           => PHP_INT_MAX,
                'ai_sitemap_custom_links' => $links,
                'ai_action_mappings'      => $actions,
            )
        );

        $this->assertSame( PublicationConstraints::AI_SITEMAP_LIMIT_MAX, $result['ai_sitemap_limit'] );
        $this->assertSame( PublicationConstraints::LLMS_LINK_LIMIT_MAX, $result['llms_link_limit'] );
        $this->assertSame( PublicationConstraints::FEED_LIMIT_MAX, $result['ai_feed_limit'] );
        $this->assertCount( PublicationConstraints::CUSTOM_LINKS_MAX, $result['ai_sitemap_custom_links'] );
        $this->assertCount( PublicationConstraints::ACTION_MAPPINGS_MAX, $result['ai_action_mappings'] );
        $this->assertSame( 'ContactAction', $result['ai_action_mappings'][0]['type'] );
        $this->assertSame( 1.0, $result['ai_sitemap_custom_links'][0]['priority'] );
    }

    public function test_invalid_action_types_and_attachment_content_types_are_removed(): void {
        $_POST['cybermaps_active_tab'] = 'ai';

        $result = SettingsSanitizer::sanitize(
            array(
                'ai_sitemap_types'   => array( 'post', 'attachment', 'portfolio' ),
                'llms_included_types'=> array( 'attachment', 'page' ),
                'ai_action_mappings' => array(
                    array(
                        'url'  => 'https://example.com/delete',
                        'type' => 'DeleteEverythingAction',
                        'desc' => 'Invalid',
                    ),
                ),
            )
        );

        $this->assertSame( array( 'post', 'portfolio' ), $result['ai_sitemap_types'] );
        $this->assertSame( array( 'page' ), $result['llms_included_types'] );
        $this->assertSame( array(), $result['ai_action_mappings'] );
    }

    public function test_public_url_settings_reject_unsafe_protocols_credentials_and_ambiguous_bases(): void {
        $_POST['cybermaps_active_tab'] = 'advanced';

        $result = SettingsSanitizer::sanitize(
            array(
                'frontend_base_url' => 'https://frontend.example/app?preview=1',
                'cdn_base_url'      => 'https://user:secret@cdn.example/media',
                'agency_url'        => 'javascript:alert(1)',
                'agency_logo'       => 'https://assets.example/logo.png',
                'ai_sitemap_custom_links' => array(
                    array( 'url' => 'ftp://files.example/catalog', 'priority' => 0.5 ),
                ),
                'ai_action_mappings' => array(
                    array(
                        'url'  => 'https://user:secret@example.com/contact',
                        'type' => 'ContactAction',
                    ),
                ),
            )
        );

        $this->assertSame( '', $result['frontend_base_url'] );
        $this->assertSame( '', $result['cdn_base_url'] );
        $this->assertSame( '', $result['agency_url'] );
        $this->assertSame( 'https://assets.example/logo.png', $result['agency_logo'] );
        $this->assertSame( array(), $result['ai_sitemap_custom_links'] );
        $this->assertSame( array(), $result['ai_action_mappings'] );
    }

    public function test_ai_excluded_post_ids_have_one_canonical_storage_key(): void {
        global $cybermaps_mock_options;
        $cybermaps_mock_options['cybermaps_settings'] = array(
            'llms_exclude_ids'       => '12, 45',
            'ai_sitemap_exclude_ids' => '45, 102',
        );

        $_POST['cybermaps_active_tab'] = 'ai';
        $saved = SettingsSanitizer::sanitize(
            array(
                'llms_exclude_ids'       => '12, 45, 102, invalid',
                'ai_sitemap_exclude_terms'=> 'Members Only, 77, members-only',
            )
        );

        $this->assertSame( '12, 45, 102', $saved['llms_exclude_ids'] );
        $this->assertArrayNotHasKey( 'ai_sitemap_exclude_ids', $saved );
        $this->assertSame( 'members-only, 77', $saved['ai_sitemap_exclude_terms'] );

        $cybermaps_mock_options['cybermaps_settings'] = $saved;
        ob_start();
        DiscoveryFields::render_global_ai_excluded_ids();
        $field = (string) ob_get_clean();
        $this->assertStringContainsString( 'value="12, 45, 102"', $field );
    }

    public function test_configuration_import_canonicalizes_the_retired_ai_exclusion_key(): void {
        $result = SettingsSanitizer::sanitize_import(
            array(
                'llms_exclude_ids'       => '12',
                'ai_sitemap_exclude_ids' => '45',
            ),
            array()
        );

        $this->assertSame( '12, 45', $result['llms_exclude_ids'] );
        $this->assertArrayNotHasKey( 'ai_sitemap_exclude_ids', $result );
    }

    public function test_all_exclusion_lists_are_bounded_by_the_canonical_limits(): void {
        $ids   = range( 1, PublicationConstraints::EXCLUSION_ITEMS_MAX + 50 );
        $terms = array_map( static fn ( int $id ): string => 'term-' . $id, $ids );

        $result = SettingsSanitizer::sanitize_import(
            array(
                'exclude_post_ids'         => $ids,
                'exclude_categories'       => $terms,
                'llms_exclude_ids'         => $ids,
                'ai_sitemap_exclude_ids'   => $ids,
                'ai_sitemap_exclude_terms' => $terms,
            ),
            array()
        );

        foreach (
            array(
                'exclude_post_ids',
                'exclude_categories',
                'llms_exclude_ids',
                'ai_sitemap_exclude_terms',
            ) as $field
        ) {
            $items = array_values( array_filter( array_map( 'trim', explode( ',', $result[ $field ] ) ) ) );
            $this->assertLessThanOrEqual( PublicationConstraints::EXCLUSION_ITEMS_MAX, count( $items ), $field );
            $this->assertLessThanOrEqual( PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES, strlen( $result[ $field ] ), $field );
        }
    }

    public function test_publication_names_taxonomy_filters_and_type_lists_are_bounded(): void {
        $content_types = array_map(
            static fn ( int $index ): string => 'type_' . $index,
            range( 0, PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX + 20 )
        );
        $taxonomy_filters = array(
            ' Category ',
            'CATEGORY',
            'POST_TAG',
            str_repeat( 'x', PublicationConstraints::TAXONOMY_NAME_MAX_LENGTH + 1 ),
        );
        for ( $index = 0; $index < PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX + 20; ++$index ) {
            $taxonomy_filters[] = 'Taxonomy_' . $index;
        }

        $result = SettingsSanitizer::sanitize_import(
            array(
                'news_publication_name'  => str_repeat( 'N', PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH + 20 ),
                'rss_sitemap_types'      => $content_types,
                'llms_included_types'    => $content_types,
                'ai_sitemap_types'       => $content_types,
                'llms_filter_taxonomies' => implode( ',', $taxonomy_filters ),
            ),
            array()
        );

        $this->assertSame( PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH, strlen( $result['news_publication_name'] ) );
        foreach ( array( 'rss_sitemap_types', 'llms_included_types', 'ai_sitemap_types' ) as $field ) {
            $this->assertCount( PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX, $result[ $field ], $field );
        }

        $taxonomies = explode( ', ', $result['llms_filter_taxonomies'] );
        $this->assertCount( PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX, $taxonomies );
        $this->assertSame( array( 'category', 'post_tag', 'taxonomy_0' ), array_slice( $taxonomies, 0, 3 ) );
        $this->assertNotContains( str_repeat( 'x', PublicationConstraints::TAXONOMY_NAME_MAX_LENGTH + 1 ), $taxonomies );
        $this->assertLessThanOrEqual(
            PublicationConstraints::TAXONOMY_FILTER_MAX_LENGTH,
            strlen( $result['llms_filter_taxonomies'] )
        );
        $this->assertMatchesRegularExpression( '/^[a-z0-9_-]+(?:, [a-z0-9_-]+)*$/', $result['llms_filter_taxonomies'] );
    }

    public function test_report_theme_is_limited_to_the_supported_presentations(): void {
        $sanitizer = new SettingsSanitizer();

        $midnight = $sanitizer::sanitize( array( 'report_theme' => 'midnight' ) );
        $invalid  = $sanitizer::sanitize( array( 'report_theme' => 'remote-css' ) );

        $this->assertSame( 'midnight', $midnight['report_theme'] );
        $this->assertSame( 'swiss', $invalid['report_theme'] );
    }

    public function test_publication_guidance_and_topics_are_bounded_on_save(): void {
        $topics = array( '', 'WordPress', 'wordpress' );
        for ( $index = 0; $index < PublicationConstraints::TOPICS_MAX + 3; ++$index ) {
            $topics[] = 'Topic ' . $index;
        }

        $result = SettingsSanitizer::sanitize(
            array(
                'llms_mission_statement'   => str_repeat( 'm', PublicationConstraints::MISSION_MAX_LENGTH + 10 ),
                'llms_custom_instructions' => str_repeat( 'g', PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH + 10 ),
                'site_guide_instructions'  => str_repeat( 's', PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH + 10 ),
                'ai_business_description'  => str_repeat( 'd', PublicationConstraints::MISSION_MAX_LENGTH + 10 ),
                'ai_topics'                => implode( ',', $topics ),
                'ai_action_mappings'       => array(
                    array(
                        'url'  => 'https://example.com/contact',
                        'type' => 'ContactAction',
                        'desc' => str_repeat( 'a', \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH + 10 ),
                    ),
                ),
            )
        );

        $this->assertSame( PublicationConstraints::MISSION_MAX_LENGTH, strlen( $result['llms_mission_statement'] ) );
        $this->assertSame( PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH, strlen( $result['llms_custom_instructions'] ) );
        $this->assertSame( PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH, strlen( $result['site_guide_instructions'] ) );
        $this->assertSame( PublicationConstraints::MISSION_MAX_LENGTH, strlen( $result['ai_business_description'] ) );
        $this->assertSame( \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH, strlen( $result['ai_action_mappings'][0]['desc'] ) );
        $this->assertCount( PublicationConstraints::TOPICS_MAX, explode( ', ', $result['ai_topics'] ) );
        $this->assertStringStartsWith( 'WordPress, Topic 0', $result['ai_topics'] );
    }

    public function test_malformed_nested_post_values_fail_closed_without_php_warnings(): void {
        $_POST['cybermaps_active_tab'] = array( 'ai' );

        set_error_handler(
            static function ( int $severity, string $message, string $file, int $line ): never {
                throw new \ErrorException( $message, 0, $severity, $file, $line );
            }
        );
        try {
            $result = SettingsSanitizer::sanitize(
                array(
                    'media_discovery_intensity' => array( 'advanced' ),
                    'websub_hubs'                => array( 'https://hub.example/' ),
                    'news_publication_name'      => (object) array( 'name' => 'News' ),
                    'rss_sitemap_post_types'     => new \stdClass(),
                    'external_sitemaps'          => array( 'https://example.com/map.xml' ),
                    'external_pages'             => new \stdClass(),
                    'exclude_post_ids'           => array( 1, 2 ),
                    'exclude_categories'         => new \stdClass(),
                    'site_language'              => array( 'en-US' ),
                    'ai_manifest_endpoints'      => array( array( 'llms.txt' ), new \stdClass() ),
                    'ai_capabilities'            => array( array( 'search_content' ), new \stdClass() ),
                    'ai_action_mappings'         => array(
                        array(
                            'url'  => 'https://example.com/contact',
                            'type' => 'ContactAction',
                            'desc' => array( 'Nested description' ),
                        ),
                    ),
                    'llms_title_override'        => array( 'Title' ),
                    'llms_content_license'       => array( 'CC-BY-4.0' ),
                    'llms_exclude_ids'           => array( array( 1 ), new \stdClass() ),
                    'ai_sitemap_exclude_ids'     => new \stdClass(),
                    'ai_sitemap_exclude_terms'   => array( array( 'news' ), new \stdClass() ),
                    'llms_filter_taxonomies'     => array( 'category' ),
                    'llms_pinned_ids'            => new \stdClass(),
                    'ai_business_description'    => array( 'Description' ),
                    'ai_topics'                  => array( array( 'SEO' ), 'WordPress' ),
                    'llms_mission_statement'     => array( 'Mission' ),
                    'llms_custom_instructions'   => new \stdClass(),
                    'site_guide_instructions'    => array( 'Guide' ),
                    'ai_licensing_email'         => array( 'licensing@example.com' ),
                    'ai_usage_rag'               => array( 'allow' ),
                    'ai_usage_training'          => new \stdClass(),
                    'ai_usage_commercial'        => array( 'forbid' ),
                    'agency_name'                => array( 'Agency' ),
                    'site_name_override'          => new \stdClass(),
                    'report_theme'               => array( 'midnight' ),
                    'api_secret'                 => new \stdClass(),
                )
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame( 'none', $result['media_discovery_intensity'] );
        $this->assertSame( '', $result['websub_hubs'] );
        $this->assertSame( '', $result['news_publication_name'] );
        $this->assertSame( array(), $result['ai_manifest_endpoints'] );
        $this->assertSame( array(), $result['ai_capabilities'] );
        $this->assertSame( '', $result['ai_action_mappings'][0]['desc'] );
        $this->assertSame( '', $result['llms_title_override'] );
        $this->assertSame( '', $result['llms_content_license'] );
        $this->assertSame( '', $result['llms_mission_statement'] );
        $this->assertSame( '', $result['llms_custom_instructions'] );
        $this->assertSame( '', $result['site_guide_instructions'] );
        $this->assertSame( 'swiss', $result['report_theme'] );
        $this->assertIsString( $result['api_secret'] );
        $this->assertNotSame( '', $result['api_secret'] );
    }

    public function test_robots_manager_override_shape(): void {
        $input = array(
            'takeover_enabled' => '1',
            'overrides'        => array(
                'gptbot' => array(
                    'robots' => '1',
                    'llm'    => '',
                    'tpm'    => '10',
                ),
            ),
            'manual_directives' => "User-agent: *\nDisallow:",
            'content_signals'   => array(
                'ai-train' => 'no',
                'search'   => 'yes',
            ),
        );

        $result = RobotsManagerSanitizer::sanitize( $input );

        $this->assertTrue( $result['takeover_enabled'] );
        $this->assertTrue( $result['overrides']['gptbot']['robots'] );
        $this->assertFalse( $result['overrides']['gptbot']['llm'] );
        $this->assertSame( 10, $result['overrides']['gptbot']['tpm'] );
        $this->assertSame( 'no', $result['content_signals']['ai-train'] );
        $this->assertSame( 'yes', $result['content_signals']['search'] );
		$this->assertFalse( $result['content_usage_enabled'] );
		$this->assertSame( array(), $result['content_usage_overrides'] );
    }

    public function test_robots_manager_reset_removes_all_per_bot_overrides(): void {
        $result = RobotsManagerSanitizer::sanitize(
            array(
                'takeover_enabled' => '1',
                'reset_overrides'  => '1',
                'overrides'        => array(
                    'gptbot' => array(
                        'robots' => '1',
                        'llm'    => '1',
                        'tpm'    => '900',
                    ),
                ),
            )
        );

        $this->assertTrue( $result['takeover_enabled'] );
        $this->assertSame( array(), $result['overrides'] );
    }

    public function test_robots_manager_keeps_rendered_registry_defaults_sparse(): void {
		$registry = \Cybermaps\Core\CrawlerRegistry::get_policy_bots();
        $rendered = array();
        foreach ( $registry as $bot_id => $bot ) {
            $rendered[ $bot_id ] = array(
                'robots' => ! empty( $bot->default['robots'] ) ? '1' : '',
                'llm'    => ! empty( $bot->default['llm'] ) ? '1' : '',
                'tpm'    => '0',
            );
        }

        $result = RobotsManagerSanitizer::sanitize(
            array(
                'overrides' => $rendered,
            )
        );

        $this->assertSame( array(), $result['overrides'] );
    }

    public function test_robots_manager_accepts_browser_compacted_json_payload(): void {
        $result = RobotsManagerSanitizer::sanitize(
            wp_json_encode(
                array(
                    'takeover_enabled' => '1',
                    'overrides' => array(
                        'gptbot' => array(
                            'robots' => '',
                            'llm'    => '1',
                            'tpm'    => '120',
                        ),
                    ),
                )
            )
        );

        $this->assertTrue( $result['takeover_enabled'] );
        $this->assertSame(
            array(
                'robots' => false,
                'llm'    => true,
                'tpm'    => 120,
            ),
            $result['overrides']['gptbot']
        );
    }

    public function test_robots_manager_persists_only_values_that_differ_from_registry_defaults(): void {
        $result = RobotsManagerSanitizer::sanitize(
            array(
                'overrides' => array(
                    'gptbot' => array(
                        'robots' => '1',
                        'llm'    => '1',
                        'tpm'    => '0',
                    ),
                    'chatgpt-user' => array(
                        'robots' => '',
                        'llm'    => '1',
                        'tpm'    => '250',
                    ),
                ),
            )
        );

        $this->assertSame(
            array(
                'chatgpt-user' => array(
                    'robots' => false,
                    'llm'    => true,
                    'tpm'    => 250,
                ),
            ),
            $result['overrides']
        );
    }

    public function test_robots_manager_rejects_manifest_targets_for_non_ai_crawlers(): void {
        $result = RobotsManagerSanitizer::sanitize(
            array(
                'overrides' => array(
                    'googlebot' => array(
                        'robots' => '1',
                        'llm'    => '1',
                        'tpm'    => '25',
                    ),
                ),
            )
        );

        $this->assertSame(
            array(
                'robots' => true,
                'llm'    => false,
                'tpm'    => 25,
            ),
            $result['overrides']['googlebot']
        );
    }

    public function test_robots_manager_maps_retired_override_ids_to_current_controls(): void {
        $result = RobotsManagerSanitizer::sanitize(
            array(
                'overrides' => array(
                    'anthropic-ai' => array(
                        'robots' => '',
                        'llm'    => '1',
                        'tpm'    => '40',
                    ),
                    'claude-web' => array(
                        'robots' => '1',
                        'llm'    => '',
                        'tpm'    => '0',
                    ),
                    'searchgpt-lib' => array(
                        'robots' => '',
                        'llm'    => '1',
                        'tpm'    => '0',
                    ),
                ),
            )
        );

        $this->assertSame(
            array(
                'claudebot' => array(
                    'robots' => false,
                    'llm'    => true,
                    'tpm'    => 40,
                ),
                'claude-user' => array(
                    'robots' => true,
                    'llm'    => false,
                    'tpm'    => 0,
                ),
                'oai-searchbot' => array(
                    'robots' => false,
                    'llm'    => true,
                    'tpm'    => 0,
                ),
            ),
            $result['overrides']
        );
    }

    public function test_robots_manager_omits_unpublished_and_invalid_content_signals(): void {
        $result = RobotsManagerSanitizer::sanitize(
            array(
                'content_signals' => array(
                    'ai-train'  => '',
                    'search'    => 'no',
                    'ai-input'  => 'yes',
                    'invented'  => 'yes',
                ),
            )
        );

        $this->assertSame(
            array(
                'search'   => 'no',
                'ai-input' => 'yes',
            ),
            $result['content_signals']
        );
    }

    public function test_robots_manager_bounds_manual_directives_on_save(): void {
        $result = RobotsManagerSanitizer::sanitize(
            array(
                'manual_directives' => str_repeat(
                    'x',
                    \Cybermaps\Discovery\Robots::MAX_MANUAL_DIRECTIVES_BYTES + 100
                ),
            )
        );

        $this->assertSame(
            \Cybermaps\Discovery\Robots::MAX_MANUAL_DIRECTIVES_BYTES,
            strlen( $result['manual_directives'] )
        );
    }

    public function test_robots_manager_reset_and_legacy_content_usage_aliases_are_characterized(): void {
        $result = RobotsManagerSanitizer::sanitize(
            array(
                'takeover_enabled' => '1',
                'reset_overrides' => '1',
                'overrides' => array(
                    'gptbot' => array( 'robots' => '1', 'llm' => '1', 'tpm' => '999' ),
                ),
                'content_usage_overrides' => array(
                    '/docs/' => array( 'train-ai' => 'n', 'search' => 'y' ),
                ),
            )
        );

        $this->assertTrue( $result['takeover_enabled'] );
        $this->assertSame( array(), $result['overrides'] );
        $this->assertSame( array( '/docs/' => array( 'ai-train' => 'no', 'search' => 'yes' ) ), $result['content_usage_overrides'] );
    }

    public function test_robots_manager_sanitizes_bounded_content_usage_path_overrides(): void {
        $input = array(
            'content_usage_enabled'   => '1',
            'content_usage_overrides' => array(
                array(
                    'path'     => 'docs//private/?ignored=yes',
                    'ai-train' => 'y',
                    'search'   => 'no',
                    'ai-input' => 'yes',
                ),
                '/docs/' => array( 'train-ai' => 'n' ),
                '/bad path/' => array( 'search' => 'yes' ),
            ),
        );

        for ( $index = 0; $index < 55; ++$index ) {
            $input['content_usage_overrides'][ '/path-' . $index . '/' ] = array( 'search' => 'yes' );
        }

        $result = RobotsManagerSanitizer::sanitize( $input );

        $this->assertTrue( $result['content_usage_enabled'] );
        $this->assertCount( \Cybermaps\Discovery\Robots::MAX_CONTENT_USAGE_OVERRIDES, $result['content_usage_overrides'] );
        $this->assertSame( array( 'ai-train' => 'yes', 'search' => 'no' ), $result['content_usage_overrides']['/docs/private/'] );
        $this->assertSame( array( 'ai-train' => 'no' ), $result['content_usage_overrides']['/docs/'] );
        $this->assertArrayNotHasKey( '/bad path/', $result['content_usage_overrides'] );
        $this->assertArrayNotHasKey( 'ai-input', $result['content_usage_overrides']['/docs/private/'] );
    }

    public function test_robots_manager_preserves_inactive_content_usage_submission(): void {
        global $cybermaps_mock_options;
        $stored = array(
            'content_usage_enabled'   => true,
            'content_usage_overrides' => array( '/private/' => array( 'search' => 'no' ) ),
        );
        $cybermaps_mock_options['cybermaps_robots_manager'] = $stored;

        $this->assertSame( $stored, RobotsManagerSanitizer::sanitize( null ) );
    }

    public function test_trusted_proxy_fields_are_canonical_and_bounded(): void {
        $_POST['cybermaps_active_tab'] = 'advanced';

        $cidrs  = array_map(
            static fn( int $index ): string => '10.0.' . $index . '.0/24',
            range( 0, 70 )
        );
        $result = SettingsSanitizer::sanitize(
            array(
                'trusted_proxy_header' => 'X-Forwarded-For',
                'trusted_proxy_cidrs'  => implode(
                    "\n",
                    array_merge(
                        array( 'bad-value', '2001:db8::/32' ),
                        $cidrs,
                        array( '10.0.0.0/24' )
                    )
                ),
            )
        );

        $stored = explode( "\n", (string) $result['trusted_proxy_cidrs'] );
        $this->assertSame( 'x_forwarded_for', $result['trusted_proxy_header'] );
        $this->assertCount( 64, $stored );
        $this->assertSame( '2001:db8::/32', $stored[0] );
        $this->assertSame( '10.0.0.0/24', $stored[1] );
        $this->assertNotContains( 'bad-value', $stored );
    }

    public function test_unrelated_tab_saves_preserve_trusted_proxy_settings(): void {
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
            'trusted_proxy_header' => 'forwarded',
            'trusted_proxy_cidrs'  => "10.0.0.0/8\n2001:db8::/32",
        );

        $_POST['cybermaps_active_tab'] = 'review';
        $result                         = SettingsSanitizer::sanitize(
            array(
                'log_retention_days' => '45',
            )
        );

        $this->assertSame( 'forwarded', $result['trusted_proxy_header'] );
        $this->assertSame( "10.0.0.0/8\n2001:db8::/32", $result['trusted_proxy_cidrs'] );
        $this->assertSame( 45, $result['log_retention_days'] );
    }
}
