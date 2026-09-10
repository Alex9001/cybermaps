<?php
declare(strict_types=1);

namespace Cybermaps\Tests\MCP;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\MCP\CallbackResourceReader;
use Cybermaps\MCP\ResourceRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResourceRegistryTest extends \WP_UnitTestCase {
	public function test_reads_only_the_exact_canonical_registry_uri(): void {
		$endpoints = EndpointRegistry::get_instance();
		if ( ! $endpoints->has( 'mcp_test_resource' ) ) {
			$endpoints->register(
				'mcp_test_resource',
				array(
					'kind'        => 'path',
					'path'        => '/mcp-test-resource.txt',
					'type'        => 'text/plain',
					'format'      => 'text',
					'advertise'   => true,
					'label'       => 'MCP test resource',
					'description' => 'Fixture publication.',
				)
			);
		}
		$reads    = 0;
		$registry = new ResourceRegistry(
			$endpoints,
			new CallbackResourceReader(
				static function ( string $id ) use ( &$reads ): array {
					++$reads;
					return array( 'body' => 'fixture:' . $id, 'mime_type' => 'text/plain' );
				}
			)
		);
		$uri      = $endpoints->get_url( 'mcp_test_resource' );

		$this->assertSame( 'fixture:mcp_test_resource', $registry->read( $uri )['text'] );
		$this->assertNull( $registry->read( $uri . '?target=https://attacker.example' ) );
		$this->assertSame( 1, $reads );
	}
}
