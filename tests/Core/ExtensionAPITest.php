<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\Container;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\ExtensionAPI;
use Cybermaps\Core\Plugin;
use Cybermaps\Discovery\DiscoveryIndex;
use PHPUnit\Framework\TestCase;

class ExtensionAPITest extends TestCase {

	public function test_core_rest_endpoints_have_canonical_urls(): void {
		$registry = EndpointRegistry::get_instance();

		$this->assertSame(
			'https://example.com/wp-json/cybermaps/v1/discovery',
			$registry->get_url( 'rest_root' )
		);
		$this->assertSame(
			array(
				'namespace' => 'cybermaps/v1',
				'route'     => '/search',
			),
			$registry->get_rest_route( 'rest_search' )
		);
		$this->assertSame(
			array(
				'namespace' => 'cybermaps/v1',
				'route'     => '/health',
			),
			$registry->get_rest_route( 'public_health' )
		);
		$this->assertSame(
			array(
				'namespace' => 'cybermaps/v1',
				'route'     => '/mcp/server-card',
			),
			$registry->get_rest_route( 'rest_mcp_server_card' )
		);
		$this->assertSame(
			'rest_search',
			$registry->match_rest_path( '/cybermaps/v1/search' )['id'] ?? null
		);
	}

	public function test_discovery_index_only_uses_advertised_registry_entries(): void {
		$advertised = EndpointRegistry::get_instance()->get_advertised_endpoints();

		$this->assertArrayHasKey( 'manifest', $advertised );
		$this->assertArrayHasKey( 'rest_root', $advertised );
		$this->assertArrayNotHasKey( 'discovery_index', $advertised );
		$this->assertArrayNotHasKey( 'llms_full', $advertised );
		$this->assertArrayNotHasKey( 'llms_tldr', $advertised );
		$this->assertArrayNotHasKey( 'rest_purge', $advertised );
	}

	public function test_registry_records_fixed_aliases_without_treating_it_as_a_router(): void {
		$registry = EndpointRegistry::get_instance();

		$this->assertSame( array(), $registry->get_aliases( 'manifest' ) );
		$this->assertSame( array(), $registry->get_aliases( 'discovery_index' ) );
		$this->assertSame( array( '/api-catalog' ), $registry->get_aliases( 'api_catalog' ) );
		$this->assertSame( array( '/ai-catalog.json' ), $registry->get_aliases( 'ai_catalog' ) );
		$this->assertSame( array(), $registry->get_aliases( 'usage_policy' ) );
		$this->assertSame( array(), $registry->get_aliases( 'actions' ) );
		$this->assertSame( array( '/skill.md' ), $registry->get_aliases( 'skill' ) );
		$this->assertSame( array(), $registry->get_aliases( 'rest_root' ) );
	}

	public function test_registry_metadata_matches_served_protocols(): void {
		$registry = EndpointRegistry::get_instance();
		$index    = ( new DiscoveryIndex() )->get_index();

		$this->assertSame( 'Cybermaps Budgeted Site Briefing 0.2-draft', $registry->get( 'llms_tldr' )['spec'] );
		$this->assertSame( 'experimental-proposal', $registry->get( 'llms_tldr' )['maturity'] );
		$this->assertSame( 'reference-only', $registry->get( 'llms_tldr' )['adoption'] );
		$this->assertSame( 'Cybermaps Literal Full Corpus 1.0', $registry->get( 'llms_full' )['spec'] );
		$this->assertSame( 'vendor-extension', $registry->get( 'llms_full' )['maturity'] );
		$this->assertSame( 'reference-only', $registry->get( 'llms_full' )['adoption'] );
		$this->assertSame( 'application/linkset+json', $registry->get( 'api_catalog' )['type'] );
		$this->assertSame( 'formal-standard', $registry->get( 'api_catalog' )['maturity'] );
		$this->assertSame( 'independent-producers', $registry->get( 'api_catalog' )['adoption'] );
		$this->assertSame( 'formal-draft', $registry->get( 'ai_catalog' )['maturity'] );
		$this->assertSame( 'experimental-proposal', $registry->get( 'mcp_server_card' )['maturity'] );
		$this->assertSame( 'application/json', $registry->get( 'rest_search' )['type'] );
		$this->assertSame( 'medium', $registry->get( 'rest_search' )['throttle_tier'] );
		$this->assertSame( 'expensive', $registry->get( 'rest_llms_tldr' )['throttle_tier'] );
		$this->assertFalse( $registry->get( 'rest_urls' )['advertise'] );
		$this->assertFalse( $registry->get( 'rest_status' )['advertise'] );
		$this->assertFalse( $registry->get( 'rest_purge' )['advertise'] );
		$this->assertArrayNotHasKey( 'llms_tldr', $index['endpoints'] );
		$this->assertSame( 'application/linkset+json', $index['endpoints']['api_catalog']['type'] );
	}

