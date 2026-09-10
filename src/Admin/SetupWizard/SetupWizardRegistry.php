<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

use Cybermaps\Admin\AIConfigurationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declarative contract for the optional Guided Setup workspace.
 *
 * This is deliberately separate from the machine-editable configuration
 * catalog. The catalog defines what can be written; this registry defines
 * which fields a short, human-reviewed setup flow may touch.
 */
final class SetupWizardRegistry {
	public const VERSION = 1;

	/** @var string[] */
	private const SECTION_IDS = array(
		'strategy',
		'sitemaps',
		'ai',
		'identity',
		'analytics',
		'delivery',
		'reports',
	);

	/**
	 * Sections intentionally controlled by the wizard. `identity_catalogs` is
	 * an explicit catalog action and is not included in identity reset.
	 *
	 * @return array<string,string[]>
	 */
	public static function controlled_fields(): array {
		return array(
			'strategy'  => array(
				'discovery_archetype',
				'discovery_overrides',
				'discovery_type_intents',
				'discovery_disabled',
			),
			'sitemaps'  => array(
				'include_homepage',
				'include_authors',
				'include_archives',
				'include_empty_terms',
				'inject_robots',
				'enable_caching',
				'enable_translation_integrations',
				'enable_indexnow',
				'enable_websub',
				'websub_hubs',
				'enable_google_news',
				'redirect_wp_sitemap',
				'enable_video_schema',
				'enable_rss_sitemap',
				'rss_sitemap_types',
				'enable_shortcode',
				'media_discovery_intensity',
				'enable_multimodal_discovery',
			),
			'ai'        => array(
				'enable_discovery_hub',
				'mcp_mode',
				'enable_header_discovery',
				'enable_llms_full',
				'enable_llms_tldr',
				'enable_rag_chunks',
				'enable_content_hints',
				'enable_multilingual_hub',
				'llms_include_sitemap_link',
				'llms_mission_statement',
				'llms_content_license',
				'llms_included_types',
				'ai_business_description',
				'ai_topics',
				'ai_capabilities',
				'ai_manifest_endpoints',
				'ai_sitemap_types',
				'ai_kg_link_org',
				'ai_licensing_email',
				'ai_usage_rag',
				'ai_usage_training',
				'ai_usage_commercial',
				'content_signals',
			),
			'identity'  => array(
				'identity_type',
				'identity_precise_type',
				'identity_name',
				'identity_description',
				'identity_image_id',
				'identity_catalogs',
				'ai_kg_link_org',
			),
			'analytics' => array(
				'enable_analytics',
				'anonymize_analytics_ips',
				'log_retention_days',
			),
			'delivery'  => array( 'static_engine_mode' ),
			'reports'   => array(
				'audit_post_min_words',
				'audit_post_max_age_days',
				'audit_post_require_media',
				'audit_page_min_words',
				'audit_page_max_age_days',
				'audit_page_require_media',
				'agency_name',
				'agency_url',
				'agency_logo',
				'site_name_override',
				'report_theme',
			),
		);
	}

