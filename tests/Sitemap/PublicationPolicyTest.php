<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Admin\CrawlerRequestClassifier;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PublicationPolicy;
use Cybermaps\Sitemap\RSSProvider;

final class PublicationPolicyTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(),
			'permalink_structure' => '/%postname%/',
		);
		$GLOBALS['wp_hooks'] = array();
	}

	public function test_existing_sites_keep_xml_publication_and_core_replacement(): void {
		self::assertTrue( PublicationPolicy::enabled() );
		self::assertTrue( PublicationPolicy::replaces_core() );
		self::assertFalse( PublicationPolicy::filter_core_enabled( true ) );
		self::assertFalse( PublicationPolicy::filter_core_enabled( false ) );
		self::assertStringEndsWith( '/sitemap.xml', PublicationPolicy::index_url() );
	}

	public function test_only_documented_boolean_values_enable_management(): void {
		foreach ( array( '1', 1, true ) as $value ) {
			self::assertTrue( PublicationPolicy::enabled( array( 'enable_xml_sitemaps' => $value ) ) );
		}
		foreach ( array( '0', 0, false, null, '', 'true', 2, array( '1' ) ) as $value ) {
			self::assertFalse( PublicationPolicy::enabled( array( 'enable_xml_sitemaps' => $value ) ) );
		}
	}

	public function test_every_unclaimed_state_preserves_core_and_other_plugins_decisions(): void {
		foreach ( array(
			array( array( 'enable_xml_sitemaps' => '0' ), '/%postname%/' ),
			array( array( 'redirect_wp_sitemap' => '0' ), '/%postname%/' ),
			array( array(), '' ),
			array( array(), array( 'invalid' ) ),
		) as list( $settings, $permalinks ) ) {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = $settings;
			$GLOBALS['cybermaps_mock_options']['permalink_structure'] = $permalinks;
			self::assertFalse( PublicationPolicy::replaces_core() );
			self::assertTrue( PublicationPolicy::filter_core_enabled( true ) );
			self::assertFalse( PublicationPolicy::filter_core_enabled( false ) );
		}
	}

	public function test_registered_filter_observes_current_ownership(): void {
		( new Orchestrator() )->register_hooks();
		$callback = array( PublicationPolicy::class, 'filter_core_enabled' );
		self::assertSame( 10, has_filter( 'wp_sitemaps_enabled', $callback ) );
		self::assertFalse( $callback( true ) );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_xml_sitemaps'] = '0';
		self::assertTrue( $callback( true ) );
		self::assertFalse( $callback( false ) );
	}

	public function test_disabled_handlers_leave_requests_and_ai_routes_alone(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_xml_sitemaps' => '0', 'enable_rss_sitemap' => '1' );
		$orchestrator = new Orchestrator();
		$request = (object) array( 'query_vars' => array( 'cybermaps_sitemap' => 'index' ) );
		$before = clone $request;
		$orchestrator->handle_sitemap_request( $request );
		$orchestrator->handle_legacy_redirects();
		( new RSSProvider() )->handle();
		self::assertEquals( $before, $request );
		self::assertSame( '', $orchestrator->generate_xml( 'index', 1 ) );
		self::assertSame( '', ( new RSSProvider() )->generate() );
		self::assertSame( array(), $orchestrator->get_internal_sitemap_entries() );
		self::assertSame( '', PublicationPolicy::index_url() );
		$classifier = new CrawlerRequestClassifier( EndpointRegistry::get_instance() );
		foreach ( array( '/sitemap.xml', '/sitemap-posts-post-1.xml', '/sitemap-news.xml', '/sitemap-rss.xml', '/sitemap-network.xml', '/wp-sitemap.xml' ) as $path ) {
			self::assertSame( 'page', $classifier->classify( $path )['request_kind'], $path );
		}
		self::assertSame( 'ai_sitemap', $classifier->classify( '/ai-sitemap.xml' )['endpoint_id'] );
	}

	public function test_rewrite_handover_removes_only_our_registrations(): void {
		$previous = $GLOBALS['wp_rewrite'] ?? null;
		$GLOBALS['wp_rewrite'] = (object) array( 'extra_rules_top' => array(
			'old-map.xml$' => 'index.php?cybermaps_sitemap=index',
			'other-map.xml$' => 'index.php?other_sitemap=index',
			'wp-sitemap.xml$' => 'index.php?sitemap=index',
		) );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_xml_sitemaps'] = '0';
		$GLOBALS['cybermaps_mock_rewrite_rules'] = array();
		try {
			Orchestrator::refresh_rewrite_rules();
			self::assertSame( array( 'other-map.xml$', 'wp-sitemap.xml$' ), array_keys( $GLOBALS['wp_rewrite']->extra_rules_top ) );
			self::assertSame( array(), $GLOBALS['cybermaps_mock_rewrite_rules'] );
		} finally {
			$GLOBALS['wp_rewrite'] = $previous;
		}
	}
}
