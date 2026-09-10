<?php
/**
 * Experimental MCP Server Card discovery document.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements only the current v1 ext-server-card draft surface.
 */
final class MCPServerCard {
	public const MEDIA_TYPE      = 'application/mcp-server-card+json';
	public const SCHEMA_URI      = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';
	public const REST_ROUTE      = '/mcp/server-card';
	public const WELL_KNOWN_PATH = '/.well-known/mcp/server-card.json';

	/**
	 * Serve either the canonical REST child or compatibility alias.
	 */
	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		if ( ! self::matches_path( $path ) || ! self::is_available() ) {
			return;
		}

		$output = \wp_json_encode( $this->get_card_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
	 * Build the draft v1 card without duplicating runtime primitive listings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_card_data(): array {
		return array(
			'$schema'     => self::SCHEMA_URI,
			'name'        => 'dev.cybermaps/wordpress',
			'version'     => CYBERMAPS_VERSION,
			'description' => 'Public discovery and bounded site-content operations for this WordPress site.',
			'title'       => 'Cybermaps',
			'websiteUrl'  => \Cybermaps\Core\URLManager::get_home_url( '/' ),
			'remotes'     => array(
				array(
					'type'                      => 'streamable-http',
					'url'                       => \Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'mcp' ),
					'supportedProtocolVersions' => array( \Cybermaps\MCP\Protocol::VERSION ),
				),
			),
		);
	}

	/**
	 * The card is public only while the MCP service itself is active.
	 */
	public static function is_available(): bool {
		return Integrity::is_hub_enabled()
			&& \Cybermaps\Core\EndpointRegistry::get_instance()->is_enabled( 'mcp' );
	}

	/**
	 * Resolve the registered card or the current-draft canonical MCP child.
	 */
	public static function get_card_url(): string {
		$endpoints  = \Cybermaps\Core\EndpointRegistry::get_instance();
		$registered = $endpoints->get_url( 'rest_mcp_server_card' );
		if ( '' !== $registered ) {
			return $registered;
		}

		$mcp_url = $endpoints->get_url( 'mcp' );
		return '' !== $mcp_url ? \rtrim( $mcp_url, '/' ) . '/server-card' : '';
	}

	/**
	 * Match the canonical child and the locked well-known compatibility path.
	 */
	private static function matches_path( string $request_path ): bool {
		$canonical_path = \wp_parse_url( self::get_card_url(), PHP_URL_PATH );
		$paths          = array( self::WELL_KNOWN_PATH );
		if ( \is_string( $canonical_path ) && '' !== $canonical_path ) {
			$paths[] = $canonical_path;
		}

		return \in_array( $request_path, $paths, true );
	}
}
