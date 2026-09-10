<?php
/**
 * Public endpoint registry.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records canonical, publicly consumable endpoint metadata.
 *
 * It covers the discovery publications and REST integration routes used in
 * machine-readable Cybermaps payloads. It is not a router or an inventory of
 * sitemap, robots, admin, internal callback, or localized URL variants.
 * Extensions register their own WordPress callbacks; fixed public alternate
 * paths may be recorded as aliases.
 */
final class EndpointRegistry {

	public const REST_NAMESPACE            = 'cybermaps/v1';
	public const REGISTRATION_ACTION       = 'cybermaps_register_endpoints';
	public const REGISTRATION_ERROR_ACTION = 'cybermaps_endpoint_registration_error';

	private static ?self $instance = null;

	/**
	 * Registered endpoint definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $endpoints = array();

	/**
	 * Rejected registration attempts.
	 *
	 * @var array<int, array{id: string, message: string}>
	 */
	private array $registration_errors = array();

	private bool $extension_registration_complete = false;

	private function __construct() {
		$this->register_core_endpoints();
	}

	/**
	 * Get the shared endpoint registry.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register endpoint metadata.
	 *
	 * Path endpoints require a `path`; REST endpoints require a literal `route`
	 * and may optionally provide a `namespace`. Parameterized WordPress route
	 * patterns cannot resolve to a canonical URL and are outside this metadata
	 * registry. The `advertise` flag controls inclusion in the discovery index.
	 *
	 * Invalid extension input is rejected without throwing so a companion plugin
	 * cannot prevent Core from loading. Callers can inspect the boolean result or
	 * get_registration_errors(), and Core also fires REGISTRATION_ERROR_ACTION.
	 *
	 * @param mixed $id         Stable endpoint identifier.
	 * @param mixed $definition Endpoint metadata.
	 * @return bool True when the definition was registered.
	 */
	public function register( mixed $id = null, mixed $definition = null ): bool {
		$error = $this->registration_input_error( $id, $definition );
		if ( null !== $error ) {
			return $this->reject( $error['id'], $error['message'], $error['definition'] );
		}
		$id         = (string) $id;
		$definition = (array) $definition;

		$kind = $this->definition_string( $definition, 'kind' );
		if ( ! in_array( $kind, array( 'path', 'rest' ), true ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps endpoint "%s" must define kind "path" or "rest".', $id ), $definition );
		}

		$registered = 'path' === $kind
			? $this->normalize_path_definition( $id, $definition )
			: $this->normalize_rest_definition( $id, $definition );
		if ( ! $registered || ! $this->validate_optional_metadata( $id, $definition ) ) {
			return false;
		}

		$definition['kind']      = $kind;
		$definition['advertise'] = ! empty( $definition['advertise'] );
		$definition['delivery']  = (string) ( $definition['delivery'] ?? ( 'path' === $kind ? 'fixed-path' : 'rest-api' ) );
		$definition['maturity']  = (string) ( $definition['maturity'] ?? 'vendor-extension' );
		$definition['adoption']  = (string) ( $definition['adoption'] ?? 'reference-only' );
		$definition['group']     = (string) ( $definition['group'] ?? 'essential' );
		$this->endpoints[ $id ]  = $definition;

		return true;
	}

	/**
	 * Let extensions declare endpoints before the readiness action fires.
	 */
	public function register_extension_endpoints(): void {
		if ( $this->extension_registration_complete ) {
			return;
		}

		/**
		 * Register endpoints implemented by companion plugins.
		 *
		 * Extensions loaded normally should call EndpointRegistry::register()
		 * inside this action and separately attach their endpoint callbacks to
		 * WordPress. A consumer arriving after this action can retrieve the ready
		 * ExtensionAPI singleton and register directly on this registry.
		 *
		 * @param EndpointRegistry $registry Public endpoint registry.
		 */
		do_action( self::REGISTRATION_ACTION, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant value is prefixed with cybermaps_.

		$this->extension_registration_complete = true;
	}

	/**
	 * Determine whether endpoint metadata is registered.
	 */
	public function has( string $id ): bool {
		return isset( $this->endpoints[ $id ] );
	}

	/**
	 * Get one endpoint definition.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$lookup_id = 'mcp' === $id ? 'rest_mcp' : $id;

		return $this->endpoints[ $lookup_id ] ?? null;
	}

	/**
	 * Determine whether a registered publication is enabled by current settings.
	 */
	public function is_enabled( string $id, ?array $settings = null ): bool {
		$endpoint = $this->get( $id );
		if ( null === $endpoint ) {
			return false;
		}

		$settings    = $settings ?? ConfigurationStore::settings();
		$mcp_enabled = ! empty( $settings['enable_discovery_hub'] )
			&& in_array( (string) ( $settings['mcp_mode'] ?? 'off' ), array( 'discovery', 'read_only', 'operations' ), true );
		if ( in_array( $id, array( 'mcp', 'rest_mcp', 'mcp_server_card', 'rest_mcp_server_card', 'oauth_authorization_server', 'oauth_protected_resource' ), true ) ) {
			return $mcp_enabled;
		}
		if ( 'auth_md' === $id ) {
			return $mcp_enabled && 'user_claimed' === (string) ( $settings['agent_registration_mode'] ?? 'off' );
		}

		$setting = isset( $endpoint['enabled_setting'] ) ? (string) $endpoint['enabled_setting'] : '';
		if ( '' === $setting ) {
			return true;
		}

		return ! empty( $settings[ $setting ] );
	}

	/**
	 * Get all endpoint definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->endpoints;
	}

	/**
	 * Get rejected registration attempts for diagnostics.
	 *
	 * @return array<int, array{id: string, message: string}>
	 */
	public function get_registration_errors(): array {
		return $this->registration_errors;
	}

	/**
	 * Resolve an endpoint to its canonical URL.
	 */
	public function get_url( string $id ): string {
		$endpoint = $this->get( $id );
		if ( null === $endpoint ) {
			return '';
		}

		if ( 'rest' === $endpoint['kind'] ) {
			return rest_url( $endpoint['namespace'] . $endpoint['route'] );
		}

		return URLManager::get_home_url( $endpoint['path'] );
	}

	/**
	 * Get the REST namespace and route for a registered REST endpoint.
	 *
	 * @return array{namespace: string, route: string}|null
	 */
	public function get_rest_route( string $id ): ?array {
		$endpoint = $this->get( $id );
		if ( null === $endpoint || 'rest' !== $endpoint['kind'] ) {
			return null;
		}

		return array(
			'namespace' => (string) $endpoint['namespace'],
			'route'     => (string) $endpoint['route'],
		);
	}

	/**
	 * Match a WordPress REST route such as /cybermaps/v1/discovery.
	 *
	 * @return array{id: string, definition: array<string, mixed>}|null
	 */
	public function match_rest_path( string $rest_path ): ?array {
		$rest_path = '/' . ltrim( $rest_path, '/' );
		foreach ( $this->endpoints as $id => $endpoint ) {
			if ( 'rest' !== ( $endpoint['kind'] ?? '' ) ) {
				continue;
			}

			$registered = '/' . trim( (string) ( $endpoint['namespace'] ?? '' ), '/' )
				. '/' . ltrim( (string) ( $endpoint['route'] ?? '' ), '/' );
			if ( $rest_path === $registered ) {
				return array(
					'id'         => $id,
					'definition' => $endpoint,
				);
			}
		}

		return null;
	}

	/**
	 * Get fixed alternate paths for a registered path endpoint.
	 *
	 * @return string[]
	 */
	public function get_aliases( string $id ): array {
		$endpoint = $this->get( $id );
		if ( null === $endpoint || 'path' !== $endpoint['kind'] ) {
			return array();
		}

		return isset( $endpoint['aliases'] ) && is_array( $endpoint['aliases'] )
			? array_values( $endpoint['aliases'] )
			: array();
	}

	/**
	 * Match an exact public path to its canonical endpoint definition.
	 *
	 * Dynamic routing, analytics, status reporting, and static publication all
	 * consume the same path metadata so aliases cannot silently drift between
	 * subsystems.
	 *
	 * @return array{id: string, definition: array<string, mixed>, canonical: bool}|null
	 */
	public function match_path( string $path ): ?array {
		foreach ( $this->endpoints as $id => $endpoint ) {
			if ( 'path' !== ( $endpoint['kind'] ?? '' ) ) {
				continue;
			}

			if ( (string) ( $endpoint['path'] ?? '' ) === $path ) {
				return array(
					'id'         => $id,
					'definition' => $endpoint,
					'canonical'  => true,
				);
			}

			if ( in_array( $path, (array) ( $endpoint['aliases'] ?? array() ), true ) ) {
				return array(
					'id'         => $id,
					'definition' => $endpoint,
					'canonical'  => false,
				);
			}
		}

		return null;
	}

	/**
	 * Get Core and extension path publications.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_path_publications(): array {
		return array_filter(
			$this->endpoints,
			static fn( array $endpoint ): bool => 'path' === ( $endpoint['kind'] ?? '' )
		);
	}

	/**
	 * Get the physical publication targets for a static mode.
	 *
	 * The historical `well_known` bucket name is retained as a stored setting
	 * compatibility value. Its targets are now the small, extension-bearing
	 * Cybermaps root publications that a server can type reliably. A target in
	 * the `all` bucket is included only in full publication mode.
	 *
	 * @return array<int, array{id: string, path: string, filename: string, bucket: string, type: string, format: string}>
	 */
	public function get_static_targets( string $mode, ?array $settings = null, bool $include_disabled = false ): array {
		if ( ! in_array( $mode, array( 'well_known', 'all' ), true ) ) {
			return array();
		}

		$targets = array();
		foreach ( $this->get_path_publications() as $id => $endpoint ) {
			foreach ( (array) ( $endpoint['static_targets'] ?? array() ) as $target ) {
				$static_target = $this->static_target_metadata( (string) $id, $endpoint, $target, $mode, $settings, $include_disabled );
				if ( null === $static_target ) {
					continue;
				}
				$targets[] = $static_target;
			}
		}

		return $targets;
	}

	/**
	 * Get endpoints intended for the public discovery index.
	 *
	 * @return array<string, array{url: string, type: string, spec: string}>
	 */
	public function get_advertised_endpoints( ?array $settings = null ): array {
		$advertised = array();

		foreach ( $this->endpoints as $id => $endpoint ) {
			$advertisement = $this->advertisement_metadata( (string) $id, $endpoint, $settings );
			if ( null === $advertisement ) {
				continue;
			}

			$advertised[ $id ] = $advertisement;
		}

		return $advertised;
	}

	/**
	 * Register Core's endpoint surface.
	 */
	private function register_core_endpoints(): void {
		// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Stable endpoint IDs vary in length; per-entry metadata remains vertically aligned.
		$path_endpoints = array(
			'manifest'        => array(
				'path'           => '/ai.json',
				'type'           => 'application/json',
				'format'         => 'json',
				'spec'           => 'Cybermaps AI Discovery Manifest 1.0',
				'handler_class'  => \Cybermaps\Discovery\AIManifest::class,
				'maturity'       => 'vendor-extension',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/ai.json',
						'bucket' => 'well_known',
					),
				),
			),
			'adp_discovery'   => array(
				'path'          => '/ai-discovery.json',
				'type'          => 'application/json',
				'format'        => 'json',
				'spec'          => 'AI Discovery Protocol 3.0 Level 3',
				'handler_class' => \Cybermaps\Discovery\ADPDiscovery::class,
				'maturity'      => 'community-convention',
				'adoption'      => 'reference-only',
			),
			'discovery_index' => array(
				'path'          => '/ai-discovery',
				'type'          => 'application/json',
				'format'        => 'json',
				'spec'          => 'Cybermaps Discovery Index',
				'handler_class' => \Cybermaps\Discovery\DiscoveryIndex::class,
				'maturity'      => 'vendor-extension',
				'adoption'      => 'reference-only',
				'advertise'     => false,
				'static_targets' => array(
					array(
						'path'   => '/ai-discovery',
						'bucket' => 'well_known',
					),
				),
			),
			'llms'            => array(
				'path'           => '/llms.txt',
				'type'           => 'text/markdown',
				'format'         => 'text',
				'spec'           => 'llms.txt proposal',
				'handler_class'  => \Cybermaps\Discovery\LLMS::class,
				'maturity'       => 'community-convention',
				'adoption'       => 'independent-producers',
				'static_targets' => array(
					array(
						'path'   => '/llms.txt',
						'bucket' => 'all',
					),
				),
			),
			'llms_full'       => array(
				'path'            => '/llms-full.txt',
				'type'            => 'text/markdown',
				'format'          => 'text',
				'spec'            => 'Cybermaps Literal Full Corpus 1.0',
				'handler_class'   => \Cybermaps\Discovery\LLMS::class,
				'maturity'        => 'vendor-extension',
				'adoption'        => 'reference-only',
				'enabled_setting' => 'enable_llms_full',
				'static_targets'  => array(
					array(
						'path'   => '/llms-full.txt',
						'bucket' => 'all',
					),
				),
			),
			'llms_tldr'       => array(
				'path'            => '/llms-tldr.txt',
				'type'            => 'text/plain',
				'format'          => 'text',
				'spec'            => 'Cybermaps Budgeted Site Briefing 0.2-draft',
				'handler_class'   => \Cybermaps\Discovery\LLMSTLDR::class,
				'maturity'        => 'experimental-proposal',
				'adoption'        => 'reference-only',
				'group'           => 'experimental',
				'enabled_setting' => 'enable_llms_tldr',
				'static_targets'  => array(
					array(
						'path'   => '/llms-tldr.txt',
						'bucket' => 'all',
					),
				),
			),
			'knowledge_graph' => array(
				'path'           => '/knowledge-graph.json',
				'type'           => 'application/ld+json',
				'format'         => 'json',
				'spec'           => 'Schema.org',
				'handler_class'  => \Cybermaps\Discovery\KnowledgeGraph::class,
				'maturity'       => 'vendor-extension',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/knowledge-graph.json',
						'bucket' => 'all',
					),
				),
			),
			'feed'            => array(
				'path'          => '/feed.json',
				'type'          => 'application/feed+json',
				'format'        => 'json',
				'spec'          => 'JSON Feed 1.1',
				'handler_class' => \Cybermaps\Discovery\Feed::class,
				'maturity'      => 'community-convention',
				'adoption'      => 'known-consumer',
			),
			'updates'         => array(
				'path'           => '/updates.json',
				'type'           => 'application/json',
				'format'         => 'json',
				'spec'           => 'AI Discovery Protocol 3.0 Updates',
				'handler_class'  => \Cybermaps\Discovery\Updates::class,
				'maturity'       => 'community-convention',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/updates.json',
						'bucket' => 'all',
					),
				),
			),
			'adp_news_llms'      => array(
				'path'           => '/news/llms.txt',
				'type'           => 'text/markdown',
				'format'         => 'text',
				'spec'           => 'AI Discovery Protocol 3.0 News Namespace',
				'handler_class'  => \Cybermaps\Discovery\ADPNews::class,
				'maturity'       => 'community-convention',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/news/llms.txt',
						'bucket' => 'all',
					),
				),
			),
			'adp_news_speakable' => array(
				'path'           => '/news/speakable.json',
				'type'           => 'application/ld+json',
				'format'         => 'json',
				'spec'           => 'AI Discovery Protocol 3.0 Speakable News',
				'handler_class'  => \Cybermaps\Discovery\ADPNews::class,
				'maturity'       => 'community-convention',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/news/speakable.json',
						'bucket' => 'all',
					),
				),
			),
			'adp_news_changelog' => array(
				'path'           => '/news/changelog.json',
				'type'           => 'application/json',
				'format'         => 'json',
				'spec'           => 'AI Discovery Protocol 3.0 News Changelog',
				'handler_class'  => \Cybermaps\Discovery\ADPNews::class,
				'maturity'       => 'community-convention',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/news/changelog.json',
						'bucket' => 'all',
					),
				),
			),
			'adp_news_archive'   => array(
				'path'           => '/news/archive.jsonl',
				'type'           => 'application/x-ndjson',
				'format'         => 'jsonl',
				'spec'           => 'AI Discovery Protocol 3.0 News Archive',
				'handler_class'  => \Cybermaps\Discovery\ADPNews::class,
				'maturity'       => 'community-convention',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/news/archive.jsonl',
						'bucket' => 'all',
					),
				),
			),
			'ai_sitemap'      => array(
				'path'           => '/ai-sitemap.xml',
				'type'           => 'application/xml',
				'format'         => 'xml',
				'spec'           => 'AI XML Sitemap',
				'handler_class'  => \Cybermaps\Discovery\AISitemap::class,
				'maturity'       => 'vendor-extension',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/ai-sitemap.xml',
						'bucket' => 'all',
					),
				),
			),
			'usage_policy'    => array(
				'path'           => '/ai-usage.json',
				'type'           => 'application/json',
				'format'         => 'json',
				'spec'           => 'Usage Policy 1.0',
				'handler_class'  => \Cybermaps\Discovery\UsagePolicy::class,
				'maturity'       => 'vendor-extension',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/ai-usage.json',
						'bucket' => 'well_known',
					),
				),
			),
			'actions'         => array(
				'path'           => '/ai-actions.json',
				'type'           => 'application/ld+json',
				'format'         => 'json',
				'spec'           => 'Action Sitemap',
				'handler_class'  => \Cybermaps\Discovery\Actions::class,
				'maturity'       => 'vendor-extension',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => '/ai-actions.json',
						'bucket' => 'well_known',
					),
				),
			),
			'skill'           => array(
				'path'           => \Cybermaps\Discovery\Capabilities::CANONICAL_PATH,
				'aliases'        => array( '/skill.md' ),
				'type'           => 'text/markdown',
				'format'         => 'text',
				'spec'           => 'Agent Skills SKILL.md',
				'handler_class'  => \Cybermaps\Discovery\Capabilities::class,
				'maturity'       => 'community-convention',
				'adoption'       => 'known-consumer',
				'static_targets' => array(
					array(
						'path'   => \Cybermaps\Discovery\Capabilities::CANONICAL_PATH,
						'bucket' => 'well_known',
					),
				),
			),
			'agent_skills'    => array(
				'path'           => \Cybermaps\Discovery\AgentSkills::INDEX_PATH,
				'type'           => 'application/json',
				'format'         => 'json',
				'spec'           => 'Agent Skills Discovery 0.2.0 draft',
				'handler_class'  => \Cybermaps\Discovery\AgentSkills::class,
				'maturity'       => 'formal-draft',
				'adoption'       => 'reference-only',
				'static_targets' => array(
					array(
						'path'   => \Cybermaps\Discovery\AgentSkills::INDEX_PATH,
						'bucket' => 'well_known',
					),
				),
			),
			'api_catalog'     => array(
				'path'                 => '/.well-known/api-catalog',
				'aliases'              => array( '/api-catalog' ),
				'type'                 => 'application/linkset+json',
				'format'               => 'json',
				'spec'                 => 'RFC 9727 API Catalog using RFC 9264 Linkset',
				'handler_class'        => \Cybermaps\Discovery\APICatalog::class,
				'maturity'             => 'formal-standard',
				'adoption'             => 'independent-producers',
				'delivery_requirement' => 'runtime_headers_required',
				'static_targets'       => array(
					array(
						'path'   => '/.well-known/api-catalog',
						'bucket' => 'well_known',
					),
				),
			),
			'ai_catalog'      => array(
				'path'                 => \Cybermaps\Discovery\AICatalog::PATH,
				'aliases'              => array( \Cybermaps\Discovery\AICatalog::ALIAS ),
				'type'                 => \Cybermaps\Discovery\AICatalog::MEDIA_TYPE,
				'format'               => 'json',
				'spec'                 => 'Agentic Resource Discovery 1.0 draft',
				'handler_class'        => \Cybermaps\Discovery\AICatalog::class,
				'maturity'             => 'formal-draft',
				'adoption'             => 'reference-only',
				'enabled_setting'      => 'enable_discovery_hub',
				'delivery_requirement' => 'runtime_headers_required',
				'static_targets'       => array(
					array(
						'path'   => \Cybermaps\Discovery\AICatalog::PATH,
						'bucket' => 'well_known',
					),
				),
			),
			'auth_md'         => array(
				'path'          => \Cybermaps\MCP\OAuth\AuthMd::PATH,
				'type'          => 'text/markdown',
				'format'        => 'text',
				'spec'          => 'Auth.md emerging protocol',
				'handler_class' => \Cybermaps\MCP\OAuth\AuthMd::class,
				'maturity'      => 'community-convention',
				'adoption'      => 'reference-only',
			),
			'mcp_server_card' => array(
				'path'                 => \Cybermaps\Discovery\MCPServerCard::WELL_KNOWN_PATH,
				'type'                 => \Cybermaps\Discovery\MCPServerCard::MEDIA_TYPE,
				'format'               => 'json',
				'spec'                 => 'MCP ext-server-card v1 draft',
				'handler_class'        => \Cybermaps\Discovery\MCPServerCard::class,
				'maturity'             => 'experimental-proposal',
				'adoption'             => 'reference-only',
				'group'                => 'experimental',
				'advertise'            => false,
				'delivery_requirement' => 'runtime_headers_required',
				'static_targets'       => array(
					array(
						'path'   => \Cybermaps\Discovery\MCPServerCard::WELL_KNOWN_PATH,
						'bucket' => 'well_known',
					),
				),
			),
			'oauth_authorization_server' => array(
				'path'                 => \Cybermaps\MCP\OAuth\OAuthMetadataPublication::AUTHORIZATION_SERVER_PATH,
				'type'                 => 'application/json',
				'format'               => 'json',
				'spec'                 => 'RFC 8414 OAuth 2.0 Authorization Server Metadata',
				'handler_class'        => \Cybermaps\MCP\OAuth\OAuthMetadataPublication::class,
				'maturity'             => 'formal-standard',
				'adoption'             => 'independent-producers',
				'delivery_requirement' => 'runtime_headers_required',
				'static_targets'       => array(
					array(
						'path'   => \Cybermaps\MCP\OAuth\OAuthMetadataPublication::AUTHORIZATION_SERVER_PATH,
						'bucket' => 'well_known',
					),
				),
			),
			'oauth_protected_resource' => array(
				'path'                 => \Cybermaps\MCP\OAuth\OAuthMetadataPublication::PROTECTED_RESOURCE_PATH,
				'type'                 => 'application/json',
				'format'               => 'json',
				'spec'                 => 'RFC 9728 OAuth 2.0 Protected Resource Metadata',
				'handler_class'        => \Cybermaps\MCP\OAuth\OAuthMetadataPublication::class,
				'maturity'             => 'formal-standard',
				'adoption'             => 'independent-producers',
				'delivery_requirement' => 'runtime_headers_required',
				'static_targets'       => array(
					array(
						'path'   => \Cybermaps\MCP\OAuth\OAuthMetadataPublication::PROTECTED_RESOURCE_PATH,
						'bucket' => 'well_known',
					),
				),
			),
			'openapi'         => array(
				'path'          => '/cybermaps-openapi.json',
				'type'          => 'application/vnd.oai.openapi+json',
				'format'        => 'json',
				'spec'          => 'OpenAPI 3.2.0 (3.1.2 compatibility negotiation)',
				'handler_class' => \Cybermaps\Discovery\OpenAPI::class,
				'maturity'      => 'community-convention',
				'adoption'      => 'known-consumer',
				'advertise'     => false,
			),
		);
		// phpcs:enable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

		// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Stable endpoint IDs vary in length; presentation metadata remains readable by entry.
		$presentation   = array(
			'manifest'        => array( __( 'AI Discovery Manifest', 'cybermaps' ), __( 'Primary machine-readable discovery manifest.', 'cybermaps' ) ),
			'adp_discovery'   => array( __( 'ADP Discovery Manifest', 'cybermaps' ), __( 'AI Discovery Protocol 3.0 Level 3 manifest.', 'cybermaps' ) ),
			'discovery_index' => array( __( 'Discovery Index', 'cybermaps' ), __( 'Index of Cybermaps discovery publications.', 'cybermaps' ) ),
			'llms'            => array( __( 'LLMS Summary', 'cybermaps' ), __( 'Concise site summary for language models.', 'cybermaps' ) ),
			'llms_full'       => array( __( 'LLMS Full', 'cybermaps' ), __( 'Opt-in complete literal publication of eligible stored content.', 'cybermaps' ) ),
			'llms_tldr'       => array( __( 'Budgeted Site Briefing', 'cybermaps' ), __( 'Experimental literal briefing with deterministic budget accounting.', 'cybermaps' ) ),
			'knowledge_graph' => array( __( 'Knowledge Graph', 'cybermaps' ), __( 'Schema.org identity and relationship graph.', 'cybermaps' ) ),
			'feed'            => array( __( 'AI Activity Feed', 'cybermaps' ), __( 'JSON Feed publication of recent content.', 'cybermaps' ) ),
			'updates'         => array( __( 'Recent Updates', 'cybermaps' ), __( 'Bounded seven-day ADP change stream for current public content.', 'cybermaps' ) ),
			'adp_news_llms'      => array( __( 'ADP News Context', 'cybermaps' ), __( 'Bounded Markdown context for recent eligible news content.', 'cybermaps' ) ),
			'adp_news_speakable' => array( __( 'ADP Speakable News', 'cybermaps' ), __( 'Schema.org speakable summaries for recent eligible content.', 'cybermaps' ) ),
			'adp_news_changelog' => array( __( 'ADP News Changelog', 'cybermaps' ), __( 'Version metadata for the Level 3 news publication surface.', 'cybermaps' ) ),
			'adp_news_archive'   => array( __( 'ADP News Archive', 'cybermaps' ), __( 'Bounded newline-delimited archive of eligible content.', 'cybermaps' ) ),
			'ai_sitemap'      => array( __( 'AI Sitemap', 'cybermaps' ), __( 'AI-oriented XML content inventory.', 'cybermaps' ) ),
			'usage_policy'    => array( __( 'AI Usage Policy', 'cybermaps' ), __( 'Machine-readable permissions for AI uses.', 'cybermaps' ) ),
			'actions'         => array( __( 'AI Actions', 'cybermaps' ), __( 'Machine-readable action and capability inventory.', 'cybermaps' ) ),
			'skill'           => array( __( 'Agent Skill Site Guide', 'cybermaps' ), __( 'Agent Skills-compatible guide to the site\'s public read-only resources.', 'cybermaps' ) ),
			'agent_skills'    => array( __( 'Agent Skills Index', 'cybermaps' ), __( 'Draft discovery index for the canonical Cybermaps site-guide skill.', 'cybermaps' ) ),
			'api_catalog'     => array( __( 'API Catalog', 'cybermaps' ), __( 'Dynamic Linkset catalog using the RFC 9727 media-type profile and api-catalog relation.', 'cybermaps' ) ),
			'ai_catalog'      => array( __( 'Agentic Resource Catalog', 'cybermaps' ), __( 'Draft ARD catalog of active Cybermaps discovery resources.', 'cybermaps' ) ),
			'auth_md'         => array( __( 'Agent Registration Guide', 'cybermaps' ), __( 'Opt-in Auth.md instructions for user-claimed OAuth device authorization.', 'cybermaps' ) ),
			'mcp_server_card' => array( __( 'MCP Server Card Compatibility URL', 'cybermaps' ), __( 'Experimental well-known compatibility route for the current MCP Server Card draft.', 'cybermaps' ) ),
			'oauth_authorization_server' => array( __( 'OAuth Authorization Server Metadata', 'cybermaps' ), __( 'RFC 8414 metadata describing how an agent obtains and refreshes access tokens.', 'cybermaps' ) ),
			'oauth_protected_resource' => array( __( 'OAuth Protected Resource Metadata', 'cybermaps' ), __( 'RFC 9728 metadata identifying the MCP resource, issuers, and supported scopes.', 'cybermaps' ) ),
			'openapi'         => array( __( 'OpenAPI Description', 'cybermaps' ), __( 'Read-only public Cybermaps REST API contract.', 'cybermaps' ) ),
		);
		$throttle_tiers = array(
			'llms_tldr'       => 'expensive',
			'knowledge_graph' => 'expensive',
			'ai_sitemap'      => 'expensive',
			'llms'            => 'medium',
			'llms_full'       => 'expensive',
			'feed'            => 'medium',
			'updates'         => 'medium',
			'adp_news_llms'      => 'medium',
			'adp_news_speakable' => 'medium',
			'adp_news_changelog' => 'cheap',
			'adp_news_archive'   => 'expensive',
		);
		// phpcs:enable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

		foreach ( $path_endpoints as $id => $metadata ) {
			$display = $presentation[ $id ] ?? array( $id, '' );
			$this->register(
				$id,
				array_merge(
					array(
						'kind'          => 'path',
						'aliases'       => array(),
						'advertise'     => true,
						'label'         => $display[0],
						'description'   => $display[1],
						'throttle_tier' => $throttle_tiers[ $id ] ?? 'cheap',
					),
					$metadata
				)
			);
		}

		$this->register(
			'rest_root',
			array(
				'kind'          => 'rest',
				'route'         => '/discovery',
				'type'          => 'application/json',
				'spec'          => 'WordPress REST API',
				'label'         => __( 'REST Discovery Index', 'cybermaps' ),
				'description'   => __( 'REST API index of primary Cybermaps publications.', 'cybermaps' ),
				'advertise'     => true,
				'throttle_tier' => 'cheap',
			)
		);
		$this->register(
			'public_health',
			array(
				'kind'            => 'rest',
				'route'           => \Cybermaps\Discovery\PublicHealth::REST_ROUTE,
				'type'            => \Cybermaps\Discovery\PublicHealth::MEDIA_TYPE,
				'spec'            => 'Cybermaps Public Discovery Health 1.0',
				'label'           => __( 'Public Discovery Health', 'cybermaps' ),
				'description'     => __( 'Bounded, non-sensitive health status for public Cybermaps discovery APIs.', 'cybermaps' ),
				'advertise'       => false,
				'throttle_tier'   => 'cheap',
				'enabled_setting' => 'enable_discovery_hub',
			)
		);

		$rest_metadata = array(
			'mcp'             => array(
				'type'          => 'application/json',
				'spec'          => 'Model Context Protocol 2026-07-28',
				'label'         => __( 'Model Context Protocol', 'cybermaps' ),
				'description'   => __( 'Optional dynamic Cybermaps MCP resource and tool endpoint.', 'cybermaps' ),
				'advertise'     => true,
				'throttle_tier' => 'medium',
			),
			'mcp_server_card' => array(
				'route'         => \Cybermaps\Discovery\MCPServerCard::REST_ROUTE,
				'type'          => \Cybermaps\Discovery\MCPServerCard::MEDIA_TYPE,
				'spec'          => 'MCP ext-server-card v1 draft',
				'label'         => __( 'MCP Server Card', 'cybermaps' ),
				'description'   => __( 'Current experimental MCP Server Card for the active Cybermaps MCP endpoint.', 'cybermaps' ),
				'advertise'     => true,
				'throttle_tier' => 'cheap',
				'maturity'      => 'experimental-proposal',
				'adoption'      => 'reference-only',
				'group'         => 'experimental',
			),
			'llms_tldr'       => array(
				'type'          => 'application/json',
				'spec'          => 'Cybermaps Budgeted Site Briefing 0.2-draft',
				'label'         => __( 'Budgeted Site Briefing REST API', 'cybermaps' ),
				'description'   => __( 'REST representation of the experimental budgeted site briefing.', 'cybermaps' ),
				'throttle_tier' => 'expensive',
			),
			'search'          => array(
				'type'          => 'application/json',
				'spec'          => 'WordPress REST API',
				'label'         => __( 'REST Search', 'cybermaps' ),
				'description'   => __( 'Bounded public search over the configured, indexable AI publication inventory.', 'cybermaps' ),
				'throttle_tier' => 'medium',
			),
			'urls'            => array(
				'type'          => 'application/json',
				'spec'          => 'Cybermaps Private Integration API',
				'label'         => __( 'Private Publication URLs', 'cybermaps' ),
				'description'   => __( 'Secret-authenticated discovery and sitemap URL inventory.', 'cybermaps' ),
				'throttle_tier' => 'cheap',
			),
			'status'          => array(
				'type'          => 'application/json',
				'spec'          => 'Cybermaps Private Integration API',
				'label'         => __( 'Private Publication Status', 'cybermaps' ),
				'description'   => __( 'Secret-authenticated publication and static-sync status.', 'cybermaps' ),
				'throttle_tier' => 'cheap',
			),
			'audit_latest'    => array(
				'type'          => 'application/json',
				'spec'          => 'Cybermaps Audit Read API 1.0',
				'label'         => __( 'Latest Content Report', 'cybermaps' ),
				'description'   => __( 'Secret-authenticated latest completed content report with a bounded synchronous JSON snapshot.', 'cybermaps' ),
				'throttle_tier' => 'medium',
			),
			'audit_run'       => array(
				'type'          => 'application/json',
				'spec'          => 'Cybermaps Audit Read API 1.0',
				'label'         => __( 'Content Report Run', 'cybermaps' ),
				'description'   => __( 'Secret-authenticated saved content report by run ID with a bounded synchronous JSON snapshot.', 'cybermaps' ),
				'throttle_tier' => 'medium',
			),
			'purge'           => array(
				'type'          => 'application/json',
				'spec'          => 'Cybermaps Administrative REST API',
				'label'         => __( 'Static Publication Purge', 'cybermaps' ),
				'description'   => __( 'Administrator-only removal of owned static publication files.', 'cybermaps' ),
				'throttle_tier' => 'expensive',
			),
		);

		foreach ( array( 'mcp', 'mcp_server_card', 'llms_tldr', 'search', 'urls', 'status', 'audit_latest', 'audit_run', 'purge' ) as $route ) {
			$metadata = array_merge(
				array(
					'kind'      => 'rest',
					'route'     => '/' . str_replace( '_', '-', $route ),
					'advertise' => false,
				),
				$rest_metadata[ $route ]
			);
			if ( 'llms_tldr' === $route ) {
				$metadata['enabled_setting'] = 'enable_llms_tldr';
				$metadata['maturity']        = 'experimental-proposal';
				$metadata['adoption']        = 'reference-only';
				$metadata['group']           = 'experimental';
			}
			if ( in_array( $route, array( 'audit_latest', 'audit_run' ), true ) ) {
				$metadata['maturity'] = 'vendor-extension';
				$metadata['adoption'] = 'reference-only';
			}
			$this->register(
				'rest_' . $route,
				$metadata
			);
		}
	}

	/**
	 * Reject invalid metadata without allowing an extension to break Core.
	 *
	 * @param string               $id         Endpoint identifier, when usable.
	 * @param string               $message    Human-readable diagnostic.
	 * @param array<string, mixed> $definition Submitted definition, when usable.
	 */
	private function definition_string( array $definition, string $key ): string {
		return isset( $definition[ $key ] ) && is_string( $definition[ $key ] ) ? $definition[ $key ] : '';
	}

	private function normalize_path_definition( string $id, array &$definition ): bool {
		$path = $this->definition_string( $definition, 'path' );
		if ( ! $this->is_valid_path( $path ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%s" must define a valid absolute path.', $id ), $definition );
		}
		$aliases = $definition['aliases'] ?? array();
		if ( ! is_array( $aliases ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%s" aliases must be an array.', $id ), $definition );
		}
		$aliases = $this->normalize_path_aliases( $aliases, $path );
		if ( null === $aliases ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%s" contains an invalid alias.', $id ), $definition );
		}
		$conflict = $this->path_conflict_id( array_merge( array( $path ), $aliases ) );
		if ( '' !== $conflict ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%1$s" conflicts with registered endpoint "%2$s".', $id, $conflict ), $definition );
		}
		return $this->finish_path_definition( $id, $definition, $path, $aliases );
	}

	private function finish_path_definition( string $id, array &$definition, string $path, array $aliases ): bool {
		if ( isset( $definition['handler_class'] ) && ! is_string( $definition['handler_class'] ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%s" contains an invalid handler class.', $id ), $definition );
		}
		if ( isset( $definition['format'] ) && ! $this->is_one_of( $definition['format'], array( 'json', 'jsonl', 'text', 'xml' ) ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%s" contains an invalid body format.', $id ), $definition );
		}
		$targets = $definition['static_targets'] ?? array();
		if ( ! is_array( $targets ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%s" static targets must be an array.', $id ), $definition );
		}
		$targets = $this->normalize_static_targets( $targets, array_merge( array( $path ), $aliases ) );
		if ( null === $targets ) {
			return $this->reject( $id, sprintf( 'Cybermaps path endpoint "%s" contains an invalid static target.', $id ), $definition );
		}
		$definition['path']           = $path;
		$definition['aliases']        = $aliases;
		$definition['static_targets'] = $targets;
		return true;
	}

	private function normalize_path_aliases( array $aliases, string $path ): ?array {
		$normalized = array();
		foreach ( $aliases as $alias ) {
			if ( ! is_string( $alias ) || ! $this->is_valid_path( $alias ) ) {
				return null;
			}
			if ( $path !== $alias ) {
				$normalized[] = $alias;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	private function path_conflict_id( array $claimed_paths ): string {
		foreach ( $this->get_path_publications() as $registered_id => $registered_endpoint ) {
			$registered_paths = array_merge( array( (string) ( $registered_endpoint['path'] ?? '' ) ), (array) ( $registered_endpoint['aliases'] ?? array() ) );
			if ( ! empty( array_intersect( $claimed_paths, $registered_paths ) ) ) {
				return (string) $registered_id;
			}
		}
		return '';
	}

	private function normalize_static_targets( array $targets, array $public_paths ): ?array {
		$normalized = array();
		foreach ( $targets as $target ) {
			$path   = is_array( $target ) ? $this->definition_string( $target, 'path' ) : '';
			$bucket = is_array( $target ) ? $this->definition_string( $target, 'bucket' ) : '';
			if ( ! $this->is_valid_path( $path ) || ! in_array( $path, $public_paths, true ) || ! in_array( $bucket, array( 'well_known', 'all' ), true ) ) {
				return null;
			}
			$normalized[ $path ] = array(
				'path'   => $path,
				'bucket' => $bucket,
			);
		}
		return array_values( $normalized );
	}

	private function normalize_rest_definition( string $id, array &$definition ): bool {
		$route = $this->definition_string( $definition, 'route' );
		if ( ! $this->is_valid_path( $route ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps REST endpoint "%s" must define a valid absolute route.', $id ), $definition );
		}
		$namespace = $definition['namespace'] ?? self::REST_NAMESPACE;
		if ( ! is_string( $namespace ) || ! $this->is_valid_rest_namespace( $namespace ) ) {
			return $this->reject( $id, sprintf( 'Cybermaps REST endpoint "%s" must define a valid namespace such as "vendor/v1".', $id ), $definition );
		}
		$conflict = $this->rest_conflict_id( $namespace, $route );
		if ( '' !== $conflict ) {
			return $this->reject( $id, sprintf( 'Cybermaps REST endpoint "%1$s" conflicts with registered endpoint "%2$s".', $id, $conflict ), $definition );
		}
		$definition['namespace'] = $namespace;
		$definition['route']     = $route;
		return true;
	}

	private function rest_conflict_id( string $rest_namespace, string $route ): string {
		foreach ( $this->endpoints as $registered_id => $registered_endpoint ) {
			if ( 'rest' === ( $registered_endpoint['kind'] ?? '' ) && (string) ( $registered_endpoint['namespace'] ?? '' ) === $rest_namespace && (string) ( $registered_endpoint['route'] ?? '' ) === $route ) {
				return (string) $registered_id;
			}
		}
		return '';
	}

	private function validate_optional_metadata( string $id, array $definition ): bool {
		$rules = array(
			'type'            => array( 'Cybermaps endpoint "%s" contains an invalid media type.', fn( mixed $value ): bool => is_string( $value ) && $this->is_valid_media_type( $value ) ),
			'spec'            => array( 'Cybermaps endpoint "%s" contains an invalid specification label.', 'is_string' ),
			'label'           => array( 'Cybermaps endpoint "%s" contains invalid presentation metadata.', 'is_string' ),
			'description'     => array( 'Cybermaps endpoint "%s" contains invalid presentation metadata.', 'is_string' ),
			'throttle_tier'   => array( 'Cybermaps endpoint "%s" contains an invalid throttle tier.', fn( mixed $value ): bool => $this->is_one_of( $value, array( 'cheap', 'medium', 'expensive' ) ) ),
			'delivery'        => array( 'Cybermaps endpoint "%s" contains an invalid delivery classification.', fn( mixed $value ): bool => $this->is_one_of( $value, array( 'fixed-path', 'rest-api' ) ) ),
			'maturity'        => array( 'Cybermaps endpoint "%s" contains an invalid maturity classification.', fn( mixed $value ): bool => $this->is_one_of( $value, array( 'formal-standard', 'formal-draft', 'community-convention', 'vendor-extension', 'experimental-proposal', 'legacy' ) ) ),
			'adoption'        => array( 'Cybermaps endpoint "%s" contains an invalid adoption classification.', fn( mixed $value ): bool => $this->is_one_of( $value, array( 'reference-only', 'independent-producers', 'known-consumer', 'provider-documented' ) ) ),
			'group'           => array( 'Cybermaps endpoint "%s" contains an invalid presentation group.', fn( mixed $value ): bool => $this->is_one_of( $value, array( 'essential', 'experimental', 'legacy' ) ) ),
			'enabled_setting' => array( 'Cybermaps endpoint "%s" contains an invalid enablement setting.', 'is_string' ),
		);
		foreach ( $rules as $key => $rule ) {
			if ( isset( $definition[ $key ] ) && ! $rule[1]( $definition[ $key ] ) ) {
				return $this->reject( $id, sprintf( $rule[0], $id ), $definition );
			}
		}
		return true;
	}

	private function is_one_of( mixed $value, array $allowed ): bool {
		return in_array( $value, $allowed, true );
	}

	private function registration_input_error( mixed $id, mixed $definition ): ?array {
		if ( ! is_string( $id ) ) {
			return array(
				'id'         => '',
				'message'    => 'Cybermaps endpoint IDs must be strings.',
				'definition' => array(),
			);
		}
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $id ) ) {
			return array(
				'id'         => $id,
				'message'    => 'Cybermaps endpoint IDs may contain lowercase letters, numbers, dots, underscores, and hyphens.',
				'definition' => array(),
			);
		}
		if ( ! is_array( $definition ) ) {
			return array(
				'id'         => $id,
				'message'    => 'Cybermaps endpoint definitions must be arrays.',
				'definition' => array(),
			);
		}
		if ( isset( $this->endpoints[ $id ] ) ) {
			return array(
				'id'         => $id,
				'message'    => sprintf( 'Cybermaps endpoint "%s" is already registered.', $id ),
				'definition' => $definition,
			);
		}
		return null;
	}

	private function static_target_metadata( string $id, array $endpoint, mixed $target, string $mode, ?array $settings, bool $include_disabled ): ?array {
		if ( ! $this->should_include_static_target( $id, $target, $mode, $settings, $include_disabled ) ) {
			return null;
		}
		$bucket = (string) ( $target['bucket'] ?? '' );
		$path   = (string) ( $target['path'] ?? '' );
		if ( ( 'well_known' !== $bucket && ( 'all' !== $mode || 'all' !== $bucket ) ) || '' === $path ) {
			return null;
		}
		return array(
			'id'       => $id,
			'path'     => $path,
			'filename' => ltrim( $path, '/' ),
			'bucket'   => $bucket,
			'type'     => (string) ( $endpoint['type'] ?? 'application/octet-stream' ),
			'format'   => (string) ( $endpoint['format'] ?? 'text' ),
			'enabled'  => $this->is_enabled( $id, $settings ),
		);
	}

	private function should_include_static_target( string $id, mixed $target, string $mode, ?array $settings, bool $include_disabled ): bool {
		if ( ! is_array( $target ) || ( ! $include_disabled && ! $this->is_enabled( $id, $settings ) ) ) {
			return false;
		}
		$bucket = (string) ( $target['bucket'] ?? '' );
		$path   = (string) ( $target['path'] ?? '' );
		return '' !== $path && ( 'well_known' === $bucket || ( 'all' === $mode && 'all' === $bucket ) );
	}

	private function advertisement_metadata( string $id, array $endpoint, ?array $settings ): ?array {
		if ( empty( $endpoint['advertise'] ) || ! $this->is_enabled( $id, $settings ) ) {
			return null;
		}
		$url = $this->get_url( $id );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'url'      => $url,
			'type'     => isset( $endpoint['type'] ) ? (string) $endpoint['type'] : 'application/octet-stream',
			'spec'     => isset( $endpoint['spec'] ) ? (string) $endpoint['spec'] : '',
			'delivery' => (string) ( $endpoint['delivery'] ?? '' ),
			'maturity' => (string) ( $endpoint['maturity'] ?? '' ),
			'adoption' => (string) ( $endpoint['adoption'] ?? '' ),
			'group'    => (string) ( $endpoint['group'] ?? 'essential' ),
		);
	}

	private function reject( string $id, string $message, array $definition = array() ): bool {
		$this->registration_errors[] = array(
			'id'      => $id,
			'message' => $message,
		);

		/**
		 * Fires when endpoint metadata is rejected.
		 *
		 * @param string               $id         Endpoint identifier, or an empty string.
		 * @param string               $message    Human-readable diagnostic.
		 * @param array<string, mixed> $definition Submitted definition, when usable.
		 */
		do_action( self::REGISTRATION_ERROR_ACTION, $id, $message, $definition ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant value is prefixed with cybermaps_.

		return false;
	}

	/**
	 * Validate a canonical path or literal REST route.
	 */
	private function is_valid_path( string $path ): bool {
		if (
			1 !== preg_match( "#^/(?!/)[A-Za-z0-9._~!$&'()*+,;=:@%/-]*$#", $path )
			|| str_contains( $path, '//' )
		) {
			return false;
		}

		foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a WordPress REST namespace without silently normalizing it.
	 */
	private function is_valid_rest_namespace( string $rest_namespace ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)+$/i', $rest_namespace );
	}

	/**
	 * Validate a MIME media type without parameters.
	 */
	private function is_valid_media_type( string $media_type ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/i', $media_type );
	}
}