	public function test_registry_is_the_canonical_path_and_static_inventory(): void {
		$registry = EndpointRegistry::get_instance();
		$actions  = $registry->match_path( '/ai-actions.json' );
		$catalog  = $registry->match_path( '/.well-known/api-catalog' );
		$catalog_alias = $registry->match_path( '/api-catalog' );
		$ai_catalog = $registry->match_path( '/.well-known/ai-catalog.json' );
		$mcp_card   = $registry->match_path( '/.well-known/mcp/server-card.json' );
		$auth_md    = $registry->match_path( '/auth.md' );

		$this->assertNotNull( $actions );
		$this->assertSame( 'actions', $actions['id'] );
		$this->assertTrue( $actions['canonical'] );
		$this->assertSame( 'api_catalog', $catalog['id'] ?? null );
		$this->assertTrue( $catalog['canonical'] ?? false );
		$this->assertSame( 'api_catalog', $catalog_alias['id'] ?? null );
		$this->assertFalse( $catalog_alias['canonical'] ?? true );
		$this->assertSame( 'ai_catalog', $ai_catalog['id'] ?? null );
		$this->assertSame( 'mcp_server_card', $mcp_card['id'] ?? null );
		$this->assertSame( 'auth_md', $auth_md['id'] ?? null );
		$this->assertNull( $registry->match_path( '/.well-known/ai-discovery.json' ) );
		$this->assertNull( $registry->match_path( '/not-a-cybermaps-publication' ) );

		$well_known = array_column( $registry->get_static_targets( 'well_known' ), 'filename' );
		$all        = array_column( $registry->get_static_targets( 'all' ), 'filename' );

		$this->assertContains( 'ai-actions.json', $well_known );
		$this->assertContains( 'ai-usage.json', $well_known );
		$this->assertContains( 'ai-discovery', $well_known );
		$this->assertNotContains( '.well-known/ai-actions.json', $well_known );
		$this->assertContains( 'ai-actions.json', $all );
		$this->assertContains( 'ai-sitemap.xml', $all );
		$this->assertNotContains( 'feed.json', $all );
		$this->assertNotContains( 'robots.txt', $all );
		$this->assertNotContains( 'skill.md', $all );
		$this->assertSame( array(), $registry->get_static_targets( 'off' ) );
		$this->assertNotContains( 'llms-full.txt', $all );
		$this->assertNotContains( 'llms-tldr.txt', $all );

		$optional = array_column(
			$registry->get_static_targets(
				'all',
				array(
					'enable_llms_full' => '1',
					'enable_llms_tldr' => '1',
				)
			),
			'filename'
		);
		$this->assertContains( 'llms-full.txt', $optional );
		$this->assertContains( 'llms-tldr.txt', $optional );
	}

	public function test_json_feed_has_its_protocol_media_type_and_stays_dynamic(): void {
		$registry = EndpointRegistry::get_instance();
		$feed     = $registry->get( 'feed' );

		$this->assertSame( 'application/feed+json', $feed['type'] ?? null );
		$this->assertSame( array(), $feed['static_targets'] ?? array() );
	}

	public function test_malformed_extension_metadata_is_rejected_without_throwing(): void {
		$registry     = EndpointRegistry::get_instance();
		$error_count = count( $registry->get_registration_errors() );

		$this->assertFalse( $registry->register() );
		$this->assertFalse( $registry->register( array( 'not-a-string' ), 'not-an-array' ) );
		$this->assertFalse(
			$registry->register(
				'bad_kind',
				array(
					'kind' => array( 'path' ),
					'path' => '/extension',
				)
			)
		);
		$this->assertFalse(
			$registry->register(
				'bad_alias',
				array(
					'kind'    => 'path',
					'path'    => '/extension',
					'aliases' => array( 'relative' ),
				)
			)
		);

		$this->assertCount( $error_count + 4, $registry->get_registration_errors() );
	}

