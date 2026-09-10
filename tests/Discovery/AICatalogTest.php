<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\AICatalog;
use PHPUnit\Framework\TestCase;

class AICatalogTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'mcp_mode'             => 'off',
			),
		);
		$registry                          = new \ReflectionProperty( \Cybermaps\Core\EndpointRegistry::class, 'instance' );
		$registry->setValue( null, null );
	}

	public function test_catalog_has_host_and_active_public_api_entry(): void {
		$catalog = ( new AICatalog() )->get_catalog_data();

		$this->assertSame( '1.0', $catalog['specVersion'] );
		$this->assertNotEmpty( $catalog['host']['displayName'] );
		$this->assertSame( 'https://example.com/', $catalog['host']['documentationUrl'] );
		$this->assertCount( 1, $catalog['entries'] );
		$this->assertSame( 'urn:air:example.com:api:cybermaps-discovery', $catalog['entries'][0]['identifier'] );
		$this->assertSame( 'application/vnd.oai.openapi+json;version=3.2', $catalog['entries'][0]['type'] );
		$this->assertArrayHasKey( 'url', $catalog['entries'][0] );
		$this->assertArrayNotHasKey( 'data', $catalog['entries'][0] );
		$this->assertCount( 2, $catalog['entries'][0]['representativeQueries'] );
	}

	public function test_mcp_entry_is_present_only_while_mcp_is_active(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'discovery';
		$entries = ( new AICatalog() )->get_catalog_data()['entries'];

		$this->assertCount( 2, $entries );
		$this->assertSame( 'urn:air:example.com:mcp:cybermaps', $entries[1]['identifier'] );
		$this->assertSame( 'application/mcp-server-card+json', $entries[1]['type'] );
		$this->assertSame(
			'https://example.com/wp-json/cybermaps/v1/mcp/server-card',
			$entries[1]['url']
		);
		$this->assertArrayNotHasKey( 'data', $entries[1] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'off';
		$this->assertCount( 1, ( new AICatalog() )->get_catalog_data()['entries'] );
	}

	public function test_disabled_hub_has_no_catalog_entries_or_a2a_claims(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '';
		$catalog = ( new AICatalog() )->get_catalog_data();
		$json    = (string) wp_json_encode( $catalog );

		$this->assertSame( array(), $catalog['entries'] );
		$this->assertStringNotContainsString( 'a2a', strtolower( $json ) );
	}

	public function test_catalog_matches_both_locked_paths_and_uses_json_media_type(): void {
		$method = new \ReflectionMethod( AICatalog::class, 'matches_path' );

		$this->assertSame( 'application/json', AICatalog::MEDIA_TYPE );
		$this->assertTrue( $method->invoke( null, '/.well-known/ai-catalog.json' ) );
		$this->assertTrue( $method->invoke( null, '/ai-catalog.json' ) );
		$this->assertFalse( $method->invoke( null, '/.well-known/ai-catalog' ) );
	}
}
