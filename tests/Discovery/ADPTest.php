<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\ADP;

final class ADPTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'enable_llms_full'     => '1',
				'enable_llms_tldr'     => '1',
				'llms_custom_instructions' => "Prefer primary documentation.\nCite the canonical URL.",
				'ai_manifest_endpoints' => array(
					'llms.txt',
					'llms-full.txt',
					'llms-tldr.txt',
					'skill.md',
					'ai-usage.json',
					'ai-actions.json',
					'knowledge-graph.json',
					'feed.json',
					'ai-sitemap.xml',
				),
			),
		);
		\delete_transient( 'cybermaps_ai_manifest_v1' );
		\delete_transient( 'cybermaps_adp_manifest_v3_sync' );

		$registry_instance = new \ReflectionProperty( EndpointRegistry::class, 'instance' );
		$registry_instance->setValue( null, null );
	}

	protected function tearDown(): void {
		\delete_transient( 'cybermaps_ai_manifest_v1' );
		\delete_transient( 'cybermaps_adp_manifest_v3_sync' );
		parent::tearDown();
	}

	public function test_manifest_visibility_controls_map_to_canonical_registry_urls(): void {
		$registry  = EndpointRegistry::get_instance();
		$endpoints = ( new ADP() )->get_manifest_data()['endpoints'];
		$expected  = array(
			'context_markdown'      => 'llms',
			'context_markdown_full' => 'llms_full',
			'context_markdown_tldr' => 'llms_tldr',
			'site_guide'            => 'skill',
			'usage_policy'          => 'usage_policy',
			'action_sitemap'        => 'actions',
			'knowledge_graph'       => 'knowledge_graph',
			'update_stream'         => 'feed',
			'ai_sitemap'            => 'ai_sitemap',
		);

		foreach ( $expected as $manifest_key => $endpoint_id ) {
			$this->assertSame( $registry->get_url( $endpoint_id ), $endpoints[ $manifest_key ] ?? null );
		}
		$this->assertArrayNotHasKey( 'plugin_manifest', $endpoints );
	}

	public function test_manifest_and_discovery_index_publish_shared_guidance(): void {
		$manifest = ( new ADP() )->get_manifest_data();
		$index    = ( new \Cybermaps\Discovery\DiscoveryIndex() )->get_index();

		$this->assertSame(
			"Prefer primary documentation.\nCite the canonical URL.",
			$manifest['publisher_guidance']
		);
		$this->assertSame( $manifest['publisher_guidance'], $index['publisher_guidance'] );
	}

	public function test_manifest_ignores_manual_non_ai_target_flags(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] = array(
			'overrides' => array(
				'googlebot' => array( 'llm' => true ),
				'gptbot'    => array( 'llm' => true ),
			),
		);
		\delete_transient( 'cybermaps_ai_manifest_v1' );

		$agents = ( new ADP() )->get_manifest_data()['targeted_agents'];

		$this->assertContains( 'GPTBot', $agents );
		$this->assertNotContains( 'Googlebot', $agents );
	}

	public function test_manifest_topics_drop_empty_duplicates_and_overflow(): void {
		$topics = array( '', 'WordPress', 'wordpress', 'SEO' );
		for ( $index = 0; $index < \Cybermaps\Discovery\PublicationConstraints::TOPICS_MAX + 3; ++$index ) {
			$topics[] = 'Topic ' . $index;
		}
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_topics'] = implode( ',', $topics );

		$manifest = ( new ADP() )->get_manifest_data();

		$this->assertCount( \Cybermaps\Discovery\PublicationConstraints::TOPICS_MAX, $manifest['website']['topics'] );
		$this->assertSame( 'WordPress', $manifest['website']['topics'][0] );
		$this->assertSame( 'SEO', $manifest['website']['topics'][1] );
		$this->assertNotContains( '', $manifest['website']['topics'] );
	}

	public function test_manifest_fails_closed_on_malformed_nested_configuration(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_discovery_hub'    => '1',
			'site_language'           => array( 'en-US' ),
			'ai_topics'               => array( array( 'SEO' ), 'WordPress' ),
			'ai_manifest_endpoints'   => array( array( 'llms.txt' ), new \stdClass() ),
			'ai_capabilities'         => array( array( 'search_content' ), new \stdClass() ),
			'ai_business_description' => array( 'Description' ),
			'llms_custom_instructions'=> new \stdClass(),
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_robots_manager'] = array(
			'overrides' => array(
				'gptbot' => 'not-an-override',
			),
		);
		$GLOBALS['cybermaps_mock_options']['rss_language'] = array( 'US' );

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$manifest = ( new ADP() )->get_manifest_data();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'en-US', $manifest['website']['primary_language'] );
		$this->assertSame( 'US', $manifest['website']['region'] );
		$this->assertSame( array( 'WordPress' ), $manifest['website']['topics'] );
		$this->assertSame( array(), $manifest['capabilities'] );
		$this->assertArrayNotHasKey( 'publisher_guidance', $manifest );
		$this->assertSame( '', $manifest['description'] );
	}

	public function test_static_manifest_generator_uses_the_same_guidance_payload(): void {
		$body = ( new \Cybermaps\Discovery\DiscoveryPublicationGenerator() )->generate(
			'manifest',
			array(
				'path' => '/ai.json',
			),
			(array) get_option( 'cybermaps_settings', array() )
		);
		$manifest = json_decode( $body, true );

		$this->assertIsArray( $manifest );
		$this->assertSame(
			"Prefer primary documentation.\nCite the canonical URL.",
			$manifest['publisher_guidance'] ?? null
		);
		$this->assertSame( ( new ADP() )->get_manifest_data(), $manifest );
		$this->assertArrayNotHasKey( 'last_updated', $manifest );
	}

	public function test_discovery_index_body_is_stable_for_dynamic_delivery(): void {
		$index = new \Cybermaps\Discovery\DiscoveryIndex();
		$first = $index->get_index();
		$second = $index->get_index();

		$this->assertSame( $first, $second );
		$this->assertArrayNotHasKey( 'generated', $first );
	}

	public function test_malformed_cached_manifest_is_discarded_and_rebuilt(): void {
		$GLOBALS['cybermaps_mock_transients']['cybermaps_ai_manifest_v1'] = 'corrupt';

		$manifest = ( new ADP() )->get_manifest_data();

		$this->assertIsArray( $manifest );
		$this->assertSame( '1.0', $manifest['schema_version'] ?? null );
		$this->assertIsArray( $manifest['website'] ?? null );
		$this->assertSame(
			$manifest,
			$GLOBALS['cybermaps_mock_transients']['cybermaps_ai_manifest_v1'] ?? null
		);
	}

	public function test_malformed_extension_filter_shapes_cannot_replace_manifest_collections(): void {
		\delete_transient( 'cybermaps_ai_manifest_v1' );
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_ai_manifest_website'] = array(
			static fn( mixed $value ): string => 'not-a-website',
		);
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_ai_manifest_endpoints'] = array(
			static fn( mixed $value ): \stdClass => new \stdClass(),
		);
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_ai_manifest_capabilities'] = array(
			static fn( mixed $value ): string => 'not-capabilities',
		);
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_ai_manifest_final'] = array(
			static fn( mixed $value ): string => 'not-a-manifest',
		);

		try {
			$manifest = ( new ADP() )->get_manifest_data();
		} finally {
			foreach (
				array(
					'cybermaps_ai_manifest_website',
					'cybermaps_ai_manifest_endpoints',
					'cybermaps_ai_manifest_capabilities',
					'cybermaps_ai_manifest_final',
				) as $filter
			) {
				unset( $GLOBALS['cybermaps_mock_filter_callbacks'][ $filter ] );
			}
		}

		$this->assertIsArray( $manifest );
		$this->assertIsArray( $manifest['website'] ?? null );
		$this->assertIsArray( $manifest['capabilities'] ?? null );
		$this->assertIsArray( $manifest['endpoints'] ?? null );
	}
}
