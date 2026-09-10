<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\ProviderIdentity;
use Cybermaps\Sitemap\SitemapRouteMatcher;
use PHPUnit\Framework\TestCase;

final class ProviderIdentityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_post_types'] = array();
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array();
	}

	public function test_equal_post_type_and_taxonomy_names_remain_distinct(): void {
		$post_type = ProviderIdentity::post_type( 'shared_name' );
		$taxonomy  = ProviderIdentity::taxonomy( 'shared_name' );

		$this->assertSame( 'post_type:shared_name', $post_type );
		$this->assertSame( 'taxonomy:shared_name', $taxonomy );
		$this->assertNotSame( $post_type, $taxonomy );
		$this->assertSame( 'post_type', ProviderIdentity::kind( $post_type ) );
		$this->assertSame( 'taxonomy', ProviderIdentity::kind( $taxonomy ) );
		$this->assertSame( 'shared_name', ProviderIdentity::name( $post_type ) );
		$this->assertSame( 'shared_name', ProviderIdentity::name( $taxonomy ) );
	}

	public function test_reserved_wordpress_object_names_do_not_equal_system_identities(): void {
		foreach ( array( 'news', 'misc', 'authors', 'archives' ) as $name ) {
			$system    = ProviderIdentity::system( $name );
			$post_type = ProviderIdentity::post_type( $name );
			$taxonomy  = ProviderIdentity::taxonomy( $name );

			$this->assertTrue( ProviderIdentity::is_system( $system, $name ) );
			$this->assertNotSame( $system, $post_type );
			$this->assertNotSame( $system, $taxonomy );
			$this->assertNotSame( $post_type, $taxonomy );
		}
	}

	public function test_filename_builder_uses_explicit_object_kind_namespaces(): void {
		$routes = array(
			'sitemap_url_base'      => 'machine-map',
			'news_sitemap_url_base' => 'press-map',
			'rss_sitemap_url_base'  => 'machine-feed',
		);

		$this->assertSame( 'press-map.xml', ProviderIdentity::filename( ProviderIdentity::NEWS, 1, $routes ) );
		$this->assertSame( 'machine-map-misc.xml', ProviderIdentity::filename( ProviderIdentity::MISC, 1, $routes ) );
		$this->assertSame( 'machine-map-authors-3.xml', ProviderIdentity::filename( ProviderIdentity::AUTHORS, 3, $routes ) );
		$this->assertSame( 'machine-map-archives-4.xml', ProviderIdentity::filename( ProviderIdentity::ARCHIVES, 4, $routes ) );
		$this->assertSame(
			'machine-map-posts-news-2.xml',
			ProviderIdentity::filename( ProviderIdentity::post_type( 'news' ), 2, $routes )
		);
		$this->assertSame(
			'machine-map-taxonomies-news-2.xml',
			ProviderIdentity::filename( ProviderIdentity::taxonomy( 'news' ), 2, $routes )
		);
	}

	public function test_route_matcher_round_trips_every_canonical_filename_kind(): void {
		$settings = array(
			'sitemap_url_base'      => 'machine-map',
			'news_sitemap_url_base' => 'press-map',
			'rss_sitemap_url_base'  => 'machine-feed',
		);
		$expected = array(
			'/machine-map.xml'                         => array( 'index', '', 1 ),
			'/sitemap-network.xml'                     => array( 'network', '', 1 ),
			'/press-map.xml'                           => array( 'news', ProviderIdentity::NEWS, 1 ),
			'/machine-feed.xml'                        => array( 'rss', '', 1 ),
			'/machine-map-misc.xml'                    => array( 'child', ProviderIdentity::MISC, 1 ),
			'/machine-map-authors-2.xml'               => array( 'child', ProviderIdentity::AUTHORS, 2 ),
			'/machine-map-archives-3.xml'              => array( 'child', ProviderIdentity::ARCHIVES, 3 ),
			'/machine-map-posts-shared_name-4.xml'     => array( 'child', ProviderIdentity::post_type( 'shared_name' ), 4 ),
			'/machine-map-taxonomies-shared_name-5.xml' => array( 'child', ProviderIdentity::taxonomy( 'shared_name' ), 5 ),
		);

		foreach ( $expected as $path => $values ) {
			$match = SitemapRouteMatcher::match_path( $path, $settings );
			$this->assertNotNull( $match, $path );
			$this->assertSame( $values[0], $match['route'], $path );
			$this->assertSame( $values[1], $match['provider_id'], $path );
			$this->assertSame( $values[2], $match['page'], $path );
		}
	}

	public function test_route_matcher_rejects_kindless_zero_page_and_lookalike_paths(): void {
		$settings = array( 'sitemap_url_base' => 'machine-map' );

		foreach (
			array(
				'/machine-map-shared-1.xml',
				'/machine-map-posts-shared-0.xml',
				'/machine-map-taxonomies-shared-01.xml',
				'/machine-map-posts--1.xml',
				'/machine-map-authors.xml',
				'/machine-map.xml/extra',
			) as $path
		) {
			$this->assertNull( SitemapRouteMatcher::match_path( $path, $settings ), $path );
		}
	}

	public function test_registered_same_slug_objects_resolve_as_two_identities_not_one(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_post_type_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'public' => true,
		);

		$this->assertSame(
			array( 'post_type:shared', 'taxonomy:shared' ),
			ProviderIdentity::public_object_identities( 'shared' )
		);
		$this->assertSame( '', ProviderIdentity::unambiguous_public_object( 'shared' ) );

		unset( $GLOBALS['cybermaps_mock_taxonomy_objects']['shared'] );
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		$this->assertSame(
			'post_type:shared',
			ProviderIdentity::unambiguous_public_object( 'shared' )
		);
	}
}
