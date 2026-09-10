<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes fixed discovery publications by their EndpointRegistry ID.
 *
 * Dynamic handlers remain the source of each payload. The static bridge calls
 * this service to copy those same bodies to disk instead of maintaining a
 * second set of generators.
 */
class DiscoveryPublicationGenerator {

	private AIContentSelector $selector;

	/**
	 * Request-local canonical body cache.
	 *
	 * @var array<string, string>
	 */
	private array $cache = array();

	public function __construct( ?AIContentSelector $selector = null ) {
		$this->selector = $selector ?? new AIContentSelector();
	}

	/**
	 * Generate a publication body.
	 *
	 * Extensions can provide a string body for their registered endpoint through
	 * cybermaps_static_publication_content. Returning null for an unknown ID
	 * makes the sync report an explicit failure instead of silently claiming the
	 * publication succeeded.
	 *
	 * @param string               $endpoint_id Registry endpoint ID.
	 * @param array<string, mixed> $target Registry static-target metadata.
	 * @param array<string, mixed> $settings Current settings snapshot.
	 */
	public function generate( string $endpoint_id, array $target, array $settings ): string {
		if ( ! \Cybermaps\Core\EndpointRegistry::get_instance()->is_enabled( $endpoint_id, $settings ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: discovery publication identifier. */
					__( 'The discovery publication "%s" is disabled.', 'cybermaps' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static-sync exceptions become status data; the admin view escapes at its output boundary.
					$endpoint_id // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry IDs are diagnostic data, not direct HTML output.
				)
			);
		}
		if ( isset( $this->cache[ $endpoint_id ] ) ) {
			$content = $this->cache[ $endpoint_id ];
		} else {
			$content = $this->generate_core_body( $endpoint_id );
			// A bounded llms-full body can still be several MiB. Keeping it in
			// this request cache after StaticBridge writes it needlessly retains
			// the largest publication through the rest of a full reconciliation.
			if ( null !== $content && 'llms_full' !== $endpoint_id ) {
				$this->cache[ $endpoint_id ] = $content;
			}
		}

		/**
		 * Supply or alter a static body for a registered publication.
		 *
		 * @param string|null         $content     Core body, or null for extension IDs.
		 * @param string              $endpoint_id Stable registry ID.
		 * @param array<string,mixed> $target      Target path and media metadata.
		 * @param array<string,mixed> $settings    Current Core settings.
		 */
		$content = \apply_filters(
			'cybermaps_static_publication_content',
			$content,
			$endpoint_id,
			$target,
			$settings
		);

		if ( ! \is_string( $content ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: discovery publication identifier. */
					__( 'No static publication generator is registered for endpoint "%s".', 'cybermaps' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static-sync exceptions become status data; the admin view escapes at its output boundary.
					$endpoint_id // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry IDs are diagnostic data, not direct HTML output.
				)
			);
		}

		return $content;
	}

	/**
	 * Generate one canonical Core body by endpoint ID.
	 */
	private function generate_core_body( string $endpoint_id ): ?string {
		$content = $this->generate_primary_body( $endpoint_id );
		if ( null !== $content ) {
			return $content;
		}
		$content = $this->generate_stream_body( $endpoint_id );
		if ( null !== $content ) {
			return $content;
		}
		$content = $this->generate_auxiliary_body( $endpoint_id );
		return null !== $content ? $content : $this->generate_protocol_body( $endpoint_id );
	}

	private function generate_primary_body( string $endpoint_id ): ?string {
		switch ( $endpoint_id ) {
			case 'manifest':
				return $this->encode_json( ( new AIManifest() )->get_manifest_data() );

			case 'discovery_index':
				return $this->encode_json( ( new DiscoveryIndex() )->get_index() );

			case 'adp_discovery':
				return ( new ADPDiscovery() )->get_json_content();

			case 'llms':
				return ( new LLMS() )->get_llms_content( false );

			case 'llms_full':
				return ( new LLMS() )->get_llms_content( true );

			case 'llms_tldr':
				return ( new LLMSTLDR() )->get_content( true );

			case 'knowledge_graph':
				return ( new KnowledgeGraph() )->get_json_content();

			case 'feed':
				return ( new Feed() )->get_json_content();
		}
		return null;
	}

	private function generate_stream_body( string $endpoint_id ): ?string {
		switch ( $endpoint_id ) {
			case 'updates':
				return ( new Updates() )->get_json_content();

			case 'adp_news_llms':
			case 'adp_news_speakable':
			case 'adp_news_changelog':
			case 'adp_news_archive':
				return ( new ADPNews() )->get_content( $endpoint_id );
		}
		return null;
	}

	private function generate_auxiliary_body( string $endpoint_id ): ?string {
		switch ( $endpoint_id ) {
			case 'ai_sitemap':
				return ( new AISitemap( $this->selector ) )->get_content();

			case 'usage_policy':
				return $this->encode_json( ( new UsagePolicy() )->get_policy_data() );

			case 'actions':
				return $this->encode_json( ( new Actions() )->get_action_data() );

			case 'skill':
				return ( new Capabilities() )->get_skill_markdown();

			case 'agent_skills':
				return ( new AgentSkills() )->get_json_content();

		}
		return null;
	}

	private function generate_protocol_body( string $endpoint_id ): ?string {
		switch ( $endpoint_id ) {
			case 'api_catalog':
				return $this->encode_json( ( new APICatalog() )->get_catalog_data() );

			case 'ai_catalog':
				return $this->encode_json( ( new AICatalog() )->get_catalog_data() );

			case 'mcp_server_card':
				return $this->encode_json( ( new MCPServerCard() )->get_card_data() );

			case 'oauth_authorization_server':
				return $this->encode_json( ( new \Cybermaps\MCP\OAuth\OAuthMetadataPublication() )->authorization_server_metadata() );

			case 'oauth_protected_resource':
				return $this->encode_json( ( new \Cybermaps\MCP\OAuth\OAuthMetadataPublication() )->protected_resource_metadata() );
		}
		return null;
	}

	/**
	 * Encode JSON without allowing a false return to become an empty publication.
	 */
	private function encode_json( mixed $data ): string {
		$encoded = \wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! \is_string( $encoded ) ) {
			throw new \RuntimeException(
				__( 'A discovery publication could not be encoded as JSON.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception-only diagnostic; JSON or escaped admin consumers own the eventual output boundary.
			);
		}

		return $encoded;
	}
}
