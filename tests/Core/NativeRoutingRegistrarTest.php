<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\NativeRoutingRegistrar;
use PHPUnit\Framework\TestCase;

final class NativeRoutingRegistrarTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		( new \ReflectionProperty( EndpointRegistry::class, 'instance' ) )->setValue( null, null );
		$GLOBALS['cybermaps_mock_rewrite_rules'] = array();
		$GLOBALS['wp_rewrite'] = new class() {
			public array $extra_rules_top = array();
			public array $extra_rules = array();
			public array $non_wp_rules = array();
			public function add_external_rule( string $regex, string $query ): void {
				$this->non_wp_rules[ $regex ] = $query;
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_rewrite'] );
		parent::tearDown();
	}

	public function test_registered_api_catalog_rule_is_internal_and_precedes_file_checks(): void {
		NativeRoutingRegistrar::get_instance()->register_rules();

		$internal = array_values( array_filter( $GLOBALS['cybermaps_mock_rewrite_rules'], static fn( array $rule ): bool => '^\\.well\\-known/api\\-catalog$' === $rule['regex'] ) );
		$this->assertCount( 1, $internal );
		$this->assertSame( 'top', $internal[0]['after'] );
		$this->assertSame( 'index.php?cybermaps_publication=api_catalog', $internal[0]['query'] );
		$this->assertSame( $internal[0]['query'], $GLOBALS['wp_rewrite']->non_wp_rules[ $internal[0]['regex'] ] );
	}

	public function test_runtime_header_requirement_prevents_static_target_inclusion(): void {
		$registry = EndpointRegistry::get_instance();
		$this->assertTrue( $registry->register( 'dynamic_fixture', array(
			'kind' => 'path',
			'path' => '/dynamic-fixture.json',
			'type' => 'application/json',
			'delivery_requirement' => 'runtime_headers_required',
			'static_targets' => array( array( 'path' => '/dynamic-fixture.json', 'bucket' => 'well_known' ) ),
		) ) );
		foreach ( array( 'well_known', 'all' ) as $mode ) {
			$this->assertNotContains( '/dynamic-fixture.json', array_column( $registry->get_static_targets( $mode, array(), true ), 'path' ) );
		}
	}

	public function test_default_static_inventory_keeps_protocol_publications_dynamic(): void {
		$targets = EndpointRegistry::get_instance()->get_static_targets( 'well_known', array(), true );
		$paths   = array_column( $targets, 'path' );

		$this->assertNotContains( '/.well-known/api-catalog', $paths );
		$this->assertContains( '/.well-known/agent-skills/index.json', $paths );
		$this->assertContains( '/.well-known/agent-skills/cybermaps-site-guide/SKILL.md', $paths );
		$this->assertNotContains( '/.well-known/oauth-authorization-server', $paths );
		$this->assertNotContains( '/.well-known/oauth-protected-resource', $paths );
		$this->assertNotContains( '/ai-discovery', $paths );
		$this->assertNotContains( '/.well-known/ai-catalog.json', $paths );
		$this->assertNotContains( '/.well-known/mcp/server-card.json', $paths );
		$this->assertCount( 5, $paths );
	}
}
