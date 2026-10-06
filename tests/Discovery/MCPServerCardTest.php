<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\MCPServerCard;
use PHPUnit\Framework\TestCase;

class MCPServerCardTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'enable_mcp_adapter'             => '1',
			),
		);
		$registry                          = new \ReflectionProperty( \Cybermaps\Core\EndpointRegistry::class, 'instance' );
		$registry->setValue( null, null );
	}

	public function test_card_uses_only_the_current_v1_draft_shape(): void {
		$card = ( new MCPServerCard() )->get_card_data();

		$this->assertSame( MCPServerCard::SCHEMA_URI, $card['$schema'] );
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9.-]+\/[a-zA-Z0-9._-]+$/', $card['name'] );
		$this->assertLessThanOrEqual( 100, strlen( $card['description'] ) );
		$this->assertSame( 'streamable-http', $card['remotes'][0]['type'] );
		$this->assertSame( 'https://example.com/wp-json/mcp/cybermaps', $card['remotes'][0]['url'] );
		$this->assertSame( array( '2025-11-25', '2026-07-28' ), $card['remotes'][0]['supportedProtocolVersions'] );
		$this->assertArrayNotHasKey( 'tools', $card );
		$this->assertArrayNotHasKey( 'resources', $card );
		$this->assertArrayNotHasKey( 'prompts', $card );
	}

	public function test_card_url_is_the_canonical_child_of_the_mcp_transport(): void {
		$this->assertSame(
			'https://example.com/wp-json/cybermaps/v1/mcp/server-card',
			MCPServerCard::get_card_url()
		);
	}

	public function test_handler_supports_the_canonical_and_locked_well_known_paths(): void {
		$method = new \ReflectionMethod( MCPServerCard::class, 'matches_path' );

		$this->assertTrue( $method->invoke( null, '/wp-json/cybermaps/v1/mcp/server-card' ) );
		$this->assertTrue( $method->invoke( null, '/.well-known/mcp/server-card.json' ) );
		$this->assertFalse( $method->invoke( null, '/.well-known/mcp-server-card.json' ) );
	}

	public function test_card_availability_tracks_the_mcp_service(): void {
		$adapter = \Cybermaps\Tests\AdapterFixture::enable();
		$this->assertTrue( MCPServerCard::is_available() );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_mcp_adapter'] = '0';
		$this->assertFalse( MCPServerCard::is_available() );
	}
}