	/**
	 * Every field in the canonical catalog has one deliberate wizard policy.
	 *
	 * @return array<string,string>
	 */
	public static function field_policies(): array {
		$policies = array_fill_keys( array_keys( AIConfigurationRegistry::get_fields() ), 'manual_only' );
		foreach ( self::controlled_fields() as $section_id => $field_ids ) {
			foreach ( $field_ids as $field_id ) {
				if ( ! isset( $policies[ $field_id ] ) ) {
					continue;
				}
				$policies[ $field_id ] = in_array(
					$field_id,
					array(
						'discovery_overrides',
						'discovery_type_intents',
						'discovery_disabled',
						'websub_hubs',
						'enable_video_schema',
						'enable_multimodal_discovery',
						'enable_multilingual_hub',
						'ai_manifest_endpoints',
						'content_signals',
					),
					true
				) ? 'derived' : 'explicit';
			}
		}

		// This setting is intentionally never placed in an onboarding flow.
		$policies['ai_kg_expose_admin'] = 'forbidden';
		return $policies;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function steps(): array {
		return array(
			array(
				'id'          => 'profile',
				'label'       => __( 'Site Profile', 'cybermaps' ),
				'description' => __( 'Choose the content strategy that best fits this website.', 'cybermaps' ),
				'sections'    => array( 'strategy' ),
			),
			array(
				'id'          => 'sitemaps',
				'label'       => __( 'Sitemaps', 'cybermaps' ),
				'description' => __( 'Set the public sitemap surfaces and practical delivery choices.', 'cybermaps' ),
				'sections'    => array( 'sitemaps' ),
			),
			array(
				'id'          => 'ai',
				'label'       => __( 'AI Publishing', 'cybermaps' ),
				'description' => __( 'Configure machine-readable discovery and publisher preferences.', 'cybermaps' ),
				'sections'    => array( 'ai' ),
			),
			array(
				'id'          => 'identity',
				'label'       => __( 'Identity', 'cybermaps' ),
				'description' => __( 'Optionally publish factual identity details and an automatic offer catalog.', 'cybermaps' ),
				'sections'    => array( 'identity' ),
			),
			array(
				'id'          => 'operations',
				'label'       => __( 'Operations', 'cybermaps' ),
				'description' => __( 'Set analytics privacy, publication delivery, and report defaults.', 'cybermaps' ),
				'sections'    => array( 'analytics', 'delivery', 'reports' ),
			),
			array(
				'id'          => 'review',
				'label'       => __( 'Review', 'cybermaps' ),
				'description' => __( 'Review the exact sanitized changes before applying them.', 'cybermaps' ),
				'sections'    => array(),
			),
		);
	}

	/**
	 * Browser-safe question metadata. Mapping remains server-authoritative in
	 * SetupWizardPlanFactory; these definitions only describe the UI.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function questions(): array {
		return array(
			array(
				'id'      => 'strategy_profile',
				'step'    => 'profile',
				'section' => 'strategy',
				'type'    => 'archetype',
				'label'   => __( 'What best describes this site?', 'cybermaps' ),
			),
			array(
				'id'      => 'strategy_clear_custom',
				'step'    => 'profile',
				'section' => 'strategy',
				'type'    => 'boolean',
				'label'   => __( 'Replace custom content-group adjustments with the selected profile baseline', 'cybermaps' ),
			),
			array(
				'id'      => 'sitemap_surfaces',
				'step'    => 'sitemaps',
				'section' => 'sitemaps',
				'type'    => 'multi',
				'label'   => __( 'Include these sitemap surfaces', 'cybermaps' ),
				'options' => array( 'homepage', 'authors', 'archives', 'empty_terms' ),
			),
			array(
				'id'      => 'sitemap_media',
				'step'    => 'sitemaps',
				'section' => 'sitemaps',
				'type'    => 'choice',
				'label'   => __( 'Media discovery depth', 'cybermaps' ),
				'options' => array( 'none', 'standard', 'advanced' ),
			),
			array(
				'id'      => 'sitemap_media_features',
				'step'    => 'sitemaps',
				'section' => 'sitemaps',
				'type'    => 'multi',
				'label'   => __( 'Media publishing enhancements', 'cybermaps' ),
				'options' => array( 'video', 'multimodal' ),
			),
			array(
				'id'      => 'sitemap_specials',
				'step'    => 'sitemaps',
				'section' => 'sitemaps',
				'type'    => 'multi',
				'label'   => __( 'Specialized publications', 'cybermaps' ),
				'options' => array( 'news', 'rss', 'html', 'indexnow', 'websub' ),
			),
			array(
				'id'      => 'sitemap_rss_types',
				'step'    => 'sitemaps',
				'section' => 'sitemaps',
				'type'    => 'post_types',
				'label'   => __( 'RSS content types', 'cybermaps' ),
			),
			array(
				'id'      => 'sitemap_integration',
				'step'    => 'sitemaps',
				'section' => 'sitemaps',
				'type'    => 'multi',
				'label'   => __( 'WordPress integration', 'cybermaps' ),
				'options' => array( 'redirect_core', 'robots', 'cache' ),
			),
			array(
				'id'      => 'sitemap_translations',
				'step'    => 'sitemaps',
				'section' => 'sitemaps',
				'type'    => 'boolean',
				'label'   => __( 'Publish alternate-language sitemap relationships', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_hub',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'boolean',
				'label'   => __( 'Enable the AI Publication Hub', 'cybermaps' ),
			),
			array(
				'id'      => 'mcp_mode',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'choice',
				'label'   => __( 'Model Context Protocol access', 'cybermaps' ),
				'options' => array( 'off', 'discovery', 'read_only', 'operations' ),
			),
			array(
				'id'      => 'ai_types',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'post_types',
				'label'   => __( 'LLMS and search content types', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_separate_sitemap_types',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'boolean',
				'label'   => __( 'Use different content types for the AI sitemap', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_sitemap_types',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'post_types',
				'label'   => __( 'AI sitemap content types', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_features',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'multi',
				'label'   => __( 'AI publication features', 'cybermaps' ),
				'options' => array( 'headers', 'hints', 'sitemap_link', 'full', 'tldr', 'rag', 'localized' ),
			),
			array(
				'id'      => 'ai_mission',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'textarea',
				'label'   => __( 'LLMS site summary', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_business_description',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'textarea',
				'label'   => __( 'AI Discovery Manifest summary', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_topics',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'text',
				'label'   => __( 'Site topics', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_capabilities',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'multi',
				'label'   => __( 'Truthful capability declarations', 'cybermaps' ),
				'options' => array( 'search_content', 'read_articles', 'extract_entities' ),
			),
			array(
				'id'      => 'ai_usage_rag',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'choice',
				'label'   => __( 'Retrieval and RAG use', 'cybermaps' ),
				'options' => array( 'allow', 'limited', 'forbid' ),
			),
			array(
				'id'      => 'ai_usage_training',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'choice',
				'label'   => __( 'Model training use', 'cybermaps' ),
				'options' => array( 'allow', 'forbid' ),
			),
			array(
				'id'      => 'ai_usage_commercial',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'choice',
				'label'   => __( 'Commercial reuse', 'cybermaps' ),
				'options' => array( 'allow', 'forbid' ),
			),
			array(
				'id'      => 'ai_license',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'license',
				'label'   => __( 'Content license assertion', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_licensing_email',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'email',
				'label'   => __( 'Public licensing email', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_sync_signals',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'boolean',
				'label'   => __( 'Publish matching Content-Signal declarations', 'cybermaps' ),
			),
			array(
				'id'      => 'ai_signal_search',
				'step'    => 'ai',
				'section' => 'ai',
				'type'    => 'choice',
				'label'   => __( 'Search indexing preference', 'cybermaps' ),
				'options' => array( 'yes', 'no' ),
			),
			array(
				'id'      => 'identity_type',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'choice',
				'label'   => __( 'What does this website represent?', 'cybermaps' ),
				'options' => array( 'Organization', 'LocalBusiness', 'Person' ),
			),
			array(
				'id'      => 'identity_precise_type',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'schema_type',
				'label'   => __( 'Specific Schema.org type', 'cybermaps' ),
			),
			array(
				'id'      => 'identity_name',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'text',
				'label'   => __( 'Public identity name', 'cybermaps' ),
			),
			array(
				'id'      => 'identity_description',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'textarea',
				'label'   => __( 'Public identity description', 'cybermaps' ),
			),
			array(
				'id'      => 'identity_image_id',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'media',
				'label'   => __( 'Identity image', 'cybermaps' ),
			),
			array(
				'id'      => 'identity_kg_link',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'boolean',
				'label'   => __( 'Link this identity to the website in the Knowledge Graph', 'cybermaps' ),
			),
			array(
				'id'      => 'catalog_action',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'choice',
				'label'   => __( 'Automatic catalog', 'cybermaps' ),
				'options' => array( 'none', 'add', 'edit' ),
			),
			array(
				'id'      => 'catalog_index',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'catalog',
				'label'   => __( 'Existing automatic catalog', 'cybermaps' ),
			),
			array(
				'id'      => 'catalog_parent_id',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'catalog_parent',
				'label'   => __( 'Parent Page', 'cybermaps' ),
			),
			array(
				'id'      => 'catalog_item_type',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'choice',
				'label'   => __( 'Catalog items represent', 'cybermaps' ),
				'options' => array( 'Service', 'Product' ),
			),
			array(
				'id'      => 'catalog_name',
				'step'    => 'identity',
				'section' => 'identity',
				'type'    => 'text',
				'label'   => __( 'Catalog name', 'cybermaps' ),
			),
			array(
				'id'      => 'analytics_mode',
				'step'    => 'operations',
				'section' => 'analytics',
				'type'    => 'choice',
				'label'   => __( 'Crawler analytics', 'cybermaps' ),
				'options' => array( 'off', 'anonymized', 'full' ),
			),
			array(
				'id'      => 'analytics_retention',
				'step'    => 'operations',
				'section' => 'analytics',
				'type'    => 'number',
				'label'   => __( 'Analytics retention days', 'cybermaps' ),
			),
			array(
				'id'      => 'delivery_mode',
				'step'    => 'operations',
				'section' => 'delivery',
				'type'    => 'choice',
				'label'   => __( 'Static File Engine delivery', 'cybermaps' ),
				'options' => array( 'off', 'well_known', 'all' ),
			),
			array(
				'id'      => 'report_measurements',
				'step'    => 'operations',
				'section' => 'reports',
				'type'    => 'report_policy',
				'label'   => __( 'Content Intelligence measurement policy', 'cybermaps' ),
			),
			array(
				'id'      => 'report_branding_configure',
				'step'    => 'operations',
				'section' => 'reports',
				'type'    => 'boolean',
				'label'   => __( 'Configure client report presentation', 'cybermaps' ),
			),
			array(
				'id'      => 'report_branding',
				'step'    => 'operations',
				'section' => 'reports',
				'type'    => 'report_branding',
				'label'   => __( 'Client report presentation', 'cybermaps' ),
			),
		);
	}

	/** @return string[] */
	public static function section_ids(): array {
		return self::SECTION_IDS;
	}

	public static function is_known_section( string $section_id ): bool {
		return in_array( $section_id, self::SECTION_IDS, true );
	}
}
