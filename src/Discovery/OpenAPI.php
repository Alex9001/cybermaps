<?php
/**
 * Public OpenAPI Description.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes only Cybermaps' public, read-only REST operations.
 */
final class OpenAPI {
	public const VERSION                  = '3.2.0';
	public const COMPATIBILITY_VERSION    = '3.1.2';
	public const MEDIA_TYPE               = 'application/vnd.oai.openapi+json;version=3.2';
	public const COMPATIBILITY_MEDIA_TYPE = 'application/vnd.oai.openapi+json;version=3.1';

	/**
	 * Serve the public OpenAPI document.
	 */
	public function handle(): void {
		if ( '/cybermaps-openapi.json' !== \Cybermaps\Core\URLManager::get_request_path() ) {
			return;
		}

		$version = self::negotiate_version();
		$output  = \wp_json_encode( $this->get_document( $version ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$output  = \is_string( $output ) ? $output : '{}';

		Integrity::send_headers( $output, HOUR_IN_SECONDS );
		header( 'Content-Type: ' . self::get_media_type( $version ) );
		header( 'Vary: Accept' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate protocol JSON response.
			echo $output;
		}
		exit;
	}

	/**
	 * Describe the public RFC 8628 device authorization request.
	 *
	 * @return array<string,mixed>
	 */
	private function device_authorization_operation(): array {
		return array(
			'summary'     => 'Begin a user-claimed OAuth 2.0 Device Authorization Grant.',
			'operationId' => 'beginDeviceAuthorization',
			'security'    => array(),
			'requestBody' => array(
				'required' => true,
				'content'  => array(
					'application/x-www-form-urlencoded' => array(
						'schema' => array(
							'type'                 => 'object',
							'required'             => array( 'client_id' ),
							'additionalProperties' => false,
							'properties'           => array(
								'client_id' => array(
									'type'      => 'string',
									'format'    => 'uri',
									'pattern'   => '^https://',
									'maxLength' => 191,
								),
								'scope'     => array(
									'type'        => 'string',
									'description' => 'Optional space-delimited subset of scopes approved by the Client ID Metadata Document.',
									'maxLength'   => 255,
								),
								'resource'  => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => 'Optional target resource; when supplied it must identify the Cybermaps MCP resource.',
									'maxLength'   => 2048,
								),
							),
						),
					),
				),
			),
			'responses'   => array(
				'200' => array(
					'description' => 'Device authorization request accepted.',
					'headers'     => array(
						'Cache-Control' => array(
							'schema' => array( 'const' => 'no-store' ),
						),
					),
					'content'     => array(
						'application/json' => array(
							'schema' => $this->device_authorization_success_schema(),
						),
					),
				),
				'400' => $this->oauth_error_response(
					'The request, scope, or target resource is invalid.',
					array( 'invalid_request', 'invalid_scope', 'invalid_target' )
				),
				'401' => $this->oauth_error_response(
					'The HTTPS Client ID Metadata Document is invalid or unauthorized.',
					array( 'invalid_client', 'unauthorized_client' )
				),
				'403' => $this->oauth_error_response(
					'User-claimed device authorization is unavailable.',
					array( 'unauthorized_client' )
				),
			),
		);
	}

	/**
	 * Add device authorization only for the settled user-claimed mode.
	 *
	 * @param array<string,mixed> $paths OpenAPI path map.
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function append_device_authorization_path( array &$paths, array $settings ): void {
		if ( ! \Cybermaps\MCP\OAuth\AgentRegistrationMode::is_user_claimed( $settings ) ) {
			return;
		}

		$paths['/cybermaps/v1/oauth/device-authorization'] = array(
			'post' => $this->device_authorization_operation(),
		);
	}

	/**
	 * RFC 8628 device authorization success schema.
	 *
	 * @return array<string,mixed>
	 */
	private function device_authorization_success_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'device_code', 'user_code', 'verification_uri', 'verification_uri_complete', 'expires_in', 'interval' ),
			'additionalProperties' => false,
			'properties'           => array(
				'device_code'               => array( 'type' => 'string' ),
				'user_code'                 => array(
					'type'    => 'string',
					'pattern' => '^[A-Z2-9]{4}-[A-Z2-9]{4}$',
				),
				'verification_uri'          => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'verification_uri_complete' => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'expires_in'                => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'interval'                  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
	}