	public function test_rest_namespace_is_validated_without_silent_normalization(): void {
		$registry = EndpointRegistry::get_instance();

		foreach ( array( 'vendor', '/vendor/v1', 'vendor/v1/', 'vendor name/v1' ) as $index => $namespace ) {
			$this->assertFalse(
				$registry->register(
					'invalid_namespace_' . $index,
					array(
						'kind'      => 'rest',
						'namespace' => $namespace,
						'route'     => '/search',
					)
				)
			);
		}

		$this->assertTrue(
			$registry->register(
				'valid_extension_route',
				array(
					'kind'      => 'rest',
					'namespace' => 'cybermaps-pro/v1',
					'route'     => '/insights',
				)
			)
		);
		$this->assertSame(
			array(
				'namespace' => 'cybermaps-pro/v1',
				'route'     => '/insights',
			),
			$registry->get_rest_route( 'valid_extension_route' )
		);
	}

	public function test_registry_rejects_public_path_and_rest_route_collisions(): void {
		$registry     = EndpointRegistry::get_instance();
		$error_count = count( $registry->get_registration_errors() );

		$this->assertFalse(
			$registry->register(
				'colliding_canonical_path',
				array(
					'kind' => 'path',
					'path' => '/ai.json',
				)
			)
		);
		$this->assertFalse(
			$registry->register(
				'colliding_alias_path',
				array(
					'kind'    => 'path',
					'path'    => '/extension-publication',
					'aliases' => array( '/llms.txt' ),
				)
			)
		);
		$this->assertFalse(
			$registry->register(
				'colliding_rest_route',
				array(
					'kind'      => 'rest',
					'namespace' => 'cybermaps/v1',
					'route'     => '/search',
				)
			)
		);

		$this->assertCount( $error_count + 3, $registry->get_registration_errors() );
	}

	public function test_extension_api_exposes_versioned_narrow_contract(): void {
		$registry = EndpointRegistry::get_instance();
		$api      = ExtensionAPI::get_instance( $registry );

		$this->assertSame( '2.0.0', $api->get_version() );
		$this->assertTrue( $api->supports( '1.0.0' ) );
		$this->assertTrue( $api->supports( '2.0.0' ) );
		$this->assertFalse( $api->supports( '3.0.0' ) );
		$this->assertSame( $registry, $api->endpoints() );
		$this->assertInstanceOf( \Cybermaps\SEO\PublicationEligibility::class, $api->eligibility() );
		$this->assertInstanceOf( \Cybermaps\Audit\AuditReadAPI::class, $api->audits() );
		$this->assertSame( $api, ExtensionAPI::get_instance() );
		$this->assertSame( 'cybermaps_register_endpoints', EndpointRegistry::REGISTRATION_ACTION );
		$this->assertSame( 'cybermaps_endpoint_registration_error', EndpointRegistry::REGISTRATION_ERROR_ACTION );
		$this->assertSame( 'cybermaps_extension_api_ready', ExtensionAPI::READY_ACTION );
	}

	public function test_plugin_defers_extension_readiness_to_init_priority_zero(): void {
		$original_hooks       = $GLOBALS['wp_hooks'];
		$GLOBALS['wp_hooks'] = array();

		$plugin = new Plugin( new Container() );
		$plugin->run();

		$boot_hooks = array_values(
			array_filter(
				$GLOBALS['wp_hooks'],
				static function( array $hook ): bool {
					return (
						'action' === $hook['type']
						&& 'init' === $hook['hook']
						&& 0 === $hook['priority']
						&& is_array( $hook['callback'] )
						&& $hook['callback'][0] instanceof ExtensionAPI
						&& 'boot' === $hook['callback'][1]
					);
				}
			)
		);

		$GLOBALS['wp_hooks'] = $original_hooks;

		$this->assertCount( 1, $boot_hooks );
	}

	public function test_late_consumer_retrieves_the_ready_api(): void {
		$api = ExtensionAPI::get_instance();
		$api->boot();

		$this->assertTrue( $api->is_ready() );
		$this->assertSame( $api, ExtensionAPI::get_instance() );
	}
}
