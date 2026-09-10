<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\APICatalog;
use PHPUnit\Framework\TestCase;

class APICatalogTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
			),
		);
		$registry                          = new \ReflectionProperty( \Cybermaps\Core\EndpointRegistry::class, 'instance' );
		$registry->setValue( null, null );
	}

	public function test_catalog_links_its_generated_service_description_without_vendor_marketing_docs(): void {
		$payload = ( new APICatalog() )->get_catalog_data();
		$catalog = $payload['linkset'][0];

		$this->assertArrayNotHasKey( 'service-doc', $catalog );
		$this->assertSame(
			'https://example.com/cybermaps-openapi.json',
			$catalog['service-desc'][0]['href'] ?? null
		);
		$this->assertSame(
			'application/vnd.oai.openapi+json;version=3.2',
			$catalog['service-desc'][0]['type'] ?? null
		);
		$this->assertSame(
			'https://example.com/cybermaps-openapi.json?version=3.1.2',
			$catalog['service-desc'][1]['href'] ?? null
		);
		$this->assertSame(
			'application/vnd.oai.openapi+json;version=3.1',
			$catalog['service-desc'][1]['type'] ?? null
		);
		$this->assertStringNotContainsString( 'cybermaps.dev', (string) wp_json_encode( $payload ) );
	}

	public function test_catalog_is_a_nonempty_linkset_of_public_api_items(): void {
		$payload = ( new APICatalog() )->get_catalog_data();
		$catalog = $payload['linkset'][0];

		$this->assertSame( 'https://example.com/.well-known/api-catalog', $catalog['anchor'] );
		$this->assertCount( 2, $catalog['item'] );
		$this->assertSame( 'https://example.com/wp-json/cybermaps/v1/discovery', $catalog['item'][0]['href'] );
		$this->assertSame( 'application/json', $catalog['item'][0]['type'] );
		$this->assertSame( 'https://example.com/wp-json/cybermaps/v1/search', $catalog['item'][1]['href'] );
		$this->assertArrayNotHasKey( 'status', $catalog );
	}

	public function test_rfc_9727_media_type_includes_registered_profile(): void {
		$media_type = APICatalog::get_media_type();

		$this->assertStringStartsWith( 'application/linkset+json;', $media_type );
		$this->assertStringContainsString( 'profile="' . APICatalog::PROFILE_URI . '"', $media_type );
	}

	public function test_catalog_mcp_item_requires_both_discovery_hub_and_mode(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'discovery';
		$catalog = ( new APICatalog() )->get_catalog_data()['linkset'][0];

		$this->assertSame( 'https://example.com/wp-json/cybermaps/v1/mcp', $catalog['item'][2]['href'] ?? null );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '';
		$catalog = ( new APICatalog() )->get_catalog_data()['linkset'][0];
		$this->assertCount( 2, $catalog['item'] );
	}

	public function test_each_public_api_has_description_documentation_and_health_relations(): void {
		$linkset = ( new APICatalog() )->get_catalog_data()['linkset'];
		$apis    = array_slice( $linkset, 1 );

		$this->assertCount( 2, $apis );
		foreach ( $apis as $api ) {
			$this->assertNotEmpty( $api['anchor'] );
			$this->assertNotEmpty( $api['service-desc'] );
			$this->assertSame(
				'https://example.com/wp-json/cybermaps/v1/discovery',
				$api['service-doc'][0]['href'] ?? null
			);
			$this->assertSame(
				'https://example.com/wp-json/cybermaps/v1/health',
				$api['status'][0]['href'] ?? null
			);
			$this->assertSame( 'application/health+json', $api['status'][0]['type'] ?? null );
		}
	}

	public function test_mcp_api_context_uses_the_server_card_as_its_description(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'discovery';
		$linkset = ( new APICatalog() )->get_catalog_data()['linkset'];
		$mcp     = $linkset[3];

		$this->assertSame( 'https://example.com/wp-json/cybermaps/v1/mcp', $mcp['anchor'] );
		$this->assertSame(
			'https://example.com/wp-json/cybermaps/v1/mcp/server-card',
			$mcp['service-desc'][0]['href'] ?? null
		);
		$this->assertSame( 'application/mcp-server-card+json', $mcp['service-desc'][0]['type'] ?? null );
	}

	public function test_head_link_header_uses_api_catalog_relation(): void {
		$link = ( new APICatalog() )->get_link_header();

		$this->assertStringStartsWith( '<https://example.com/.well-known/api-catalog>', $link );
		$this->assertStringContainsString( 'rel="api-catalog"', $link );
		$this->assertStringContainsString( 'type="application/linkset+json"', $link );
	}

	public function test_handler_accepts_the_standard_path_and_compatibility_alias(): void {
		$method = new \ReflectionMethod( APICatalog::class, 'matches_path' );

		$this->assertTrue( $method->invoke( null, '/api-catalog' ) );
		$this->assertTrue( $method->invoke( null, '/.well-known/api-catalog' ) );
		$this->assertFalse( $method->invoke( null, '/api-catalog/' ) );
		$this->assertFalse( $method->invoke( null, '/not-api-catalog' ) );
	}

	public function test_registry_records_rfc_9727_as_a_formal_standard(): void {
		$definition = \Cybermaps\Core\EndpointRegistry::get_instance()->get( 'api_catalog' );

		$this->assertSame( 'formal-standard', $definition['maturity'] );
		$this->assertStringContainsString( 'RFC 9727', $definition['spec'] );
	}

	public function test_head_request_does_not_send_a_body(): void {
		$method = new \ReflectionMethod( APICatalog::class, 'request_has_body' );
		$prior  = $_SERVER['REQUEST_METHOD'] ?? null;

		try {
			$_SERVER['REQUEST_METHOD'] = 'HEAD';
			$this->assertFalse( $method->invoke( null ) );

			$_SERVER['REQUEST_METHOD'] = 'GET';
			$this->assertTrue( $method->invoke( null ) );

			$_SERVER['REQUEST_METHOD'] = array( 'HEAD' );
			$this->assertTrue( $method->invoke( null ) );
		} finally {
			if ( null === $prior ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $prior;
			}
		}
	}
}
