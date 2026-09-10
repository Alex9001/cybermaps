<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cybermaps AI Discovery Manifest implementation.
 *
 * @deprecated 6.0.0 Use AIManifest for new integrations. The class remains for
 *                   backward compatibility with existing extension code.
 */
class ADP {
	/**
	 * Handle the Cybermaps AI Discovery Manifest request.
	 *
	 * @return void
	 */
	public function handle() {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		$path = \Cybermaps\Core\URLManager::get_request_path();
		if ( '/ai.json' !== $path ) {
			return;
		}

		$manifest = $this->get_manifest_data();

		$output = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		Integrity::send_headers( $output );
		header( 'Content-Type: application/json; charset=utf-8', true );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
		exit;
	}

	/**
	 * Get the Cybermaps AI Discovery Manifest data.
	 *
	 * @return array
	 */
	public function get_manifest_data() {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$manifest = get_transient( 'cybermaps_ai_manifest_v1' );
		if ( false !== $manifest && ! is_array( $manifest ) ) {
			delete_transient( 'cybermaps_ai_manifest_v1' );
			$manifest = false;
		}
		delete_transient( 'cybermaps_adp_manifest_v3_sync' );

		if ( false === $manifest ) {
			$sitemaps        = $this->sitemaps( $settings );
			$website         = $this->website( $settings );
			$endpoints       = $this->endpoints( $settings, $sitemaps );
			$capabilities    = $this->capabilities( $settings );
			$targeted_agents = $this->targeted_agents();
			$description     = $this->description( $settings );

			$manifest = array(
				'schema_version'  => '1.0',
				'name'            => get_bloginfo( 'name' ),
				'description'     => PublicationConstraints::bounded_text(
					$description,
					PublicationConstraints::MISSION_MAX_LENGTH
				),
				'website'         => $website,
				'capabilities'    => $capabilities,
				'endpoints'       => $endpoints,
				'targeted_agents' => $targeted_agents,
			);

			$publisher_guidance = PublisherGuidance::get( $settings );
			if ( '' !== $publisher_guidance ) {
				$manifest['publisher_guidance'] = $publisher_guidance;
			}

			$filtered_manifest = apply_filters( 'cybermaps_ai_manifest_final', $manifest );
			if ( is_array( $filtered_manifest ) ) {
				$manifest = $filtered_manifest;
			}
			set_transient( 'cybermaps_ai_manifest_v1', $manifest, DAY_IN_SECONDS );
		}

		return $manifest;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return string[]
	 */
	private function sitemaps( array $settings ): array {
		$base     = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$sitemaps = array( \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' ) );
		if ( ! empty( $settings['enable_google_news'] ) ) {
			$news_base  = \Cybermaps\Sitemap\Orchestrator::get_news_sitemap_base();
			$sitemaps[] = \Cybermaps\Core\URLManager::get_home_url( '/' . $news_base . '.xml' );
		}
		return $sitemaps;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<string,mixed>
	 */
	private function website( array $settings ): array {
		$configured = $settings['site_language'] ?? '';
		$language   = \Cybermaps\Core\TranslationHelper::normalize_hreflang(
			is_scalar( $configured ) ? (string) $configured : ''
		);
		if ( '' === $language ) {
			$language = \Cybermaps\Core\TranslationHelper::normalize_hreflang(
				(string) get_bloginfo( 'language' )
			);
		}
		if ( '' === $language ) {
			$language = 'en';
		}
		$stored_region = get_option( 'rss_language', 'US' );
		$region        = is_scalar( $stored_region )
			? sanitize_text_field( (string) $stored_region )
			: 'US';
		$website       = array(
			'url'              => \Cybermaps\Core\URLManager::get_home_url( '/' ),
			'primary_language' => PublicationConstraints::bounded_text( $language, 64 ),
			'topics'           => PublicationConstraints::topics( $settings['ai_topics'] ?? '' ),
			'region'           => PublicationConstraints::bounded_text( $region, 64 ),
		);
		$filtered      = apply_filters( 'cybermaps_ai_manifest_website', $website );
		return is_array( $filtered ) ? $filtered : $website;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @param string[]            $sitemaps Sitemap URLs.
	 * @return array<string,mixed>
	 */
	private function endpoints( array $settings, array $sitemaps ): array {
		$enabled     = isset( $settings['ai_manifest_endpoints'] ) && is_array( $settings['ai_manifest_endpoints'] )
			? array_values(
				array_filter(
					$settings['ai_manifest_endpoints'],
					static fn ( mixed $value ): bool => is_string( $value )
				)
			)
			: array( 'llms.txt', 'feed.json', 'knowledge-graph.json', 'ai-sitemap.xml' );
		$definitions = array(
			'context_markdown'      => array(
				'endpoint' => 'llms',
				'key'      => 'llms.txt',
			),
			'context_markdown_full' => array(
				'endpoint' => 'llms_full',
				'key'      => 'llms-full.txt',
			),
			'context_markdown_tldr' => array(
				'endpoint' => 'llms_tldr',
				'key'      => 'llms-tldr.txt',
			),
			'site_guide'            => array(
				'endpoint' => 'skill',
				'key'      => 'skill.md',
			),
			'usage_policy'          => array(
				'endpoint' => 'usage_policy',
				'key'      => 'ai-usage.json',
			),
			'action_sitemap'        => array(
				'endpoint' => 'actions',
				'key'      => 'ai-actions.json',
			),
			'knowledge_graph'       => array(
				'endpoint' => 'knowledge_graph',
				'key'      => 'knowledge-graph.json',
			),
			'update_stream'         => array(
				'endpoint' => 'feed',
				'key'      => 'feed.json',
			),
			'ai_sitemap'            => array(
				'endpoint' => 'ai_sitemap',
				'key'      => 'ai-sitemap.xml',
			),
		);
		$registry    = \Cybermaps\Core\EndpointRegistry::get_instance();
		$endpoints   = array();
		foreach ( $definitions as $id => $data ) {
			if ( ! in_array( $data['key'], $enabled, true ) || ! $registry->is_enabled( $data['endpoint'], $settings ) ) {
				continue;
			}
			$url = $registry->get_url( $data['endpoint'] );
			if ( '' !== $url ) {
				$endpoints[ $id ] = $url;
			}
		}
		$endpoints['sitemaps'] = $sitemaps;
		$filtered              = apply_filters( 'cybermaps_ai_manifest_endpoints', $endpoints );
		return is_array( $filtered ) ? $filtered : $endpoints;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return string[]
	 */
	private function capabilities( array $settings ): array {
		$capabilities = isset( $settings['ai_capabilities'] ) && is_array( $settings['ai_capabilities'] )
			? array_values(
				array_intersect(
					array_filter(
						$settings['ai_capabilities'],
						static fn ( mixed $value ): bool => is_string( $value )
					),
					array( 'search_content', 'read_articles', 'extract_entities' )
				)
			)
			: array();
		$filtered     = apply_filters( 'cybermaps_ai_manifest_capabilities', $capabilities );
		return is_array( $filtered ) ? $filtered : $capabilities;
	}

	/**
	 * @return string[]
	 */
	private function targeted_agents(): array {
		$manager   = \Cybermaps\Core\ConfigurationStore::robots();
		$overrides = isset( $manager['overrides'] ) && is_array( $manager['overrides'] )
			? $manager['overrides']
			: array();
		$agents    = array();
		foreach ( \Cybermaps\Core\CrawlerRegistry::get_policy_bots() as $id => $bot ) {
			if ( ! \Cybermaps\Core\CrawlerRegistry::supports_manifest_target( $bot ) ) {
				continue;
			}
			$override = isset( $overrides[ $id ] ) && is_array( $overrides[ $id ] )
				? $overrides[ $id ]
				: array();
			$allow    = array_key_exists( 'llm', $override )
				? (bool) $override['llm']
				: (bool) $bot->default['llm'];
			if ( $allow ) {
				$agents[] = $bot->ua;
			}
		}
		return $agents;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function description( array $settings ): string {
		$configured  = $settings['ai_business_description'] ?? '';
		$description = is_scalar( $configured )
			? sanitize_text_field( (string) $configured )
			: '';
		return '' === $description
			? sanitize_text_field( (string) get_bloginfo( 'description' ) )
			: $description;
	}
}
