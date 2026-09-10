<?php
/**
 * Agentic Resource Discovery capability catalog.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes active Cybermaps capabilities using the ARD 1.0 catalog shape.
 */
final class AICatalog {
	public const PATH         = '/.well-known/ai-catalog.json';
	public const ALIAS        = '/ai-catalog.json';
	public const MEDIA_TYPE   = 'application/json';
	public const SPEC_VERSION = '1.0';

	/**
	 * Serve the canonical catalog or compatibility alias.
	 */
	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		if ( ! self::matches_path( $path ) || ! Integrity::is_hub_enabled() ) {
			return;
		}

		$output = \wp_json_encode( $this->get_catalog_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$output = \is_string( $output ) ? $output : '{}';

		Integrity::send_headers( $output, HOUR_IN_SECONDS );
		\header( 'Content-Type: ' . self::MEDIA_TYPE );
		\header( 'Access-Control-Allow-Origin: *' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate protocol JSON response.
			echo $output;
		}
		exit;
	}

	/**
	 * Build a catalog containing only active, resolvable capabilities.
	 *
	 * @return array<string,mixed>
	 */
	public function get_catalog_data(): array {
		$entries = array();
		if ( Integrity::is_hub_enabled() ) {
			$this->append_entry( $entries, $this->api_entry() );
			$this->append_entry( $entries, $this->mcp_entry() );
			$this->append_entry( $entries, $this->abilities_entry() );
		}

		return array(
			'specVersion' => self::SPEC_VERSION,
			'host'        => $this->host_data(),
			'entries'     => $entries,
		);
	}

	/**
	 * Describe the always-public REST discovery API when its contract is active.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_entry(): ?array {
		$endpoints = \Cybermaps\Core\EndpointRegistry::get_instance();
		$url       = $endpoints->get_url( 'openapi' );
		if ( ! $endpoints->is_enabled( 'rest_root' ) || '' === $url ) {
			return null;
		}

		return array(
			'identifier'            => $this->identifier( 'api', 'cybermaps-discovery' ),
			'displayName'           => 'Cybermaps Public Discovery API',
			'type'                  => OpenAPI::MEDIA_TYPE,
			'url'                   => $url,
			'description'           => 'Read-only discovery and bounded search over public site content.',
			'capabilities'          => array( 'public-discovery', 'bounded-site-search' ),
			'representativeQueries' => array(
				'Discover the public AI resources on this site.',
				'Search this site for public content relevant to a question.',
			),
			'version'               => CYBERMAPS_VERSION,
		);
	}

	/**
	 * Describe MCP only while its configured service is active.
	 *
	 * @return array<string,mixed>|null
	 */
	private function mcp_entry(): ?array {
		$url = MCPServerCard::get_card_url();
		if ( ! MCPServerCard::is_available() || '' === $url ) {
			return null;
		}

		return array(
			'identifier'            => $this->identifier( 'mcp', 'cybermaps' ),
			'displayName'           => 'Cybermaps MCP Server',
			'type'                  => MCPServerCard::MEDIA_TYPE,
			'url'                   => $url,
			'description'           => 'Connection metadata for the active Cybermaps MCP service.',
			'capabilities'          => array( 'server-discovery' ),
			'representativeQueries' => array(
				'Discover how to connect to this site\'s MCP server.',
				'Find the public MCP service for this site.',
			),
			'version'               => CYBERMAPS_VERSION,
		);
	}

	/** Describe the WordPress 7.1 public ability catalog when populated. */
	private function abilities_entry(): ?array {
		if ( ! \Cybermaps\Core\AbilityKernel::get_instance()->has_public_abilities() ) {
			return null;
		}

		return array(
			'identifier'            => $this->identifier( 'abilities', 'wordpress-public' ),
			'displayName'           => 'WordPress Public Abilities',
			'type'                  => 'application/json',
			'url'                   => rest_url( 'wp-abilities/v1/abilities' ),
			'description'           => 'Public WordPress 7.1 abilities available to authorized clients.',
			'capabilities'          => array( 'ability-discovery', 'schema-described-execution' ),
			'representativeQueries' => array(
				'Discover the public abilities exposed by this WordPress site.',
				'Find schema-described actions this site allows an agent to invoke.',
			),
			'version'               => CYBERMAPS_VERSION,
		);
	}

	/**
	 * Append only complete entries containing exactly one artifact locator.
	 *
	 * @param array<int,array<string,mixed>> $entries Catalog entries.
	 * @param array<string,mixed>|null       $entry Candidate entry.
	 */
	private function append_entry( array &$entries, ?array $entry ): void {
		if ( null !== $entry ) {
			$entries[] = $entry;
		}
	}

	/**
	 * Public host metadata without unsupported identity or trust claims.
	 *
	 * @return array{displayName:string,documentationUrl:string}
	 */
	private function host_data(): array {
		$name = \sanitize_text_field( (string) \get_bloginfo( 'name' ) );

		return array(
			'displayName'      => '' !== $name ? $name : 'Cybermaps site',
			'documentationUrl' => \Cybermaps\Core\URLManager::get_home_url( '/' ),
		);
	}

	/**
	 * Build a domain-anchored ARD identifier.
	 */
	private function identifier( string $resource_namespace, string $name ): string {
		return 'urn:air:' . $this->publisher_domain() . ':' . $resource_namespace . ':' . $name;
	}

	/**
	 * Normalize the public host to the ARD identifier grammar.
	 */
	private function publisher_domain(): string {
		$host = \wp_parse_url( \Cybermaps\Core\URLManager::get_home_url( '/' ), PHP_URL_HOST );
		$host = \is_string( $host ) ? \strtolower( $host ) : '';
		$host = (string) \preg_replace( '/[^a-z0-9.-]/', '', $host );

		return '' !== $host ? $host : 'localhost';
	}

	/**
	 * Match the standard well-known path and locked compatibility alias.
	 */
	private static function matches_path( string $path ): bool {
		return \in_array( $path, array( self::PATH, self::ALIAS ), true );
	}
}