	/**
	 * OAuth JSON error response implemented by the route controller.
	 *
	 * @param string[] $codes OAuth error codes possible for this status.
	 * @return array<string,mixed>
	 */
	private function oauth_error_response( string $description, array $codes ): array {
		return array(
			'description' => $description,
			'content'     => array(
				'application/json' => array(
					'schema' => array(
						'type'                 => 'object',
						'required'             => array( 'error', 'error_description' ),
						'additionalProperties' => false,
						'properties'           => array(
							'error'             => array(
								'type' => 'string',
								'enum' => $codes,
							),
							'error_description' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Build the contract from the endpoint registry so disabled experimental
	 * routes and private integration routes cannot leak into public discovery.
	 *
	 * @return array<string, mixed>
	 */
	public function get_document( ?string $version = null ): array {
		$version  = self::normalize_version( $version ) ?? self::VERSION;
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$paths    = array(
			'/cybermaps/v1/discovery' => array(
				'get' => $this->operation(
					'Discover the site’s public Cybermaps publications.',
					'discoverPublications',
					array()
				),
			),
			'/cybermaps/v1/search'    => array(
				'get' => $this->operation(
					'Search the bounded, public Cybermaps publication inventory.',
					'searchPublications',
					array(
						array(
							'name'        => 'q',
							'in'          => 'query',
							'required'    => true,
							'description' => 'Search query string.',
							'schema'      => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => PublicationConstraints::SEARCH_QUERY_MAX_LENGTH,
							),
						),
						array(
							'name'        => 'limit',
							'in'          => 'query',
							'required'    => false,
							'description' => 'Maximum number of results to return.',
							'schema'      => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 100,
								'default' => 20,
							),
						),
					)
				),
			),
		);

		$paths['/cybermaps/v1/health'] = array(
			'get' => array(
				'summary'     => 'Return bounded public readiness metadata for Cybermaps discovery.',
				'operationId' => 'getPublicDiscoveryHealth',
				'security'    => array(),
				'responses'   => array(
					'200' => array(
						'description' => 'The public discovery service is ready.',
						'content'     => array(
							PublicHealth::MEDIA_TYPE => array(
								'schema' => array(
									'type'                 => 'object',
									'required'             => array( 'status', 'serviceId', 'version' ),
									'additionalProperties' => false,
									'properties'           => array(
										'status'    => array( 'const' => 'pass' ),
										'serviceId' => array( 'const' => 'cybermaps-discovery' ),
										'version'   => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
			),
		);

		if ( $registry->is_enabled( 'rest_llms_tldr', $settings ) ) {
			$paths['/cybermaps/v1/llms-tldr'] = array(
				'get' => $this->operation(
					'Return the enabled experimental Cybermaps budgeted site briefing.',
					'getBudgetedBriefing',
					array()
				),
			);
		}

		$rest_base   = \rest_url();
		$rest_base   = \is_string( $rest_base ) ? \rtrim( $rest_base, '/' ) : '';
		$openapi_url = $registry->get_url( 'openapi' );
		$self        = '' !== $openapi_url ? $openapi_url : '/cybermaps-openapi.json';

		$document = array(
			'openapi'           => $version,
			'$self'             => $self,
			'jsonSchemaDialect' => 'https://spec.openapis.org/oas/3.2/dialect/base',
			'info'              => array(
				'title'       => 'Cybermaps Public Discovery API',
				'version'     => CYBERMAPS_VERSION,
				'description' => 'Read-only public discovery operations exposed by Cybermaps. Administrative and secret-authenticated routes are intentionally excluded.',
			),
			'servers'           => '' !== $rest_base ? array( array( 'url' => $rest_base ) ) : array(),
			'paths'             => $paths,
			'components'        => array(
				'schemas' => array(
					'Problem' => array(
						'type'                 => 'object',
						'description'          => 'RFC 9457-style problem response.',
						'additionalProperties' => true,
					),
				),
			),
		);

		$document['components']['schemas']['McpJsonRpcRequest']  = array(
			'type'                 => 'object',
			'required'             => array( 'jsonrpc', 'id', 'method' ),
			'additionalProperties' => false,
			'properties'           => array(
				'jsonrpc' => array( 'const' => '2.0' ),
				'id'      => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ) ) ),
				'method'  => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'params'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
		);
		$document['components']['schemas']['McpJsonRpcResponse'] = array(
			'type'                 => 'object',
			'required'             => array( 'jsonrpc', 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'jsonrpc' => array( 'const' => '2.0' ),
				'id'      => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'integer' ), array( 'type' => 'null' ) ) ),
				'result'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'error'   => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
		);
		$document['components']['securitySchemes']               = array(
			'oauth2'     => array(
				'type'  => 'oauth2',
				'flows' => array(
					'authorizationCode' => array(
						'authorizationUrl' => $rest_base . '/cybermaps/v1/oauth/authorize',
						'tokenUrl'         => $rest_base . '/cybermaps/v1/oauth/token',
						'scopes'           => array(
							'cybermaps:read'              => 'Read public and authorized Cybermaps resources.',
							'cybermaps:audit'             => 'Run bounded Cybermaps audits.',
							'cybermaps:publish'           => 'Reconcile static Cybermaps publications.',
							'cybermaps:purge'             => 'Purge owned Cybermaps publications.',
							'cybermaps:abilities:execute' => 'Execute public WordPress abilities that perform updates.',
						),
					),
				),
			),
			'bearerAuth' => array(
				'type'   => 'http',
				'scheme' => 'bearer',
			),
		);

		if ( $registry->is_enabled( 'mcp', $settings ) ) {
			$this->append_device_authorization_path( $document['paths'], $settings );
			$document['paths']['/cybermaps/v1/mcp/server-card'] = array(
				'get' => array(
					'summary'     => 'Return the public MCP Server Card for the active Cybermaps service.',
					'operationId' => 'getMcpServerCard',
					'security'    => array(),
					'responses'   => array(
						'200' => array(
							'description' => 'Current-draft MCP Server Card.',
							'content'     => array(
								MCPServerCard::MEDIA_TYPE => array(
									'schema' => array( '$ref' => MCPServerCard::SCHEMA_URI ),
								),
							),
						),
					),
				),
			);
			$mcp_mode                               = (string) ( $settings['mcp_mode'] ?? 'discovery' );
			$document['paths']['/cybermaps/v1/mcp'] = array(
				'post' => array(
					'summary'     => 'Process one stateless MCP JSON-RPC request.',
					'operationId' => 'mcpRequest',
					'security'    => 'discovery' === $mcp_mode ? array() : array( array( 'oauth2' => array( 'cybermaps:read' ) ) ),
					'parameters'  => array(
						array(
							'name'     => 'MCP-Protocol-Version',
							'in'       => 'header',
							'required' => true,
							'schema'   => array( 'const' => '2026-07-28' ),
						),
						array(
							'name'     => 'Mcp-Method',
							'in'       => 'header',
							'required' => true,
							'schema'   => array(
								'type'      => 'string',
								'minLength' => 1,
							),
						),
						array(
							'name'     => 'Mcp-Name',
							'in'       => 'header',
							'required' => true,
							'schema'   => array(
								'type'      => 'string',
								'minLength' => 1,
							),
						),
					),
					'requestBody' => array(
						'required' => true,
						'content'  => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/McpJsonRpcRequest' ) ) ),
					),
					'responses'   => array(
						'200' => array(
							'description' => 'MCP JSON-RPC response.',
							'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/McpJsonRpcResponse' ) ) ),
						),
						'405' => array( 'description' => 'Only POST is supported.' ),
					),
				),
			);
		}

		$this->append_abilities_paths( $document );

		if ( self::COMPATIBILITY_VERSION === $version ) {
			return $this->to_compatibility_document( $document, $self );
		}

		return $document;
	}

	/** @param array<string,mixed> $document OpenAPI document under construction. */
	private function append_abilities_paths( array &$document ): void {
		if ( ! \Cybermaps\Core\AbilityKernel::get_instance()->has_public_abilities() ) {
			return;
		}
		$document['paths']['/wp-abilities/v1/abilities'] = array(
			'get' => $this->operation( 'List public WordPress abilities and their client-safe schemas.', 'listPublicWordPressAbilities', array() ),
		);
		$ability_path                                    = '/wp-abilities/v1/abilities/{namespace}/{ability}/run';
		$parameters                                      = array(
			array(
				'name'     => 'namespace',
				'in'       => 'path',
				'required' => true,
				'schema'   => array( 'type' => 'string' ),
			),
			array(
				'name'     => 'ability',
				'in'       => 'path',
				'required' => true,
				'schema'   => array( 'type' => 'string' ),
			),
		);
		$document['paths'][ $ability_path ]              = array(
			'get'    => $this->operation( 'Run a read-only public WordPress ability.', 'runReadOnlyWordPressAbility', $parameters ),
			'post'   => $this->operation( 'Run an updating public WordPress ability.', 'runUpdatingWordPressAbility', $parameters ),
			'delete' => $this->operation( 'Run an idempotent destructive public WordPress ability.', 'runDestructiveWordPressAbility', $parameters ),
		);
	}

	/**
	 * Project the canonical document to the OpenAPI 3.1.2 contract.
	 *
	 * OpenAPI 3.2 adds `$self`; it must not be emitted as an unknown top-level
	 * field in the compatibility representation. The 3.1 dialect is also
	 * explicit so schema tooling does not accidentally validate against 3.2.
	 *
	 * @param array<string, mixed> $document Canonical document.
	 * @param string               $canonical_url Canonical document URL.
	 * @return array<string, mixed>
	 */
	private function to_compatibility_document( array $document, string $canonical_url ): array {
		unset( $document['$self'] );
		$document['openapi']               = self::COMPATIBILITY_VERSION;
		$document['jsonSchemaDialect']     = 'https://spec.openapis.org/oas/3.1/dialect/base';
		$document['x-cybermaps-canonical'] = $canonical_url;

		return $document;
	}

	/**
	 * Return the negotiated public representation version for the current request.
	 */
	public static function negotiate_version(): string {
		$query_version = isset( $_GET['version'] ) && is_scalar( $_GET['version'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public version negotiation query, not a state-changing form.
			? sanitize_text_field( wp_unslash( (string) $_GET['version'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public version negotiation query, not a state-changing form.
			: '';
		$normalized    = self::normalize_version( $query_version );
		if ( null !== $normalized ) {
			return $normalized;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) && is_scalar( $_SERVER['HTTP_ACCEPT'] )
			? strtolower( (string) $_SERVER['HTTP_ACCEPT'] )
			: '';
		if ( preg_match( '/application\/vnd\.oai\.openapi\+json\s*;\s*version\s*=\s*3\.1(?:\.2)?/', $accept ) ) {
			return self::COMPATIBILITY_VERSION;
		}

		return self::VERSION;
	}

	/**
	 * @return string|null Supported OpenAPI version or null for an invalid value.
	 */
	private static function normalize_version( ?string $version ): ?string {
		if ( null === $version || '' === trim( $version ) ) {
			return null;
		}
		$version = trim( $version );
		if ( self::VERSION === $version || '3.2' === $version ) {
			return self::VERSION;
		}
		if ( self::COMPATIBILITY_VERSION === $version || '3.1' === $version ) {
			return self::COMPATIBILITY_VERSION;
		}
		return null;
	}

	public static function get_media_type( ?string $version = null ): string {
		return self::COMPATIBILITY_VERSION === self::normalize_version( $version )
			? self::COMPATIBILITY_MEDIA_TYPE
			: self::MEDIA_TYPE;
	}

	/**
	 * @param array<int, array<string, mixed>> $parameters Operation parameters.
	 * @return array<string, mixed>
	 */
	private function operation( string $summary, string $operation_id, array $parameters ): array {
		return array(
			'summary'     => $summary,
			'operationId' => $operation_id,
			'parameters'  => $parameters,
			'responses'   => array(
				'200' => array(
					'description' => 'Successful JSON response.',
					'content'     => array(
						'application/json' => array(
							'schema' => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
					),
				),
				'404' => array( 'description' => 'Discovery Hub or optional operation is disabled.' ),
				'429' => array( 'description' => 'Search rate limit exceeded.' ),
			),
		);
	}
}
