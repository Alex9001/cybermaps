<?php
/**
 * Maturity guidance for emerging discovery controls.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders visible, linked maturity guidance for settings and status surfaces.
 */
final class MaturityGuidance {
	/**
	 * Return one translatable maturity definition.
	 *
	 * @return array{badge:string,text:string,links:array<int,array{label:string,url:string}>}|array{}
	 */
	public static function definition( string $key ): array {
		$definitions = array(
			'publication_hub'    => array(
				'badge' => __( 'Standard + Draft', 'cybermaps' ),
				'text'  => __( 'Cybermaps publishes both established and emerging discovery formats. RFC 9727 is standardized; ARD remains a draft. Public delivery must still be verified in AI Discovery Status.', 'cybermaps' ),
				'links' => array(
					array(
						'label' => __( 'RFC 9727', 'cybermaps' ),
						'url'   => 'https://www.rfc-editor.org/rfc/rfc9727',
					),
					array(
						'label' => __( 'ARD draft', 'cybermaps' ),
						'url'   => 'https://agenticresourcediscovery.org/',
					),
				),
			),
			'mcp'                => array(
				'badge' => __( 'Experimental', 'cybermaps' ),
				'text'  => __( 'Early-adoption feature: Cybermaps publishes the current MCP Server Card draft so this site is prepared ahead of broad client support. Older scanners may expect a superseded format.', 'cybermaps' ),
				'links' => array(
					array(
						'label' => __( 'Current MCP Server Card draft', 'cybermaps' ),
						'url'   => 'https://github.com/modelcontextprotocol/ext-server-card',
					),
				),
			),
			'agent_registration' => array(
				'badge' => __( 'Early protocol', 'cybermaps' ),
				'text'  => __( 'Auth.md is an emerging agent-registration protocol. No account or credential is created until a logged-in WordPress user reviews and approves the request.', 'cybermaps' ),
				'links' => array(
					array(
						'label' => __( 'Auth.md overview', 'cybermaps' ),
						'url'   => 'https://workos.com/auth-md',
					),
					array(
						'label' => __( 'Auth.md source', 'cybermaps' ),
						'url'   => 'https://github.com/workos/auth.md',
					),
				),
			),
			'webmcp'             => array(
				'badge' => __( 'Early preview', 'cybermaps' ),
				'text'  => __( 'WebMCP is available only in participating preview browsers. Cybermaps exposes read-only tools and safely does nothing when the browser API is unavailable.', 'cybermaps' ),
				'links' => array(
					array(
						'label' => __( 'WebMCP specification', 'cybermaps' ),
						'url'   => 'https://webmachinelearning.github.io/webmcp/',
					),
				),
			),
			'markdown'           => array(
				'badge' => __( 'Vendor convention', 'cybermaps' ),
				'text'  => __( 'This emerging negotiation convention prepares eligible pages for Markdown-capable agents. HTML remains the default, and shared caches must honor Vary: Accept.', 'cybermaps' ),
				'links' => array(
					array(
						'label' => __( 'Markdown for Agents', 'cybermaps' ),
						'url'   => 'https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/',
					),
				),
			),
			'api_catalog'        => array(
				'badge' => __( 'RFC standard', 'cybermaps' ),
				'text'  => __( "RFC 9727 is a formal standard. 'Enabled' means Cybermaps generated the catalog; use AI Discovery Status to confirm that the origin serves the canonical well-known URL.", 'cybermaps' ),
				'links' => array(
					array(
						'label' => __( 'RFC 9727', 'cybermaps' ),
						'url'   => 'https://www.rfc-editor.org/rfc/rfc9727',
					),
				),
			),
			'deployment'         => array(
				'badge' => __( 'Deployment required', 'cybermaps' ),
				'text'  => __( 'Cybermaps materializes ownership-safe canonical fallback bodies when the origin bypasses WordPress. Debugging reports header conformance, and Advanced can optionally install scoped Cloudflare response rules.', 'cybermaps' ),
				'links' => array(),
			),
		);

		return $definitions[ $key ] ?? array();
	}

	/**
	 * Return the description element ID associated with a control.
	 */
	public static function description_id( string $control_id ): string {
		return sanitize_key( $control_id ) . '-maturity';
	}

	/**
	 * Render one maturity definition as visible small text.
	 */
	public static function render( string $control_id, string $key ): void {
		$definition = self::definition( $key );
		if ( array() === $definition ) {
			return;
		}

		echo '<p id="' . esc_attr( self::description_id( $control_id ) ) . '" class="description cm-maturity-guidance">';
		echo '<span class="cm-maturity-badge">' . esc_html( $definition['badge'] ) . '</span> ';
		echo esc_html( $definition['text'] );
		foreach ( $definition['links'] as $link ) {
			echo ' <a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['label'] ) . '</a>';
		}
		echo '</p>';
	}
}
