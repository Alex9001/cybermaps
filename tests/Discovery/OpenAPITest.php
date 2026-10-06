<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\OpenAPI;
use PHPUnit\Framework\TestCase;

final class OpenAPITest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'enable_discovery_hub' => '1' ),
		);
	}

	public function test_document_describes_only_public_read_only_operations(): void {
		$document = ( new OpenAPI() )->get_document();

		$this->assertSame( OpenAPI::VERSION, $document['openapi'] );
		$this->assertArrayHasKey( '/cybermaps/v1/discovery', $document['paths'] );
		$this->assertArrayHasKey( '/cybermaps/v1/search', $document['paths'] );
		$this->assertArrayHasKey( '/cybermaps/v1/health', $document['paths'] );
		$this->assertArrayNotHasKey( '/cybermaps/v1/urls', $document['paths'] );
		$this->assertArrayNotHasKey( '/cybermaps/v1/purge', $document['paths'] );
		$this->assertSame( 'searchPublications', $document['paths']['/cybermaps/v1/search']['get']['operationId'] );
		$this->assertSame( array(), $document['paths']['/cybermaps/v1/health']['get']['security'] );
		$this->assertArrayHasKey(
			'application/health+json',
			$document['paths']['/cybermaps/v1/health']['get']['responses']['200']['content']
		);
	}

	public function test_optional_briefing_is_described_only_when_enabled(): void {
		$this->assertArrayNotHasKey( '/cybermaps/v1/llms-tldr', ( new OpenAPI() )->get_document()['paths'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_llms_tldr'] = '1';
		$this->assertArrayHasKey( '/cybermaps/v1/llms-tldr', ( new OpenAPI() )->get_document()['paths'] );
	}

	public function test_canonical_document_uses_openapi_3_2_contract_metadata(): void {
		$document = ( new OpenAPI() )->get_document();

		$this->assertSame( '3.2.0', $document['openapi'] );
		$this->assertSame( 'https://spec.openapis.org/oas/3.2/dialect/base', $document['jsonSchemaDialect'] );
		$this->assertSame( 'https://example.com/cybermaps-openapi.json', $document['$self'] );
		$this->assertSame( OpenAPI::MEDIA_TYPE, OpenAPI::get_media_type() );
		$this->assertArrayNotHasKey( 'securitySchemes', $document['components'] );
	}

	public function test_3_1_2_compatibility_representation_is_explicit(): void {
		$document = ( new OpenAPI() )->get_document( '3.1.2' );

		$this->assertSame( '3.1.2', $document['openapi'] );
		$this->assertSame( 'https://spec.openapis.org/oas/3.1/dialect/base', $document['jsonSchemaDialect'] );
		$this->assertArrayNotHasKey( '$self', $document );
		$this->assertSame( 'https://example.com/cybermaps-openapi.json', $document['x-cybermaps-canonical'] );
		$this->assertSame( OpenAPI::COMPATIBILITY_MEDIA_TYPE, OpenAPI::get_media_type( '3.1.2' ) );
	}

	public function test_request_negotiation_prefers_query_then_compatibility_accept(): void {
		$this->assertSame(
			'3.1.2',
			OpenAPI::negotiate_version( '', 'application/vnd.oai.openapi+json;version=3.1' )
		);
		$this->assertSame(
			'3.1.2',
			OpenAPI::negotiate_version( '3.1.2', 'application/vnd.oai.openapi+json;version=3.2' )
		);
		$this->assertSame( '3.2.0', OpenAPI::negotiate_version( '', '' ) );
	}

	public function test_adapter_metadata_requires_dependency_and_opt_in(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_mcp_adapter'] = '1';
		$this->assertArrayNotHasKey( 'x-cybermaps-mcp', ( new OpenAPI() )->get_document() );
		$adapter = \Cybermaps\Tests\AdapterFixture::enable();
		$document = ( new OpenAPI() )->get_document();
		$this->assertSame( 'https://example.com/wp-json/mcp/cybermaps', $document['x-cybermaps-mcp']['url'] );
		$this->assertTrue( $document['x-cybermaps-mcp']['readOnly'] );
		$this->assertArrayHasKey( '/cybermaps/v1/mcp/server-card', $document['paths'] );
		$this->assertArrayNotHasKey( '/cybermaps/v1/mcp', $document['paths'] );
	}

	public function test_legacy_settings_cannot_restore_oauth_or_mutating_ability_contracts(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] += array( 'mcp_mode' => 'operations', 'agent_registration_mode' => 'user_claimed' );
		$document = ( new OpenAPI() )->get_document();
		$this->assertArrayNotHasKey( '/cybermaps/v1/oauth/device-authorization', $document['paths'] );
		$this->assertArrayNotHasKey( '/wp-abilities/v1/abilities/{namespace}/{ability}/run', $document['paths'] );
		$this->assertArrayNotHasKey( 'securitySchemes', $document['components'] );
	}

	public function test_mcp_route_requires_the_discovery_hub_gate(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'mcp_mode' => 'operations' );

		$this->assertArrayNotHasKey( '/cybermaps/v1/mcp', ( new OpenAPI() )->get_document()['paths'] );
		$this->assertArrayNotHasKey( '/cybermaps/v1/mcp/server-card', ( new OpenAPI() )->get_document()['paths'] );
	}
}
