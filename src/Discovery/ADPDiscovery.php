<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes the AI Discovery Protocol 3.0 Level 3 manifest.
 */
final class ADPDiscovery {
	private const CACHE_KEY        = 'cybermaps_adp_discovery_v3';
	private const UPDATE_FREQUENCY = 'daily';

	/**
	 * Serve /ai-discovery.json.
	 */
	public function handle(): void {
		if ( '/ai-discovery.json' !== \Cybermaps\Core\URLManager::get_request_path() ) {
			return;
		}

		$output = $this->get_json_content();
		Integrity::send_headers( $output );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Update-Frequency: ' . self::UPDATE_FREQUENCY );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate JSON response.
		}
		exit;
	}

	/**
	 * Return the canonical JSON body used by dynamic and static delivery.
	 */
	public function get_json_content(): string {
		$output = \wp_json_encode( $this->get_manifest_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! \is_string( $output ) ) {
			return '{}';
		}

		return $output;
	}

	/**
	 * Build or retrieve the stable Level 3 manifest.
	 *
	 * @return array<string,mixed>
	 */
	public function get_manifest_data(): array {
		$cached = \get_transient( self::CACHE_KEY );
		if ( \is_array( $cached ) ) {
			return $cached;
		}
		if ( false !== $cached ) {
			\delete_transient( self::CACHE_KEY );
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$manifest = array(
			'version'      => '3.0',
			'generatedAt'  => \gmdate( 'c' ),
			'website'      => $this->website( $settings ),
			'endpoints'    => $this->endpoints( $settings ),
			'capabilities' => array(
				'supportsVersioning'         => false,
				'supportsIncrementalUpdates' => true,
				'supportsChangeDetection'    => true,
				'updateFrequency'            => self::UPDATE_FREQUENCY,
			),
		);

		$email = $this->contact_email();
		if ( '' !== $email ) {
			$manifest['contact'] = array( 'email' => $email );
		}

		/**
		 * Filter the final AI Discovery Protocol 3.0 manifest.
		 *
		 * @param array<string,mixed> $manifest Level 3 manifest.
		 * @param array<string,mixed> $settings Current Cybermaps settings.
		 */
		$filtered = \apply_filters( 'cybermaps_adp_manifest', $manifest, $settings );
		if ( \is_array( $filtered ) ) {
			$manifest = $filtered;
		}

		\Cybermaps\Core\CacheManager::set( self::CACHE_KEY, $manifest, DAY_IN_SECONDS, 'discovery' );
		return $manifest;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<string,mixed>
	 */
	private function website( array $settings ): array {
		$description = isset( $settings['ai_business_description'] ) && \is_scalar( $settings['ai_business_description'] )
			? \sanitize_text_field( (string) $settings['ai_business_description'] )
			: '';
		if ( '' === $description ) {
			$description = \sanitize_text_field( (string) \get_bloginfo( 'description' ) );
		}
		$language = isset( $settings['site_language'] ) && \is_scalar( $settings['site_language'] )
			? (string) $settings['site_language']
			: '';
		$website  = array(
			'name'            => PublicationConstraints::bounded_text(
				(string) \get_bloginfo( 'name' ),
				PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH
			),
			'url'             => \Cybermaps\Core\URLManager::get_home_url( '/' ),
			'description'     => PublicationConstraints::bounded_text(
				$description,
				PublicationConstraints::MISSION_MAX_LENGTH
			),
			'primaryLanguage' => PublicationConstraints::bounded_text( $this->language( $language ), 64 ),
		);
		$topics   = PublicationConstraints::topics( $settings['ai_topics'] ?? '' );
		if ( isset( $topics[0] ) && \is_string( $topics[0] ) && '' !== $topics[0] ) {
			$website['category'] = $topics[0];
		}
		return $website;
	}

	private function language( string $language ): string {
		$language = \Cybermaps\Core\TranslationHelper::normalize_hreflang( $language );
		if ( '' === $language ) {
			$language = \Cybermaps\Core\TranslationHelper::normalize_hreflang(
				(string) \get_bloginfo( 'language' )
			);
		}
		return '' === $language ? 'en' : $language;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<string,string>
	 */
	private function endpoints( array $settings ): array {
		$registry  = \Cybermaps\Core\EndpointRegistry::get_instance();
		$endpoints = array(
			'knowledgeGraph'    => $registry->get_url( 'knowledge_graph' ),
			'contextDocument'   => $registry->get_url( 'llms' ),
			'crawlerDirectives' => \Cybermaps\Core\URLManager::get_home_url( '/robots.txt' ),
			'contentFeed'       => $registry->get_url( 'feed' ),
			'recentUpdates'     => $registry->get_url( 'updates' ),
			'aiSitemap'         => $registry->get_url( 'ai_sitemap' ),
			'newsNamespace'     => \Cybermaps\Core\URLManager::get_home_url( '/news/' ),
			'newsContext'       => $registry->get_url( 'adp_news_llms' ),
			'speakableNews'     => $registry->get_url( 'adp_news_speakable' ),
			'newsChangelog'     => $registry->get_url( 'adp_news_changelog' ),
			'newsArchive'       => $registry->get_url( 'adp_news_archive' ),
		);
		if ( $registry->is_enabled( 'llms_full', $settings ) ) {
			$endpoints['contextDocumentFull'] = $registry->get_url( 'llms_full' );
		}
		return \array_filter(
			$endpoints,
			static fn ( mixed $url ): bool => \is_string( $url ) && '' !== $url
		);
	}

	private function contact_email(): string {
		$identity = \Cybermaps\Core\ConfigurationStore::identity();
		return isset( $identity['email'] ) && \is_scalar( $identity['email'] )
			? \sanitize_email( (string) $identity['email'] )
			: '';
	}
}
