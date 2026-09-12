<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

use Cybermaps\Admin\AIConfigurationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Declarative contract for the three-step Quick Setup survey. */
final class SetupWizardRegistry {
	public const VERSION = 2;

	/** @return array<string,string[]> */
	public static function controlled_fields(): array {
		return array(
			'strategy'  => array( 'discovery_archetype' ),
			'sitemaps'  => array(
				'include_homepage',
				'include_authors',
				'include_archives',
				'include_empty_terms',
				'inject_robots',
				'enable_caching',
				'enable_translation_integrations',
				'enable_websub',
				'enable_google_news',
				'redirect_wp_sitemap',
				'enable_rss_sitemap',
				'rss_sitemap_types',
				'enable_shortcode',
				'media_discovery_intensity',
			),
			'ai'        => array(
				'enable_discovery_hub',
				'enable_header_discovery',
				'enable_content_hints',
				'enable_multilingual_hub',
				'llms_include_sitemap_link',
				'llms_mission_statement',
				'llms_included_types',
				'ai_business_description',
				'ai_manifest_endpoints',
				'ai_sitemap_types',
				'ai_kg_link_org',
			),
			'identity'  => array(
				'identity_type',
				'identity_precise_type',
				'identity_name',
				'identity_description',
				'identity_image_id',
			),
			'analytics' => array( 'enable_analytics', 'anonymize_analytics_ips', 'log_retention_days' ),
			'delivery'  => array( 'static_engine_mode' ),
		);
	}

	/** @return array<string,string> */
	public static function field_policies(): array {
		$policies = array_fill_keys( array_keys( AIConfigurationRegistry::get_fields() ), 'manual_only' );
		foreach ( self::controlled_fields() as $field_ids ) {
			foreach ( $field_ids as $field_id ) {
				if ( isset( $policies[ $field_id ] ) ) {
					$policies[ $field_id ] = in_array(
						$field_id,
						array( 'enable_multilingual_hub', 'ai_manifest_endpoints', 'ai_kg_link_org' ),
						true
					) ? 'derived' : 'explicit';
				}
			}
		}
		$policies['ai_kg_expose_admin'] = 'forbidden';
		return $policies;
	}

	/** @return array<int,array<string,string>> */
	public static function steps(): array {
		return array(
			array(
				'id'          => 'website',
				'label'       => __( 'Your website', 'cybermaps' ),
				'description' => __( 'Tell us what you publish so Cybermaps can choose a useful starting point.', 'cybermaps' ),
			),
			array(
				'id'          => 'priorities',
				'label'       => __( 'Your priorities', 'cybermaps' ),
				'description' => __( 'Choose how visible and hands-on you want Cybermaps to be.', 'cybermaps' ),
			),
			array(
				'id'          => 'identity',
				'label'       => __( 'About you', 'cybermaps' ),
				'description' => __( 'Give search engines and AI tools a clear, public introduction.', 'cybermaps' ),
			),
		);
	}

	/** @return array<string,array<string,array<string,string>>> */
	public static function choices(): array {
		return array(
			'website_type'  => array(
				'blog'            => self::choice( __( 'Blog', 'cybermaps' ), __( 'Articles, essays, and regular posts.', 'cybermaps' ), '✎' ),
				'newspaper'       => self::choice( __( 'News or magazine', 'cybermaps' ), __( 'Timely stories from multiple sections or authors.', 'cybermaps' ), '▤' ),
				'ecommerce'       => self::choice( __( 'Online store', 'cybermaps' ), __( 'Products, collections, and shopping pages.', 'cybermaps' ), '◇' ),
				'knowledgebase'   => self::choice( __( 'Guides or documentation', 'cybermaps' ), __( 'Help content, reference material, or a knowledge base.', 'cybermaps' ), '☷' ),
				'corporate'       => self::choice( __( 'Portfolio or agency', 'cybermaps' ), __( 'Projects, services, and work samples.', 'cybermaps' ), '◫' ),
				'small-business'  => self::choice( __( 'Local business', 'cybermaps' ), __( 'A business serving customers in a place or region.', 'cybermaps' ), '⌖' ),
				'medium-business' => self::choice( __( 'Company', 'cybermaps' ), __( 'A mix of company, service, and editorial content.', 'cybermaps' ), '◎' ),
			),
			'ai_visibility' => array(
				'on'  => self::choice( __( 'Yes, help them find me', 'cybermaps' ), __( 'Publish a clear, machine-readable guide to your content.', 'cybermaps' ), '✦' ),
				'off' => self::choice( __( 'Not right now', 'cybermaps' ), __( 'Leave AI discovery switched off for now.', 'cybermaps' ), '○' ),
			),
			'operations'    => array(
				'insights'    => self::choice( __( 'Show me crawler activity', 'cybermaps' ), __( 'Keep a private, anonymized 30-day activity history.', 'cybermaps' ), '◔' ),
				'performance' => self::choice( __( 'Keep things lightweight', 'cybermaps' ), __( 'Skip activity logging and favor static delivery.', 'cybermaps' ), 'ϟ' ),
			),
			'identity_type' => array(
				'Person'        => self::choice( __( 'A person', 'cybermaps' ), __( 'A personal site, creator, or independent expert.', 'cybermaps' ), '◉' ),
				'Organization'  => self::choice( __( 'A business or organization', 'cybermaps' ), __( 'A company, nonprofit, team, or brand.', 'cybermaps' ), '▦' ),
				'LocalBusiness' => self::choice( __( 'A local business', 'cybermaps' ), __( 'A customer-facing business tied to a location.', 'cybermaps' ), '⌂' ),
			),
		);
	}

	/** @return array<string,string> */
	private static function choice( string $label, string $description, string $icon ): array {
		return array(
			'label'       => $label,
			'description' => $description,
			'icon'        => $icon,
		);
	}
}
