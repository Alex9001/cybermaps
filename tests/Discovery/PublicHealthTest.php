<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\PublicHealth;
use PHPUnit\Framework\TestCase;

class PublicHealthTest extends TestCase {
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

	public function test_health_payload_is_bounded_and_contains_no_internal_diagnostics(): void {
		$payload = ( new PublicHealth() )->get_health_data();

		$this->assertSame( array( 'status', 'serviceId', 'version' ), array_keys( $payload ) );
		$this->assertSame( 'pass', $payload['status'] );
		$this->assertSame( 'cybermaps-discovery', $payload['serviceId'] );
		$this->assertSame( CYBERMAPS_VERSION, $payload['version'] );
		$this->assertStringNotContainsString( 'database', strtolower( (string) wp_json_encode( $payload ) ) );
		$this->assertStringNotContainsString( ABSPATH, (string) wp_json_encode( $payload ) );
	}

	public function test_health_url_is_derived_from_the_public_rest_namespace(): void {
		$this->assertSame( 'https://example.com/wp-json/cybermaps/v1/health', PublicHealth::get_url() );
	}

	public function test_handler_matches_only_the_canonical_health_route(): void {
		$method = new \ReflectionMethod( PublicHealth::class, 'matches_path' );

		$this->assertTrue( $method->invoke( null, '/wp-json/cybermaps/v1/health' ) );
		$this->assertFalse( $method->invoke( null, '/health' ) );
		$this->assertFalse( $method->invoke( null, '/wp-json/cybermaps/v1/status' ) );
	}
}
