<?php
/**
 * Public OpenAPI Description.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\ProtocolOutput;
use Cybermaps\Core\RequestInput;

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

		$version = self::negotiate_version(
			RequestInput::query_text( 'version', 16 ),
			RequestInput::header( 'accept' )
		);
		$output  = ProtocolOutput::json( $this->get_document( $version ) );

		Integrity::send_headers( $output, HOUR_IN_SECONDS );
		header( 'Content-Type: ' . self::get_media_type( $version ) );
		header( 'Vary: Accept' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			ProtocolOutput::emit( $output, 'json' );
		}
		exit;
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

		if ( $registry->is_enabled( 'mcp', $settings ) ) {
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
			$document['x-cybermaps-mcp']                        = array(
				'url'            => $registry->get_url( 'mcp' ),
				'provider'       => 'WordPress MCP Adapter',
				'authentication' => 'WordPress Application Password over HTTPS',
				'readOnly'       => true,
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
		$document['paths']['/wp-abilities/v1/abilities/cybermaps/search/run'] = array(
			'get' => $this->operation( 'Search eligible public Cybermaps content.', 'runCybermapsSearch', array() ),
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
	public static function negotiate_version( string $query_version, string $accept ): string {
		$normalized = self::normalize_version( $query_version );
		if ( null !== $normalized ) {
			return $normalized;
		}

		$accept = strtolower( $accept );
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
