<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CrawlerRequestClassifier;
use Cybermaps\Core\EndpointRegistry;

final class CrawlerRequestClassifierTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array();
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_indexnow_key'] );
	}

	public function test_registry_canonical_path_has_the_endpoint_id_without_an_unregistered_alias(): void {
		$classifier = new CrawlerRequestClassifier( EndpointRegistry::get_instance() );

		$this->assertSame(
			array( 'request_kind' => 'endpoint', 'endpoint_id' => 'manifest' ),
			$classifier->classify( '/ai.json' )
		);
		$this->assertSame( 'page', $classifier->classify( '/.well-known/ai.json' )['request_kind'] );
	}

	public function test_configured_sitemaps_robots_and_chunks_are_classified_without_broad_prefixes(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'sitemap_url_base'      => 'machine-map.xml',
			'news_sitemap_url_base' => 'machine-news.xml',
			'rss_sitemap_url_base'  => 'machine-rss',
		);
		$classifier = new CrawlerRequestClassifier( EndpointRegistry::get_instance() );

		$this->assertSame( 'sitemap_index', $classifier->classify( '/machine-map.xml' )['endpoint_id'] );
		$this->assertSame( 'sitemap_child', $classifier->classify( '/machine-map-posts-post-2.xml' )['endpoint_id'] );
		$this->assertSame( 'sitemap_child', $classifier->classify( '/machine-map-taxonomies-post-2.xml' )['endpoint_id'] );
		$this->assertSame( 'sitemap_network', $classifier->classify( '/sitemap-network.xml' )['endpoint_id'] );
		$this->assertSame( 'sitemap_news', $classifier->classify( '/machine-news.xml' )['endpoint_id'] );
		$this->assertSame( 'sitemap_rss', $classifier->classify( '/machine-rss.xml' )['endpoint_id'] );
		$this->assertSame( 'page', $classifier->classify( '/machine-map-post-2.xml' )['request_kind'] );
		$this->assertSame( 'robots', $classifier->classify( '/robots.txt' )['endpoint_id'] );
		$this->assertSame( 'rag_chunk', $classifier->classify( '/discovery/chunks/42.json' )['endpoint_id'] );
		$this->assertSame( 'llms', $classifier->classify( '/es/llms.txt' )['endpoint_id'] );
		$this->assertSame( 'llms_full', $classifier->classify( '/fr/llms-full.txt' )['endpoint_id'] );
		$this->assertSame( 'llms_tldr', $classifier->classify( '/de/llms-tldr.txt' )['endpoint_id'] );
		$this->assertSame( 'markdown_alternate', $classifier->classify( '/guides/setup/index.md' )['endpoint_id'] );
		$this->assertSame( 'markdown_alternate', $classifier->classify( '/about.html.md' )['endpoint_id'] );
		$this->assertSame( 'page', $classifier->classify( '/cybermaps-review' )['request_kind'] );
	}

	public function test_rest_routes_use_registry_ids_and_do_not_capture_other_namespaces(): void {
		$classifier = new CrawlerRequestClassifier( EndpointRegistry::get_instance() );

		$this->assertSame(
			array( 'request_kind' => 'endpoint', 'endpoint_id' => 'rest_root' ),
			$classifier->classify( '/', '/cybermaps/v1/discovery' )
		);
		$this->assertSame(
			array( 'request_kind' => 'endpoint', 'endpoint_id' => 'rest_status' ),
			$classifier->classify( '/wp-json/cybermaps/v1/status' )
		);
		$this->assertSame( 'page', $classifier->classify( '/wp-json/wp/v2/posts' )['request_kind'] );
		$this->assertFalse( $classifier->is_cybermaps_rest_route( '/wp/v2/posts' ) );
	}

	public function test_enabled_markdown_negotiation_classifies_the_canonical_page(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_discovery_hub'        => '1',
			'enable_markdown_negotiation' => '1',
		);
		$classifier = new CrawlerRequestClassifier( EndpointRegistry::get_instance() );

		$this->assertSame( 'markdown_negotiation', $classifier->classify( '/guide/', '', 'text/markdown' )['endpoint_id'] );
		$this->assertSame( 'page', $classifier->classify( '/guide/', '', 'text/markdown;q=0' )['request_kind'] );
	}

	public function test_early_request_classification_does_not_call_rest_url(): void {
		$source = file_get_contents( CYBERMAPS_PLUGIN_DIR . 'src/Admin/CrawlerRequestClassifier.php' );

		$this->assertIsString( $source );
		$this->assertStringNotContainsString( '\\rest_url(', $source );
	}
}
