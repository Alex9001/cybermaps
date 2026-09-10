<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\StandardsRegistry;
use PHPUnit\Framework\TestCase;

final class StandardsRegistryTest extends TestCase {
	public function test_registry_contains_the_reviewed_protocol_profiles(): void {
		$registry = new StandardsRegistry();

		$this->assertSame(
			array(
				'openapi-3.2.0',
				'openapi-3.1.2',
				'llms-txt-v2',
				'markdown-for-agents-2026',
				'agent-skills-0.2.0',
				'adp-3.0-cybermaps-level-3',
				'mcp-2026-07-28',
				'aipref-vocab-07',
				'aipref-attachment-draft',
				'websub-2026',
				'json-feed-1.1',
				'rfc-9309',
				'rfc-9457',
				'rfc-9530',
				'rfc-9727',
				'indexnow',
			),
			$registry->ids()
		);
	}

	public function test_profiles_are_typed_and_reviewed_without_runtime_network_access(): void {
		$registry = new StandardsRegistry();
		$profiles = $registry->all();

		$this->assertCount( 16, $profiles );
		foreach ( $profiles as $profile ) {
			$this->assertIsString( $profile['title'] ?? null );
			$this->assertIsString( $profile['authority'] ?? null );
			$this->assertIsString( $profile['spec_uri'] ?? null );
			$this->assertIsString( $profile['version'] ?? null );
			$this->assertSame( StandardsRegistry::REVIEWED_AT, $profile['reviewed_at'] ?? null );
			$this->assertIsArray( $profile['conformance'] ?? null );
		}

		$profiles[0]['title'] = 'changed by caller';
		$this->assertNotSame( 'changed by caller', $registry->all()[0]['title'] );
		$this->assertSame( 'application/vnd.oai.openapi+json;version=3.2', $registry->get( 'openapi-3.2.0' )['conformance']['canonical_media_type'] );
	}
}
