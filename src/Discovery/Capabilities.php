<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\EndpointRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes an Agent Skills-compatible read-only guide for the current site.
 */
final class Capabilities {
	public const SKILL_NAME        = 'cybermaps-site-guide';
	public const CANONICAL_PATH    = '/.well-known/agent-skills/cybermaps-site-guide/SKILL.md';
	public const SKILL_DESCRIPTION = 'Discover and retrieve the public content and machine-readable resources published by this WordPress site. Use when an agent needs the site\'s canonical content map, search endpoint, knowledge graph, usage policy, or sitemap.';

	public function handle(): void {
		if ( ! \in_array( \Cybermaps\Core\URLManager::get_request_path(), array( '/skill.md', self::CANONICAL_PATH ), true ) ) {
			return;
		}
		if ( ! Integrity::is_hub_enabled() ) {
			return;
		}

		$output = $this->get_skill_markdown();
		Integrity::send_headers( $output );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate Markdown response.
			echo $output;
		}
		exit;
	}

	public function get_skill_markdown(): string {
		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$site_name = trim( (string) get_bloginfo( 'name' ) );
		$registry  = EndpointRegistry::get_instance();
		$base      = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$output    = "---\n";
		$output   .= 'name: ' . self::SKILL_NAME . "\n";
		$output   .= 'description: ' . $this->yaml_string( self::SKILL_DESCRIPTION ) . "\n";
		$output   .= "metadata:\n";
		$output   .= "  generator: Cybermaps\n";
		$output   .= '  generator-version: ' . $this->yaml_string( CYBERMAPS_VERSION ) . "\n";
		$output   .= "---\n\n";
		$output   .= '# Site Guide: ' . $this->plain_line( $site_name ) . "\n\n";
		$output   .= "This skill describes public, read-only site resources. Client discovery and support depend on the consuming agent.\n\n";
		if ( $registry->is_enabled( 'mcp', $settings ) ) {
			$mcp_mode = (string) ( $settings['mcp_mode'] ?? 'discovery' );
			$mcp_url  = $registry->get_url( 'mcp' );
			if ( '' !== $mcp_url ) {
				$output .= "## Optional MCP endpoint\n\n";
				$output .= '- Endpoint: [' . $mcp_url . '](' . $mcp_url . ")\n";
				$output .= '- Mode: `' . $this->plain_line( $mcp_mode ) . "`\n";
				$output .= "This endpoint is administrator-enabled. Public resources in this guide do not grant permission to invoke tools or perform operations; authorized clients still require the configured authentication and WordPress capability checks.\n\n";
			}
		} else {
			$output .= "This site does not expose an MCP server because MCP is disabled by default until an administrator enables it.\n\n";
		}
		$output .= "## Content publications\n\n";
		$output .= '- [LLMS site map](' . $registry->get_url( 'llms' ) . ")\n";
		if ( $registry->is_enabled( 'llms_full', $settings ) ) {
			$output .= '- [Complete literal content publication](' . $registry->get_url( 'llms_full' ) . ")\n";
		}
		if ( $registry->is_enabled( 'llms_tldr', $settings ) ) {
			$output .= '- [Experimental budgeted site briefing](' . $registry->get_url( 'llms_tldr' ) . ")\n";
		}
		$output .= '- [XML sitemap](' . \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' ) . ")\n";
		$output .= '- [JSON Feed](' . $registry->get_url( 'feed' ) . ")\n";
		$output .= '- [Schema.org graph publication](' . $registry->get_url( 'knowledge_graph' ) . ")\n\n";

		$search_url = $registry->get_url( 'rest_search' );
		if ( '' !== $search_url ) {
			$output .= "## Read-only search\n\n";
			$output .= '- Endpoint: ' . \Cybermaps\Core\URLManager::append_query_template(
				$search_url,
				'q={query}&limit={1-100}'
			) . "\n";
			$output .= "- Method: GET\n";
			$output .= "- Behavior: WordPress text search over configured, eligible public content; no vector or semantic ranking.\n\n";
		}

		$actions_url = $registry->get_url( 'actions' );
		if ( '' !== $actions_url ) {
			$output .= "## Operator-declared links\n\n";
			$output .= '- [Action inventory](' . $actions_url . ")\n";
			$output .= "These are site-operator declarations. Cybermaps does not verify that a linked service is executable or safe for autonomous use.\n\n";
		}

		$guidance = PublisherGuidance::get( $settings );
		if ( '' !== $guidance ) {
			$output .= "## Publisher guidance\n\n";
			$output .= $guidance . "\n\n";
		}

		$guide_addition = PublisherGuidance::get_site_guide_guidance( $settings );
		if ( '' !== $guide_addition && $guidance !== $guide_addition ) {
			$output .= "## Additional Site Guide guidance\n\n";
			$output .= $guide_addition . "\n\n";
		}

		return rtrim( $output ) . "\n";
	}

	private function plain_line( string $value ): string {
		return preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? trim( $value );
	}

	private function yaml_string( string $value ): string {
		$encoded = \wp_json_encode( $this->plain_line( $value ), JSON_UNESCAPED_SLASHES );
		return \is_string( $encoded ) ? $encoded : '""';
	}
}
