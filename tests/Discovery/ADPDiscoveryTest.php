<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\ADPDiscovery;

final class ADPDiscoveryTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings'      => array(
				'enable_discovery_hub' => '1',
				'enable_llms_full'     => '1',
				'ai_topics'            => 'technical SEO, WordPress',
			),
			'cybermaps_identity_data' => array(
				'email' => 'public@example.com',
			),
		);
		$GLOBALS['cybermaps_mock_transients'] = array();
	}

	public function test_level_three_manifest_advertises_required_resources_without_mcp(): void {
		$manifest = ( new ADPDiscovery() )->get_manifest_data();

		$this->assertSame( '3.0', $manifest['version'] );
		$this->assertSame( 'technical SEO', $manifest['website']['category'] );
		$this->assertSame( 'daily', $manifest['capabilities']['updateFrequency'] );
		$this->assertTrue( $manifest['capabilities']['supportsIncrementalUpdates'] );
		$this->assertArrayHasKey( 'recentUpdates', $manifest['endpoints'] );
		$this->assertArrayHasKey( 'newsNamespace', $manifest['endpoints'] );
		$this->assertArrayHasKey( 'newsContext', $manifest['endpoints'] );
		$this->assertArrayHasKey( 'speakableNews', $manifest['endpoints'] );
		$this->assertArrayHasKey( 'newsChangelog', $manifest['endpoints'] );
		$this->assertArrayHasKey( 'newsArchive', $manifest['endpoints'] );
		$this->assertArrayHasKey( 'contextDocumentFull', $manifest['endpoints'] );
		$this->assertArrayNotHasKey( 'mcp', $manifest['endpoints'] );
		$this->assertSame( 'public@example.com', $manifest['contact']['email'] );
	}

	public function test_generated_timestamp_is_stable_until_discovery_cache_invalidation(): void {
		$handler = new ADPDiscovery();
		$first   = $handler->get_manifest_data();
		$second  = $handler->get_manifest_data();

		$this->assertSame( $first['generatedAt'], $second['generatedAt'] );
	}
}
