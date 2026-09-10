<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\DeploymentGuidance;
use Cybermaps\Admin\MaturityGuidance;
use Cybermaps\Admin\Settings\Fields\FieldRenderer;
use Cybermaps\Discovery\StaticHeaderManifest;
use PHPUnit\Framework\TestCase;

final class MaturityDeploymentGuidanceTest extends TestCase {
	public function test_maturity_definitions_preserve_the_seven_authoritative_contracts(): void {
		$expected = array(
			'publication_hub'    => array( 'Standard + Draft', 'Cybermaps publishes both established and emerging discovery formats. RFC 9727 is standardized; ARD remains a draft. Public delivery must still be verified in AI Discovery Status.' ),
			'mcp'                => array( 'Experimental', 'Early-adoption feature: Cybermaps publishes the current MCP Server Card draft so this site is prepared ahead of broad client support. Older scanners may expect a superseded format.' ),
			'agent_registration' => array( 'Early protocol', 'Auth.md is an emerging agent-registration protocol. No account or credential is created until a logged-in WordPress user reviews and approves the request.' ),
			'webmcp'             => array( 'Early preview', 'WebMCP is available only in participating preview browsers. Cybermaps exposes read-only tools and safely does nothing when the browser API is unavailable.' ),
			'markdown'           => array( 'Vendor convention', 'This emerging negotiation convention prepares eligible pages for Markdown-capable agents. HTML remains the default, and shared caches must honor Vary: Accept.' ),
			'api_catalog'        => array( 'RFC standard', "RFC 9727 is a formal standard. 'Enabled' means Cybermaps generated the catalog; use AI Discovery Status to confirm that the origin serves the canonical well-known URL." ),
			'deployment'         => array( 'Deployment required', 'Cybermaps materializes ownership-safe canonical fallback bodies when the origin bypasses WordPress. Debugging reports header conformance, and Advanced can optionally install scoped Cloudflare response rules.' ),
		);

		foreach ( $expected as $key => $contract ) {
			$definition = MaturityGuidance::definition( $key );
			self::assertSame( $contract[0], $definition['badge'] );
			self::assertSame( $contract[1], $definition['text'] );
		}
	}

	public function test_toggle_guidance_is_visible_and_programmatically_described(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();
		ob_start();
		FieldRenderer::render_toggle(
			array(
				'label_for' => 'enable_webmcp',
				'label'     => 'Expose read-only browser tools',
				'maturity'  => 'webmcp',
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'aria-describedby="enable_webmcp-maturity"', $html );
		self::assertStringContainsString( 'id="enable_webmcp-maturity" class="description cm-maturity-guidance"', $html );
		self::assertStringContainsString( 'WebMCP is available only in participating preview browsers.', $html );
		self::assertStringContainsString( 'https://webmachinelearning.github.io/webmcp/', $html );
	}

	public function test_deployment_manifest_exposes_all_copy_ready_rule_families(): void {
		$method = new \ReflectionMethod( StaticHeaderManifest::class, 'routing_snippets' );
		$rules  = $method->invoke( new StaticHeaderManifest() );

		self::assertStringContainsString( 'try_files $uri /index.php?$args;', $rules['nginx'] );
		self::assertStringContainsString( 'RewriteRule ^', $rules['apache_openlitespeed'] );
		self::assertStringContainsString( 'E=Cache-Control:no-cache', $rules['litespeed_cache'] );
		self::assertStringContainsString( 'return (pass);', $rules['varnish'] );
		self::assertStringContainsString( '"origin": "wordpress"', $rules['reverse_proxy_cdn'] );
	}

	public function test_status_summary_identifies_intercepted_well_known_canonical_path(): void {
		$summary = DeploymentGuidance::summarize(
			array(
				array(
					'endpoint_id' => 'api_catalog',
					'canonical'   => true,
					'enabled'     => true,
					'path'        => '/.well-known/api-catalog',
					'url'         => 'https://example.com/.well-known/api-catalog',
					'status'      => 'error',
				),
				array(
					'endpoint_id' => 'api_catalog',
					'canonical'   => false,
					'enabled'     => true,
					'path'        => '/api-catalog',
					'url'         => 'https://example.com/api-catalog',
					'status'      => 'healthy',
				),
			)
		);

		self::assertSame( 1, $summary['configured'] );
		self::assertSame( 1, $summary['advertised'] );
		self::assertSame( 0, $summary['verified'] );
		self::assertSame(
			array(
				array(
					'canonical' => '/.well-known/api-catalog',
					'alias'     => '/api-catalog',
				),
			),
			$summary['intercepted']
		);
	}
}
