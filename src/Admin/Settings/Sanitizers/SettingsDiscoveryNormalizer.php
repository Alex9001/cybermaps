<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsDiscoveryNormalizer {
	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @param array<string, mixed> $old_options
	 */
	public static function normalize( array $input, array $sanitized, array $old_options, string $active_tab ): array {
		unset( $old_options );
		SettingsSanitizer::canonicalize_ai_exclusion_ids( $input, $sanitized );
		$sanitized = self::normalize_discovery_flags( $input, $sanitized, $active_tab );
		$sanitized = self::normalize_discovery_limits( $input, $sanitized );
		$sanitized = self::normalize_discovery_publication( $input, $sanitized, $active_tab );
		$sanitized = self::normalize_discovery_actions( $input, $sanitized, $active_tab );
		return self::normalize_discovery_content( $input, $sanitized );
	}

	/**
	 * Normalize discovery switches and mode selectors.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_discovery_flags( array $input, array $sanitized, string $active_tab ): array {
		if ( array_key_exists( 'media_discovery_intensity', $input ) ) {
			$intensity                              = sanitize_key( SettingsSanitizer::scalar_string( $input['media_discovery_intensity'] ) );
			$sanitized['media_discovery_intensity'] = in_array( $intensity, array( 'none', 'standard', 'advanced' ), true ) ? $intensity : 'none';
		}
		$checkboxes = array(
			'enable_multimodal_discovery' => 'sitemaps',
			'enable_discovery_hub'        => 'ai',
			'enable_llms_full'            => 'ai',
			'enable_llms_tldr'            => 'ai',
			'enable_header_discovery'     => 'ai',
			'enable_markdown_negotiation' => 'ai',
			'enable_webmcp'               => 'ai',
			'enable_rag_chunks'           => 'ai',
			'enable_content_hints'        => 'ai',
			'enable_multilingual_hub'     => 'ai',
			'llms_include_sitemap_link'   => 'ai',
			'ai_feed_full_content'        => 'ai',
			'ai_feed_include_authors'     => 'ai',
			'ai_kg_expose_admin'          => 'ai',
			'ai_kg_link_org'              => 'ai',
		);
		foreach ( $checkboxes as $key => $tab ) {
			if ( SettingsSanitizer::should_process_checkbox( $input, $key, $tab, $active_tab ) ) {
				$sanitized[ $key ] = ! empty( $input[ $key ] ) ? '1' : '0';
			}
		}
		if ( array_key_exists( 'mcp_mode', $input ) && is_scalar( $input['mcp_mode'] ) ) {
			$mode                  = sanitize_key( (string) $input['mcp_mode'] );
			$sanitized['mcp_mode'] = in_array( $mode, array( 'off', 'discovery', 'read_only', 'operations' ), true ) ? $mode : 'off';
		}
		if ( array_key_exists( 'agent_registration_mode', $input ) && is_scalar( $input['agent_registration_mode'] ) ) {
			$registration_mode                    = sanitize_key( (string) $input['agent_registration_mode'] );
			$sanitized['agent_registration_mode'] = in_array( $registration_mode, array( 'off', 'user_claimed' ), true ) ? $registration_mode : 'off';
		}
		unset( $sanitized['enable_semantic_snippets'], $sanitized['ai_custom_skill_prompt'] );
		return $sanitized;
	}

	/**
	 * Normalize bounded discovery quantities and exclusion terms.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_discovery_limits( array $input, array $sanitized ): array {
		if ( array_key_exists( 'rag_chunk_size', $input ) || array_key_exists( 'rag_chunk_overlap', $input ) ) {
			$chunk_config                   = \Cybermaps\Discovery\Chunker::normalize_configuration(
				array(
					'rag_chunk_size'    => SettingsSanitizer::value_or_default( $input['rag_chunk_size'] ?? ( $sanitized['rag_chunk_size'] ?? null ), \Cybermaps\Discovery\Chunker::DEFAULT_WINDOW_SIZE ),
					'rag_chunk_overlap' => SettingsSanitizer::value_or_default( $input['rag_chunk_overlap'] ?? ( $sanitized['rag_chunk_overlap'] ?? null ), 100 ),
				)
			);
			$sanitized['rag_chunk_size']    = $chunk_config['window_size'];
			$sanitized['rag_chunk_overlap'] = $chunk_config['overlap'];
		}
		$sanitized = self::normalize_limit_values( $input, $sanitized );
		if ( array_key_exists( 'ai_sitemap_exclude_terms', $input ) ) {
			$sanitized['ai_sitemap_exclude_terms'] = SettingsSanitizer::sanitize_term_list( $input['ai_sitemap_exclude_terms'] );
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_limit_values( array $input, array $sanitized ): array {
		$limits = array(
			'ai_sitemap_limit' => array(
				'method'  => 'ai_sitemap_limit',
				'default' => \Cybermaps\Discovery\PublicationConstraints::AI_SITEMAP_LIMIT_DEFAULT,
			),
			'llms_link_limit'  => array(
				'method'  => 'llms_link_limit',
				'default' => \Cybermaps\Discovery\PublicationConstraints::LLMS_LINK_LIMIT_DEFAULT,
			),
			'ai_feed_limit'    => array(
				'method'  => 'feed_limit',
				'default' => \Cybermaps\Discovery\PublicationConstraints::FEED_LIMIT_DEFAULT,
			),
		);
		foreach ( $limits as $key => $definition ) {
			if ( array_key_exists( $key, $input ) ) {
				$sanitized[ $key ] = call_user_func( array( \Cybermaps\Discovery\PublicationConstraints::class, $definition['method'] ), SettingsSanitizer::value_or_default( $input[ $key ], $definition['default'] ) );
			}
		}
		return $sanitized;
	}

	/**
	 * Normalize publication type, endpoint, and capability selections.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_discovery_publication( array $input, array $sanitized, string $active_tab ): array {
		$sanitized = self::normalize_publication_types( $input, $sanitized, $active_tab );
		$sanitized = self::normalize_manifest_endpoints( $input, $sanitized, $active_tab );
		return self::normalize_capabilities( $input, $sanitized, $active_tab );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_publication_types( array $input, array $sanitized, string $active_tab ): array {
		$fields = array( 'ai_sitemap_types', 'llms_included_types' );
		foreach ( $fields as $key ) {
			if ( array_key_exists( $key, $input ) || 'ai' === $active_tab ) {
				$sanitized[ $key ] = \Cybermaps\Core\PublicationPostTypes::filter_names( SettingsSanitizer::sanitize_key_list( $input[ $key ] ?? array(), \Cybermaps\Discovery\PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX ) );
			}
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_manifest_endpoints( array $input, array $sanitized, string $active_tab ): array {
		if ( array_key_exists( 'ai_manifest_endpoints', $input ) || 'ai' === $active_tab ) {
			$allowed                            = array( 'llms.txt', 'llms-full.txt', 'llms-tldr.txt', 'skill.md', 'ai-usage.json', 'ai-actions.json', 'knowledge-graph.json', 'feed.json', 'ai-sitemap.xml' );
			$sanitized['ai_manifest_endpoints'] = array_values( array_unique( array_intersect( SettingsSanitizer::sanitize_text_list( $input['ai_manifest_endpoints'] ?? array() ), $allowed ) ) );
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_capabilities( array $input, array $sanitized, string $active_tab ): array {
		if ( array_key_exists( 'ai_capabilities', $input ) || 'ai' === $active_tab ) {
			$sanitized['ai_capabilities'] = array_values( array_intersect( SettingsSanitizer::sanitize_key_list( $input['ai_capabilities'] ?? array() ), array( 'search_content', 'read_articles', 'extract_entities' ) ) );
		}
		return $sanitized;
	}

	/**
	 * Normalize custom sitemap links and action mappings.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_discovery_actions( array $input, array $sanitized, string $active_tab ): array {
		$sanitized = self::normalize_custom_links( $input, $sanitized, $active_tab );
		return self::normalize_action_mappings( $input, $sanitized, $active_tab );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_custom_links( array $input, array $sanitized, string $active_tab ): array {
		if ( ! array_key_exists( 'ai_sitemap_custom_links', $input ) && 'ai' !== $active_tab ) {
			return $sanitized;
		}
		$sanitized['ai_sitemap_custom_links'] = array();
		$links                                = is_array( $input['ai_sitemap_custom_links'] ?? null ) ? array_slice( $input['ai_sitemap_custom_links'], 0, \Cybermaps\Discovery\PublicationConstraints::CUSTOM_LINKS_MAX ) : array();
		foreach ( $links as $link ) {
			$url = is_array( $link ) ? \Cybermaps\Core\URLManager::sanitize_http_url( $link['url'] ?? '' ) : '';
			if ( '' !== $url ) {
				$priority                               = SettingsSanitizer::value_or_default( is_array( $link ) ? ( $link['priority'] ?? null ) : null, 0.5 );
				$sanitized['ai_sitemap_custom_links'][] = array(
					'url'      => $url,
					'priority' => max( 0.1, min( 1.0, (float) $priority ) ),
				);
			}
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_action_mappings( array $input, array $sanitized, string $active_tab ): array {
		if ( ! array_key_exists( 'ai_action_mappings', $input ) && 'ai' !== $active_tab ) {
			return $sanitized;
		}
		$sanitized['ai_action_mappings'] = array();
		$maps                            = is_array( $input['ai_action_mappings'] ?? null ) ? array_slice( $input['ai_action_mappings'], 0, \Cybermaps\Discovery\PublicationConstraints::ACTION_MAPPINGS_MAX ) : array();
		foreach ( $maps as $map ) {
			$normalized = self::normalize_action_mapping( $map );
			if ( null !== $normalized ) {
				$sanitized['ai_action_mappings'][] = $normalized;
			}
		}
		return $sanitized;
	}

	/**
	 * @param mixed $map
	 * @return array<string, string>|null
	 */
	private static function normalize_action_mapping( $map ): ?array {
		if ( ! is_array( $map ) ) {
			return null;
		}
		$url  = \Cybermaps\Core\URLManager::sanitize_http_url( $map['url'] ?? '' );
		$type = \Cybermaps\Discovery\PublicationConstraints::action_type( $map['type'] ?? '' );
		if ( '' === $url || '' === $type ) {
			return null;
		}
		return array(
			'url'  => $url,
			'type' => $type,
			'desc' => is_scalar( $map['desc'] ?? null ) ? \Cybermaps\Discovery\PublicationConstraints::bounded_text( sanitize_text_field( (string) $map['desc'] ), \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ) : '',
		);
	}

	/**
	 * Normalize descriptive, policy, and AI briefing fields.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_discovery_content( array $input, array $sanitized ): array {
		$sanitized = self::normalize_content_identity( $input, $sanitized );
		$sanitized = self::normalize_content_text( $input, $sanitized );
		$sanitized = self::normalize_content_policy( $input, $sanitized );
		return self::normalize_content_budget( $input, $sanitized );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_content_identity( array $input, array $sanitized ): array {
		$sanitized = self::normalize_identity_basics( $input, $sanitized );
		return self::normalize_identity_description( $input, $sanitized );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_identity_basics( array $input, array $sanitized ): array {
		if ( array_key_exists( 'llms_title_override', $input ) ) {
			$sanitized['llms_title_override'] = is_scalar( $input['llms_title_override'] ) ? sanitize_text_field( (string) $input['llms_title_override'] ) : '';
		}
		if ( array_key_exists( 'llms_content_license', $input ) ) {
			$sanitized['llms_content_license'] = \Cybermaps\Discovery\PublicationConstraints::content_license( $input['llms_content_license'] );
		}
		if ( array_key_exists( 'llms_exclude_ids', $input ) ) {
			$sanitized['llms_exclude_ids'] = SettingsSanitizer::sanitize_id_list( $input['llms_exclude_ids'] );
		}
		if ( array_key_exists( 'llms_filter_taxonomies', $input ) ) {
			$sanitized['llms_filter_taxonomies'] = SettingsSanitizer::sanitize_taxonomy_filter( $input['llms_filter_taxonomies'] );
		}
		if ( array_key_exists( 'llms_pinned_ids', $input ) ) {
			$sanitized['llms_pinned_ids'] = SettingsSanitizer::sanitize_id_list( $input['llms_pinned_ids'], \Cybermaps\Discovery\PublicationConstraints::BRIEFING_PINNED_IDS_MAX );
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_identity_description( array $input, array $sanitized ): array {
		if ( array_key_exists( 'ai_business_description', $input ) ) {
			$sanitized['ai_business_description'] = is_scalar( $input['ai_business_description'] ) ? \Cybermaps\Discovery\PublicationConstraints::bounded_text( sanitize_text_field( (string) $input['ai_business_description'] ), \Cybermaps\Discovery\PublicationConstraints::MISSION_MAX_LENGTH ) : '';
		}
		if ( array_key_exists( 'ai_topics', $input ) ) {
			$sanitized['ai_topics'] = implode( ', ', \Cybermaps\Discovery\PublicationConstraints::topics( $input['ai_topics'] ) );
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_content_text( array $input, array $sanitized ): array {
		$text_fields = array(
			'llms_mission_statement'   => \Cybermaps\Discovery\PublicationConstraints::MISSION_MAX_LENGTH,
			'llms_custom_instructions' => \Cybermaps\Discovery\PublicationConstraints::PUBLISHER_GUIDANCE_MAX_LENGTH,
			'site_guide_instructions'  => \Cybermaps\Discovery\PublicationConstraints::SITE_GUIDE_ADDITION_MAX_LENGTH,
		);
		foreach ( $text_fields as $key => $limit ) {
			if ( array_key_exists( $key, $input ) ) {
				$value             = is_scalar( $input[ $key ] ) ? sanitize_textarea_field( (string) $input[ $key ] ) : '';
				$sanitized[ $key ] = \Cybermaps\Discovery\PublicationConstraints::bounded_text( $value, $limit );
			}
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_content_policy( array $input, array $sanitized ): array {
		if ( array_key_exists( 'ai_licensing_email', $input ) ) {
			$sanitized['ai_licensing_email'] = is_scalar( $input['ai_licensing_email'] ) ? sanitize_email( (string) $input['ai_licensing_email'] ) : '';
		}
		self::normalize_usage_policy( $input, $sanitized );
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_content_budget( array $input, array $sanitized ): array {
		if ( array_key_exists( 'llms_tldr_token_budget', $input ) ) {
			$budget                              = SettingsSanitizer::value_or_default( $input['llms_tldr_token_budget'], \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_DEFAULT );
			$sanitized['llms_tldr_token_budget'] = max( \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MIN, min( \Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MAX, absint( $budget ) ) );
		}
		return $sanitized;
	}

	/**
	 * Normalize the three AI usage policy selectors.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 */
	private static function normalize_usage_policy( array $input, array &$sanitized ): void {
		$policies = array(
			'ai_usage_rag'        => array( 'allow', 'forbid', 'limited' ),
			'ai_usage_training'   => array( 'allow', 'forbid' ),
			'ai_usage_commercial' => array( 'allow', 'forbid' ),
		);
		$defaults = array(
			'ai_usage_rag'        => 'allow',
			'ai_usage_training'   => 'forbid',
			'ai_usage_commercial' => 'forbid',
		);
		foreach ( $policies as $key => $allowed ) {
			if ( array_key_exists( $key, $input ) ) {
				$value             = is_scalar( $input[ $key ] ) ? sanitize_key( (string) $input[ $key ] ) : '';
				$sanitized[ $key ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
			}
		}
	}
}
