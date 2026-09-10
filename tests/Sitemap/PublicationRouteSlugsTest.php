<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PublicationRouteSlugs;
use PHPUnit\Framework\TestCase;

class PublicationRouteSlugsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	public function test_defaults_are_unique_and_reachable(): void {
		$this->assertSame(
			array(
				'sitemap_url_base'      => 'sitemap',
				'news_sitemap_url_base' => 'sitemap-news',
				'rss_sitemap_url_base'  => 'sitemap-rss',
			),
			PublicationRouteSlugs::resolve( array() )
		);
	}

	public function test_valid_custom_slugs_and_optional_xml_suffixes_are_preserved(): void {
		$this->assertSame(
			array(
				'sitemap_url_base'      => 'client-map',
				'news_sitemap_url_base' => 'press-wire',
				'rss_sitemap_url_base'  => 'recent-feed',
			),
			PublicationRouteSlugs::resolve(
				array(
					'sitemap_url_base'      => 'Client Map.XML',
					'news_sitemap_url_base' => 'Press Wire.xml',
					'rss_sitemap_url_base'  => 'Recent Feed.XML',
				)
			)
		);
	}

	public function test_cross_field_collisions_fall_back_by_route_priority(): void {
		$this->assertSame(
			array(
				'sitemap_url_base'      => 'shared',
				'news_sitemap_url_base' => 'shared-news',
				'rss_sitemap_url_base'  => 'sitemap-rss',
			),
			PublicationRouteSlugs::resolve(
				array(
					'sitemap_url_base'      => 'shared',
					'news_sitemap_url_base' => 'shared',
					'rss_sitemap_url_base'  => 'shared',
				)
			)
		);

		$routes = PublicationRouteSlugs::resolve(
			array(
				'sitemap_url_base'      => 'client-map',
				'news_sitemap_url_base' => 'updates',
				'rss_sitemap_url_base'  => 'updates',
			)
		);
		$this->assertSame( 'updates', $routes['news_sitemap_url_base'] );
		$this->assertSame( 'sitemap-rss', $routes['rss_sitemap_url_base'] );
	}

	public function test_fixed_publication_and_wordpress_sitemap_routes_are_reserved(): void {
		foreach ( array( 'ai-sitemap', 'sitemap-network', 'wp-sitemap', 'wp-sitemap-posts-post-1' ) as $reserved ) {
			$primary = PublicationRouteSlugs::resolve(
				array( 'sitemap_url_base' => $reserved )
			);
			$this->assertSame( 'sitemap', $primary['sitemap_url_base'], $reserved );

			$news = PublicationRouteSlugs::resolve(
				array(
					'sitemap_url_base'      => 'client-map',
					'news_sitemap_url_base' => $reserved,
				)
			);
			$this->assertSame( 'client-map-news', $news['news_sitemap_url_base'], $reserved );

			$rss = PublicationRouteSlugs::resolve(
				array(
					'sitemap_url_base'     => 'client-map',
					'rss_sitemap_url_base' => $reserved,
				)
			);
			$this->assertSame( 'sitemap-rss', $rss['rss_sitemap_url_base'], $reserved );
		}
	}

	public function test_primary_cannot_use_the_legacy_news_redirect_path(): void {
		$routes = PublicationRouteSlugs::resolve(
			array( 'sitemap_url_base' => 'sitemap-news' )
		);

		$this->assertSame( 'sitemap', $routes['sitemap_url_base'] );
		$this->assertSame( 'sitemap-news', $routes['news_sitemap_url_base'] );
	}

	public function test_auxiliary_routes_cannot_shadow_generated_children(): void {
		$routes = PublicationRouteSlugs::resolve(
			array(
				'sitemap_url_base'      => 'client-map',
				'news_sitemap_url_base' => 'client-map-posts-product_type-12',
				'rss_sitemap_url_base'  => 'client-map-misc',
			)
		);

		$this->assertSame( 'client-map-news', $routes['news_sitemap_url_base'] );
		$this->assertSame( 'sitemap-rss', $routes['rss_sitemap_url_base'] );

		foreach (
			array(
				'client-map-authors-1',
				'client-map-archives-22',
				'client-map-taxonomies-product_type-3',
			) as $generated_child
		) {
			$routes = PublicationRouteSlugs::resolve(
				array(
					'sitemap_url_base'      => 'client-map',
					'news_sitemap_url_base' => $generated_child,
				)
			);
			$this->assertSame( 'client-map-news', $routes['news_sitemap_url_base'] );
		}
	}

	public function test_runtime_getters_recover_from_malformed_stored_options_before_resave(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'sitemap_url_base'      => str_repeat( 'x', 500 ),
			'news_sitemap_url_base' => 'ai-sitemap',
			'rss_sitemap_url_base'  => array( 'not', 'scalar' ),
		);

		$this->assertSame( 'sitemap', Orchestrator::get_sitemap_base() );
		$this->assertSame( 'sitemap-news', Orchestrator::get_news_sitemap_base() );
		$this->assertSame( 'sitemap-rss', Orchestrator::get_rss_sitemap_base() );
	}
}
