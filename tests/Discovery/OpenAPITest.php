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
		$this->assertArrayHasKey( 'oauth2', $document['components']['securitySchemes'] );
		$this->assertArrayHasKey( 'bearerAuth', $document['components']['securitySchemes'] );
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
		$_GET['version']        = '';
		$_SERVER['HTTP_ACCEPT'] = 'application/vnd.oai.openapi+json;version=3.1';
		$this->assertSame( '3.1.2', OpenAPI::negotiate_version() );

		$_GET['version']        = '3.1.2';
		$_SERVER['HTTP_ACCEPT'] = 'application/vnd.oai.openapi+json;version=3.2';
		$this->assertSame( '3.1.2', OpenAPI::negotiate_version() );

		unset( $_GET['version'], $_SERVER['HTTP_ACCEPT'] );
		$this->assertSame( '3.2.0', OpenAPI::negotiate_version() );
	}

	public function test_mcp_route_is_described_only_when_enabled(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'operations';
		$document = ( new OpenAPI() )->get_document();

		$this->assertArrayHasKey( '/cybermaps/v1/mcp', $document['paths'] );
		$this->assertArrayHasKey( '/cybermaps/v1/mcp/server-card', $document['paths'] );
		$this->assertSame( '#/components/schemas/McpJsonRpcRequest', $document['paths']['/cybermaps/v1/mcp']['post']['requestBody']['content']['application/json']['schema']['$ref'] );
		$this->assertSame( '2026-07-28', $document['paths']['/cybermaps/v1/mcp']['post']['parameters'][0]['schema']['const'] );
		$this->assertSame( array(), $document['paths']['/cybermaps/v1/mcp/server-card']['get']['security'] );
		$this->assertSame(
			'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json',
			$document['paths']['/cybermaps/v1/mcp/server-card']['get']['responses']['200']['content']['application/mcp-server-card+json']['schema']['$ref']
		);
	}

	public function test_device_authorization_contract_matches_user_claimed_rfc_8628_route(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode']                = 'discovery';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['agent_registration_mode'] = 'user_claimed';
		$document  = ( new OpenAPI() )->get_document();
		$operation = $document['paths']['/cybermaps/v1/oauth/device-authorization']['post'];
		$form      = $operation['requestBody']['content']['application/x-www-form-urlencoded']['schema'];
		$success   = $operation['responses']['200']['content']['application/json']['schema'];

		$this->assertSame( array(), $operation['security'] );
		$this->assertSame( array( 'client_id' ), $form['required'] );
		$this->assertSame( '^https://', $form['properties']['client_id']['pattern'] );
		$this->assertSame( array( 'client_id', 'scope', 'resource' ), array_keys( $form['properties'] ) );
		$this->assertSame(
			array( 'device_code', 'user_code', 'verification_uri', 'verification_uri_complete', 'expires_in', 'interval' ),
			$success['required']
		);
		$this->assertSame( '^[A-Z2-9]{4}-[A-Z2-9]{4}$', $success['properties']['user_code']['pattern'] );
		$this->assertSame( array( 'invalid_request', 'invalid_scope', 'invalid_target' ), $operation['responses']['400']['content']['application/json']['schema']['properties']['error']['enum'] );
		$this->assertSame( array( 'invalid_client', 'unauthorized_client' ), $operation['responses']['401']['content']['application/json']['schema']['properties']['error']['enum'] );
		$this->assertSame( array( 'unauthorized_client' ), $operation['responses']['403']['content']['application/json']['schema']['properties']['error']['enum'] );
		$this->assertStringNotContainsString( 'openid', strtolower( (string) wp_json_encode( $operation ) ) );
		$this->assertStringNotContainsString( 'jwks', strtolower( (string) wp_json_encode( $operation ) ) );
		$this->assertStringNotContainsString( 'registration_endpoint', strtolower( (string) wp_json_encode( $operation ) ) );
	}

	public function test_device_authorization_requires_both_user_claimed_mode_and_mcp(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'discovery';
		$this->assertArrayNotHasKey( '/cybermaps/v1/oauth/device-authorization', ( new OpenAPI() )->get_document()['paths'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['agent_registration_mode'] = 'user_claimed';
		$this->assertArrayHasKey( '/cybermaps/v1/oauth/device-authorization', ( new OpenAPI() )->get_document()['paths'] );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'off';
		$this->assertArrayNotHasKey( '/cybermaps/v1/oauth/device-authorization', ( new OpenAPI() )->get_document()['paths'] );
	}

	public function test_mcp_route_requires_the_discovery_hub_gate(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'mcp_mode' => 'operations' );

		$this->assertArrayNotHasKey( '/cybermaps/v1/mcp', ( new OpenAPI() )->get_document()['paths'] );
		$this->assertArrayNotHasKey( '/cybermaps/v1/mcp/server-card', ( new OpenAPI() )->get_document()['paths'] );
	}
}
