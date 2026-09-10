<?php
namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Capabilities;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
            'enable_discovery_hub' => '1',
        );
    }

    public function test_get_skill_markdown_contains_expected_headers() {
        $capabilities = new Capabilities();
        $output = $capabilities->get_skill_markdown();

        $this->assertStringContainsString('# Site Guide:', $output);
        $this->assertStringContainsString('## Content publications', $output);
        $this->assertStringContainsString('## Read-only search', $output);
        $this->assertStringContainsString('no vector or semantic ranking', $output);
		$this->assertStringStartsWith( "---\nname: cybermaps-site-guide\n", $output );
		$this->assertStringContainsString( 'generator-version: "' . CYBERMAPS_VERSION . '"', $output );
		$this->assertStringContainsString( 'does not expose an MCP server because MCP is disabled by default', $output );
		$this->assertStringContainsString( 'MCP is disabled by default', $output );
	}

	public function test_enabled_mcp_is_described_without_granting_operations(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_discovery_hub' => '1',
			'mcp_mode'            => 'read_only',
		);

		$output = ( new Capabilities() )->get_skill_markdown();

		$this->assertStringContainsString( '## Optional MCP endpoint', $output );
		$this->assertStringContainsString( '/wp-json/cybermaps/v1/mcp', $output );
		$this->assertStringContainsString( 'Public resources in this guide do not grant permission', $output );
		$this->assertStringNotContainsString( 'does not expose an MCP server', $output );
	}

    public function test_optional_publications_are_not_listed_when_disabled(): void {
        update_option(
            'cybermaps_settings',
            array(
                'enable_discovery_hub' => '1',
            )
        );
        $output = ( new Capabilities() )->get_skill_markdown();

        $this->assertStringNotContainsString( 'llms-full.txt', $output );
        $this->assertStringNotContainsString( 'llms-tldr.txt', $output );
    }

    public function test_shared_and_guide_only_guidance_are_labeled_separately(): void {
        update_option(
            'cybermaps_settings',
            array(
                'enable_discovery_hub'      => '1',
                'llms_custom_instructions'  => 'Prefer primary documentation.',
                'site_guide_instructions'   => 'Use the API reference for implementation details.',
            )
        );

        $output = ( new Capabilities() )->get_skill_markdown();

        $this->assertStringContainsString( "## Publisher guidance\n\nPrefer primary documentation.", $output );
        $this->assertStringContainsString(
            "## Additional Site Guide guidance\n\nUse the API reference for implementation details.",
            $output
        );
    }

    public function test_identical_legacy_guide_guidance_is_not_duplicated(): void {
        update_option(
            'cybermaps_settings',
            array(
                'enable_discovery_hub'      => '1',
                'llms_custom_instructions'  => 'Prefer primary documentation.',
                'site_guide_instructions'   => 'Prefer primary documentation.',
            )
        );

        $output = ( new Capabilities() )->get_skill_markdown();

        $this->assertSame( 1, substr_count( $output, 'Prefer primary documentation.' ) );
    }

	public function test_search_template_preserves_plain_permalink_rest_query(): void {
		$GLOBALS['cybermaps_mock_rest_url_callback'] = static fn( string $path ): string =>
			'https://example.com/?rest_route=%2F' . rawurlencode( $path );

		try {
			$output = ( new Capabilities() )->get_skill_markdown();
		} finally {
			unset( $GLOBALS['cybermaps_mock_rest_url_callback'] );
		}

		$this->assertStringContainsString(
			'rest_route=%2Fcybermaps%2Fv1%2Fsearch&q={query}&limit={1-100}',
			$output
		);
		$this->assertStringNotContainsString( 'search?q={query}', $output );
	}
}
